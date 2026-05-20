<?php

namespace App\Domain\Governance\Http\Controllers;

use App\Domain\Governance\Models\StrategicPlan;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StrategicPlanController extends Controller
{
    public function create()
    {
        $this->authorize('create', StrategicPlan::class);

        return Inertia::render('Governance/Strategy/Create');
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', StrategicPlan::class);

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
        $this->authorize('view', $plan);

        $plan->load(['goals' => fn ($q) => $q->orderBy('order')]);

        return Inertia::render('Governance/Strategy/Show', [
            'plan' => $plan,
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', StrategicPlan::class);

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
        $this->authorize('update', $plan);

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
        $this->authorize('addGoal', $plan);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'timeframe' => ['nullable', 'string', 'max:255'],
            'pillar' => ['nullable', 'string', 'max:255'],
            'lead_executive_id' => ['nullable', 'exists:users,id'],
            'key_results' => ['nullable', 'array'],
            'risks' => ['nullable', 'array'],
            'order' => ['integer', 'min:0'],
        ]);

        $data['timeframe'] ??= $plan->period_start?->toDateString().' - '.$plan->period_end?->toDateString();
        $data['pillar'] ??= 'quality';
        $data['lead_executive_id'] ??= $request->user()->id;

        $plan->goals()->create($data);

        return redirect()->back()->with('success', 'Goal added.');
    }

    public function approve(Request $request, StrategicPlan $plan)
    {
        $this->authorize('approve', $plan);

        $validated = $request->validate([
            'resolution_id' => 'required|exists:resolutions,id',
            'notes' => 'nullable|string',
        ]);

        $plan->approve($validated['resolution_id']);

        return redirect()->back()->with('success', 'Strategic plan approved.');
    }

    public function edit(StrategicPlan $plan)
    {
        $this->authorize('update', $plan);

        $plan->load('goals');

        return Inertia::render('Governance/Strategy/Edit', [
            'plan' => $plan,
        ]);
    }

    public function createVersion(Request $request, StrategicPlan $plan)
    {
        $this->authorize('createVersion', $plan);

        $validated = $request->validate([
            'version_notes' => 'required|string|max:500',
        ]);

        $newPlan = $plan->createNewVersion($validated['version_notes'], auth()->id());

        return redirect()->route('governance.strategy.show', $newPlan)
            ->with('success', 'New version created (v'.$newPlan->version_number.').');
    }

    public function changes(StrategicPlan $plan)
    {
        $this->authorize('viewChanges', $plan);

        $changeData = $plan->getChangesSinceLastSnapshot();

        return Inertia::render('Governance/Strategy/Changes', [
            'plan' => $plan->load('goals'),
            'changes' => $changeData,
        ]);
    }
}
