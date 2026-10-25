<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ChemicalExposureRecoveryCase;
use App\Models\ChemicalExposureRecoveryReceipt;
use App\Models\ChemicalExposureRecoveryVersion;
use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use App\Services\HealthSafety\ChemicalExposureRecoveryLedger;
use App\Services\HealthSafety\HealthSafetyReadContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Complete source foundation suite; routes/shared private audit boundary remain UNAPPLIED and every case UNRUN. */
final class ChemicalExposureAssociationRecoveryTest extends ChemicalExposureRecoveryTestCase
{
    public function test_metadata_only_inspection_keeps_original_body_pointer_dates_and_history_sealed(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Legacy exposure association recovery has no route or caller: ChemicalExposureAssociationRecoveryService is reached only from the unmounted recovery evidence service. The SQL this test flags is an exists(select * ...) probe, not a body read.', 'mount exposure recovery with its routes.'));
        $root = $this->legacy(); [$dto, $queries] = $this->capturedQueries(fn () => $this->recovery()->inspect($this->manager, $root->id));
        $this->noOldBody($queries); $this->assertSame('sealed', $dto['legacy_source']['history_state']);
        $this->assertNull($dto['legacy_source']['medical_record']); $this->assertNull($dto['legacy_source']['evidence']);
        $this->assertFalse($dto['can']['read_old_body']); $this->assertNull($dto['href']);
        $this->assertSame(0, ChemicalExposureRecoveryCase::count());
    }
    public function test_unresolved_review_has_real_receipt_but_no_saved_reason_or_evidence_self_read_grant(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Legacy exposure association recovery has no route or caller: ChemicalExposureAssociationRecoveryService is reached only from the unmounted recovery evidence service. The SQL this test flags is an exists(select * ...) probe, not a body read.', 'mount exposure recovery with its routes.'));
        $root = $this->legacy(); $before = $root->getRawOriginal();
        [$receipt, $queries] = $this->capturedQueries(fn () => $this->recovery()->recordReview($this->manager, $root->id,
            $this->reviewInput(['reason' => 'SEALED-NEW-UNRESOLVED-BASIS'])));
        $this->noOldBody($queries); $this->assertSame(ChemicalExposureRecoveryLedger::KEYS, array_keys($receipt));
        $this->assertTrue($receipt['accepted']); $this->assertSame(1, $receipt['version']);
        $dto = $this->recovery()->inspect($this->manager, $root->id);
        $this->assertNull($dto['review']['facts']); $this->assertNull($dto['review']['evidence']);
        $this->assertSame('unknown', $dto['review']['original_association_state']);
        $this->assertStringNotContainsString('SEALED-NEW-UNRESOLVED-BASIS', json_encode($dto, JSON_THROW_ON_ERROR));
        $this->assertSame($before, $root->fresh()->getRawOriginal());
    }
    public function test_explicit_later_new_association_preserves_original_unknown_and_reads_only_newly_entered_review_facts(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Legacy exposure association recovery has no route or caller: ChemicalExposureAssociationRecoveryService is reached only from the unmounted recovery evidence service. The SQL this test flags is an exists(select * ...) probe, not a body read.', 'mount exposure recovery with its routes.'));
        $root = $this->legacy(); $before = $root->getRawOriginal(); $incident = $this->selectedIncident();
        $r = $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput([
            'decision' => 'reviewed_association', 'incident_id' => $incident->id, 'notes' => 'Actual later review observation']));
        $dto = $this->recovery()->inspect($this->manager, $root->id);
        $this->assertSame('later_reviewed', $dto['review']['association_origin']); $this->assertSame('unknown', $dto['review']['original_association_state']);
        $this->assertSame('Actual later review observation', $dto['review']['facts']['notes']);
        $this->assertSame($before, $root->fresh()->getRawOriginal());
        $this->assertStringNotContainsString('SEALED-ORIGINAL', json_encode($dto, JSON_THROW_ON_ERROR));
        $this->assertSame('not_attempted', $dto['delivery']); $this->assertSame(1, $r['version']);
    }
    public function test_later_no_association_is_not_original_known_null_and_does_not_clear_legacy_pointer(): void
    {
        $root = $this->legacy(); $oldPointer = $root->related_incident_id;
        $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput(['decision' => 'reviewed_no_association']));
        $dto = $this->recovery()->inspect($this->manager, $root->id);
        $this->assertSame('unknown', $dto['legacy_source']['original_association_state']); $this->assertNull($dto['review']['facts']);
        $this->assertSame($oldPointer, $root->fresh()->related_incident_id); $this->assertNull($root->fresh()->original_context);
    }
    public function test_retired_fixed_historical_worker_needs_no_new_capture_eligibility_or_worker_reassignment(): void
    {
        $root = $this->legacy(); HrEmployeeProfile::where('user_id', $this->worker->id)->update(['is_active' => false, 'end_date' => '2021-01-01']);
        $r = $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput());
        $this->assertSame($this->worker->id, ChemicalExposureRecoveryCase::findOrFail($r['root_id'])->worker_id);
        $this->validation(fn () => $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput(['user_id' => $this->reporter->id], 1)), 'command');
    }
    public function test_warmed_manager_private_and_manage_revocation_restore_current_authority_before_any_root_sql(): void
    {
        $root = $this->legacy(); $this->manager->load(['roles.permissions', 'permissionOverrides']);
        foreach (['hazards.manage', 'hr.wellbeing.view'] as $permission) {
            $this->allow($this->manager, $permission, false);
            [, $queries] = $this->capturedQueries(function () use ($root) { $this->denial(fn () => $this->recovery()->inspect($this->manager, $root->id)); return null; });
            foreach ($queries as $query) { $this->assertStringNotContainsString('substance_exposure_records', $query['query']); }
            $this->allow($this->manager, $permission); $this->assertSame('admitted', $this->recovery()->inspect($this->manager, $root->id)['recovery_access']);
        }
    }
    public function test_subject_or_original_recorder_is_not_self_authorised_to_inspect_or_reconcile(): void
    {
        $root = $this->legacy(); $this->denial(fn () => $this->recovery()->inspect($this->worker, $root->id));
        $this->denial(fn () => $this->recovery()->recordReview($this->reporter, $root->id, $this->reviewInput()));
        $this->assertSame(0, ChemicalExposureRecoveryCase::count());
    }
    public function test_current_staff_and_site_withdrawal_and_restore_conceal_before_legacy_body(): void
    {
        $root = $this->legacy(); HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => false]);
        $this->denial(fn () => $this->recovery()->inspect($this->manager, $root->id));
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => true, 'primary_site_id' => $this->foreignSite->id]);
        $this->denial(fn () => $this->recovery()->inspect($this->manager, $root->id), 404);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertNull($this->recovery()->inspect($this->manager, $root->id)['legacy_source']['medical_record']);
    }
    public function test_new_review_incident_and_client_scope_are_independent_before_private_new_notes(): void
    {
        $root = $this->legacy(); $incident = $this->selectedIncident();
        $input = $this->reviewInput(['decision' => 'reviewed_association', 'incident_id' => $incident->id]);
        $this->allow($this->manager, 'clients.viewAny', false); $this->denial(fn () => $this->recovery()->recordReview($this->manager, $root->id, $input));
        $this->allow($this->manager, 'clients.viewAny'); $r = $this->recovery()->recordReview($this->manager, $root->id, $input);
        $this->manager->load(['roles.permissions', 'permissionOverrides']); $this->allow($this->manager, 'incidents.viewAny', false);
        $this->denial(fn () => $this->recovery()->inspect($this->manager, $root->id), 404);
        $this->allow($this->manager, 'incidents.viewAny'); $this->assertSame('reviewed_association', $this->recovery()->inspect($this->manager, $root->id)['review']['decision']);
        $this->assertSame($r, $this->recovery()->recordReview($this->manager, $root->id, $input));
    }
    public function test_changed_new_incident_tuple_cannot_be_replaced_by_todays_other_client(): void
    {
        $root = $this->legacy(); $incident = $this->selectedIncident();
        $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput(['decision' => 'reviewed_association', 'incident_id' => $incident->id]));
        $original = $incident->client_id; $other = \App\Models\Client::factory()->create(['site_id' => $this->site->id]);
        DB::table('client_incidents')->where('id', $incident->id)->update(['client_id' => $other->id]);
        $this->integrity(fn () => $this->recovery()->inspect($this->manager, $root->id));
        DB::table('client_incidents')->where('id', $incident->id)->update(['client_id' => $original]);
        $this->assertNotNull($this->recovery()->inspect($this->manager, $root->id)['review']['facts']);
    }
    public function test_existing_typed_promotion_after_unknown_legacy_origin_is_recoverable_but_old_body_remains_sealed(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Legacy exposure association recovery has no route or caller: ChemicalExposureAssociationRecoveryService is reached only from the unmounted recovery evidence service. The SQL this test flags is an exists(select * ...) probe, not a body read.', 'mount exposure recovery with its routes.'));
        $root = $this->legacy(); $incident = $this->selectedIncident();
        $this->historicalCorrection($root, ['circumstances' => 'Actual older legacy correction']);
        $this->historicalCorrection($root, ['related_incident_id' => $incident->id, 'original_context' => [
            'schema_version' => 1, 'site_id' => $this->site->id, 'incident_id' => $incident->id, 'client_id' => $incident->client_id,
            'shift_id' => null, 'service_context_id' => null, 'stay_id' => null]]);
        [$dto, $queries] = $this->capturedQueries(fn () => $this->recovery()->inspect($this->manager, $root->id));
        $this->noOldBody($queries); $this->assertNull($dto['legacy_source']['medical_record']);
        $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput());
        $this->assertSame(2, $root->fresh()->source_revision); $this->assertSame(2, \App\Models\ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count());
    }
    public function test_real_prospective_source_and_unlinked_legacy_are_not_downgraded_into_recovery_purpose(): void
    {
        $root = $this->exposure(); $this->validation(fn () => $this->recovery()->inspect($this->manager, $root->id), 'source');
        $legacy = $this->legacy(); DB::table('substance_exposure_records')->where('id', $legacy->id)->update(['related_incident_id' => null]);
        $this->validation(fn () => $this->recovery()->inspect($this->manager, $legacy->id), 'source');
    }
    public function test_retained_governed_marker_downgrade_cannot_create_a_fake_legacy_recovery_case(): void
    {
        $root = $this->exposure(); DB::table('substance_exposure_records')->where('id', $root->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->integrity(fn () => $this->recovery()->inspect($this->manager, $root->id)); $this->assertSame(0, ChemicalExposureRecoveryCase::count());
    }
    public function test_same_uuid_lost_response_returns_original_review_after_later_review_without_duplicate_or_new_revision(): void
    {
        $root = $this->legacy(); $input = $this->reviewInput(); $r = $this->recovery()->recordReview($this->manager, $root->id, $input);
        $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput(['decision' => 'reviewed_no_association'], 1));
        $this->assertSame($r, $this->recovery()->recordReview($this->manager, $root->id, $input));
        $this->assertSame(2, ChemicalExposureRecoveryVersion::count()); $this->assertSame(2, ChemicalExposureRecoveryReceipt::count());
    }
    public function test_uuid_actor_root_verb_or_review_payload_cannot_be_rebound(): void
    {
        $root = $this->legacy(); $input = $this->reviewInput(); $this->recovery()->recordReview($this->manager, $root->id, $input);
        $other = $this->staff(['hazards.view', 'hazards.manage', 'hr.wellbeing.view']); $second = $this->legacy();
        foreach ([[$this->manager, $root->id, [...$input, 'reason' => 'Different submitted basis']], [$other, $root->id, $input], [$this->manager, $second->id, $input]] as [$actor, $id, $changed]) {
            $this->validation(fn () => $this->recovery()->recordReview($actor, $id, $changed), 'idempotency_key');
        }
        $this->assertSame(1, ChemicalExposureRecoveryCase::count());
    }
    public function test_stale_review_version_preserves_acceptance_and_does_not_change_old_source(): void
    {
        $root = $this->legacy(); $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput());
        $this->validation(fn () => $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput()), 'expected_version');
        $this->assertSame(1, ChemicalExposureRecoveryVersion::count()); $this->assertSame(0, $root->fresh()->source_revision);
    }
    public function test_version_save_veto_rolls_back_entire_new_case_and_uuid(): void
    {
        $root = $this->legacy(); ChemicalExposureRecoveryVersion::creating(fn () => false);
        $this->integrity(fn () => $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput()));
        $this->assertSame(0, ChemicalExposureRecoveryCase::count()); $this->assertSame(0, ChemicalExposureRecoveryReceipt::count());
        $this->assertSame(0, \App\Models\ChemicalCommandIntent::where('source_type', 'exposure_association_review')->count());
    }
    public function test_strict_audit_veto_rolls_back_versions_case_and_claim(): void
    {
        $root = $this->legacy(); AuditLog::creating(fn ($row) => str_starts_with($row->action, 'chemical.exposure.recovery.') ? false : null);
        $this->integrity(fn () => $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput()));
        $this->assertSame(0, ChemicalExposureRecoveryCase::count()); $this->assertSame(0, ChemicalExposureRecoveryVersion::count());
    }
    public function test_audit_digest_mutation_cannot_certify_a_review_or_reconstruct_a_receipt(): void
    {
        $root = $this->legacy(); $input = $this->reviewInput(); $this->recovery()->recordReview($this->manager, $root->id, $input);
        $receipt = ChemicalExposureRecoveryReceipt::sole(); $meta = $receipt->audit_metadata; $meta['binding_digest'] = str_repeat('a', 64);
        DB::table('audit_logs')->where('id', $receipt->audit_log_id)->update(['meta' => json_encode($meta, JSON_THROW_ON_ERROR)]);
        $this->integrity(fn () => $this->recovery()->inspect($this->manager, $root->id));
        $this->integrity(fn () => $this->recovery()->recordReview($this->manager, $root->id, $input));
    }
    public function test_unknown_new_body_mutation_is_detected_by_byte_digest_without_loading_its_prose(): void
    {
        $root = $this->legacy(); $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput(['notes' => 'SEALED-UNRESOLVED-NEW-PROSE']));
        DB::table('chemical_exposure_recovery_versions')->where('id', ChemicalExposureRecoveryVersion::sole()->id)->update(['content' => '{}']);
        $this->integrity(fn () => $this->recovery()->inspect($this->manager, $root->id));
    }
    public function test_immutable_history_rejects_model_update_delete_and_current_pointer_damage(): void
    {
        $root = $this->legacy(); $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput());
        $version = ChemicalExposureRecoveryVersion::sole(); $this->integrity(fn () => $version->update(['decision' => 'reviewed_association']));
        $this->integrity(fn () => $version->delete()); $this->integrity(fn () => ChemicalExposureRecoveryReceipt::sole()->delete());
        DB::table('chemical_exposure_recovery_cases')->where('id', $version->case_id)->update(['current_version_id' => null]);
        $this->integrity(fn () => $this->recovery()->inspect($this->manager, $root->id));
    }
    public function test_supplied_context_keeps_one_fresh_actor_wrong_viewer_missing_keys_and_lifetime_errors_visible(): void
    {
        $root = $this->legacy(); $standalone = $this->recovery()->inspect($this->manager, $root->id); $escaped = null;
        HealthSafetyReadContext::within($this->manager, ChemicalExposureCommandAccess::PERMISSIONS, function ($context) use ($root, $standalone, &$escaped) {
            $escaped = $context; $this->assertSame($standalone, $this->recovery()->inspect($this->manager, $root->id, $context));
            $this->integrity(fn () => $this->recovery()->inspect($this->reporter, $root->id, $context)); return null;
        });
        $this->integrity(fn () => $this->recovery()->inspect($this->manager, $root->id, $escaped));
        HealthSafetyReadContext::within($this->manager, ['hazards.view', 'hr.wellbeing.view'], function ($context) use ($root) {
            $this->integrity(fn () => $this->recovery()->inspect($this->manager, $root->id, $context)); return null;
        });
    }
    public function test_case_save_veto_cannot_leave_an_original_claim_or_a_partial_case(): void
    {
        $root = $this->legacy(); ChemicalExposureRecoveryCase::creating(fn () => false);
        $this->integrity(fn () => $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput()));
        $this->assertSame(0, ChemicalExposureRecoveryCase::count()); $this->assertSame(0, ChemicalExposureRecoveryVersion::count());
        $this->assertSame(0, \App\Models\ChemicalCommandIntent::where('source_type', 'exposure_association_review')->count());
    }
    public function test_receipt_save_veto_rolls_back_strict_audit_case_version_and_claim(): void
    {
        $root = $this->legacy(); ChemicalExposureRecoveryReceipt::creating(fn () => false);
        $this->integrity(fn () => $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput()));
        $this->assertSame(0, ChemicalExposureRecoveryCase::count()); $this->assertSame(0, ChemicalExposureRecoveryVersion::count());
        $this->assertSame(0, AuditLog::where('action', 'like', 'chemical.exposure.recovery.%')->count());
        $this->assertSame(0, \App\Models\ChemicalCommandIntent::where('source_type', 'exposure_association_review')->count());
    }
    public function test_actual_same_callback_original_root_change_after_final_identity_hydration_refuses_detached_result(): void
    {
        $root = $this->legacy(); $reads = 0;
        \App\Models\SubstanceExposureRecord::retrieved(function ($row) use ($root, &$reads) {
            if ($row->id === $root->id && ++$reads === 2) {
                DB::table('substance_exposure_records')->where('id', $root->id)->update(['created_by' => $this->manager->id]);
            }
        });
        $this->integrity(fn () => $this->recovery()->inspect($this->manager, $root->id));
        $this->assertSame(2, $reads); $this->assertSame(0, ChemicalExposureRecoveryCase::count());
    }
    public function test_current_case_identity_and_new_body_cannot_be_rebound_after_metadata_proof(): void
    {
        $root = $this->legacy(); $incident = $this->selectedIncident();
        $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput(['decision' => 'reviewed_association', 'incident_id' => $incident->id]));
        $changed = false;
        ChemicalExposureRecoveryVersion::retrieved(function ($row) use (&$changed, $root) {
            if (! $changed && array_key_exists('content', $row->getAttributes())) {
                $changed = true;
                DB::table('chemical_exposure_recovery_cases')->where('exposure_id', $root->id)->update(['worker_id' => $this->manager->id]);
            }
        });
        $this->integrity(fn () => $this->recovery()->inspect($this->manager, $root->id)); $this->assertTrue($changed);
    }
    public function test_exact_accepted_audit_is_proven_without_general_audit_grant_and_arbitrary_case_audit_is_not(): void
    {
        $root = $this->legacy(); $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput());
        $receipt = ChemicalExposureRecoveryReceipt::sole(); $case = ChemicalExposureRecoveryCase::sole();
        $fake = AuditLog::create(['user_id' => $this->manager->id, 'action' => 'chemical.exposure.recovery.record_legacy_review',
            'auditable_type' => $case->getMorphClass(), 'auditable_id' => $case->id, 'meta' => ['source_content_digest' => str_repeat('a', 64)]]);
        HealthSafetyReadContext::within($this->manager, ChemicalExposureCommandAccess::PERMISSIONS, function ($context) use ($receipt, $fake) {
            return $context->consumeViewer(ChemicalExposureCommandAccess::PERMISSIONS, function ($current, $reads, $sites) use ($receipt, $fake) {
                $proof = $this->recovery()->auditProof($receipt->audit_log_id, $current, $reads, $sites);
                $this->assertSame('recorded', $proof['attribution_state']); $this->assertSame('sealed', $proof['old_medical_state']);
                $this->assertNull($this->recovery()->auditProof($fake->id, $current, $reads, $sites)); return null;
            });
        });
    }
    public function test_external_review_timestamp_rewrite_does_not_certify_history_relative_to_accepted_body(): void
    {
        $root = $this->legacy(); $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput());
        DB::table('chemical_exposure_recovery_versions')->where('id', ChemicalExposureRecoveryVersion::sole()->id)
            ->update(['reviewed_at' => '2026-10-11 01:00:00.000000']);
        $this->integrity(fn () => $this->recovery()->inspect($this->manager, $root->id));
    }
    public function test_existing_other_chemical_family_uuid_cannot_be_claimed_as_recovery(): void
    {
        $root = $this->legacy(); $key = (string) \Illuminate\Support\Str::uuid();
        $this->service()->create($this->manager, ['idempotency_key' => $key, 'name' => 'Other exact catalogue claim', 'physical_form' => 'liquid']);
        $this->validation(fn () => $this->recovery()->recordReview($this->manager, $root->id, $this->reviewInput([], 0, $key)), 'idempotency_key');
        $this->assertSame(0, ChemicalExposureRecoveryCase::count());
        $this->assertSame(1, \App\Models\ChemicalCommandIntent::where('idempotency_key', $key)->count());
    }
}
