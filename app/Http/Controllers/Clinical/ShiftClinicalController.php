<?php

namespace App\Http\Controllers\Clinical;

use App\Domain\Clinical\Enums\ClinicalEventType;
use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Services\ClinicalEventService;
use App\Domain\Clinical\Services\ClinicalObservationService;
use App\Domain\Clinical\Services\ClinicalProtocolService;
use App\Enums\AlertSeverity;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShiftClinicalController extends Controller
{
    public function __construct(
        protected ClinicalObservationService $observationService,
        protected ClinicalEventService $eventService,
        protected ClinicalProtocolService $protocolService,
    ) {}

    /**
     * Get observations due for a shift's client.
     */
    public function dueObservations(Request $request, Shift $shift)
    {
        $this->authorizeShiftAccess($request, $shift);

        $due = $this->protocolService->getDueForShift($shift);

        return response()->json([
            'items' => $due->map(fn (array $item) => [
                'protocol_id' => $item['protocol']->id,
                'protocol_name' => $item['protocol']->name,
                'observation_type' => $item['protocol']->observation_type->value,
                'observation_type_label' => $item['protocol']->observation_type->label(),
                'instructions' => $item['protocol']->instructions,
                'schedule_id' => $item['schedule']?->id,
                'due_at' => $item['schedule']?->due_at?->toISOString(),
                'is_overdue' => $item['schedule']?->isOverdue() ?? false,
            ])->values(),
        ]);
    }

    /**
     * Store an observation from shift context.
     */
    public function store(Request $request, Shift $shift)
    {
        $this->authorizeShiftAccess($request, $shift);

        $user = $request->user();

        if (! $user->canDo('clinical.observations.record') && ! $user->canDo('clinical.observations.recordClinical')) {
            abort(403);
        }

        $validated = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'observation_type' => ['required', Rule::in(array_column(ObservationType::cases(), 'value'))],
            'data' => ['present', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'recorded_at' => ['nullable', 'date'],
            'protocol_schedule_id' => ['nullable', 'integer', 'exists:clinical_protocol_schedules,id'],
        ]);

        $type = ObservationType::from($validated['observation_type']);

        if ($type->requiresClinicalPermission() && ! $user->canDo('clinical.observations.recordClinical')) {
            abort(403, 'Clinical observation permission required for '.$type->label());
        }

        $client = $this->resolveObservationClient($shift, $validated['client_id'] ?? null);

        try {
            $observation = $this->observationService->record(
                $client,
                $user,
                $validated,
                $shift,
            );
        } catch (ValidationException $e) {
            throw $e;
        }

        if ($request->wantsJson()) {
            return response()->json([
                'id' => $observation->id,
                'observation_type' => $observation->observation_type->value,
                'recorded_at' => $observation->recorded_at->toISOString(),
                'shift_id' => $observation->shift_id,
            ], 201);
        }

        return back()->with('success', $type->label().' recorded successfully.');
    }

    protected function resolveObservationClient(Shift $shift, ?int $clientId): Client
    {
        $shift->loadMissing('client');

        if ($clientId) {
            $client = Client::query()->findOrFail($clientId);

            if ((int) $client->id === (int) $shift->client_id) {
                return $client;
            }

            if ($shift->site_id && (int) $client->site_id === (int) $shift->site_id) {
                return $client;
            }

            throw ValidationException::withMessages([
                'client_id' => 'Select a resident attached to this shift or site.',
            ]);
        }

        if (! $shift->client) {
            abort(422, 'Shift has no associated client.');
        }

        return $shift->client;
    }

    /**
     * Store a clinical event from shift context.
     */
    public function storeEvent(Request $request, Shift $shift)
    {
        $this->authorizeShiftAccess($request, $shift);

        $user = $request->user();

        if (! $user->canDo('clinical.events.record')) {
            abort(403);
        }

        $validated = $request->validate([
            'event_type' => ['required', Rule::in(array_map(
                fn (ClinicalEventType $type) => $type->value,
                ClinicalEventType::cases(),
            ))],
            'severity' => ['required', Rule::in(AlertSeverity::ALL)],
            'occurred_at' => ['required', 'date'],
            'description' => ['required', 'string', 'max:5000'],
            'immediate_action_taken' => [
                Rule::requiredIf(fn () => ClinicalEventType::tryFrom((string) $request->input('event_type'))?->requiresImmediateAction() ?? false),
                'nullable',
                'string',
                'max:5000',
            ],
            'outcome' => ['nullable', 'string', 'max:5000'],
            'requires_followup' => ['nullable', 'boolean'],
            'followup_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $shift->loadMissing('client');

        if (! $shift->client) {
            abort(422, 'Shift has no associated client.');
        }

        $event = $this->eventService->record(
            $shift->client,
            $user,
            $validated,
            $shift,
        );

        if ($request->wantsJson()) {
            return response()->json([
                'id' => $event->id,
                'event_type' => $event->event_type->value,
                'occurred_at' => $event->occurred_at->toISOString(),
                'requires_followup' => $event->requires_followup,
                'shift_id' => $event->shift_id,
            ], 201);
        }

        return back()->with('success', 'Clinical event recorded successfully.');
    }

    /**
     * Mirror the shift access check used in ShiftController.
     */
    protected function authorizeShiftAccess(Request $request, Shift $shift): void
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('shifts.viewAny') || $auth->canDo('shifts.viewAssigned')), 403);

        if (! $auth->canDo('shifts.manageAny') && $shift->user_id !== $auth->id) {
            abort(403);
        }
    }
}
