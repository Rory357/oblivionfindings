<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Services\TimeTrackingService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\Concerns\ResolvesHrTenant;
use App\Http\Requests\Hr\ClockOnBehalfRequest;
use App\Http\Requests\Hr\StoreTimesheetRequest;
use App\Http\Requests\Hr\UpdateTimeEntryRequest;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TimeTrackingController extends Controller
{
    use ResolvesHrTenant;

    public function __construct(
        private readonly TimeTrackingService $timeTrackingService,
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

    private function operationsTimesheetQuery(int $tenantId, User $user, array $access)
    {
        $staffUserIds = $this->hrStaffUserIdsForTenant($tenantId);

        if (empty($staffUserIds)) {
            return Timesheet::query()->whereRaw('1 = 0');
        }

        $query = Timesheet::query()
            ->with('staff:id,name,email', 'approver:id,name', 'client:id,first_name,last_name')
            ->whereIn('user_id', $staffUserIds)
            ->whereNull('archived_at');

        if ($access['canManage']) {
            return $query;
        }

        if ($access['canApproveTeam']) {
            return $query->whereIn('user_id', array_values(array_unique(array_merge([$user->id], $access['teamUserIds']))));
        }

        return $query->where('user_id', $user->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOperationsTimesheet(Timesheet $timesheet, bool $approvalQueue = false): array
    {
        $workDate = $timesheet->work_date ?? $timesheet->starts_at ?? now();
        $periodStart = $workDate->copy()->startOfWeek()->toDateString();
        $periodEnd = $workDate->copy()->endOfWeek()->toDateString();
        $moduleUrl = $approvalQueue
            ? route('operations.timesheets.index', ['tab' => 'submitted', 'view' => $timesheet->id], false)
            : route('operations.timesheets.index', ['view' => $timesheet->id], false);

        return [
            'id' => $timesheet->id,
            'source' => 'operations',
            'user_name' => $timesheet->staff?->name ?? $timesheet->staff_name_snapshot ?? 'Unknown',
            'user_id' => $timesheet->user_id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'work_date' => $timesheet->work_date?->toDateString(),
            'client_name' => $timesheet->client_name_snapshot ?: ($timesheet->client
                ? trim(($timesheet->client->first_name ?? '').' '.($timesheet->client->last_name ?? ''))
                : null),
            'status' => $timesheet->status,
            'total_hours' => (float) $timesheet->total_hours,
            'submitted_at' => $timesheet->submitted_at?->toDateTimeString(),
            'approved_by' => $timesheet->approver?->name,
            'approved_at' => $timesheet->approved_at?->toDateTimeString(),
            'rejection_reason' => $timesheet->status === 'rejected' ? $timesheet->decision_notes : null,
            'returned_notes' => $timesheet->returned_notes,
            'returned_at' => $timesheet->returned_at?->toDateTimeString(),
            'module_url' => $moduleUrl,
            'edit_url' => route('operations.timesheets.edit', $timesheet, false),
            'hours_waiting' => $timesheet->submitted_at ? round($timesheet->submitted_at->diffInHours(now()), 1) : 0,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Index — timekeeping dashboard */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('timesheets.viewAny'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
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

        $pendingTimesheets = (clone $this->operationsTimesheetQuery($tenantId, $user, $access))
            ->where('status', 'submitted')
            ->count();

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
        $timesheets = (clone $this->operationsTimesheetQuery($tenantId, $user, $access))
            ->orderByDesc('work_date')
            ->orderByDesc('starts_at')
            ->paginate(20, ['*'], 'ts_page')
            ->withQueryString();

        $timesheets->through(fn (Timesheet $ts) => $this->serializeOperationsTimesheet($ts));

        // --- Approval queue (submitted timesheets from team) ---
        $approvalTimesheets = [];
        $pendingApprovalCount = 0;
        if ($access['canApproveAny']) {
            $approvalQuery = (clone $this->operationsTimesheetQuery($tenantId, $user, $access))
                ->where('status', 'submitted');
            $pendingApprovalCount = (clone $approvalQuery)->count();

            $approvalTimesheets = (clone $approvalQuery)
                ->orderBy('submitted_at')
                ->limit(50)
                ->get()
                ->map(fn (Timesheet $ts) => $this->serializeOperationsTimesheet($ts, true));
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
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
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
}
