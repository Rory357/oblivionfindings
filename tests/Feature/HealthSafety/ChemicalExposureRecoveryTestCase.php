<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\ChemicalExposureAssociationRecoveryService;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use App\Services\HealthSafety\ChemicalExposureLedger;
use Illuminate\Support\Str;

abstract class ChemicalExposureRecoveryTestCase extends ChemicalExposureCommandTestCase
{
    protected function recovery(): ChemicalExposureAssociationRecoveryService { return app(ChemicalExposureAssociationRecoveryService::class); }
    protected function legacy(): SubstanceExposureRecord
    {
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['site_id' => $this->site->id,
            'client_id' => $client->id, 'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'is_hr_confidential' => false]));
        return SubstanceExposureRecord::withoutEvents(fn () => SubstanceExposureRecord::create([
            'hazardous_substance_id' => $this->catalogue()->id, 'user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'related_incident_id' => $incident->id, 'created_by' => $this->reporter->id, 'exposed_at' => '2020-02-01 00:00:00',
            'exposure_type' => 'skin_contact', 'symptoms' => 'SEALED-ORIGINAL-MEDICAL', 'medical_attention_sought' => true,
            'medical_outcome' => 'SEALED-ORIGINAL-OUTCOME', 'circumstances' => 'SEALED-ORIGINAL-CIRCUMSTANCES']));
    }
    protected function reviewInput(array $facts = [], int $version = 0, ?string $key = null): array
    {
        return ['idempotency_key' => $key ?? (string) Str::uuid(), 'expected_version' => $version, 'decision' => 'unresolved',
            'reviewed_at' => '2026-10-12T01:00:00Z', 'reason' => 'Later explicit association review', ...$facts];
    }
    protected function selectedIncident(): ClientIncident
    {
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($this->manager, $key); }
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        return ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['site_id' => $this->site->id,
            'client_id' => $client->id, 'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'is_hr_confidential' => false]));
    }
    protected function noOldBody(array $queries): void
    {
        foreach ($queries as $query) {
            $sql = strtolower(str_replace(['`', '"'], '', $query['query']));
            $this->assertDoesNotMatchRegularExpression('/select\s+(?:substance_exposure_records\.)?\*\s+from\s+substance_exposure_records/', $sql);
            foreach (['symptoms', 'medical_outcome', 'medical_treatment', 'medical_attention_sought', 'circumstances', 'exposed_at'] as $field) {
                $this->assertStringNotContainsString($field, $sql);
            }
        }
    }
    /** Genuine retained pre-admission command foundation; not an authorised present-day full-body transport. */
    protected function historicalCorrection(SubstanceExposureRecord $root, array $facts): void
    {
        app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager, function ($current, $reads, $sites) use ($root, $facts) {
            $access = app(ChemicalExposureCommandAccess::class); $access->assertOperation($current, $reads, 'manage'); $access->site($root->site_id, $reads, $sites);
            $ledger = app(ChemicalExposureLedger::class); $before = $ledger->snapshot($root);
            if (isset($facts['original_context'])) { $this->exposureService()->assertOriginalContext($facts['original_context'], $current, $reads, $root->site_id); }
            $v = ['idempotency_key' => (string) Str::uuid(), 'expected_version' => $root->source_revision, 'reason' => 'Actual pre-policy legacy correction', ...$facts];
            $intent = $ledger->claim($current, $reads, $root->id, $root->hazardous_substance_id, 'correct_exposure', $v, $sites);
            $root->fill(array_diff_key($facts, ['original_context' => true]));
            $root->forceFill(['source_schema_version' => 1, 'source_revision' => $root->source_revision + 1,
                'original_context' => $facts['original_context'] ?? $root->original_context, 'updated_by' => $current->id]);
            $this->exposureService()->save($root);
            $version = $ledger->version($intent, $root, $root, 'exposure', $current, $reads, $before, $before, ['reason' => $v['reason']]);
            $ledger->accept($intent, $root, $root, 'exposure', $version, $current, $reads, true, $sites);
            return null;
        });
    }
}
