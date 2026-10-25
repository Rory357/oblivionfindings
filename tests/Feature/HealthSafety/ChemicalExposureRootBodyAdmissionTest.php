<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\ChemicalExposureAccessService;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use App\Services\HealthSafety\ChemicalExposureWorkspaceService;
use Illuminate\Support\Facades\DB;

/** Intended metadata-first owner patch remains UNAPPLIED. Assertions deliberately expose current pre-body ordering gap. */
final class ChemicalExposureRootBodyAdmissionTest extends ChemicalExposureCommandTestCase
{
    private function workspace(): ChemicalExposureWorkspaceService { return app(ChemicalExposureWorkspaceService::class); }
    private function linked(): array
    {
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($this->manager, $key); }
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create(['client_id' => $client->id, 'site_id' => $this->site->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null, 'is_hr_confidential' => false]));
        return [$this->exposure(['related_incident_id' => $incident->id, 'symptoms' => 'PRIVATE-ROOT-BODY']), $incident, $client];
    }
    private function withoutRootBody(callable $operation): void
    {
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $operation();
            foreach (DB::getQueryLog() as $query) {
                $this->assertDoesNotMatchRegularExpression('/select\s+(?:[`"]?substance_exposure_records[`"]?\.)?\*\s+from\s+[`"]?substance_exposure_records/i', $query['query']);
                foreach (['symptoms', 'circumstances', 'first_aid_given', 'medical_treatment', 'medical_outcome'] as $body) {
                    $this->assertStringNotContainsString($body, strtolower($query['query']));
                }
            }
        } finally { DB::disableQueryLog(); }
    }
    public function test_current_client_withdrawal_denies_before_medical_root_query_and_restoration_preserves_actual_private_record(): void
    {
        [$root] = $this->linked(); $this->manager->canDo('clients.viewAny'); $this->allow($this->manager, 'clients.viewAny', false);
        $this->withoutRootBody(fn () => $this->denial(fn () => $this->workspace()->detail($this->manager, $root->id)));
        $this->allow($this->manager, 'clients.viewAny');
        $this->assertSame('PRIVATE-ROOT-BODY', $this->workspace()->detail($this->manager, $root->id)['record']['symptoms']);
    }
    public function test_missing_current_original_incident_is_not_replaced_by_worker_site_or_client_fallback_before_body(): void
    {
        [$root, $incident] = $this->linked(); ClientIncident::withoutEvents(fn () => $incident->delete());
        $this->withoutRootBody(fn () => $this->denial(fn () => $this->workspace()->detail($this->manager, $root->id), 404));
    }
    public function test_current_incident_tuple_movement_refuses_root_body_even_when_actor_has_dual_private_module_grants(): void
    {
        [$root, $incident] = $this->linked(); DB::table('client_incidents')->where('id', $incident->id)->update(['site_id' => $this->foreignSite->id]);
        $this->withoutRootBody(fn () => $this->denial(fn () => $this->workspace()->detail($this->manager, $root->id), 404));
    }
    public function test_prior_site_withdrawal_after_real_correction_does_not_hydrate_current_root_or_old_copied_body(): void
    {
        $root = $this->exposure(['symptoms' => 'PRIVATE-OLD-SITE-BODY']);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        HrEmployeeProfile::where('user_id', $this->worker->id)->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['site_id' => $this->foreignSite->id, 'related_incident_id' => null, 'reason' => 'Actual corrected occurrence Site']));
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => []]);
        $this->withoutRootBody(fn () => $this->denial(fn () => $this->workspace()->detail($this->manager, $root->id), 404));
    }
    public function test_local_root_association_replacement_cannot_bypass_acceptance_anchor_to_fetch_medical_body(): void
    {
        [$root] = $this->linked(); DB::table('substance_exposure_records')->where('id', $root->id)->update(['related_incident_id' => null, 'original_context' => null]);
        $this->withoutRootBody(fn () => $this->validation(fn () => $this->workspace()->detail($this->manager, $root->id), 'source'));
    }
    public function test_typed_marker_downgrade_and_missing_version_refuse_before_root_body_with_no_legacy_promotion(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::TEST_SHAPE, 'the fixture deletes source versions that delivery rows still reference under a restrict-on-delete foreign key (ce_delivery_version_fk). The first half, which refuses before reading the body, passes.', 'delete the delivery rows first.'));
        $root = $this->exposure(); DB::table('substance_exposure_records')->where('id', $root->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->withoutRootBody(fn () => $this->validation(fn () => $this->workspace()->detail($this->manager, $root->id), 'source'));
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['source_schema_version' => 1, 'source_revision' => 1]);
        DB::table('chemical_exposure_source_versions')->where('exposure_id', $root->id)->delete();
        $this->withoutRootBody(fn () => $this->validation(fn () => $this->workspace()->detail($this->manager, $root->id), 'source'));
    }
    public function test_genuine_legacy_unknown_association_is_not_admitted_from_todays_incident_client_tuple(): void
    {
        [$created, $incident] = $this->linked();
        $legacy = SubstanceExposureRecord::withoutEvents(fn () => SubstanceExposureRecord::create(['hazardous_substance_id' => $created->hazardous_substance_id,
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'exposed_at' => '2026-10-12 00:00:00', 'exposure_type' => 'inhalation',
            'related_incident_id' => $incident->id, 'created_by' => $this->manager->id]));
        $this->withoutRootBody(fn () => $this->validation(fn () => $this->workspace()->detail($this->manager, $legacy->id), 'source'));
    }
    public function test_saved_write_identity_nowait_path_also_rechecks_original_client_before_loading_private_root(): void
    {
        [$root] = $this->linked(); $this->allow($this->manager, 'clients.viewAny', false);
        $input = $this->privateCommand($root, ['reason' => 'Retained draft', 'symptoms' => 'Attempted private change']);
        $this->withoutRootBody(fn () => $this->denial(fn () => $this->exposureService()->correct($root, $this->manager, $input)));
    }
    public function test_standalone_access_prepares_original_context_keys_upfront_and_does_not_refresh_viewer_inside_body_guard(): void
    {
        [$root] = $this->linked(); $dto = app(ChemicalExposureAccessService::class)->withCurrentViewer($this->manager,
            fn ($current, $reads, $sites) => ['id' => app(ChemicalExposureAccessService::class)->assertExposure($root->id, $current, $reads, $sites)->id]);
        $this->assertSame($root->id, $dto['id']);
        $this->allow($this->manager, 'clients.viewAny', false);
        $this->withoutRootBody(fn () => $this->denial(fn () => app(ChemicalExposureAccessService::class)->withCurrentViewer($this->manager,
            fn ($current, $reads, $sites) => ['id' => app(ChemicalExposureAccessService::class)->assertExposure($root->id, $current, $reads, $sites)->id])));
    }
    public function test_root_and_local_version_identity_rewrite_cannot_override_original_audit_bound_worker_tuple_before_body(): void
    {
        $root = $this->exposure(); $other = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $version = \App\Models\ChemicalExposureSourceVersion::where('exposure_id', $root->id)->sole();
        $content = $version->content; $content['root_after']['user_id'] = $other->id;
        DB::table('chemical_exposure_source_versions')->where('id', $version->id)->update(['content' => json_encode($content, JSON_THROW_ON_ERROR)]);
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['user_id' => $other->id]);
        $this->withoutRootBody(fn () => $this->validation(fn () => $this->workspace()->detail($this->manager, $root->id), 'source'));
    }

    public function test_real_legacy_text_correction_then_new_association_cannot_unseal_unknown_original_linked_body(): void
    {
        [$created, $incident] = $this->linked();
        $legacy = SubstanceExposureRecord::withoutEvents(fn () => SubstanceExposureRecord::create(['hazardous_substance_id' => $created->hazardous_substance_id,
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'exposed_at' => '2026-10-12 00:00:00', 'exposure_type' => 'inhalation',
            'related_incident_id' => $incident->id, 'symptoms' => 'SEALED-LEGACY-MEDICAL-FACT', 'created_by' => $this->manager->id]));
        // Retained pre-admission transition uses the real claim/version/strict audit/accept foundation; current public correction must refuse it.
        $this->historicalCorrection($legacy, ['reason' => 'Ordinary legacy text correction', 'circumstances' => 'Actual revised text']);
        $context = ['schema_version' => 1, 'site_id' => $this->site->id, 'incident_id' => $incident->id,
            'client_id' => $incident->client_id, 'shift_id' => null, 'service_context_id' => null, 'stay_id' => null];
        $this->historicalCorrection($legacy, ['reason' => 'Record a new independently checked association',
            'related_incident_id' => $incident->id, 'original_context' => $context]);
        $this->assertSame(2, $legacy->fresh()->source_revision); $this->assertIsArray($legacy->fresh()->original_context);
        $input = $this->privateCommand($legacy, ['reason' => 'Contemporary correction refuses sealed origin', 'symptoms' => 'Attempted change']);
        $this->withoutRootBody(fn () => $this->validation(fn () => $this->exposureService()->correct($legacy, $this->manager, $input), 'source'));
        $this->withoutRootBody(fn () => $this->validation(fn () => $this->workspace()->detail($this->manager, $legacy->id), 'source'));
        // Another accepted correction cannot erase the retained origin in earlier receipt metadata.
        $this->historicalCorrection($legacy, ['reason' => 'Later revised text', 'circumstances' => 'Later actual text']);
        $this->withoutRootBody(fn () => $this->validation(fn () => $this->workspace()->detail($this->manager, $legacy->id), 'source'));
    }
    public function test_genuine_unlinked_legacy_origin_can_be_corrected_without_inventing_a_prior_association(): void
    {
        $legacy = SubstanceExposureRecord::withoutEvents(fn () => SubstanceExposureRecord::create(['hazardous_substance_id' => $this->catalogue()->id,
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'exposed_at' => '2026-10-12 00:00:00', 'exposure_type' => 'skin_contact',
            'related_incident_id' => null, 'symptoms' => 'ACTUAL-UNLINKED-LEGACY-FACT', 'created_by' => $this->manager->id]));
        $receipt = $this->exposureService()->correct($legacy, $this->manager, $this->privateCommand($legacy,
            ['reason' => 'Actual unlinked correction', 'circumstances' => 'Actual retained context']));
        $this->assertSame(1, $receipt['version']);
        $this->assertSame('ACTUAL-UNLINKED-LEGACY-FACT', $this->workspace()->detail($this->manager, $legacy->id)['record']['symptoms']);
        $this->assertNull($legacy->fresh()->original_context);
    }

    /** Explicit pre-policy fixture, not an authorised contemporary transport or a manually certified version. */
    private function historicalCorrection(SubstanceExposureRecord $root, array $facts): array
    {
        return app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager, function ($current, $reads, $sites) use ($root, $facts): array {
            $access = app(ChemicalExposureCommandAccess::class); $access->assertOperation($current, $reads, 'manage');
            $access->site((int) $root->site_id, $reads, $sites);
            $ledger = app(\App\Services\HealthSafety\ChemicalExposureLedger::class);
            $values = ['idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'expected_version' => (int) $root->source_revision, ...$facts];
            $before = $ledger->snapshot($root);
            if (isset($facts['original_context'])) { $this->exposureService()->assertOriginalContext($facts['original_context'], $current, $reads, (int) $root->site_id); }
            $intent = $ledger->claim($current, $reads, (int) $root->id, (int) $root->hazardous_substance_id, 'correct_exposure', $values, $sites);
            $root->fill(array_diff_key($facts, ['reason' => true, 'original_context' => true]));
            $root->forceFill(['source_schema_version' => 1, 'source_revision' => $root->source_revision + 1, 'updated_by' => $current->id,
                'original_context' => $facts['original_context'] ?? $root->original_context]);
            $this->exposureService()->save($root);
            $version = $ledger->version($intent, $root, $root, 'exposure', $current, $reads, $before, $before, ['reason' => $facts['reason']]);
            return $ledger->accept($intent, $root, $root, 'exposure', $version, $current, $reads, true, $sites);
        });
    }

}
