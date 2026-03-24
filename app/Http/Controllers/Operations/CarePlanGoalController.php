<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use Illuminate\Http\Request;

class CarePlanGoalController extends Controller
{
    public function store(Request $request, $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.update'), 403);

        $carePlan = CarePlan::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($carePlan);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['required', 'string', 'max:100'],
            'priority' => ['required', 'string', 'in:low,medium,high,critical'],
            'target_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:not_started,in_progress,completed,on_hold,cancelled'],
        ]);

        CarePlanGoal::create([
            'organization_id' => $auth->organization_id,
            'care_plan_id' => $carePlan->id,
            'client_id' => $carePlan->client_id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'category' => $data['category'],
            'priority' => $data['priority'],
            'target_date' => $data['target_date'] ?? null,
            'status' => $data['status'] ?? 'not_started',
            'progress_percentage' => 0,
            'created_by' => $auth->id,
        ]);

        return redirect()->back()->with('success', 'Goal added.');
    }

    public function update(Request $request, $carePlan, $goal)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.update'), 403);

        $carePlan = CarePlan::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($carePlan);

        $goal = CarePlanGoal::query()
            ->where('care_plan_id', $carePlan->id)
            ->findOrFail($goal);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['sometimes', 'required', 'string', 'max:100'],
            'priority' => ['sometimes', 'required', 'string', 'in:low,medium,high,critical'],
            'target_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:not_started,in_progress,completed,on_hold,cancelled'],
            'outcome_notes' => ['nullable', 'string'],
        ]);

        $goal->update($data);

        return redirect()->back()->with('success', 'Goal updated.');
    }

    public function destroy(Request $request, $carePlan, $goal)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.update'), 403);

        $carePlan = CarePlan::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($carePlan);

        $goal = CarePlanGoal::query()
            ->where('care_plan_id', $carePlan->id)
            ->findOrFail($goal);

        $goal->delete();

        return redirect()->back()->with('success', 'Goal removed.');
    }

    public function updateProgress(Request $request, $carePlan, $goal)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.update'), 403);

        $carePlan = CarePlan::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($carePlan);

        $goal = CarePlanGoal::query()
            ->where('care_plan_id', $carePlan->id)
            ->findOrFail($goal);

        $data = $request->validate([
            'progress_percentage' => ['required', 'integer', 'min:0', 'max:100'],
            'status' => ['nullable', 'string', 'in:not_started,in_progress,completed,on_hold,cancelled'],
        ]);

        $goal->update([
            'progress_percentage' => $data['progress_percentage'],
            'status' => $data['status'] ?? ($data['progress_percentage'] >= 100 ? 'completed' : 'in_progress'),
        ]);

        return redirect()->back()->with('success', 'Progress updated.');
    }
}
