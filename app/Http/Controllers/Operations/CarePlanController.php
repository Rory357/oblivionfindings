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
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string'],
            'plan_type' => ['nullable', 'string'],
            'client_id' => ['nullable', 'integer'],
            'review_due' => ['nullable', 'boolean'],
        ]);

        $baseQuery = CarePlan::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id));

        // Stats
        $stats = [
            'total' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)->where('status', 'active')->count(),
            'review_due' => (clone $baseQuery)->where('status', 'active')
                ->where(function ($q) {
                    $q->whereNull('next_review_at')
                        ->orWhere('next_review_at', '<=', now());
                })->count(),
            'draft' => (clone $baseQuery)->where('status', 'draft')->count(),
            'in_review' => (clone $baseQuery)->where('status', 'review')->count(),
            'plans_without_goals' => (clone $baseQuery)->whereDoesntHave('goals')->where('status', '!=', 'archived')->count(),
            'overdue_goals' => \App\Models\CarePlanGoal::query()
                ->whereHas('carePlan', function ($q) use ($auth) {
                    $q->where('status', 'active')
                        ->when($auth->organization_id, fn ($q2) => $q2->where('organization_id', $auth->organization_id));
                })
                ->where('status', '!=', 'completed')
                ->whereNotNull('target_date')
                ->where('target_date', '<', now())
                ->count(),
        ];

        // Charts
        $plans_by_status = (clone $baseQuery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Filtered query for listing
        $carePlans = CarePlan::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->when(!empty($data['q']), fn ($q) => $q->where('title', 'like', '%' . $data['q'] . '%'))
            ->when(!empty($data['status']), fn ($q) => $q->where('status', $data['status']))
            ->when(!empty($data['plan_type']), fn ($q) => $q->where('plan_type', $data['plan_type']))
            ->when(!empty($data['client_id']), fn ($q) => $q->where('client_id', $data['client_id']))
            ->when(!empty($data['review_due']), fn ($q) => $q->where('status', 'active')->where(function ($q2) {
                $q2->whereNull('next_review_at')->orWhere('next_review_at', '<=', now());
            }))
            ->with(['client:id,first_name,last_name', 'creator:id,name'])
            ->withCount(['goals', 'goals as goals_achieved_count' => fn ($q) => $q->where('status', 'completed')])
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        $clients = \App\Models\Client::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->select('id', 'first_name', 'last_name')
            ->orderBy('last_name')
            ->get();

        return inertia('operations/care-plans/Index', [
            'carePlans' => $carePlans,
            'clients' => $clients,
            'filters' => $data,
            'stats' => $stats,
            'plans_by_status' => $plans_by_status,
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

        // Auto-complete onboarding step if from_onboarding
        if ($request->boolean('from_onboarding')) {
            $workflow = \App\Models\ClientOnboardingWorkflow::where('client_id', $data['client_id'])
                ->where('status', 'in_progress')
                ->first();

            if ($workflow) {
                $step = \App\Models\ClientOnboardingStep::where('workflow_id', $workflow->id)
                    ->where('step_name', 'Care Plan Created')
                    ->where('status', '!=', 'completed')
                    ->first();

                if ($step) {
                    $step->update([
                        'status' => 'completed',
                        'completed_at' => now(),
                        'completed_by' => $auth->id,
                        'notes' => 'Auto-completed: Care plan #' . $carePlan->id . ' created.',
                    ]);
                }
            }

            return redirect("/operations/clients/{$data['client_id']}?tab=onboarding")
                ->with('success', 'Care plan created and onboarding step completed.');
        }

        return redirect()->route('operations.care_plans.show', $carePlan)
            ->with('success', 'Care plan created.');
    }

    public function show(Request $request, $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.viewAny'), 403);

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

        // Progress notes linked to this plan's goals
        $progressNotes = \App\Models\ProgressNote::query()
            ->whereHas('goal', fn ($q) => $q->where('care_plan_id', $carePlan->id))
            ->with(['author:id,name', 'goal:id,title'])
            ->orderByDesc('created_at')
            ->get();

        // Review history via parent_id chain
        $reviewHistory = CarePlan::query()
            ->where(function ($q) use ($carePlan) {
                $q->where('parent_id', $carePlan->parent_id ?? $carePlan->id)
                    ->orWhere('id', $carePlan->parent_id ?? $carePlan->id);
            })
            ->where('id', '!=', $carePlan->id)
            ->with(['reviewer:id,name'])
            ->orderByDesc('version')
            ->get();

        // Staff in same org for reviewer assignment
        $staff = \App\Models\User::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return inertia('operations/care-plans/Show', [
            'care_plan' => $carePlan,
            'progressStats' => $progressStats,
            'progressNotes' => $progressNotes,
            'reviewHistory' => $reviewHistory,
            'staff' => $staff,
        ]);
    }

    public function edit(Request $request, $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.update'), 403);

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
        abort_unless($auth && $auth->canDo('care_plans.update'), 403);

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

    public function startReview(Request $request, CarePlan $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.update'), 403);

        // Create new version
        $newVersion = $carePlan->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
        $newVersion->version = $carePlan->version + 1;
        $newVersion->parent_id = $carePlan->parent_id ?? $carePlan->id;
        $newVersion->status = 'review';
        $newVersion->reviewed_at = null;
        $newVersion->reviewed_by = null;
        $newVersion->save();

        // Copy goals to new version
        foreach ($carePlan->goals as $goal) {
            $newGoal = $goal->replicate(['id', 'created_at', 'updated_at', 'deleted_at']);
            $newGoal->care_plan_id = $newVersion->id;
            $newGoal->save();
        }

        return redirect()->route('operations.care_plans.edit', $newVersion->id)
            ->with('success', 'Review started. Edit the plan and complete the review when ready.');
    }

    public function completeReview(Request $request, CarePlan $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.update'), 403);

        $data = $request->validate([
            'review_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Archive the parent version
        if ($carePlan->parent_id) {
            CarePlan::where('id', $carePlan->parent_id)->update(['status' => 'archived']);
        }

        // Activate this version
        $carePlan->update([
            'status' => 'active',
            'reviewed_at' => now(),
            'reviewed_by' => $auth->id,
            'next_review_at' => $carePlan->next_review_at ?? now()->addMonths(3),
        ]);

        return redirect()->route('operations.care_plans.show', $carePlan->id)
            ->with('success', 'Review completed. Plan is now active.');
    }

    public function destroy(Request $request, $carePlan)
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('care_plans.update'), 403);

        $carePlan = CarePlan::query()
            ->when($auth->organization_id, fn ($q) => $q->where('organization_id', $auth->organization_id))
            ->findOrFail($carePlan);

        $carePlan->delete();

        return redirect()->route('operations.care_plans.index')
            ->with('success', 'Care plan deleted.');
    }
}
