<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrGoalCycle;
use App\Domain\Hr\Services\CycleService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\HrGoalAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GoalCycleController extends Controller
{
    public function __construct(
        private readonly CycleService $cycleService,
        private readonly HrGoalAccessService $goalAccess,
        private readonly HrCurrentStaffService $currentStaff,
    ) {}

    /** List the application-wide cycle catalogue used by selectors. */
    public function index(Request $request): JsonResponse
    {
        $this->viewer($request);

        $cycles = $this->cycleService->cycles()
            ->sortBy('starts_at')
            ->values()
            ->map(fn (HrGoalCycle $cycle) => [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'type' => $cycle->type,
                'status' => $cycle->status,
                'starts_at' => $cycle->starts_at?->toDateString(),
                'ends_at' => $cycle->ends_at?->toDateString(),
            ]);

        return response()->json([
            'cycles' => $cycles,
            'current_cycle_id' => $this->cycleService->currentCycle()?->id,
        ]);
    }

    public function store(Request $request)
    {
        $this->manager($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('hr_goal_cycles', 'name')],
            'type' => ['required', 'string', 'in:quarter,half,year,custom'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'parent_cycle_id' => ['nullable', 'integer'],
        ]);
        $parent = isset($data['parent_cycle_id'])
            ? $this->cycle((int) $data['parent_cycle_id'])
            : null;

        HrGoalCycle::query()->create([
            ...$data,
            'parent_cycle_id' => $parent?->id,
            'status' => 'upcoming',
        ]);

        return redirect()->back()->with('success', 'Cycle created.');
    }

    public function update(Request $request, HrGoalCycle $cycle)
    {
        $this->manager($request);
        $cycle = $this->cycle((int) $cycle->getKey());

        $data = $request->validate([
            'name' => [
                'sometimes',
                'string',
                'max:120',
                Rule::unique('hr_goal_cycles', 'name')->ignore($cycle->id),
            ],
            'type' => ['sometimes', 'string', 'in:quarter,half,year,custom'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after_or_equal:starts_at'],
            'status' => ['sometimes', 'string', 'in:upcoming,active,closed'],
        ]);

        DB::transaction(function () use ($cycle, $data): void {
            HrGoalCycle::query()
                ->lockForUpdate()
                ->findOrFail($cycle->id)
                ->update($data);
        }, attempts: 1);

        return redirect()->back()->with('success', 'Cycle updated.');
    }

    public function close(Request $request, HrGoalCycle $cycle)
    {
        $user = $this->manager($request);
        $cycle = $this->cycle((int) $cycle->getKey());

        [$autoCompleted, $remainOpen] = DB::transaction(function () use ($cycle, $user): array {
            $lockedCycle = HrGoalCycle::query()
                ->lockForUpdate()
                ->findOrFail($cycle->id);
            $allCurrentGoals = HrGoal::query()
                ->where('cycle_id', $lockedCycle->id)
                ->whereIn('user_id', $this->currentStaff->currentUsersQuery()->select('users.id'))
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();
            $visibleCurrentGoalIds = $this->goalAccess
                ->applyCurrentGoalScope(HrGoal::query(), $user)
                ->where('cycle_id', $lockedCycle->id)
                ->where('status', 'active')
                ->pluck('id');

            abort_unless(
                $allCurrentGoals->pluck('id')->sort()->values()->all()
                    === $visibleCurrentGoalIds->sort()->values()->all(),
                403,
                'This cycle contains current objectives outside your approved Sites.',
            );

            $lockedCycle->update(['status' => 'closed']);
            $autoCompleted = 0;
            foreach ($allCurrentGoals as $goal) {
                if ((int) $goal->progress_percentage < 100) {
                    continue;
                }

                $goal->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
                $autoCompleted++;
            }

            return [
                $autoCompleted,
                $allCurrentGoals->count() - $autoCompleted,
            ];
        }, attempts: 1);

        return redirect()->back()->with(
            'success',
            "Cycle “{$cycle->name}” closed — {$autoCompleted} objective(s) auto-completed, {$remainOpen} remain open for rollover.",
        );
    }

    /** Clone an exact Site-visible selection from this cycle into another. */
    public function rollover(Request $request, HrGoalCycle $cycle)
    {
        $user = $this->manager($request);
        $cycle = $this->cycle((int) $cycle->getKey());

        $data = $request->validate([
            'target_cycle_id' => ['required', 'integer'],
            'goal_ids' => ['required', 'array', 'min:1'],
            'goal_ids.*' => ['integer', 'distinct'],
            'with_key_results' => ['sometimes', 'boolean'],
        ]);
        $target = $this->cycle((int) $data['target_cycle_id']);
        abort_if($target->id === $cycle->id, 422, 'Choose a different target cycle.');

        $goalIds = collect($data['goal_ids'])->map(fn ($id) => (int) $id)->values();
        $goals = $this->goalAccess
            ->applyCurrentGoalScope(HrGoal::query(), $user)
            ->where('cycle_id', $cycle->id)
            ->whereKey($goalIds->all())
            ->get();
        abort_unless($goals->count() === $goalIds->count(), 404);

        $count = $this->cycleService->rollover(
            $user,
            $target,
            $goals,
            $data['with_key_results'] ?? true,
            source: $cycle,
        );

        return redirect()->back()->with('success', "{$count} objective(s) rolled over to “{$target->name}”.");
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

    private function cycle(int $cycleId): HrGoalCycle
    {
        return HrGoalCycle::query()->findOrFail($cycleId);
    }

    private function canView(?User $user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.performance.view')
            || $user->canDo('hr.performance.manage')
        );
    }

    private function canManage(?User $user): bool
    {
        return (bool) $user && $user->canDo('hr.performance.manage');
    }
}
