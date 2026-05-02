<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\ReportingService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    private const REPORT_TYPES = [
        'client-summary' => [
            'name' => 'Client Summary',
            'description' => 'Overview of services delivered per client, including hours and billing.',
        ],
        'staff-utilisation' => [
            'name' => 'Staff Utilisation',
            'description' => 'Staff hours worked, utilisation rates, and capacity analysis.',
        ],
        'shift-analytics' => [
            'name' => 'Shift Analytics',
            'description' => 'Shift coverage, cancellation rates, and scheduling patterns.',
        ],
        'billing' => [
            'name' => 'Billing Report',
            'description' => 'Billing entries, invoicing status, and revenue breakdown.',
        ],
        'compliance' => [
            'name' => 'Compliance Report',
            'description' => 'Credential expiry, training compliance, and documentation status.',
        ],
        'service-hours' => [
            'name' => 'Service Hours',
            'description' => 'Total service hours delivered by period, client, and service type.',
        ],
    ];

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('operations.reports.view') || $auth->canDo('reports.viewAny')), 403);

        return inertia('operations/reports/Index', [
            'reportTypes' => self::REPORT_TYPES,
        ]);
    }

    public function show(Request $request, $type)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('operations.reports.view') || $auth->canDo('reports.viewAny')), 403);

        abort_unless(array_key_exists($type, self::REPORT_TYPES), 404, 'Unknown report type.');

        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $orgId = $auth->organization_id;
        $dateFrom = $data['date_from'] ?? now()->startOfMonth()->toDateString();
        $dateTo = $data['date_to'] ?? now()->endOfMonth()->toDateString();
        $siteAccess = app(UserSiteAccessService::class);
        $bypassPermissions = ['reports.viewAny'];

        if (! empty($data['client_id'])) {
            $siteAccess->assertCanAccessClientId(
                $auth,
                (int) $data['client_id'],
                $bypassPermissions,
                'You are not authorized to view reports for that client.',
            );
        }

        if (! empty($data['staff_id'])) {
            $staffExists = $siteAccess->applyStaffScope(
                User::query()->staff()->whereKey((int) $data['staff_id']),
                $auth,
                $bypassPermissions,
            )->exists();

            abort_unless($staffExists, 403, 'You are not authorized to view reports for that staff member.');
        }

        $data['allowed_site_ids'] = $siteAccess->accessibleSiteIds($auth, $bypassPermissions);

        $reportData = match ($type) {
            'client-summary' => $this->clientSummary($orgId, $dateFrom, $dateTo, $data),
            'staff-utilisation' => $this->staffUtilisation($orgId, $dateFrom, $dateTo, $data),
            'shift-analytics' => $this->shiftAnalytics($orgId, $dateFrom, $dateTo, $data),
            'billing' => $this->billingReport($orgId, $dateFrom, $dateTo, $data),
            'compliance' => $this->complianceReport($orgId, $data),
            'service-hours' => $this->serviceHours($orgId, $dateFrom, $dateTo, $data),
            default => [],
        };

        return inertia('operations/reports/Show', [
            'report_type' => $type,
            'report_meta' => self::REPORT_TYPES[$type],
            'data' => $reportData,
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'client_id' => $request->input('client_id'),
                'staff_id' => $request->input('staff_id'),
            ],
            'clients' => $this->usesClientFilter($type)
                ? $this->clientOptions($siteAccess, $auth, $orgId, $bypassPermissions)
                : [],
            'staff' => $this->usesStaffFilter($type)
                ? $this->staffOptions($siteAccess, $auth, $orgId, $bypassPermissions)
                : [],
        ]);
    }

    private function clientSummary(int $orgId, string $dateFrom, string $dateTo, array $filters): array
    {
        $query = BillingEntry::query()
            ->where('organization_id', $orgId)
            ->whereBetween('service_date', [$dateFrom, $dateTo])
            ->when(!empty($filters['allowed_site_ids']), fn ($q) => $this->applyBillingSiteScope($q, $filters['allowed_site_ids']))
            ->when(!empty($filters['client_id']), fn ($q) => $q->where('client_id', $filters['client_id']));

        return [
            'total_clients' => (clone $query)->distinct('client_id')->count('client_id'),
            'total_hours' => (float) (clone $query)->sum('hours'),
            'total_billed' => (float) (clone $query)->sum('amount'),
            'by_client' => (clone $query)
                ->selectRaw('client_id, SUM(hours) as total_hours, SUM(amount) as total_amount, COUNT(*) as entry_count')
                ->groupBy('client_id')
                ->with('client:id,first_name,last_name')
                ->get(),
        ];
    }

    private function staffUtilisation(int $orgId, string $dateFrom, string $dateTo, array $filters): array
    {
        $query = Timesheet::query()
            ->whereHas('client', fn ($q) => $q->where('organization_id', $orgId))
            ->whereBetween('work_date', [$dateFrom, $dateTo])
            ->when(!empty($filters['allowed_site_ids']), fn ($q) => $this->applyTimesheetSiteScope($q, $filters['allowed_site_ids']))
            ->when(!empty($filters['staff_id']), fn ($q) => $q->where('user_id', $filters['staff_id']));

        $timesheets = (clone $query)->with('user:id,name')->get();

        return [
            'total_staff' => $timesheets->pluck('user_id')->filter()->unique()->count(),
            'total_hours' => round((float) $timesheets->sum(fn (Timesheet $timesheet) => $timesheet->total_hours), 2),
            'by_staff' => $timesheets
                ->groupBy('user_id')
                ->map(function ($group, $userId) {
                    $first = $group->first();

                    return [
                        'user_id' => (int) $userId,
                        'staff_name' => $first?->user?->name,
                        'total_hours' => round((float) $group->sum(fn (Timesheet $timesheet) => $timesheet->total_hours), 2),
                        'shift_count' => $group->count(),
                        'approved_count' => $group->where('status', 'approved')->count(),
                    ];
                })
                ->values(),
        ];
    }

    private function shiftAnalytics(int $orgId, string $dateFrom, string $dateTo, array $filters): array
    {
        return app(ReportingService::class)->shiftAnalytics($orgId, $dateFrom, $dateTo, $filters);
    }

    private function billingReport(int $orgId, string $dateFrom, string $dateTo, array $filters): array
    {
        $query = BillingEntry::query()
            ->where('organization_id', $orgId)
            ->whereBetween('service_date', [$dateFrom, $dateTo])
            ->when(!empty($filters['allowed_site_ids']), fn ($q) => $this->applyBillingSiteScope($q, $filters['allowed_site_ids']));

        return [
            'total_entries' => (clone $query)->count(),
            'total_amount' => (float) (clone $query)->sum('amount'),
            'by_status' => (clone $query)
                ->selectRaw('status, COUNT(*) as count, SUM(amount) as total_amount')
                ->groupBy('status')
                ->get(),
            'by_rate_type' => (clone $query)
                ->selectRaw('rate_type, COUNT(*) as count, SUM(hours) as total_hours, SUM(amount) as total_amount')
                ->groupBy('rate_type')
                ->get(),
        ];
    }

    private function complianceReport(int $orgId, array $filters): array
    {
        return app(ReportingService::class)->complianceReport($orgId, $filters);
    }

    private function serviceHours(int $orgId, string $dateFrom, string $dateTo, array $filters): array
    {
        $query = BillingEntry::query()
            ->where('organization_id', $orgId)
            ->whereBetween('service_date', [$dateFrom, $dateTo])
            ->when(!empty($filters['allowed_site_ids']), fn ($q) => $this->applyBillingSiteScope($q, $filters['allowed_site_ids']))
            ->when(!empty($filters['client_id']), fn ($q) => $q->where('client_id', $filters['client_id']));

        return [
            'total_hours' => (float) (clone $query)->sum('hours'),
            'total_entries' => (clone $query)->count(),
            'by_client' => (clone $query)
                ->selectRaw('client_id, SUM(hours) as total_hours, COUNT(*) as entry_count')
                ->groupBy('client_id')
                ->with('client:id,first_name,last_name')
                ->get(),
            'by_site' => (clone $query)
                ->selectRaw("COALESCE(site_name_snapshot, 'Unknown site') as site_label, SUM(hours) as total_hours, COUNT(*) as entry_count")
                ->groupByRaw("COALESCE(site_name_snapshot, 'Unknown site')")
                ->orderByDesc('total_hours')
                ->get()
                ->map(fn ($row) => [
                    'site' => $row->site_label,
                    'total_hours' => round((float) $row->total_hours, 2),
                    'entry_count' => (int) $row->entry_count,
                ])
                ->values(),
        ];
    }

    private function usesClientFilter(string $type): bool
    {
        return in_array($type, ['client-summary', 'service-hours', 'compliance'], true);
    }

    private function usesStaffFilter(string $type): bool
    {
        return in_array($type, ['staff-utilisation', 'shift-analytics'], true);
    }

    private function clientOptions(UserSiteAccessService $siteAccess, User $user, ?int $orgId, array $bypassPermissions): array
    {
        return $siteAccess->applyClientScope(
            Client::query()
                ->when($orgId !== null, fn ($query) => $query->where('organization_id', $orgId))
                ->orderBy('first_name')
                ->orderBy('last_name'),
            $user,
            $bypassPermissions,
        )
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (Client $client) => [
                'id' => $client->id,
                'name' => trim($client->first_name.' '.$client->last_name),
            ])
            ->values()
            ->all();
    }

    private function staffOptions(UserSiteAccessService $siteAccess, User $user, ?int $orgId, array $bypassPermissions): array
    {
        return $siteAccess->applyStaffScope(
            User::query()
                ->staff()
                ->when($orgId !== null, fn ($query) => $query->where(function ($nested) use ($orgId) {
                    $nested->where('organization_id', $orgId)
                        ->orWhereNull('organization_id');
                }))
                ->orderBy('name'),
            $user,
            $bypassPermissions,
        )
            ->get(['id', 'name'])
            ->map(fn (User $staff) => [
                'id' => $staff->id,
                'name' => $staff->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    private function applyBillingSiteScope(Builder $query, array $siteIds): Builder
    {
        return $query->where(function (Builder $nested) use ($siteIds) {
            $nested->whereIn('site_id', $siteIds)
                ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery->whereIn('site_id', $siteIds));
        });
    }

    /**
     * @param  array<int, int>  $siteIds
     */
    private function applyTimesheetSiteScope(Builder $query, array $siteIds): Builder
    {
        return $query->where(function (Builder $nested) use ($siteIds) {
            $nested->whereIn('shift_site_id', $siteIds)
                ->orWhereHas('shift', fn (Builder $shiftQuery) => $shiftQuery
                    ->whereIn('site_id', $siteIds)
                    ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery->whereIn('site_id', $siteIds)))
                ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery->whereIn('site_id', $siteIds));
        });
    }
}
