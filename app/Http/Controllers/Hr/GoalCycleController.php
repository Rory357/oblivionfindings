<?php

namespace App\Http\Controllers\Hr;

use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrGoalCycle;
use App\Domain\Hr\Services\CycleService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GoalCycleController extends Controller
{
    public function __construct(
        protected CycleService $cycleService,
    ) {}

    /** List cycles for the tenant (JSON — used by selectors / API callers). */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canView($user), 403);

        $tenantId = $user->tenant_id ?? 1;

        $cycles = $this->cycleService->cyclesForTenant($tenantId)
            ->sortBy('starts_at')
            ->values()
            ->map(fn (HrGoalCycle $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
                'status' => $c->status,
                'starts_at' => $c->starts_at?->toDateString(),
                'ends_at' => $c->ends_at?->toDateString(),
            ]);

        return response()->json([
            'cycles' => $cycles,
            'current_cycle_id' => $this->cycleService->currentCycle($tenantId)?->id,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);

        $tenantId = $user->tenant_id ?? 1;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', 'in:quarter,half,year,custom'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'parent_cycle_id' => ['nullable', 'integer', Rule::exists('hr_goal_cycles', 'id')->where('tenant_id', $tenantId)],
        ]);

        HrGoalCycle::create([
            'tenant_id' => $tenantId,
            ...$data,
            'status' => 'upcoming',
        ]);

        return redirect()->back()->with('success', 'Cycle created.');
    }

    public function update(Request $request, HrGoalCycle $cycle)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        abort_unless(($user->tenant_id ?? 1) === $cycle->tenant_id, 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', 'string', 'in:quarter,half,year,custom'],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date', 'after_or_equal:starts_at'],
            'status' => ['sometimes', 'string', 'in:upcoming,active,closed'],
        ]);

        $cycle->update($data);

        return redirect()->back()->with('success', 'Cycle updated.');
    }

    public function close(Request $request, HrGoalCycle $cycle)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        abort_unless(($user->tenant_id ?? 1) === $cycle->tenant_id, 403);

        $cycle->update(['status' => 'closed']);

        // Close-out: objectives that reached 100% are auto-completed; anything
        // else is left untouched for the existing rollover flow.
        $autoCompleted = 0;
        HrGoal::query()
            ->forTenant($cycle->tenant_id)
            ->where('cycle_id', $cycle->id)
            ->where('status', 'active')
            ->where('progress_percentage', '>=', 100)
            ->get()
            ->each(function (HrGoal $goal) use (&$autoCompleted) {
                $goal->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);
                $autoCompleted++;
            });

        $remainOpen = HrGoal::query()
            ->forTenant($cycle->tenant_id)
            ->where('cycle_id', $cycle->id)
            ->where('status', 'active')
            ->count();

        return redirect()->back()->with(
            'success',
            "Cycle “{$cycle->name}” closed — {$autoCompleted} objective(s) auto-completed, {$remainOpen} remain open for rollover.",
        );
    }

    /** Clone selected objectives from this cycle into another. */
    public function rollover(Request $request, HrGoalCycle $cycle)
    {
        $user = $request->user();
        abort_unless($this->canManage($user), 403);
        abort_unless(($user->tenant_id ?? 1) === $cycle->tenant_id, 403);

        $tenantId = $user->tenant_id ?? 1;

        $data = $request->validate([
            'target_cycle_id' => ['required', 'integer', Rule::exists('hr_goal_cycles', 'id')->where('tenant_id', $tenantId)],
            'goal_ids' => ['required', 'array', 'min:1'],
            'goal_ids.*' => ['integer'],
            'with_key_results' => ['sometimes', 'boolean'],
        ]);

        $target = HrGoalCycle::findOrFail($data['target_cycle_id']);
        $count = $this->cycleService->rollover($target, $data['goal_ids'], $data['with_key_results'] ?? true);

        return redirect()->back()->with('success', "{$count} objective(s) rolled over to “{$target->name}”.");
    }

    private function canView($user): bool
    {
        return (bool) $user && (
            $user->canDo('hr.performance.view')
            || $user->canDo('hr.performance.manage')
        );
    }

    private function canManage($user): bool
    {
        return (bool) $user && $user->canDo('hr.performance.manage');
    }
}
