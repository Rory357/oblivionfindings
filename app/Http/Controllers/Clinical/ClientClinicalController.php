<?php

namespace App\Http\Controllers\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Services\ClinicalEventService;
use App\Domain\Clinical\Services\ClinicalObservationService;
use App\Http\Controllers\Clinical\Concerns\RecordsClinicalRecords;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;

class ClientClinicalController extends Controller
{
    use RecordsClinicalRecords;

    public function __construct(
        protected ClinicalObservationService $observationService,
        protected ClinicalEventService $eventService,
    ) {}

    /**
     * List observations for a client (paginated, filterable).
     */
    public function observations(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $query = ClinicalObservation::query()
            ->forClient($client->id)
            ->with('recorder:id,name')
            ->orderByDesc('recorded_at');

        if ($request->filled('type')) {
            $type = ObservationType::tryFrom($request->input('type'));
            if ($type) {
                $query->ofType($type);
            }
        }

        $observations = $query->paginate(20)->through(fn (ClinicalObservation $obs) => [
            'id' => $obs->id,
            'observation_type' => $obs->observation_type->value,
            'observation_type_label' => $obs->observation_type->label(),
            'recorded_at' => $obs->recorded_at->toISOString(),
            'data' => $obs->data,
            'notes' => $obs->notes,
            'is_flagged' => $obs->is_flagged,
            'recorder' => $obs->recorder ? ['id' => $obs->recorder->id, 'name' => $obs->recorder->name] : null,
            'shift_id' => $obs->shift_id,
        ]);

        return response()->json($observations);
    }

    /**
     * Store a new observation for a client from Client Profile context.
     */
    public function store(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $user = $request->user();
        $validated = $this->validateObservationInput($request, $user);
        $type = ObservationType::from($validated['observation_type']);

        $observation = $this->observationService->record($client, $user, $validated);

        if ($request->wantsJson()) {
            return response()->json([
                'id' => $observation->id,
                'observation_type' => $observation->observation_type->value,
                'recorded_at' => $observation->recorded_at->toISOString(),
            ], 201);
        }

        return back()->with('success', $type->label() . ' recorded successfully.');
    }

    /**
     * Store a new clinical event for a client from Client Profile context.
     */
    public function storeEvent(Request $request, Client $client)
    {
        $this->authorize('view', $client);

        $user = $request->user();
        $validated = $this->validateClinicalEventInput($request, $user);

        $event = $this->eventService->record($client, $user, $validated);
        $this->saveClinicalAttachments($request, $event);

        if ($request->wantsJson()) {
            return response()->json([
                'id' => $event->id,
                'event_type' => $event->event_type->value,
                'occurred_at' => $event->occurred_at->toISOString(),
                'requires_followup' => $event->requires_followup,
            ], 201);
        }

        return back()->with('success', 'Clinical event recorded successfully.');
    }
}
