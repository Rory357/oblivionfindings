<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrPerformanceReview;
use App\Domain\Hr\Services\GoalService;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GoalController extends Controller
{
    public function __construct(
        protected GoalService $goalService,
    ) {}

    /**
     * List all goals with progress bars.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.goals.view'), 403);

        $goals = HrGoal::query()
            ->forTenant($user->tenant_id)
            ->with(['user:id,name', 'parentGoal:id,title'])
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->query('goal_type'), fn ($q, $type) => $q->where('goal_type', $type))
            ->when($request->query('priority'), fn ($q, $p) => $q->where('priority', $p))
            ->when($request->query('user_id'), fn ($q, $uid) => $q->where('user_id', $uid))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $users = User::where('tenant_id', $user->tenant_id)->get(['id', 'name']);

        return Inertia::render('hr/goals/index', [
            'goals' => $goals,
            'users' => $users,
            'filters' => [
                'status' => $request->query('status'),
                'goal_type' => $request->query('goal_type'),
                'priority' => $request->query('priority'),
                'user_id' => $request->query('user_id'),
            ],
            'can' => [
                'manage' => $user->canDo('hr.goals.manage'),
            ],
        ]);
    }

    /**
     * Show create goal form.
     */
    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.goals.manage'), 403);

        $users = User::where('tenant_id', $user->tenant_id)->get(['id', 'name']);
        $parentGoals = HrGoal::forTenant($user->tenant_id)
            ->whereNull('parent_goal_id')
            ->active()
            ->get(['id', 'title']);

        return Inertia::render('hr/goals/create', [
            'users' => $users,
            'parentGoals' => $parentGoals,
            'goalTypes' => [
                ['value' => 'individual', 'label' => 'Individual'],
                ['value' => 'team', 'label' => 'Team'],
                ['value' => 'company', 'label' => 'Company'],
            ],
            'priorities' => [
                ['value' => 'low', 'label' => 'Low'],
                ['value' => 'medium', 'label' => 'Medium'],
                ['value' => 'high', 'label' => 'High'],
            ],
        ]);
    }

    /**
     * Store a new goal.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.goals.manage'), 403);

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

        $this->goalService->createGoal([
            'tenant_id' => $user->tenant_id,
            'created_by' => $user->id,
            ...$data,
        ]);

        return redirect()->route('hr.goals.index')->with('success', 'Goal created.');
    }

    /**
     * Show goal detail with progress updates.
     */
    public function show(Request $request, HrGoal $goal)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.goals.view'), 403);

        $goal->load([
            'user:id,name',
            'creator:id,name',
            'parentGoal:id,title',
            'childGoals:id,title,progress_percentage,status',
            'updates' => fn ($q) => $q->with('user:id,name')->orderByDesc('created_at'),
            'performanceReview:id,review_type,status',
        ]);

        return Inertia::render('hr/goals/show', [
            'goal' => $goal,
            'can' => [
                'manage' => $user->canDo('hr.goals.manage'),
                'updateProgress' => $user->canDo('hr.goals.manage') || $goal->user_id === $user->id,
            ],
        ]);
    }

    /**
     * Update goal details.
     */
    public function update(Request $request, HrGoal $goal)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('hr.goals.manage'), 403);

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

    /**
     * Update progress on a goal.
     */
    public function updateProgress(Request $request, HrGoal $goal)
    {
        $user = $request->user();
        abort_unless(
            $user && ($user->canDo('hr.goals.manage') || $goal->user_id === $user->id),
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
}
