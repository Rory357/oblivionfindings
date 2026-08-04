<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrCompetency;
use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrGoalCycle;
use App\Domain\Hr\Models\HrGoalTemplate;
use App\Domain\Hr\Models\HrKeyResult;
use App\Domain\Hr\Notifications\GoalAssignedNotification;
use App\Domain\Hr\Services\CycleService;
use App\Domain\Hr\Services\GoalService;
use App\Domain\Hr\Services\HrGoalAccessService;
use App\Domain\Hr\Services\HrNotificationService;
use App\Domain\Hr\Services\HrPerformanceAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GoalController extends Controller
{
    public function __construct(
        protected GoalService $goalService,
        protected CycleService $cycleService,
        protected HrNotificationService $notificationService,
        private readonly HrPerformanceAccessService $performanceAccess,
        private readonly HrGoalAccessService $goalAccess,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — Dashboard + List */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $this->viewer($request);

        // Resolve cycle lens. ?cycle=all shows every cycle; a numeric id scopes
        // to that cycle; default is the current cycle (or all if it's empty).
        $cycles = $this->cycleService->cycles();
        $current = $this->cycleService->currentCycle();
        $cycleParam = $request->query('cycle');

        $selectedCycleId = null; // null === "all"
        if ($cycleParam === 'all') {
            $selectedCycleId = null;
        } elseif (is_numeric($cycleParam) && $cycles->contains('id', (int) $cycleParam)) {
            $selectedCycleId = (int) $cycleParam;
        } elseif ($current) {
            $hasGoals = $this->goalAccess
                ->applyHistoricalGoalScope(HrGoal::query(), $user)
                ->where('cycle_id', $current->id)
                ->exists();
            $selectedCycleId = $hasGoals ? $current->id : null;
        }

        $objectives = $this->goalAccess
            ->applyHistoricalGoalScope(HrGoal::query(), $user)
            ->with([
                'user:id,name',
                'parentGoal' => fn ($parentQuery) => $this->goalAccess
                    ->applyHistoricalGoalScope($parentQuery, $user)
                    ->select(['id', 'title', 'user_id']),
                'cycle:id,name',
                'keyResults' => fn ($keyResultQuery) => $keyResultQuery->with([
                    'owner' => fn ($ownerQuery) => $ownerQuery
                        ->whereIn('users.id', $this->goalAccess->historicalStaffQuery($user))
                        ->select(['users.id', 'users.name']),
                ]),
            ])
            ->withCount([
                'developmentGoals' => fn ($developmentQuery) => $developmentQuery->whereIn(
                    $developmentQuery->qualifyColumn('employee_user_id'),
                    $this->performanceAccess->historicalUserIds($user),
                ),
            ])
            ->forCycle($selectedCycleId)
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (HrGoal $g) => $this->mapObjective($g))
            ->values();

        $users = $this->goalAccess->currentStaffQuery($user)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name']);

        $developmentPlans = $this->performanceAccess
            ->applyHistoricalSubjectScope(HrDevelopmentGoal::query(), $user)
            ->with(['employee:id,name', 'manager:id,name', 'goal:id,title', 'competency:id,name'])
            ->orderByRaw("CASE WHEN status = 'blocked' THEN 0 WHEN status = 'in_progress' THEN 1 WHEN status = 'not_started' THEN 2 ELSE 3 END")
            ->orderBy('due_date')
            ->get()
            ->map(fn (HrDevelopmentGoal $d) => $this->mapDevelopmentPlan($d))
            ->values();

        $analytics = $this->goalService->getGoalAnalytics($user, $selectedCycleId);
        $cascadeTree = $this->goalService->getCompanyGoalTree($user, $selectedCycleId);

        return Inertia::render('hr/goals/index', [
            'objectives' => $objectives,
            'developmentPlans' => $developmentPlans,
            'users' => $users,
            'goalTypes' => $this->goalTypeOptions(),
            'priorities' => $this->priorityOptions(),
            'parentGoals' => $this->parentGoalOptions($user),
            'cycles' => $cycles->sortBy('starts_at')->values()->map(fn (HrGoalCycle $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
                'status' => $c->status,
                'starts_at' => $c->starts_at?->toDateString(),
                'ends_at' => $c->ends_at?->toDateString(),
                'meta' => $this->cycleMeta($c),
            ]),
            'selectedCycleId' => $selectedCycleId,
            'currentCycleId' => $current?->id,
            'analytics' => $analytics,
            'cascadeTree' => $cascadeTree,
            'devCategories' => $this->devCategoryOptions(),
            'templates' => HrGoalTemplate::query()->active()->orderBy('name')->get()
                ->map(fn (HrGoalTemplate $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'title' => $t->title,
                    'description' => $t->description,
                    'goal_type' => $t->goal_type,
                    'category' => $t->category,
                    'priority' => $t->priority,
                    'key_results' => $t->key_results ?? [],
                ]),
            'allTags' => $this->goalAccess
                ->applyHistoricalGoalScope(HrGoal::query(), $user)
                ->whereNotNull('tags')
                ->pluck('tags')
                ->flatMap(fn ($t) => is_array($t) ? $t : [])->unique()->sort()->values(),
            'competencies' => HrCompetency::query()
                ->orderBy('name')->get(['id', 'name'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]),
            'can' => [
                'manage' => $this->canManage($user),
            ],
            'defaultTab' => $request->query('tab'),
        ]);
    }

    /** Shape an objective for the hub (rows, board, alignment, wizards). */
    private function mapObjective(HrGoal $g): array
    {
        return [
            'id' => $g->id,
            'title' => $g->title,
            'description' => $g->description,
            'goal_type' => $g->goal_type,
            'category' => $g->category,
            'tags' => $g->tags ?? [],
            'status' => $g->status,
            'confidence' => $g->confidence ?? 'on_track',
            'priority' => $g->priority,
            'progress_percentage' => (int) $g->progress_percentage,
            'target_value' => $g->target_value !== null ? (float) $g->target_value : null,
            'current_value' => $g->current_value !== null ? (float) $g->current_value : null,
            'unit' => $g->unit,
            'start_date' => $g->start_date?->toDateString(),
            'due_date' => $g->due_date?->toDateString(),
            'checkin_frequency' => $g->checkin_frequency,
            'last_checkin_at' => $g->last_checkin_at?->toDateString(),
            'last_checkin_days' => $g->last_checkin_at ? (int) $g->last_checkin_at->diffInDays(now()) : null,
            'user' => $g->user ? ['id' => $g->user->id, 'name' => $g->user->name] : null,
            'parent_goal_id' => $g->parent_goal_id,
            'parent_goal' => $g->parentGoal ? ['id' => $g->parentGoal->id, 'title' => $g->parentGoal->title] : null,
            'cycle' => $g->cycle ? ['id' => $g->cycle->id, 'name' => $g->cycle->name] : null,
            'cycle_id' => $g->cycle_id,
            'key_results_count' => $g->keyResults->count(),
            'development_count' => (int) ($g->development_goals_count ?? 0),
            'key_results' => $g->keyResults->map(fn (HrKeyResult $kr) => [
                'id' => $kr->id,
                'title' => $kr->title,
                'kr_type' => $kr->kr_type ?? 'number',
                'start_value' => (float) $kr->start_value,
                'current_value' => (float) $kr->current_value,
                'target_value' => (float) $kr->target_value,
                'unit' => $kr->unit,
                'weight' => (int) ($kr->weight ?? 1),
                'progress_percentage' => (int) $kr->progress_percentage,
                'status' => $kr->status,
                'confidence' => $kr->confidence ?? 'on_track',
                'owner' => $kr->owner ? ['id' => $kr->owner->id, 'name' => $kr->owner->name] : null,
            ])->values(),
        ];
    }

    private function mapDevelopmentPlan(HrDevelopmentGoal $d): array
    {
        return [
            'id' => $d->id,
            'title' => $d->title,
            'competency_area' => $d->competency_area,
            'category' => $d->category,
            'status' => $d->status,
            'progress_percent' => (int) $d->progress_percent,
            'current_level' => $d->current_level,
            'target_level' => $d->target_level,
            'review_frequency' => $d->review_frequency,
            'due_date' => $d->due_date?->toDateString(),
            'next_review_at' => $d->next_review_at?->toDateString(),
            'competency_id' => $d->competency_id,
            'competency' => $d->competency ? ['id' => $d->competency->id, 'name' => $d->competency->name] : null,
            'employee' => $d->employee ? ['id' => $d->employee->id, 'name' => $d->employee->name] : null,
            'manager' => $d->manager ? ['id' => $d->manager->id, 'name' => $d->manager->name] : null,
            'hr_goal_id' => $d->hr_goal_id,
            'linked_objective' => $d->goal ? ['id' => $d->goal->id, 'title' => $d->goal->title] : null,
        ];
    }

    private function cycleMeta(HrGoalCycle $c): string
    {
        $start = $c->starts_at?->format('j M');
        $end = $c->ends_at?->format('j M Y');

        return trim("{$start} – {$end}");
    }

    private function devCategoryOptions(): array
    {
        return [
            ['value' => 'growth', 'label' => 'Growth'],
            ['value' => 'performance', 'label' => 'Performance'],
            ['value' => 'leadership', 'label' => 'Leadership'],
            ['value' => 'compliance', 'label' => 'Compliance'],
            ['value' => 'capability', 'label' => 'Capability'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Create */
    /* ------------------------------------------------------------------ */

    /**
     * The page-based create form was replaced by the GoalDialog on the goals hub.
     * Preserve the route with a redirect.
     */
    public function create(Request $request)
    {
        $this->manager($request);

        return redirect()->route('hr.goals.index');
    }

    /** Goal-type options for the dialog. */
    private function goalTypeOptions(): array
    {
        return [
            ['value' => 'company', 'label' => 'Company'],
            ['value' => 'team', 'label' => 'Team'],
            ['value' => 'individual', 'label' => 'Individual'],
        ];
    }

    /** Priority options for the dialog. */
    private function priorityOptions(): array
    {
        return [
            ['value' => 'low', 'label' => 'Low'],
            ['value' => 'medium', 'label' => 'Medium'],
            ['value' => 'high', 'label' => 'High'],
        ];
    }

    /** Active/draft goals selectable as a parent in the dialog. */
    private function parentGoalOptions(User $viewer, ?int $excludeGoalId = null)
    {
        return $this->goalAccess
            ->applyCurrentGoalScope(HrGoal::query(), $viewer)
            ->when($excludeGoalId, fn (Builder $query) => $query->where(
                $query->qualifyColumn('id'),
                '!=',
                $excludeGoalId,
            ))
            ->where(fn ($q) => $q->where('status', 'active')->orWhere('status', 'draft'))
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn ($g) => ['id' => $g->id, 'title' => $g->title])
            ->values();
    }

    /* ------------------------------------------------------------------ */
    /*  Store */
    /* ------------------------------------------------------------------ */

    public function store(Request $request)
    {
        $user = $this->manager($request);

        $data = $request->validate([
            'user_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'goal_type' => ['required', 'string', 'in:individual,team,company'],
            'category' => ['nullable', 'string', 'max:255'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:40'],
            'parent_goal_id' => ['nullable', 'integer'],
            'cycle_id' => ['nullable', 'integer'],
            'target_value' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'priority' => ['required', 'string', 'in:low,medium,high'],
            'confidence' => ['sometimes', 'string', 'in:on_track,at_risk,off_track'],
            'checkin_frequency' => ['sometimes', 'string', 'in:weekly,fortnightly,monthly,quarterly'],
            'start_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', 'string', 'in:draft,active'],
            'key_results' => ['sometimes', 'array'],
            'key_results.*.title' => ['required_with:key_results', 'string', 'max:500'],
            'key_results.*.kr_type' => ['nullable', 'string', 'in:number,percent,currency,milestone,boolean'],
            'key_results.*.start_value' => ['nullable', 'numeric'],
            'key_results.*.target_value' => ['nullable', 'numeric'],
            'key_results.*.unit' => ['nullable', 'string', 'max:50'],
            'key_results.*.weight' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        $keyResults = $data['key_results'] ?? [];
        unset($data['key_results']);
        $owner = $this->goalAccess->currentStaff($user, (int) $data['user_id']);
        $parent = isset($data['parent_goal_id'])
            ? $this->goalAccess->currentGoal($user, (int) $data['parent_goal_id'])
            : null;
        $cycle = isset($data['cycle_id'])
            ? $this->cycle((int) $data['cycle_id'])
            : null;

        $goal = DB::transaction(function () use ($data, $keyResults, $owner, $parent, $cycle, $user) {
            $goal = $this->goalService->createGoal([
                ...$data,
                'user_id' => $owner->id,
                'parent_goal_id' => $parent?->id,
                'cycle_id' => $cycle?->id,
                'created_by' => $user->id,
            ]);

            foreach ($keyResults as $kr) {
                if (trim((string) ($kr['title'] ?? '')) === '') {
                    continue;
                }

                $created = HrKeyResult::create([
                    'goal_id' => $goal->id,
                    'title' => $kr['title'],
                    'kr_type' => $kr['kr_type'] ?? 'number',
                    'start_value' => $kr['start_value'] ?? 0,
                    'current_value' => $kr['start_value'] ?? 0,
                    'target_value' => $kr['target_value'] ?? 100,
                    'unit' => $kr['unit'] ?? null,
                    'weight' => $kr['weight'] ?? 1,
                    'status' => 'not_started',
                    'confidence' => $goal->confidence ?? 'on_track',
                    'due_date' => $goal->due_date,
                    'owner_id' => $goal->user_id,
                ]);
                $created->recalculateProgress();
                $created->save();
            }

            if ($keyResults !== []) {
                $this->goalService->recalculateGoalProgress($goal->fresh());
            }

            return $goal;
        });

        // Notify the owner the objective was assigned to them.
        if ($goal->user_id !== $user->id) {
            $owner->notify(new GoalAssignedNotification($goal));
        }

        if ($request->boolean('stay')) {
            return redirect()->back()->with('success', 'Objective created.');
        }

        return redirect("/hr/goals/{$goal->id}")->with('success', 'Objective created.');
    }

    /* ------------------------------------------------------------------ */
    /*  Show — Objective Detail */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrGoal $goal)
    {
        $user = $this->viewer($request);
        $goal = $this->goalAccess->historicalGoal($user, $goal);

        $goal->load([
            'user:id,name',
            'creator:id,name',
            'cycle:id,name',
            'parentGoal' => fn ($parentQuery) => $this->goalAccess
                ->applyHistoricalGoalScope($parentQuery, $user)
                ->select(['id', 'title', 'goal_type', 'user_id']),
            'childGoals' => fn ($childQuery) => $this->goalAccess
                ->applyHistoricalGoalScope($childQuery, $user)
                ->with('user:id,name', 'keyResults')
                ->orderBy('priority', 'desc'),
            'keyResults' => fn ($keyResultQuery) => $keyResultQuery->with([
                'owner' => fn ($ownerQuery) => $ownerQuery
                    ->whereIn('users.id', $this->goalAccess->historicalStaffQuery($user))
                    ->select(['users.id', 'users.name']),
            ]),
            'updates' => fn ($q) => $q->with('user:id,name')->orderByDesc('created_at')->limit(20),
            'developmentGoals' => fn ($developmentQuery) => $developmentQuery
                ->whereIn(
                    $developmentQuery->qualifyColumn('employee_user_id'),
                    $this->performanceAccess->historicalUserIds($user),
                )
                ->with('employee:id,name')
                ->orderByDesc('updated_at'),
        ]);

        $users = $this->goalAccess->currentStaffQuery($user)
            ->orderBy('name')
            ->limit(500)
            ->get(['id', 'name']);
        $isCurrentGoal = $this->goalAccess
            ->applyCurrentGoalScope(HrGoal::query(), $user)
            ->whereKey($goal->id)
            ->exists();

        return Inertia::render('hr/goals/show', [
            'goal' => [
                'id' => $goal->id,
                'title' => $goal->title,
                'description' => $goal->description,
                'goal_type' => $goal->goal_type,
                'category' => $goal->category,
                'tags' => $goal->tags ?? [],
                'status' => $goal->status,
                'confidence' => $goal->confidence ?? 'on_track',
                'priority' => $goal->priority,
                'checkin_frequency' => $goal->checkin_frequency,
                'progress_percentage' => $goal->progress_percentage,
                'target_value' => $goal->target_value,
                'current_value' => $goal->current_value,
                'unit' => $goal->unit,
                'start_date' => $goal->start_date?->toDateString(),
                'due_date' => $goal->due_date?->toDateString(),
                'completed_at' => $goal->completed_at?->toDateString(),
                'cycle' => $goal->cycle ? ['id' => $goal->cycle->id, 'name' => $goal->cycle->name] : null,
                'cycle_id' => $goal->cycle_id,
                'user' => $goal->user ? ['id' => $goal->user->id, 'name' => $goal->user->name] : null,
                'creator' => $goal->creator?->name,
                'parent_goal_id' => $goal->parent_goal_id,
                'parent_goal' => $goal->parentGoal ? ['id' => $goal->parentGoal->id, 'title' => $goal->parentGoal->title, 'goal_type' => $goal->parentGoal->goal_type] : null,
                'child_goals' => $goal->childGoals->map(fn ($c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'goal_type' => $c->goal_type,
                    'status' => $c->status,
                    'confidence' => $c->confidence ?? 'on_track',
                    'priority' => $c->priority,
                    'progress_percentage' => $c->progress_percentage,
                    'user' => $c->user ? ['name' => $c->user->name] : null,
                    'key_results_count' => $c->keyResults->count(),
                ])->values(),
                'key_results' => $goal->keyResults->map(fn ($kr) => [
                    'id' => $kr->id,
                    'title' => $kr->title,
                    'kr_type' => $kr->kr_type ?? 'number',
                    'start_value' => (float) $kr->start_value,
                    'target_value' => (float) $kr->target_value,
                    'current_value' => (float) $kr->current_value,
                    'unit' => $kr->unit,
                    'weight' => (int) ($kr->weight ?? 1),
                    'progress_percentage' => $kr->progress_percentage,
                    'status' => $kr->status,
                    'confidence' => $kr->confidence ?? 'on_track',
                    'due_date' => $kr->due_date?->toDateString(),
                    'owner' => $kr->owner ? ['id' => $kr->owner->id, 'name' => $kr->owner->name] : null,
                ])->values(),
                'updates' => $goal->updates->map(fn ($u) => [
                    'id' => $u->id,
                    'user_name' => $u->user?->name,
                    'previous_value' => $u->previous_value,
                    'new_value' => $u->new_value,
                    'progress_percentage' => $u->progress_percentage,
                    'confidence' => $u->confidence,
                    'comment' => $u->comment,
                    'created_at' => $u->created_at?->diffForHumans(),
                ])->values(),
                'development_goals' => $goal->developmentGoals->map(fn ($d) => [
                    'id' => $d->id,
                    'title' => $d->title,
                    'competency_area' => $d->competency_area,
                    'status' => $d->status,
                    'progress_percent' => (int) $d->progress_percent,
                    'current_level' => $d->current_level,
                    'target_level' => $d->target_level,
                    'employee' => $d->employee?->name,
                ])->values(),
            ],
            'users' => $users,
            'goalTypes' => $this->goalTypeOptions(),
            'priorities' => $this->priorityOptions(),
            'parentGoals' => $this->parentGoalOptions($user, $goal->id),
            'cycles' => $this->cycleService->cycles()
                ->sortBy('starts_at')
                ->values()
                ->map(fn (HrGoalCycle $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'type' => $c->type,
                    'status' => $c->status,
                    'starts_at' => $c->starts_at?->toDateString(),
                    'ends_at' => $c->ends_at?->toDateString(),
                ]),
            'can' => [
                'manage' => $this->canManage($user) && $isCurrentGoal,
                'updateProgress' => $isCurrentGoal
                    && ($this->canManage($user) || $goal->user_id === $user->id),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Update */
    /* ------------------------------------------------------------------ */

    public function update(Request $request, HrGoal $goal)
    {
        $user = $this->manager($request);
        $goal = $this->goalAccess->currentGoal($user, $goal);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'goal_type' => ['sometimes', 'string', 'in:individual,team,company'],
            'category' => ['nullable', 'string', 'max:255'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
            'parent_goal_id' => ['nullable', 'integer'],
            'cycle_id' => ['nullable', 'integer'],
            'target_value' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'priority' => ['sometimes', 'string', 'in:low,medium,high'],
            'confidence' => ['sometimes', 'string', 'in:on_track,at_risk,off_track'],
            'checkin_frequency' => ['sometimes', 'string', 'in:weekly,fortnightly,monthly,quarterly'],
            'start_date' => ['sometimes', 'date'],
            'due_date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string', 'in:draft,active,on_hold,blocked,completed,cancelled'],
        ]);

        $newParent = null;
        if (array_key_exists('parent_goal_id', $data) && $data['parent_goal_id'] !== null) {
            $newParent = $this->goalAccess->currentGoal($user, (int) $data['parent_goal_id']);
            abort_if($newParent->id === $goal->id, 422, 'An objective cannot be its own parent.');
            abort_if($this->isDescendant($user, $goal, $newParent->id), 422, 'Cannot move an objective under one of its own descendants.');
            $data['parent_goal_id'] = $newParent->id;
        }

        if (array_key_exists('cycle_id', $data) && $data['cycle_id'] !== null) {
            $data['cycle_id'] = $this->cycle((int) $data['cycle_id'])->id;
        }

        if (($data['status'] ?? null) === 'completed' && $goal->status !== 'completed') {
            $data['completed_at'] = now();
            $data['confidence'] = 'on_track';
        } elseif (isset($data['status']) && $data['status'] !== 'completed' && $goal->status === 'completed') {
            $data['completed_at'] = null;
        }

        $oldParentId = $goal->parent_goal_id;
        [$goal, $wasCompleted] = DB::transaction(function () use ($goal, $user, $data): array {
            $locked = $this->goalAccess
                ->applyCurrentGoalScope(HrGoal::query(), $user)
                ->lockForUpdate()
                ->findOrFail($goal->id);
            $wasCompleted = $locked->status === 'completed';
            $locked->update($data);

            return [$locked->fresh(), $wasCompleted];
        }, attempts: 1);

        if (array_key_exists('parent_goal_id', $data) && $oldParentId !== $goal->parent_goal_id) {
            $oldParent = $oldParentId
                ? $this->goalAccess->applyCurrentGoalScope(HrGoal::query(), $user)->find($oldParentId)
                : null;
            if ($oldParent) {
                $this->goalService->recalculateGoalProgress($oldParent);
            }
            if ($newParent) {
                $this->goalService->recalculateGoalProgress($newParent->fresh());
            }
        }

        $this->notifyCompletionTransition($goal, $wasCompleted);

        return redirect()->back()->with('success', 'Objective updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Destroy */
    /* ------------------------------------------------------------------ */

    public function destroy(Request $request, HrGoal $goal)
    {
        $user = $this->manager($request);
        $goal = $this->goalAccess->currentGoal($user, $goal);

        DB::transaction(function () use ($goal, $user): void {
            $this->goalAccess
                ->applyCurrentGoalScope(HrGoal::query(), $user)
                ->lockForUpdate()
                ->findOrFail($goal->id)
                ->delete();
        }, attempts: 1);

        return redirect()->route('hr.goals.index')->with('success', 'Objective deleted.');
    }

    /* ------------------------------------------------------------------ */
    /*  Update Progress */
    /* ------------------------------------------------------------------ */

    public function updateProgress(Request $request, HrGoal $goal)
    {
        $user = $this->goalActor($request);
        $goal = $this->goalForCheckin($user, $goal);

        $data = $request->validate([
            'current_value' => ['nullable', 'numeric', 'min:0'],
            'progress_percentage' => ['required', 'integer', 'min:0', 'max:100'],
            'confidence' => ['sometimes', 'string', 'in:on_track,at_risk,off_track'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        [$goal, $wasCompleted] = DB::transaction(function () use ($goal, $user, $data): array {
            $locked = $this->lockGoalForCheckin($user, $goal);

            // An objective with KRs derives its percentage from them. A manual
            // slider must never clobber the weighted roll-up.
            abort_if($locked->hasKeyResults(), 422, 'This objective derives progress from its key results. Check in on the key results instead.');

            $wasCompleted = $locked->status === 'completed';
            $this->goalService->updateProgress($locked, [
                'user_id' => $user->id,
                ...$data,
            ]);

            return [$locked->fresh(), $wasCompleted];
        }, attempts: 1);
        $this->notifyCompletionTransition($goal, $wasCompleted);

        return redirect()->back()->with('success', 'Progress updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Check-in — the unified wizard endpoint */
    /* ------------------------------------------------------------------ */

    /**
     * One check-in covers either KR values (objectives with KRs) or a manual %
     * (objectives without), plus an overall confidence and comment. Mirrors the
     * check-in wizard so /hr/my and the hub share the same flow.
     */
    public function checkin(Request $request, HrGoal $goal)
    {
        $user = $this->goalActor($request);
        $goal = $this->goalForCheckin($user, $goal);

        $data = $request->validate([
            'confidence' => ['required', 'string', 'in:on_track,at_risk,off_track'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'manual_progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'key_results' => ['sometimes', 'array'],
            'key_results.*.id' => ['required_with:key_results', 'integer', 'distinct'],
            'key_results.*.current_value' => ['required_with:key_results', 'numeric'],
            'key_results.*.confidence' => ['nullable', 'string', 'in:on_track,at_risk,off_track'],
        ]);

        [$goal, $wasCompleted] = DB::transaction(function () use ($goal, $data, $user): array {
            $locked = $this->lockGoalForCheckin($user, $goal);
            $keyResults = $locked->keyResults()->lockForUpdate()->get();
            $hasKeyResults = $keyResults->isNotEmpty();
            $submittedKeyResults = $data['key_results'] ?? [];

            abort_if(! $hasKeyResults && $submittedKeyResults !== [], 422, 'This objective does not have key results.');

            $wasCompleted = $locked->status === 'completed';
            if ($hasKeyResults && $submittedKeyResults !== []) {
                $byId = $keyResults->keyBy('id');
                abort_unless(
                    collect($submittedKeyResults)->every(fn (array $row) => $byId->has((int) $row['id'])),
                    422,
                    'One or more key results do not belong to this objective.',
                );

                foreach ($submittedKeyResults as $row) {
                    $kr = $byId->get((int) $row['id']);
                    $this->goalService->updateKeyResultProgress($kr, [
                        'current_value' => $row['current_value'],
                        'confidence' => $row['confidence'] ?? $data['confidence'],
                        'comment' => $data['comment'] ?? null,
                    ], $user->id);
                }
                // Recalc gives the weighted roll-up; log a goal-level note with confidence.
                $locked->refresh();
                $this->goalService->updateProgress($locked, [
                    'user_id' => $user->id,
                    'progress_percentage' => $locked->progress_percentage,
                    'confidence' => $data['confidence'],
                    'comment' => $data['comment'] ?? null,
                ]);
            } else {
                $this->goalService->updateProgress($locked, [
                    'user_id' => $user->id,
                    'progress_percentage' => $hasKeyResults
                        ? $locked->progress_percentage
                        : ($data['manual_progress'] ?? $locked->progress_percentage),
                    'confidence' => $data['confidence'],
                    'comment' => $data['comment'] ?? null,
                ]);
            }

            return [$locked->fresh(), $wasCompleted];
        }, attempts: 1);
        $this->notifyCompletionTransition($goal, $wasCompleted);

        return redirect()->back()->with('success', 'Check-in logged.');
    }

    /* ================================================================== */
    /*  KEY RESULTS CRUD */
    /* ================================================================== */

    public function storeKeyResult(Request $request, HrGoal $goal)
    {
        $user = $this->manager($request);
        $goal = $this->goalAccess->currentGoal($user, $goal);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'kr_type' => ['nullable', 'string', 'in:number,percent,currency,milestone,boolean'],
            'start_value' => ['nullable', 'numeric'],
            'target_value' => ['required', 'numeric'],
            'unit' => ['nullable', 'string', 'max:50'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:10'],
            'due_date' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'integer'],
        ]);
        $owner = isset($data['owner_id'])
            ? $this->goalAccess->currentStaff($user, (int) $data['owner_id'])
            : $this->goalAccess->currentStaff($user, (int) $goal->user_id);

        DB::transaction(function () use ($goal, $user, $data, $owner): void {
            $lockedGoal = $this->goalAccess
                ->applyCurrentGoalScope(HrGoal::query(), $user)
                ->lockForUpdate()
                ->findOrFail($goal->id);
            $keyResult = HrKeyResult::query()->create([
                'goal_id' => $lockedGoal->id,
                'title' => $data['title'],
                'kr_type' => $data['kr_type'] ?? 'number',
                'start_value' => $data['start_value'] ?? 0,
                'current_value' => $data['start_value'] ?? 0,
                'target_value' => $data['target_value'],
                'unit' => $data['unit'] ?? null,
                'weight' => $data['weight'] ?? 1,
                'due_date' => $data['due_date'] ?? $lockedGoal->due_date,
                'owner_id' => $owner->id,
                'status' => 'not_started',
                'confidence' => $lockedGoal->confidence ?? 'on_track',
            ]);
            $keyResult->recalculateProgress();
            $keyResult->save();

            // The first KR flips the objective from manual to derived.
            $this->goalService->recalculateGoalProgress($lockedGoal->fresh());
        }, attempts: 1);

        return redirect()->back()->with('success', 'Key result added.');
    }

    public function updateKeyResult(Request $request, HrKeyResult $keyResult)
    {
        $user = $this->manager($request);
        $keyResult = $this->goalAccess->currentKeyResult($user, $keyResult);

        $data = $request->validate([
            'current_value' => ['sometimes', 'numeric'],
            'title' => ['sometimes', 'string', 'max:500'],
            'kr_type' => ['sometimes', 'string', 'in:number,percent,currency,milestone,boolean'],
            'start_value' => ['sometimes', 'numeric'],
            'target_value' => ['sometimes', 'numeric'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:50'],
            'weight' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'confidence' => ['sometimes', 'string', 'in:on_track,at_risk,off_track'],
            'status' => ['sometimes', 'string', Rule::in(['not_started', 'in_progress', 'completed', 'cancelled'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        [$goal, $wasCompleted] = DB::transaction(function () use ($keyResult, $user, $data): array {
            $locked = $this->goalAccess
                ->applyCurrentKeyResultScope(HrKeyResult::query(), $user)
                ->lockForUpdate()
                ->findOrFail($keyResult->id);

            // Static attributes update directly. Value and confidence flow
            // through the service so progress and first-class history agree.
            $static = array_intersect_key($data, array_flip(['title', 'kr_type', 'start_value', 'target_value', 'unit', 'weight']));
            if ($static !== []) {
                $locked->fill($static);
                $locked->save();
            }

            $goal = $locked->goal()->lockForUpdate()->firstOrFail();
            $wasCompleted = $goal->status === 'completed';
            $this->goalService->updateKeyResultProgress($locked, $data, $user->id);

            return [$goal->fresh(), $wasCompleted];
        }, attempts: 1);
        $this->notifyCompletionTransition($goal, $wasCompleted);

        return redirect()->back()->with('success', 'Key result updated.');
    }

    private function notifyCompletionTransition(HrGoal $goal, bool $wasCompleted): void
    {
        $goal->refresh();

        if (! $wasCompleted && $goal->status === 'completed') {
            $this->notificationService->notifyGoalCompleted($goal);
        }
    }

    public function destroyKeyResult(Request $request, HrKeyResult $keyResult)
    {
        $user = $this->manager($request);
        $keyResult = $this->goalAccess->currentKeyResult($user, $keyResult);

        DB::transaction(function () use ($keyResult, $user): void {
            $locked = $this->goalAccess
                ->applyCurrentKeyResultScope(HrKeyResult::query(), $user)
                ->lockForUpdate()
                ->findOrFail($keyResult->id);
            $goal = $locked->goal()->lockForUpdate()->firstOrFail();
            $locked->delete();
            $this->goalService->recalculateGoalProgress($goal);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Key result removed.');
    }

    /* ================================================================== */
    /*  Bulk · Duplicate · Re-parent · Export */
    /* ================================================================== */

    /** Back the multi-select bar on the objectives table. */
    public function bulk(Request $request)
    {
        $user = $this->manager($request);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:archive,request_checkin,reassign_owner,recycle'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
            'owner_id' => ['required_if:action,reassign_owner', 'nullable', 'integer'],
            'cycle_id' => ['nullable', 'integer'],
        ]);

        $goalIds = collect($data['ids'])->map(fn ($id) => (int) $id)->values();
        $goals = $this->goalAccess
            ->applyCurrentGoalScope(HrGoal::query(), $user)
            ->whereKey($goalIds->all())
            ->get();
        abort_unless($goals->count() === $goalIds->count(), 404);

        if ($data['action'] === 'request_checkin') {
            foreach ($goals as $goal) {
                $this->goalAccess
                    ->currentStaff($user, (int) $goal->user_id)
                    ->notify(new GoalAssignedNotification($goal, checkinReminder: true));
            }

            $count = $goals->count();
        } elseif ($data['action'] === 'recycle') {
            $target = isset($data['cycle_id'])
                ? $this->cycle((int) $data['cycle_id'])
                : $this->cycleService->currentCycle();
            abort_unless($target, 422, 'No target cycle is available.');
            $count = $this->cycleService->rollover($user, $target, $goals, true);
        } else {
            $newOwner = $data['action'] === 'reassign_owner'
                ? $this->goalAccess->currentStaff($user, (int) $data['owner_id'])
                : null;

            $count = DB::transaction(function () use ($goalIds, $user, $data, $newOwner): int {
                $lockedGoals = $this->goalAccess
                    ->applyCurrentGoalScope(HrGoal::query(), $user)
                    ->whereKey($goalIds->all())
                    ->lockForUpdate()
                    ->get();
                abort_unless($lockedGoals->count() === $goalIds->count(), 404);

                foreach ($lockedGoals as $goal) {
                    if ($data['action'] === 'archive') {
                        $goal->update(['status' => 'cancelled']);

                        continue;
                    }

                    $previousOwnerId = $goal->user_id;
                    $goal->update(['user_id' => $newOwner->id]);
                    $goal->keyResults()
                        ->where('owner_id', $previousOwnerId)
                        ->update(['owner_id' => $newOwner->id]);
                }

                return $lockedGoals->count();
            }, attempts: 1);
        }

        return redirect()->back()->with('success', "{$count} objective(s) updated.");
    }

    /** Duplicate an objective (optionally with KRs) into a chosen cycle. */
    public function duplicate(Request $request, HrGoal $goal)
    {
        $user = $this->manager($request);
        $goal = $this->goalAccess->currentGoal($user, $goal);

        $data = $request->validate([
            'cycle_id' => ['nullable', 'integer'],
            'with_key_results' => ['sometimes', 'boolean'],
        ]);

        $target = isset($data['cycle_id'])
            ? $this->cycle((int) $data['cycle_id'])
            : ($goal->cycle ?? $this->cycleService->currentCycle());
        if ($goal->parent_goal_id) {
            $this->goalAccess->currentGoal($user, (int) $goal->parent_goal_id);
        }

        $withKrs = $data['with_key_results'] ?? true;

        DB::transaction(function () use ($goal, $target, $withKrs, $user): void {
            $lockedGoal = $this->goalAccess
                ->applyCurrentGoalScope(HrGoal::query(), $user)
                ->lockForUpdate()
                ->findOrFail($goal->id);
            $keyResults = $withKrs
                ? $lockedGoal->keyResults()->lockForUpdate()->get()
                : collect();
            $lockedTarget = $target
                ? HrGoalCycle::query()->lockForUpdate()->findOrFail($target->id)
                : null;

            $clone = $lockedGoal->replicateForApplication([
                'progress_percentage',
                'completed_at',
                'last_checkin_at',
            ]);
            $clone->title = $lockedGoal->title.' (copy)';
            $clone->status = 'draft';
            $clone->confidence = 'on_track';
            $clone->progress_percentage = 0;
            $clone->completed_at = null;
            $clone->last_checkin_at = null;
            if ($lockedTarget) {
                $clone->cycle_id = $lockedTarget->id;
                $clone->start_date = $lockedTarget->starts_at;
                $clone->due_date = $lockedTarget->ends_at;
            }
            $clone->save();

            foreach ($keyResults as $keyResult) {
                $keyResultClone = $keyResult->replicateForApplication([
                    'current_value',
                    'progress_percentage',
                    'status',
                ]);
                $keyResultClone->goal_id = $clone->id;
                $keyResultClone->current_value = $keyResult->start_value;
                $keyResultClone->progress_percentage = 0;
                $keyResultClone->status = 'not_started';
                $keyResultClone->confidence = 'on_track';
                $keyResultClone->save();
            }
        }, attempts: 1);

        return redirect()->back()->with('success', 'Objective duplicated.');
    }

    /** Re-parent an objective (alignment drag / "Move under…"). */
    public function reparent(Request $request, HrGoal $goal)
    {
        $user = $this->manager($request);
        $goal = $this->goalAccess->currentGoal($user, $goal);

        $data = $request->validate([
            'parent_goal_id' => ['nullable', 'integer'],
        ]);

        $parentId = $data['parent_goal_id'] ?? null;
        $newParent = $parentId !== null
            ? $this->goalAccess->currentGoal($user, (int) $parentId)
            : null;

        // Guard against cycles: can't parent to self or to a descendant.
        if ($parentId !== null) {
            abort_if($parentId === $goal->id, 422, 'An objective cannot be its own parent.');
            abort_if($this->isDescendant($user, $goal, (int) $parentId), 422, 'Cannot move an objective under one of its own descendants.');
        }

        $oldParentId = $goal->parent_goal_id;
        DB::transaction(function () use ($goal, $user, $parentId): void {
            $this->goalAccess
                ->applyCurrentGoalScope(HrGoal::query(), $user)
                ->lockForUpdate()
                ->findOrFail($goal->id)
                ->update(['parent_goal_id' => $parentId]);
        }, attempts: 1);

        // Recompute roll-ups on both old and new branches.
        $oldParent = $oldParentId
            ? $this->goalAccess->applyCurrentGoalScope(HrGoal::query(), $user)->find($oldParentId)
            : null;
        if ($oldParent) {
            $this->goalService->recalculateGoalProgress($oldParent->fresh());
        }
        if ($newParent) {
            $this->goalService->recalculateGoalProgress($newParent->fresh());
        }

        return redirect()->back()->with('success', 'Objective moved.');
    }

    private function isDescendant(User $viewer, HrGoal $goal, int $candidateParentId): bool
    {
        $node = $this->goalAccess
            ->applyCurrentGoalScope(HrGoal::query(), $viewer)
            ->find($candidateParentId);
        $guard = 0;
        while ($node && $guard++ < 50) {
            if ($node->id === $goal->id) {
                return true;
            }
            if (! $node->parent_goal_id) {
                return false;
            }

            $node = $this->goalAccess
                ->applyCurrentGoalScope(HrGoal::query(), $viewer)
                ->find($node->parent_goal_id);

            // A broken or inaccessible branch is never a valid new parent.
            if (! $node) {
                return true;
            }
        }

        return $guard >= 50;
    }

    /** Export objectives + KRs for the current cycle lens as CSV. */
    public function export(Request $request): StreamedResponse
    {
        $user = $this->viewer($request);

        $cycleParam = $request->query('cycle');
        $cycleId = is_numeric($cycleParam)
            ? $this->cycle((int) $cycleParam)->id
            : null;

        $goals = $this->goalAccess
            ->applyHistoricalGoalScope(HrGoal::query(), $user)
            ->with(['user:id,name', 'cycle:id,name', 'keyResults'])
            ->forCycle($cycleId)
            ->orderBy('goal_type')
            ->orderBy('title')
            ->get();

        $filename = 'okr-export-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($goals) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, [
                'Type', 'Objective', 'Owner', 'Cycle', 'Status', 'Confidence',
                'Priority', 'Progress %', 'Start', 'Due',
                'Key result', 'KR type', 'Baseline', 'Current', 'Target', 'Unit', 'Weight', 'KR progress %',
            ]);

            foreach ($goals as $g) {
                $base = [
                    $g->goal_type,
                    $g->title,
                    $g->user?->name,
                    $g->cycle?->name,
                    $g->status,
                    $g->confidence,
                    $g->priority,
                    $g->progress_percentage,
                    $g->start_date?->toDateString(),
                    $g->due_date?->toDateString(),
                ];

                if ($g->keyResults->isEmpty()) {
                    $this->putCsv($out, [...$base, '', '', '', '', '', '', '', '']);

                    continue;
                }

                foreach ($g->keyResults as $kr) {
                    $this->putCsv($out, [
                        ...$base,
                        $kr->title,
                        $kr->kr_type,
                        $kr->start_value,
                        $kr->current_value,
                        $kr->target_value,
                        $kr->unit,
                        $kr->weight,
                        $kr->progress_percentage,
                    ]);
                }
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function canView($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.goals.view')
            || $user->canDo('hr.goals.manage')
            || $user->canDo('hr.performance.view')
            || $user->canDo('hr.performance.manage')
        );
    }

    private function canManage($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.goals.manage')
            || $user->canDo('hr.performance.manage')
        );
    }

    private function viewer(Request $request): User
    {
        $user = $this->goalAccess->currentViewer($request->user());
        abort_unless($this->canView($user), 403);

        return $user;
    }

    private function manager(Request $request): User
    {
        $user = $this->goalAccess->currentViewer($request->user());
        abort_unless($this->canManage($user), 403);

        return $user;
    }

    private function goalActor(Request $request): User
    {
        return $this->goalAccess->currentViewer($request->user());
    }

    private function goalForCheckin(User $viewer, HrGoal $goal): HrGoal
    {
        if ($this->canManage($viewer)) {
            return $this->goalAccess->currentGoal($viewer, $goal);
        }

        return HrGoal::query()
            ->where('user_id', $viewer->id)
            ->findOrFail($goal->getKey());
    }

    private function lockGoalForCheckin(User $viewer, HrGoal $goal): HrGoal
    {
        $query = $this->canManage($viewer)
            ? $this->goalAccess->applyCurrentGoalScope(HrGoal::query(), $viewer)
            : HrGoal::query()->where('user_id', $viewer->id);

        return $query->lockForUpdate()->findOrFail($goal->getKey());
    }

    private function cycle(int $cycleId): HrGoalCycle
    {
        return HrGoalCycle::query()->findOrFail($cycleId);
    }
}
