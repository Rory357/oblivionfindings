<?php

namespace App\Services\Medication\Followups;

use App\Models\ClientMedicationAdministration;
use App\Services\Medication\MedicationScopeDecision;
use App\Services\Medication\MedicationScopeDecisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Existing effect routes use the canonical workflow; they own no completion state. */
final class LegacyEffectFollowupAdapter
{
    public function save(Request $request, MedicationScopeDecisionService $scopeService): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor?->canDo('medications.administer.record'), 403);
        $id = filter_var($request->input('client_medication_administration_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_unless($id !== false, 404);
        $source = new ClientMedicationAdministration;
        $source->setAttribute($source->getKeyName(), (int) $id);
        // Preserve the existing source's activity and clinical scope checks,
        // then release them before canonical transition takes its full lock order.
        $scopeService->forPrnEffectiveness($actor, $source, now(), static function (MedicationScopeDecision $scope): void {
            abort_if($scope->medication->controlled_drug && ! $scope->performer->canDo('medications.controlled.record'), 404);
        });
        $data = $request->validate([
            'request_uuid' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:1'],
            'effectiveness' => ['required', 'in:effective,partially_effective,not_effective'],
            'review_minutes_after' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'escalation_needed' => ['nullable', 'boolean'], 'told' => ['nullable', 'string', 'max:255'],
            'escalation_action' => ['nullable', 'string', 'max:2000'],
        ]);
        $work = app(MedicationFollowupService::class);
        $row = $work->prepareAdministration($actor, (int) $id);
        $prior = $row->events()->where('request_uuid', $data['request_uuid'])->first();
        $data['action'] = $prior?->action ?? ($row->completed_at ? 'amend_effect' : 'effect');
        $data['outcome'] = $data['effectiveness'];
        unset($data['effectiveness']);
        $work->transition($actor, (int) $row->id, $data);

        return back()->with('success', 'Medication effect follow-up saved.');
    }
}
