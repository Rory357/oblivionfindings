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
use App\Domain\Hr\Services\HrNotificationService;
use App\Http\Controllers\Controller;
use App\Models\User;
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
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — Dashboard + List */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $tenantId = $user->tenant_id ?? 1;

        // Resolve cycle lens. ?cycle=all shows every cycle; a numeric id scopes
        // to that cycle; default is the current cycle (or all if it's empty).
        $cycles = $this->cycleService->cyclesForTenant($tenantId);
        $current = $this->cycleService->currentCycle($tenantId);
        $cycleParam = $request->query('cycle');

        $selectedCycleId = null; // null === "all"
        if ($cycleParam === 'all') {
            $selectedCycleId = null;
        } elseif (is_numeric($cycleParam) && $cycles->contains('id', (int) $cycleParam)) {
            $selectedCycleId = (int) $cycleParam;
        } elseif ($current) {
            $hasGoals = HrGoal::forTenant($tenantId)->where('cycle_id', $current->id)->exists();
            $selectedCycleId = $hasGoals ? $current->id : null;
        }

        $objectives = HrGoal::query()
            ->forTenant($tenantId)
            ->with(['user:id,name', 'parentGoal:id,title', 'cycle:id,name', 'keyResults.owner:id,name'])
            ->withCount('developmentGoals')
            ->forCycle($selectedCycleId)
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (HrGoal $g) => $this->mapObjective($g))
            ->values();

        $users = User::orderBy('name')->get(['id', 'name']);

        $developmentPlans = HrDevelopmentGoal::query()
            ->forTenant($tenantId)
            ->with(['employee:id,name', 'manager:id,name', 'goal:id,title', 'competency:id,name'])
            ->orderByRaw("CASE WHEN status = 'blocked' THEN 0 WHEN status = 'in_progress' THEN 1 WHEN status = 'not_started' THEN 2 ELSE 3 END")
            ->orderBy('due_date')
            ->get()
            ->map(fn (HrDevelopmentGoal $d) => $this->mapDevelopmentPlan($d))
            ->values();

        $analytics = $this->goalService->getGoalAnalytics($tenantId, $selectedCycleId);
        $cascadeTree = $this->goalService->getCompanyGoalTree($tenantId, $selectedCycleId);

        return Inertia::render('hr/goals/index', [
            'objectives' => $objectives,
            'developmentPlans' => $developmentPlans,
            'users' => $users,
            'goalTypes' => $this->goalTypeOptions(),
            'priorities' => $this->priorityOptions(),
            'parentGoals' => $this->parentGoalOptions($tenantId),
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
            'templates' => HrGoalTemplate::forTenant($tenantId)->active()->orderBy('name')->get()
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
            'allTags' => HrGoal::forTenant($tenantId)->whereNotNull('tags')->pluck('tags')
                ->flatMap(fn ($t) => is_array($t) ? $t : [])->unique()->sort()->values(),
            'competencies' => HrCompetency::query()
                ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
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
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

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
    private function parentGoalOptions(int $tenantId)
    {
        return HrGoal::forTenant($tenantId)
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
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $tenantId = $user->tenant_id ?? 1;

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'goal_type' => ['required', 'string', 'in:individual,team,company'],
            'category' => ['nullable', 'string', 'max:255'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', 'max:40'],
            'parent_goal_id' => ['nullable', 'integer', Rule::exists('hr_goals', 'id')->where('tenant_id', $tenantId)],
            'cycle_id' => ['nullable', 'integer', Rule::exists('hr_goal_cycles', 'id')->where('tenant_id', $tenantId)],
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

        $goal = DB::transaction(function () use ($data, $keyResults, $tenantId, $user) {
            $goal = $this->goalService->createGoal([
                'tenant_id' => $tenantId,
                'created_by' => $user->id,
                ...$data,
            ]);

            foreach ($keyResults as $kr) {
                if (trim((string) ($kr['title'] ?? '')) === '') {
                    continue;
                }

                $created = HrKeyResult::create([
                    'tenant_id' => $tenantId,
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
        if ($goal->user_id !== $user->id && ($owner = User::find($goal->user_id))) {
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
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $goal->load([
            'user:id,name',
            'creator:id,name',
            'cycle:id,name',
            'parentGoal:id,title,goal_type',
            'childGoals' => fn ($q) => $q->with('user:id,name', 'keyResults')->orderBy('priority', 'desc'),
            'keyResults.owner:id,name',
            'updates' => fn ($q) => $q->with('user:id,name')->orderByDesc('created_at')->limit(20),
            'developmentGoals' => fn ($q) => $q->with('employee:id,name')->orderByDesc('updated_at'),
        ]);

        $users = User::orderBy('name')->get(['id', 'name']);

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
            'parentGoals' => $this->parentGoalOptions($user->tenant_id ?? 1),
            'cycles' => $this->cycleService->cyclesForTenant($user->tenant_id ?? 1)
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
                'manage' => $this->canManage($user),
                'updateProgress' => $this->canManage($user) || $goal->user_id === $user->id,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Update */
    /* ------------------------------------------------------------------ */

    public function update(Request $request, HrGoal $goal)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $tenantId = $goal->tenant_id;

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'goal_type' => ['sometimes', 'string', 'in:individual,team,company'],
            'category' => ['nullable', 'string', 'max:255'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:40'],
            'parent_goal_id' => ['nullable', 'integer', Rule::exists('hr_goals', 'id')->where('tenant_id', $tenantId)],
            'cycle_id' => ['nullable', 'integer', Rule::exists('hr_goal_cycles', 'id')->where('tenant_id', $tenantId)],
            'target_value' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'priority' => ['sometimes', 'string', 'in:low,medium,high'],
            'confidence' => ['sometimes', 'string', 'in:on_track,at_risk,off_track'],
            'checkin_frequency' => ['sometimes', 'string', 'in:weekly,fortnightly,monthly,quarterly'],
            'start_date' => ['sometimes', 'date'],
            'due_date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string', 'in:draft,active,on_hold,blocked,completed,cancelled'],
        ]);

        // A goal can't be its own parent (cycle guard).
        if (($data['parent_goal_id'] ?? null) === $goal->id) {
            unset($data['parent_goal_id']);
        }

        if (($data['status'] ?? null) === 'completed' && $goal->status !== 'completed') {
            $data['completed_at'] = now();
            $data['confidence'] = 'on_track';
        }

        $wasCompleted = $goal->status === 'completed';
        $goal->update($data);
        $this->notifyCompletionTransition($goal, $wasCompleted);

        return redirect()->back()->with('success', 'Objective updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Destroy */
    /* ------------------------------------------------------------------ */

    public function destroy(Request $request, HrGoal $goal)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $goal->delete();

        return redirect()->route('hr.goals.index')->with('success', 'Objective deleted.');
    }

    /* ------------------------------------------------------------------ */
    /*  Update Progress */
    /* ------------------------------------------------------------------ */

    public function updateProgress(Request $request, HrGoal $goal)
    {
        $user = $request->user();
        abort_unless(
            ($user && $this->canManage($user)) || $goal->user_id === $user?->id,
            403
        );

        $data = $request->validate([
            'current_value' => ['nullable', 'numeric', 'min:0'],
            'progress_percentage' => ['required', 'integer', 'min:0', 'max:100'],
            'confidence' => ['sometimes', 'string', 'in:on_track,at_risk,off_track'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        // Progress-source rule: an objective with KRs derives its % from them —
        // a manual slider must never clobber the weighted roll-up.
        abort_if($goal->hasKeyResults(), 422, 'This objective derives progress from its key results. Check in on the key results instead.');

        $wasCompleted = $goal->status === 'completed';
        $this->goalService->updateProgress($goal, [
            'user_id' => $user->id,
            ...$data,
        ]);
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
        $user = $request->user();
        abort_unless(
            ($user && $this->canManage($user)) || $goal->user_id === $user?->id,
            403
        );

        $data = $request->validate([
            'confidence' => ['required', 'string', 'in:on_track,at_risk,off_track'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'manual_progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'key_results' => ['sometimes', 'array'],
            'key_results.*.id' => ['required_with:key_results', 'integer'],
            'key_results.*.current_value' => ['required_with:key_results', 'numeric'],
            'key_results.*.confidence' => ['nullable', 'string', 'in:on_track,at_risk,off_track'],
        ]);

        $goal->loadMissing('keyResults');

        $wasCompleted = $goal->status === 'completed';

        DB::transaction(function () use ($goal, $data, $user) {
            if ($goal->hasKeyResults() && ! empty($data['key_results'])) {
                $byId = $goal->keyResults->keyBy('id');
                foreach ($data['key_results'] as $row) {
                    $kr = $byId->get($row['id']);
                    if (! $kr) {
                        continue;
                    }
                    $this->goalService->updateKeyResultProgress($kr, [
                        'current_value' => $row['current_value'],
                        'confidence' => $row['confidence'] ?? $data['confidence'],
                        'comment' => $data['comment'] ?? null,
                    ], $user->id);
                }
                // Recalc gives the weighted roll-up; log a goal-level note with confidence.
                $goal->refresh();
                $this->goalService->updateProgress($goal, [
                    'user_id' => $user->id,
                    'progress_percentage' => $goal->progress_percentage,
                    'confidence' => $data['confidence'],
                    'comment' => $data['comment'] ?? null,
                ]);
            } else {
                $this->goalService->updateProgress($goal, [
                    'user_id' => $user->id,
                    'progress_percentage' => $data['manual_progress'] ?? $goal->progress_percentage,
                    'confidence' => $data['confidence'],
                    'comment' => $data['comment'] ?? null,
                ]);
            }
        });
        $this->notifyCompletionTransition($goal, $wasCompleted);

        return redirect()->back()->with('success', 'Check-in logged.');
    }

    /* ================================================================== */
    /*  KEY RESULTS CRUD */
    /* ================================================================== */

    public function storeKeyResult(Request $request, HrGoal $goal)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'kr_type' => ['nullable', 'string', 'in:number,percent,currency,milestone,boolean'],
            'start_value' => ['nullable', 'numeric'],
            'target_value' => ['required', 'numeric'],
            'unit' => ['nullable', 'string', 'max:50'],
            'weight' => ['nullable', 'integer', 'min:1', 'max:10'],
            'due_date' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $kr = HrKeyResult::create([
            'tenant_id' => $goal->tenant_id,
            'goal_id' => $goal->id,
            'title' => $data['title'],
            'kr_type' => $data['kr_type'] ?? 'number',
            'start_value' => $data['start_value'] ?? 0,
            'current_value' => $data['start_value'] ?? 0,
            'target_value' => $data['target_value'],
            'unit' => $data['unit'] ?? null,
            'weight' => $data['weight'] ?? 1,
            'due_date' => $data['due_date'] ?? $goal->due_date,
            'owner_id' => $data['owner_id'] ?? $goal->user_id,
            'status' => 'not_started',
            'confidence' => $goal->confidence ?? 'on_track',
        ]);
        $kr->recalculateProgress();
        $kr->save();

        // First KR flips the objective from manual to derived — recalc now.
        $this->goalService->recalculateGoalProgress($goal->fresh());

        return redirect()->back()->with('success', 'Key result added.');
    }

    public function updateKeyResult(Request $request, HrKeyResult $keyResult)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

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

        // Static attributes (title/type/weight/baseline) update directly;
        // value/confidence flow through the service so progress recalculates
        // and a KR-level check-in is logged.
        $static = array_intersect_key($data, array_flip(['title', 'kr_type', 'start_value', 'target_value', 'unit', 'weight']));
        if ($static !== []) {
            $keyResult->fill($static);
            $keyResult->save();
        }

        $goal = $keyResult->goal;
        $wasCompleted = $goal->status === 'completed';
        $this->goalService->updateKeyResultProgress($keyResult, $data, $user->id);
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
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $goal = $keyResult->goal;
        $keyResult->delete();

        // Recalculate parent after deletion
        $this->goalService->recalculateGoalProgress($goal);

        return redirect()->back()->with('success', 'Key result removed.');
    }

    /* ================================================================== */
    /*  Bulk · Duplicate · Re-parent · Export */
    /* ================================================================== */

    /** Back the multi-select bar on the objectives table. */
    public function bulk(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $tenantId = $user->tenant_id ?? 1;

        $data = $request->validate([
            'action' => ['required', 'string', 'in:archive,request_checkin,reassign_owner,recycle'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'cycle_id' => ['nullable', 'integer', Rule::exists('hr_goal_cycles', 'id')->where('tenant_id', $tenantId)],
        ]);

        $goals = HrGoal::forTenant($tenantId)->whereIn('id', $data['ids'])->get();
        $count = 0;

        foreach ($goals as $goal) {
            switch ($data['action']) {
                case 'archive':
                    $goal->update(['status' => 'cancelled']);
                    $count++;
                    break;
                case 'reassign_owner':
                    if (! empty($data['owner_id'])) {
                        $goal->update(['user_id' => $data['owner_id']]);
                        $count++;
                    }
                    break;
                case 'request_checkin':
                    if ($owner = User::find($goal->user_id)) {
                        $owner->notify(new GoalAssignedNotification($goal, checkinReminder: true));
                        $count++;
                    }
                    break;
                case 'recycle':
                    // handled below in one shot
                    break;
            }
        }

        if ($data['action'] === 'recycle') {
            $target = ($data['cycle_id'] ?? null)
                ? HrGoalCycle::find($data['cycle_id'])
                : $this->cycleService->currentCycle($tenantId);
            if ($target) {
                $count = $this->cycleService->rollover($target, $data['ids'], true);
            }
        }

        return redirect()->back()->with('success', "{$count} objective(s) updated.");
    }

    /** Duplicate an objective (optionally with KRs) into a chosen cycle. */
    public function duplicate(Request $request, HrGoal $goal)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $tenantId = $goal->tenant_id;

        $data = $request->validate([
            'cycle_id' => ['nullable', 'integer', Rule::exists('hr_goal_cycles', 'id')->where('tenant_id', $tenantId)],
            'with_key_results' => ['sometimes', 'boolean'],
        ]);

        $target = ($data['cycle_id'] ?? null)
            ? HrGoalCycle::find($data['cycle_id'])
            : ($goal->cycle ?? $this->cycleService->currentCycle($tenantId));

        $withKrs = $data['with_key_results'] ?? true;

        DB::transaction(function () use ($goal, $target, $withKrs) {
            $clone = $goal->replicate(['progress_percentage', 'completed_at', 'last_checkin_at']);
            $clone->title = $goal->title.' (copy)';
            $clone->status = 'draft';
            $clone->confidence = 'on_track';
            $clone->progress_percentage = 0;
            $clone->completed_at = null;
            $clone->last_checkin_at = null;
            if ($target) {
                $clone->cycle_id = $target->id;
                $clone->start_date = $target->starts_at;
                $clone->due_date = $target->ends_at;
            }
            $clone->save();

            if ($withKrs) {
                foreach ($goal->keyResults as $kr) {
                    $krClone = $kr->replicate(['current_value', 'progress_percentage', 'status']);
                    $krClone->goal_id = $clone->id;
                    $krClone->current_value = $kr->start_value;
                    $krClone->progress_percentage = 0;
                    $krClone->status = 'not_started';
                    $krClone->confidence = 'on_track';
                    $krClone->save();
                }
            }
        });

        return redirect()->back()->with('success', 'Objective duplicated.');
    }

    /** Re-parent an objective (alignment drag / "Move under…"). */
    public function reparent(Request $request, HrGoal $goal)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $tenantId = $goal->tenant_id;

        $data = $request->validate([
            'parent_goal_id' => ['nullable', 'integer', Rule::exists('hr_goals', 'id')->where('tenant_id', $tenantId)],
        ]);

        $parentId = $data['parent_goal_id'] ?? null;

        // Guard against cycles: can't parent to self or to a descendant.
        if ($parentId !== null) {
            abort_if($parentId === $goal->id, 422, 'An objective cannot be its own parent.');
            abort_if($this->isDescendant($goal, $parentId), 422, 'Cannot move an objective under one of its own descendants.');
        }

        $oldParent = $goal->parentGoal;
        $goal->update(['parent_goal_id' => $parentId]);

        // Recompute roll-ups on both old and new branches.
        if ($oldParent) {
            $this->goalService->recalculateGoalProgress($oldParent->fresh());
        }
        if ($parentId && ($newParent = HrGoal::find($parentId))) {
            $this->goalService->recalculateGoalProgress($newParent);
        }

        return redirect()->back()->with('success', 'Objective moved.');
    }

    private function isDescendant(HrGoal $goal, int $candidateParentId): bool
    {
        $node = HrGoal::find($candidateParentId);
        $guard = 0;
        while ($node && $guard++ < 50) {
            if ($node->parent_goal_id === $goal->id) {
                return true;
            }
            $node = $node->parent_goal_id ? HrGoal::find($node->parent_goal_id) : null;
        }

        return false;
    }

    /** Export objectives + KRs for the current cycle lens as CSV. */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $tenantId = $user->tenant_id ?? 1;
        $cycleParam = $request->query('cycle');
        $cycleId = is_numeric($cycleParam) ? (int) $cycleParam : null;

        $goals = HrGoal::forTenant($tenantId)
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
}
