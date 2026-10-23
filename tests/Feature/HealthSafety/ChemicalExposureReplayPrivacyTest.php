<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\ChemicalCommandValues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ChemicalExposureReplayPrivacyTest extends ChemicalExposureCommandTestCase
{
    public function test_lost_create_response_and_uppercase_uuid_reconstruct_exact_minimal_original_acceptance(): void
    {
        $r = $this->capture(['symptoms' => 'PRIVATE-LOST-BODY'], $this->reporter);
        $this->assertSame($r, $this->exposureService()->confirmationForKey($this->reporter, strtoupper($r['idempotency_key'])));
        $this->assertSame($r, $this->exposureService()->confirmationForKey($this->reporter, $r['idempotency_key'], $r['root_id']));
        $this->assertSame(1, SubstanceExposureRecord::count()); $this->assertSame(1, ChemicalExposureCommandReceipt::count());
        $this->assertStringNotContainsString('PRIVATE-LOST-BODY', json_encode($r, JSON_THROW_ON_ERROR));
    }

    public function test_create_confirmation_rechecks_current_original_capture_permission_before_private_receipt_sql(): void
    {
        $r = $this->capture([], $this->reporter); $this->reporter->load('permissionOverrides');
        $this->assertTrue($this->reporter->canDo('hazards.create')); $this->allow($this->reporter, 'hazards.create', false);
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $this->denial(fn () => $this->exposureService()->confirmationForKey($this->reporter, $r['idempotency_key']));
            $queries = DB::getQueryLog();
        } finally { DB::disableQueryLog(); }
        foreach ($queries as $q) {
            $this->assertStringNotContainsString('chemical_exposure_command_receipts', $q['query']);
            $this->assertStringNotContainsString('chemical_exposure_source_versions', $q['query']);
        }
        $this->allow($this->reporter, 'hazards.create');
        $this->assertSame($r, $this->exposureService()->confirmationForKey($this->reporter, $r['idempotency_key']));
    }

    public function test_forged_other_actor_uuid_or_noncreate_key_cannot_recover_capture_entry(): void
    {
        $root = $this->exposure();
        $r = $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Record actual', 'symptoms' => 'Actual']));
        $this->assertNull($this->exposureService()->confirmationForKey($this->reporter, $r['idempotency_key']));
        $created = ChemicalExposureCommandReceipt::where('command', 'create_exposure')->sole();
        $this->assertNull($this->exposureService()->confirmationForKey($this->reporter, $created->idempotency_key));
        $this->assertNull($this->exposureService()->confirmationForKey($this->manager, 'not-a-uuid'));
    }

    public function test_warmed_manage_view_or_wellbeing_withdrawal_prevents_replay_and_private_materialisation(): void
    {
        $root = $this->exposure(); $input = $this->privateCommand($root, ['reason' => 'Actual edit', 'medical_outcome' => 'PRIVATE-OUTCOME']);
        $r = $this->exposureService()->correct($root, $this->manager, $input);
        foreach (['hazards.manage', 'hazards.view', 'hr.wellbeing.view'] as $permission) {
            $this->manager->load('permissionOverrides'); $this->assertTrue($this->manager->canDo($permission));
            $this->allow($this->manager, $permission, false);
            $this->denial(fn () => $this->exposureService()->correct($root, $this->manager, $input));
            $this->allow($this->manager, $permission);
            $this->assertSame($r, $this->exposureService()->correct($root, $this->manager, $input));
        }
    }

    public function test_current_viewer_employment_or_site_withdrawal_denies_even_accepted_uuid(): void
    {
        $root = $this->exposure(); $input = $this->privateCommand($root, ['reason' => 'Actual edit', 'symptoms' => 'Retained']);
        $this->exposureService()->correct($root, $this->manager, $input);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['end_date' => '2026-10-11']);
        $this->denial(fn () => $this->exposureService()->correct($root, $this->manager, $input));
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['end_date' => null, 'primary_site_id' => $this->foreignSite->id]);
        $this->denial(fn () => $this->exposureService()->correct($root, $this->manager, $input), 404);
    }

    public function test_historical_exposed_worker_end_of_employment_does_not_remove_authorised_saved_read(): void
    {
        $root = $this->exposure();
        HrEmployeeProfile::where('user_id', $this->worker->id)->update(['end_date' => '2026-10-11', 'is_active' => false]);
        $this->assertSame('recorded', $this->proof($root)['attribution_state']);
        $this->assertSame($this->worker->id, $this->proof($root)['worker_id']);
    }

    public function test_global_public_private_uuid_and_cross_private_root_actor_or_intent_reuse_refuse(): void
    {
        $catalogue = $this->catalogue(); $public = \App\Models\ChemicalCommandReceipt::where('root_type', 'catalogue')->where('root_id', $catalogue->id)->sole();
        $this->validation(fn () => $this->capture(['idempotency_key' => $public->idempotency_key], null, $catalogue->id), 'idempotency_key');
        $root = $this->exposure(); $input = $this->privateCommand($root, ['reason' => 'Actual edit', 'symptoms' => 'One intent']);
        $this->exposureService()->correct($root, $this->manager, $input);
        $otherRoot = $this->exposure(); $otherActor = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']);
        $this->validation(fn () => $this->exposureService()->correct($otherRoot, $this->manager, $input), 'idempotency_key');
        $this->validation(fn () => $this->exposureService()->correct($root, $otherActor, $input), 'idempotency_key');
        $this->validation(fn () => $this->exposureService()->correct($root, $this->manager, [...$input, 'symptoms' => 'Changed intent']), 'idempotency_key');
        $this->assertSame(1, ChemicalCommandIntent::where('idempotency_key', $input['idempotency_key'])->count());
    }

    public function test_original_accepted_replay_survives_later_real_revision_without_rewriting_original_receipt(): void
    {
        $root = $this->exposure(); $input = $this->privateCommand($root, ['reason' => 'Original correction', 'symptoms' => 'Original']);
        $first = $this->exposureService()->correct($root, $this->manager, $input);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Later correction', 'symptoms' => 'Later']));
        $this->assertSame($first, $this->exposureService()->correct($root, $this->manager, $input));
        $this->assertSame($first, $this->exposureService()->confirmationForKey($this->manager, $input['idempotency_key'], $root->id));
        $this->assertSame('Later', $root->fresh()->symptoms); $this->assertSame(3, $root->fresh()->source_revision);
    }

    public function test_private_receipt_projection_whitelists_forged_extra_narrative_without_clearing_other_draft(): void
    {
        $r = $this->capture(); $row = ChemicalExposureCommandReceipt::where('idempotency_key', $r['idempotency_key'])->sole();
        DB::table('chemical_exposure_command_receipts')->where('id', $row->id)
            ->update(['accepted_result' => json_encode([...$row->accepted_result, 'symptoms' => 'FORGED-PRIVATE-BODY'], JSON_THROW_ON_ERROR)]);
        $dto = $this->exposureService()->confirmationForKey($this->manager, $r['idempotency_key']);
        $this->assertSame($r, $dto); $this->assertArrayNotHasKey('symptoms', $dto);
    }

    public function test_receipt_actor_or_revision_link_corruption_is_unconfirmed_not_partial_success(): void
    {
        $r = $this->capture(); $row = ChemicalExposureCommandReceipt::where('idempotency_key', $r['idempotency_key'])->sole();
        foreach (['actor_id' => $this->worker->id, 'version' => 99, 'expected_version' => 1, 'source_id' => $r['source_id'] + 1] as $key => $value) {
            DB::table('chemical_exposure_command_receipts')->where('id', $row->id)
                ->update(['accepted_result' => json_encode([...$row->accepted_result, $key => $value], JSON_THROW_ON_ERROR)]);
            $this->integrity(fn () => $this->exposureService()->confirmationForKey($this->manager, $r['idempotency_key']));
        }
    }

    public function test_missing_or_actor_action_key_subject_mutated_audit_cannot_certify_saved_source(): void
    {
        $r = $this->capture(); $root = SubstanceExposureRecord::findOrFail($r['root_id']);
        $receipt = ChemicalExposureCommandReceipt::where('idempotency_key', $r['idempotency_key'])->sole();
        $audit = AuditLog::findOrFail($receipt->audit_log_id); $original = $audit->getRawOriginal();
        foreach (['user_id' => $this->worker->id, 'action' => 'unrelated', 'auditable_id' => $root->id + 1,
            'meta' => json_encode(['idempotency_key' => 'not-original'], JSON_THROW_ON_ERROR)] as $field => $value) {
            DB::table('audit_logs')->where('id', $audit->id)->update([$field => $value]);
            $this->assertSame('repair_required', $this->proof($root)['attribution_state']);
            $this->integrity(fn () => $this->exposureService()->confirmationForKey($this->manager, $r['idempotency_key']));
            DB::table('audit_logs')->where('id', $audit->id)->update($original);
        }
    }

    public function test_changed_version_with_recomputed_local_digest_fails_original_receipt_audit_anchor(): void
    {
        $r = $this->capture(['symptoms' => 'Original private fact']); $root = SubstanceExposureRecord::findOrFail($r['root_id']);
        $v = ChemicalExposureSourceVersion::findOrFail($r['source_version_id']); $content = $v->content;
        $content['root_after']['symptoms'] = 'FORGED'; $content['source_after']['symptoms'] = 'FORGED';
        DB::table('chemical_exposure_source_versions')->where('id', $v->id)->update(['content' => json_encode($content, JSON_THROW_ON_ERROR),
            'content_digest' => ChemicalCommandValues::digest($content)]);
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['symptoms' => 'FORGED']);
        $this->assertSame('repair_required', $this->proof($root)['attribution_state']);
        $this->integrity(fn () => $this->exposureService()->confirmationForKey($this->manager, $r['idempotency_key']));
    }

    public function test_missing_version_external_fact_mutation_and_schema_marker_downgrade_never_become_legacy(): void
    {
        $root = $this->exposure();
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->assertSame(['attribution_state' => 'repair_required', 'source_id' => $root->id], $this->proof($root));
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['source_schema_version' => 1, 'source_revision' => 1, 'symptoms' => 'External']);
        $this->assertSame('repair_required', $this->proof($root)['attribution_state']);
        Schema::disableForeignKeyConstraints();
        try { DB::table('chemical_exposure_source_versions')->where('exposure_id', $root->id)->delete(); }
        finally { Schema::enableForeignKeyConstraints(); }
        $this->assertSame('repair_required', $this->proof($root)['attribution_state']);
    }

    public function test_missing_original_intent_cannot_be_recreated_from_receipt_or_retried_payload(): void
    {
        $root = $this->exposure(); $input = $this->privateCommand($root, ['reason' => 'Original', 'symptoms' => 'Original']);
        $r = $this->exposureService()->correct($root, $this->manager, $input);
        Schema::disableForeignKeyConstraints();
        try { DB::table('chemical_command_intents')->where('idempotency_key', $r['idempotency_key'])->delete(); }
        finally { Schema::enableForeignKeyConstraints(); }
        $this->integrity(fn () => $this->exposureService()->correct($root, $this->manager, $input));
        $this->assertSame(0, ChemicalCommandIntent::where('idempotency_key', $r['idempotency_key'])->count());
    }

    public function test_private_source_versions_and_receipts_reject_ordinary_update_or_delete(): void
    {
        $r = $this->capture(); $v = ChemicalExposureSourceVersion::findOrFail($r['source_version_id']);
        $receipt = ChemicalExposureCommandReceipt::where('idempotency_key', $r['idempotency_key'])->sole();
        $this->integrity(fn () => $v->update(['command' => 'forged'])); $this->integrity(fn () => $v->delete());
        $this->integrity(fn () => $receipt->update(['source_id' => 99])); $this->integrity(fn () => $receipt->delete());
    }
}