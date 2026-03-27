<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\BillingEntry;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Operations\ReportingService;
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
        abort_unless($auth && $auth->canDo('reports.viewAny'), 403);

        return inertia('operations/reports/Index', [
            'reportTypes' => self::REPORT_TYPES,
        ]);
    }

    public function show(Request $request, $type)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('reports.view'), 403);

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
                'client_id' => $data['client_id'] ?? null,
                'staff_id' => $data['staff_id'] ?? null,
            ],
        ]);
    }

    private function clientSummary(int $orgId, string $dateFrom, string $dateTo, array $filters): array
    {
        $query = BillingEntry::query()
            ->where('organization_id', $orgId)
            ->whereBetween('service_date', [$dateFrom, $dateTo])
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
            ->where('organization_id', $orgId)
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->when(!empty($filters['staff_id']), fn ($q) => $q->where('user_id', $filters['staff_id']));

        return [
            'total_staff' => (clone $query)->distinct('user_id')->count('user_id'),
            'total_hours' => (float) (clone $query)->sum('total_hours'),
            'by_staff' => (clone $query)
                ->selectRaw('user_id, SUM(total_hours) as total_hours, COUNT(*) as shift_count')
                ->groupBy('user_id')
                ->with('user:id,name')
                ->get(),
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
            ->whereBetween('service_date', [$dateFrom, $dateTo]);

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
            ->when(!empty($filters['client_id']), fn ($q) => $q->where('client_id', $filters['client_id']));

        return [
            'total_hours' => (float) (clone $query)->sum('hours'),
            'total_entries' => (clone $query)->count(),
            'by_client' => (clone $query)
                ->selectRaw('client_id, SUM(hours) as total_hours, COUNT(*) as entry_count')
                ->groupBy('client_id')
                ->with('client:id,first_name,last_name')
                ->get(),
        ];
    }
}
