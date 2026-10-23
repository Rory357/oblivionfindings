<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use App\Services\HealthSafety\ChemicalExposureReadService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Whole intended collection boundary suite. Owner admission and reader patches remain UNAPPLIED; every case UNRUN. */
final class ChemicalExposureCollectionAdmissionTest extends ChemicalExposureCommandTestCase
{
    private function reader(): ChemicalExposureReadService { return app(ChemicalExposureReadService::class); }

    private function linked(array $facts = []): array
    {
        foreach (['incidents.viewAny', 'clients.viewAny'] as $key) { $this->allow($this->manager, $key); }
        $client = Client::factory()->create(['site_id' => $this->site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $this->site->id, 'client_id' => $client->id, 'shift_id' => null,
            'service_context_id' => null, 'respite_stay_id' => null, 'is_hr_confidential' => false]));
        return [$this->exposure(['related_incident_id' => $incident->id, 'medical_treatment' => 'medical',
            'symptoms' => 'PRIVATE-COLLECTION-MEDICAL', ...$facts]), $incident, $client];
    }

    private function legacy(array $facts = []): SubstanceExposureRecord
    {
        return SubstanceExposureRecord::withoutEvents(fn () => SubstanceExposureRecord::factory()->create([
            'hazardous_substance_id' => $this->catalogue()->id, 'site_id' => $this->site->id,
            'user_id' => $this->worker->id, 'created_by' => $this->manager->id, 'updated_by' => null,
            'source_schema_version' => 0, 'source_revision' => 0, 'original_context' => null,
            'related_incident_id' => null, 'exposed_at' => '2026-10-12 00:00:00',
            'medical_attention_sought' => false, 'medical_outcome' => null, ...$facts]));
    }

    private function unavailable(array $dto, string $state): void
    {
        $this->assertSame($state, $dto['source_integrity']);
        foreach (['records', 'counts', 'page', 'filters'] as $key) { $this->assertNull($dto[$key]); }
        $json = json_encode($dto, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-COLLECTION-MEDICAL', $this->worker->name, $this->manager->name] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    private function noMedicalBodyOrCount(array $queries): void
    {
        foreach ($queries as $query) {
            $sql = strtolower(str_replace(['`', '"'], '', $query['query']));
            $this->assertDoesNotMatchRegularExpression('/select\s+(?:substance_exposure_records\.)?\*\s+from\s+substance_exposure_records/', $sql);
            foreach (['medical_attention_sought', 'medical_outcome', 'medical_treatment', 'symptoms', 'circumstances'] as $field) {
                $this->assertStringNotContainsString($field, $sql);
            }
            if (str_contains($sql, 'from substance_exposure_records')) {
                $this->assertStringNotContainsString('count(', $sql);
                $this->assertStringNotContainsString('order by exposed_at', $sql);
                $this->assertStringNotContainsString('exposed_at >=', $sql);
            }
        }
    }

    public function test_warmed_client_withdrawal_withholds_counts_page_and_body_then_restore_reveals_actual_same_record(): void
    {
        [$root] = $this->linked(); $this->manager->load(['roles.permissions', 'permissionOverrides']);
        $this->allow($this->manager, 'clients.viewAny', false);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->reader()->index($this->manager));
        $this->unavailable($dto, 'restricted'); $this->noMedicalBodyOrCount($queries);
        $this->allow($this->manager, 'clients.viewAny');
        $dto = $this->reader()->index($this->manager);
        $this->assertSame(['exposures' => 1, 'awaiting_medical_outcome' => 1], $dto['counts']);
        $this->assertSame($root->id, $dto['records'][0]['id']);
        $this->assertSame('PRIVATE-COLLECTION-MEDICAL', $dto['records'][0]['symptoms']);
        $this->assertSame('recorded', $dto['records'][0]['attribution_state']);
    }

    public function test_incident_permission_withdrawal_and_restore_are_independent_of_private_module_read(): void
    {
        [$root] = $this->linked(); $this->allow($this->manager, 'incidents.viewAny', false);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->reader()->forSubstance($this->manager, $root->hazardous_substance_id));
        $this->unavailable($dto, 'restricted'); $this->noMedicalBodyOrCount($queries);
        $this->allow($this->manager, 'incidents.viewAny');
        $this->assertSame(1, $this->reader()->forSubstance($this->manager, $root->hazardous_substance_id)['counts']['exposures']);
    }

    public function test_medical_filter_count_is_not_queried_when_original_incident_is_missing(): void
    {
        [, $incident] = $this->linked(); ClientIncident::withoutEvents(fn () => $incident->delete());
        [$dto, $queries] = $this->capturedQueries(fn () => $this->reader()->index($this->manager));
        $this->unavailable($dto, 'restricted'); $this->noMedicalBodyOrCount($queries);
    }

    public function test_moved_original_incident_site_cannot_be_certified_from_todays_worker_site(): void
    {
        [, $incident] = $this->linked(); DB::table('client_incidents')->where('id', $incident->id)->update(['site_id' => $this->foreignSite->id]);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->reader()->index($this->manager));
        $this->unavailable($dto, 'restricted'); $this->noMedicalBodyOrCount($queries);
    }

    public function test_legacy_linked_unknown_is_unconfirmed_not_a_false_clean_empty_medical_total(): void
    {
        [, $incident] = $this->linked(); $legacy = $this->legacy(['related_incident_id' => $incident->id]);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->reader()->forSubstance($this->manager, $legacy->hazardous_substance_id));
        $this->unavailable($dto, 'association_unconfirmed'); $this->noMedicalBodyOrCount($queries);
        $this->assertNull($legacy->fresh()->original_context);
    }

    public function test_genuine_unlinked_legacy_remains_readable_unknown_and_medical_facts_are_preserved(): void
    {
        $root = $this->legacy(['medical_attention_sought' => true, 'medical_outcome' => null, 'symptoms' => 'Actual retained legacy symptom']);
        $dto = $this->reader()->index($this->manager);
        $this->assertSame(['exposures' => 1, 'awaiting_medical_outcome' => 1], $dto['counts']);
        $this->assertSame($root->id, $dto['records'][0]['id']); $this->assertSame('legacy_unknown', $dto['records'][0]['attribution_state']);
        $this->assertSame('Actual retained legacy symptom', $dto['records'][0]['symptoms']);
        $this->assertNull($root->fresh()->original_context); $this->assertSame(0, $root->fresh()->source_revision);
    }

    public function test_marker_downgrade_is_unconfirmed_before_any_private_root_or_medical_count_query(): void
    {
        $root = $this->exposure(); DB::table('substance_exposure_records')->where('id', $root->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->reader()->index($this->manager));
        $this->unavailable($dto, 'repair_required'); $this->noMedicalBodyOrCount($queries);
    }

    public function test_locally_rebound_worker_and_incident_do_not_override_retained_acceptance_for_collection_counts(): void
    {
        [$root] = $this->linked();
        DB::table('substance_exposure_records')->where('id', $root->id)->update([
            'user_id' => $this->reporter->id, 'related_incident_id' => null, 'original_context' => null]);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->reader()->index($this->manager));
        $this->unavailable($dto, 'repair_required'); $this->noMedicalBodyOrCount($queries);
    }

    public function test_date_and_worker_refinements_do_not_prefilter_unproved_association_to_make_zero_look_authorised(): void
    {
        [$root] = $this->linked(); $this->allow($this->manager, 'clients.viewAny', false);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->reader()->index($this->manager,
            ['worker_id' => $this->reporter->id, 'from' => '2030-01-01T00:00:00Z']));
        $this->unavailable($dto, 'restricted'); $this->noMedicalBodyOrCount($queries);
    }

    public function test_private_scope_revoke_restore_never_queries_source_metadata_or_counts_on_denial(): void
    {
        $root = $this->exposure(); $this->manager->load(['roles.permissions', 'permissionOverrides']);
        $this->allow($this->manager, 'hr.wellbeing.view', false);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->reader()->index($this->manager, ['from' => 'not-an-instant']));
        $this->unavailable($dto, 'restricted');
        foreach ($queries as $query) { $this->assertStringNotContainsString('substance_exposure_records', $query['query']); }
        $this->allow($this->manager, 'hr.wellbeing.view'); $this->assertSame($root->id, $this->reader()->index($this->manager)['records'][0]['id']);
    }

    public function test_site_reassignment_withdraws_old_sources_and_restores_same_actual_scope(): void
    {
        $root = $this->exposure(); $this->manager->load('hrEmployeeProfile');
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $dto = $this->reader()->index($this->manager); $this->assertSame(0, $dto['counts']['exposures']); $this->assertSame([], $dto['records']);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertSame([$root->id], array_column($this->reader()->index($this->manager)['records'], 'id'));
    }

    public function test_foreign_selected_site_denies_before_source_or_medical_queries(): void
    {
        $this->exposure();
        [, $queries] = $this->capturedQueries(function () {
            $this->denial(fn () => $this->reader()->index($this->manager, ['site_id' => $this->foreignSite->id]), 404);
            return null;
        });
        foreach ($queries as $query) { $this->assertStringNotContainsString('substance_exposure_records', $query['query']); }
    }

    public function test_prior_site_permission_is_required_after_real_source_site_correction_before_new_body_count(): void
    {
        $root = $this->exposure();
        foreach ([$this->manager, $this->worker] as $user) { HrEmployeeProfile::where('user_id', $user->id)->update(['secondary_site_ids' => [$this->foreignSite->id]]); }
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root,
            ['site_id' => $this->foreignSite->id, 'related_incident_id' => null, 'reason' => 'Actual corrected Site']));
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => []]);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->reader()->index($this->manager));
        $this->unavailable($dto, 'restricted'); $this->noMedicalBodyOrCount($queries);
    }

    public function test_filtered_counts_page_and_equal_instant_id_order_derive_from_actual_proved_rows(): void
    {
        $substance = $this->catalogue(); $otherWorker = $this->staff([]);
        $firstReceipt = $this->capture(['medical_treatment' => 'medical', 'exposed_at' => '2026-10-12T13:00:00+13:00'], null, $substance->id);
        $first = SubstanceExposureRecord::findOrFail($firstReceipt['root_id']);
        // capture helper takes parent separately; use the actual create API for equal source parent and instant.
        $receipt = $this->capture(['medical_treatment' => 'none', 'exposed_at' => '2026-10-12T00:00:00Z'], null, $substance->id);
        $second = SubstanceExposureRecord::findOrFail($receipt['root_id']);
        $this->capture(['user_id' => $otherWorker->id, 'medical_treatment' => 'medical'], null, $substance->id);
        $dto = $this->reader()->forSubstance($this->manager, $substance->id, ['worker_id' => $this->worker->id,
            'from' => '2026-10-12T00:00:00Z', 'to' => '2026-10-12T13:00:00+13:00']);
        $this->assertSame(['exposures' => 2, 'awaiting_medical_outcome' => 1], $dto['counts']);
        $this->assertSame([$second->id, $first->id], array_column($dto['records'], 'id'));
        $this->assertSame(2, $dto['page']['total']); $this->assertSame(1, $dto['page']['last_page']); $this->assertFalse($dto['page']['has_more']);
    }

    public function test_offpage_unproved_original_association_withholds_total_before_loading_any_of_that_source_body(): void
    {
        [$linked] = $this->linked(['exposed_at' => '2020-01-01T00:00:00Z']);
        for ($i = 0; $i < 26; $i++) { $this->exposure(); }
        $this->allow($this->manager, 'clients.viewAny', false);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->reader()->index($this->manager));
        $this->unavailable($dto, 'restricted'); $this->noMedicalBodyOrCount($queries);
        $this->assertSame(27, SubstanceExposureRecord::count());
    }

    public function test_whole_actual_27_record_pagination_keeps_complete_total_without_hidden_cap(): void
    {
        $ids = []; for ($i = 0; $i < 27; $i++) { $ids[] = $this->exposure()->id; }
        $first = $this->reader()->index($this->manager); $second = $this->reader()->index($this->manager, ['page' => 2]);
        $this->assertCount(25, $first['records']); $this->assertCount(2, $second['records']);
        $this->assertSame(27, $first['counts']['exposures']); $this->assertSame(27, $second['page']['total']);
        $this->assertSame(2, $second['page']['last_page']); $this->assertFalse($second['page']['has_more']);
        $this->assertSame(array_reverse($ids), [...array_column($first['records'], 'id'), ...array_column($second['records'], 'id')]);
    }

    public function test_tampered_offpage_medical_outcome_is_repair_not_an_empty_or_clean_count(): void
    {
        $root = $this->exposure(['medical_treatment' => 'medical']);
        for ($i = 0; $i < 26; $i++) { $this->exposure(); }
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['medical_outcome' => 'Ungoverned outcome']);
        $this->unavailable($this->reader()->index($this->manager), 'repair_required');
    }

    public function test_body_change_during_actual_name_projection_invalidates_detached_medical_count_and_rows(): void
    {
        $root = $this->exposure(['medical_treatment' => 'medical']); $changed = false;
        User::retrieved(function ($user) use ($root, &$changed) {
            if (! $changed && (int) $user->id === (int) $this->worker->id && array_keys($user->getAttributes()) === ['id', 'name']) {
                $changed = true; DB::table('substance_exposure_records')->where('id', $root->id)->update(['medical_outcome' => 'Changed between count and names']);
            }
        });
        $dto = $this->reader()->index($this->manager); $this->assertTrue($changed); $this->unavailable($dto, 'repair_required');
    }

    public function test_root_identity_change_after_metadata_admission_cannot_certify_a_different_presented_worker(): void
    {
        $root = $this->exposure(['symptoms' => 'Recorded body']); $changed = false;
        SubstanceExposureRecord::retrieved(function ($row) use ($root, &$changed) {
            if (! $changed && (int) $row->id === (int) $root->id && $row->getAttribute('symptoms') !== null) {
                $changed = true; DB::table('substance_exposure_records')->where('id', $root->id)->update(['user_id' => $this->reporter->id]);
            }
        });
        $dto = $this->reader()->index($this->manager); $this->assertTrue($changed); $this->unavailable($dto, 'repair_required');
    }

    public function test_supplied_active_complete_context_matches_fresh_wrapper_and_does_not_refresh_actor(): void
    {
        $this->exposure(); $standalone = $this->reader()->index($this->manager);
        HealthSafetyReadContext::within($this->manager, ChemicalExposureCommandAccess::PERMISSIONS, function ($context) use ($standalone) {
            [$dto, $queries] = $this->capturedQueries(fn () => $this->reader()->indexInContext($this->manager, [], $context));
            $this->assertSame($standalone, $dto);
            foreach ($queries as $query) {
                foreach (['permission_user', 'role_user', 'role_has_permissions'] as $authority) { $this->assertStringNotContainsString($authority, $query['query']); }
            }
            return null;
        });
    }

    public function test_supplied_wrong_actor_missing_keys_and_expired_context_are_visible_contract_errors(): void
    {
        $this->exposure(); $escaped = null;
        HealthSafetyReadContext::within($this->manager, ChemicalExposureCommandAccess::PERMISSIONS, function ($context) use (&$escaped) {
            $escaped = $context;
            try { $this->reader()->indexInContext($this->reporter, [], $context); $this->fail('Wrong viewer must refuse.'); }
            catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
            return null;
        });
        try { $this->reader()->indexInContext($this->manager, [], $escaped); $this->fail('Expired context must refuse.'); }
        catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
        HealthSafetyReadContext::within($this->manager, ['hazards.view', 'hr.wellbeing.view', 'healthSafety.viewAllSites'], function ($context) {
            try { $this->reader()->indexInContext($this->manager, [], $context); $this->fail('Missing original-context preparation must refuse.'); }
            catch (LogicException $error) { $this->assertNotSame('', $error->getMessage()); }
            return null;
        });
    }

    public function test_actual_source_insert_after_final_identity_hydration_invalidates_known_row_total(): void
    {
        $root = $this->exposure(); $metadataQueries = 0; $finalHydration = false; $inserted = null;
        DB::listen(function ($event) use (&$metadataQueries, &$finalHydration) {
            $sql = strtolower(str_replace(['`', '"'], '', $event->sql));
            if (str_starts_with($sql, 'select ') && str_contains($sql, 'from substance_exposure_records')
                && str_contains($sql, 'source_schema_version') && preg_match('/order by (?:substance_exposure_records\.)?id asc/', $sql)) {
                $metadataQueries++; $finalHydration = $metadataQueries === 2;
            }
        });
        SubstanceExposureRecord::retrieved(function ($identity) use ($root, &$finalHydration, &$inserted) {
            if ($finalHydration && $inserted === null && (int) $identity->id === (int) $root->id
                && ! array_key_exists('symptoms', $identity->getAttributes())) {
                $inserted = 0; // Guard reentrant actual source command queries, not a readiness mock.
                $inserted = $this->capture([], null, $root->hazardous_substance_id)['root_id'];
            }
        });
        $dto = $this->reader()->index($this->manager);
        $this->assertGreaterThan(0, $inserted); $this->assertSame(2, SubstanceExposureRecord::count());
        $this->unavailable($dto, 'repair_required');
    }

    public function test_actual_empty_to_new_source_after_final_identity_query_is_not_certified_as_zero(): void
    {
        $parent = $this->catalogue(); $metadataQueries = 0; $inserted = null;
        DB::listen(function ($event) use ($parent, &$metadataQueries, &$inserted) {
            $sql = strtolower(str_replace(['`', '"'], '', $event->sql));
            if (str_starts_with($sql, 'select ') && str_contains($sql, 'from substance_exposure_records')
                && str_contains($sql, 'source_schema_version') && preg_match('/order by (?:substance_exposure_records\.)?id asc/', $sql)) {
                $metadataQueries++;
                if ($metadataQueries === 2 && $inserted === null) {
                    $inserted = 0;
                    $inserted = $this->capture([], null, $parent->id)['root_id'];
                }
            }
        });
        $dto = $this->reader()->forSubstance($this->manager, $parent->id);
        $this->assertGreaterThan(0, $inserted); $this->assertSame(1, SubstanceExposureRecord::count());
        $this->unavailable($dto, 'repair_required');
    }

    public function test_actual_foreign_site_insert_does_not_invalidate_the_unchanged_authorised_scope(): void
    {
        $root = $this->exposure(); $foreignActor = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view'], $this->foreignSite);
        $foreignWorker = $this->staff([], $this->foreignSite); $metadataQueries = 0; $inserted = null;
        DB::listen(function ($event) use ($root, $foreignActor, $foreignWorker, &$metadataQueries, &$inserted) {
            $sql = strtolower(str_replace(['`', '"'], '', $event->sql));
            if (str_starts_with($sql, 'select ') && str_contains($sql, 'from substance_exposure_records')
                && str_contains($sql, 'source_schema_version') && preg_match('/order by (?:substance_exposure_records\.)?id asc/', $sql)) {
                $metadataQueries++;
                if ($metadataQueries === 2 && $inserted === null) {
                    $inserted = 0;
                    $inserted = $this->capture(['site_id' => $this->foreignSite->id, 'user_id' => $foreignWorker->id],
                        $foreignActor, $root->hazardous_substance_id)['root_id'];
                }
            }
        });
        $dto = $this->reader()->index($this->manager); $this->assertGreaterThan(0, $inserted);
        $this->assertSame(1, $dto['counts']['exposures']); $this->assertSame([$root->id], array_column($dto['records'], 'id'));
    }

    private function workspace(): \App\Services\HealthSafety\ChemicalExposureWorkspaceService
    {
        return app(\App\Services\HealthSafety\ChemicalExposureWorkspaceService::class);
    }

    private function withheldWorkspace(array $dto, string $state): void
    {
        $this->assertSame('unavailable', $dto['exposure_access']); $this->assertSame($state, $dto['source_integrity']);
        foreach (['records', 'counts', 'page', 'filters', 'sites'] as $field) { $this->assertNull($dto[$field]); }
        $this->assertTrue($dto['can']['capture']);
        $this->assertStringNotContainsString('PRIVATE-COLLECTION-MEDICAL', json_encode($dto, JSON_THROW_ON_ERROR));
    }

    public function test_permanent_workspace_warmed_client_withdrawal_before_date_refinement_and_restore_preserves_own_envelope(): void
    {
        [$root] = $this->linked(); $this->manager->load(['roles.permissions', 'permissionOverrides']);
        $this->allow($this->manager, 'clients.viewAny', false);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->workspace()->index($this->manager,
            ['substance_id' => $root->hazardous_substance_id, 'from' => '2026-10-12T13:00:00+13:00']));
        $this->withheldWorkspace($dto, 'original_context_restricted'); $this->noMedicalBodyOrCount($queries);
        $this->allow($this->manager, 'clients.viewAny');
        $dto = $this->workspace()->index($this->manager, ['substance_id' => $root->hazardous_substance_id]);
        $this->assertSame('readable', $dto['exposure_access']); $this->assertSame('admitted', $dto['source_integrity']);
        $this->assertSame($root->id, $dto['records'][0]['id']); $this->assertSame(1, $dto['counts']['awaiting_medical_outcome']);
        $this->assertSame(1, $dto['filters']['page']); $this->assertSame([$this->site->id], array_column($dto['sites'], 'id'));
        $this->assertTrue($dto['can']['capture']);
    }

    public function test_permanent_workspace_unknown_original_association_has_no_repair_href_or_cached_private_filter_options(): void
    {
        [, $incident] = $this->linked(); $legacy = $this->legacy(['related_incident_id' => $incident->id]);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->workspace()->index($this->manager,
            ['substance_id' => $legacy->hazardous_substance_id, 'from' => '2030-01-01T00:00:00Z']));
        $this->withheldWorkspace($dto, 'association_unconfirmed'); $this->noMedicalBodyOrCount($queries);
        $this->assertStringNotContainsString('/chemical-exposures/', json_encode($dto, JSON_THROW_ON_ERROR));
    }

    public function test_permanent_workspace_private_module_denial_keeps_capture_separate_and_no_private_sql(): void
    {
        $this->exposure(); $this->allow($this->manager, 'hr.wellbeing.view', false);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->workspace()->index($this->manager));
        $this->assertSame('restricted', $dto['exposure_access']); $this->assertSame('restricted', $dto['source_integrity']);
        foreach (['records', 'counts', 'page', 'filters', 'sites'] as $field) { $this->assertNull($dto[$field]); }
        $this->assertTrue($dto['can']['capture']);
        foreach ($queries as $query) { $this->assertStringNotContainsString('substance_exposure_records', $query['query']); }
    }

    public function test_permanent_workspace_admitted_identity_body_damage_retains_minimal_repair_and_never_trusted_date_or_zero_count(): void
    {
        $root = $this->exposure(); $healthy = $this->exposure();
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['symptoms' => 'Damaged private fact', 'exposed_at' => '2030-01-01 00:00:00']);
        $dto = $this->workspace()->index($this->manager, ['to' => '2026-10-12T13:00:00+13:00']);
        $this->assertSame('readable', $dto['exposure_access']); $this->assertSame('repair_required', $dto['source_integrity']);
        $this->assertNull($dto['counts']['exposures']); $this->assertNull($dto['counts']['awaiting_medical_outcome']);
        $this->assertNull($dto['page']['total']); $this->assertNull($dto['page']['has_more']);
        $this->assertSame(['id' => $root->id, 'attribution_state' => 'repair_required', 'record' => null, 'href' => null], $dto['records'][0]);
        $this->assertSame($healthy->id, $dto['records'][1]['id']);
        $this->assertStringNotContainsString('Damaged private fact', json_encode($dto, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('2030-01-01', json_encode($dto, JSON_THROW_ON_ERROR));
    }

    public function test_permanent_workspace_identity_admission_damage_has_no_identifying_repair_placeholder(): void
    {
        [$root] = $this->linked(); DB::table('substance_exposure_records')->where('id', $root->id)->update(['related_incident_id' => null, 'original_context' => null]);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->workspace()->index($this->manager));
        $this->withheldWorkspace($dto, 'repair_required'); $this->noMedicalBodyOrCount($queries);
    }

    public function test_permanent_workspace_actual_insert_after_final_identity_hydration_invalidates_page_and_total(): void
    {
        $root = $this->exposure(); $metadataQueries = 0; $finalHydration = false; $inserted = null;
        DB::listen(function ($event) use (&$metadataQueries, &$finalHydration) {
            $sql = strtolower(str_replace(['`', '"'], '', $event->sql));
            if (str_starts_with($sql, 'select ') && str_contains($sql, 'from substance_exposure_records')
                && str_contains($sql, 'source_schema_version') && preg_match('/order by (?:substance_exposure_records\.)?id asc/', $sql)) {
                $metadataQueries++; $finalHydration = $metadataQueries === 2;
            }
        });
        SubstanceExposureRecord::retrieved(function ($identity) use ($root, &$finalHydration, &$inserted) {
            if ($finalHydration && $inserted === null && (int) $identity->id === (int) $root->id
                && ! array_key_exists('symptoms', $identity->getAttributes())) {
                $inserted = 0; $inserted = $this->capture([], null, $root->hazardous_substance_id)['root_id'];
            }
        });
        $dto = $this->workspace()->index($this->manager); $this->assertGreaterThan(0, $inserted);
        $this->withheldWorkspace($dto, 'repair_required'); $this->assertSame(2, SubstanceExposureRecord::count());
    }

    public function test_permanent_workspace_actual_empty_to_insert_final_query_cannot_return_an_authorised_empty_page(): void
    {
        $parent = $this->catalogue(); $metadataQueries = 0; $inserted = null;
        DB::listen(function ($event) use ($parent, &$metadataQueries, &$inserted) {
            $sql = strtolower(str_replace(['`', '"'], '', $event->sql));
            if (str_starts_with($sql, 'select ') && str_contains($sql, 'from substance_exposure_records')
                && str_contains($sql, 'source_schema_version') && preg_match('/order by (?:substance_exposure_records\.)?id asc/', $sql)) {
                $metadataQueries++;
                if ($metadataQueries === 2 && $inserted === null) { $inserted = 0; $inserted = $this->capture([], null, $parent->id)['root_id']; }
            }
        });
        $dto = $this->workspace()->index($this->manager, ['substance_id' => $parent->id]);
        $this->assertGreaterThan(0, $inserted); $this->withheldWorkspace($dto, 'repair_required');
    }

    public function test_permanent_workspace_body_change_during_name_projection_cannot_certify_previous_medical_count(): void
    {
        $root = $this->exposure(['medical_treatment' => 'medical']); $changed = false;
        User::retrieved(function ($user) use ($root, &$changed) {
            if (! $changed && (int) $user->id === (int) $this->worker->id && array_keys($user->getAttributes()) === ['id', 'name']) {
                $changed = true; DB::table('substance_exposure_records')->where('id', $root->id)->update(['medical_outcome' => 'Changed after proof']);
            }
        });
        $dto = $this->workspace()->index($this->manager); $this->assertTrue($changed); $this->withheldWorkspace($dto, 'repair_required');
    }

    public function test_unknown_filter_and_invalid_page_are_rejected_after_private_module_authority_not_silently_ignored(): void
    {
        $this->exposure(); $this->validation(fn () => $this->reader()->index($this->manager, ['medical_outcome' => 'private search']), 'filters');
        $this->validation(fn () => $this->reader()->index($this->manager, ['page' => 0]), 'page');
        $this->allow($this->manager, 'hr.wellbeing.view', false);
        $this->assertNull($this->reader()->index($this->manager, ['medical_outcome' => 'private search'])['filters']);
    }
    private function finalByteMutation(bool $permanent, string $replacement): void
    {
        $original = 'Case Sensitive Fact'; $root = $this->exposure(['symptoms' => $original]);
        $positive = $permanent ? $this->workspace()->index($this->manager) : $this->reader()->index($this->manager);
        $this->assertSame($root->id, $positive['records'][0]['id']); $this->assertSame(1, $positive['counts']['exposures']);
        $metadataQueries = 0; $finalHydration = false; $changed = false;
        DB::listen(function ($event) use (&$metadataQueries, &$finalHydration) {
            $sql = strtolower(str_replace(['`', '"'], '', $event->sql));
            if (str_starts_with($sql, 'select ') && str_contains($sql, 'from substance_exposure_records')
                && str_contains($sql, 'source_schema_version') && preg_match('/order by (?:substance_exposure_records\.)?id asc/', $sql)) {
                $metadataQueries++; $finalHydration = $metadataQueries === 2;
            }
        });
        SubstanceExposureRecord::retrieved(function ($identity) use ($root, $replacement, &$finalHydration, &$changed) {
            if ($finalHydration && ! $changed && (int) $identity->id === (int) $root->id
                && ! array_key_exists('symptoms', $identity->getAttributes())) {
                $changed = true;
                DB::table('substance_exposure_records')->where('id', $root->id)->update(['symptoms' => $replacement]);
            }
        });
        $operation = fn () => $permanent ? $this->workspace()->index($this->manager) : $this->reader()->index($this->manager);
        [$dto, $queries] = $this->capturedQueries($operation); $this->assertTrue($changed);
        if ($permanent) { $this->withheldWorkspace($dto, 'repair_required'); }
        else { $this->unavailable($dto, 'repair_required'); }
        $this->assertSame($replacement, DB::table('substance_exposure_records')->where('id', $root->id)->value('symptoms'));
        $binary = array_filter($queries, fn ($query) => str_contains($query['query'], 'CAST(`symptoms` AS BINARY) = CAST(? AS BINARY)'));
        $this->assertNotEmpty($binary, 'Final predicate must reject raw-byte changes independently of SQL collation.');
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['symptoms' => $original]);
        $restored = $operation(); $this->assertSame($root->id, $restored['records'][0]['id']);
        $this->assertSame(1, $restored['counts']['exposures']);
        $this->assertSame($original, DB::table('substance_exposure_records')->where('id', $root->id)->value('symptoms'));
    }
    public function test_reader_post_final_identity_case_only_medical_rewrite_is_refused_and_exact_source_restores(): void
    {
        $this->finalByteMutation(false, 'case sensitive fact');
    }
    public function test_reader_post_final_identity_trailing_space_medical_rewrite_is_refused_and_exact_source_restores(): void
    {
        $this->finalByteMutation(false, 'Case Sensitive Fact ');
    }
    public function test_permanent_index_post_final_identity_case_only_medical_rewrite_is_refused_and_exact_source_restores(): void
    {
        $this->finalByteMutation(true, 'case sensitive fact');
    }
    public function test_permanent_index_post_final_identity_trailing_space_medical_rewrite_is_refused_and_exact_source_restores(): void
    {
        $this->finalByteMutation(true, 'Case Sensitive Fact ');
    }
}
