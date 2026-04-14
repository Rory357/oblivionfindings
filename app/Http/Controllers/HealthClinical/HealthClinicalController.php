<?php

namespace App\Http\Controllers\HealthClinical;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClinicalEvent;
use App\Models\ClinicalObservation;
use App\Models\ClinicalProtocol;
use App\Models\User;
use App\Services\HealthClinical\ClinicalEventService;
use App\Services\HealthClinical\ClinicalObservationService;
use App\Services\HealthClinical\HealthSummaryService;
use App\Services\HealthClinical\ProtocolService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HealthClinicalController extends Controller
{
    public function __construct(
        private readonly ClinicalObservationService $observationService,
        private readonly ClinicalEventService $eventService,
        private readonly ProtocolService $protocolService,
        private readonly HealthSummaryService $summaryService,
    ) {}

    // ── Dashboard ──────────────────────────────────────────────────────

    public function dashboard(Request $request): \Inertia\Response
    {
        $observationStats = $this->observationService->dashboardStats();
        $eventStats = $this->eventService->dashboardStats();
        $protocolStats = $this->protocolService->dashboardStats();

        $recentObservations = ClinicalObservation::query()
            ->with(['client:id,first_name,last_name', 'recorder:id,name'])
            ->orderByDesc('recorded_at')
            ->limit(10)
            ->get();

        $recentEvents = ClinicalEvent::query()
            ->with(['client:id,first_name,last_name', 'reporter:id,name'])
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        return Inertia::render('health-clinical/Dashboard', [
            'observation_stats' => $observationStats,
            'event_stats' => $eventStats,
            'protocol_stats' => $protocolStats,
            'recent_observations' => $recentObservations,
            'recent_events' => $recentEvents,
            'observation_types' => ClinicalObservation::TYPE_LABELS,
            'event_types' => ClinicalEvent::TYPE_LABELS,
        ]);
    }

    // ── Observations ───────────────────────────────────────────────────

    public function observations(Request $request): \Inertia\Response
    {
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'observation_type' => ['nullable', 'string', 'in:' . implode(',', ClinicalObservation::TYPES)],
            'recorded_by' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
        ]);

        $observations = $this->observationService->list($filters);

        $clients = Client::query()
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        $staff = User::query()
            ->whereHas('roles')
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('health-clinical/Observations', [
            'observations' => $observations,
            'filters' => $filters,
            'clients' => $clients,
            'staff' => $staff,
            'observation_types' => ClinicalObservation::TYPE_LABELS,
        ]);
    }

    public function storeObservation(Request $request): \Illuminate\Http\RedirectResponse
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('clinical.observations.record'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'clinical_protocol_id' => ['nullable', 'integer', 'exists:clinical_protocols,id'],
            'observation_type' => ['required', 'string', 'in:' . implode(',', ClinicalObservation::TYPES)],
            'data' => ['required', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'recorded_at' => ['nullable', 'date'],
        ]);

        $this->observationService->record($data, $auth);

        return back()->with('success', 'Observation recorded.');
    }

    // ── Events ─────────────────────────────────────────────────────────

    public function events(Request $request): \Inertia\Response
    {
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'event_type' => ['nullable', 'string', 'in:' . implode(',', ClinicalEvent::TYPES)],
            'severity' => ['nullable', 'string', 'in:low,medium,high,critical'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'needs_follow_up' => ['nullable', 'boolean'],
        ]);

        $events = $this->eventService->list($filters);

        $clients = Client::query()
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return Inertia::render('health-clinical/Events', [
            'events' => $events,
            'filters' => $filters,
            'clients' => $clients,
            'event_types' => ClinicalEvent::TYPE_LABELS,
        ]);
    }

    public function storeEvent(Request $request): \Illuminate\Http\RedirectResponse
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('clinical.events.record'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'shift_id' => ['nullable', 'integer', 'exists:shifts,id'],
            'event_type' => ['required', 'string', 'in:' . implode(',', ClinicalEvent::TYPES)],
            'severity' => ['required', 'string', 'in:low,medium,high,critical'],
            'occurred_at' => ['required', 'date'],
            'description' => ['required', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
            'follow_up_required' => ['nullable', 'boolean'],
            'linked_observation_id' => ['nullable', 'integer', 'exists:clinical_observations,id'],
        ]);

        $this->eventService->record($data, $auth);

        return back()->with('success', 'Clinical event recorded.');
    }

    // ── Protocols ──────────────────────────────────────────────────────

    public function protocols(Request $request): \Inertia\Response
    {
        $protocols = ClinicalProtocol::query()
            ->with(['client:id,first_name,last_name', 'creator:id,name'])
            ->when($request->input('client_id'), fn ($q, $id) => $q->where('client_id', $id))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('observation_type'), fn ($q, $t) => $q->where('observation_type', $t))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $clients = Client::query()
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name']);

        return Inertia::render('health-clinical/Protocols', [
            'protocols' => $protocols,
            'filters' => $request->only(['client_id', 'status', 'observation_type']),
            'clients' => $clients,
            'observation_types' => ClinicalObservation::TYPE_LABELS,
        ]);
    }

    public function storeProtocol(Request $request): \Illuminate\Http\RedirectResponse
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('clinical.protocols.manage'), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'observation_type' => ['required', 'string', 'in:' . implode(',', ClinicalObservation::TYPES)],
            'frequency' => ['required', 'string', 'in:' . implode(',', ClinicalProtocol::FREQUENCIES)],
            'custom_interval_days' => ['nullable', 'integer', 'min:1', 'max:365', 'required_if:frequency,custom'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'next_due_at' => ['nullable', 'date'],
            'schedules' => ['nullable', 'array'],
            'schedules.*.day_of_week' => ['nullable', 'string', 'in:mon,tue,wed,thu,fri,sat,sun'],
            'schedules.*.preferred_time' => ['nullable', 'date_format:H:i'],
        ]);

        $this->protocolService->create($data, $auth);

        return back()->with('success', 'Protocol created.');
    }

    public function updateProtocol(Request $request, ClinicalProtocol $protocol): \Illuminate\Http\RedirectResponse
    {
        $auth = $request->user();
        abort_unless($auth && $auth->canDo('clinical.protocols.manage'), 403);

        $data = $request->validate([
            'status' => ['nullable', 'string', 'in:active,paused,completed'],
            'frequency' => ['nullable', 'string', 'in:' . implode(',', ClinicalProtocol::FREQUENCIES)],
            'custom_interval_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $protocol->update(array_filter($data, fn ($v) => $v !== null));

        return back()->with('success', 'Protocol updated.');
    }

    // ── Health Summary (per-client) ────────────────────────────────────

    public function clientSummary(Request $request, Client $client): \Inertia\Response
    {
        $auth = $request->user();
        abort_unless(
            $auth && (
                $auth->canDo('clinical.observations.viewAny')
                || $auth->canDo('clinical.observations.viewAssigned')
            ),
            403
        );

        if (! $auth->canDo('clinical.observations.viewAny')) {
            $this->authorize('view', $client);
        }

        $summary = $this->summaryService->forClient($client->id);

        return Inertia::render('health-clinical/ClientSummary', [
            'client' => $client->only(['id', 'first_name', 'last_name']),
            'summary' => $summary,
            'observation_types' => ClinicalObservation::TYPE_LABELS,
            'event_types' => ClinicalEvent::TYPE_LABELS,
        ]);
    }
}
