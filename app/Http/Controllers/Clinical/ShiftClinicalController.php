<?php

namespace App\Http\Controllers\Clinical;

use App\Domain\Clinical\Enums\ObservationType;
use App\Domain\Clinical\Services\ClinicalObservationService;
use App\Domain\Clinical\Services\ClinicalProtocolService;
use App\Http\Controllers\Controller;
use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShiftClinicalController extends Controller
{
    public function __construct(
        protected ClinicalObservationService $observationService,
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
            'observation_type' => ['required', Rule::in(array_column(ObservationType::cases(), 'value'))],
            'data' => ['present', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'recorded_at' => ['nullable', 'date'],
            'protocol_schedule_id' => ['nullable', 'integer', 'exists:clinical_protocol_schedules,id'],
        ]);

        $type = ObservationType::from($validated['observation_type']);

        if ($type->requiresClinicalPermission() && ! $user->canDo('clinical.observations.recordClinical')) {
            abort(403, 'Clinical observation permission required for ' . $type->label());
        }

        $shift->loadMissing('client');

        if (! $shift->client) {
            abort(422, 'Shift has no associated client.');
        }

        try {
            $observation = $this->observationService->record(
                $shift->client,
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

        return back()->with('success', $type->label() . ' recorded successfully.');
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
