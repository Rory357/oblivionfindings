<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimesheet;
use App\Domain\Hr\Services\HrTimesheetApprovalService;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\BulkTimesheetActionRequest;
use App\Http\Requests\Hr\ClockOnBehalfRequest;
use App\Http\Requests\Hr\StoreTimesheetRequest;
use App\Http\Requests\Hr\UpdateTimeEntryRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TimeTrackingController extends Controller
{
    public function __construct(
        private readonly TimeTrackingService $timeTrackingService,
        private readonly HrTimesheetApprovalService $timesheetApprovals,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Helpers */
    /* ------------------------------------------------------------------ */

    private function resolveAccess($user): array
    {
        $canManage = $user->canDo('timesheets.manageAny');
        $canApproveTeam = $user->canDo('timesheets.approve');
        $teamUserIds = [];

        if ($canApproveTeam && ! $canManage) {
            $teamUserIds = $this->timeTrackingService->getTeamUserIds($user);
        }

        return [
            'canManage' => $canManage,
            'canApproveTeam' => $canApproveTeam,
            'canApproveAny' => $canManage || $canApproveTeam,
            'teamUserIds' => $teamUserIds,
        ];
    }

    private function applyAccessScope($query, $user, array $access)
    {
        if ($access['canManage']) {
            return $query; // Admin sees all
        }
        if ($access['canApproveTeam']) {
            return $query->forUserOrTeam($user->id, $access['teamUserIds']);
        }

        return $query->forUser($user->id);
    }

    /* ------------------------------------------------------------------ */
    /*  Index — timekeeping dashboard */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('timesheets.viewAny'), 403);

        $tenantId = $user->tenant_id;
        $access = $this->resolveAccess($user);
        $status = $request->query('status');
        $search = trim((string) $request->query('q', ''));
        $payType = $request->query('pay_type');
        $scope = $request->query('scope', $access['canApproveAny'] ? 'team' : 'mine');
        $tab = $request->query('tab', 'dashboard');

        // --- Team members list (for filters and dialogs) ---
        $teamMembers = [];
        if ($access['canApproveAny']) {
            $teamProfiles = $access['canManage']
                ? HrEmployeeProfile::where('tenant_id', $tenantId)->where('is_active', true)->with('user:id,name')->get()
                : HrEmployeeProfile::where('manager_user_id', $user->id)->where('is_active', true)->with('user:id,name')->get();

            $teamMembers = $teamProfiles->map(fn ($p) => [
                'id' => $p->user_id,
                'name' => $p->user?->name ?? 'Unknown',
            ])->values()->all();
        }

        // --- KPI Stats ---
        $weekStart = now()->startOfWeek()->toDateString();
        $weekEnd = now()->endOfWeek()->toDateString();

        $kpiEntriesQuery = HrTimeEntry::forTenant($tenantId);
        $this->applyAccessScope($kpiEntriesQuery, $user, $access);

        $totalHoursThisWeek = (clone $kpiEntriesQuery)
            ->forDateRange($weekStart, $weekEnd)
            ->whereNotNull('clock_out')
            ->sum('total_hours');

        $activeClockedIn = HrTimeEntry::forTenant($tenantId)
            ->active()
            ->when(! $access['canManage'], function ($q) use ($user, $access) {
                if ($access['canApproveTeam']) {
                    $q->forUserOrTeam($user->id, $access['teamUserIds']);
                } else {
                    $q->forUser($user->id);
                }
            })
            ->distinct('user_id')
            ->count('user_id');

        $pendingTimesheetQuery = HrTimesheet::forTenant($tenantId)->where('status', 'submitted');
        $this->applyAccessScope($pendingTimesheetQuery, $user, $access);
        $pendingTimesheets = $pendingTimesheetQuery->count();

        // Overtime
        $overtimeHours = 0;
        if ($access['canApproveAny']) {
            $otQuery = HrTimeEntry::forTenant($tenantId)
                ->forDateRange($weekStart, $weekEnd)
                ->whereNotNull('clock_out');
            $this->applyAccessScope($otQuery, $user, $access);
            $userWeeklyHours = $otQuery
                ->selectRaw('user_id, SUM(total_hours) as week_hours')
                ->groupBy('user_id')
                ->havingRaw('SUM(total_hours) > 40')
                ->get();
            $overtimeHours = round($userWeeklyHours->sum(fn ($row) => max(0, $row->week_hours - 40)), 1);
        }

        $avgHoursPerDay = 0;
        $daysQuery = (clone $kpiEntriesQuery)->forDateRange($weekStart, $weekEnd)->whereNotNull('clock_out');
        $daysWorked = $daysQuery->distinct('entry_date')->count('entry_date');
        if ($daysWorked > 0) {
            $avgHoursPerDay = round($totalHoursThisWeek / $daysWorked, 1);
        }

        // --- Entries (scoped by toggle) ---
        $entriesBaseQuery = HrTimeEntry::forTenant($tenantId);
        if ($scope === 'mine') {
            $entriesBaseQuery->forUser($user->id);
        } elseif ($scope === 'team' && $access['canApproveTeam'] && ! $access['canManage']) {
            $entriesBaseQuery->forUserOrTeam($user->id, $access['teamUserIds']);
        }
        // 'all' scope → no filter (admin only)

        $entries = (clone $entriesBaseQuery)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($payType, fn ($q) => $q->where('pay_type', $payType))
            ->when($search !== '', fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
            ))
            ->with('user:id,name,email', 'approver:id,name', 'shift:id,starts_at,ends_at', 'shift.client:id,first_name,last_name', 'client:id,first_name,last_name')
            ->orderByDesc('entry_date')
            ->orderByDesc('clock_in')
            ->paginate(20)
            ->withQueryString();

        $entries->through(fn ($entry) => [
            'id' => $entry->id,
            'user_name' => $entry->user?->name ?? 'Unknown',
            'user_id' => $entry->user_id,
            'entry_date' => $entry->entry_date->toDateString(),
            'clock_in' => $entry->clock_in->format('Y-m-d\TH:i'),
            'clock_in_short' => $entry->clock_in->format('H:i'),
            'clock_out' => $entry->clock_out?->format('Y-m-d\TH:i'),
            'clock_out_short' => $entry->clock_out?->format('H:i'),
            'break_minutes' => $entry->break_minutes,
            'total_hours' => $entry->total_hours,
            'entry_type' => $entry->entry_type,
            'status' => $entry->status,
            'pay_type' => $entry->pay_type ?? 'standard',
            'is_sleepover' => (bool) $entry->is_sleepover,
            'is_on_call' => (bool) $entry->is_on_call,
            'is_public_holiday' => (bool) $entry->is_public_holiday,
            'break_compliance_met' => $entry->break_compliance_met,
            'notes' => $entry->notes,
            'project_code' => $entry->project_code,
            'approved_by' => $entry->approver?->name,
            'amended_by' => $entry->amended_by,
            'amendment_reason' => $entry->amendment_reason,
            'client_name' => $entry->client
                ? trim(($entry->client->first_name ?? '').' '.($entry->client->last_name ?? ''))
                : ($entry->shift?->client
                    ? trim(($entry->shift->client->first_name ?? '').' '.($entry->shift->client->last_name ?? ''))
                    : null),
            'shift' => $entry->shift ? [
                'id' => $entry->shift->id,
                'starts_at' => $entry->shift->starts_at?->format('H:i'),
                'ends_at' => $entry->shift->ends_at?->format('H:i'),
            ] : null,
        ]);

        // --- Timesheets ---
        $timesheetsQuery = HrTimesheet::forTenant($tenantId);
        $this->applyAccessScope($timesheetsQuery, $user, $access);

        $timesheets = (clone $timesheetsQuery)
            ->with('user:id,name,email', 'approver:id,name')
            ->orderByDesc('period_start')
            ->paginate(20, ['*'], 'ts_page')
            ->withQueryString();

        $timesheets->through(fn ($ts) => [
            'id' => $ts->id,
            'user_name' => $ts->user?->name ?? 'Unknown',
            'user_id' => $ts->user_id,
            'period_start' => $ts->period_start->toDateString(),
            'period_end' => $ts->period_end->toDateString(),
            'status' => $ts->status,
            'total_hours' => $ts->total_hours,
            'submitted_at' => $ts->submitted_at?->toDateTimeString(),
            'approved_by' => $ts->approver?->name,
            'approved_at' => $ts->approved_at?->toDateTimeString(),
            'rejection_reason' => $ts->rejection_reason,
            'returned_notes' => $ts->returned_notes,
            'returned_at' => $ts->returned_at?->toDateTimeString(),
        ]);

        // --- Approval queue (submitted timesheets from team) ---
        $approvalTimesheets = [];
        $pendingApprovalCount = 0;
        if ($access['canApproveAny']) {
            $approvalQuery = HrTimesheet::forTenant($tenantId)->where('status', 'submitted');
            $this->applyAccessScope($approvalQuery, $user, $access);
            $pendingApprovalCount = (clone $approvalQuery)->count();

            $approvalTimesheets = (clone $approvalQuery)
                ->with('user:id,name,email')
                ->orderBy('submitted_at')
                ->limit(50)
                ->get()
                ->map(fn ($ts) => [
                    'id' => $ts->id,
                    'user_name' => $ts->user?->name ?? 'Unknown',
                    'user_id' => $ts->user_id,
                    'period_start' => $ts->period_start->toDateString(),
                    'period_end' => $ts->period_end->toDateString(),
                    'total_hours' => $ts->total_hours,
                    'submitted_at' => $ts->submitted_at?->toDateTimeString(),
                    'hours_waiting' => $ts->submitted_at ? round($ts->submitted_at->diffInHours(now()), 1) : 0,
                ]);
        }

        // Active clock-in for current user
        $activeClock = HrTimeEntry::forTenant($tenantId)
            ->forUser($user->id)
            ->active()
            ->first();

        $weeklySummary = $this->timeTrackingService->getWeeklySummary($tenantId, $user->id);

        // Recent activity
        $recentActivity = [];
        if ($access['canApproveAny']) {
            $activityQuery = HrTimeEntry::forTenant($tenantId);
            $this->applyAccessScope($activityQuery, $user, $access);
            $recentActivity = $activityQuery
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn ($entry) => [
                    'id' => $entry->id,
                    'user_name' => $entry->user?->name ?? 'Unknown',
                    'action' => $entry->clock_out ? 'clocked_out' : 'clocked_in',
                    'time' => ($entry->clock_out ?? $entry->clock_in)->diffForHumans(),
                    'pay_type' => $entry->pay_type ?? 'standard',
                    'entry_type' => $entry->entry_type,
                ]);
        }

        return Inertia::render('hr/time/index', [
            'entries' => $entries,
            'timesheets' => $timesheets,
            'approvalTimesheets' => $approvalTimesheets,
            'pendingApprovalCount' => $pendingApprovalCount,
            'teamMembers' => $teamMembers,
            'activeClock' => $activeClock ? [
                'id' => $activeClock->id,
                'clock_in' => $activeClock->clock_in->format('Y-m-d H:i'),
                'notes' => $activeClock->notes,
            ] : null,
            'weeklySummary' => $weeklySummary,
            'kpiStats' => [
                'total_hours_this_week' => round($totalHoursThisWeek, 1),
                'active_clocked_in' => $activeClockedIn,
                'pending_timesheets' => $pendingTimesheets,
                'overtime_hours' => $overtimeHours,
                'avg_hours_per_day' => $avgHoursPerDay,
            ],
            'recentActivity' => $recentActivity,
            'filters' => [
                'status' => $status,
                'pay_type' => $payType,
                'q' => $search,
                'tab' => $tab,
                'scope' => $scope,
            ],
            'can' => [
                'manage' => $access['canManage'],
                'approveTeam' => $access['canApproveTeam'],
                'approveAny' => $access['canApproveAny'],
                'editEntry' => $access['canApproveAny'],
                'clockOnBehalf' => $access['canApproveAny'],
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Clock In */
    /* ------------------------------------------------------------------ */

    public function clockIn(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('timesheets.viewAny'), 403);

        $validated = $request->validate([
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'project_code' => ['nullable', 'string', 'max:50'],
        ]);

        try {
            $this->timeTrackingService->clockIn(
                $user,
                $validated['notes'] ?? null,
                $validated['project_code'] ?? null,
                $validated['shift_id'] ?? null,
            );
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Clocked in successfully.');
    }

    /* ------------------------------------------------------------------ */
    /*  Clock Out */
    /* ------------------------------------------------------------------ */

    public function clockOut(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('timesheets.viewAny'), 403);

        $validated = $request->validate([
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
            'mileage_km' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->timeTrackingService->clockOut(
                $user,
                (int) ($validated['break_minutes'] ?? 0),
                $validated['notes'] ?? null,
                isset($validated['mileage_km']) ? (float) $validated['mileage_km'] : null,
            );
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Clocked out successfully.');
    }

    /* ------------------------------------------------------------------ */
    /*  Clock On Behalf */
    /* ------------------------------------------------------------------ */

    public function clockOnBehalf(ClockOnBehalfRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        try {
            $this->timeTrackingService->clockOnBehalf($user, $validated['target_user_id'], $validated);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Time entry created on behalf of staff member.');
    }

    /* ------------------------------------------------------------------ */
    /*  Store — manual time entry */
    /* ------------------------------------------------------------------ */

    public function store(StoreTimesheetRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        $this->timeTrackingService->createManualEntry($user, $validated);

        return redirect()->back()->with('success', 'Time entry created.');
    }

    /* ------------------------------------------------------------------ */
    /*  Update — edit/amend time entry */
    /* ------------------------------------------------------------------ */

    public function updateEntry(UpdateTimeEntryRequest $request, HrTimeEntry $entry)
    {
        $user = $request->user();
        $validated = $request->validated();
        $reason = $validated['amendment_reason'];
        unset($validated['amendment_reason']);

        try {
            $this->timeTrackingService->editTimeEntry($entry, $user, $validated, $reason);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Time entry updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Entry Amendments — audit trail */
    /* ------------------------------------------------------------------ */

    public function entryAmendments(HrTimeEntry $entry)
    {
        $amendments = $entry->amendments()
            ->with('amendedByUser:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'field_name' => $a->field_name,
                'old_value' => $a->old_value,
                'new_value' => $a->new_value,
                'reason' => $a->reason,
                'amended_by' => $a->amendedByUser?->name ?? 'Unknown',
                'created_at' => $a->created_at->toDateTimeString(),
            ]);

        return response()->json($amendments);
    }

    /* ------------------------------------------------------------------ */
    /*  Timesheets */
    /* ------------------------------------------------------------------ */

    public function timesheets(Request $request)
    {
        abort_unless($request->user()?->canDo('timesheets.viewAny'), 403);

        // Redirect to main page with timesheets tab
        return redirect()->route('hr.time.index', ['tab' => 'timesheets']);
    }

    /* ------------------------------------------------------------------ */
    /*  Submit Timesheet */
    /* ------------------------------------------------------------------ */

    public function submitTimesheet(Request $request, HrTimesheet $timesheet)
    {
        $user = $request->user();
        abort_unless($user && $user->id === $timesheet->user_id, 403);

        try {
            $this->timesheetApprovals->submit($timesheet, $user);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Timesheet submitted.');
    }

    /* ------------------------------------------------------------------ */
    /*  Approve Timesheet */
    /* ------------------------------------------------------------------ */

    public function approveTimesheet(Request $request, HrTimesheet $timesheet)
    {
        $user = $request->user();
        abort_unless($user, 403);
        $this->assertCanReviewTimesheet($timesheet, $user);

        try {
            $this->timesheetApprovals->approve($timesheet, $user, $request->input('notes'));
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Timesheet approved.');
    }

    /* ------------------------------------------------------------------ */
    /*  Reject Timesheet */
    /* ------------------------------------------------------------------ */

    public function rejectTimesheet(Request $request, HrTimesheet $timesheet)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->assertCanReviewTimesheet($timesheet, $user);
            $this->timesheetApprovals->reject(
                $timesheet,
                $user,
                $validated['rejection_reason'],
            );
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Timesheet rejected.');
    }

    /* ------------------------------------------------------------------ */
    /*  Return Timesheet for Changes */
    /* ------------------------------------------------------------------ */

    public function returnTimesheet(Request $request, HrTimesheet $timesheet)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'notes' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->assertCanReviewTimesheet($timesheet, $user);
            $this->timesheetApprovals->returnForChanges($timesheet, $user, $validated['notes']);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Timesheet returned for changes.');
    }

    /* ------------------------------------------------------------------ */
    /*  Bulk Timesheet Actions */
    /* ------------------------------------------------------------------ */

    public function bulkApproveTimesheets(BulkTimesheetActionRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        $timesheets = $this->reviewableTimesheets($validated['ids'], $user);
        $count = $this->timesheetApprovals
            ->bulkApprove($timesheets, $user, $validated['notes'] ?? null)
            ->changedCount();

        return redirect()->back()->with('success', "{$count} timesheet(s) approved.");
    }

    public function bulkRejectTimesheets(BulkTimesheetActionRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        abort_unless(! empty($validated['reason']), 422, 'Rejection reason is required.');

        $timesheets = $this->reviewableTimesheets($validated['ids'], $user);
        $count = $this->timesheetApprovals
            ->bulkReject($timesheets, $user, $validated['reason'])
            ->changedCount();

        return redirect()->back()->with('success', "{$count} timesheet(s) rejected.");
    }

    public function bulkReturnTimesheets(BulkTimesheetActionRequest $request)
    {
        $user = $request->user();
        $validated = $request->validated();

        abort_unless(! empty($validated['notes']), 422, 'Return notes are required.');

        $timesheets = $this->reviewableTimesheets($validated['ids'], $user);
        $count = $this->timesheetApprovals
            ->bulkReturn($timesheets, $user, $validated['notes'])
            ->changedCount();

        return redirect()->back()->with('success', "{$count} timesheet(s) returned for changes.");
    }

    private function assertCanReviewTimesheet(HrTimesheet $timesheet, $user): void
    {
        $access = $this->resolveAccess($user);

        abort_unless($access['canApproveAny'], 403);

        $allowed = HrTimesheet::query()
            ->whereKey($timesheet->id)
            ->tap(fn ($query) => $this->applyAccessScope($query, $user, $access))
            ->exists();

        abort_unless($allowed, 403);
    }

    /**
     * @param  array<int, int>  $ids
     * @return \Illuminate\Support\Collection<int, HrTimesheet>
     */
    private function reviewableTimesheets(array $ids, $user)
    {
        $access = $this->resolveAccess($user);

        abort_unless($access['canApproveAny'], 403);

        $timesheets = HrTimesheet::query()
            ->whereIn('id', $ids)
            ->tap(fn ($query) => $this->applyAccessScope($query, $user, $access))
            ->get();

        abort_if($timesheets->count() !== count(array_unique($ids)), 403, 'You are not authorized to review one or more selected timesheets.');

        return $timesheets;
    }
}
