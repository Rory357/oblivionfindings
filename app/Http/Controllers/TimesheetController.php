<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Shifts\Timesheets\TimesheetApprovalService;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\TimesheetAmendment;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\Operations\TimesheetReconciliationService;
use App\Services\ShiftOperationalSnapshotService;
use App\Services\UserSiteAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TimesheetController extends Controller
{
    public function approvals(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);

        $pending = Timesheet::query()
            ->with([
                'client:id,first_name,last_name',
                'staff:id,name,email',
                // PR — ship the per-client breakdown alongside each row so
                // approvers can confirm time was attributed correctly before
                // clicking Approve. Mirrors MyTasksController::getTimesheets.
                'clientAllocations.client:id,first_name,last_name',
                'shift.site.clients:id,site_id,first_name,last_name',
            ])
            ->where('status', 'submitted')
            ->orderByDesc('submitted_at')
            ->tap(fn ($query) => $this->siteAccess()->applyTimesheetScope($query, $auth, $this->timesheetBypassPermissions()))
            ->paginate(25)
            ->withQueryString();

        $pending = $pending->through(fn (Timesheet $ts) => $this->serializeTimesheetForApproval($ts));

        return inertia('operations/timesheets/approvals', [
            'timesheets' => $pending,
            'filters' => $request->only(['from', 'to', 'client_id', 'staff_id']),
        ]);
    }

    /**
     * Serialise a timesheet for the approval queue: base model attrs + the
     * per-client allocation breakdown. Same contract as
     * {@see MyTasksController::getTimesheets} so the front-end can render
     * either source through the shared breakdown component.
     *
     * @return array<string, mixed>
     */
    protected function serializeTimesheetForApproval(Timesheet $ts): array
    {
        $data = $ts->toArray();
        $data['total_hours'] = (float) $ts->total_hours;
        $data['client_allocations'] = $ts->effectiveClientAllocations()->all();
        $data['allocation_method'] = $ts->dominantAllocationMethod();
        $data['clients_candidates'] = $this->buildAllocationCandidates($ts);

        return $data;
    }

    /**
     * Eligible-client roster the worker may attribute time to. Mirrors the
     * candidate list built by {@see MyTasksController::getTimesheets} so the
     * front-end can look up resident names for allocation rows.
     *
     * @return array<int, array{id:int,name:string,is_primary:bool}>
     */
    protected function buildAllocationCandidates(Timesheet $timesheet): array
    {
        $candidatesById = [];

        if ($timesheet->client) {
            $candidatesById[$timesheet->client->id] = [
                'id' => (int) $timesheet->client->id,
                'name' => trim($timesheet->client->first_name.' '.$timesheet->client->last_name),
                'is_primary' => true,
            ];
        }

        $siteClients = $timesheet->shift?->site?->clients ?? collect();
        foreach ($siteClients as $sc) {
            if (! isset($candidatesById[$sc->id])) {
                $candidatesById[$sc->id] = [
                    'id' => (int) $sc->id,
                    'name' => trim($sc->first_name.' '.$sc->last_name),
                    'is_primary' => false,
                ];
            }
        }

        // Defensive: include any allocation-row clients that aren't in the
        // candidate set (e.g. site changed after the rows were written).
        if ($timesheet->relationLoaded('clientAllocations')) {
            foreach ($timesheet->clientAllocations as $a) {
                if (! isset($candidatesById[$a->client_id]) && $a->client) {
                    $candidatesById[$a->client_id] = [
                        'id' => (int) $a->client_id,
                        'name' => trim($a->client->first_name.' '.$a->client->last_name),
                        'is_primary' => false,
                    ];
                }
            }
        }

        return array_values($candidatesById);
    }

    public function bulkApprove(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:timesheets,id'],
            'decision_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $timesheets = Timesheet::query()
            ->whereIn('id', $data['ids'])
            ->tap(fn ($query) => $this->siteAccess()->applyTimesheetScope($query, $auth, $this->timesheetBypassPermissions()))
            ->get();

        abort_if($timesheets->count() !== count($data['ids']), 403, 'You are not authorized to approve timesheets for one or more selected sites.');

        $result = $this->timesheetApprovals()
            ->bulkApprove($timesheets, $auth, $data['decision_notes'] ?? null);

        foreach ($result->changedTimesheets() as $approvedTimesheet) {
            $client = $approvedTimesheet->shift?->client;
            app(NotificationService::class)->notifyCrud($auth, 'approved', 'timesheet', $approvedTimesheet, $client, [
                'event_key' => 'timesheets.approved',
                'title' => 'Timesheet approved',
                'url' => url("/operations/timesheets/{$approvedTimesheet->id}/edit"),
            ]);
        }

        return redirect()->back()->with('success', 'Selected timesheets approved.');
    }

    public function bulkReturnForChanges(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:timesheets,id'],
            'returned_notes' => ['required', 'string', 'max:5000'],
        ]);

        $timesheets = Timesheet::query()
            ->whereIn('id', $data['ids'])
            ->tap(fn ($query) => $this->siteAccess()->applyTimesheetScope($query, $auth, $this->timesheetBypassPermissions()))
            ->get();

        abort_if($timesheets->count() !== count($data['ids']), 403, 'You are not authorized to return timesheets for one or more selected sites.');

        $result = $this->timesheetApprovals()
            ->bulkReturn($timesheets, $auth, $data['returned_notes']);

        foreach ($result->changedTimesheets() as $returnedTimesheet) {
            $client = $returnedTimesheet->shift?->client;
            app(NotificationService::class)->notifyCrud($auth, 'returned', 'timesheet', $returnedTimesheet, $client, [
                'event_key' => 'timesheets.returned',
                'title' => 'Timesheet returned for changes',
                'url' => url("/operations/timesheets/{$returnedTimesheet->id}/edit"),
            ]);
        }

        return redirect()->back()->with('success', 'Selected timesheets returned for changes.');
    }

    public function bulkReject(Request $request)
    {
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:timesheets,id'],
            'decision_notes' => ['required', 'string', 'max:5000'],
        ]);

        $timesheets = Timesheet::query()
            ->whereIn('id', $data['ids'])
            ->tap(fn ($query) => $this->siteAccess()->applyTimesheetScope($query, $auth, $this->timesheetBypassPermissions()))
            ->get();

        abort_if($timesheets->count() !== count($data['ids']), 403, 'You are not authorized to reject timesheets for one or more selected sites.');

        $result = $this->timesheetApprovals()
            ->bulkReject($timesheets, $auth, $data['decision_notes']);

        foreach ($result->changedTimesheets() as $rejectedTimesheet) {
            $client = $rejectedTimesheet->shift?->client;
            app(NotificationService::class)->notifyCrud($auth, 'rejected', 'timesheet', $rejectedTimesheet, $client, [
                'event_key' => 'timesheets.rejected',
                'title' => 'Timesheet rejected',
                'url' => url("/operations/timesheets/{$rejectedTimesheet->id}/edit"),
            ]);
        }

        return redirect()->back()->with('success', 'Selected timesheets rejected.');
    }

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('timesheets.viewAny') || $auth->canDo('timesheets.viewAssigned')), 403);

        $canApprove = $this->canReviewTimesheets($auth);
        $tab = $request->query('tab', 'all');
        $from = $request->query('from');
        $to = $request->query('to');
        $clientId = $request->query('client_id');
        $staffId = $request->query('staff_id');
        $search = $request->query('search');

        $q = Timesheet::query()
            ->with([
                'client:id,first_name,last_name',
                'staff:id,name,email',
                'shift:id,client_id,service_context_id,starts_at,ends_at,location,shift_type,is_sleepover,is_on_call,expected_break_minutes,status',
                'shift.serviceContext:id,name',
                'shift.tasks:id,shift_id,is_completed',
                'site:id,name',
                'clientAllocations.client:id,first_name,last_name',
            ])
            ->orderByDesc('work_date');

        $this->siteAccess()->applyTimesheetScope($q, $auth, $this->timesheetBypassPermissions());

        // Scope to "own only" unless the user has manageAny OR is reviewing the
        // Pending tab as an approver (the Pending tab IS the approval queue —
        // approvers need to see everyone's submitted timesheets here).
        $isPendingForApprover = $tab === 'submitted' && $canApprove;
        if (! $auth->canDo('timesheets.manageAny') && ! $isPendingForApprover) {
            $q->where('user_id', $auth->id);
        }

        // Tab → status / archive filter
        if ($tab === 'archived') {
            $q->whereNotNull('archived_at');
        } elseif (in_array($tab, ['draft', 'submitted', 'returned', 'approved', 'rejected', 'paid'], true)) {
            $q->whereNull('archived_at')->where('status', $tab);
        } else {
            // 'all' hides archived rows
            $q->whereNull('archived_at');
        }

        if ($from) {
            $q->whereDate('work_date', '>=', $from);
        }
        if ($to) {
            $q->whereDate('work_date', '<=', $to);
        }
        if ($clientId) {
            $q->where('client_id', $clientId);
        }
        if ($staffId) {
            $q->where('user_id', $staffId);
        }
        if ($search) {
            $q->where(function ($sub) use ($search) {
                $sub->where('client_name_snapshot', 'like', "%{$search}%")
                    ->orWhere('staff_name_snapshot', 'like', "%{$search}%")
                    ->orWhere('shift_location_snapshot', 'like', "%{$search}%");
            });
        }

        $timesheets = $q->paginate(50)->withQueryString();
        $timesheets = $timesheets->through(fn (Timesheet $ts) => $this->serializeTimesheetRow($ts));

        // Single scoped status histogram shared by the tab strip and the hero
        // summary — replaces the ~18 per-status COUNT queries these two used to
        // fire independently.
        $statusCounts = $this->scopedStatusCounts($auth);

        // Tab counts — derived from the shared histogram (+ one archived count).
        $tabCounts = $this->computeTabCounts($auth, $statusCounts);

        // Hero summary — week-aware when the list is scoped to an exact
        // Mon–Sun pair (the hero week-stepper writes from/to).
        $heroSummary = $this->computeHeroSummary($auth, $statusCounts, $from, $to);

        // Clients / sites / shifts for the Create dialog and filters.
        $clientScope = $this->siteAccess()->applyClientScope(Client::query(), $auth, $this->timesheetBypassPermissions());
        $clients = $clientScope->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        $staff = $canApprove
            ? $this->siteAccess()->applyStaffScope(\App\Models\User::staff(), $auth, $this->timesheetBypassPermissions())
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
            : [];

        $sites = $this->siteAccess()
            ->applySiteScope(\App\Models\Site::query(), $auth, $this->timesheetBypassPermissions())
            ->orderBy('name')
            ->get(['id', 'name']);

        // Today + upcoming shifts available for tile-pick in the Create dialog.
        $availableShifts = $this->availableShiftsForCreate($auth);

        // When the worker doesn't have `timesheets.manageAny`, the controller
        // already scopes the list to their own user_id (line above). Flag this
        // for the front-end so the page renders as "My Timesheets" instead of
        // the generic manager copy + hides the redundant "Staff" column.
        $isOwnOnlyView = ! $auth->canDo('timesheets.manageAny');

        return inertia('operations/timesheets/index', [
            'timesheets' => $timesheets,
            'filters' => [
                'tab' => $tab,
                'from' => $from,
                'to' => $to,
                'client_id' => $clientId,
                'staff_id' => $staffId,
                'search' => $search,
            ],
            'tabCounts' => $tabCounts,
            'heroSummary' => $heroSummary,
            'isOwnOnlyView' => $isOwnOnlyView,
            'clients' => $clients,
            'sites' => $sites,
            'staff' => $staff,
            'availableShifts' => $availableShifts,
            'canApprove' => $canApprove,
            'canCreate' => $auth->canDo('timesheets.create'),
        ]);
    }

    /**
     * Serialise a timesheet row for the index table — includes hours, task
     * progress, allocation breakdown, and a hover-popover payload.
     *
     * @return array<string, mixed>
     */
    protected function serializeTimesheetRow(Timesheet $ts): array
    {
        $data = $ts->toArray();
        $data['total_hours'] = (float) $ts->total_hours;
        $data['client_allocations'] = $ts->effectiveClientAllocations()->all();
        $data['allocation_method'] = $ts->dominantAllocationMethod();

        // Task progress — pulled from the linked shift's tasks when present.
        $tasksTotal = 0;
        $tasksCompleted = 0;
        if ($ts->shift_id && $ts->shift && method_exists($ts->shift, 'tasks')) {
            $shiftTasks = $ts->shift->relationLoaded('tasks') ? $ts->shift->tasks : collect();
            $tasksTotal = $shiftTasks->count();
            $tasksCompleted = $shiftTasks->where('is_completed', true)->count();
        } elseif (is_array($ts->activity_items)) {
            $tasksTotal = count($ts->activity_items);
            $tasksCompleted = $tasksTotal;
        }
        $data['tasks_total'] = $tasksTotal;
        $data['tasks_completed'] = $tasksCompleted;

        return $data;
    }

    /**
     * Scoped, non-archived status histogram (status => count) used by both the
     * tab strip and the hero summary. One grouped query per request.
     *
     * @return array<string, int>
     */
    protected function scopedStatusCounts(User $auth): array
    {
        $base = Timesheet::query();
        $this->siteAccess()->applyTimesheetScope($base, $auth, $this->timesheetBypassPermissions());

        if (! $auth->canDo('timesheets.manageAny')) {
            $base->where('user_id', $auth->id);
        }

        return $base->whereNull('archived_at')
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /**
     * Counts per tab for the tab strip. Reads the per-status numbers from the
     * shared histogram; only the archived bucket needs its own count.
     *
     * @param  array<string, int>  $statusCounts  non-archived status histogram
     * @return array<string, int>
     */
    protected function computeTabCounts(User $auth, array $statusCounts): array
    {
        $statuses = ['draft', 'submitted', 'returned', 'approved', 'rejected', 'paid'];

        $archivedBase = Timesheet::query();
        $this->siteAccess()->applyTimesheetScope($archivedBase, $auth, $this->timesheetBypassPermissions());

        if (! $auth->canDo('timesheets.manageAny')) {
            $archivedBase->where('user_id', $auth->id);
        }

        $counts = [
            'all' => array_sum($statusCounts),
            'archived' => $archivedBase->whereNotNull('archived_at')->count(),
        ];
        foreach ($statuses as $s) {
            $counts[$s] = $statusCounts[$s] ?? 0;
        }

        return $counts;
    }

    /**
     * The week the hero summarises. Defaults to the current week; when the
     * list's from/to filters describe an exact Mon–Sun pair (what the hero
     * week-stepper writes), the summary follows that week instead. Any other
     * from/to range keeps the current-week summary — the approval queue must
     * never week-hide by default, so this is purely an explicit-filter echo.
     *
     * @return array{0: \Illuminate\Support\Carbon, 1: \Illuminate\Support\Carbon}
     */
    protected function resolveSummaryWeek(?string $from, ?string $to): array
    {
        if ($from && $to) {
            try {
                $f = \Illuminate\Support\Carbon::parse($from)->startOfDay();
                $t = \Illuminate\Support\Carbon::parse($to)->startOfDay();

                if ($f->isSameDay($f->copy()->startOfWeek()) && $t->isSameDay($f->copy()->addDays(6))) {
                    return [$f->copy()->startOfDay(), $f->copy()->addDays(6)->endOfDay()];
                }
            } catch (\Throwable) {
                // fall through to the current week
            }
        }

        return [now()->startOfWeek(), now()->endOfWeek()];
    }

    /**
     * Hero summary block — pending/returned/approved counts plus hours-vs-rostered.
     *
     * @param  array<string, int>  $statusCounts  non-archived status histogram
     * @return array<string, mixed>
     */
    protected function computeHeroSummary(User $auth, array $statusCounts, ?string $from = null, ?string $to = null): array
    {
        $base = Timesheet::query();
        $this->siteAccess()->applyTimesheetScope($base, $auth, $this->timesheetBypassPermissions());

        if (! $auth->canDo('timesheets.manageAny')) {
            $base->where('user_id', $auth->id);
        }

        [$weekStart, $weekEnd] = $this->resolveSummaryWeek($from, $to);

        $thisWeek = (clone $base)
            ->whereBetween('work_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->whereNull('archived_at')
            ->get();

        $hoursThisWeek = round($thisWeek->sum(fn ($t) => (float) $t->total_hours), 1);

        // Rostered hours (linked shifts in the same week).
        $rosteredShifts = \App\Models\Shift::query()
            ->whereBetween('starts_at', [$weekStart, $weekEnd])
            ->when(! $auth->canDo('timesheets.manageAny'), fn ($q) => $q->where('user_id', $auth->id))
            ->get(['id', 'starts_at', 'ends_at', 'expected_break_minutes']);

        $hoursTarget = round($rosteredShifts->sum(function ($s) {
            if (! $s->starts_at || ! $s->ends_at) return 0;
            $mins = $s->starts_at->diffInMinutes($s->ends_at) - (int) ($s->expected_break_minutes ?? 0);
            return max($mins, 0) / 60;
        }), 1);

        $firstName = explode(' ', trim($auth->name))[0] ?? $auth->name;

        return [
            'firstName' => $firstName,
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'week_number' => (int) $weekStart->format('W'),
            'timesheets_total' => array_sum($statusCounts),
            'timesheets_submitted' => $statusCounts['submitted'] ?? 0,
            'timesheets_approved' => $statusCounts['approved'] ?? 0,
            'timesheets_returned' => $statusCounts['returned'] ?? 0,
            'unapproved' => $statusCounts['submitted'] ?? 0,
            'hours_this_week' => $hoursThisWeek,
            'hours_target' => max($hoursTarget, 0.1),
            'next_payroll_date' => now()->next(\Carbon\Carbon::FRIDAY)->format('d M'),
            'sites_count' => $this->siteAccess()->applySiteScope(\App\Models\Site::query(), $auth, $this->timesheetBypassPermissions())->count(),
            'regions_count' => 1,
            'rostered_today' => \App\Models\Shift::query()->whereDate('starts_at', today())->count(),
            'staff_on_shift' => \App\Models\Shift::query()->whereDate('starts_at', today())->where('status', 'in_progress')->count(),
        ];
    }

    /**
     * Shifts the user can pick to base a timesheet on. Returns rostered shifts
     * for the current week (excluding any that already have a timesheet for
     * this user).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function availableShiftsForCreate(User $auth): array
    {
        $start = now()->subDays(7);
        $end = now()->addDays(7);

        $shifts = \App\Models\Shift::query()
            ->with([
                'client:id,first_name,last_name',
                'serviceContext:id,name',
                'tasks',
            ])
            ->whereBetween('starts_at', [$start, $end])
            ->when(! $auth->canDo('timesheets.manageAny'), fn ($q) => $q->where('user_id', $auth->id))
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('starts_at')
            ->limit(60)
            ->get();

        // Drop shifts that already have a timesheet for the same user.
        $existing = Timesheet::query()
            ->whereIn('shift_id', $shifts->pluck('id'))
            ->where('user_id', $auth->id)
            ->pluck('shift_id')
            ->all();

        return $shifts
            ->reject(fn ($s) => in_array($s->id, $existing, true))
            ->map(function ($s) {
                $tasks = $s->relationLoaded('tasks') ? $s->tasks : collect();

                return [
                    'id' => $s->id,
                    'client' => $s->client ? [
                        'id' => $s->client->id,
                        'first_name' => $s->client->first_name,
                        'last_name' => $s->client->last_name,
                    ] : null,
                    'starts_at' => optional($s->starts_at)->toIso8601String(),
                    'ends_at' => optional($s->ends_at)->toIso8601String(),
                    'location' => $s->location,
                    'shift_type' => $s->shift_type,
                    'status' => $s->status,
                    'service_context' => $s->serviceContext ? $s->serviceContext->name : null,
                    'expected_break_minutes' => (int) ($s->expected_break_minutes ?? 0),
                    'is_sleepover' => (bool) $s->is_sleepover,
                    'is_on_call' => (bool) $s->is_on_call,
                    'client_id' => $s->client_id,
                    'tasks' => $tasks->map(fn ($t) => [
                        'id' => $t->id,
                        'label' => $t->title ?? $t->label ?? 'Task',
                        'completed' => (bool) ($t->completed ?? false),
                        'time' => optional($t->scheduled_at ?? null)?->format('H:i'),
                        'minutes' => (int) ($t->estimated_minutes ?? 15),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    public function show(Request $request, Timesheet $timesheet)
    {
        // The roster grid (and any other surface) opens the read-only
        // ViewTimesheetDialog inline. It fetches the same row payload the index
        // table feeds that modal, so serve JSON for those requests and keep the
        // full edit page for normal navigation.
        if ($request->wantsJson() || $request->boolean('modal')) {
            return $this->showTimesheetCard($request, $timesheet);
        }

        return $this->edit($request, $timesheet);
    }

    /**
     * JSON payload for the inline ViewTimesheetDialog ("View timesheet" from the
     * roster grid). Mirrors the relations index() eager-loads so the row is
     * identical to what the index table hands the modal, applies the same access
     * guard as edit(), and returns can_approve so the modal knows whether to
     * surface the approve / return / reject controls.
     */
    protected function showTimesheetCard(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('timesheets.viewAny') || $auth->canDo('timesheets.viewAssigned')), 403);

        if (! $auth->canDo('timesheets.manageAny') && ! $this->canReviewTimesheets($auth) && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        $this->assertCanAccessTimesheet($auth, $timesheet);

        $timesheet->load([
            'client:id,first_name,last_name',
            'staff:id,name,email',
            'shift:id,client_id,service_context_id,starts_at,ends_at,location,shift_type,is_sleepover,is_on_call,expected_break_minutes,status',
            'shift.serviceContext:id,name',
            'shift.tasks:id,shift_id,is_completed',
            'site:id,name',
            'clientAllocations.client:id,first_name,last_name',
        ]);

        return response()->json([
            'timesheet' => $this->serializeTimesheetRow($timesheet),
            'can_approve' => $this->canReviewTimesheets($auth),
        ]);
    }

    /**
     * Store a new timesheet via the unified CreateTimesheetDialog. Supports
     * two modes:
     *   - shift   — `shift_id` is required; tasks come from the linked shift.
     *   - manual  — `activity_type` is required; `activity_items` (json) and
     *               optional client_id / site_id let the worker log non-shift
     *               time (training, meetings, travel, etc.).
     *
     * Both modes share the actual times worked, break, mileage, notes, and
     * tag toggles (sleepover/on-call/public_holiday).
     */
    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('timesheets.create'), 403);

        $data = $request->validate([
            'mode' => ['required', 'in:shift,manual'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id', 'required_if:mode,shift'],
            'activity_type' => [
                'nullable', 'string',
                'in:training,meeting,admin,travel,handover,supervision,standby,other',
                'required_if:mode,manual',
            ],
            'activity_items' => ['nullable', 'array'],
            'activity_items.*' => ['string', 'max:255'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'work_date' => ['required', 'date'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'mileage_km' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'sleepover' => ['nullable', 'boolean'],
            'on_call' => ['nullable', 'boolean'],
            'allowance_notes' => ['nullable', 'string'],
            'public_holiday' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'is_residential_billable' => ['nullable', 'boolean'],
            'submit' => ['nullable', 'boolean'],
            'tasks' => ['nullable', 'array'],
            'tasks.*.id' => ['integer'],
            'tasks.*.included' => ['boolean'],
            'tasks.*.completed' => ['boolean'],
        ]);

        $mode = $data['mode'];
        $userId = $auth->id;
        $shiftId = $data['shift_id'] ?? null;
        $linkedShift = null;

        if ($mode === 'shift') {
            $linkedShift = Shift::findOrFail($shiftId);
            $this->siteAccess()->assertCanAccessShift(
                $auth,
                $linkedShift,
                $this->timesheetBypassPermissions(),
                'You are not authorized to create timesheets for that site.',
            );
            if (! $auth->canDo('timesheets.manageAny') && $linkedShift->user_id !== $auth->id) {
                abort(403);
            }
            // Open / unassigned shifts have no `user_id`; default the timesheet
            // owner to the authenticated user so the row still has a valid
            // owner. Managers picking an open shift implicitly claim the
            // timesheet for themselves.
            $userId = $linkedShift->user_id ?? $auth->id;
            $data['client_id'] = $linkedShift->client_id;

            if (Timesheet::query()
                ->where('shift_id', $linkedShift->id)
                ->where('user_id', $userId)
                ->exists()) {
                $message = 'A timesheet already exists for this shift and staff member.';

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => $message,
                        'errors' => ['shift_id' => [$message]],
                    ], 422);
                }

                return back()->with('error', $message)->withInput();
            }
        }

        // Manual mode: client_id is optional. When provided, enforce site access.
        if (! empty($data['client_id'])) {
            $this->siteAccess()->assertCanAccessClientId(
                $auth,
                (int) $data['client_id'],
                $this->timesheetBypassPermissions(),
                'You are not authorized to create timesheets for that site.',
            );
        }

        $clientForSnapshot = $data['client_id'] ?? null;
        $snapshot = $clientForSnapshot
            ? $this->draftSnapshot((int) $clientForSnapshot, $linkedShift, $auth, $data['notes'] ?? null)
            : $this->manualSnapshot($auth, $data['activity_type'] ?? null, $data['site_id'] ?? null);

        $timesheet = Timesheet::create([
            'user_id' => $userId,
            'client_id' => $data['client_id'] ?? null,
            'shift_id' => $shiftId,
            'activity_type' => $mode === 'manual' ? ($data['activity_type'] ?? null) : null,
            'activity_items' => $mode === 'manual' ? ($data['activity_items'] ?? []) : null,
            'site_id' => $data['site_id'] ?? null,
            'shift_site_id' => $snapshot['site_id'] ?? null,
            'shift_service_context_id' => $snapshot['service_context_id'] ?? null,
            'work_date' => $data['work_date'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'break_minutes' => (int) ($data['break_minutes'] ?? $linkedShift?->expected_break_minutes ?? 0),
            'mileage_km' => $data['mileage_km'] ?? null,
            'sleepover' => $linkedShift ? (bool) $linkedShift->is_sleepover : (bool) ($data['sleepover'] ?? false),
            'on_call' => $linkedShift ? (bool) $linkedShift->is_on_call : (bool) ($data['on_call'] ?? false),
            'allowance_notes' => $data['allowance_notes'] ?? null,
            'public_holiday' => (bool) ($data['public_holiday'] ?? false),
            'notes' => $data['notes'] ?? null,
            'is_residential_billable' => (bool) ($data['is_residential_billable'] ?? false),
            'shift_site_name_snapshot' => $snapshot['site_name'] ?? null,
            'shift_location_snapshot' => $snapshot['location'] ?? null,
            'service_context_name_snapshot' => $snapshot['service_context_name'] ?? null,
            'client_name_snapshot' => $snapshot['client_name'] ?? null,
            'staff_name_snapshot' => $snapshot['staff_name'] ?? $auth->name,
            'shift_type_snapshot' => $snapshot['shift_type'] ?? ($mode === 'manual' ? ($data['activity_type'] ?? 'manual') : 'standard'),
            'coverage_roles_snapshot' => $snapshot['coverage_roles'] ?? [],
            'status' => 'draft',
            'created_by' => $auth->id,
        ]);

        if ($mode === 'shift') {
            app(TimesheetReconciliationService::class)->reconcile($timesheet);
        }

        $timesheet->load(['shift.client']);
        $client = $timesheet->shift?->client ?? $timesheet->client;

        app(NotificationService::class)->notifyCrud($request->user(), 'created', 'timesheet', $timesheet, $client, [
            'event_key' => 'timesheets.created',
            'title' => 'Timesheet created',
            'url' => url("/operations/timesheets?view={$timesheet->id}"),
            'target_user_ids' => [$timesheet->user_id],
        ]);

        // If the dialog's "Submit for approval" button was clicked, transition
        // straight to submitted so the worker doesn't have to chase a second
        // route from the dialog.
        if (! empty($data['submit'])) {
            try {
                $this->timesheetApprovals()->submit($timesheet, $auth);
                app(NotificationService::class)->notifyCrud($auth, 'submitted', 'timesheet', $timesheet, $client, [
                    'event_key' => 'timesheets.submitted',
                    'title' => 'Timesheet submitted for approval',
                    'url' => url("/operations/timesheets?view={$timesheet->id}"),
                    'include_entity_user' => false,
                ]);
            } catch (ValidationException $e) {
                return back()->withErrors($e->errors());
            }
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'timesheet_id' => $timesheet->id,
                'message' => 'Timesheet created.',
            ]);
        }

        return redirect()
            ->route('operations.timesheets.index', ['view' => $timesheet->id])
            ->with('success', 'Timesheet created.');
    }

    /**
     * Snapshot fields when there is no client and no shift to copy from (pure
     * manual entry: training, meeting, admin, etc).
     *
     * @return array<string, mixed>
     */
    protected function manualSnapshot(User $auth, ?string $activityType, ?int $siteId): array
    {
        $site = $siteId ? \App\Models\Site::find($siteId) : null;

        return [
            'site_id' => $siteId,
            'service_context_id' => null,
            'site_name' => $site?->name,
            'location' => $site?->name,
            'service_context_name' => $activityType,
            'client_name' => null,
            'staff_name' => $auth->name,
            'shift_type' => $activityType ?: 'manual',
            'coverage_roles' => [],
        ];
    }

    /**
     * Soft-archive a timesheet. Manager-only; used by the row context menu on
     * the index page. Leaves audit columns intact — the row remains readable
     * on the Archive tab.
     *
     * Updates the archive columns directly via the query builder to bypass
     * the model `saving` invariant guard (which rejects any change on an
     * approved timesheet). Archiving is an out-of-band catalogue action, not
     * an operational mutation, so the guard does not apply.
     */
    public function archive(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('timesheets.manageAny'), 403);
        $this->assertCanAccessTimesheet($auth, $timesheet);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        if ($timesheet->archived_at) {
            return back()->with('success', 'Timesheet already archived.');
        }

        Timesheet::query()->whereKey($timesheet->id)->update([
            'archived_at' => now(),
            'archived_reason' => $data['reason'] ?? 'Archived from row menu',
        ]);

        return back()->with('success', 'Timesheet archived.');
    }

    /**
     * Restore an archived timesheet back to the active list.
     */
    public function restore(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('timesheets.manageAny'), 403);
        $this->assertCanAccessTimesheet($auth, $timesheet);

        if (! $timesheet->archived_at) {
            return back()->with('success', 'Timesheet is already active.');
        }

        Timesheet::query()->whereKey($timesheet->id)->update([
            'archived_at' => null,
            'archived_reason' => null,
        ]);

        return back()->with('success', 'Timesheet restored.');
    }

    public function edit(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('timesheets.viewAny') || $auth->canDo('timesheets.viewAssigned')), 403);

        if (! $auth->canDo('timesheets.manageAny') && ! $this->canReviewTimesheets($auth) && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        $this->assertCanAccessTimesheet($auth, $timesheet);

        $timesheet->load([
            'client:id,first_name,last_name',
            'staff:id,name,email',
            'shift:id,client_id,service_context_id,starts_at,ends_at,location,shift_type,is_sleepover,is_on_call,expected_break_minutes,status,user_id',
            'shift.serviceContext:id,name',
            'shift.staff:id,name,email',
            // PR — per-client allocation breakdown shown read-only in the
            // approver UI. See `Timesheet::effectiveClientAllocations()`.
            'clientAllocations.client:id,first_name,last_name',
            'shift.site.clients:id,site_id,first_name,last_name',
        ]);
        $clients = $this->siteAccess()->applyClientScope(
            Client::query(),
            $auth,
            $this->timesheetBypassPermissions(),
        )->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        $timesheetPayload = array_merge($timesheet->toArray(), [
            'total_hours' => (float) $timesheet->total_hours,
            'client_allocations' => $timesheet->effectiveClientAllocations()->all(),
            'allocation_method' => $timesheet->dominantAllocationMethod(),
            'clients_candidates' => $this->buildAllocationCandidates($timesheet),
        ]);

        return inertia('operations/timesheets/edit', [
            'timesheet' => $timesheetPayload,
            'clients' => $clients,
            'canApprove' => $this->canReviewTimesheets($auth),
            'canSubmit' => $auth->canDo('timesheets.submit') && ($auth->canDo('timesheets.manageAny') || $timesheet->user_id === $auth->id),
            'canEdit' => $auth->canDo('timesheets.update')
                && ($auth->canDo('timesheets.manageAny') || $timesheet->user_id === $auth->id)
                && in_array($timesheet->status, ['draft', 'returned'], true),
        ]);
    }

    public function update(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('timesheets.update'), 403);

        // Ownership check
        if (! $auth->canDo('timesheets.manageAny') && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        $this->assertCanAccessTimesheet($auth, $timesheet);

        // Only editable while draft/returned (audit safety)
        if (! in_array($timesheet->status, ['draft', 'returned'], true)) {
            return back()->with('error', 'Only draft or returned timesheets can be edited.');
        }

        // Payroll lock check: if timesheet is in a locked payroll run, prevent edits
        if ($this->isLockedByPayroll($timesheet)) {
            return back()->with('error', 'This timesheet is locked by a payroll run and cannot be edited.');
        }

        if ($timesheet->is_protected_from_changes) {
            return back()->with('error', 'Approved or payroll-linked timesheets require a controlled correction workflow.');
        }

        $data = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'work_date' => ['required', 'date'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'mileage_km' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'sleepover' => ['nullable', 'boolean'],
            'on_call' => ['nullable', 'boolean'],
            'allowance_notes' => ['nullable', 'string'],
            'public_holiday' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'is_residential_billable' => ['nullable', 'boolean'],
        ]);

        $linkedShift = $timesheet->shift_id ? Shift::find($timesheet->shift_id) : null;
        if ($linkedShift) {
            $data['client_id'] = $linkedShift->client_id;
        }

        $snapshot = $this->draftSnapshot($data['client_id'], $linkedShift, $timesheet->staff ?? $auth, $data['notes'] ?? $timesheet->notes);

        $timesheet->fill([
            'client_id' => $data['client_id'],
            'work_date' => $data['work_date'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'break_minutes' => (int) ($data['break_minutes'] ?? 0),
            'mileage_km' => $data['mileage_km'] ?? null,
            'sleepover' => $linkedShift ? (bool) $linkedShift->is_sleepover : (bool) ($data['sleepover'] ?? false),
            'on_call' => $linkedShift ? (bool) $linkedShift->is_on_call : (bool) ($data['on_call'] ?? false),
            'allowance_notes' => $data['allowance_notes'] ?? null,
            'public_holiday' => (bool) ($data['public_holiday'] ?? false),
            'notes' => $data['notes'] ?? null,
            'is_residential_billable' => (bool) ($data['is_residential_billable'] ?? false),
            'shift_site_id' => $snapshot['site_id'] ?? $timesheet->shift_site_id,
            'shift_service_context_id' => $snapshot['service_context_id'] ?? $timesheet->shift_service_context_id,
            'shift_site_name_snapshot' => $snapshot['site_name'] ?? $timesheet->shift_site_name_snapshot,
            'shift_location_snapshot' => $snapshot['location'] ?? $timesheet->shift_location_snapshot,
            'service_context_name_snapshot' => $snapshot['service_context_name'] ?? $timesheet->service_context_name_snapshot,
            'client_name_snapshot' => $snapshot['client_name'] ?? $timesheet->client_name_snapshot,
            'staff_name_snapshot' => $snapshot['staff_name'] ?? $timesheet->staff_name_snapshot,
            'shift_type_snapshot' => $snapshot['shift_type'] ?? $timesheet->shift_type_snapshot ?? 'standard',
            'coverage_roles_snapshot' => $snapshot['coverage_roles'] ?? $timesheet->coverage_roles_snapshot ?? [],
        ]);

        $timesheet->save();

        app(TimesheetReconciliationService::class)->reconcile($timesheet);

        $timesheet->load(['shift.client']);
        $client = $timesheet->shift?->client;

        app(NotificationService::class)->notifyCrud($request->user(), 'updated', 'timesheet', $timesheet, $client, [
            'event_key' => 'timesheets.updated',
            'title' => 'Timesheet updated',
            'url' => url("/operations/timesheets/{$timesheet->id}/edit"),
            'target_user_ids' => [$timesheet->user_id],
        ]);

        return redirect()->back()->with('success', 'Timesheet updated.');
    }

    public function submit(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('timesheets.submit'), 403);

        // Ownership check
        if (! $auth->canDo('timesheets.manageAny') && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        $this->assertCanAccessTimesheet($auth, $timesheet);

        abort_unless(in_array($timesheet->status, ['draft', 'returned'], true), 403);

        // Payroll lock check
        if ($this->isLockedByPayroll($timesheet)) {
            return back()->with('error', 'This timesheet is locked by a payroll run and cannot be submitted.');
        }

        if ($timesheet->is_protected_from_changes) {
            return back()->with('error', 'Approved or payroll-linked timesheets cannot be resubmitted.');
        }

        abort_if(
            $timesheet->linkedShiftIsCancelled(),
            422,
            'Timesheets linked to cancelled shifts cannot be submitted.',
        );

        $result = $this->timesheetApprovals()->submit($timesheet, $auth);
        $submittedTimesheet = $result->timesheet;

        $client = $submittedTimesheet->shift?->client;

        app(NotificationService::class)->notifyCrud($auth, 'submitted', 'timesheet', $submittedTimesheet, $client, [
            'event_key' => 'timesheets.submitted',
            'title' => 'Timesheet submitted for approval',
            'url' => url("/operations/timesheets/{$submittedTimesheet->id}/edit"),
            'include_entity_user' => false,
        ]);

        return redirect()->back()->with('success', 'Timesheet submitted.');
    }

    /**
     * Atomic save-and-resubmit for the inline /my-day edit sheet.
     *
     * Why: the original UI did a chained PUT /timesheets/{id} → POST submit
     * from the browser. If the submit failed after the PUT succeeded, the
     * timesheet was mutated but stuck in `returned`, leaving the worker with
     * no clear retry path. This endpoint runs both inside one DB transaction
     * so the row either fully transitions to `submitted` or stays untouched.
     */
    public function resubmit(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless(
            $auth && $auth->canDo('timesheets.update') && $auth->canDo('timesheets.submit'),
            403,
        );

        if (! $auth->canDo('timesheets.manageAny') && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        $this->assertCanAccessTimesheet($auth, $timesheet);

        if (! in_array($timesheet->status, ['draft', 'returned'], true)) {
            return back()->with('error', 'Only draft or returned timesheets can be resubmitted.');
        }

        if ($this->isLockedByPayroll($timesheet)) {
            return back()->with('error', 'This timesheet is locked by a payroll run and cannot be resubmitted.');
        }

        if ($timesheet->is_protected_from_changes) {
            return back()->with('error', 'Approved or payroll-linked timesheets require a controlled correction workflow.');
        }

        abort_if(
            $timesheet->linkedShiftIsCancelled(),
            422,
            'Timesheets linked to cancelled shifts cannot be resubmitted.',
        );

        $data = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'work_date' => ['required', 'date'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'mileage_km' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'sleepover' => ['nullable', 'boolean'],
            'on_call' => ['nullable', 'boolean'],
            'allowance_notes' => ['nullable', 'string'],
            'public_holiday' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'is_residential_billable' => ['nullable', 'boolean'],
        ]);

        $linkedShift = $timesheet->shift_id ? Shift::find($timesheet->shift_id) : null;
        if ($linkedShift) {
            $data['client_id'] = $linkedShift->client_id;
        }

        $snapshot = $this->draftSnapshot(
            $data['client_id'],
            $linkedShift,
            $timesheet->staff ?? $auth,
            $data['notes'] ?? $timesheet->notes,
        );

        $result = $this->timesheetApprovals()->resubmit($timesheet, $auth, [
            'client_id' => $data['client_id'],
            'work_date' => $data['work_date'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'break_minutes' => (int) ($data['break_minutes'] ?? 0),
            'mileage_km' => $data['mileage_km'] ?? null,
            'sleepover' => $linkedShift ? (bool) $linkedShift->is_sleepover : (bool) ($data['sleepover'] ?? false),
            'on_call' => $linkedShift ? (bool) $linkedShift->is_on_call : (bool) ($data['on_call'] ?? false),
            'allowance_notes' => $data['allowance_notes'] ?? null,
            'public_holiday' => (bool) ($data['public_holiday'] ?? false),
            'notes' => $data['notes'] ?? null,
            'is_residential_billable' => (bool) ($data['is_residential_billable'] ?? false),
            'shift_site_id' => $snapshot['site_id'] ?? $timesheet->shift_site_id,
            'shift_service_context_id' => $snapshot['service_context_id'] ?? $timesheet->shift_service_context_id,
            'shift_site_name_snapshot' => $snapshot['site_name'] ?? $timesheet->shift_site_name_snapshot,
            'shift_location_snapshot' => $snapshot['location'] ?? $timesheet->shift_location_snapshot,
            'service_context_name_snapshot' => $snapshot['service_context_name'] ?? $timesheet->service_context_name_snapshot,
            'client_name_snapshot' => $snapshot['client_name'] ?? $timesheet->client_name_snapshot,
            'staff_name_snapshot' => $snapshot['staff_name'] ?? $timesheet->staff_name_snapshot,
            'shift_type_snapshot' => $snapshot['shift_type'] ?? $timesheet->shift_type_snapshot ?? 'standard',
            'coverage_roles_snapshot' => $snapshot['coverage_roles'] ?? $timesheet->coverage_roles_snapshot ?? [],
        ]);
        $submittedTimesheet = $result->timesheet;

        $client = $submittedTimesheet->shift?->client;

        app(NotificationService::class)->notifyCrud($auth, 'submitted', 'timesheet', $submittedTimesheet, $client, [
            'event_key' => 'timesheets.submitted',
            'title' => 'Timesheet updated and resubmitted',
            'url' => url("/operations/timesheets/{$submittedTimesheet->id}/edit"),
            'include_entity_user' => false,
        ]);

        return redirect()->back()->with('success', 'Timesheet updated and resubmitted.');
    }

    public function approve(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);
        $this->assertCanAccessTimesheet($auth, $timesheet);

        $data = $request->validate([
            'decision_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $result = $this->timesheetApprovals()
                ->approve($timesheet, $auth, $data['decision_notes'] ?? null);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        /** @var \App\Models\Timesheet $approvedTimesheet */
        $approvedTimesheet = $result->timesheet;

        if (! $result->changed) {
            return redirect()->back()->with('success', 'Timesheet already approved.');
        }

        $client = $approvedTimesheet->shift?->client;

        app(NotificationService::class)->notifyCrud($auth, 'approved', 'timesheet', $approvedTimesheet, $client, [
            'event_key' => 'timesheets.approved',
            'title' => 'Timesheet approved',
            'url' => url("/operations/timesheets/{$approvedTimesheet->id}/edit"),
        ]);

        return redirect()->back()->with('success', 'Timesheet approved.');
    }

    public function reject(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);
        abort_unless($timesheet->status === 'submitted', 403);
        $this->assertCanAccessTimesheet($auth, $timesheet);

        if ($timesheet->is_payroll_segment_complete || $timesheet->payroll_reference) {
            return back()->with('error', 'Payroll-linked timesheets cannot be rejected after export preparation.');
        }

        $data = $request->validate([
            'decision_notes' => ['nullable', 'string', 'max:5000'],
            'rejection_reason' => ['nullable', 'string', 'max:5000'],
        ]);

        $decisionNotes = $data['decision_notes'] ?? $data['rejection_reason'] ?? null;
        if (! $decisionNotes) {
            return back()->withErrors(['decision_notes' => 'Decision notes are required.']);
        }

        $result = $this->timesheetApprovals()->reject($timesheet, $auth, $decisionNotes);
        $rejectedTimesheet = $result->timesheet;

        $client = $rejectedTimesheet->shift?->client;

        app(NotificationService::class)->notifyCrud($auth, 'rejected', 'timesheet', $rejectedTimesheet, $client, [
            'event_key' => 'timesheets.rejected',
            'title' => 'Timesheet rejected',
            'url' => url("/operations/timesheets/{$rejectedTimesheet->id}/edit"),
        ]);

        return redirect()->back()->with('success', 'Timesheet rejected.');
    }

    public function returnForChanges(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);
        abort_unless($timesheet->status === 'submitted', 403);
        $this->assertCanAccessTimesheet($auth, $timesheet);

        if ($timesheet->is_payroll_segment_complete || $timesheet->payroll_reference) {
            return back()->with('error', 'Payroll-linked timesheets cannot be returned after export preparation.');
        }

        $data = $request->validate([
            'returned_notes' => ['nullable', 'string', 'max:5000'],
            'return_reason' => ['nullable', 'string', 'max:5000'],
        ]);

        $returnedNotes = $data['returned_notes'] ?? $data['return_reason'] ?? null;
        if (! $returnedNotes) {
            return back()->withErrors(['returned_notes' => 'Returned notes are required.']);
        }

        $result = $this->timesheetApprovals()->returnForChanges($timesheet, $auth, $returnedNotes);
        $returnedTimesheet = $result->timesheet;

        $client = $returnedTimesheet->shift?->client;

        app(NotificationService::class)->notifyCrud($auth, 'returned', 'timesheet', $returnedTimesheet, $client, [
            'event_key' => 'timesheets.returned',
            'title' => 'Timesheet returned for changes',
            'url' => url("/operations/timesheets/{$returnedTimesheet->id}/edit"),
        ]);

        return redirect()->back()->with('success', 'Timesheet returned for changes.');
    }

    /**
     * Check if a timesheet is locked by a payroll run.
     */
    protected function isLockedByPayroll(Timesheet $timesheet): bool
    {
        if (! $timesheet->work_date) {
            return false;
        }

        $user = $timesheet->relationLoaded('user')
            ? $timesheet->user
            : User::query()->with('hrEmployeeProfile')->find($timesheet->user_id);

        $user?->loadMissing('hrEmployeeProfile');

        $tenantId = $user?->hrEmployeeProfile?->tenant_id
            ?? $user?->organization_id
            ?? $user?->getAttribute('tenant_id');

        if (! $tenantId) {
            return false;
        }

        return HrPayrollRun::where('tenant_id', $tenantId)
            ->whereIn('status', ['locked', 'exported'])
            ->where('period_start', '<=', $timesheet->work_date)
            ->where('period_end', '>=', $timesheet->work_date)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    protected function draftSnapshot(?int $clientId, ?Shift $linkedShift, User $staff, ?string $location = null): array
    {
        $snapshots = app(ShiftOperationalSnapshotService::class);

        if ($linkedShift) {
            return $snapshots->snapshotForShift($linkedShift, $linkedShift->staff ?? $staff);
        }

        if (! $clientId) {
            return $snapshots->snapshotForClient(null, $staff, $location);
        }

        return $snapshots->snapshotForClient(
            Client::query()->with(['site:id,name', 'serviceContext:id,name'])->find($clientId),
            $staff,
            $location,
        );
    }

    /**
     * Payroll adjustments pending queue: approved amendments on payroll-linked
     * timesheets that have not yet been applied / processed.
     */
    public function payrollAdjustmentsPending(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('timesheets.approve') || $auth->canDo('timesheets.manageAny')), 403);

        $amendments = TimesheetAmendment::query()
            ->where('status', TimesheetAmendment::STATUS_APPROVED)
            ->where('payroll_adjustment_required', true)
            ->whereNull('applied_at')
            ->with([
                'timesheet:id,shift_id,user_id,client_id,work_date,starts_at,ends_at,status,staff_name_snapshot,client_name_snapshot,shift_site_name_snapshot,payroll_reference,exported_to_payroll_at',
                'timesheet.shift:id,starts_at,ends_at',
                'requestedBy:id,name',
                'reviewedBy:id,name',
            ])
            ->orderBy('reviewed_at')
            ->paginate(20)
            ->withQueryString();

        return inertia('operations/timesheets/payroll-adjustments', [
            'amendments' => $amendments->through(fn (TimesheetAmendment $a) => [
                'id' => $a->id,
                'timesheet_id' => $a->timesheet_id,
                'staff_name' => $a->timesheet?->staff_name_snapshot ?? 'Unknown',
                'client_name' => $a->timesheet?->client_name_snapshot ?? '',
                'site_name' => $a->timesheet?->shift_site_name_snapshot ?? '',
                'work_date' => $a->timesheet?->work_date?->toDateString(),
                'original_values' => $a->original_values,
                'proposed_values' => $a->proposed_values,
                'reason' => $a->reason,
                'requested_by' => $a->requestedBy?->name,
                'reviewed_by' => $a->reviewedBy?->name,
                'reviewed_at' => $a->reviewed_at?->toIso8601String(),
                'payroll_reference' => $a->timesheet?->payroll_reference,
                'timesheet_url' => url("/operations/timesheets/{$a->timesheet_id}/edit"),
            ]),
        ]);
    }

    /**
     * Mark a payroll-linked amendment as processed (payroll adjustment handled externally).
     */
    public function markPayrollAdjustmentProcessed(Request $request, TimesheetAmendment $amendment)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('timesheets.approve') || $auth->canDo('timesheets.manageAny')), 403);

        if ($amendment->status !== TimesheetAmendment::STATUS_APPROVED) {
            return back()->with('error', 'Only approved amendments can be marked as processed.');
        }

        if (! $amendment->payroll_adjustment_required) {
            return back()->with('error', 'This amendment does not require payroll adjustment.');
        }

        if ($amendment->applied_at) {
            return back()->with('success', 'This adjustment has already been marked as processed.');
        }

        $amendment->update(['applied_at' => now()]);

        \App\Services\AuditLogger::log('timesheet.amendment.payroll_processed', $amendment->timesheet, [
            'amendment_id' => $amendment->id,
            'processed_by' => $auth->id,
        ]);

        return back()->with('success', 'Payroll adjustment marked as processed.');
    }

    protected function canReviewTimesheets(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        return $user->canDo('timesheets.approve')
            || $user->canDo('timesheets.manageAny');
    }

    protected function timesheetApprovals(): TimesheetApprovalService
    {
        return app(TimesheetApprovalService::class);
    }

    protected function siteAccess(): UserSiteAccessService
    {
        return app(UserSiteAccessService::class);
    }

    /**
     * @return array<int, string>
     */
    protected function timesheetBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }

    protected function assertCanAccessTimesheet(User $auth, Timesheet $timesheet): void
    {
        $this->siteAccess()->assertCanAccessTimesheet(
            $auth,
            $timesheet,
            $this->timesheetBypassPermissions(),
            'You are not authorized to access timesheets for this site.',
        );
    }
}
