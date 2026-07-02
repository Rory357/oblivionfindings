<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\User;
use App\Services\Operations\ShiftReportingService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShiftReportController extends Controller
{
    public function __construct(
        protected ShiftReportingService $reporting,
    ) {}

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('operations.reports.view') || $user->canDo('reports.viewAny')), 403);

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $normalizedFilters = [
            'date_from' => $filters['date_from'] ?? now()->startOfMonth()->toDateString(),
            'date_to' => $filters['date_to'] ?? now()->endOfMonth()->toDateString(),
            'site_id' => $filters['site_id'] ?? null,
            'staff_id' => $filters['staff_id'] ?? null,
        ];

        $siteAccess = app(UserSiteAccessService::class);
        $bypassPermissions = ['reports.viewAny'];

        if (! empty($normalizedFilters['site_id'])) {
            $siteAccess->assertCanAccessSiteId(
                $user,
                (int) $normalizedFilters['site_id'],
                $bypassPermissions,
                'You are not authorized to view reports for that site.',
            );
        }

        $allowedSiteIds = $siteAccess->accessibleSiteIds($user, $bypassPermissions);
        $normalizedFilters['allowed_site_ids'] = $allowedSiteIds;

        return Inertia::render('operations/reports/Shifts', [
            'filters' => [
                'date_from' => $normalizedFilters['date_from'],
                'date_to' => $normalizedFilters['date_to'],
                'site_id' => $normalizedFilters['site_id'],
                'staff_id' => $normalizedFilters['staff_id'],
            ],
            'sites' => $siteAccess->applySiteScope(
                Site::query(),
                $user,
                $bypassPermissions,
            )
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Site $site) => [
                    'id' => $site->id,
                    'name' => $site->name,
                ])
                ->values(),
            'staff' => $siteAccess->applyStaffScope(
                User::query()->staff(),
                $user,
                $bypassPermissions,
            )
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $staff) => [
                    'id' => $staff->id,
                    'name' => $staff->name,
                ])
                ->values(),
            'report' => $this->reporting->build($normalizedFilters),
            'export_url' => route('operations.reports.shifts.export'),
        ]);
    }

    public function export(Request $request)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('operations.reports.view') || $user->canDo('reports.viewAny')), 403);

        $filters = $request->validate([
            'dataset' => ['required', 'string', 'in:staff-utilisation,coverage-gaps,reconciliation,attendance-variance,risk-summary'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'staff_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $siteAccess = app(UserSiteAccessService::class);
        $bypassPermissions = ['reports.viewAny'];

        if (! empty($filters['site_id'])) {
            $siteAccess->assertCanAccessSiteId(
                $user,
                (int) $filters['site_id'],
                $bypassPermissions,
                'You are not authorized to export reports for that site.',
            );
        }

        $payload = $this->reporting->exportDataset($filters['dataset'], [
            'date_from' => $filters['date_from'] ?? now()->startOfMonth()->toDateString(),
            'date_to' => $filters['date_to'] ?? now()->endOfMonth()->toDateString(),
            'site_id' => $filters['site_id'] ?? null,
            'staff_id' => $filters['staff_id'] ?? null,
            'allowed_site_ids' => $siteAccess->accessibleSiteIds($user, $bypassPermissions),
        ]);

        return response()->streamDownload(function () use ($payload) {
            $handle = fopen('php://output', 'w');

            $this->putCsv($handle, $payload['headers']);

            foreach ($payload['rows'] as $row) {
                $this->putCsv($handle, $row);
            }

            fclose($handle);
        }, $payload['filename'], [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
