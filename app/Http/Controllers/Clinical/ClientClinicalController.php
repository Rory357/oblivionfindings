<?php

namespace App\Http\Controllers\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Models\ClinicalObservation;
use App\Domain\Clinical\Services\ClinicalObservationService;
use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClientClinicalController extends Controller
{
    public function __construct(
        protected ClinicalObservationService $observationService,
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

        // Check base recording permission
        if (! $user->canDo('clinical.observations.record') && ! $user->canDo('clinical.observations.recordClinical')) {
            abort(403);
        }

        $validated = $request->validate([
            'observation_type' => ['required', Rule::in(array_column(ObservationType::cases(), 'value'))],
            'data' => ['present', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'recorded_at' => ['nullable', 'date'],
            'protocol_schedule_id' => ['nullable', 'integer', 'exists:clinical_protocol_schedules,id'],
        ]);

        $type = ObservationType::from($validated['observation_type']);

        // Check clinical permission for clinical types
        if ($type->requiresClinicalPermission() && ! $user->canDo('clinical.observations.recordClinical')) {
            abort(403, 'Clinical observation permission required for ' . $type->label());
        }

        try {
            $observation = $this->observationService->record($client, $user, $validated);
        } catch (ValidationException $e) {
            throw $e;
        }

        if ($request->wantsJson()) {
            return response()->json([
                'id' => $observation->id,
                'observation_type' => $observation->observation_type->value,
                'recorded_at' => $observation->recorded_at->toISOString(),
            ], 201);
        }

        return back()->with('success', $type->label() . ' recorded successfully.');
    }
}
