<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\Site;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjuryCommandReceipt;
use App\Models\WorkplaceInjurySourceVersion;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\InjuryAccessService;
use App\Services\HealthSafety\RestraintCommandValues;
use App\Support\JsonEvidence;
use Illuminate\Support\Facades\DB;
use LogicException;

final class WorkplaceInjuryFactualSourceProofTest extends WorkplaceInjurySourceTestCase
{
    /** MySQL json columns return object keys in their own order; values and types stay strict. */
    private function assertSameFacts(array $expected, mixed $actual): void
    {
        $this->assertIsArray($actual);
        $this->assertSame(JsonEvidence::canonicalise($expected), JsonEvidence::canonicalise($actual));
    }

    private function proof(WorkplaceInjury $injury, $viewer = null): array
    {
        return $this->access()->withCurrentViewer($viewer ?? $this->factual, fn ($current, $reads, $sites) =>
            $this->access()->factualSourceProof($injury, $current, $reads, $sites));
    }

    private function currentVersion(WorkplaceInjury $injury): WorkplaceInjurySourceVersion
    {
        return WorkplaceInjurySourceVersion::where('workplace_injury_id', $injury->id)->orderByDesc('aggregate_revision')->firstOrFail();
    }

    public function test_new_capture_has_exact_bounded_factual_digest_and_no_private_result_fields(): void
    {
        $injury = $this->injury();
        $version = $this->currentVersion($injury);
        $this->assertSameFacts(InjuryAccessService::factualSnapshot($injury->fresh()), $version->factual_content);
        $this->assertSame(RestraintCommandValues::digest($version->factual_content), $version->factual_digest);
        $this->assertSame(['attribution_state' => 'recorded', 'source_version_id' => $version->id,
            'source_revision' => 1], $this->proof($injury));
        foreach (['immediate_treatment', 'medical_treatment_type', 'acc_claim_number', 'notes', 'source_type', 'source_id',
            'command', 'recorded_by', 'recorder_snapshot', 'assessor_snapshot', 'worker_snapshot'] as $excluded) {
            $this->assertArrayNotHasKey($excluded, $version->factual_content);
            $this->assertArrayNotHasKey($excluded, $version->factual_content['facts']);
        }
        $this->assertStringNotContainsString('PRIVATE-CLAIM', json_encode($version->factual_content));
        $this->assertStringNotContainsString('Private', json_encode($version->factual_content));
    }

    public function test_private_child_aggregate_certifies_only_current_injury_facts_not_child_identity_or_narrative(): void
    {
        $injury = $this->injury();
        $receipt = $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury));
        $version = $this->currentVersion($injury);
        $this->assertSame('capacity_assessment', $version->source_type);
        $this->assertSameFacts(InjuryAccessService::factualSnapshot($injury->fresh()), $version->factual_content);
        $this->assertSame(['attribution_state' => 'recorded', 'source_version_id' => $receipt['source_version_id'],
            'source_revision' => 2], $this->proof($injury));
        foreach (['capacity_assessment', 'assessment_summary', 'restrictions', 'Attributed external issuer', 'Private'] as $excluded) {
            $this->assertStringNotContainsString($excluded, json_encode($version->factual_content));
            $this->assertStringNotContainsString($excluded, json_encode($this->proof($injury)));
        }
    }

    public function test_factual_proof_sql_never_selects_private_version_payload_or_private_injury_columns_for_any_reader(): void
    {
        $injury = $this->injury();
        $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury));
        foreach ([$this->factual, $this->medicalReader] as $viewer) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $proof = $this->proof($injury, $viewer);
            $queries = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();
            $this->assertSame('recorded', $proof['attribution_state']);
            $versionQueries = array_filter($queries, fn ($sql) => str_contains(strtolower($sql), 'workplace_injury_source_versions'));
            $this->assertNotEmpty($versionQueries);
            foreach ($versionQueries as $sql) {
                $this->assertDoesNotMatchRegularExpression('/(?<![a-z_])(content|content_digest|source_type|source_id|command|recorded_by|recorder_snapshot)(?![a-z_])/i', $sql);
                $this->assertStringNotContainsString('*', $sql);
            }
            foreach (['immediate_treatment', 'medical_treatment_type', 'acc_claim_number', 'work_capacity_assessments', 'workplace_injury_attachments'] as $private) {
                $this->assertStringNotContainsString($private, strtolower(implode("\n", $queries)));
            }
        }
    }

    public function test_shared_context_uses_same_factual_proof_without_exporting_private_authority(): void
    {
        $injury = $this->injury();
        $proof = HealthSafetyReadContext::within($this->factual, InjuryAccessService::PERMISSIONS, fn ($context) =>
            $this->access()->withReadContext($context, fn ($current, $reads, $sites) =>
                $this->access()->factualSourceProof($injury, $current, $reads, $sites)));
        $this->assertSame($this->proof($injury), $proof);
        $this->assertSame(['attribution_state', 'source_version_id', 'source_revision'], array_keys($proof));
    }

    public function test_current_grant_and_canonical_site_withdrawal_deny_loaded_source_proof(): void
    {
        $injury = $this->injury();
        $this->assertSame('recorded', $this->proof($injury)['attribution_state']);
        $this->revoke($this->factual, 'hazards.view');
        $this->denial(fn () => $this->proof($injury));
        DB::table('workplace_injuries')->where('id', $injury->id)->update(['site_id' => Site::factory()->create()->id]);
        $this->denial(fn () => $this->proof($injury, $this->medicalReader), 404);
    }

    public function test_capture_only_never_acquires_arbitrary_enduring_record_proof(): void
    {
        $injury = $this->injury();
        $this->denial(fn () => $this->proof($injury, $this->capturer));
        $this->denial(fn () => $this->access()->withCurrentViewer($this->capturer, fn ($current, $reads, $sites) =>
            $this->access()->factualSourceProof($injury, $current, $reads, $sites, 'create')));
    }

    public function test_true_legacy_baseline_remains_unknown_and_noop_does_not_certify_it(): void
    {
        $injury = WorkplaceInjury::factory()->create(['user_id' => $this->worker->id, 'site_id' => $this->site->id]);
        $this->assertSame(['attribution_state' => 'legacy_unknown', 'source_version_id' => null, 'source_revision' => null], $this->proof($injury));
        $receipt = $this->source()->update($injury, $this->factual, $this->command($injury, ['description' => $injury->description]));
        $this->assertFalse($receipt['changed']);
        $this->assertSame(0, $injury->fresh()->source_revision);
        $baseline = $this->currentVersion($injury);
        $this->assertSame(0, $baseline->source_schema_version);
        $this->assertNull($baseline->factual_content);
        $this->assertNull($baseline->factual_digest);
        $this->assertSame('legacy_unknown', $this->proof($injury)['attribution_state']);
    }

    public function test_governed_marker_downgrade_or_malformed_positive_legacy_revision_is_repair_not_unknown(): void
    {
        $injury = $this->injury();
        DB::table('workplace_injuries')->where('id', $injury->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->assertSame(['attribution_state' => 'repair_required', 'source_version_id' => null, 'source_revision' => null], $this->proof($injury));
        DB::table('workplace_injury_source_versions')->where('workplace_injury_id', $injury->id)->update(['source_schema_version' => 0]);
        $this->assertSame('repair_required', $this->proof($injury)['attribution_state']);
        $legacy = WorkplaceInjury::factory()->create(['user_id' => $this->worker->id, 'site_id' => $this->site->id,
            'source_schema_version' => 0, 'source_revision' => 7]);
        $this->assertSame('repair_required', $this->proof($legacy)['attribution_state']);
    }

    public function test_missing_snapshot_or_bad_digest_is_not_repaired_or_certified_by_noop_write(): void
    {
        $injury = $this->injury();
        $version = $this->currentVersion($injury);
        DB::table('workplace_injury_source_versions')->where('id', $version->id)->update(['factual_content' => null]);
        $this->assertSame('repair_required', $this->proof($injury)['attribution_state']);
        try {
            $this->source()->update($injury, $this->factual, $this->command($injury, ['description' => $injury->description]));
            $this->fail('An unchanged submission cannot manufacture missing factual proof.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('factual snapshot', $error->getMessage());
        }
        $this->assertSame(1, WorkplaceInjurySourceVersion::count());
        $this->assertSame(1, WorkplaceInjuryCommandReceipt::count());
        DB::table('workplace_injury_source_versions')->where('id', $version->id)->update([
            'factual_content' => json_encode($version->factual_content), 'factual_digest' => str_repeat('0', 64)]);
        $this->assertSame('repair_required', $this->proof($injury)['attribution_state']);
    }

    public function test_recomputed_digest_cannot_hide_changed_facts_or_extra_arbitrary_snapshot_fields(): void
    {
        $injury = $this->injury();
        $version = $this->currentVersion($injury);
        $changed = $version->factual_content;
        $changed['facts']['description'] = 'Falsely rewritten immutable source';
        DB::table('workplace_injury_source_versions')->where('id', $version->id)->update([
            'factual_content' => json_encode($changed), 'factual_digest' => RestraintCommandValues::digest($changed)]);
        $this->assertSame('repair_required', $this->proof($injury)['attribution_state']);
        $changed = $version->factual_content;
        $changed['coordinator'] = ['id' => $this->manager->id, 'name' => $this->manager->name, 'private_snapshot' => 'Forbidden provenance'];
        DB::table('workplace_injury_source_versions')->where('id', $version->id)->update([
            'factual_content' => json_encode($changed), 'factual_digest' => RestraintCommandValues::digest($changed)]);
        $this->assertSame('repair_required', $this->proof($injury)['attribution_state']);
    }

    public function test_external_current_factual_mutation_and_missing_latest_aggregate_proof_are_repair(): void
    {
        $injury = $this->injury();
        DB::table('workplace_injuries')->where('id', $injury->id)->update(['description' => 'Ungoverned factual change']);
        $this->assertSame('repair_required', $this->proof($injury)['attribution_state']);
        DB::table('workplace_injuries')->where('id', $injury->id)->update(['description' => $injury->description]);
        $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury));
        DB::table('workplace_injury_source_versions')->where('workplace_injury_id', $injury->id)->where('aggregate_revision', 2)->delete();
        $this->assertSame('repair_required', $this->proof($injury)['attribution_state']);
    }

    public function test_accepted_worker_site_correction_creates_new_fact_proof_without_relabelling_original_version(): void
    {
        $injury = $this->injury();
        $original = $this->currentVersion($injury)->factual_content;
        $nextSite = Site::factory()->create();
        $nextWorker = $this->staff([], $nextSite);
        \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $this->manager->id)->update(['secondary_site_ids' => json_encode([$nextSite->id])]);
        $receipt = $this->source()->update($injury, $this->manager, $this->command($injury,
            ['user_id' => $nextWorker->id, 'site_id' => $nextSite->id]));
        $proof = $this->proof($injury, $this->manager);
        $this->assertSame('recorded', $proof['attribution_state']);
        $this->assertSame($receipt['source_version_id'], $proof['source_version_id']);
        $this->assertSame($nextWorker->id, $this->currentVersion($injury)->factual_content['worker_id']);
        $this->assertSame($nextSite->id, $this->currentVersion($injury)->factual_content['site_id']);
        $this->assertSame($original, WorkplaceInjurySourceVersion::where('aggregate_revision', 1)->sole()->factual_content);
    }

    public function test_factual_snapshot_veto_rolls_back_new_source_revision_history_audit_and_receipt(): void
    {
        $injury = $this->injury();
        $dispatcher = WorkplaceInjurySourceVersion::getEventDispatcher();
        WorkplaceInjurySourceVersion::setEventDispatcher(clone $dispatcher);
        WorkplaceInjurySourceVersion::creating(function (WorkplaceInjurySourceVersion $version) {
            if ($version->command === 'update') {
                $version->factual_content = null;
            }
        });
        try {
            $this->source()->update($injury, $this->factual, $this->command($injury, ['description' => 'Will be rolled back']));
            $this->fail('Missing persisted factual snapshot must refuse source acceptance.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('immutable injury source facts', $error->getMessage());
        } finally {
            WorkplaceInjurySourceVersion::setEventDispatcher($dispatcher);
        }
        $this->assertSame(1, $injury->fresh()->source_revision);
        $this->assertSame('Recorded lifting injury.', $injury->fresh()->description);
        $this->assertSame(1, WorkplaceInjurySourceVersion::count());
        $this->assertSame(1, WorkplaceInjuryCommandReceipt::count());
        $this->assertSame(0, AuditLog::where('action', 'health_safety.injury.update')->count());
        $this->assertSame('recorded', $this->proof($injury)['attribution_state']);
    }

    public function test_coordinator_snapshot_is_explicit_and_arbitrary_identity_metadata_is_not_retained(): void
    {
        $injury = $this->injury();
        $this->source()->assign($injury, $this->manager, $this->command($injury,
            ['coordinator_user_id' => $this->factual->id, 'next_review_date' => '2026-10-20', 'reason' => 'Actual coordination']));
        $factual = $this->currentVersion($injury)->factual_content;
        $this->assertSame(['id' => $this->factual->id, 'name' => $this->factual->name], $factual['coordinator']);
        $this->assertArrayNotHasKey('coordinator_snapshot', $factual['facts']);
        $this->assertArrayNotHasKey('coordinator_user_id', $factual['facts']);
        $poisoned = $injury->fresh();
        $poisoned->coordinator_snapshot = ['id' => $this->factual->id, 'name' => $this->factual->name,
            'private_note' => 'Forbidden arbitrary identity snapshot'];
        $this->assertSameFacts($factual, InjuryAccessService::factualSnapshot($poisoned));
    }
}
