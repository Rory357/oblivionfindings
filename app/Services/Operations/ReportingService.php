<?php

namespace App\Services\Operations;

use App\Models\BillingEntry;
use App\Models\ClientIncident;
use App\Models\ClientMedicationAdministration;
use App\Models\CustomFormSubmission;
use App\Models\FleetResidentTransport;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\ShiftTask;
use App\Models\StaffCredential;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\Schema;

class ReportingService
{
    public function __construct(
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function shiftAnalytics(int $orgId, string $dateFrom, string $dateTo, array $filters): array
    {
        $query = Shift::query()
            ->whereHas('client', fn ($q) => $q->where('organization_id', $orgId))
            ->whereBetween('starts_at', [$dateFrom, $dateTo.' 23:59:59'])
            ->when(! empty($filters['allowed_site_ids']), fn ($q) => $q->where(function ($siteQuery) use ($filters) {
                $siteIds = $filters['allowed_site_ids'];
                $siteQuery->whereIn('shifts.site_id', $siteIds)
                    ->orWhereHas('client', fn ($clientQuery) => $clientQuery->whereIn('clients.site_id', $siteIds));
            }))
            ->when(! empty($filters['client_id']), fn ($q) => $q->where('shifts.client_id', $filters['client_id']))
            ->when(! empty($filters['staff_id']), fn ($q) => $q->where('shifts.user_id', $filters['staff_id']));

        $total = (clone $query)->count();
        $completed = (clone $query)->where('status', 'completed')->count();
        $cancelled = (clone $query)->where('status', 'cancelled')->count();
        $noShow = (clone $query)->where('status', 'no_show')->count();
        $assigned = (clone $query)->whereNotNull('user_id')->count();
        $unassigned = max(0, $total - $assigned);
        $shiftIds = (clone $query)->pluck('id');

        $tasksTotal = ShiftTask::query()->whereIn('shift_id', $shiftIds)->count();
        $tasksCompleted = ShiftTask::query()
            ->whereIn('shift_id', $shiftIds)
            ->where('is_completed', true)
            ->count();
        $incidentCount = ClientIncident::query()->whereIn('shift_id', $shiftIds)->count();
        $formSubmissionCount = CustomFormSubmission::query()->whereIn('shift_id', $shiftIds)->count();
        $medicationRecordCount = ClientMedicationAdministration::query()->whereIn('shift_id', $shiftIds)->count();
        $handoverCount = ShiftHandover::query()
            ->tap(fn ($handoverQuery) => $this->siteAccess->applyHandoverIntegrityScope($handoverQuery))
            ->where(function ($query) use ($shiftIds) {
                $query->whereIn('outgoing_shift_id', $shiftIds)
                    ->orWhereIn('incoming_shift_id', $shiftIds);
            })
            ->count();
        $linkedTransportCount = Schema::hasTable('fleet_resident_transports')
            && Schema::hasColumn('fleet_resident_transports', 'shift_id')
            ? FleetResidentTransport::query()->whereIn('shift_id', $shiftIds)->count()
            : 0;
        $timesheetQuery = Timesheet::query()->whereIn('shift_id', $shiftIds);
        $workedHours = $timesheetQuery->get()->sum(fn (Timesheet $timesheet) => $timesheet->total_hours);
        $approvedWorkedHours = Timesheet::query()
            ->whereIn('shift_id', $shiftIds)
            ->where('status', 'approved')
            ->get()
            ->sum(fn (Timesheet $timesheet) => $timesheet->total_hours);
        $staffingCost = BillingEntry::query()->whereIn('shift_id', $shiftIds)->sum('payroll_cost');
        $billingValue = BillingEntry::query()->whereIn('shift_id', $shiftIds)->sum('amount');

        return [
            'total_shifts' => $total,
            'completed' => $completed,
            'cancelled' => $cancelled,
            'no_show' => $noShow,
            'assigned' => $assigned,
            'unassigned' => $unassigned,
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
            'cancellation_rate' => $total > 0 ? round(($cancelled / $total) * 100, 1) : 0,
            'assignment_rate' => $total > 0 ? round(($assigned / $total) * 100, 1) : 0,
            'by_status' => (clone $query)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
            'by_shift_type' => (clone $query)
                ->selectRaw("COALESCE(shifts.shift_type, 'standard') as shift_type_label, COUNT(*) as count")
                ->groupByRaw("COALESCE(shifts.shift_type, 'standard')")
                ->orderByDesc('count')
                ->get()
                ->map(fn ($row) => [
                    'shift_type' => $row->shift_type_label,
                    'count' => (int) $row->count,
                ])
                ->values(),
            'by_service_context' => (clone $query)
                ->leftJoin('service_contexts', 'service_contexts.id', '=', 'shifts.service_context_id')
                ->selectRaw("COALESCE(service_contexts.name, 'Unspecified') as service_context_label, COUNT(*) as count")
                ->groupByRaw("COALESCE(service_contexts.name, 'Unspecified')")
                ->orderByDesc('count')
                ->get()
                ->map(fn ($row) => [
                    'service_context' => $row->service_context_label,
                    'count' => (int) $row->count,
                ])
                ->values(),
            'by_day_of_week' => (clone $query)
                ->selectRaw('DAYOFWEEK(starts_at) as dow, COUNT(*) as count')
                ->groupBy('dow')
                ->pluck('count', 'dow'),
            'by_staff' => (clone $query)
                ->selectRaw('user_id, COUNT(*) as shift_count')
                ->groupBy('user_id')
                ->with('staff:id,name')
                ->limit(20)
                ->get(),
            'timesheet_statuses' => Timesheet::query()
                ->whereIn('shift_id', $shiftIds)
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get()
                ->map(fn ($row) => [
                    'status' => $row->status,
                    'count' => (int) $row->count,
                ])
                ->values(),
            'execution_evidence' => [
                'tasks_total' => $tasksTotal,
                'tasks_completed' => $tasksCompleted,
                'incidents_logged' => $incidentCount,
                'forms_submitted' => $formSubmissionCount,
                'medication_records' => $medicationRecordCount,
                'handovers_recorded' => $handoverCount,
                'linked_transports' => $linkedTransportCount,
            ],
            'coverage_vs_actual_work' => [
                'planned_shifts' => $total,
                'timesheets_recorded' => $timesheetQuery->count(),
                'worked_hours' => round((float) $workedHours, 2),
                'approved_worked_hours' => round((float) $approvedWorkedHours, 2),
            ],
            'cost_vs_staffing' => [
                'estimated_payroll_cost' => round((float) $staffingCost, 2),
                'billable_value' => round((float) $billingValue, 2),
                'operational_margin' => round((float) $billingValue - (float) $staffingCost, 2),
            ],
            'historical_site_breakdown' => BillingEntry::query()
                ->whereIn('shift_id', $shiftIds)
                ->selectRaw("COALESCE(site_name_snapshot, 'Unknown site') as site_label, COUNT(*) as entry_count, SUM(hours) as total_hours, SUM(amount) as total_amount, SUM(COALESCE(payroll_cost, 0)) as payroll_cost")
                ->groupByRaw("COALESCE(site_name_snapshot, 'Unknown site')")
                ->orderByDesc('total_hours')
                ->get()
                ->map(fn ($row) => [
                    'site' => $row->site_label,
                    'entry_count' => (int) $row->entry_count,
                    'total_hours' => round((float) $row->total_hours, 2),
                    'total_amount' => round((float) $row->total_amount, 2),
                    'payroll_cost' => round((float) $row->payroll_cost, 2),
                ])
                ->values(),
        ];
    }

    public function complianceReport(int $orgId, array $filters): array
    {
        $staffQuery = User::where('organization_id', $orgId)->staff();

        if (! empty($filters['allowed_site_ids'])) {
            $siteIds = array_values(array_map('intval', $filters['allowed_site_ids']));

            $staffQuery->where(function ($query) use ($siteIds) {
                $query->whereHas('hrEmployeeProfile', function ($profileQuery) use ($siteIds) {
                    $profileQuery->where(function ($siteProfileQuery) use ($siteIds) {
                        $siteProfileQuery->whereIn('primary_site_id', $siteIds);

                        foreach ($siteIds as $siteId) {
                            $siteProfileQuery->orWhereJsonContains('secondary_site_ids', $siteId);
                        }
                    });
                })->orWhereHas('shifts', fn ($shiftQuery) => $shiftQuery->whereIn('shifts.site_id', $siteIds));
            });
        }

        if (! empty($filters['client_id'])) {
            $staffQuery->whereHas('shifts', function ($shiftQuery) use ($filters) {
                $shiftQuery->where('shifts.client_id', $filters['client_id'])
                    ->when(! empty($filters['date_from']), fn ($query) => $query->where('starts_at', '>=', $filters['date_from']))
                    ->when(! empty($filters['date_to']), fn ($query) => $query->where('starts_at', '<=', $filters['date_to'].' 23:59:59'));
            });
        }

        if (! empty($filters['staff_id'])) {
            $staffQuery->whereKey((int) $filters['staff_id']);
        }

        $totalStaff = (clone $staffQuery)->count();

        $credentials = StaffCredential::whereIn('user_id', (clone $staffQuery)->select('id'))
            ->get();

        $expired = $credentials->filter(fn ($c) => $c->expires_at && $c->expires_at->isPast());
        $expiringSoon = $credentials->filter(fn ($c) => $c->expires_at && $c->expires_at->isBetween(now(), now()->addDays(30)));
        $valid = $credentials->filter(fn ($c) => ! $c->expires_at || $c->expires_at->isFuture());

        $byType = $credentials->groupBy('type')->map(function ($group) {
            return [
                'total' => $group->count(),
                'expired' => $group->filter(fn ($c) => $c->expires_at && $c->expires_at->isPast())->count(),
                'expiring_soon' => $group->filter(fn ($c) => $c->expires_at && $c->expires_at->isBetween(now(), now()->addDays(30)))->count(),
                'valid' => $group->filter(fn ($c) => ! $c->expires_at || $c->expires_at->isFuture())->count(),
            ];
        });

        return [
            'total_staff' => $totalStaff,
            'total_credentials' => $credentials->count(),
            'expired_count' => $expired->count(),
            'expiring_soon_count' => $expiringSoon->count(),
            'valid_count' => $valid->count(),
            'compliance_rate' => $credentials->count() > 0
                ? round(($valid->count() / $credentials->count()) * 100, 1)
                : 100,
            'by_type' => $byType,
            'expired_details' => $expired->take(50)->map(fn ($c) => [
                'user_id' => $c->user_id,
                'type' => $c->type,
                'expires_at' => $c->expires_at->toDateString(),
                'days_overdue' => $c->expires_at->diffInDays(now()),
            ])->values(),
        ];
    }
}
