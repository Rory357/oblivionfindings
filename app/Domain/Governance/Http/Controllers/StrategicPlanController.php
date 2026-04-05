<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domain\Governance\Models\StrategicPlan;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StrategicPlanController extends Controller
{
    public function create()
    {
        abort_unless(request()->user()?->canDo('governance.strategy.manage'), 403);

        return Inertia::render('Governance/Strategy/Create');
    }

    public function index(Request $request)
    {
        abort_unless($request->user()?->canDo('governance.strategy.view'), 403);

        $plans = StrategicPlan::query()
            ->withCount('goals')
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Governance/Strategy/Index', [
            'plans' => ['data' => $plans],
        ]);
    }

    public function show(Request $request, StrategicPlan $plan)
    {
        abort_unless($request->user()?->canDo('governance.strategy.view'), 403);

        $plan->load(['goals' => fn($q) => $q->orderBy('order')]);

        return Inertia::render('Governance/Strategy/Show', [
            'plan' => $plan,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->canDo('governance.strategy.manage'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'planning_horizon' => ['required', 'in:3_year,5_year'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'vision_statement' => ['nullable', 'string'],
            'mission_statement' => ['nullable', 'string'],
            'values' => ['nullable', 'array'],
        ]);

        $plan = StrategicPlan::create([
            'title' => $data['title'],
            'planning_horizon' => $data['planning_horizon'],
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'vision_statement' => $data['vision_statement'] ?? $data['description'] ?? 'TBD',
            'mission_statement' => $data['mission_statement'] ?? 'TBD',
            'values' => $data['values'] ?? [],
            'created_by' => $request->user()->id,
        ]);

        return redirect()->route('governance.strategy.index')
            ->with('success', 'Strategic plan created.');
    }

    public function update(Request $request, StrategicPlan $plan)
    {
        abort_unless($request->user()?->canDo('governance.strategy.manage'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'planning_horizon' => ['sometimes', 'in:3_year,5_year'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after:period_start'],
            'vision_statement' => ['nullable', 'string'],
            'mission_statement' => ['nullable', 'string'],
            'values' => ['nullable', 'array'],
            'status' => ['sometimes', 'string', 'in:draft,active,completed,archived'],
        ]);

        $plan->update([
            'title' => $data['title'],
            'planning_horizon' => $data['planning_horizon'] ?? $plan->planning_horizon,
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'vision_statement' => $data['vision_statement'] ?? $data['description'] ?? $plan->vision_statement,
            'mission_statement' => $data['mission_statement'] ?? $plan->mission_statement,
            'values' => $data['values'] ?? $plan->values,
            'status' => $data['status'] ?? $plan->status,
        ]);

        return redirect()->back()->with('success', 'Strategic plan updated.');
    }

    public function addGoal(Request $request, StrategicPlan $plan)
    {
        abort_unless($request->user()?->canDo('governance.strategy.manage'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'order' => ['integer', 'min:0'],
        ]);

        $plan->goals()->create($data);

        return redirect()->back()->with('success', 'Goal added.');
    }

    public function approve(Request $request, StrategicPlan $plan)
    {
        abort_unless($request->user()?->canDo('governance.strategy.manage'), 403);

        $validated = $request->validate([
            'resolution_id' => 'required|exists:resolutions,id',
            'notes' => 'nullable|string',
        ]);

        $plan->update([
            'approval_resolution_id' => $validated['resolution_id'],
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'status' => 'active',
        ]);

        return redirect()->back()->with('success', 'Strategic plan approved.');
    }

    public function edit(StrategicPlan $plan)
    {
        abort_unless(request()->user()?->canDo('governance.strategy.manage'), 403);

        $plan->load('goals');

        return Inertia::render('Governance/Strategy/Edit', [
            'plan' => $plan,
        ]);
    }

    public function createVersion(Request $request, StrategicPlan $plan)
    {
        abort_unless($request->user()?->canDo('governance.strategy.manage'), 403);

        $validated = $request->validate([
            'version_notes' => 'required|string|max:500',
        ]);

        $newPlan = $plan->createNewVersion($validated['version_notes'], auth()->id());

        return redirect()->route('governance.strategy.show', $newPlan)
            ->with('success', 'New version created (v' . $newPlan->version_number . ').');
    }

    public function changes(StrategicPlan $plan)
    {
        abort_unless(request()->user()?->canDo('governance.strategy.view'), 403);

        $changeData = $plan->getChangesSinceLastSnapshot();

        return Inertia::render('Governance/Strategy/Changes', [
            'plan' => $plan->load('goals'),
            'changes' => $changeData,
        ]);
    }
}
