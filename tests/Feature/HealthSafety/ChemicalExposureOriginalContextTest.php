<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\SubstanceExposureRecord;
use Illuminate\Support\Facades\DB;

final class ChemicalExposureOriginalContextTest extends ChemicalExposureCommandTestCase
{
    private function incident(array $facts = []): ClientIncident
    {
        foreach (['incidents.viewAny', 'clients.viewAny'] as $permission) { $this->allow($this->manager, $permission); }
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        return ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'is_hr_confidential' => false, ...$facts]);
    }

    public function test_prospective_absent_association_is_known_null_without_fabricating_external_context(): void
    {
        $root = $this->exposure(); $proof = $this->proof($root);
        foreach (['incident_id', 'client_id', 'shift_id', 'service_context_id', 'stay_id'] as $key) { $this->assertNull($proof[$key]); }
        foreach (['incident_binding', 'client_binding', 'shift_binding'] as $key) { $this->assertSame('absent', $proof[$key]); }
        $this->assertSame('recorded', $proof['context_binding']); $this->assertArrayNotHasKey('clinical_id', $proof);
        $this->assertArrayNotHasKey('injury_id', $proof); $this->assertArrayNotHasKey('first_aid_id', $proof);
    }

    public function test_explicit_current_incident_client_and_shift_tuple_is_retained_at_capture(): void
    {
        $incident = $this->incident();
        $shift = Shift::factory()->create(['client_id' => $incident->client_id, 'site_id' => $this->site->id]);
        $incident->update(['shift_id' => $shift->id]);
        $root = $this->exposure(['related_incident_id' => $incident->id, 'incident_reported' => true]);
        $proof = $this->proof($root);
        $this->assertSame($incident->id, $proof['incident_id']); $this->assertSame($incident->client_id, $proof['client_id']);
        $this->assertSame($shift->id, $proof['shift_id']); $this->assertSame('recorded', $proof['incident_binding']);
        $this->assertSame('recorded', $proof['client_binding']); $this->assertSame('recorded', $proof['shift_binding']);
        $this->assertSame($root->original_context, $root->fresh()->original_context);
    }

    public function test_legacy_linked_incident_does_not_backfill_todays_client_shift_or_service_context(): void
    {
        $incident = $this->incident();
        $root = SubstanceExposureRecord::factory()->create(['hazardous_substance_id' => $this->catalogue()->id,
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'related_incident_id' => $incident->id,
            'created_by' => $this->manager->id, 'source_schema_version' => 0, 'source_revision' => 0, 'original_context' => null]);
        [$proof, $queries] = $this->capturedQueries(fn () => $this->proof($root));
        $this->assertSame('legacy_unknown', $proof['attribution_state']);
        foreach (['incident_id', 'client_id', 'shift_id', 'service_context_id', 'stay_id'] as $field) { $this->assertNull($proof[$field]); }
        foreach (['incident_binding', 'client_binding', 'shift_binding', 'context_binding'] as $field) { $this->assertSame('unknown', $proof[$field]); }
        foreach ($queries as $q) { $this->assertStringNotContainsString('client_incidents', $q['query']); }
    }

    public function test_ordinary_legacy_text_correction_keeps_unknown_association_and_explicit_reassociation_retains_history(): void
    {
        $incident = $this->incident();
        $root = SubstanceExposureRecord::factory()->create(['hazardous_substance_id' => $this->catalogue()->id,
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'related_incident_id' => $incident->id,
            'created_by' => $this->manager->id, 'source_schema_version' => 0, 'source_revision' => 0, 'original_context' => null]);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Correct only narrative', 'circumstances' => 'Actual correction']));
        $this->assertSame('unknown', $this->proof($root)['context_binding']); $this->assertNull($root->fresh()->original_context);
        $r = $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['reason' => 'Explicitly record current original association', 'related_incident_id' => $incident->id]));
        $v = \App\Models\ChemicalExposureSourceVersion::findOrFail($r['source_version_id']);
        $this->assertNull($v->content['root_before']['original_context']); $this->assertSame($incident->id, $v->content['root_after']['original_context']['incident_id']);
        $this->assertSame('recorded', $this->proof($root)['context_binding']);
    }

    public function test_incident_without_exact_recorded_site_refuses_even_if_legacy_incident_reader_has_site_fallback(): void
    {
        $incident = $this->incident(['site_id' => null]);
        $this->denial(fn () => $this->capture(['related_incident_id' => $incident->id]), 404);
        $this->assertSame(0, SubstanceExposureRecord::count());
    }

    public function test_foreign_or_conflicting_incident_shift_is_never_silently_erased(): void
    {
        $incident = $this->incident();
        $shift = Shift::factory()->create(['client_id' => $incident->client_id, 'site_id' => $this->foreignSite->id]);
        $incident->update(['shift_id' => $shift->id]);
        $this->denial(fn () => $this->capture(['related_incident_id' => $incident->id]), 404);
        $incident->update(['shift_id' => null, 'site_id' => $this->foreignSite->id]);
        $this->denial(fn () => $this->capture(['related_incident_id' => $incident->id]), 404);
        $this->assertSame(0, SubstanceExposureRecord::count());
    }

    public function test_current_incident_and_client_grants_are_independent_and_withdrawal_denies_before_body(): void
    {
        $incident = $this->incident(); $root = $this->exposure(['related_incident_id' => $incident->id]);
        foreach (['incidents.viewAny', 'clients.viewAny'] as $permission) {
            $this->manager->load('permissionOverrides'); $this->allow($this->manager, $permission, false);
            $this->denial(fn () => $this->proof($root), $permission === 'incidents.viewAny' ? 404 : 403);
            $this->allow($this->manager, $permission); $this->assertSame('recorded', $this->proof($root)['context_binding']);
        }
    }

    public function test_moved_incident_client_or_shift_pointer_cannot_relabel_retained_original_context(): void
    {
        $incident = $this->incident(); $root = $this->exposure(['related_incident_id' => $incident->id]);
        $original = $root->original_context;
        $other = Client::factory()->create(['site_id' => $this->site->id]);
        $incident->update(['client_id' => $other->id]);
        $this->assertSame('repair_required', $this->proof($root)['attribution_state']);
        $this->assertSame($original, $root->fresh()->original_context);
        $incident->update(['client_id' => $original['client_id'], 'site_id' => $this->foreignSite->id]);
        $this->denial(fn () => $this->proof($root), 404);
    }

    public function test_service_context_is_selected_from_actual_incident_and_must_be_active_and_available_to_original_site(): void
    {
        $context = ServiceContext::factory()->create(['site_id' => $this->site->id, 'is_active' => true]);
        $incident = $this->incident(['service_context_id' => $context->id]);
        $root = $this->exposure(['related_incident_id' => $incident->id]);
        $this->assertSame($context->id, $this->proof($root)['service_context_id']);
        $context->update(['is_active' => false]); $this->denial(fn () => $this->proof($root), 404);
    }

    public function test_unsupported_unproven_respite_pointer_is_not_recorded_from_raw_incident_id(): void
    {
        $incident = $this->incident();
        // A real stay is required, but the actor's independent respite authority must deny before its discovery.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try { DB::table('client_incidents')->where('id', $incident->id)->update(['respite_stay_id' => 999999]); }
        finally { DB::statement('SET FOREIGN_KEY_CHECKS=1'); }
        $this->allow($this->manager, 'respite.viewAny', false);
        $this->denial(fn () => $this->capture(['related_incident_id' => $incident->id]), 403);
        $this->assertSame(0, SubstanceExposureRecord::count());
    }

    public function test_private_read_does_not_grant_public_incident_or_client_read_and_never_exposes_names_in_proof(): void
    {
        $root = $this->exposure(); $proof = $this->proof($root);
        $json = json_encode($proof, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($this->worker->name, $json); $this->assertStringNotContainsString($this->manager->name, $json);
        $this->assertArrayNotHasKey('symptoms', $proof); $this->assertArrayNotHasKey('recorded_by', $proof);
        $this->assertFalse($this->manager->fresh()->canDo('incidents.viewAny')); $this->assertFalse($this->manager->fresh()->canDo('clients.viewAny'));
    }
    public function test_denied_original_client_policy_uses_only_canonical_identity_before_private_client_body(): void
    {
        $incident = $this->incident();
        foreach (['clients.viewAny', 'clients.viewAssigned'] as $key) { $this->allow($this->manager, $key, false); }
        [, $queries] = $this->capturedQueries(function () use ($incident) {
            $this->denial(fn () => $this->capture(['related_incident_id' => $incident->id]));
            return null;
        });
        $directClientQueries = array_filter($queries, function ($query) {
            $sql = str_replace(['`', '"'], '', strtolower($query['query']));
            return preg_match('/^select\s+.+?\s+from\s+clients(?:\s|$)/', $sql) === 1;
        });
        $this->assertNotEmpty($directClientQueries);
        foreach ($directClientQueries as $query) {
            $sql = str_replace(['`', '"'], '', strtolower($query['query']));
            $this->assertMatchesRegularExpression('/^select\s+(?:clients\.)?id,\s*(?:clients\.)?site_id\s+from\s+clients(?:\s|$)/', $sql);
            foreach (['first_name', 'last_name', 'medical', 'care_plan'] as $field) { $this->assertStringNotContainsString($field, $sql); }
        }
        $this->assertSame(0, SubstanceExposureRecord::count());
        $this->allow($this->manager, 'clients.viewAny');
        $root = $this->exposure(['related_incident_id' => $incident->id]);
        $this->assertSame('recorded', $this->proof($root)['attribution_state']);
    }
}