<?php

namespace App\Http\Controllers\Clinical;

use App\Domain\Clinical\Enums\BehaviourFunction;
use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Models\ClinicalEvent;
use App\Domain\Clinical\Services\ClinicalDashboardService;
use App\Domain\Clinical\Services\ClinicalEventService;
use App\Domain\Clinical\Services\ClinicalObservationService;
use App\Enums\AlertSeverity;
use App\Http\Controllers\Clinical\Concerns\RecordsClinicalRecords;
use App\Http\Controllers\Controller;
use App\Models\BehaviourAbcEntry;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HealthClinicalDashboardController extends Controller
{
    use RecordsClinicalRecords;

    public function __construct(
        private readonly ClinicalDashboardService $dashboardService,
        private readonly ClinicalObservationService $observationService,
        private readonly ClinicalEventService $eventService,
    ) {}

    public function index(Request $request): \Inertia\Response
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('clinical.dashboard'), 403);

        $kpis = $this->dashboardService->getKpis();

        return inertia('health-clinical/index', [
            'kpis' => $kpis,
            'tab_counts' => $this->dashboardService->getTabCounts($kpis),
            'deterioration_watch' => $this->dashboardService->getDeteriorationWatch(),
            'overdue_items' => $this->dashboardService->getOverdueItems(),
            'recent_events' => $this->dashboardService->getRecentEvents(),
            'recent_observations' => $this->dashboardService->getRecentObservations(),
        ]);
    }

    /**
     * Cross-client observation register — paginated, filterable.
     */
    public function observations(Request $request): \Inertia\Response
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('clinical.observations.viewAny'), 403);

        $filters = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'observation_type' => ['nullable', 'string', 'in:' . implode(',', array_column(ObservationType::cases(), 'value'))],
            'recorded_by' => ['nullable', 'integer', 'exists:users,id'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $observations = $this->dashboardService->getObservationRegister($filters);
        $stats = $this->dashboardService->getObservationRegisterStats();
        $kpis = $this->dashboardService->getKpis();

        return inertia('health-clinical/observations', [
            'observations' => $observations,
            'stats' => $stats,
            'kpis' => $kpis,
            'tab_counts' => $this->dashboardService->getTabCounts($kpis),
            'filters' => $filters,
            'filter_options' => [
                'clients' => Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
                'sites' => Site::query()->orderBy('name')->get(['id', 'name']),
                'staff' => User::query()->whereHas('roles')->orderBy('name')->get(['id', 'name']),
                'observation_types' => collect(ObservationType::cases())->map(fn ($t) => [
                    'value' => $t->value,
                    'label' => $t->label(),
                ])->values(),
            ],
        ]);
    }

    /**
     * Cross-client clinical event register — paginated, filterable.
     */
    public function events(Request $request): \Inertia\Response
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('clinical.events.viewAny'), 403);

        $filters = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'event_type' => ['nullable', 'string', 'in:' . implode(',', array_map(
                fn (ClinicalEventType $type) => $type->value,
                ClinicalEventType::cases()
            ))],
            'severity' => ['nullable', 'string', 'in:' . implode(',', AlertSeverity::ALL)],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'follow_up_status' => ['nullable', 'string', 'in:none,required,pending,completed'],
            'review_status' => ['nullable', 'string', 'in:reviewed,unreviewed'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $eventTypes = collect(ClinicalEventType::cases())->map(fn (ClinicalEventType $type) => [
            'value' => $type->value,
            'label' => $type->label(),
        ])->values();

        $kpis = $this->dashboardService->getKpis();

        return inertia('health-clinical/Events', [
            'events' => $this->dashboardService->getEventRegister($filters),
            'stats' => $this->dashboardService->getEventRegisterStats(),
            'kpis' => $kpis,
            'tab_counts' => $this->dashboardService->getTabCounts($kpis),
            'filters' => $filters,
            'filter_options' => [
                'clients' => Client::query()->orderBy('first_name')->get(['id', 'first_name', 'last_name']),
                'sites' => Site::query()->orderBy('name')->get(['id', 'name']),
                'event_types' => $eventTypes,
                'severities' => collect(AlertSeverity::ALL)->map(fn (string $severity) => [
                    'value' => $severity,
                    'label' => ucfirst($severity),
                ])->values(),
                'follow_up_statuses' => collect([
                    ['value' => 'required', 'label' => 'Follow-up required'],
                    ['value' => 'pending', 'label' => 'Pending follow-up'],
                    ['value' => 'completed', 'label' => 'Follow-up complete'],
                    ['value' => 'none', 'label' => 'No follow-up'],
                ]),
                'review_statuses' => collect([
                    ['value' => 'unreviewed', 'label' => 'Unreviewed'],
                    ['value' => 'reviewed', 'label' => 'Reviewed'],
                ]),
            ],
            'event_types' => $eventTypes->pluck('label', 'value'),
        ]);
    }

    /**
     * Cross-client Behaviour (ABC) register — paginated, filterable.
     */
    public function behaviour(Request $request): \Inertia\Response
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('clinical.behaviour.viewAny'), 403);

        $filters = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'behaviour_function' => ['nullable', 'string', Rule::in(array_column(BehaviourFunction::cases(), 'value'))],
            'intensity' => ['nullable', 'string', 'in:low,medium,high'],
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $entries = $this->dashboardService->getBehaviourRegister($filters)
            ->through(fn (BehaviourAbcEntry $e) => [
                'id' => $e->id,
                'occurred_at' => $e->occurred_at?->toISOString(),
                'setting' => $e->setting,
                'antecedent' => $e->antecedent,
                'behaviour' => $e->behaviour,
                'consequence' => $e->consequence,
                'behaviour_tags' => $e->behaviour_tags ?? [],
                'behaviour_function' => $e->behaviour_function?->value,
                'behaviour_function_label' => $e->behaviour_function?->label(),
                'intensity' => $e->intensity,
                'duration_seconds' => $e->duration_seconds,
                'harm_occurred' => $e->harm_occurred,
                'escalated' => $e->escalated,
                'requires_followup' => $e->requires_followup,
                'followup_completed' => $e->followup_completed_at !== null,
                'client' => $e->client ? [
                    'id' => $e->client->id,
                    'first_name' => $e->client->first_name,
                    'last_name' => $e->client->last_name,
                    'site' => $e->client->site?->name,
                ] : null,
                'recorder' => $e->recorder ? ['id' => $e->recorder->id, 'name' => $e->recorder->name] : null,
            ]);

        $kpis = $this->dashboardService->getKpis();

        return inertia('health-clinical/Behaviour', [
            'entries' => $entries,
            'stats' => $this->dashboardService->getBehaviourRegisterStats(),
            'filters' => $filters,
            'filter_options' => $this->dashboardService->getBehaviourFilterOptions(),
            'kpis' => $kpis,
            'tab_counts' => $this->dashboardService->getTabCounts($kpis),
        ]);
    }

    /**
     * Record an observation from the module (no client in the URL — the wizard
     * supplies `client_id`). Routes through the canonical Domain service via the
     * shared trait, so the module and client-profile entry points never drift.
     */
    public function storeObservation(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate(['client_id' => ['required', 'integer', 'exists:clients,id']]);
        $client = Client::findOrFail($request->integer('client_id'));
        $this->authorize('view', $client);

        $validated = $this->validateObservationInput($request, $user);
        $type = ObservationType::from($validated['observation_type']);

        $this->observationService->record($client, $user, $validated);

        return back()->with('success', $type->label().' recorded successfully.');
    }

    /**
     * Record a clinical event from the module (no client in the URL — the wizard
     * supplies `client_id`). High-severity falls/seizures/choking auto-link to an
     * H&S event inside ClinicalEventService::record().
     */
    public function storeEvent(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate(['client_id' => ['required', 'integer', 'exists:clients,id']]);
        $client = Client::findOrFail($request->integer('client_id'));
        $this->authorize('view', $client);

        $validated = $this->validateClinicalEventInput($request, $user);

        $event = $this->eventService->record($client, $user, $validated);
        $this->saveClinicalAttachments($request, $event);

        return back()->with('success', 'Clinical event recorded successfully.');
    }

    /**
     * Debounced client search backing the record-wizard pickers (name / preferred
     * name / NHI). NHI search matters for nurses. JSON.
     */
    public function clientSearch(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && (
            $user->canDo('clinical.observations.viewAny')
            || $user->canDo('clinical.observations.viewAssigned')
            || $user->canDo('clinical.observations.record')
            || $user->canDo('clinical.observations.recordClinical')
        ), 403);

        $q = trim((string) $request->input('q', ''));

        $clients = Client::query()
            ->when($q !== '', fn ($query) => $query->where(function ($sub) use ($q) {
                $sub->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('preferred_name', 'like', "%{$q}%");

                // nhi_number is encrypted at rest — match the full NHI via its hash.
                if (Client::validateNhi($q)) {
                    $sub->orWhere('nhi_hash', Client::nhiHash($q));
                }
            }))
            ->with('site:id,name')
            ->orderBy('first_name')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name', 'preferred_name', 'nhi_number', 'site_id'])
            ->map(fn (Client $c) => [
                'id' => $c->id,
                'name' => trim("{$c->first_name} {$c->last_name}"),
                'preferred_name' => $c->preferred_name,
                'nhi' => $c->nhi_number,
                'site' => $c->site?->name,
            ]);

        return response()->json(['clients' => $clients]);
    }

    /**
     * The live clinical card (allergies, baseline vitals + NEWS2, active protocols)
     * shown in a record wizard's rail once a client is chosen. JSON.
     */
    public function clinicalCard(Request $request, Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        $user = $request->user();
        abort_unless($user && (
            $user->canDo('clinical.observations.viewAny')
            || $user->canDo('clinical.observations.viewAssigned')
            || $user->canDo('clinical.observations.record')
            || $user->canDo('clinical.observations.recordClinical')
        ), 403);

        return response()->json($this->dashboardService->getClinicalCard($client));
    }

    /**
     * Review & sign off a clinical event. Permission gated by route middleware.
     */
    public function reviewEvent(Request $request, ClinicalEvent $event): RedirectResponse
    {
        $this->eventService->review($event, $request->user());

        return back()->with('success', 'Clinical event reviewed and signed off.');
    }

    /**
     * Mark a clinical event's follow-up complete.
     */
    public function completeEventFollowup(Request $request, ClinicalEvent $event): RedirectResponse
    {
        $this->eventService->completeFollowup($event, $request->user());

        return back()->with('success', 'Follow-up marked complete.');
    }

    /**
     * Escalate a clinical event to on-call clinical leadership.
     */
    public function escalateEvent(Request $request, ClinicalEvent $event): RedirectResponse
    {
        $this->eventService->escalate($event, $request->user());

        return back()->with('success', 'Clinical event escalated to on-call leadership.');
    }
}
