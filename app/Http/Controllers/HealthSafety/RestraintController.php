<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\BehaviourSupportPlan;
use App\Models\Client;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RestraintController extends Controller
{
    /**
     * List restraint events and behaviour support plans.
     */
    public function index(Request $request): \Inertia\Response
    {
        $filters = $request->only(['client_id', 'site_id', 'restraint_type', 'severity', 'from', 'to']);

        $events = RestraintEvent::with([
                'client:id,first_name,last_name',
                'site:id,name',
                'behaviourSupportPlan:id,title',
            ])
            ->when(!empty($filters['client_id']), fn ($q) => $q->where('client_id', $filters['client_id']))
            ->when(!empty($filters['site_id']), fn ($q) => $q->where('site_id', $filters['site_id']))
            ->when(!empty($filters['restraint_type']), fn ($q) => $q->where('restraint_type', $filters['restraint_type']))
            ->when(!empty($filters['severity']), fn ($q) => $q->where('severity', $filters['severity']))
            ->when(!empty($filters['from']), fn ($q) => $q->where('started_at', '>=', $filters['from']))
            ->when(!empty($filters['to']), fn ($q) => $q->where('started_at', '<=', $filters['to']))
            ->orderByDesc('started_at')
            ->paginate(25)
            ->withQueryString();

        $plans = BehaviourSupportPlan::with('client:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->get();

        // Stats
        $thirtyDaysAgo = Carbon::now()->subDays(30);
        $events30d = RestraintEvent::where('started_at', '>=', $thirtyDaysAgo)->count();
        $activePlans = BehaviourSupportPlan::where('status', 'active')->count();
        $reviewsDue = BehaviourSupportPlan::where('status', 'active')
            ->whereNotNull('review_date')
            ->where('review_date', '<=', Carbon::now()->addDays(30))
            ->count();

        return Inertia::render('health-safety/restraints/index', [
            'events' => $events,
            'plans' => $plans,
            'filters' => $filters,
            'stats' => [
                'events_30d' => $events30d,
                'active_plans' => $activePlans,
                'reviews_due' => $reviewsDue,
            ],
            'clients' => Client::select('id', 'first_name', 'last_name')->orderBy('last_name')->get(),
            'sites' => Site::select('id', 'name')->where('is_active', true)->orderBy('name')->get(),
            'staff' => User::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Create a restraint event.
     */
    public function storeEvent(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'behaviour_support_plan_id' => 'nullable|exists:behaviour_support_plans,id',
            'site_id' => 'nullable|exists:sites,id',
            'started_at' => 'required|date',
            'ended_at' => 'nullable|date|after:started_at',
            'duration_minutes' => 'nullable|integer|min:0',
            'restraint_type' => 'required|in:physical,chemical,mechanical,seclusion,environmental',
            'severity' => 'required|in:low,medium,high',
            'trigger_description' => 'required|string',
            'de_escalation_attempted' => 'required|string',
            'restraint_description' => 'required|string',
            'staff_involved' => 'nullable|array',
            'person_response' => 'nullable|string',
            'post_incident_support' => 'nullable|string',
            'injury_occurred' => 'boolean',
            'injury_details' => 'nullable|string|required_if:injury_occurred,true',
            'within_support_plan' => 'boolean',
            'deviation_reason' => 'nullable|string|required_if:within_support_plan,false',
            'authorised_by' => 'nullable|exists:users,id',
            'related_incident_id' => 'nullable|exists:client_incidents,id',
        ]);

        $validated['created_by'] = $request->user()->id;

        RestraintEvent::create($validated);

        return redirect()->route('health-safety.restraints.index')
            ->with('success', 'Restraint event recorded.');
    }

    /**
     * Update/review a restraint event.
     */
    public function updateEvent(Request $request, RestraintEvent $event): RedirectResponse
    {
        $validated = $request->validate([
            'reviewed_by' => 'nullable|exists:users,id',
            'reviewed_at' => 'nullable|date',
            'review_notes' => 'nullable|string',
            'lessons_learned' => 'nullable|string',
            'severity' => 'nullable|in:low,medium,high',
            'post_incident_support' => 'nullable|string',
        ]);

        $validated['updated_by'] = $request->user()->id;

        $event->update($validated);

        return redirect()->route('health-safety.restraints.index')
            ->with('success', 'Restraint event updated.');
    }

    /**
     * Create a behaviour support plan.
     */
    public function storePlan(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'title' => 'required|string|max:255',
            'triggers' => 'nullable|string',
            'de_escalation_strategies' => 'nullable|string',
            'approved_interventions' => 'nullable|string',
            'prohibited_interventions' => 'nullable|string',
            'restrictive_practice_type' => 'nullable|in:physical,chemical,mechanical,seclusion,environmental',
            'developed_by' => 'nullable|exists:users,id',
            'developed_at' => 'nullable|date',
            'review_date' => 'nullable|date',
            'status' => 'nullable|in:draft,active,under_review,archived',
            'notes' => 'nullable|string',
        ]);

        $validated['created_by'] = $request->user()->id;

        BehaviourSupportPlan::create($validated);

        return redirect()->route('health-safety.restraints.index')
            ->with('success', 'Behaviour support plan created.');
    }

    /**
     * Update a behaviour support plan.
     */
    public function updatePlan(Request $request, BehaviourSupportPlan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'triggers' => 'nullable|string',
            'de_escalation_strategies' => 'nullable|string',
            'approved_interventions' => 'nullable|string',
            'prohibited_interventions' => 'nullable|string',
            'restrictive_practice_type' => 'nullable|in:physical,chemical,mechanical,seclusion,environmental',
            'review_date' => 'nullable|date',
            'status' => 'nullable|in:draft,active,under_review,archived',
            'notes' => 'nullable|string',
        ]);

        $validated['updated_by'] = $request->user()->id;

        $plan->update($validated);

        return redirect()->route('health-safety.restraints.index')
            ->with('success', 'Behaviour support plan updated.');
    }
}
