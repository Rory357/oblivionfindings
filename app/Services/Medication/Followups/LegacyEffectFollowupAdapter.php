<?php

namespace App\Services\Medication\Followups;

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
        $work = app(MedicationFollowupService::class);
        $work->assertAdministrationReadable($actor, (int) $id);
        // Canonical transition holds the source, current authority and any
        // emergency grant throughout the clinical write and audit receipt.
        $data = $request->validate([
            'request_uuid' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:1'],
            'effectiveness' => ['required', 'in:effective,partially_effective,not_effective'],
            'review_minutes_after' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'escalation_needed' => ['nullable', 'boolean'], 'told' => ['nullable', 'string', 'max:255'],
            'escalation_action' => ['nullable', 'string', 'max:2000'],
        ]);
        $row = $work->prepareAdministration($actor, (int) $id);
        $prior = $row->events()->where('request_uuid', $data['request_uuid'])->first();
        $data['action'] = $prior?->action ?? ($row->completed_at ? 'amend_effect' : 'effect');
        $data['outcome'] = $data['effectiveness'];
        unset($data['effectiveness']);
        $work->transition($actor, (int) $row->id, $data);

        return back()->with('success', 'Medication effect follow-up saved.');
    }
}
