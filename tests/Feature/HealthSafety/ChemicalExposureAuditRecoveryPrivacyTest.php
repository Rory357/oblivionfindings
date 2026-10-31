<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalCommandIntent;
use App\Models\ChemicalExposureCommandReceipt;
use App\Models\ChemicalExposureEvidence;
use App\Models\ChemicalExposureEvidenceCleanup;
use App\Models\ChemicalExposureEvidenceStaging;
use App\Models\ChemicalExposureHsProjection;
use App\Models\ChemicalExposureSourceDelivery;
use App\Models\ChemicalExposureSourceVersion;
use App\Models\SubstanceExposureRecord;
use App\Models\User;
use App\Services\HealthSafety\ChemicalCommandValues;
use App\Services\HealthSafety\ChemicalExposureAuditAccessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Real reservation/acceptance fixtures require the existing guarded physical-commit MySQL harness. UNRUN. */
final class ChemicalExposureAuditRecoveryPrivacyTest extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allow($this->manager, 'audit.viewAny');
        Storage::fake('private');
    }

    public function test_actual_accepted_evidence_stage_receipt_and_original_intent_share_current_audit_source_proof(): void
    {
        [$root, $file, $stage, $receipt] = $this->uploaded();
        $intent = ChemicalCommandIntent::findOrFail($stage->intent_id);
        $rows = [
            $this->audit(ChemicalExposureEvidence::class, $file->id),
            $this->audit(ChemicalExposureEvidenceStaging::class, $stage->id),
            $this->audit(ChemicalExposureCommandReceipt::class, $receipt->id),
            $this->audit(ChemicalCommandIntent::class, $intent->id),
        ];
        $ids = array_map(fn ($row) => $row->id, $rows);
        $this->assertSame($ids, $this->visible($ids));
        $this->assertSame($root->id, $stage->exposure_id);
        $this->assertSame('accepted', $stage->state);
        $this->assertTrue(Storage::disk('private')->exists($file->path));
    }

    public function test_auditor_need_not_be_historical_actor_but_private_site_and_dual_authority_are_required(): void
    {
        [$root, $file, $stage] = $this->uploaded();
        $viewer = $this->staff(['audit.viewAny', 'hazards.view', 'hr.wellbeing.view']);
        $rows = [$this->audit(ChemicalExposureEvidence::class, $file->id), $this->audit(ChemicalExposureEvidenceStaging::class, $stage->id)];
        $ids = array_column(array_map(fn ($row) => ['id' => $row->id], $rows), 'id');
        $this->assertNotSame($viewer->id, $stage->actor_id);
        $this->assertSame($ids, $this->visible($ids, $viewer));
        $this->allow($viewer, 'hr.wellbeing.view', false);
        $this->assertSame([], $this->visible($ids, $viewer));
        $this->allow($viewer, 'hr.wellbeing.view');
        $viewer->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id]);
        $this->assertSame([], $this->visible($ids, $viewer));
    }

    public function test_original_staging_claim_corruption_cannot_be_proved_by_matching_parent_id(): void
    {
        [$root, $file, $stage] = $this->uploaded();
        $row = $this->audit(ChemicalExposureEvidenceStaging::class, $stage->id);
        $this->assertSame([$row->id], $this->visible([$row->id]));
        DB::table('chemical_exposure_evidence_staging')->where('id', $stage->id)->update(['reservation_digest' => str_repeat('0', 64)]);
        $this->assertSame([], $this->visible([$row->id]));
        $this->assertTrue(Storage::disk('private')->exists($file->path));
    }

    public function test_accepted_receipt_and_stage_binding_remain_exact_when_another_source_exists(): void
    {
        [$root, $file, $stage] = $this->uploaded();
        [$otherRoot, $otherFile, $otherStage, $otherReceipt] = $this->uploaded();
        $row = $this->audit(ChemicalExposureEvidenceStaging::class, $stage->id);
        DB::table('chemical_exposure_evidence_staging')->where('id', $stage->id)->update([
            'receipt_id' => $otherReceipt->id, 'evidence_id' => $otherFile->id]);
        $this->assertSame([], $this->visible([$row->id]));
        $this->assertNotSame($root->id, $otherRoot->id);
    }

    public function test_actual_removed_evidence_cleanup_audit_retains_exact_accepted_removal_proof(): void
    {
        [$root, $file] = $this->uploaded();
        $this->evidenceService()->remove($root, $file->id, $this->manager,
            $this->privateCommand($root, ['reason' => 'Remove retained private evidence']));
        $cleanup = ChemicalExposureEvidenceCleanup::where('exposure_id', $root->id)->sole();
        $row = $this->audit(ChemicalExposureEvidenceCleanup::class, $cleanup->id);
        $this->assertSame([$row->id], $this->visible([$row->id]));
        DB::table('chemical_exposure_evidence_cleanups')->where('id', $cleanup->id)->update(['sha256' => str_repeat('f', 64)]);
        $this->assertSame([], $this->visible([$row->id]));
    }

    public function test_moved_child_parent_fails_before_its_old_metadata_is_presented_as_current(): void
    {
        [$root, $file] = $this->uploaded();
        $other = $this->exposure();
        $row = $this->audit(ChemicalExposureEvidence::class, $file->id);
        DB::table('chemical_exposure_evidence')->where('id', $file->id)->update(['exposure_id' => $other->id]);
        $this->assertSame([], $this->visible([$row->id]));
        $this->assertTrue(Storage::disk('private')->exists($file->path));
    }

    public function test_delivery_audit_revalidates_original_immutable_membership_and_current_source(): void
    {
        $root = $this->exposure();
        $delivery = ChemicalExposureSourceDelivery::where('exposure_id', $root->id)->where('kind', 'health_safety')->sole();
        $row = $this->audit(ChemicalExposureSourceDelivery::class, $delivery->id);
        $this->assertSame([$row->id], $this->visible([$row->id]));
        $membership = $delivery->membership; $membership['worker_id'] = $this->manager->id;
        DB::table('chemical_exposure_source_deliveries')->where('id', $delivery->id)->update(['membership' => json_encode($membership, JSON_THROW_ON_ERROR)]);
        $this->assertSame([], $this->visible([$row->id]));
    }

    public function test_old_delivery_version_cannot_be_locally_recoded_after_a_later_genuine_aggregate_command(): void
    {
        $root = $this->exposure();
        $delivery = ChemicalExposureSourceDelivery::where('exposure_id', $root->id)->where('kind', 'health_safety')->sole();
        $version = ChemicalExposureSourceVersion::findOrFail($delivery->source_version_id);
        $this->exposureService()->correct($root, $this->manager,
            $this->privateCommand($root, ['reason' => 'Record actual later correction', 'circumstances' => 'Later retained fact']));
        $row = $this->audit(ChemicalExposureSourceDelivery::class, $delivery->id);
        $this->assertSame([$row->id], $this->visible([$row->id]));
        $content = $version->content;
        $content['root_after']['symptoms'] = 'Recomputed local historical body';
        $content['source_after']['symptoms'] = 'Recomputed local historical body';
        DB::table('chemical_exposure_source_versions')->where('id', $version->id)->update([
            'content' => json_encode($content, JSON_THROW_ON_ERROR), 'content_digest' => ChemicalCommandValues::digest($content)]);
        $this->assertSame([], $this->visible([$row->id]));
    }

    public function test_shared_public_chemical_intent_audit_is_not_blanket_classified_as_private_exposure(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure audit row-hiding guard (ChemicalExposureAuditAccessService::materialize/scope) is not mounted, by Stephan\'s decision. The audit pages keep main\'s rows for audit.viewAny holders; the exposure symptoms and treatment fields will be hidden for everyone instead (job 4). Its fixture also looks for a catalogue intent recorded as source_type "substance"; this build records "catalogue".', 'Job 4 (after the merge): hide exposure symptoms and treatment fields for everyone on the audit pages and CSV; Codex\'s row-hiding guard stays out.'));
        $catalogue = $this->catalogue();
        $intent = ChemicalCommandIntent::where('source_type', 'substance')->where('command', 'create')->orderByDesc('id')->firstOrFail();
        $row = AuditLog::create(['action' => 'chemical.catalogue.created', 'auditable_type' => ChemicalCommandIntent::class,
            'auditable_id' => $intent->id, 'user_id' => $this->manager->id]);
        $this->allow($this->manager, 'hr.wellbeing.view', false);
        $this->assertSame([$row->id], $this->visible([$row->id]));
        $this->assertNotNull($catalogue->id);
    }

    public function test_recovery_subject_site_denial_has_no_private_subject_body_hydration(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure audit row-hiding guard (ChemicalExposureAuditAccessService::materialize/scope) is not mounted, by Stephan\'s decision. The audit pages keep main\'s rows for audit.viewAny holders; the exposure symptoms and treatment fields will be hidden for everyone instead (job 4).', 'Job 4 (after the merge): hide exposure symptoms and treatment fields for everyone on the audit pages and CSV; Codex\'s row-hiding guard stays out.'));
        [$root, $file, $stage] = $this->uploaded();
        $row = $this->audit(ChemicalExposureEvidenceStaging::class, $stage->id);
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id]);
        [$ids, $queries] = $this->capturedQueries(fn () => $this->visible([$row->id]));
        $this->assertSame([], $ids);
        foreach ($queries as $entry) {
            $sql = strtolower($entry['query']);
            if (str_contains($sql, 'from `chemical_exposure_evidence_staging`')) {
                $this->assertStringNotContainsString('select *', $sql);
                $this->assertStringNotContainsString('`path`', $sql);
                $this->assertStringNotContainsString('`command_values`', $sql);
            }
        }
    }

    public function test_actual_destination_marker_audit_uses_current_canonical_private_root_and_marker_proof(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure audit row-hiding guard (ChemicalExposureAuditAccessService::materialize/scope) is not mounted, by Stephan\'s decision. The audit pages keep main\'s rows for audit.viewAny holders; the exposure symptoms and treatment fields will be hidden for everyone instead (job 4). This case also builds its H&S event through the unmounted delivery pipeline, which refuses the observer-created event.', 'Job 4 (after the merge): hide exposure symptoms and treatment fields for everyone on the audit pages and CSV; Codex\'s row-hiding guard stays out.'));
        $root = $this->exposure();
        $delivery = ChemicalExposureSourceDelivery::where('exposure_id', $root->id)->where('kind', 'health_safety')->sole();
        app(\App\Services\HealthSafety\ChemicalExposureHsEventDeliveryService::class)->deliver($this->manager, $delivery->id);
        $marker = ChemicalExposureHsProjection::where('exposure_id', $root->id)->sole();
        $row = $this->audit(ChemicalExposureHsProjection::class, $marker->id);
        $viewer = $this->staff(['audit.viewAny', 'hazards.view', 'hr.wellbeing.view']);
        $this->assertSame([$row->id], $this->visible([$row->id], $viewer));
        $this->allow($viewer, 'hr.wellbeing.view', false);
        $this->assertSame([], $this->visible([$row->id], $viewer));
        $this->allow($viewer, 'hr.wellbeing.view');
        $projection = $marker->projection; $projection['staff_id'] = $this->manager->id;
        DB::table('chemical_exposure_hs_projections')->where('id', $marker->id)->update([
            'projection' => json_encode($projection, JSON_THROW_ON_ERROR)]);
        $this->assertSame([], $this->visible([$row->id], $viewer));
    }

    public function test_marker_foreign_source_and_unknown_class_never_produce_a_private_audit_body(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure audit row-hiding guard (ChemicalExposureAuditAccessService::materialize/scope) is not mounted, by Stephan\'s decision. The audit pages keep main\'s rows for audit.viewAny holders; the exposure symptoms and treatment fields will be hidden for everyone instead (job 4). This case also builds its H&S event through the unmounted delivery pipeline, which refuses the observer-created event.', 'Job 4 (after the merge): hide exposure symptoms and treatment fields for everyone on the audit pages and CSV; Codex\'s row-hiding guard stays out.'));
        $root = $this->exposure();
        $delivery = ChemicalExposureSourceDelivery::where('exposure_id', $root->id)->where('kind', 'health_safety')->sole();
        app(\App\Services\HealthSafety\ChemicalExposureHsEventDeliveryService::class)->deliver($this->manager, $delivery->id);
        $marker = ChemicalExposureHsProjection::where('exposure_id', $root->id)->sole();
        $row = $this->audit(ChemicalExposureHsProjection::class, $marker->id);
        $unknown = $this->audit('Other\\ChemicalExposureHsProjection', $marker->id);
        $this->assertSame([], $this->visible([$unknown->id]));
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id]);
        [$ids, $queries] = $this->capturedQueries(fn () => $this->visible([$row->id]));
        $this->assertSame([], $ids);
        foreach ($queries as $entry) {
            if (str_contains($entry['query'], 'from `chemical_exposure_hs_projections`')) {
                $this->assertStringNotContainsString('select *', strtolower($entry['query']));
                $this->assertStringNotContainsString('`projection`', strtolower($entry['query']));
            }
        }
    }
    public function test_true_noop_receipt_and_intent_require_the_retained_acceptance_tuple_anchor(): void
    {
        $root = $this->exposure();
        $accepted = $this->exposureService()->correct($root, $this->manager,
            $this->privateCommand($root, ['reason' => 'Observed facts already match']));
        $receipt = ChemicalExposureCommandReceipt::where('idempotency_key', $accepted['idempotency_key'])->sole();
        $this->assertNull($receipt->source_version_id);
        $rows = [$this->audit(ChemicalExposureCommandReceipt::class, $receipt->id),
            $this->audit(ChemicalCommandIntent::class, $receipt->intent_id)];
        $ids = array_map(fn ($row) => $row->id, $rows);
        $this->assertSame($ids, $this->visible($ids));
        $tuple = $receipt->source_tuple;
        $tuple['after']['site_id'] = $this->foreignSite->id;
        DB::table('chemical_exposure_command_receipts')->where('id', $receipt->id)->update([
            'source_tuple' => json_encode($tuple, JSON_THROW_ON_ERROR),
            'source_tuple_digest' => ChemicalCommandValues::digest($tuple)]);
        $this->assertSame([], $this->visible($ids));
        $this->assertSame($this->site->id, $root->fresh()->site_id);
    }

    public function test_old_noop_audit_admits_its_original_site_before_body_after_later_genuine_source_changes(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::NEVER_BUILT, 'Codex\'s exposure audit row-hiding guard (ChemicalExposureAuditAccessService::materialize/scope) is not mounted, by Stephan\'s decision. The audit pages keep main\'s rows for audit.viewAny holders; the exposure symptoms and treatment fields will be hidden for everyone instead (job 4).', 'Job 4 (after the merge): hide exposure symptoms and treatment fields for everyone on the audit pages and CSV; Codex\'s row-hiding guard stays out.'));
        $root = $this->exposure();
        $accepted = $this->exposureService()->correct($root, $this->manager,
            $this->privateCommand($root, ['reason' => 'Observed facts already match']));
        $receipt = ChemicalExposureCommandReceipt::where('idempotency_key', $accepted['idempotency_key'])->sole();
        $this->assertNull($receipt->source_version_id);
        $rows = [$this->audit(ChemicalExposureCommandReceipt::class, $receipt->id),
            $this->audit(ChemicalCommandIntent::class, $receipt->intent_id)];
        $ids = array_map(fn ($row) => $row->id, $rows);
        $this->manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $this->worker->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, [
            'reason' => 'Correct the occurrence to the actually approved Site', 'site_id' => $this->foreignSite->id,
            'related_incident_id' => null]));
        // Advance once more so the latest source proof alone no longer carries the first Site correction.
        $this->exposureService()->correct($root, $this->manager, $this->privateCommand($root, [
            'reason' => 'Record a later genuine fact at the corrected Site', 'symptoms' => 'Later observed fact']));
        $this->assertSame($ids, $this->visible($ids));
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => []]);
        $this->assertSame('recorded', $this->proof($root)['attribution_state']);
        [$visible, $queries] = $this->capturedQueries(fn () => $this->visible($ids));
        $this->assertSame([], $visible);
        foreach ($queries as $entry) {
            $sql = strtolower($entry['query']);
            if (preg_match('/^select \* from `chemical_exposure_command_receipts` where `exposure_id` = \? and `id` = \?/', $sql)
                && ($entry['bindings'][1] ?? null) === $receipt->id) {
                $this->fail('Original no-op receipt body was hydrated before its historical Site admission.');
            }
            if (preg_match('/^select \* from `chemical_command_intents` where `source_type` = \? and `id` = \?/', $sql)
                && ($entry['bindings'][1] ?? null) === $receipt->intent_id) {
                $this->fail('Original no-op intent body was hydrated before its historical Site admission.');
            }
        }
        $this->manager->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->site->id]]);
        $this->assertSame($ids, $this->visible($ids));
    }
    private function uploaded(): array
    {
        $root = $this->exposure();
        $r = $this->evidenceService()->upload($root, $this->manager, UploadedFile::fake()->createWithContent('private.pdf', "%PDF-1.4\nObserved private physical bytes\n%%EOF"),
            $this->privateCommand($root, ['description' => 'Private observed attachment']));
        $file = ChemicalExposureEvidence::findOrFail($r['source_id']);
        $stage = ChemicalExposureEvidenceStaging::where('exposure_id', $root->id)->sole();
        $receipt = ChemicalExposureCommandReceipt::where('idempotency_key', $r['idempotency_key'])->sole();
        $this->assertTrue($r['accepted']);
        return [$root, $file, $stage, $receipt];
    }

    private function audit(string $type, int $id): AuditLog
    {
        return AuditLog::create(['action' => 'chemical.exposure.audit_subject', 'auditable_type' => $type,
            'auditable_id' => $id, 'user_id' => $this->manager->id]);
    }

    private function visible(array $ids, ?User $viewer = null): array
    {
        $guard = app(ChemicalExposureAuditAccessService::class);
        return $guard->materialize($viewer ?? $this->manager, fn ($current, $context) => $guard
            ->scope(AuditLog::whereKey($ids), $current, $context)->orderBy('id')->pluck('id')->all());
    }
}
