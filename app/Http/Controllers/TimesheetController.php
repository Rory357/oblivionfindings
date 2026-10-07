<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Shifts\Timesheets\TimesheetAllocationService;
use App\Domain\Shifts\Timesheets\TimesheetApprovalService;
use App\Domain\Shifts\Timesheets\TimesheetCommandReceipt;
use App\Domain\Shifts\Timesheets\TimesheetCreationService;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\TimesheetAmendment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\ShiftOperationalSnapshotService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TimesheetController extends Controller
{
    private array $staffProfileLinks = [];

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
            ->tap(fn ($query) => $this->siteAccess()->applyTimesheetScope($query, $auth, []))
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
        return app(\App\Domain\Shifts\Timesheets\TimesheetAllocationService::class)->candidates($timesheet, auth()->user());
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
            ->tap(fn ($query) => $this->siteAccess()->applyTimesheetScope($query, $auth, []))
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
            ->tap(fn ($query) => $this->siteAccess()->applyTimesheetScope($query, $auth, []))
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
            ->tap(fn ($query) => $this->siteAccess()->applyTimesheetScope($query, $auth, []))
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
        $filters = $this->timesheetReadFilters($request);
        $canApprove = $this->canReviewTimesheets($auth);
        $approvalQueue = $filters['tab'] === 'submitted' && $canApprove;
        $rowEagerLoads = [
            'client:id,first_name,last_name', 'staff:id,name,email',
            'shift:id,client_id,service_context_id,starts_at,ends_at,location,shift_type,is_sleepover,is_on_call,expected_break_minutes,status',
            'shift.serviceContext:id,name', 'shift.tasks:id,shift_id,is_completed',
            'site:id,name', 'clientAllocations.client:id,first_name,last_name',
        ];
        $cohort = $this->timesheetReadCohort($auth, $filters, $approvalQueue);
        $tabCounts = $this->computeTabCounts($auth, $filters);
        $q = (clone $cohort)->with($rowEagerLoads)->orderByDesc('work_date')->orderByDesc('id');
        if ($filters['tab'] === 'archived') {
            $q->whereNotNull('archived_at');
        } else {
            $q->whereNull('archived_at');
            if ($filters['tab'] !== 'all') {
                $q->where('status', $filters['tab']);
            }
        }
        $total = $tabCounts[$filters['tab']];
        $filters['page'] = min($filters['page'], max(1, (int) ceil($total / 50)));
        $timesheets = $q->paginate(50, ['*'], 'page', $filters['page'], $total)->withQueryString();
        $pageShown = $timesheets->count();
        $includesDetailRecord = false;

        // Preserve authorised legacy dialog links, separately from the filtered page totals.
        $viewId = (int) ($filters['view'] ?? $filters['edit'] ?? 0);
        if ($viewId && ! $timesheets->getCollection()->contains(fn (Timesheet $ts) => (int) $ts->id === $viewId)) {
            $extraQuery = Timesheet::query()->with($rowEagerLoads)->whereKey($viewId);
            $this->siteAccess()->applyTimesheetScope($extraQuery, $auth, $this->timesheetReadBypassPermissions());
            $extra = $extraQuery->first();
            if ($extra && ($auth->canDo('timesheets.manageAny') || (int) $extra->user_id === (int) $auth->id
                || ($canApprove && $extra->status === 'submitted'))) {
                $timesheets->setCollection($timesheets->getCollection()->prepend($extra));
                $includesDetailRecord = true;
            }
        }
        $pagination = ['current_page' => $timesheets->currentPage(), 'per_page' => $timesheets->perPage(),
            'total' => $total, 'last_page' => $timesheets->lastPage(), 'from' => $timesheets->firstItem(),
            'to' => $pageShown ? (($filters['page'] - 1) * 50 + $pageShown) : null,
            'links' => $timesheets->linkCollection()->toArray()];
        // firstItem uses the materialised collection; a detail-only row is not a page result.
        $pagination['from'] = $pageShown ? (($filters['page'] - 1) * 50 + 1) : null;
        $mutableTimesheetIds = Timesheet::query()->whereIn('id', $timesheets->getCollection()->pluck('id'))
            ->tap(fn ($query) => $this->siteAccess()->applyTimesheetScope($query, $auth, []))
            ->pluck('id')->mapWithKeys(fn ($id): array => [(int) $id => true]);
        $this->primeStaffProfileLinks($timesheets->getCollection()->pluck('user_id')->unique()->all(), $auth);
        $timesheets = $timesheets->through(fn (Timesheet $ts) => $this->serializeTimesheetRow(
            $ts, $mutableTimesheetIds->has((int) $ts->id), $canApprove));
        $summaryBase = clone $cohort;
        $filters['tab'] === 'archived' ? $summaryBase->whereNotNull('archived_at') : $summaryBase->whereNull('archived_at');
        $summaryCounts = $this->scopedStatusCounts($summaryBase);
        $summary = ['total' => array_sum($summaryCounts), ...array_replace(
            array_fill_keys(['draft', 'submitted', 'returned', 'approved', 'rejected', 'paid'], 0),
            array_intersect_key($summaryCounts, array_flip(['draft', 'submitted', 'returned', 'approved', 'rejected', 'paid'])))];
        $summary['archived'] = $filters['tab'] === 'archived' ? $summary['total'] : 0;
        $heroSummary = $this->computeHeroSummary($auth, $summaryBase, $filters, $approvalQueue);
        $scope = $this->timesheetReadScopeLabel($auth, $approvalQueue);
        // Clients / sites / shifts for the Create dialog and filters.
        $clientScope = $this->siteAccess()->applyClientScope(Client::query(), $auth, []);
        $clients = $clientScope->orderBy('first_name')->get(['id', 'first_name', 'last_name']);

        $staff = $canApprove
            ? $this->siteAccess()->applyStaffScope(User::staff(), $auth, [])
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
            : [];

        $sites = $this->siteAccess()
            ->applySiteScope(Site::query(), $auth, [])
            ->orderBy('name')
            ->get(['id', 'name']);

        // Today + upcoming shifts available for tile-pick in the Create dialog.
        $availableShifts = $this->availableShiftsForCreate($auth);

        return inertia('operations/timesheets/index', [
            'timesheets' => $timesheets, 'filters' => $filters, 'tabCounts' => $tabCounts,
            'summary' => $summary, 'pagination' => $pagination,
            'lists' => ['timesheets' => ['total' => $total, 'shown' => $pageShown, 'limit' => 50,
                'page' => $filters['page'], 'truncated' => $total > $pageShown,
                'includes_detail_record' => $includesDetailRecord, 'materialized_count' => $timesheets->count()]],
            'evidence' => ['state' => $summary['total'] === 0 ? 'no_records' : 'recorded', 'complete' => true,
                'basis' => 'recorded_timesheets_matching_filters_before_status', 'scope' => $scope,
                'archive_mode' => $filters['tab'] === 'archived' ? 'archived' : 'active',
                'date_basis' => 'stored_work_date', 'date_from' => $filters['from'], 'date_to' => $filters['to'],
                'period_start' => $filters['from'] ? Carbon::parse($filters['from'], $this->workerTimezone())->startOfDay()->utc()->toIso8601String() : null,
                'period_end_exclusive' => $filters['to'] ? Carbon::parse($filters['to'], $this->workerTimezone())->startOfDay()->addDay()->utc()->toIso8601String() : null,
                'search_basis' => 'recorded_client_staff_location_snapshots',
                'timezone' => $this->workerTimezone(), 'checked_at' => now()->toIso8601String(),
                'tab_counts_basis' => 'each_destination_with_its_existing_owner_or_submitted_review_scope'],
            'heroSummary' => $heroSummary, 'workerTimezone' => $this->workerTimezone(),
            'isOwnOnlyView' => ! $auth->canDo('timesheets.manageAny') && ! $approvalQueue,
            'clients' => $clients, 'sites' => $sites, 'staff' => $staff, 'availableShifts' => $availableShifts,
            'canApprove' => $canApprove, 'canCreate' => $auth->isApproved() && $auth->canDo('timesheets.create'),
            'canSubmit' => $auth->isApproved() && $auth->canDo('timesheets.submit'),
        ]);
    }

    /**
     * Serialise a timesheet row for the index table — includes hours, task
     * progress, allocation breakdown, and a hover-popover payload.
     *
     * @return array<string, mixed>
     */
    protected function serializeTimesheetRow(
        Timesheet $ts,
        bool $canMutate = false,
        bool $canReview = false,
    ): array {
        $data = $ts->toArray();
        $data['total_hours'] = (float) $ts->total_hours;
        $data['client_allocations'] = $ts->effectiveClientAllocations()->all();
        $data['allocation_method'] = $ts->dominantAllocationMethod();
        $data['can_mutate'] = $canMutate;
        $data['can_approve'] = $canMutate && $canReview;
        $data['can_edit'] = $canMutate && $ts->attendance_session_id === null;
        $actor = auth()->user();
        $approved = $actor && $actor->isApproved();
        $owns = $actor && ((int) $ts->user_id === (int) $actor->id || $actor->canDo('timesheets.manageAny'));
        $draft = in_array($ts->status, ['draft', 'returned'], true) && ! $ts->is_protected_from_changes && ! $ts->linkedShiftIsCancelled();
        $data['can_update'] = $canMutate && $approved && $owns && $draft && $ts->attendance_session_id === null && $actor->canDo('timesheets.update');
        $data['can_submit'] = $canMutate && $approved && $owns && $draft && $actor->canDo('timesheets.submit');
        $data['can_resubmit'] = $data['can_update'] && $data['can_submit'];
        $reviewable = $canMutate && $approved && $canReview && $ts->status === 'submitted' && ! $ts->is_protected_from_changes;
        $data['can_approve'] = $reviewable && (int) $ts->user_id !== (int) $actor?->id;
        $data['can_reject'] = $reviewable;
        $data['can_return'] = $reviewable;

        $profileLink = $this->staffProfileLinks[(int) $ts->user_id] ?? ['employee_profile_id' => null, 'profile_url' => null];
        $data['staff_employee_profile_id'] = $profileLink['employee_profile_id'];
        $data['staff_profile_url'] = $profileLink['profile_url'];

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

    private function timesheetReadFilters(Request $request): array
    {
        $data = $request->validate([
            'tab' => ['nullable', Rule::in(['all', 'draft', 'submitted', 'returned', 'approved', 'rejected', 'paid', 'archived'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'client_id' => ['nullable', 'integer', 'min:1'], 'staff_id' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:255'], 'page' => ['nullable', 'integer', 'min:1', 'max:2147483647'],
            'view' => ['nullable', 'integer', 'min:1'], 'edit' => ['nullable', 'integer', 'min:1'],
        ]);

        return ['tab' => $data['tab'] ?? 'all', 'from' => $data['from'] ?? null, 'to' => $data['to'] ?? null,
            'client_id' => isset($data['client_id']) ? (int) $data['client_id'] : null,
            'staff_id' => isset($data['staff_id']) ? (int) $data['staff_id'] : null,
            'search' => $data['search'] ?? null, 'page' => (int) ($data['page'] ?? 1),
            'view' => isset($data['view']) ? (int) $data['view'] : null,
            'edit' => isset($data['edit']) ? (int) $data['edit'] : null];
    }

    private function timesheetReadCohort(User $auth, array $filters, bool $approvalQueue = false): Builder
    {
        $query = Timesheet::query();
        $this->siteAccess()->applyTimesheetScope($query, $auth, $this->timesheetReadBypassPermissions());
        if (! $auth->canDo('timesheets.manageAny')) {
            $query->where(function (Builder $owner) use ($auth, $approvalQueue): void {
                $owner->where('user_id', $auth->id);
                if ($approvalQueue && $this->canReviewTimesheets($auth)) {
                    // Other workers' submitted records are the existing review queue; their drafts are not.
                    $owner->orWhere('status', 'submitted');
                }
            });
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (! empty($filters[$key])) {
                $query->whereDate('work_date', $operator, $filters[$key]);
            }
        }
        foreach (['client_id' => 'client_id', 'staff_id' => 'user_id'] as $key => $column) {
            if (! empty($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }
        if (filled($filters['search'] ?? null)) {
            $pattern = '%'.str_replace(['=', '%', '_'], ['==', '=%', '=_'], $filters['search']).'%';
            $query->where(function (Builder $text) use ($pattern): void {
                foreach (['client_name_snapshot', 'staff_name_snapshot', 'shift_location_snapshot'] as $i => $column) {
                    $method = $i === 0 ? 'whereRaw' : 'orWhereRaw';
                    $text->{$method}("{$column} LIKE ? ESCAPE '='", [$pattern]);
                }
            });
        }

        return $query;
    }

    protected function scopedStatusCounts(Builder $base): array
    {
        return (clone $base)->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')
            ->pluck('aggregate', 'status')->map(fn ($count): int => (int) $count)->all();
    }

    protected function computeTabCounts(User $auth, array $filters): array
    {
        $own = $this->timesheetReadCohort($auth, $filters);
        $counts = $this->scopedStatusCounts((clone $own)->whereNull('archived_at'));
        $tabs = ['all' => array_sum($counts), ...array_replace(
            array_fill_keys(['draft', 'submitted', 'returned', 'approved', 'rejected', 'paid'], 0),
            array_intersect_key($counts, array_flip(['draft', 'submitted', 'returned', 'approved', 'rejected', 'paid']))),
            'archived' => (clone $own)->whereNotNull('archived_at')->count()];
        if (! $auth->canDo('timesheets.manageAny') && $this->canReviewTimesheets($auth)) {
            $tabs['submitted'] = $this->timesheetReadCohort($auth, $filters, true)
                ->whereNull('archived_at')->where('status', 'submitted')->count();
        }

        return $tabs;
    }

    private function workerTimezone(): string
    {
        return config('app.worker_timezone') ?: config('app.timezone', 'UTC');
    }

    private function timesheetReadScopeLabel(User $auth, bool $approvalQueue): string
    {
        return $auth->canDo('timesheets.manageAny') ? 'permitted_sites'
            : ($approvalQueue ? 'own_and_permitted_submitted_records' : 'own_records_in_permitted_sites');
    }

    /** Summary defaults to the current worker week; the list never acquires an implicit date filter. */
    protected function resolveSummaryWeek(?string $from, ?string $to): array
    {
        $start = $from ? Carbon::parse($from, $this->workerTimezone())->startOfDay() : null;
        if ($start && $to && $start->dayOfWeek === Carbon::MONDAY
            && $start->copy()->addDays(6)->toDateString() === $to) {
            return [$start, $start->copy()->addDays(6)->endOfDay()];
        }

        return [now($this->workerTimezone())->startOfWeek(Carbon::MONDAY)->startOfDay(),
            now($this->workerTimezone())->startOfWeek(Carbon::MONDAY)->addDays(6)->endOfDay()];
    }

    protected function computeHeroSummary(User $auth, Builder $cohort, array $filters, bool $approvalQueue): array
    {
        [$weekStart, $weekEnd] = $this->resolveSummaryWeek($filters['from'], $filters['to']);
        $weekEndExclusive = $weekStart->copy()->addWeek();
        $week = (clone $cohort)->whereDate('work_date', '>=', $weekStart->toDateString())
            ->whereDate('work_date', '<', $weekEndExclusive->toDateString());
        $counts = $this->scopedStatusCounts($week);
        $hours = round((clone $week)->select(['id', 'starts_at', 'ends_at', 'break_minutes'])
            ->lazyById(100)->sum(fn (Timesheet $row): float => (float) $row->total_hours), 1);
        $roster = $this->timesheetRosterCohort($auth, $filters, $approvalQueue)
            ->where('starts_at', '>=', $weekStart->copy()->utc())->where('starts_at', '<', $weekEndExclusive->copy()->utc());
        $target = filled($filters['search']) ? null : round((clone $roster)
            ->select(['id', 'starts_at', 'ends_at', 'expected_break_minutes'])->lazyById(100)->sum(function (Shift $shift): float {
                if (! $shift->starts_at || ! $shift->ends_at) {
                    return 0;
                }

                return max(0, $shift->starts_at->diffInMinutes($shift->ends_at) - (int) $shift->expected_break_minutes) / 60;
            }), 1);
        $today = now($this->workerTimezone())->startOfDay();
        $todayQuery = $this->timesheetRosterCohort($auth, $filters, $approvalQueue)
            ->where('starts_at', '>=', $today->copy()->utc())->where('starts_at', '<', $today->copy()->addDay()->utc());
        $onShift = $this->timesheetRosterCohort($auth, $filters, $approvalQueue)->where('status', 'in_progress')
            ->where('starts_at', '<=', now())->where('ends_at', '>', now());
        $allWeeks = $this->scopedStatusCounts($this->timesheetReadCohort($auth, [], $approvalQueue)->whereNull('archived_at'));

        return ['firstName' => explode(' ', trim($auth->name))[0] ?? $auth->name,
            'week_start' => $weekStart->toDateString(), 'week_end' => $weekEnd->toDateString(),
            'week_number' => (int) $weekStart->format('W'), 'timesheets_total' => array_sum($counts),
            'timesheets_submitted' => $counts['submitted'] ?? 0, 'timesheets_approved' => $counts['approved'] ?? 0,
            'timesheets_returned' => $counts['returned'] ?? 0, 'unapproved' => $counts['submitted'] ?? 0,
            'hours_this_week' => $hours, 'hours_target' => $target, 'next_payroll_date' => null, 'regions_count' => null,
            'sites_count' => $this->siteAccess()->applySiteScope(Site::query(), $auth, $this->timesheetReadBypassPermissions())->count(),
            'rostered_today' => filled($filters['search']) ? null : $todayQuery->count(),
            'staff_on_shift' => filled($filters['search']) ? null : $onShift->distinct()->count('user_id'),
            'all_weeks' => ['total' => array_sum($allWeeks), 'submitted' => $allWeeks['submitted'] ?? 0,
                'approved' => $allWeeks['approved'] ?? 0, 'returned' => $allWeeks['returned'] ?? 0,
                'scope' => $this->timesheetReadScopeLabel($auth, $approvalQueue),
                'basis' => 'all_non_archived_records_without_list_filters'],
            'evidence' => ['state' => array_sum($counts) === 0 ? 'no_records' : 'recorded', 'complete' => true,
                'basis' => 'recorded_timesheets_matching_filters_before_status_in_summary_week',
                'scope' => $this->timesheetReadScopeLabel($auth, $approvalQueue), 'timezone' => $this->workerTimezone(),
                'period_start' => $weekStart->copy()->utc()->toIso8601String(),
                'period_end_exclusive' => $weekEndExclusive->copy()->utc()->toIso8601String(), 'checked_at' => now()->toIso8601String(),
                'date_basis' => 'stored_work_date', 'list_tab' => $filters['tab'], 'date_from' => $filters['from'], 'date_to' => $filters['to'],
                'rostered_hours_basis' => $target === null ? 'unavailable_for_snapshot_text_search' : 'permitted_assigned_employee_duties_starting_in_summary_week',
                'payroll_close_basis' => 'not_available', 'sites_count_basis' => 'permitted_sites_without_list_filters',
                'today' => $today->toDateString(), 'today_counts_basis' => 'permitted_assigned_employee_duties_starting_on_worker_local_today',
                'on_shift_basis' => 'distinct_recorded_in_progress_workers_overlapping_now_not_verified_attendance']];
    }

    private function timesheetRosterCohort(User $auth, array $filters, bool $approvalQueue): Builder
    {
        $query = Shift::query()->employeeDuties()->whereNotNull('user_id')->where('status', '!=', 'cancelled');
        $this->siteAccess()->applyShiftScope($query, $auth, $this->timesheetReadBypassPermissions());
        if (! $auth->canDo('timesheets.manageAny') && ! $approvalQueue) {
            $query->where('user_id', $auth->id);
        }
        foreach (['client_id' => 'client_id', 'staff_id' => 'user_id'] as $key => $column) {
            if (! empty($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }

        return $query;
    }

    private function primeStaffProfileLinks(array $userIds, User $viewer): void
    {
        foreach ($userIds as $id) {
            $this->staffProfileLinks[(int) $id] = ['employee_profile_id' => null, 'profile_url' => null];
        }
        if (! $viewer->canDo('hr.employees.viewAny') || $userIds === []) {
            return;
        }
        $profiles = $this->siteAccess()->applyHistoricalHrEmployeeProfileScope(
            HrEmployeeProfile::withTrashed()->whereIn('user_id', $userIds)->whereHas('user', fn (Builder $staff) => $staff->staff()), $viewer)
            ->get(['id', 'user_id']);
        foreach ($profiles as $profile) {
            $this->staffProfileLinks[(int) $profile->user_id] = ['employee_profile_id' => (int) $profile->id,
                'profile_url' => route('hr.people.show', $profile->id)];
        }
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

        $shifts = Shift::query()
            ->with([
                'client:id,first_name,last_name',
                'serviceContext:id,name',
                'tasks',
            ])
            ->whereBetween('starts_at', [$start, $end])
            ->when(! $auth->canDo('timesheets.manageAny'), fn ($q) => $q->where('user_id', $auth->id))
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('starts_at')
            ->limit(60);
        $this->siteAccess()->applyShiftScope($shifts, $auth, []);
        $shifts = $shifts->get();

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
        $auth = $request->user();
        abort_unless($auth, 403);
        $this->assertCanViewTimesheet($auth, $timesheet);

        // The roster grid (and any other surface) opens the read-only
        // ViewTimesheetDialog inline. It fetches the same row payload the index
        // table feeds that modal, so serve JSON for those requests; normal
        // navigation lands on the unified index with the dialog deep-linked.
        if ($request->wantsJson() || $request->boolean('modal')) {
            return $this->showTimesheetCard($request, $timesheet);
        }

        return redirect()->to("/operations/timesheets?view={$timesheet->id}");
    }

    /**
     * JSON payload for the inline ViewTimesheetDialog ("View timesheet" from the
     * roster grid). Mirrors the relations index() eager-loads so the row is
     * identical to what the index table hands the modal, applies the same view
     * guard as show(), and returns can_approve so the modal knows whether to
     * surface the approve / return / reject controls.
     */
    protected function showTimesheetCard(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($auth, 403);
        $this->assertCanViewTimesheet($auth, $timesheet);

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
            'timesheet' => $this->serializeTimesheetRow(
                $timesheet,
                $this->canMutateTimesheet($auth, $timesheet),
                $this->canReviewTimesheets($auth),
            ),
            'can_approve' => $this->canReviewTimesheets($auth)
                && $this->canMutateTimesheet($auth, $timesheet),
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
        $rootEntry = $this->commandReceipt()->begin($request);
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

        try {
            $result = app(TimesheetCreationService::class)->create($auth, $data);
        } catch (ValidationException $exception) {
            $duplicate = $exception->errors()['shift_id'][0] ?? null;
            if ($duplicate === 'A timesheet already exists for this shift and staff member.') {
                if ($request->expectsJson()) {
                    return response()->json(['message' => $duplicate, 'errors' => $exception->errors()], 422);
                }

                return back()->with('error', $duplicate)->withInput();
            }
            throw $exception;
        }
        $timesheet = $result->timesheet;
        $this->notifyTimesheetAfterCommit($auth, 'created', $timesheet, [
            'event_key' => 'timesheets.created', 'title' => 'Timesheet created',
            'url' => url('/operations/timesheets?view='.$timesheet->id), 'target_user_ids' => [$timesheet->user_id],
        ]);
        if (! empty($data['submit'])) {
            $this->notifyTimesheetAfterCommit($auth, 'submitted', $timesheet, [
                'event_key' => 'timesheets.submitted', 'title' => 'Timesheet submitted for approval',
                'url' => url('/operations/timesheets?view='.$timesheet->id), 'include_entity_user' => false,
            ]);
        }
        $receipt = $this->commandReceipt()->committed($rootEntry, 'create', $result, ! empty($data['submit']));
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true, 'timesheet_id' => $timesheet->id, 'message' => 'Timesheet created.',
                ...($receipt === null ? [] : ['timesheet_result' => $receipt]),
            ]);
        }
        $response = redirect()->route('operations.timesheets.index', ['view' => $timesheet->id])->with('success', 'Timesheet created.');
        if ($receipt !== null) {
            $response->with('timesheet_result', $receipt);
        }

        return $response;
    }

    /**
     * Snapshot fields when there is no client and no shift to copy from (pure
     * manual entry: training, meeting, admin, etc).
     *
     * @return array<string, mixed>
     */
    protected function manualSnapshot(User $auth, ?string $activityType, ?int $siteId): array
    {
        $site = $siteId ? Site::find($siteId) : null;

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
        $this->assertCanMutateTimesheet($auth, $timesheet);

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
        $this->assertCanMutateTimesheet($auth, $timesheet);

        if (! $timesheet->archived_at) {
            return back()->with('success', 'Timesheet is already active.');
        }

        Timesheet::query()->whereKey($timesheet->id)->update([
            'archived_at' => null,
            'archived_reason' => null,
        ]);

        return back()->with('success', 'Timesheet restored.');
    }

    /**
     * The standalone edit page is retired — editing lives in the unified
     * index's EditTimesheetDialog. Old deep links (notifications, bookmarks)
     * land on the index with the dialog deep-linked; the index applies the
     * same scoping + visibility rules when materialising the target row.
     */
    public function edit(Request $request, Timesheet $timesheet)
    {
        $auth = $request->user();
        abort_unless($auth, 403);
        $this->assertCanEditTimesheet($auth, $timesheet);

        return redirect()->to("/operations/timesheets?edit={$timesheet->id}");
    }

    public function update(Request $request, Timesheet $timesheet)
    {
        $rootEntry = $this->commandReceipt()->begin($request);
        $auth = $request->user();
        abort_unless($auth, 403);
        $this->assertCanEditTimesheet($auth, $timesheet);

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
            // Resolve existence and Site access together below so missing and
            // inaccessible direct IDs have the same concealed response.
            'client_id' => ['nullable', 'integer'],
            'activity_type' => [
                'nullable', 'string',
                'in:training,meeting,admin,travel,handover,supervision,standby,other',
            ],
            'site_id' => ['nullable', 'integer'],
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

        // A linked shift remains authoritative. Manual reassignment must pass
        // the same canonical client-Site boundary as timesheet creation before
        // snapshot data is read or any timesheet side effect can occur.
        if (($data['client_id'] ?? null) !== null) {
            $this->siteAccess()->assertCanAccessClientId(
                $auth,
                (int) $data['client_id'],
                [],
                'You are not authorized to assign that client to this timesheet.',
            );
        }

        $clearingManualClient = ! $linkedShift && ($data['client_id'] ?? null) === null;
        $manualActivityType = array_key_exists('activity_type', $data)
            ? $data['activity_type']
            : $timesheet->activity_type;
        $manualSiteId = array_key_exists('site_id', $data)
            ? ($data['site_id'] !== null ? (int) $data['site_id'] : null)
            : ($timesheet->site_id !== null ? (int) $timesheet->site_id : null);

        if ($clearingManualClient && $manualSiteId !== null) {
            $this->siteAccess()->assertCanAccessSiteId(
                $auth,
                $manualSiteId,
                [],
                'You are not authorized to assign that site to this timesheet.',
            );
        }

        $snapshot = $clearingManualClient
            ? $this->manualSnapshot($timesheet->staff ?? $auth, $manualActivityType, $manualSiteId)
            : $this->draftSnapshot($data['client_id'], $linkedShift, $timesheet->staff ?? $auth, $data['notes'] ?? $timesheet->notes);
        $snapshotFallback = $clearingManualClient ? null : $timesheet;
        $canonicalSiteId = $linkedShift
            ? $timesheet->site_id
            : ($snapshot['site_id'] ?? $manualSiteId);

        $result = $this->timesheetApprovals()->updateEditable($timesheet, $auth, [
            'client_id' => $data['client_id'],
            'activity_type' => $clearingManualClient ? $manualActivityType : $timesheet->activity_type,
            'site_id' => $canonicalSiteId,
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
            'shift_site_id' => $snapshot['site_id'] ?? $snapshotFallback?->shift_site_id,
            'shift_service_context_id' => $snapshot['service_context_id'] ?? $snapshotFallback?->shift_service_context_id,
            'shift_site_name_snapshot' => $snapshot['site_name'] ?? $snapshotFallback?->shift_site_name_snapshot,
            'shift_location_snapshot' => $snapshot['location'] ?? $snapshotFallback?->shift_location_snapshot,
            'service_context_name_snapshot' => $snapshot['service_context_name'] ?? $snapshotFallback?->service_context_name_snapshot,
            'client_name_snapshot' => $snapshot['client_name'] ?? $snapshotFallback?->client_name_snapshot,
            'staff_name_snapshot' => $snapshot['staff_name'] ?? $snapshotFallback?->staff_name_snapshot,
            'shift_type_snapshot' => $snapshot['shift_type'] ?? $snapshotFallback?->shift_type_snapshot ?? 'standard',
            'coverage_roles_snapshot' => $snapshot['coverage_roles'] ?? $snapshotFallback?->coverage_roles_snapshot ?? [],
        ]);

        $receipt = $this->commandReceipt()->committed($rootEntry, 'update', $result);
        $timesheet = $result->timesheet;

        $this->notifyTimesheetAfterCommit($auth, 'updated', $timesheet, [
            'event_key' => 'timesheets.updated',
            'title' => 'Timesheet updated',
            'url' => url("/operations/timesheets/{$timesheet->id}/edit"),
            'target_user_ids' => [$timesheet->user_id],
        ]);

        return $this->withTimesheetReceipt(redirect()->back()->with('success', 'Timesheet updated.'), $receipt);
    }

    public function submit(Request $request, Timesheet $timesheet)
    {
        $rootEntry = $this->commandReceipt()->begin($request);
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('timesheets.submit'), 403);

        // Ownership check
        if (! $auth->canDo('timesheets.manageAny') && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        $this->assertCanMutateTimesheet($auth, $timesheet);

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
        $receipt = $this->commandReceipt()->committed($rootEntry, 'submit', $result);
        $submittedTimesheet = $result->timesheet;

        $this->notifyTimesheetAfterCommit($auth, 'submitted', $submittedTimesheet, [
            'event_key' => 'timesheets.submitted',
            'title' => 'Timesheet submitted for approval',
            'url' => url("/operations/timesheets/{$submittedTimesheet->id}/edit"),
            'include_entity_user' => false,
        ]);

        return $this->withTimesheetReceipt(redirect()->back()->with('success', 'Timesheet submitted.'), $receipt);
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
        $rootEntry = $this->commandReceipt()->begin($request);
        $auth = $request->user();
        abort_unless(
            $auth && $auth->canDo('timesheets.update') && $auth->canDo('timesheets.submit'),
            403,
        );

        if (! $auth->canDo('timesheets.manageAny') && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        $this->assertCanMutateTimesheet($auth, $timesheet);

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
            // Resolve existence and Site access together below so missing and
            // inaccessible direct IDs have the same concealed response.
            'client_id' => ['nullable', 'integer'],
            'activity_type' => [
                'nullable', 'string',
                'in:training,meeting,admin,travel,handover,supervision,standby,other',
            ],
            'site_id' => ['nullable', 'integer'],
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

        // Keep the client resolution ahead of snapshotting and the atomic
        // workflow command: denial must leave status, audit, payroll, billing,
        // notification, and allocation state untouched.
        if (($data['client_id'] ?? null) !== null) {
            $this->siteAccess()->assertCanAccessClientId(
                $auth,
                (int) $data['client_id'],
                [],
                'You are not authorized to assign that client to this timesheet.',
            );
        }

        $clearingManualClient = ! $linkedShift && ($data['client_id'] ?? null) === null;
        $manualActivityType = array_key_exists('activity_type', $data)
            ? $data['activity_type']
            : $timesheet->activity_type;
        $manualSiteId = array_key_exists('site_id', $data)
            ? ($data['site_id'] !== null ? (int) $data['site_id'] : null)
            : ($timesheet->site_id !== null ? (int) $timesheet->site_id : null);

        if ($clearingManualClient && $manualSiteId !== null) {
            $this->siteAccess()->assertCanAccessSiteId(
                $auth,
                $manualSiteId,
                [],
                'You are not authorized to assign that site to this timesheet.',
            );
        }

        $snapshot = $clearingManualClient
            ? $this->manualSnapshot($timesheet->staff ?? $auth, $manualActivityType, $manualSiteId)
            : $this->draftSnapshot(
                $data['client_id'],
                $linkedShift,
                $timesheet->staff ?? $auth,
                $data['notes'] ?? $timesheet->notes,
            );
        $snapshotFallback = $clearingManualClient ? null : $timesheet;
        $canonicalSiteId = $linkedShift
            ? $timesheet->site_id
            : ($snapshot['site_id'] ?? $manualSiteId);

        $result = $this->timesheetApprovals()->resubmit($timesheet, $auth, [
            'client_id' => $data['client_id'],
            'activity_type' => $clearingManualClient ? $manualActivityType : $timesheet->activity_type,
            'site_id' => $canonicalSiteId,
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
            'shift_site_id' => $snapshot['site_id'] ?? $snapshotFallback?->shift_site_id,
            'shift_service_context_id' => $snapshot['service_context_id'] ?? $snapshotFallback?->shift_service_context_id,
            'shift_site_name_snapshot' => $snapshot['site_name'] ?? $snapshotFallback?->shift_site_name_snapshot,
            'shift_location_snapshot' => $snapshot['location'] ?? $snapshotFallback?->shift_location_snapshot,
            'service_context_name_snapshot' => $snapshot['service_context_name'] ?? $snapshotFallback?->service_context_name_snapshot,
            'client_name_snapshot' => $snapshot['client_name'] ?? $snapshotFallback?->client_name_snapshot,
            'staff_name_snapshot' => $snapshot['staff_name'] ?? $snapshotFallback?->staff_name_snapshot,
            'shift_type_snapshot' => $snapshot['shift_type'] ?? $snapshotFallback?->shift_type_snapshot ?? 'standard',
            'coverage_roles_snapshot' => $snapshot['coverage_roles'] ?? $snapshotFallback?->coverage_roles_snapshot ?? [],
        ]);
        $receipt = $this->commandReceipt()->committed($rootEntry, 'resubmit', $result);
        $submittedTimesheet = $result->timesheet;

        $this->notifyTimesheetAfterCommit($auth, 'submitted', $submittedTimesheet, [
            'event_key' => 'timesheets.submitted',
            'title' => 'Timesheet updated and resubmitted',
            'url' => url("/operations/timesheets/{$submittedTimesheet->id}/edit"),
            'include_entity_user' => false,
        ]);

        return $this->withTimesheetReceipt(redirect()->back()->with('success', 'Timesheet updated and resubmitted.'), $receipt);
    }

    public function approve(Request $request, Timesheet $timesheet)
    {
        $rootEntry = $this->commandReceipt()->begin($request);
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);
        $this->assertCanMutateTimesheet($auth, $timesheet);

        $data = $request->validate([
            'decision_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $result = $this->timesheetApprovals()
                ->approve($timesheet, $auth, $data['decision_notes'] ?? null);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        $receipt = $this->commandReceipt()->committed($rootEntry, 'approve', $result);
        /** @var Timesheet $approvedTimesheet */
        $approvedTimesheet = $result->timesheet;

        if (! $result->changed) {
            return $this->withTimesheetReceipt(redirect()->back()->with('success', 'Timesheet already approved.'), $receipt);
        }

        $this->notifyTimesheetAfterCommit($auth, 'approved', $approvedTimesheet, [
            'event_key' => 'timesheets.approved',
            'title' => 'Timesheet approved',
            'url' => url("/operations/timesheets/{$approvedTimesheet->id}/edit"),
        ]);

        return $this->withTimesheetReceipt(redirect()->back()->with('success', 'Timesheet approved.'), $receipt);
    }

    public function reject(Request $request, Timesheet $timesheet)
    {
        $rootEntry = $this->commandReceipt()->begin($request);
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);
        abort_unless($timesheet->status === 'submitted', 403);
        $this->assertCanMutateTimesheet($auth, $timesheet);

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
        $receipt = $this->commandReceipt()->committed($rootEntry, 'reject', $result);
        $rejectedTimesheet = $result->timesheet;

        if ($result->changed) {
            $this->notifyTimesheetAfterCommit($auth, 'rejected', $rejectedTimesheet, [
                'event_key' => 'timesheets.rejected',
                'title' => 'Timesheet rejected',
                'url' => url("/operations/timesheets/{$rejectedTimesheet->id}/edit"),
            ]);
        }

        return $this->withTimesheetReceipt(redirect()->back()->with('success', 'Timesheet rejected.'), $receipt);
    }

    public function returnForChanges(Request $request, Timesheet $timesheet)
    {
        $rootEntry = $this->commandReceipt()->begin($request);
        $auth = $request->user();
        abort_unless($this->canReviewTimesheets($auth), 403);
        abort_unless($timesheet->status === 'submitted', 403);
        $this->assertCanMutateTimesheet($auth, $timesheet);

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
        $receipt = $this->commandReceipt()->committed($rootEntry, 'return', $result);
        $returnedTimesheet = $result->timesheet;

        if ($result->changed) {
            $this->notifyTimesheetAfterCommit($auth, 'returned', $returnedTimesheet, [
                'event_key' => 'timesheets.returned',
                'title' => 'Timesheet returned for changes',
                'url' => url("/operations/timesheets/{$returnedTimesheet->id}/edit"),
            ]);
        }

        return $this->withTimesheetReceipt(redirect()->back()->with('success', 'Timesheet returned for changes.'), $receipt);
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

        AuditLogger::log('timesheet.amendment.payroll_processed', $amendment->timesheet, [
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
    protected function timesheetReadBypassPermissions(): array
    {
        return ['reports.viewAny'];
    }

    protected function assertCanMutateTimesheet(User $auth, Timesheet $timesheet): void
    {
        $this->siteAccess()->assertCanAccessTimesheet(
            $auth,
            $timesheet,
            [],
            'You are not authorized to access timesheets for this site.',
        );
    }

    protected function assertCanViewTimesheet(User $auth, Timesheet $timesheet): void
    {
        abort_unless($auth->canDo('timesheets.viewAny') || $auth->canDo('timesheets.viewAssigned'), 403);

        if (! $auth->canDo('timesheets.manageAny') && ! $this->canReviewTimesheets($auth) && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        $this->siteAccess()->assertCanAccessTimesheet(
            $auth,
            $timesheet,
            $this->timesheetReadBypassPermissions(),
            'You are not authorized to access timesheets for this site.',
        );
    }

    protected function assertCanEditTimesheet(User $auth, Timesheet $timesheet): void
    {
        abort_unless($auth->canDo('timesheets.update'), 403);

        if (! $auth->canDo('timesheets.manageAny') && $timesheet->user_id !== $auth->id) {
            abort(403);
        }

        $this->assertCanMutateTimesheet($auth, $timesheet);
    }

    protected function canMutateTimesheet(User $auth, Timesheet $timesheet): bool
    {
        $query = Timesheet::query()->whereKey($timesheet->id);
        $this->siteAccess()->applyTimesheetScope($query, $auth, []);

        return $query->exists();
    }

    private function commandReceipt(): TimesheetCommandReceipt
    {
        return app(TimesheetCommandReceipt::class);
    }

    private function withTimesheetReceipt($response, ?array $receipt)
    {
        if ($receipt !== null) {
            $response->with('timesheet_result', $receipt);
        }

        return $response;
    }

    private function notifyTimesheetAfterCommit(User $actor, string $action, Timesheet $timesheet, array $options): void
    {
        $model = clone $timesheet;
        DB::afterCommit(function () use ($actor, $action, $model, $options): void {
            try {
                app(NotificationService::class)->notifyCrud($actor, $action, 'timesheet', $model, $model->shift?->client ?? $model->client, $options);
            } catch (\Throwable $exception) {
                try {
                    Log::warning('Timesheet notification failed after commit', ['timesheet_id' => $model->id, 'action' => $action, 'exception_class' => $exception::class]);
                } catch (\Throwable) {
                }
            }
        });
    }
}
