<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\CarePlan;
use App\Models\Client;
use Illuminate\Http\Request;

class CarePlanController extends Controller
{
    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.viewAny'), 403);

        $data = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'status' => ['nullable', 'string', 'in:draft,active,review,archived'],
            'plan_type' => ['nullable', 'string'],
            'review_due' => ['nullable', 'boolean'],
        ]);

        $carePlans = CarePlan::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name', 'creator:id,name'])
            ->withCount('goals')
            ->when(!empty($data['client_id']), fn ($q) => $q->where('client_id', $data['client_id']))
            ->when(!empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(!empty($data['plan_type']), fn ($q) => $q->where('plan_type', $data['plan_type']))
            ->when(!empty($data['review_due']), fn ($q) => $q->reviewDue())
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->withQueryString();

        $clients = Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return inertia('operations/care-plans/Index', [
            'carePlans' => $carePlans,
            'clients' => $clients,
            'filters' => $request->only(['client_id', 'status', 'plan_type', 'review_due']),
        ]);
    }

    public function create(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.create'), 403);

        $clients = Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return inertia('operations/care-plans/Create', [
            'clients' => $clients,
        ]);
    }

    public function store(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.create'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'title' => ['required', 'string', 'max:255'],
            'plan_type' => ['required', 'string', 'max:100'],
            'content' => ['nullable', 'array'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'next_review_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:draft,active,review,archived'],
        ]);

        $carePlan = CarePlan::create([
            'organization_id' => $auth->organization_id,
            'client_id' => $data['client_id'],
            'title' => $data['title'],
            'plan_type' => $data['plan_type'],
            'content' => $data['content'] ?? null,
            'starts_at' => $data['starts_at'] ?? null,
            'ends_at' => $data['ends_at'] ?? null,
            'next_review_at' => $data['next_review_at'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'created_by' => $auth->id,
            'version' => 1,
        ]);

        return redirect()->route('operations.care_plans.show', $carePlan)
            ->with('success', 'Care plan created.');
    }

    public function show(Request $request, $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.view'), 403);

        $carePlan = CarePlan::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with([
                'client:id,first_name,last_name',
                'creator:id,name',
                'reviewer:id,name',
                'goals' => fn ($q) => $q->orderBy('priority')->orderBy('title'),
                'goals.progressNotes' => fn ($q) => $q->latest()->limit(5),
            ])
            ->withCount([
                'goals',
                'goals as goals_completed' => fn ($q) => $q->where('status', 'completed'),
                'goals as goals_in_progress' => fn ($q) => $q->where('status', 'in_progress'),
            ])
            ->findOrFail($carePlan);

        $progressStats = [
            'total_goals' => $carePlan->goals_count,
            'completed' => $carePlan->goals_completed,
            'in_progress' => $carePlan->goals_in_progress,
            'average_progress' => $carePlan->goals->count() > 0
                ? round($carePlan->goals->avg('progress_percentage'), 1)
                : 0,
        ];

        return inertia('operations/care-plans/Show', [
            'care_plan' => $carePlan,
            'progressStats' => $progressStats,
        ]);
    }

    public function edit(Request $request, $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.edit'), 403);

        $carePlan = CarePlan::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->with(['client:id,first_name,last_name'])
            ->findOrFail($carePlan);

        $clients = Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return inertia('operations/care-plans/Edit', [
            'care_plan' => $carePlan,
            'clients' => $clients,
        ]);
    }

    public function update(Request $request, $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.edit'), 403);

        $carePlan = CarePlan::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($carePlan);

        $data = $request->validate([
            'client_id' => ['sometimes', 'required', 'integer', 'exists:clients,id'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'plan_type' => ['sometimes', 'required', 'string', 'max:100'],
            'content' => ['nullable', 'array'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'next_review_at' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:draft,active,review,archived'],
        ]);

        $carePlan->update($data);

        return redirect()->route('operations.care_plans.show', $carePlan)
            ->with('success', 'Care plan updated.');
    }

    public function destroy(Request $request, $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.delete'), 403);

        $carePlan = CarePlan::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($carePlan);

        $carePlan->delete();

        return redirect()->route('operations.care_plans.index')
            ->with('success', 'Care plan deleted.');
    }
}
