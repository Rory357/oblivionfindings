<?php

namespace App\Services\Operations;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Models\ControlRoomAlert;
use App\Models\Shift;
use App\Models\ShiftSignal;
use App\Models\Timesheet;
use App\Services\ShiftCoverageService;
use App\Services\ShiftSignalService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ShiftReportingService
{
    protected const CHRONIC_SHORTAGE_WINDOW_THRESHOLD = 3;

    protected const OVERDUE_APPROVAL_DAYS = 3;

    protected const FREQUENT_START_ANOMALY_THRESHOLD = 2;

    public function __construct(
        protected ShiftCoverageService $coverage,
        protected TimesheetReconciliationService $reconciliation,
    ) {
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $normalized = $this->normalizeFilters($filters);

        $staffUtilisation = $this->buildStaffUtilisation($normalized);
        $coverageGapReport = $this->buildCoverageGapReport($normalized);
        $timesheetReconciliation = $this->buildTimesheetReconciliationReport($normalized);
        $attendanceVariance = $this->buildAttendanceVarianceReport($normalized);
        $riskSummary = $this->buildRiskSummary(
            $normalized,
            $staffUtilisation,
            $coverageGapReport,
            $timesheetReconciliation,
            $attendanceVariance,
        );

        return [
            'risk_summary' => $riskSummary,
            'staff_utilisation' => $staffUtilisation,
            'coverage_gap_report' => $coverageGapReport,
            'timesheet_reconciliation_report' => $timesheetReconciliation,
            'attendance_variance_report' => $attendanceVariance,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{filename:string, headers:array<int, string>, rows:array<int, array<int, mixed>>}
     */
    public function exportDataset(string $dataset, array $filters): array
    {
        $report = $this->build($filters);
        $safeDate = Carbon::parse($filters['date_to'] ?? now())->format('Y-m-d');

        return match ($dataset) {
            'staff-utilisation' => [
                'filename' => "shift-staff-utilisation-{$safeDate}.csv",
                'headers' => ['Staff', 'Total Shifts', 'Planned Hours', 'Worked Hours', 'Hours Per Week', 'Overtime Flag'],
                'rows' => collect($report['staff_utilisation']['rows'] ?? [])
                    ->map(fn (array $row) => [
                        $row['staff_name'] ?? 'Unknown',
                        $row['total_shifts'] ?? 0,
                        $row['planned_hours'] ?? 0,
                        $row['worked_hours'] ?? 0,
                        $row['hours_per_week'] ?? 0,
                        $row['overtime_flag'] ?? 'none',
                    ])->all(),
            ],
            'coverage-gaps' => [
                'filename' => "shift-coverage-gaps-{$safeDate}.csv",
                'headers' => ['Site', 'Rule', 'Window', 'Required Staff', 'Assigned Staff', 'Planned Staff', 'Deficit', 'Role Shortages', 'Gap Status'],
                'rows' => collect($report['coverage_gap_report']['rows'] ?? [])
                    ->map(fn (array $row) => [
                        $row['site_name'] ?? 'Site',
                        $row['rule_name'] ?? 'Coverage Rule',
                        $row['window_label'] ?? '',
                        $row['required_staff'] ?? 0,
                        $row['assigned_staff'] ?? 0,
                        $row['planned_staff'] ?? 0,
                        $row['deficit'] ?? 0,
                        $row['role_shortage_summary'] ?? '',
                        $row['gap_status'] ?? 'normal',
                    ])->all(),
            ],
            'reconciliation' => [
                'filename' => "shift-reconciliation-{$safeDate}.csv",
                'headers' => ['Bucket', 'Record ID', 'Date', 'Staff', 'Client', 'Site', 'Status', 'Summary'],
                'rows' => $this->reconciliationExportRows($report)->all(),
            ],
            'attendance-variance' => [
                'filename' => "shift-attendance-variance-{$safeDate}.csv",
                'headers' => ['Shift ID', 'Site', 'Staff', 'Client', 'Planned Start', 'Actual Start', 'Start Variance (min)', 'Planned End', 'Actual End', 'End Variance (min)', 'Start Flag', 'Completion Flag'],
                'rows' => collect($report['attendance_variance_report']['shift_rows'] ?? [])
                    ->map(fn (array $row) => [
                        $row['shift_id'] ?? null,
                        $row['site_name'] ?? 'Site',
                        $row['staff_name'] ?? 'Unknown',
                        $row['client_name'] ?? 'Client',
                        $row['planned_start'] ?? null,
                        $row['actual_start'] ?? null,
                        $row['start_variance_minutes'] ?? null,
                        $row['planned_end'] ?? null,
                        $row['actual_end'] ?? null,
                        $row['end_variance_minutes'] ?? null,
                        $row['start_flag'] ?? 'on_time',
                        $row['completion_flag'] ?? 'normal',
                    ])->all(),
            ],
            'risk-summary' => [
                'filename' => "shift-operational-risk-summary-{$safeDate}.csv",
                'headers' => ['Flag', 'Count', 'Severity', 'Reason'],
                'rows' => collect($report['risk_summary']['flags'] ?? [])
                    ->map(fn (array $row) => [
                        $row['label'] ?? 'Flag',
                        $row['count'] ?? 0,
                        $row['severity'] ?? 'info',
                        $row['reason'] ?? '',
                    ])->all(),
            ],
            default => throw new \InvalidArgumentException('Unknown shift report dataset.'),
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function normalizeFilters(array $filters): array
    {
        $start = isset($filters['date_from']) && $filters['date_from']
            ? Carbon::parse($filters['date_from'])->startOfDay()
            : now()->startOfMonth();
        $end = isset($filters['date_to']) && $filters['date_to']
            ? Carbon::parse($filters['date_to'])->endOfDay()
            : now()->endOfMonth();

        return [
            'date_from' => $start->toDateString(),
            'date_to' => $end->toDateString(),
            'site_id' => isset($filters['site_id']) && $filters['site_id'] !== null && $filters['site_id'] !== ''
                ? (int) $filters['site_id']
                : null,
            'staff_id' => isset($filters['staff_id']) && $filters['staff_id'] !== null && $filters['staff_id'] !== ''
                ? (int) $filters['staff_id']
                : null,
            'allowed_site_ids' => collect($filters['allowed_site_ids'] ?? [])
                ->map(fn ($siteId) => (int) $siteId)
                ->filter(fn (int $siteId) => $siteId > 0)
                ->unique()
                ->values()
                ->all(),
            'start' => $start,
            'end' => $end,
        ];
    }

    protected function applyShiftSiteFilter($query, int $siteId): void
    {
        $query->where(function ($nested) use ($siteId) {
            $nested->where('site_id', $siteId)
                ->orWhereHas('client', fn ($clientQuery) => $clientQuery->where('site_id', $siteId));
        });
    }

    protected function applyTimesheetSiteFilter($query, int $siteId): void
    {
        $query->where(function ($nested) use ($siteId) {
            $nested->where('shift_site_id', $siteId)
                ->orWhereHas('shift', fn ($shiftQuery) => $this->applyShiftSiteFilter($shiftQuery, $siteId))
                ->orWhereHas('client', fn ($clientQuery) => $clientQuery->where('site_id', $siteId));
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyNormalizedShiftSiteConstraint($query, array $filters): void
    {
        if (! empty($filters['site_id'])) {
            $this->applyShiftSiteFilter($query, (int) $filters['site_id']);

            return;
        }

        $allowedSiteIds = $this->allowedSiteIds($filters);
        if ($allowedSiteIds === []) {
            return;
        }

        $query->where(function ($nested) use ($allowedSiteIds) {
            $nested->whereIn('site_id', $allowedSiteIds)
                ->orWhereHas('client', fn ($clientQuery) => $clientQuery->whereIn('site_id', $allowedSiteIds));
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyNormalizedTimesheetSiteConstraint($query, array $filters): void
    {
        if (! empty($filters['site_id'])) {
            $this->applyTimesheetSiteFilter($query, (int) $filters['site_id']);

            return;
        }

        $allowedSiteIds = $this->allowedSiteIds($filters);
        if ($allowedSiteIds === []) {
            return;
        }

        $query->where(function ($nested) use ($allowedSiteIds) {
            $nested->whereIn('shift_site_id', $allowedSiteIds)
                ->orWhereHas('shift', function ($shiftQuery) use ($allowedSiteIds) {
                    $shiftQuery->where(function ($shiftSites) use ($allowedSiteIds) {
                        $shiftSites->whereIn('site_id', $allowedSiteIds)
                            ->orWhereHas('client', fn ($clientQuery) => $clientQuery->whereIn('site_id', $allowedSiteIds));
                    });
                })
                ->orWhereHas('client', fn ($clientQuery) => $clientQuery->whereIn('site_id', $allowedSiteIds));
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, int>
     */
    protected function allowedSiteIds(array $filters): array
    {
        return $filters['allowed_site_ids'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function buildStaffUtilisation(array $filters): array
    {
        $periodWeeks = $this->periodWeeks($filters['start'], $filters['end']);
        $warningThreshold = (float) config('hr.fatigue.warning_threshold_weekly', 40);
        $maxThreshold = (float) config('hr.fatigue.max_hours_per_week', 50);

        $shifts = Shift::query()
            ->with([
                'staff:id,name',
                'client:id,first_name,last_name,site_id',
                'site:id,name',
                'attendanceSessions:id,shift_id,user_id,clock_in_at,clock_out_at,break_minutes,status',
                'timesheets:id,shift_id,user_id,starts_at,ends_at,break_minutes,status',
            ])
            ->where('starts_at', '<=', $filters['end'])
            ->where('ends_at', '>=', $filters['start'])
            ->whereNotIn('status', ['cancelled'])
            ->whereNotNull('user_id')
            ->when($filters['staff_id'], fn ($query, $staffId) => $query->where('user_id', $staffId))
            ->tap(fn ($query) => $this->applyNormalizedShiftSiteConstraint($query, $filters))
            ->get();

        $rows = $shifts
            ->groupBy('user_id')
            ->map(function (Collection $staffShifts, $userId) use ($periodWeeks, $warningThreshold, $maxThreshold) {
                $plannedMinutes = $staffShifts->sum(fn (Shift $shift) => $this->shiftPlannedMinutes($shift));
                $workedMinutes = $staffShifts->sum(fn (Shift $shift) => $this->shiftWorkedMinutes($shift) ?? 0);
                $hoursPerWeek = round(($workedMinutes / 60) / $periodWeeks, 2);
                $overtimeFlag = $hoursPerWeek >= $maxThreshold
                    ? 'high'
                    : ($hoursPerWeek >= $warningThreshold ? 'warning' : 'none');

                return [
                    'user_id' => (int) $userId,
                    'staff_name' => $staffShifts->first()?->staff?->name ?? 'Unknown',
                    'total_shifts' => $staffShifts->count(),
                    'planned_hours' => round($plannedMinutes / 60, 2),
                    'worked_hours' => round($workedMinutes / 60, 2),
                    'hours_per_week' => $hoursPerWeek,
                    'overtime_flag' => $overtimeFlag,
                ];
            })
            ->sortByDesc('worked_hours')
            ->values();

        return [
            'total_staff' => $rows->count(),
            'total_shifts' => $shifts->count(),
            'total_planned_hours' => round($rows->sum('planned_hours'), 2),
            'total_worked_hours' => round($rows->sum('worked_hours'), 2),
            'period_weeks' => $periodWeeks,
            'rows' => $rows->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function buildCoverageGapReport(array $filters): array
    {
        $windows = collect($this->coverage->buildRangeCoverage(
            $filters['start'],
            $filters['end'],
            $filters['site_id'],
        ));

        if (! $filters['site_id'] && $this->allowedSiteIds($filters) !== []) {
            $windows = $windows
                ->filter(fn (array $window) => in_array((int) ($window['site_id'] ?? 0), $this->allowedSiteIds($filters), true))
                ->values();
        }

        $gapWindows = $windows
            ->filter(function (array $window): bool {
                return ! empty($window['has_actionable_gap'])
                    || ! empty($window['has_actionable_imbalance'])
                    || (int) ($window['missing_staff'] ?? 0) > 0
                    || ! empty($window['role_shortages']);
            })
            ->values();

        $rows = $gapWindows->map(function (array $window) {
            $deficit = (int) ($window['unfilled_after_open_shifts'] ?? $window['missing_staff'] ?? 0);
            $roleShortages = collect($window['role_shortages'] ?? [])
                ->map(fn (array $shortage) => sprintf(
                    '%s x%s',
                    $shortage['label'] ?? $shortage['key'] ?? 'role',
                    $shortage['missing'] ?? 0,
                ))
                ->implode(', ');

            return [
                'site_id' => $window['site_id'] ?? null,
                'site_name' => $window['site_name'] ?? 'Site',
                'rule_id' => $window['rule_id'] ?? null,
                'rule_name' => $window['rule_name'] ?? 'Coverage Rule',
                'window_label' => $window['window_label'] ?? null,
                'starts_at' => $window['starts_at'] ?? null,
                'ends_at' => $window['ends_at'] ?? null,
                'required_staff' => (int) ($window['required_staff'] ?? 0),
                'assigned_staff' => (int) ($window['assigned_staff'] ?? 0),
                'planned_staff' => (int) ($window['planned_staff'] ?? $window['assigned_staff'] ?? 0),
                'deficit' => $deficit,
                'missing_staff' => (int) ($window['missing_staff'] ?? 0),
                'gap_status' => $deficit > 0 ? 'deficit' : 'role_gap',
                'role_shortages' => $window['role_shortages'] ?? [],
                'role_shortage_summary' => $roleShortages,
            ];
        });

        $trendRows = $gapWindows
            ->groupBy(fn (array $window) => Carbon::parse($window['starts_at'])->toDateString())
            ->map(fn (Collection $dailyWindows, string $date) => [
                'date' => $date,
                'gap_windows' => $dailyWindows->count(),
                'total_deficit' => (int) $dailyWindows->sum(fn (array $window) => (int) ($window['unfilled_after_open_shifts'] ?? $window['missing_staff'] ?? 0)),
            ])
            ->sortBy('date')
            ->values();

        $chronicRows = $this->buildChronicShortageRows($gapWindows);
        $unresolvedUncovered = $this->unresolvedUncoveredAlertCount($filters);

        return [
            'gap_window_count' => $rows->count(),
            'total_required_staff' => (int) $gapWindows->sum('required_staff'),
            'total_assigned_staff' => (int) $gapWindows->sum('assigned_staff'),
            'total_deficit' => (int) $rows->sum('deficit'),
            'unresolved_uncovered_count' => $unresolvedUncovered,
            'chronic_shortage_count' => count($chronicRows),
            'rows' => $rows->all(),
            'trend_rows' => $trendRows->all(),
            'chronic_rows' => $chronicRows,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function buildTimesheetReconciliationReport(array $filters): array
    {
        $timesheets = Timesheet::query()
            ->with([
                'staff:id,name',
                'client:id,first_name,last_name,site_id',
                'shift:id,site_id,status,starts_at,ends_at',
                'shift.site:id,name',
                'client.site:id,name',
                'shiftSite:id,name',
            ])
            ->whereBetween('work_date', [$filters['start']->toDateString(), $filters['end']->toDateString()])
            ->when($filters['staff_id'], fn ($query, $staffId) => $query->where('user_id', $staffId))
            ->tap(fn ($query) => $this->applyNormalizedTimesheetSiteConstraint($query, $filters))
            ->get();

        $blockedRows = $timesheets
            ->where('reconciliation_status', TimesheetReconciliationService::STATUS_BLOCKED)
            ->map(fn (Timesheet $timesheet) => $this->mapTimesheetReviewRow($timesheet, 'blocked'))
            ->values();

        $reviewRows = $timesheets
            ->where('reconciliation_status', TimesheetReconciliationService::STATUS_REVIEW)
            ->map(fn (Timesheet $timesheet) => $this->mapTimesheetReviewRow($timesheet, 'review'))
            ->values();

        $approvedNotExportedRows = $timesheets
            ->where('status', 'approved')
            ->filter(fn (Timesheet $timesheet) => ! $timesheet->exported_to_payroll_at && ! $timesheet->payroll_reference)
            ->map(fn (Timesheet $timesheet) => [
                'timesheet_id' => $timesheet->id,
                'work_date' => optional($timesheet->work_date)->toDateString(),
                'staff_name' => $timesheet->staff?->name ?? 'Unknown',
                'client_name' => $timesheet->client?->full_name ?? 'Client',
                'site_name' => $timesheet->shiftSite?->name
                    ?? $timesheet->shift?->site?->name
                    ?? $timesheet->client?->site?->name
                    ?? 'Site',
                'status' => $timesheet->status,
                'summary' => 'Approved timesheet is still waiting for payroll export.',
            ])
            ->values();

        $completedShifts = Shift::query()
            ->with(['staff:id,name', 'client:id,first_name,last_name,site_id', 'site:id,name', 'client.site:id,name'])
            ->where('status', 'completed')
            ->whereNotNull('user_id')
            ->whereBetween(\DB::raw('COALESCE(actual_ends_at, ends_at)'), [$filters['start'], $filters['end']])
            ->when($filters['staff_id'], fn ($query, $staffId) => $query->where('user_id', $staffId))
            ->tap(fn ($query) => $this->applyNormalizedShiftSiteConstraint($query, $filters))
            ->get()
            ->values();
        $completedShiftPairs = $this->existingTimesheetPairs(
            $completedShifts->map(fn (Shift $shift) => [
                'shift_id' => (int) $shift->id,
                'user_id' => (int) $shift->user_id,
            ])
        );
        $completedShiftRows = $completedShifts
            ->filter(fn (Shift $shift) => ! isset($completedShiftPairs[$this->timesheetPairKey((int) $shift->id, (int) $shift->user_id)]))
            ->map(fn (Shift $shift) => [
                'shift_id' => $shift->id,
                'date' => optional($shift->actual_ends_at ?? $shift->ends_at)->toDateString(),
                'staff_name' => $shift->staff?->name ?? 'Unknown',
                'client_name' => $shift->client?->full_name ?? 'Client',
                'site_name' => $shift->site?->name ?? $shift->client?->site?->name ?? 'Site',
                'status' => $shift->status,
                'summary' => 'Completed shift has no timesheet.',
            ])
            ->values();

        $attendanceSessions = HrAttendanceSession::query()
            ->with([
                'user:id,name',
                'site:id,name',
                'shift:id,site_id,client_id',
                'shift.site:id,name',
                'shift.client:id,first_name,last_name,site_id',
                'shift.client.site:id,name',
                'timesheet:id,attendance_session_id',
            ])
            ->whereNotNull('clock_out_at')
            ->whereBetween(\DB::raw('COALESCE(clock_out_at, clock_in_at)'), [$filters['start'], $filters['end']])
            ->when($filters['staff_id'], fn ($query, $staffId) => $query->where('user_id', $staffId))
            ->tap(function ($query) use ($filters) {
                if (! empty($filters['site_id'])) {
                    $siteId = (int) $filters['site_id'];

                    $query->where(function ($nested) use ($siteId) {
                        $nested->where('site_id', $siteId)
                            ->orWhereHas('shift', fn ($shiftQuery) => $this->applyShiftSiteFilter($shiftQuery, $siteId));
                    });

                    return;
                }

                $allowedSiteIds = $this->allowedSiteIds($filters);
                if ($allowedSiteIds === []) {
                    return;
                }

                $query->where(function ($nested) use ($allowedSiteIds) {
                    $nested->whereIn('site_id', $allowedSiteIds)
                        ->orWhereHas('shift', function ($shiftQuery) use ($allowedSiteIds) {
                            $shiftQuery->where(function ($shiftSites) use ($allowedSiteIds) {
                                $shiftSites->whereIn('site_id', $allowedSiteIds)
                                    ->orWhereHas('client', fn ($clientQuery) => $clientQuery->whereIn('site_id', $allowedSiteIds));
                            });
                        });
                });
            })
            ->get()
            ->values();

        $attendancePairs = $this->existingTimesheetPairs(
            $attendanceSessions
                ->filter(fn (HrAttendanceSession $session) => ! $session->timesheet && $session->shift_id)
                ->map(fn (HrAttendanceSession $session) => [
                    'shift_id' => (int) $session->shift_id,
                    'user_id' => (int) $session->user_id,
                ])
        );

        $attendanceRows = $attendanceSessions
            ->filter(function (HrAttendanceSession $session) use ($attendancePairs): bool {
                if ($session->timesheet) {
                    return false;
                }

                if (! $session->shift_id) {
                    return true;
                }

                return ! isset($attendancePairs[$this->timesheetPairKey((int) $session->shift_id, (int) $session->user_id)]);
            })
            ->map(fn (HrAttendanceSession $session) => [
                'attendance_session_id' => $session->id,
                'date' => optional($session->clock_out_at ?? $session->clock_in_at)->toDateString(),
                'staff_name' => $session->user?->name ?? 'Unknown',
                'client_name' => $session->shift?->client?->full_name ?? 'Client',
                'site_name' => $session->site?->name
                    ?? $session->shift?->site?->name
                    ?? $session->shift?->client?->site?->name
                    ?? 'Site',
                'status' => $session->status,
                'summary' => 'Attendance session has no matching timesheet.',
            ])
            ->values();

        return [
            'blocked_count' => $blockedRows->count(),
            'review_count' => $reviewRows->count(),
            'completed_shift_without_timesheet_count' => $completedShiftRows->count(),
            'attendance_without_timesheet_count' => $attendanceRows->count(),
            'approved_not_exported_count' => $approvedNotExportedRows->count(),
            'blocked_rows' => $blockedRows->all(),
            'review_rows' => $reviewRows->all(),
            'completed_shift_without_timesheet_rows' => $completedShiftRows->all(),
            'attendance_without_timesheet_rows' => $attendanceRows->all(),
            'approved_not_exported_rows' => $approvedNotExportedRows->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function buildAttendanceVarianceReport(array $filters): array
    {
        $shifts = Shift::query()
            ->with([
                'staff:id,name',
                'client:id,first_name,last_name,site_id',
                'site:id,name',
                'client.site:id,name',
                'attendanceSessions:id,shift_id,user_id,clock_in_at,clock_out_at,break_minutes,status',
            ])
            ->where('starts_at', '<=', $filters['end'])
            ->where('ends_at', '>=', $filters['start'])
            ->whereNotIn('status', ['cancelled'])
            ->whereNotNull('user_id')
            ->when($filters['staff_id'], fn ($query, $staffId) => $query->where('user_id', $staffId))
            ->tap(fn ($query) => $this->applyNormalizedShiftSiteConstraint($query, $filters))
            ->get();

        $shiftRows = $shifts->map(function (Shift $shift) {
            $actualStart = $this->shiftActualStart($shift);
            $actualEnd = $this->shiftActualEnd($shift);
            $startVariance = $actualStart && $shift->starts_at
                ? $shift->starts_at->diffInMinutes($actualStart, false)
                : null;
            $endVariance = $actualEnd && $shift->ends_at
                ? $shift->ends_at->diffInMinutes($actualEnd, false)
                : null;

            return [
                'shift_id' => $shift->id,
                'site_name' => $shift->site?->name ?? $shift->client?->site?->name ?? 'Site',
                'staff_name' => $shift->staff?->name ?? 'Unknown',
                'client_name' => $shift->client?->full_name ?? 'Client',
                'planned_start' => $shift->starts_at?->toISOString(),
                'actual_start' => $actualStart?->toISOString(),
                'start_variance_minutes' => $startVariance,
                'planned_end' => $shift->ends_at?->toISOString(),
                'actual_end' => $actualEnd?->toISOString(),
                'end_variance_minutes' => $endVariance,
                'start_flag' => $this->varianceFlag($startVariance),
                'completion_flag' => $actualEnd ? $this->varianceFlag($endVariance) : 'incomplete',
            ];
        })->values();

        $signalCounts = $this->shiftSignalCounts($filters);

        $byStaff = $shiftRows
            ->groupBy('staff_name')
            ->map(function (Collection $rows, string $staffName) use ($signalCounts) {
                $signals = $signalCounts['by_staff'][$staffName] ?? [];

                return [
                    'staff_name' => $staffName,
                    'shift_count' => $rows->count(),
                    'avg_start_variance_minutes' => round((float) $rows->pluck('start_variance_minutes')->filter(fn ($value) => $value !== null)->avg(), 2),
                    'avg_end_variance_minutes' => round((float) $rows->pluck('end_variance_minutes')->filter(fn ($value) => $value !== null)->avg(), 2),
                    'no_show_count' => $signals[ShiftSignalService::TYPE_NO_SHOW] ?? 0,
                    'late_start_count' => $signals[ShiftSignalService::TYPE_LATE_START] ?? 0,
                    'not_completed_count' => $signals[ShiftSignalService::TYPE_NOT_COMPLETED] ?? 0,
                ];
            })
            ->sortByDesc('late_start_count')
            ->values();

        $bySite = $shiftRows
            ->groupBy('site_name')
            ->map(function (Collection $rows, string $siteName) use ($signalCounts) {
                $signals = $signalCounts['by_site'][$siteName] ?? [];

                return [
                    'site_name' => $siteName,
                    'shift_count' => $rows->count(),
                    'avg_start_variance_minutes' => round((float) $rows->pluck('start_variance_minutes')->filter(fn ($value) => $value !== null)->avg(), 2),
                    'avg_end_variance_minutes' => round((float) $rows->pluck('end_variance_minutes')->filter(fn ($value) => $value !== null)->avg(), 2),
                    'no_show_count' => $signals[ShiftSignalService::TYPE_NO_SHOW] ?? 0,
                    'late_start_count' => $signals[ShiftSignalService::TYPE_LATE_START] ?? 0,
                    'not_completed_count' => $signals[ShiftSignalService::TYPE_NOT_COMPLETED] ?? 0,
                ];
            })
            ->sortByDesc('late_start_count')
            ->values();

        return [
            'avg_start_variance_minutes' => round((float) $shiftRows->pluck('start_variance_minutes')->filter(fn ($value) => $value !== null)->avg(), 2),
            'avg_end_variance_minutes' => round((float) $shiftRows->pluck('end_variance_minutes')->filter(fn ($value) => $value !== null)->avg(), 2),
            'no_show_count' => $signalCounts['totals'][ShiftSignalService::TYPE_NO_SHOW] ?? 0,
            'late_start_count' => $signalCounts['totals'][ShiftSignalService::TYPE_LATE_START] ?? 0,
            'not_completed_count' => $signalCounts['totals'][ShiftSignalService::TYPE_NOT_COMPLETED] ?? 0,
            'shift_rows' => $shiftRows->all(),
            'by_staff' => $byStaff->all(),
            'by_site' => $bySite->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $staffUtilisation
     * @param  array<string, mixed>  $coverageGapReport
     * @param  array<string, mixed>  $timesheetReconciliation
     * @param  array<string, mixed>  $attendanceVariance
     * @return array<string, mixed>
     */
    protected function buildRiskSummary(
        array $filters,
        array $staffUtilisation,
        array $coverageGapReport,
        array $timesheetReconciliation,
        array $attendanceVariance,
    ): array {
        $overdueApprovals = Timesheet::query()
            ->where('status', 'submitted')
            ->whereBetween('work_date', [$filters['start']->toDateString(), $filters['end']->toDateString()])
            ->whereNotNull('submitted_at')
            ->where('submitted_at', '<=', now()->subDays(self::OVERDUE_APPROVAL_DAYS))
            ->when($filters['staff_id'], fn ($query, $staffId) => $query->where('user_id', $staffId))
            ->tap(fn ($query) => $this->applyNormalizedTimesheetSiteConstraint($query, $filters))
            ->count();

        $frequentStartAnomalyStaff = collect($attendanceVariance['by_staff'] ?? [])
            ->filter(fn (array $row) => (($row['no_show_count'] ?? 0) + ($row['late_start_count'] ?? 0)) >= self::FREQUENT_START_ANOMALY_THRESHOLD)
            ->count();

        $overtimeRiskStaff = collect($staffUtilisation['rows'] ?? [])
            ->whereIn('overtime_flag', ['warning', 'high'])
            ->count();

        $flags = collect([
            [
                'key' => 'blocked_reconciliation',
                'label' => 'Blocked Reconciliation',
                'count' => (int) ($timesheetReconciliation['blocked_count'] ?? 0),
                'severity' => 'high',
                'reason' => 'Timesheets with blocking reconciliation findings need payroll review before progression.',
            ],
            [
                'key' => 'overdue_approvals',
                'label' => 'Overdue Approvals',
                'count' => $overdueApprovals,
                'severity' => $overdueApprovals > 0 ? 'medium' : 'low',
                'reason' => 'Submitted timesheets older than three days are still waiting for approval.',
            ],
            [
                'key' => 'uncovered_shifts',
                'label' => 'Uncovered Shifts',
                'count' => (int) ($coverageGapReport['unresolved_uncovered_count'] ?? 0),
                'severity' => ((int) ($coverageGapReport['unresolved_uncovered_count'] ?? 0)) > 0 ? 'high' : 'low',
                'reason' => 'Coverage windows with active staffing deficits still have unresolved uncovered alerts.',
            ],
            [
                'key' => 'chronic_shortages',
                'label' => 'Chronic Shortages',
                'count' => (int) ($coverageGapReport['chronic_shortage_count'] ?? 0),
                'severity' => ((int) ($coverageGapReport['chronic_shortage_count'] ?? 0)) > 0 ? 'high' : 'low',
                'reason' => 'The same site or role has repeated deficit windows in the selected period.',
            ],
            [
                'key' => 'frequent_start_anomalies',
                'label' => 'Frequent Late Starts / No-Shows',
                'count' => $frequentStartAnomalyStaff,
                'severity' => $frequentStartAnomalyStaff > 0 ? 'medium' : 'low',
                'reason' => 'Staff with repeated late-start or no-show anomalies need workload or roster review.',
            ],
            [
                'key' => 'overtime_risk',
                'label' => 'Overtime Risk',
                'count' => $overtimeRiskStaff,
                'severity' => $overtimeRiskStaff > 0 ? 'medium' : 'low',
                'reason' => 'Staff approaching or exceeding weekly fatigue thresholds need scheduling review.',
            ],
            [
                'key' => 'approved_not_exported',
                'label' => 'Payroll Export Backlog',
                'count' => (int) ($timesheetReconciliation['approved_not_exported_count'] ?? 0),
                'severity' => ((int) ($timesheetReconciliation['approved_not_exported_count'] ?? 0)) > 0 ? 'medium' : 'low',
                'reason' => 'Approved timesheets still waiting for payroll export can delay payroll completion.',
            ],
        ])->values();

        return [
            'high_risk_reconciliation_count' => (int) ($timesheetReconciliation['blocked_count'] ?? 0),
            'overdue_timesheet_approvals_count' => $overdueApprovals,
            'uncovered_shifts_count' => (int) ($coverageGapReport['unresolved_uncovered_count'] ?? 0),
            'frequent_start_anomaly_staff_count' => $frequentStartAnomalyStaff,
            'overtime_risk_staff_count' => $overtimeRiskStaff,
            'approved_not_exported_count' => (int) ($timesheetReconciliation['approved_not_exported_count'] ?? 0),
            'chronic_shortage_count' => (int) ($coverageGapReport['chronic_shortage_count'] ?? 0),
            'flags' => $flags->all(),
        ];
    }

    protected function shiftPlannedMinutes(Shift $shift): int
    {
        if (! $shift->starts_at || ! $shift->ends_at) {
            return 0;
        }

        return max(0, (int) $shift->starts_at->diffInMinutes($shift->ends_at) - (int) ($shift->expected_break_minutes ?? 0));
    }

    protected function shiftWorkedMinutes(Shift $shift): ?int
    {
        $attendanceMinutes = $shift->attendanceSessions
            ->filter(fn (HrAttendanceSession $session) => (int) $session->user_id === (int) $shift->user_id)
            ->sum(function (HrAttendanceSession $session) {
                if (! $session->clock_in_at || ! $session->clock_out_at) {
                    return 0;
                }

                return max(0, (int) $session->clock_in_at->diffInMinutes($session->clock_out_at) - (int) ($session->break_minutes ?? 0));
            });

        if ($attendanceMinutes > 0) {
            return (int) $attendanceMinutes;
        }

        $timesheet = $shift->timesheets
            ->first(fn (Timesheet $timesheet) => (int) $timesheet->user_id === (int) $shift->user_id);

        return $timesheet ? (int) $timesheet->total_minutes : null;
    }

    protected function shiftActualStart(Shift $shift): ?CarbonInterface
    {
        if ($shift->actual_starts_at) {
            return $shift->actual_starts_at;
        }

        return $shift->attendanceSessions
            ->filter(fn (HrAttendanceSession $session) => (int) $session->user_id === (int) $shift->user_id)
            ->whereNotNull('clock_in_at')
            ->sortBy('clock_in_at')
            ->first()?->clock_in_at;
    }

    protected function shiftActualEnd(Shift $shift): ?CarbonInterface
    {
        if ($shift->actual_ends_at) {
            return $shift->actual_ends_at;
        }

        return $shift->attendanceSessions
            ->filter(fn (HrAttendanceSession $session) => (int) $session->user_id === (int) $shift->user_id)
            ->whereNotNull('clock_out_at')
            ->sortByDesc('clock_out_at')
            ->first()?->clock_out_at;
    }

    protected function periodWeeks(CarbonInterface $start, CarbonInterface $end): float
    {
        $days = max(1, $start->copy()->startOfDay()->diffInDays($end->copy()->endOfDay()) + 1);

        return round(max(1, $days / 7), 2);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $gapWindows
     * @return array<int, array<string, mixed>>
     */
    protected function buildChronicShortageRows(Collection $gapWindows): array
    {
        return $gapWindows
            ->flatMap(function (array $window) {
                $siteId = $window['site_id'] ?? null;
                $siteName = $window['site_name'] ?? 'Site';
                $startsAt = $window['starts_at'] ?? null;
                $roleShortages = $window['role_shortages'] ?? [];
                $deficit = (int) ($window['unfilled_after_open_shifts'] ?? $window['missing_staff'] ?? 0);

                if ($roleShortages !== []) {
                    return collect($roleShortages)->map(fn (array $shortage) => [
                        'group_key' => implode(':', [$siteId, $shortage['key'] ?? 'role']),
                        'site_id' => $siteId,
                        'site_name' => $siteName,
                        'role_key' => $shortage['key'] ?? null,
                        'role_name' => $shortage['label'] ?? $shortage['key'] ?? 'Role',
                        'missing' => (int) ($shortage['missing'] ?? 0),
                        'starts_at' => $startsAt,
                    ]);
                }

                if ($deficit <= 0) {
                    return collect();
                }

                return collect([[
                    'group_key' => implode(':', [$siteId, 'headcount', $window['rule_id'] ?? 'rule']),
                    'site_id' => $siteId,
                    'site_name' => $siteName,
                    'role_key' => null,
                    'role_name' => 'Headcount',
                    'missing' => $deficit,
                    'starts_at' => $startsAt,
                ]]);
            })
            ->groupBy('group_key')
            ->map(function (Collection $rows) {
                $first = $rows->first();

                return [
                    'site_id' => $first['site_id'] ?? null,
                    'site_name' => $first['site_name'] ?? 'Site',
                    'role_key' => $first['role_key'] ?? null,
                    'role_name' => $first['role_name'] ?? 'Role',
                    'gap_windows' => $rows->count(),
                    'total_missing' => (int) $rows->sum('missing'),
                    'latest_window_start' => $rows->max('starts_at'),
                ];
            })
            ->filter(fn (array $row) => $row['gap_windows'] >= self::CHRONIC_SHORTAGE_WINDOW_THRESHOLD)
            ->sortByDesc('gap_windows')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function unresolvedUncoveredAlertCount(array $filters): int
    {
        return ControlRoomAlert::query()
            ->unresolved()
            ->where('source', 'shift_operations')
            ->where('alert_type', app(ShiftSignalService::class)->alertTypeForSignalType(ShiftSignalService::TYPE_UNCOVERED))
            ->whereBetween('triggered_at', [$filters['start'], $filters['end']])
            ->tap(function ($query) use ($filters) {
                if (! empty($filters['site_id'])) {
                    $query->where('site_id', $filters['site_id']);

                    return;
                }

                $allowedSiteIds = $this->allowedSiteIds($filters);
                if ($allowedSiteIds !== []) {
                    $query->whereIn('site_id', $allowedSiteIds);
                }
            })
            ->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    protected function shiftSignalCounts(array $filters): array
    {
        $signals = ShiftSignal::query()
            ->with([
                'shift:id,site_id,client_id,user_id',
                'shift.staff:id,name',
                'shift.site:id,name',
                'shift.client:id,first_name,last_name,site_id',
                'shift.client.site:id,name',
                'site:id,name',
                'staff:id,name',
            ])
            ->whereNotNull('shift_id')
            ->whereIn('signal_type', [
                ShiftSignalService::TYPE_NO_SHOW,
                ShiftSignalService::TYPE_LATE_START,
                ShiftSignalService::TYPE_NOT_COMPLETED,
            ])
            ->whereBetween('occurred_at', [$filters['start'], $filters['end']])
            ->tap(function ($query) use ($filters) {
                if (! empty($filters['site_id'])) {
                    $siteId = (int) $filters['site_id'];

                    $query->where(function ($nested) use ($siteId) {
                        $nested->where('site_id', $siteId)
                            ->orWhereHas('shift', fn ($shiftQuery) => $this->applyShiftSiteFilter($shiftQuery, $siteId));
                    });

                    return;
                }

                $allowedSiteIds = $this->allowedSiteIds($filters);
                if ($allowedSiteIds === []) {
                    return;
                }

                $query->where(function ($nested) use ($allowedSiteIds) {
                    $nested->whereIn('site_id', $allowedSiteIds)
                        ->orWhereHas('shift', function ($shiftQuery) use ($allowedSiteIds) {
                            $shiftQuery->where(function ($shiftSites) use ($allowedSiteIds) {
                                $shiftSites->whereIn('site_id', $allowedSiteIds)
                                    ->orWhereHas('client', fn ($clientQuery) => $clientQuery->whereIn('site_id', $allowedSiteIds));
                            });
                        });
                });
            })
            ->when($filters['staff_id'], fn ($query, $staffId) => $query->where('user_id', $staffId))
            ->get()
            ->unique(fn (ShiftSignal $signal) => $signal->shift_id.'|'.$signal->signal_type);

        return [
            'totals' => $signals->groupBy('signal_type')->map->count()->all(),
            'by_staff' => $signals
                ->groupBy(fn (ShiftSignal $signal) => $signal->shift?->staff?->name ?? $signal->staff?->name ?? 'Unknown')
                ->map(fn (Collection $staffSignals) => $staffSignals->groupBy('signal_type')->map->count()->all())
                ->all(),
            'by_site' => $signals
                ->groupBy(fn (ShiftSignal $signal) => $signal->shift?->site?->name ?? $signal->shift?->client?->site?->name ?? $signal->site?->name ?? 'Site')
                ->map(fn (Collection $siteSignals) => $siteSignals->groupBy('signal_type')->map->count()->all())
                ->all(),
        ];
    }

    protected function mapTimesheetReviewRow(Timesheet $timesheet, string $bucket): array
    {
        return [
            'timesheet_id' => $timesheet->id,
            'bucket' => $bucket,
            'work_date' => optional($timesheet->work_date)->toDateString(),
            'staff_name' => $timesheet->staff?->name ?? 'Unknown',
            'client_name' => $timesheet->client?->full_name ?? 'Client',
            'site_name' => $timesheet->shiftSite?->name
                ?? $timesheet->shift?->site?->name
                ?? $timesheet->client?->site?->name
                ?? 'Site',
            'status' => $timesheet->status,
            'summary' => $timesheet->reconciliation_summary,
            'severity' => $timesheet->reconciliation_severity,
        ];
    }

    protected function varianceFlag(?int $varianceMinutes): string
    {
        if ($varianceMinutes === null) {
            return 'unknown';
        }

        if ($varianceMinutes >= 15) {
            return 'late';
        }

        if ($varianceMinutes <= -15) {
            return 'early';
        }

        return 'on_time';
    }

    /**
     * @param  array<string, mixed>  $report
     * @return \Illuminate\Support\Collection<int, array<int, mixed>>
     */
    protected function reconciliationExportRows(array $report): Collection
    {
        return collect()
            ->concat(collect($report['timesheet_reconciliation_report']['blocked_rows'] ?? [])->map(fn (array $row) => [
                'Blocked',
                $row['timesheet_id'] ?? null,
                $row['work_date'] ?? null,
                $row['staff_name'] ?? 'Unknown',
                $row['client_name'] ?? 'Client',
                $row['site_name'] ?? 'Site',
                $row['status'] ?? null,
                $row['summary'] ?? '',
            ]))
            ->concat(collect($report['timesheet_reconciliation_report']['review_rows'] ?? [])->map(fn (array $row) => [
                'Review',
                $row['timesheet_id'] ?? null,
                $row['work_date'] ?? null,
                $row['staff_name'] ?? 'Unknown',
                $row['client_name'] ?? 'Client',
                $row['site_name'] ?? 'Site',
                $row['status'] ?? null,
                $row['summary'] ?? '',
            ]))
            ->concat(collect($report['timesheet_reconciliation_report']['completed_shift_without_timesheet_rows'] ?? [])->map(fn (array $row) => [
                'Completed Shift Missing Timesheet',
                $row['shift_id'] ?? null,
                $row['date'] ?? null,
                $row['staff_name'] ?? 'Unknown',
                $row['client_name'] ?? 'Client',
                $row['site_name'] ?? 'Site',
                $row['status'] ?? null,
                $row['summary'] ?? '',
            ]))
            ->concat(collect($report['timesheet_reconciliation_report']['attendance_without_timesheet_rows'] ?? [])->map(fn (array $row) => [
                'Attendance Missing Timesheet',
                $row['attendance_session_id'] ?? null,
                $row['date'] ?? null,
                $row['staff_name'] ?? 'Unknown',
                $row['client_name'] ?? 'Client',
                $row['site_name'] ?? 'Site',
                $row['status'] ?? null,
                $row['summary'] ?? '',
            ]))
            ->concat(collect($report['timesheet_reconciliation_report']['approved_not_exported_rows'] ?? [])->map(fn (array $row) => [
                'Approved Not Exported',
                $row['timesheet_id'] ?? null,
                $row['work_date'] ?? null,
                $row['staff_name'] ?? 'Unknown',
                $row['client_name'] ?? 'Client',
                $row['site_name'] ?? 'Site',
                $row['status'] ?? null,
                $row['summary'] ?? '',
            ]))
            ->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{shift_id:int, user_id:int}>  $pairs
     * @return array<string, true>
     */
    protected function existingTimesheetPairs(Collection $pairs): array
    {
        $normalizedPairs = $pairs
            ->filter(fn (array $pair) => ($pair['shift_id'] ?? 0) > 0 && ($pair['user_id'] ?? 0) > 0)
            ->map(fn (array $pair) => [
                'shift_id' => (int) $pair['shift_id'],
                'user_id' => (int) $pair['user_id'],
            ])
            ->unique(fn (array $pair) => $this->timesheetPairKey($pair['shift_id'], $pair['user_id']))
            ->values();

        if ($normalizedPairs->isEmpty()) {
            return [];
        }

        return Timesheet::query()
            ->where(function ($query) use ($normalizedPairs) {
                foreach ($normalizedPairs as $pair) {
                    $query->orWhere(function ($nested) use ($pair) {
                        $nested->where('shift_id', $pair['shift_id'])
                            ->where('user_id', $pair['user_id']);
                    });
                }
            })
            ->get(['shift_id', 'user_id'])
            ->mapWithKeys(fn (Timesheet $timesheet) => [
                $this->timesheetPairKey((int) $timesheet->shift_id, (int) $timesheet->user_id) => true,
            ])
            ->all();
    }

    protected function timesheetPairKey(int $shiftId, int $userId): string
    {
        return $shiftId.'|'.$userId;
    }
}
