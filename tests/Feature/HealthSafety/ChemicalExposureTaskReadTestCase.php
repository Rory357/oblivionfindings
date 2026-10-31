<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ChemicalExposureFollowup;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\Tasks\Providers\ChemicalExposureFollowupProvider;
use App\Services\Tasks\TaskAggregator;

/** Real private source commands; no notification send or injected production provider registry. UNRUN. */
abstract class ChemicalExposureTaskReadTestCase extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;

    protected function assignedWork(?ClientIncident $incident = null, ?User $receiver = null, ?SubstanceExposureRecord $root = null, array $facts = []): array
    {
        $receiver ??= $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        if ($incident !== null) {
            foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($receiver, $key); $this->allow($this->manager, $key); }
        }
        $root ??= $this->exposure(['symptoms' => 'PRIVATE-TASK-MEDICAL-BODY', 'related_incident_id' => $incident?->id]);
        $receipt = $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root, [
            'assigned_to' => $receiver->id, 'instruction' => 'PRIVATE-TASK-NAMED-INSTRUCTION', 'due_at' => '2026-10-13T01:00:00Z', ...$facts,
        ]));
        $child = ChemicalExposureFollowup::findOrFail($receipt['source_id']);
        $delivery = ChemicalExposureSourceDelivery::where('kind', 'followup')->where('source_version_id', $receipt['source_version_id'])->sole();
        return [$root->fresh(), $receiver, $child, $delivery, $receipt];
    }

    protected function ownTasks(): TaskAggregator { return new TaskAggregator([new ChemicalExposureFollowupProvider]); }

    protected function linkedIncident(): ClientIncident
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        return ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $this->site->id, 'client_id' => $client->id, 'shift_id' => null, 'service_context_id' => null,
            'respite_stay_id' => null, 'is_hr_confidential' => false, 'reported_by' => $this->manager->id,
        ]));
    }

    protected function sealedLegacy(User $receiver): array
    {
        $incident = $this->linkedIncident();
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($receiver, $key); }
        $root = SubstanceExposureRecord::create(['hazardous_substance_id' => $this->catalogue()->id, 'user_id' => $this->worker->id,
            'site_id' => $this->site->id, 'exposed_at' => '2026-10-12T00:00:00Z', 'exposure_type' => 'skin_contact',
            'related_incident_id' => $incident->id, 'created_by' => $this->manager->id, 'symptoms' => 'PRIVATE-SEALED-LEGACY-BODY']);
        $child = ChemicalExposureFollowup::create(['exposure_id' => $root->id, 'source_schema_version' => 0, 'source_revision' => 0,
            'assigned_to' => $receiver->id, 'assignee_snapshot' => [], 'instruction' => 'PRIVATE-SEALED-LEGACY-CHILD',
            'due_at' => '2026-10-13T01:00:00Z', 'recorded_by' => $this->manager->id]);
        return [$root, $child]; // Genuine schema0 fixture; no invented accepted version/audit/receipt.
    }
}
