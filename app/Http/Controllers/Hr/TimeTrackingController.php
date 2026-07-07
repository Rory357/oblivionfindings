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

    /**
     * Guard a single-entry write/read: the entry must belong to the actor's
     * tenant, and an approve-only (non-admin) manager may only touch their own
     * or their direct reports' entries — mirrors clockOnBehalf()'s team check so
     * a team lead can't rewrite arbitrary staff's payroll-bound time.
     */
    private function assertEntryAccess(HrTimeEntry $entry, User $user): void
    {
        $tenantId = $this->resolveHrTenantIdForUser($user);
        $this->assertHrTenantAccess($tenantId, $entry->tenant_id);

        if ($user->canDo('timesheets.manageAny')) {
            return;
        }

        $teamUserIds = $this->timeTrackingService->getTeamUserIds($user);
        if ($entry->user_id !== $user->id && ! in_array($entry->user_id, $teamUserIds, true)) {
            abort(403);
        }
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
        $siteFilter = $request->query('site_id');
        $scope = $request->query('scope', $access['canApproveAny'] ? 'team' : 'mine');
        $tab = $request->query('tab', 'overview');
        $tz = (string) config('app.worker_timezone', config('app.timezone', 'UTC'));

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
            // Voided entries are soft-deleted; without withTrashed() the
            // "Voided" filter would silently match nothing.
            ->when($status, fn ($q) => $status === 'voided'
                ? $q->withTrashed()->where('status', 'voided')
                : $q->where('status', $status))
            ->when($payType, fn ($q) => $q->where('pay_type', $payType))
            ->when($siteFilter, fn ($q) => $q->where('site_id', $siteFilter))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                ->orWhere('notes', 'like', "%{$search}%")
            ))
            ->withCount('amendments')
            ->with('user:id,name,email', 'approver:id,name', 'site:id,name', 'shift:id,starts_at,ends_at', 'shift.client:id,first_name,last_name', 'client:id,first_name,last_name')
            ->orderByDesc('entry_date')
            ->orderByDesc('clock_in')
            ->paginate(20)
            ->withQueryString();

        $entries->through(fn ($entry) => $this->serializeEntry($entry, $tz));

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

        // --- On now (everyone currently clocked in, in scope) ---
        $onNowQuery = HrTimeEntry::forTenant($tenantId)->active();
        $this->applyAccessScope($onNowQuery, $user, $access);
        $onNow = $onNowQuery
            ->with('user:id,name', 'site:id,name', 'client:id,first_name,last_name', 'shift:id,starts_at,ends_at')
            ->orderBy('clock_in')
            ->get()
            ->map(function (HrTimeEntry $e) use ($tz) {
                $name = $e->user?->name ?? 'Unknown';
                $client = $e->client
                    ? trim(($e->client->first_name ?? '').' '.($e->client->last_name ?? ''))
                    : null;
                $meta = array_filter([$e->site?->name, $client]);

                return [
                    'id' => $e->id,
                    'user_id' => $e->user_id,
                    'name' => $name,
                    'initials' => $this->initialsFor($name),
                    'meta' => $meta ? implode(' · ', $meta) : 'No site linked',
                    'since' => $e->clock_in->copy()->setTimezone($tz)->format('H:i'),
                    'clock_in' => $e->clock_in->copy()->setTimezone($tz)->format('Y-m-d\TH:i'),
                    'entry_date' => $e->entry_date->toDateString(),
                    'elapsed_minutes' => (int) $e->clock_in->diffInMinutes(now()),
                    'pay_type' => $e->pay_type ?? 'standard',
                    'is_sleepover' => (bool) $e->is_sleepover,
                ];
            })
            ->values()
            ->all();

        // --- Team weekly hours (Mon–Sun rollup) ---
        $weekRows = (clone $kpiEntriesQuery)
            ->forDateRange($weekStart, $weekEnd)
            ->whereNotNull('clock_out')
            ->get(['entry_date', 'total_hours']);
        $weeklyTeam = [];
        for ($d = now()->startOfWeek(); $d->lte(now()->endOfWeek()); $d->addDay()) {
            $key = $d->toDateString();
            $weeklyTeam[] = [
                'date' => $key,
                'day' => $d->format('D'),
                'hours' => round((float) $weekRows->where('entry_date', $key)->sum('total_hours'), 1),
            ];
        }

        // --- Exceptions board ---
        $exceptions = $access['canApproveAny']
            ? $this->buildExceptions($tenantId, $user, $access, $tz, $weekStart, $weekEnd)
            : [];

        // --- Recent activity ---
        $recentActivity = [];
        if ($access['canApproveAny']) {
            $activityQuery = HrTimeEntry::forTenant($tenantId);
            $this->applyAccessScope($activityQuery, $user, $access);
            $recentActivity = $activityQuery
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get()
                ->map(fn ($entry) => [
                    'id' => $entry->id,
                    'user_name' => $entry->user?->name ?? 'Unknown',
                    'action' => $entry->clock_out ? 'clocked out' : 'clocked in',
                    'time' => ($entry->clock_out ?? $entry->clock_in)->diffForHumans(null, true),
                    'on_behalf' => $entry->entry_type === 'admin_clock',
                ])
                ->all();
        }

        // --- Pickers (staff / sites / clients) ---
        $staff = collect($teamMembers)->map(fn ($m) => [
            'id' => $m['id'],
            'name' => $m['name'],
        ])->values()->all();

        $sites = \App\Models\Site::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all();

        $clients = \App\Models\Client::query()
            ->orderBy('first_name')
            ->limit(500)
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => trim(($c->first_name ?? '').' '.($c->last_name ?? '')),
            ])->all();

        // Reports tab data (manager-only, computed lazily on that tab).
        $report = ($tab === 'reports' && $access['canApproveAny'])
            ? $this->buildReport($tenantId, $user, $access, $scope, $weekStart, $weekEnd)
            : null;

        return Inertia::render('hr/time/index', [
            'entries' => $entries,
            'report' => $report,
            'timesheets' => $timesheets,
            'approvalTimesheets' => $approvalTimesheets,
            'pendingApprovalCount' => $pendingApprovalCount,
            'onNow' => $onNow,
            'exceptions' => $exceptions,
            'weeklyTeam' => $weeklyTeam,
            'recentActivity' => $recentActivity,
            'teamMembers' => $teamMembers,
            'staff' => $staff,
            'sites' => $sites,
            'clients' => $clients,
            'activeClock' => $activeClock ? [
                'id' => $activeClock->id,
                'clock_in' => $activeClock->clock_in->copy()->setTimezone($tz)->format('Y-m-d H:i'),
                'notes' => $activeClock->notes,
            ] : null,
            'weeklySummary' => $weeklySummary,
            'kpiStats' => [
                'clocked_in_now' => $activeClockedIn,
                'team_hours_week' => round($totalHoursThisWeek, 1),
                'awaiting_approval' => $pendingTimesheets,
                'exceptions_count' => count($exceptions),
                'overtime_hours' => $overtimeHours,
                'avg_hours_per_day' => $avgHoursPerDay,
            ],
            'filters' => [
                'status' => $status,
                'pay_type' => $payType,
                'site_id' => $siteFilter,
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
    /*  Serialization + exception helpers */
    /* ------------------------------------------------------------------ */

    private function initialsFor(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
        $letters = array_map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)), array_slice($parts, 0, 2));

        return implode('', $letters) ?: '—';
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeEntry(HrTimeEntry $entry, string $tz): array
    {
        $name = $entry->user?->name ?? 'Unknown';
        $clientName = $entry->client
            ? trim(($entry->client->first_name ?? '').' '.($entry->client->last_name ?? ''))
            : ($entry->shift?->client
                ? trim(($entry->shift->client->first_name ?? '').' '.($entry->shift->client->last_name ?? ''))
                : null);

        return [
            'id' => $entry->id,
            'user_name' => $name,
            'user_id' => $entry->user_id,
            'initials' => $this->initialsFor($name),
            'site_name' => $entry->site?->name,
            'entry_date' => $entry->entry_date->toDateString(),
            'clock_in' => $entry->clock_in->copy()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'clock_in_short' => $entry->clock_in->copy()->setTimezone($tz)->format('H:i'),
            'clock_out' => $entry->clock_out?->copy()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'clock_out_short' => $entry->clock_out?->copy()->setTimezone($tz)->format('H:i'),
            'break_minutes' => $entry->break_minutes,
            'total_hours' => $entry->total_hours !== null ? (float) $entry->total_hours : null,
            'entry_type' => $entry->entry_type,
            'status' => $entry->status,
            'pay_type' => $entry->pay_type ?? 'standard',
            'is_sleepover' => (bool) $entry->is_sleepover,
            'is_on_call' => (bool) $entry->is_on_call,
            'is_public_holiday' => (bool) $entry->is_public_holiday,
            'sleepover_disturbances' => $entry->sleepover_disturbances ?? [],
            'break_compliance_met' => $entry->break_compliance_met,
            'mileage_km' => $entry->mileage_km !== null ? (float) $entry->mileage_km : null,
            'notes' => $entry->notes,
            'project_code' => $entry->project_code,
            'cost_centre' => $entry->cost_centre,
            'approved_by' => $entry->approver?->name,
            'amended_by' => $entry->amended_by,
            'amendment_reason' => $entry->amendment_reason,
            'amendment_count' => (int) ($entry->amendments_count ?? 0),
            'client_name' => $clientName,
            'shift' => $entry->shift ? [
                'id' => $entry->shift->id,
                'starts_at' => $entry->shift->starts_at?->copy()->setTimezone($tz)->format('H:i'),
                'ends_at' => $entry->shift->ends_at?->copy()->setTimezone($tz)->format('H:i'),
            ] : null,
        ];
    }

    /**
     * Build the Overview exception board — missed clock-outs, break-compliance
     * fails, weekly overtime, roster-unlinked entries and today's loadings.
     *
     * @return list<array<string, mixed>>
     */
    private function buildExceptions(int $tenantId, User $user, array $access, string $tz, string $weekStart, string $weekEnd): array
    {
        $exceptions = [];

        // 1. Missed clock-out — still active beyond 12h.
        $missedQuery = HrTimeEntry::forTenant($tenantId)
            ->active()
            ->where('clock_in', '<', now()->subHours(12));
        $this->applyAccessScope($missedQuery, $user, $access);
        foreach ($missedQuery->with('user:id,name')->orderBy('clock_in')->limit(20)->get() as $e) {
            $hours = round($e->clock_in->diffInMinutes(now()) / 60, 1);
            $exceptions[] = [
                'id' => 'missed-'.$e->id,
                'kind' => 'missed_clock_out',
                'severity' => 'critical',
                'title' => $e->user?->name ?? 'Unknown',
                'detail' => 'Clocked in '.$e->clock_in->copy()->setTimezone($tz)->format('D H:i').' — still open after '.$hours.'h',
                'badge' => 'Missed clock-out',
                'entry_id' => $e->id,
                'user_id' => $e->user_id,
                'user_name' => $e->user?->name ?? 'Unknown',
                'clock_in' => $e->clock_in->copy()->setTimezone($tz)->format('Y-m-d\TH:i'),
                'entry_date' => $e->entry_date->toDateString(),
                'action' => 'correct',
            ];
        }

        // 2. Break-compliance fails this week.
        $breakQuery = HrTimeEntry::forTenant($tenantId)
            ->forDateRange($weekStart, $weekEnd)
            ->where('break_compliance_met', false);
        $this->applyAccessScope($breakQuery, $user, $access);
        foreach ($breakQuery->with('user:id,name')->orderByDesc('entry_date')->limit(20)->get() as $e) {
            $exceptions[] = [
                'id' => 'break-'.$e->id,
                'kind' => 'break_fail',
                'severity' => 'warning',
                'title' => $e->user?->name ?? 'Unknown',
                'detail' => $e->entry_date->format('D d M').' — '.($e->break_minutes ?: 0).'m break logged on a '.($e->total_hours ?? 0).'h shift',
                'badge' => 'Break shortfall',
                'entry_id' => $e->id,
                'action' => 'edit',
            ];
        }

        // 3. Weekly overtime (>40h) per staff.
        $otQuery = HrTimeEntry::forTenant($tenantId)
            ->forDateRange($weekStart, $weekEnd)
            ->whereNotNull('clock_out');
        $this->applyAccessScope($otQuery, $user, $access);
        $otRows = $otQuery->selectRaw('user_id, SUM(total_hours) as week_hours')
            ->groupBy('user_id')
            ->havingRaw('SUM(total_hours) > 40')
            ->get();
        $userNames = User::whereIn('id', $otRows->pluck('user_id'))->pluck('name', 'id');
        foreach ($otRows as $row) {
            $exceptions[] = [
                'id' => 'ot-'.$row->user_id,
                'kind' => 'overtime',
                'severity' => 'warning',
                'title' => $userNames[$row->user_id] ?? 'Unknown',
                'detail' => round((float) $row->week_hours, 1).'h logged this week — '.round((float) $row->week_hours - 40, 1).'h over 40h',
                'badge' => 'Overtime',
                'user_id' => $row->user_id,
                'action' => 'view_entries',
            ];
        }

        // 4. Roster-unlinked clock entries this week (no shift_id) — aggregated.
        $unlinkedQuery = HrTimeEntry::forTenant($tenantId)
            ->forDateRange($weekStart, $weekEnd)
            ->where('entry_type', 'clock')
            ->whereNull('shift_id');
        $this->applyAccessScope($unlinkedQuery, $user, $access);
        $unlinkedCount = (clone $unlinkedQuery)->count();
        if ($unlinkedCount > 0) {
            $exceptions[] = [
                'id' => 'unlinked-week',
                'kind' => 'unlinked',
                'severity' => 'info',
                'title' => $unlinkedCount.' '.($unlinkedCount === 1 ? 'entry' : 'entries').' not linked to a roster shift',
                'detail' => 'Clock entries this week with no rostered shift — confirm they were worked as planned.',
                'badge' => 'Unlinked',
                'action' => 'view_entries',
            ];
        }

        // 5. Today's loadings (sleepover / on-call / public holiday).
        $loadingQuery = HrTimeEntry::forTenant($tenantId)
            ->where('entry_date', now()->toDateString())
            ->where(fn ($q) => $q->where('is_sleepover', true)->orWhere('is_on_call', true)->orWhere('is_public_holiday', true));
        $this->applyAccessScope($loadingQuery, $user, $access);
        $loadingCount = (clone $loadingQuery)->count();
        if ($loadingCount > 0) {
            $exceptions[] = [
                'id' => 'loadings-today',
                'kind' => 'loadings',
                'severity' => 'info',
                'title' => $loadingCount.' loading '.($loadingCount === 1 ? 'entry' : 'entries').' today',
                'detail' => 'Sleepover, on-call or public-holiday loadings to verify before payroll.',
                'badge' => 'Loadings',
                'action' => 'view_entries',
            ];
        }

        return $exceptions;
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
        $this->assertEntryAccess($entry, $user);
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

    public function entryAmendments(Request $request, HrTimeEntry $entry)
    {
        $this->assertEntryAccess($entry, $request->user());

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
    /*  Correct a missed clock-out */
    /* ------------------------------------------------------------------ */

    public function correct(Request $request, HrTimeEntry $entry)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('timesheets.manageAny') || $user->canDo('timesheets.approve')), 403);
        $this->assertEntryAccess($entry, $user);

        $validated = $request->validate([
            'clock_out' => ['required', 'date', 'after:'.$entry->clock_in->toDateTimeString()],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->timeTrackingService->correctMissedClockOut(
                $entry,
                $user,
                $validated['clock_out'],
                (int) ($validated['break_minutes'] ?? 0),
                $validated['reason'],
            );
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Clock-out corrected.');
    }

    /* ------------------------------------------------------------------ */
    /*  Void (soft-delete) an entry */
    /* ------------------------------------------------------------------ */

    public function void(Request $request, HrTimeEntry $entry)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('timesheets.manageAny'), 403);
        $this->assertEntryAccess($entry, $user);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->timeTrackingService->voidEntry($entry, $user, $validated['reason']);
        } catch (\LogicException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', 'Time entry voided.');
    }

    /* ------------------------------------------------------------------ */
    /*  Add note */
    /* ------------------------------------------------------------------ */

    public function addNote(Request $request, HrTimeEntry $entry)
    {
        $user = $request->user();
        abort_unless($user && ($user->canDo('timesheets.manageAny') || $user->canDo('timesheets.approve')), 403);
        $this->assertEntryAccess($entry, $user);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        $this->timeTrackingService->addNote($entry, $user, $validated['note']);

        return redirect()->back()->with('success', 'Note added.');
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
    /*  Export + reports (backend handoff §12) */
    /* ------------------------------------------------------------------ */

    private function scopedEntriesQuery(int $tenantId, User $user, array $access, string $scope)
    {
        $query = HrTimeEntry::forTenant($tenantId);
        if ($scope === 'mine') {
            $query->forUser($user->id);
        } elseif ($scope === 'team' && $access['canApproveTeam'] && ! $access['canManage']) {
            $query->forUserOrTeam($user->id, $access['teamUserIds']);
        }

        return $query;
    }

    /**
     * Streamed CSV of time entries honouring the current scope/status/pay/site/
     * search filters. Payroll export ("mark paid") stays the HR pay run — this is
     * a read-only data export with overtime/break-compliance/mileage columns.
     */
    public function export(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('timesheets.viewAny'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $access = $this->resolveAccess($user);
        $tz = (string) config('app.worker_timezone', config('app.timezone', 'UTC'));
        $scope = $request->query('scope', $access['canApproveAny'] ? 'team' : 'mine');

        $query = $this->scopedEntriesQuery($tenantId, $user, $access, $scope)
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('pay_type'), fn ($q, $v) => $q->where('pay_type', $v))
            ->when($request->query('site_id'), fn ($q, $v) => $q->where('site_id', $v))
            ->when(trim((string) $request->query('q', '')) !== '', function ($q) use ($request) {
                $search = trim((string) $request->query('q', ''));
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            })
            ->with('user:id,name', 'site:id,name')
            ->orderByDesc('entry_date')
            ->orderByDesc('clock_in');

        $filename = 'time-entries-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query, $tz) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, [
                'Staff', 'Date', 'Clock in', 'Clock out', 'Break (min)', 'Hours',
                'Pay type', 'Sleepover', 'On-call', 'Public holiday',
                'Break compliant', 'Mileage (km)', 'Site', 'Status',
            ]);
            $query->chunk(500, function ($rows) use ($out, $tz) {
                foreach ($rows as $e) {
                    $this->putCsv($out, [
                        $e->user?->name ?? 'Unknown',
                        $e->entry_date->toDateString(),
                        $e->clock_in?->copy()->setTimezone($tz)->format('Y-m-d H:i'),
                        $e->clock_out?->copy()->setTimezone($tz)->format('Y-m-d H:i'),
                        $e->break_minutes,
                        $e->total_hours,
                        $e->pay_type,
                        $e->is_sleepover ? 'Yes' : 'No',
                        $e->is_on_call ? 'Yes' : 'No',
                        $e->is_public_holiday ? 'Yes' : 'No',
                        $e->break_compliance_met === null ? '' : ($e->break_compliance_met ? 'Yes' : 'No'),
                        $e->mileage_km,
                        $e->site?->name,
                        $e->status,
                    ]);
                }
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Hours & compliance report for a week (this-week by default): KPIs, hours by
     * site and hours by staff (with per-staff overtime over 40h). Feeds the
     * Reports tab and the PDF export. Manager-scoped.
     *
     * @return array<string, mixed>
     */
    private function buildReport(int $tenantId, User $user, array $access, string $scope, string $weekStart, string $weekEnd): array
    {
        $rows = $this->scopedEntriesQuery($tenantId, $user, $access, $scope)
            ->forDateRange($weekStart, $weekEnd)
            ->whereNotNull('clock_out')
            ->with('user:id,name', 'site:id,name')
            ->get();

        $totalHours = round((float) $rows->sum('total_hours'), 1);
        $mileage = round((float) $rows->sum('mileage_km'), 1);
        $breakFails = $rows->where('break_compliance_met', false)->count();

        $bySite = $rows->groupBy(fn ($e) => $e->site?->name ?? 'No site')
            ->map(fn ($g, $name) => [
                'name' => $name,
                'hours' => round((float) $g->sum('total_hours'), 1),
            ])->sortByDesc('hours')->values()->all();

        $byStaff = $rows->groupBy('user_id')->map(function ($g) {
            $hours = round((float) $g->sum('total_hours'), 1);

            return [
                'user_id' => $g->first()->user_id,
                'name' => $g->first()->user?->name ?? 'Unknown',
                'hours' => $hours,
                'overtime' => round(max(0, $hours - 40), 1),
            ];
        })->sortByDesc('hours')->values()->all();

        $overtime = round(array_sum(array_column($byStaff, 'overtime')), 1);

        return [
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'kpis' => [
                'total_hours' => $totalHours,
                'overtime_hours' => $overtime,
                'break_fails' => $breakFails,
                'mileage_km' => $mileage,
            ],
            'by_site' => $bySite,
            'by_staff' => $byStaff,
        ];
    }

    /** PDF of the weekly hours & compliance report (dompdf). */
    public function reportPdf(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('timesheets.viewAny'), 403);

        $tenantId = $this->resolveHrTenantIdForUser($user);
        $access = $this->resolveAccess($user);
        abort_unless($access['canApproveAny'], 403);
        $scope = $request->query('scope', 'team');
        $weekStart = now()->startOfWeek()->toDateString();
        $weekEnd = now()->endOfWeek()->toDateString();

        $report = $this->buildReport($tenantId, $user, $access, $scope, $weekStart, $weekEnd);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('hr.time.report', [
            'report' => $report,
            'generatedAt' => now()->format('D d M Y, H:i'),
        ]);

        return $pdf->download('time-report-'.now()->format('Y-m-d').'.pdf');
    }
}
