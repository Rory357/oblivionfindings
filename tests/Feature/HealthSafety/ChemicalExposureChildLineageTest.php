<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ChemicalExposureEvidence;
use App\Models\ChemicalExposureFollowup;
use App\Models\ChemicalExposureReview;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\SubstanceExposureRecord;
use App\Services\HealthSafety\ChemicalExposureCommandAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Whole canonical metadata/legacy admission contract. UNAPPLIED Source seam and all runtime remain UNRUN. */
final class ChemicalExposureChildLineageTest extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;
    protected function setUp(): void { parent::setUp(); Storage::fake('private'); }

    public function test_actual_governed_review_followup_and_evidence_retain_exact_accepted_original_parent(): void
    {
        foreach (['review','followup','evidence'] as $kind) {
            $root = $this->exposure(); $child = $this->typed($root, $kind);
            $version = $this->prove($root->fresh(), $child->fresh(), $kind);
            $this->assertSame($root->id, $version['exposure_id']); $this->assertSame($child->id, $version['source_id']);
            $this->assertSame($kind, $version['source_type']);
        }
    }

    public function test_genuine_unlinked_legacy_root_and_all_canonical_legacy_children_remain_unknown(): void
    {
        $root = $this->legacy();
        foreach (['review','followup','evidence'] as $kind) { $this->assertNull($this->prove($root, $this->legacyChild($root, $kind), $kind)); }
        $this->assertNull($this->prove($root, $root, 'exposure'));
        $this->assertSame(0, ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count());
    }

    public function test_genuine_legacy_child_on_later_governed_parent_does_not_receive_invented_child_proof(): void
    {
        $root = $this->exposure();
        foreach (['review','followup','evidence'] as $kind) { $this->assertNull($this->prove($root, $this->legacyChild($root, $kind), $kind)); }
        $this->assertSame(1, $this->prove($root, $root, 'exposure')['aggregate_revision']);
    }

    public function test_root_legacy_shortcut_cannot_accept_foreign_child_or_same_id_different_class(): void
    {
        $root = $this->legacy(); $foreign = $this->legacy(); $review = $this->legacyChild($foreign, 'review');
        foreach (['review','exposure','invented'] as $kind) { $this->integrity(fn () => $this->prove($root, $review, $kind)); }
        $this->integrity(fn () => $this->prove($root, $foreign, 'exposure'));
    }

    public function test_root_legacy_shortcut_refuses_schema_zero_nonzero_child_revision(): void
    {
        $root = $this->legacy();
        foreach (['review','followup','evidence'] as $kind) {
            $child = $this->legacyChild($root, $kind); DB::table($child->getTable())->where('id', $child->id)->update(['source_revision' => 7]);
            $this->integrity(fn () => $this->prove($root, $child->fresh(), $kind));
        }
    }

    public function test_root_legacy_shortcut_refuses_typed_child_without_retained_version(): void
    {
        $root = $this->legacy(); $child = $this->legacyChild($root, 'followup');
        DB::table($child->getTable())->where('id', $child->id)->update(['source_schema_version' => 1, 'source_revision' => 1]);
        $this->integrity(fn () => $this->prove($root, $child->fresh(), 'followup'));
    }

    public function test_all_moved_governed_children_downgraded_to_legacy_are_refused_before_private_version_or_child_body(): void
    {
        foreach (['review','followup','evidence'] as $kind) {
            $origin = $this->exposure(); $child = $this->typed($origin, $kind); $target = $this->legacy(); $original = $child->getAttributes();
            DB::table($child->getTable())->where('id', $child->id)->update(['exposure_id' => $target->id, 'source_schema_version' => 0, 'source_revision' => 0]);
            [, $queries] = $this->capturedQueries(fn () => $this->integrity(fn () => $this->prove($target, $child->fresh(), $kind)));
            // The fixture's fresh() above loads the intentional corruption before proof. The isolated seam below is metadata only.
            $identity = $this->identity($child->fresh());
            [, $queries] = $this->capturedQueries(fn () => $this->integrity(fn () => $this->preflight($target, $identity, $kind)));
            $this->noPrivateBodies($queries);
            DB::table($child->getTable())->where('id', $child->id)->update(array_intersect_key($original, array_flip(['exposure_id','source_schema_version','source_revision'])));
            $this->assertSame($origin->id, $this->prove($origin->fresh(), $child->fresh(), $kind)['exposure_id']);
        }
    }

    public function test_rebound_governed_child_with_intact_markers_is_not_current_target_child(): void
    {
        $origin = $this->exposure(); $child = $this->typed($origin, 'followup'); $target = $this->exposure();
        DB::table($child->getTable())->where('id', $child->id)->update(['exposure_id' => $target->id]);
        $identity = $this->identity($child->fresh());
        [, $queries] = $this->capturedQueries(fn () => $this->integrity(fn () => $this->preflight($target, $identity, 'followup')));
        $this->noPrivateBodies($queries);
    }

    public function test_same_parent_governed_marker_downgrade_is_not_genuine_legacy(): void
    {
        foreach (['review','followup','evidence'] as $kind) {
            $root = $this->exposure(); $child = $this->typed($root, $kind);
            DB::table($child->getTable())->where('id', $child->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
            $identity = $this->identity($child->fresh());
            [, $queries] = $this->capturedQueries(fn () => $this->integrity(fn () => $this->preflight($root->fresh(), $identity, $kind)));
            $this->noPrivateBodies($queries);
        }
    }

    public function test_foreign_original_site_withdrawal_does_not_require_old_body_to_refuse_moved_child(): void
    {
        $this->manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $origin = $this->exposure(); $child = $this->typed($origin, 'followup'); $worker = $this->staff([], $this->foreignSite);
        $target = $this->legacy(['site_id' => $this->foreignSite->id, 'user_id' => $worker->id]);
        DB::table($child->getTable())->where('id', $child->id)->update(['exposure_id' => $target->id, 'source_schema_version' => 0, 'source_revision' => 0]);
        $identity = $this->identity($child->fresh());
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => []]);
        [, $queries] = $this->capturedQueries(fn () => $this->integrity(fn () => $this->preflight($target, $identity, 'followup')));
        $this->noPrivateBodies($queries); $this->assertSame($target->id, $child->fresh()->exposure_id);
    }

    public function test_in_memory_legacy_parent_site_or_worker_rebind_cannot_certify_canonical_source(): void
    {
        $root = $this->legacy(); $other = $this->staff([]); $root->user_id = $other->id;
        $this->integrity(fn () => $this->prove($root, $root, 'exposure'));
        $root = $root->fresh(); $this->manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->foreignSite->id]]); $root->site_id = $this->foreignSite->id;
        $this->integrity(fn () => $this->prove($root, $root, 'exposure'));
    }

    public function test_retained_root_kind_identity_cannot_become_legacy_when_version_parent_metadata_is_rebound(): void
    {
        $root = $this->exposure(); $other = $this->legacy(); $version = ChemicalExposureSourceVersion::where('exposure_id', $root->id)->sole();
        DB::table('chemical_exposure_source_versions')->where('id', $version->id)->update(['exposure_id' => $other->id]);
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $root = $root->fresh();
        [, $queries] = $this->capturedQueries(fn () => $this->integrity(fn () => $this->preflight($root, $root, 'exposure')));
        $this->noPrivateBodies($queries);
    }

    public function test_current_read_revoke_precedes_any_lineage_or_private_child_identity_sql(): void
    {
        $root = $this->exposure(); $child = $this->typed($root, 'review'); $identity = $this->identity($child);
        $this->manager->load('roles.permissions', 'permissionOverrides'); $this->allow($this->manager, 'hr.wellbeing.view', false);
        [, $queries] = $this->capturedQueries(fn () => $this->denial(fn () => $this->preflight($root->fresh(), $identity, 'review')));
        foreach ($queries as $query) { $this->assertStringNotContainsString('chemical_exposure_reviews', strtolower($query['query'])); $this->assertStringNotContainsString('chemical_exposure_source_versions', strtolower($query['query'])); }
        $this->allow($this->manager, 'hr.wellbeing.view'); $this->assertSame($child->id, $this->prove($root->fresh(), $child, 'review')['source_id']);
    }

    public function test_capture_only_authority_never_admits_saved_private_child_provenance(): void
    {
        $root = $this->exposure(); $child = $this->typed($root, 'followup');
        $this->denial(fn () => $this->preflight($root->fresh(), $this->identity($child), 'followup', $this->reporter));
        $this->integrity(fn () => $this->preflight($root->fresh(), $this->identity($child), 'followup', $this->reporter, 'capture'));
    }

    public function test_preflight_reads_only_identity_and_checks_current_identity_again_without_a_reusable_authority_handle(): void
    {
        $root = $this->exposure(); $child = $this->typed($root, 'review'); $identity = $this->identity($child);
        [, $queries] = $this->capturedQueries(fn () => $this->preflight($root->fresh(), $identity, 'review'));
        $this->noPrivateBodies($queries);
        DB::table($child->getTable())->where('id', $child->id)->update(['source_revision' => $child->source_revision + 1]);
        $this->integrity(fn () => $this->preflight($root->fresh(), $identity, 'review'));
    }

    public function test_closed_kind_and_actual_model_class_are_checked_even_for_genuine_legacy_root(): void
    {
        $root = $this->legacy(); $review = $this->legacyChild($root, 'review'); $followup = $this->legacyChild($root, 'followup');
        $this->integrity(fn () => $this->preflight($root, $review, 'followup'));
        $this->integrity(fn () => $this->preflight($root, $followup, 'review'));
        $this->integrity(fn () => $this->preflight($root, $review, 'unknown'));
        $unsaved = new ChemicalExposureReview(['id' => $review->id, 'exposure_id' => $root->id, 'source_schema_version' => 0, 'source_revision' => 0]);
        $this->integrity(fn () => $this->preflight($root, $unsaved, 'review'));
    }

    public function test_soft_deleted_governed_evidence_retains_original_lineage_after_marker_downgrade(): void
    {
        $root = $this->exposure(); $file = $this->typed($root, 'evidence');
        $this->evidenceService()->remove($root->fresh(), $file->id, $this->manager, $this->privateCommand($root, ['reason' => 'Actual source removal']));
        DB::table($file->getTable())->where('id', $file->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $file = ChemicalExposureEvidence::withTrashed()->findOrFail($file->id);
        $this->integrity(fn () => $this->prove($root->fresh(), $file, 'evidence'));
    }

    public function test_canonical_complete_and_reassign_refuse_moved_downgraded_child_without_accepting_new_intent(): void
    {
        $origin = $this->exposure(); $child = $this->typed($origin, 'followup'); $target = $this->legacy();
        DB::table($child->getTable())->where('id', $child->id)->update(['exposure_id' => $target->id, 'source_schema_version' => 0, 'source_revision' => 0]);
        $before = $target->getAttributes();
        [, $queries] = $this->capturedQueries(fn () => $this->integrity(fn () => $this->exposureService()->completeFollowup($target, $child->id, $this->manager,
            $this->privateCommand($target, ['completed_at' => '2026-10-12T00:45:00Z', 'completion_notes' => 'Must not accept foreign work']))));
        $this->noPrivateBodies($queries);
        [, $queries] = $this->capturedQueries(fn () => $this->integrity(fn () => $this->exposureService()->reassignFollowup($target, $child->id, $this->manager,
            $this->privateCommand($target, ['assigned_to' => $this->manager->id, 'reason' => 'Must not accept rebound work']))));
        $this->noPrivateBodies($queries);
        $this->assertSame($before, $target->fresh()->getAttributes());
        $this->assertNull($child->fresh()->completed_at);
        $this->assertSame(0, DB::table('chemical_exposure_command_receipts')->where('exposure_id', $target->id)->count());
        $this->assertSame(0, DB::table('chemical_command_intents')->where('source_type', 'exposure')->where('source_id', $target->id)->count());
    }

    public function test_source_audit_subject_refuses_rebound_children_before_full_private_body_sql(): void
    {
        foreach (['review','followup','evidence'] as $kind) {
            $origin = $this->exposure(); $child = $this->typed($origin, $kind); $target = $this->legacy();
            DB::table($child->getTable())->where('id', $child->id)->update(['exposure_id' => $target->id, 'source_schema_version' => 0, 'source_revision' => 0]);
            [$proof, $queries] = $this->capturedQueries(fn () => app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager,
                fn ($current, $reads, $sites) => $this->exposureService()->sourceProofForAuditSubject($child::class, $child->id, $current, $reads, $sites)));
            $this->assertSame(['attribution_state' => 'repair_required', 'source_id' => $target->id], $proof);
            $this->noPrivateBodies($queries);
        }
    }

    public function test_retained_child_receipt_refuses_legacy_after_version_kind_identity_is_moved(): void
    {
        foreach (['review','followup','evidence'] as $kind) {
            $origin = $this->exposure(); $child = $this->typed($origin, $kind); $target = $this->legacy();
            $version = ChemicalExposureSourceVersion::where('source_type', $kind)->where('source_id', $child->id)->sole();
            // Deliberate immutable metadata corruption; no FK/schema uniqueness is disabled or weakened.
            DB::table('chemical_exposure_source_versions')->where('id', $version->id)->update(['source_type' => 'exposure', 'source_id' => $origin->id]);
            DB::table($child->getTable())->where('id', $child->id)->update(['exposure_id' => $target->id, 'source_schema_version' => 0, 'source_revision' => 0]);
            $identity = $this->identity($child->fresh());
            [, $queries] = $this->capturedQueries(fn () => $this->integrity(fn () => $this->preflight($target, $identity, $kind)));
            $this->noPrivateBodies($queries);
        }
    }

    public function test_version_bearing_root_receipt_prevents_body_legacy_admission_after_version_and_markers_are_moved(): void
    {
        $root = $this->exposure(['symptoms' => 'PRIVATE-ORIGINAL-ROOT']); $other = $this->legacy();
        $version = ChemicalExposureSourceVersion::where('exposure_id', $root->id)->sole();
        DB::table('chemical_exposure_source_versions')->where('id', $version->id)->update(['exposure_id' => $other->id, 'source_id' => $other->id]);
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        [, $queries] = $this->capturedQueries(fn () => $this->validation(fn () => app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager,
            fn ($current, $reads, $sites) => app(\App\Services\HealthSafety\ChemicalExposureAccessService::class)->assertExposure($root->id, $current, $reads, $sites)), 'source'));
        $this->noPrivateBodies($queries);
        foreach ($queries as $query) { $this->assertDoesNotMatchRegularExpression('/select\s+\*\s+from\s+[`"]?substance_exposure_records/i', $query['query']); }
        $this->integrity(fn () => $this->preflight($root->fresh(), $root->fresh(), 'exposure'));
    }

    public function test_legitimate_unversioned_legacy_noop_receipt_keeps_unknown_provenance_and_original_metadata_admission(): void
    {
        $root = $this->legacy(); $r = $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Actual unchanged legacy intent']));
        $this->assertFalse($r['changed']); $this->assertNull($r['source_version_id']);
        $this->assertNull($this->prove($root->fresh(), $root->fresh(), 'exposure'));
        $dto = app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager, fn ($current, $reads, $sites) =>
            $this->exposureService()->reportedFactsForSource($root->id, $current, $reads, $sites));
        $this->assertSame('legacy_unconfirmed', $dto['fact_states']['medical_attention_sought']['state']);
        $this->assertSame(0, ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count());
    }

    public function test_legacy_noop_original_site_withdrawal_cannot_be_bypassed_by_current_raw_parent_rebind(): void
    {
        $root = $this->legacy(); $r = $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Retained unchanged original Site intent']));
        $this->assertNull($r['source_version_id']); $worker = $this->staff([], $this->foreignSite);
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['site_id' => $this->foreignSite->id, 'user_id' => $worker->id]);
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => []]);
        [, $queries] = $this->capturedQueries(fn () => $this->denial(fn () => app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager,
            fn ($current, $reads, $sites) => app(\App\Services\HealthSafety\ChemicalExposureAccessService::class)->assertExposure($root->id, $current, $reads, $sites)), 404));
        $this->noPrivateBodies($queries);
        foreach ($queries as $query) { $this->assertDoesNotMatchRegularExpression('/select\s+\*\s+from\s+[`"]?substance_exposure_records/i', $query['query']); }
    }

    public function test_root_body_admission_recognises_original_kind_identity_even_without_retained_receipt_row(): void
    {
        $root = $this->exposure(['symptoms' => 'PRIVATE-SEALED-ROOT']); $other = $this->legacy();
        $version = ChemicalExposureSourceVersion::where('exposure_id', $root->id)->sole();
        DB::table('chemical_exposure_source_versions')->where('id', $version->id)->update(['exposure_id' => $other->id]);
        // Explicit lost receipt corruption, not a provenance backfill or FK/schema alteration.
        DB::table('chemical_exposure_command_receipts')->where('exposure_id', $root->id)->delete();
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        [, $queries] = $this->capturedQueries(fn () => $this->validation(fn () => app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager,
            fn ($current, $reads, $sites) => app(\App\Services\HealthSafety\ChemicalExposureAccessService::class)->assertExposure($root->id, $current, $reads, $sites)), 'source'));
        $this->noPrivateBodies($queries);
        foreach ($queries as $query) { $this->assertDoesNotMatchRegularExpression('/select\s+\*\s+from\s+[`"]?substance_exposure_records/i', $query['query']); }
    }

    public function test_typed_child_receipt_alone_cannot_admit_missing_exact_child_version_before_body(): void
    {
        $root = $this->exposure(); $child = $this->typed($root, 'review');
        $version = ChemicalExposureSourceVersion::where('source_type', 'review')->where('source_id', $child->id)->sole();
        DB::table('chemical_exposure_source_versions')->where('id', $version->id)->update(['source_type' => 'exposure', 'source_id' => $root->id]);
        $root = $root->fresh(); $identity = $this->identity($child);
        [, $queries] = $this->capturedQueries(fn () => $this->integrity(fn () => $this->preflight($root, $identity, 'review')));
        $this->noPrivateBodies($queries);
    }

    public function test_original_capture_only_actor_keeps_internal_root_proof_without_saved_private_read_grant(): void
    {
        $accepted = $this->capture(['medical_attention_sought' => true], $this->reporter);
        $root = SubstanceExposureRecord::findOrFail($accepted['root_id']);
        $versionId = app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->reporter, function ($current, $reads, $sites) use ($root): int {
            $version = $this->exposureService()->currentVersion($root, $root, 'exposure', $reads, $current, $sites, 'capture');
            return (int) $version->id; // Internal original acceptance only; no private snapshot/model escapes.
        });
        $this->assertSame($accepted['source_version_id'], $versionId);
        $this->assertSame($accepted, $this->exposureService()->confirmationForKey($this->reporter, $accepted['idempotency_key'], $root->id));
        $this->denial(fn () => app(\App\Services\HealthSafety\ChemicalExposureReadService::class)->detail($this->reporter, $root->id));
    }

    public function test_two_actual_unchanged_legacy_noops_keep_latest_identity_unknown_without_new_source_version(): void
    {
        $root = $this->legacy();
        foreach (['First unchanged intent','Second unchanged intent'] as $reason) {
            $r = $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => $reason]));
            $this->assertFalse($r['changed']); $this->assertNull($r['source_version_id']);
        }
        $this->assertNull($this->prove($root->fresh(), $root->fresh(), 'exposure'));
        $dto = app(\App\Services\HealthSafety\ChemicalExposureWorkspaceService::class)->detail($this->manager, $root->id);
        $this->assertSame('legacy_unknown', $dto['source_integrity']);
        $this->assertSame('legacy_unconfirmed', $dto['record']['fact_states']['medical_attention_sought']['state']);
        $this->assertSame(0, ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count());
    }

    public function test_latest_legacy_noop_tuple_refuses_raw_worker_or_substance_rebind_before_body(): void
    {
        foreach (['user_id','hazardous_substance_id'] as $field) {
            $root = $this->legacy();
            $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Genuine unchanged original identity']));
            $replacement = $field === 'user_id' ? $this->staff([])->id : $this->catalogue(['name' => 'Another actual reference'])->id;
            DB::table('substance_exposure_records')->where('id', $root->id)->update([$field => $replacement]);
            $this->assertSealedBeforeBody($root->id, 'latest accepted unchanged source tuple');
        }
    }

    public function test_latest_legacy_noop_tuple_refuses_raw_known_absent_context_in_place_of_unknown_before_body(): void
    {
        $root = $this->legacy(); $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Genuine unchanged unknown context']));
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['original_context' => json_encode([
            'schema_version' => 1, 'site_id' => $this->site->id, 'incident_id' => null, 'client_id' => null,
            'shift_id' => null, 'service_context_id' => null, 'stay_id' => null], JSON_THROW_ON_ERROR)]);
        $this->assertSealedBeforeBody($root->id, 'latest accepted unchanged source tuple');
    }

    public function test_latest_legacy_noop_tuple_refuses_new_raw_association_even_when_current_incident_is_readable(): void
    {
        $root = $this->legacy(); $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, ['reason' => 'Unchanged absent pointer intent']));
        $this->allow($this->manager, 'incidents.viewAny'); $this->allow($this->manager, 'clients.viewAny');
        $client = \App\Models\Client::factory()->create(['site_id' => $this->site->id]);
        $incident = \App\Models\ClientIncident::withoutEvents(fn () => \App\Models\ClientIncident::factory()->submitted()->create([
            'site_id' => $this->site->id, 'client_id' => $client->id, 'reported_by' => $this->manager->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null]));
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['related_incident_id' => $incident->id,
            'original_context' => json_encode(['schema_version' => 1, 'site_id' => $this->site->id, 'incident_id' => $incident->id,
                'client_id' => $client->id, 'shift_id' => null, 'service_context_id' => null, 'stay_id' => null], JSON_THROW_ON_ERROR)]);
        $this->assertSealedBeforeBody($root->id, 'latest accepted unchanged source tuple');
    }

    public function test_earlier_accepted_unknown_link_noop_remains_sealed_after_pointer_clear_and_later_matching_noop(): void
    {
        $client = \App\Models\Client::factory()->create(['site_id' => $this->site->id]);
        $incident = \App\Models\ClientIncident::withoutEvents(fn () => \App\Models\ClientIncident::factory()->submitted()->create([
            'site_id' => $this->site->id, 'client_id' => $client->id, 'reported_by' => $this->manager->id,
            'shift_id' => null, 'service_context_id' => null, 'respite_stay_id' => null]));
        $root = $this->legacy(['related_incident_id' => $incident->id, 'original_context' => null]);
        $old = $this->historicalNoop($root);
        DB::table('substance_exposure_records')->where('id', $root->id)->update(['related_incident_id' => null]);
        $later = $this->historicalNoop($root->fresh());
        $this->assertNull($old['source_version_id']); $this->assertNull($later['source_version_id']);
        $this->assertSealedBeforeBody($root->id, 'Retained linked legacy no-op origin is unproved');
        $this->assertNull($root->fresh()->related_incident_id); $this->assertSame(0, $root->fresh()->source_revision);
    }

    public function test_actual_legacy_completion_promotes_only_new_snapshot_and_reads_without_certifying_assignment(): void
    {
        foreach ([$this->legacy(), $this->exposure()] as $root) {
            $child = $this->legacyChild($root, 'followup'); $old = $child->getAttributes();
            $input = $this->privateCommand($root, ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Actual current named executor completion']);
            $r = $this->exposureService()->completeFollowup($root, $child->id, $this->manager, $input);
            $this->assertTrue($r['accepted']); $this->assertSame('complete_followup', $r['command']);
            $this->assertSame(1, $child->fresh()->source_schema_version); $this->assertSame($r['version'], $child->fresh()->source_revision);
            $version = ChemicalExposureSourceVersion::findOrFail($r['source_version_id']);
            $this->assertSame(0, $version->content['source_before']['source_schema_version']); $this->assertSame(0, $version->content['source_before']['source_revision']);
            $this->assertSame(1, $version->content['source_after']['source_schema_version']);
            $this->assertSame('legacy_unknown', $version->content['facts']['assignment_attribution_state']);
            $this->assertSame('recorded', $version->content['facts']['completion_attribution_state']);
            foreach (['instruction','assigned_to','recorded_by','assignee_snapshot'] as $field) { $this->assertSame($old[$field], $child->fresh()->getRawOriginal($field)); }
            $this->assertSame($r['source_version_id'], $this->prove($root->fresh(), $child->fresh(), 'followup')['id']);
            $dto = app(\App\Services\HealthSafety\ChemicalExposureWorkspaceService::class)->detail($this->manager, $root->id);
            $row = $dto['followups']['items'][0]; $this->assertSame('recorded', $row['attribution_state']);
            $this->assertSame('legacy_unknown', $row['assignment_attribution_state']);
            $this->assertSame('recorded', $row['completion_attribution_state']);
            $this->assertNotNull($row['completed_at']); $this->assertFalse($row['can_complete']); $this->assertFalse($row['can_reassign']);
            $this->assertSame($r, $this->exposureService()->completeFollowup($root, $child->id, $this->manager, $input));
        }
    }

    public function test_legacy_completion_save_or_audit_veto_preserves_original_schema_and_all_atomic_boundaries(): void
    {
        foreach (['child','audit'] as $veto) {
            $root = $this->legacy(); $child = $this->legacyChild($root, 'followup'); $before = $child->getAttributes();
            if ($veto === 'child') { ChemicalExposureFollowup::updating(fn () => false); }
            else { \App\Models\AuditLog::creating(fn ($row) => $row->action === 'chemical.exposure.complete_followup' ? false : null); }
            try { $this->integrity(fn () => $this->exposureService()->completeFollowup($root, $child->id, $this->manager,
                $this->privateCommand($root, ['completed_at' => '2026-10-12T01:00:00Z', 'completion_notes' => 'Must remain uncertain on refusal']))); }
            finally { ChemicalExposureFollowup::flushEventListeners(); \App\Models\AuditLog::flushEventListeners(); }
            $this->assertSame($before, $child->fresh()->getAttributes()); $this->assertSame(0, $root->fresh()->source_schema_version);
            $this->assertSame(0, $root->fresh()->source_revision);
            $this->assertSame(0, ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count());
            $this->assertSame(0, DB::table('chemical_exposure_command_receipts')->where('exposure_id', $root->id)->count());
        }
    }

    private function assertSealedBeforeBody(int $rootId, string $reason): void
    {
        [, $queries] = $this->capturedQueries(function () use ($rootId, $reason): void {
            try {
                app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager, fn ($current, $reads, $sites) =>
                    app(\App\Services\HealthSafety\ChemicalExposureAccessService::class)->assertExposure($rootId, $current, $reads, $sites));
                $this->fail('Expected metadata-only original tuple refusal before medical body.');
            } catch (\Illuminate\Validation\ValidationException $error) {
                $this->assertSame(['source'], array_keys($error->errors())); $this->assertStringContainsString($reason, $error->errors()['source'][0]);
            }
        });
        $this->noPrivateBodies($queries);
        foreach ($queries as $query) { $this->assertDoesNotMatchRegularExpression('/^select\s+\*\s+from\s+[`"]?substance_exposure_records/i', $query['query']); }
    }
    /** Genuine retained no-op ledger acceptance from the prior unsealed handler, not invented digest/receipt JSON. */
    private function historicalNoop(SubstanceExposureRecord $root): array
    {
        return app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager, function ($current, $reads, $sites) use ($root): array {
            $access = app(ChemicalExposureCommandAccess::class); $access->assertOperation($current, $reads, 'manage'); $access->site($root->site_id, $reads, $sites);
            $values = ['idempotency_key' => (string) Str::uuid(), 'expected_version' => 0, 'reason' => 'Actual prior unchanged source acceptance'];
            $ledger = app(\App\Services\HealthSafety\ChemicalExposureLedger::class);
            $intent = $ledger->claim($current, $reads, $root->id, $root->hazardous_substance_id, 'correct_exposure', $values, $sites);
            return $ledger->accept($intent, $root, $root, 'exposure', null, $current, $reads, false, $sites);
        });
    }

    private function prove(SubstanceExposureRecord $root, Model $child, string $kind): ?array
    {
        return app(ChemicalExposureCommandAccess::class)->withCurrentViewer($this->manager, function ($current, $reads, $sites) use ($root, $child, $kind): ?array {
            $version = $this->exposureService()->currentVersion($root, $child, $kind, $reads, $current, $sites);
            return $version === null ? null : array_intersect_key($version->attributesToArray(), array_flip(['id','source_type','source_id','exposure_id','aggregate_revision']));
        });
    }
    private function preflight(SubstanceExposureRecord $root, Model $child, string $kind, $viewer = null, string $purpose = 'read'): void
    {
        app(ChemicalExposureCommandAccess::class)->withCurrentViewer($viewer ?? $this->manager, function ($current, $reads, $sites) use ($root, $child, $kind, $purpose): void {
            $this->exposureService()->assertSourceIdentityBeforeBody($root, $child, $kind, $reads, $current, $sites, $purpose);
        });
    }
    private function identity(Model $child): Model
    {
        $query = $child instanceof ChemicalExposureEvidence ? $child::withTrashed() : $child::query();
        return $query->whereKey($child->id)->firstOrFail(['id','exposure_id','source_schema_version','source_revision']);
    }
    private function legacy(array $facts = []): SubstanceExposureRecord
    {
        return SubstanceExposureRecord::withoutEvents(fn () => SubstanceExposureRecord::factory()->create(['hazardous_substance_id' => $this->catalogue()->id,
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'created_by' => $this->manager->id,
            'exposed_at' => '2026-10-12 00:00:00', 'related_incident_id' => null, 'original_context' => null,
            'source_schema_version' => 0, 'source_revision' => 0, ...$facts]))->fresh();
    }
    private function typed(SubstanceExposureRecord $root, string $kind): Model
    {
        if ($kind === 'review') {
            $r = $this->exposureService()->review($root, $this->manager, $this->privateCommand($root,
                ['reviewed_at' => '2026-10-12T00:30:00Z', 'outcome' => 'recorded', 'notes' => 'PRIVATE-ORIGINAL-REVIEW']));
            return ChemicalExposureReview::findOrFail($r['source_id']);
        }
        if ($kind === 'followup') {
            $r = $this->exposureService()->assignFollowup($root, $this->manager, $this->privateCommand($root,
                ['assigned_to' => $this->manager->id, 'instruction' => 'PRIVATE-ORIGINAL-INSTRUCTION', 'due_at' => '2026-10-13T00:00:00Z']));
            return ChemicalExposureFollowup::findOrFail($r['source_id']);
        }
        $r = $this->evidenceService()->upload($root, $this->manager, UploadedFile::fake()->createWithContent('actual.pdf', "%PDF-1.4\nPRIVATE-ORIGINAL-BYTES\n%%EOF"),
            $this->privateCommand($root, ['description' => 'PRIVATE-ORIGINAL-FILE-DESCRIPTION']));
        return ChemicalExposureEvidence::findOrFail($r['source_id']);
    }
    private function legacyChild(SubstanceExposureRecord $root, string $kind): Model
    {
        $base = ['exposure_id' => $root->id, 'source_schema_version' => 0, 'source_revision' => 0];
        $row = match ($kind) {
            'review' => ChemicalExposureReview::create([...$base, 'reviewed_at' => '2026-10-12 00:30:00', 'outcome' => 'recorded',
                'notes' => 'Retained unknown original provenance', 'recorded_by' => $this->manager->id, 'recorder_snapshot' => ['id' => $this->manager->id]]),
            'followup' => ChemicalExposureFollowup::create([...$base, 'assigned_to' => $this->manager->id, 'assignee_snapshot' => ['id' => $this->manager->id],
                'instruction' => 'Retained unknown original provenance', 'due_at' => '2026-10-13 00:00:00', 'recorded_by' => $this->manager->id]),
            'evidence' => ChemicalExposureEvidence::create([...$base, 'disk' => 'private', 'path' => 'health-safety/chemical-exposures/legacy/'.Str::uuid(),
                'original_name' => 'legacy.pdf', 'mime' => 'application/pdf', 'size' => 4, 'sha256' => hash('sha256','test'),
                'description' => 'Retained unknown original provenance', 'uploaded_by' => $this->manager->id, 'uploader_snapshot' => ['id' => $this->manager->id]]),
        };
        return $row->fresh(); // Compare actual persisted JSON/scalar representation, not unsaved factory transport types.
    }
    private function noPrivateBodies(array $queries): void
    {
        foreach ($queries as $query) {
            foreach (['chemical_exposure_reviews','chemical_exposure_followups','chemical_exposure_evidence','chemical_exposure_source_versions'] as $table) {
                if (preg_match('/\bfrom\s+[`"]?'.preg_quote($table, '/').'\b/i', $query['query'])) {
                    // EXISTS(select *) proves presence; it does not hydrate a source body or SELECT its columns.
                    if (preg_match('/^select\s+exists\s*\(/i', $query['query'])) { continue; }
                    $selected = preg_split('/\s+from\s+/i', strtolower($query['query']), 2)[0];
                    $this->assertStringNotContainsString('select *', $selected);
                    foreach (['`notes`','`instruction`','`path`','`content`','actor_snapshot','recorder_snapshot'] as $field) {
                        $this->assertStringNotContainsString($field, $selected);
                    }
                }
            }
        }
    }
}
