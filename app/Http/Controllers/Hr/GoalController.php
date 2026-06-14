<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrKeyResult;
use App\Domain\Hr\Services\GoalService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class GoalController extends Controller
{
    public function __construct(
        protected GoalService $goalService,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Index — Dashboard + List                                           */
    /* ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $tenantId = $user->tenant_id ?? 1;

        $goals = HrGoal::query()
            ->forTenant($tenantId)
            ->with(['user:id,name', 'parentGoal:id,title', 'keyResults'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('goal_type'), fn ($q, $type) => $q->where('goal_type', $type))
            ->when($request->query('priority'), fn ($q, $p) => $q->where('priority', $p))
            ->when($request->query('user_id'), fn ($q, $uid) => $q->where('user_id', $uid))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $goals->through(fn (HrGoal $g) => [
            'id' => $g->id,
            'title' => $g->title,
            'description' => $g->description,
            'goal_type' => $g->goal_type,
            'status' => $g->status,
            'priority' => $g->priority,
            'progress_percentage' => $g->progress_percentage,
            'target_value' => $g->target_value,
            'current_value' => $g->current_value,
            'unit' => $g->unit,
            'start_date' => $g->start_date?->toDateString(),
            'due_date' => $g->due_date?->toDateString(),
            'user' => $g->user ? ['id' => $g->user->id, 'name' => $g->user->name] : null,
            'parent_goal_id' => $g->parent_goal_id,
            'parent_goal' => $g->parentGoal ? ['id' => $g->parentGoal->id, 'title' => $g->parentGoal->title] : null,
            'key_results_count' => $g->keyResults->count(),
        ]);

        $users = User::orderBy('name')->get(['id', 'name']);
        $analytics = $this->goalService->getGoalAnalytics($tenantId);
        $cascadeTree = $this->goalService->getCompanyGoalTree($tenantId);

        return Inertia::render('hr/goals/index', [
            'goals' => $goals,
            'users' => $users,
            'goalTypes' => $this->goalTypeOptions(),
            'priorities' => $this->priorityOptions(),
            'parentGoals' => $this->parentGoalOptions($tenantId),
            'analytics' => $analytics,
            'cascadeTree' => $cascadeTree,
            'filters' => [
                'status' => $request->query('status'),
                'goal_type' => $request->query('goal_type'),
                'priority' => $request->query('priority'),
                'user_id' => $request->query('user_id'),
            ],
            'can' => [
                'manage' => $this->canManage($user),
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Create                                                             */
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
    /*  Store                                                              */
    /* ------------------------------------------------------------------ */

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'goal_type' => ['required', 'string', 'in:individual,team,company'],
            'category' => ['nullable', 'string', 'max:255'],
            'parent_goal_id' => ['nullable', 'integer', 'exists:hr_goals,id'],
            'target_value' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'priority' => ['required', 'string', 'in:low,medium,high'],
            'start_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:start_date'],
            'status' => ['sometimes', 'string', 'in:draft,active'],
        ]);

        $goal = $this->goalService->createGoal([
            'tenant_id' => $user->tenant_id ?? 1,
            'created_by' => $user->id,
            ...$data,
        ]);

        return redirect("/hr/goals/{$goal->id}")->with('success', 'Objective created. Add key results below.');
    }

    /* ------------------------------------------------------------------ */
    /*  Show — Objective Detail                                            */
    /* ------------------------------------------------------------------ */

    public function show(Request $request, HrGoal $goal)
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $goal->load([
            'user:id,name',
            'creator:id,name',
            'parentGoal:id,title,goal_type',
            'childGoals' => fn ($q) => $q->with('user:id,name', 'keyResults')->orderBy('priority', 'desc'),
            'keyResults.owner:id,name',
            'updates' => fn ($q) => $q->with('user:id,name')->orderByDesc('created_at')->limit(20),
        ]);

        $users = User::orderBy('name')->get(['id', 'name']);

        return Inertia::render('hr/goals/show', [
            'goal' => [
                'id' => $goal->id,
                'title' => $goal->title,
                'description' => $goal->description,
                'goal_type' => $goal->goal_type,
                'category' => $goal->category,
                'status' => $goal->status,
                'priority' => $goal->priority,
                'progress_percentage' => $goal->progress_percentage,
                'target_value' => $goal->target_value,
                'current_value' => $goal->current_value,
                'unit' => $goal->unit,
                'start_date' => $goal->start_date?->toDateString(),
                'due_date' => $goal->due_date?->toDateString(),
                'completed_at' => $goal->completed_at?->toDateString(),
                'user' => $goal->user ? ['id' => $goal->user->id, 'name' => $goal->user->name] : null,
                'creator' => $goal->creator?->name,
                'parent_goal_id' => $goal->parent_goal_id,
                'parent_goal' => $goal->parentGoal ? ['id' => $goal->parentGoal->id, 'title' => $goal->parentGoal->title, 'goal_type' => $goal->parentGoal->goal_type] : null,
                'child_goals' => $goal->childGoals->map(fn ($c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'goal_type' => $c->goal_type,
                    'status' => $c->status,
                    'priority' => $c->priority,
                    'progress_percentage' => $c->progress_percentage,
                    'user' => $c->user ? ['name' => $c->user->name] : null,
                    'key_results_count' => $c->keyResults->count(),
                ])->values(),
                'key_results' => $goal->keyResults->map(fn ($kr) => [
                    'id' => $kr->id,
                    'title' => $kr->title,
                    'target_value' => (float) $kr->target_value,
                    'current_value' => (float) $kr->current_value,
                    'unit' => $kr->unit,
                    'progress_percentage' => $kr->progress_percentage,
                    'status' => $kr->status,
                    'due_date' => $kr->due_date?->toDateString(),
                    'owner' => $kr->owner ? ['id' => $kr->owner->id, 'name' => $kr->owner->name] : null,
                ])->values(),
                'updates' => $goal->updates->map(fn ($u) => [
                    'id' => $u->id,
                    'user_name' => $u->user?->name,
                    'previous_value' => $u->previous_value,
                    'new_value' => $u->new_value,
                    'progress_percentage' => $u->progress_percentage,
                    'comment' => $u->comment,
                    'created_at' => $u->created_at?->diffForHumans(),
                ])->values(),
            ],
            'users' => $users,
            'goalTypes' => $this->goalTypeOptions(),
            'priorities' => $this->priorityOptions(),
            'parentGoals' => $this->parentGoalOptions($user->tenant_id ?? 1),
            'can' => [
                'manage' => $this->canManage($user),
                'updateProgress' => $this->canManage($user) || $goal->user_id === $user->id,
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  Update                                                             */
    /* ------------------------------------------------------------------ */

    public function update(Request $request, HrGoal $goal)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'goal_type' => ['sometimes', 'string', 'in:individual,team,company'],
            'category' => ['nullable', 'string', 'max:255'],
            'parent_goal_id' => ['nullable', 'integer', 'exists:hr_goals,id'],
            'target_value' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'priority' => ['sometimes', 'string', 'in:low,medium,high'],
            'start_date' => ['sometimes', 'date'],
            'due_date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'string', 'in:draft,active,completed,cancelled'],
        ]);

        $goal->update($data);

        return redirect()->back()->with('success', 'Goal updated.');
    }

    /* ------------------------------------------------------------------ */
    /*  Update Progress                                                    */
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
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->goalService->updateProgress($goal, [
            'user_id' => $user->id,
            ...$data,
        ]);

        return redirect()->back()->with('success', 'Progress updated.');
    }

    /* ================================================================== */
    /*  KEY RESULTS CRUD                                                   */
    /* ================================================================== */

    public function storeKeyResult(Request $request, HrGoal $goal)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'target_value' => ['required', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:50'],
            'due_date' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        HrKeyResult::create([
            'tenant_id' => $goal->tenant_id,
            'goal_id' => $goal->id,
            'title' => $data['title'],
            'target_value' => $data['target_value'],
            'unit' => $data['unit'] ?? null,
            'due_date' => $data['due_date'] ?? $goal->due_date,
            'owner_id' => $data['owner_id'] ?? $goal->user_id,
            'status' => 'not_started',
        ]);

        return redirect()->back()->with('success', 'Key result added.');
    }

    public function updateKeyResult(Request $request, HrKeyResult $keyResult)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $data = $request->validate([
            'current_value' => ['sometimes', 'numeric', 'min:0'],
            'title' => ['sometimes', 'string', 'max:500'],
            'target_value' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', Rule::in(['not_started', 'in_progress', 'completed', 'cancelled'])],
        ]);

        $this->goalService->updateKeyResultProgress($keyResult, $data);

        return redirect()->back()->with('success', 'Key result updated.');
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
