<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ReturnToWorkPlan;
use App\Models\WorkCapacityAssessment;
use App\Models\WorkplaceInjurySourceVersion;
use Illuminate\Support\Facades\DB;
use LogicException;

final class WorkplaceInjuryOperationalSourceTest extends WorkplaceInjurySourceTestCase
{
    private function expected($injury, $receipt, string $type): array
    {
        return ['expected_injury_revision' => $injury->fresh()->source_revision, 'source_type' => $type,
            'source_id' => $receipt['source_id'], 'expected_source_revision' => $receipt['version'],
            'expected_source_version_id' => $receipt['source_version_id']];
    }

    public function test_metadata_is_source_bound_without_private_prose_or_operational_approval(): void
    {
        $injury = $this->injury();
        $receipt = $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury));
        $dto = $this->access()->canonicalSourceForViewer($this->medicalReader, $injury->id, $this->expected($injury, $receipt, 'capacity_assessment'));
        $this->assertSame($this->worker->id, $dto['worker_id']);
        $this->assertSame($this->site->id, $dto['source_site_id']);
        $this->assertSame(['type' => 'capacity_assessment', 'id' => $receipt['source_id'], 'revision' => 2,
            'version_id' => $receipt['source_version_id']], $dto['source']);
        $this->assertSame('recorded', $dto['attribution_state']);
        $this->assertSame($this->manager->id, $dto['recorded_by']);
        foreach (['Private', 'restrictions', 'assessment_summary', 'assessor_name', 'document_path', 'approved'] as $private) {
            $this->assertStringNotContainsString($private, json_encode($dto));
        }
    }

    public function test_unrelated_coordination_advances_aggregate_without_changing_child_version(): void
    {
        $injury = $this->injury();
        $receipt = $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury));
        $before = $this->expected($injury, $receipt, 'capacity_assessment');
        $this->source()->assign($injury, $this->manager, $this->command($injury,
            ['coordinator_user_id' => $this->factual->id, 'next_review_date' => '2026-10-20', 'reason' => 'Coordination only']));
        $this->validation(fn () => $this->access()->canonicalSourceForViewer($this->medicalReader, $injury->id, $before), 'expected_injury_revision');
        $dto = $this->access()->canonicalSourceForViewer($this->medicalReader, $injury->id,
            [...$before, 'expected_injury_revision' => 3]);
        $this->assertSame(3, $dto['injury_revision']);
        $this->assertSame(2, $dto['source']['revision']);
        $this->assertSame($receipt['source_version_id'], $dto['source']['version_id']);
        $this->assertSame($this->factual->id, $dto['coordinator']['id']);
    }

    public function test_factual_source_reader_is_denied_before_private_source_table_materialisation(): void
    {
        $injury = $this->injury();
        $receipt = $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury));
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->denial(fn () => $this->access()->canonicalSourceForViewer($this->factual, $injury->id,
            $this->expected($injury, $receipt, 'capacity_assessment')));
        $sql = strtolower(implode("\n", array_column(DB::getQueryLog(), 'query')));
        DB::disableQueryLog();
        $this->assertStringNotContainsString('work_capacity_assessments', $sql);
        $this->assertStringNotContainsString('workplace_injury_source_versions', $sql);
    }

    public function test_legacy_source_revision_zero_remains_explicitly_unknown(): void
    {
        $injury = $this->injury();
        $legacy = WorkCapacityAssessment::factory()->create(['workplace_injury_id' => $injury->id, 'user_id' => $this->manager->id]);
        $dto = $this->access()->canonicalSourceForViewer($this->medicalReader, $injury->id,
            ['expected_injury_revision' => 1, 'source_type' => 'capacity_assessment', 'source_id' => $legacy->id,
                'expected_source_revision' => 0, 'expected_source_version_id' => null]);
        $this->assertSame('legacy_unknown', $dto['attribution_state']);
        $this->assertNull($dto['recorded_by']);
        $this->assertNull($dto['recorded_at']);
        $this->assertNull($dto['source']['version_id']);
        $this->assertSame($this->manager->id, (int) $legacy->fresh()->user_id);
    }

    public function test_foreign_same_site_worker_child_and_stale_version_identity_are_denied(): void
    {
        $injury = $this->injury();
        $receipt = $this->recovery()->createPlan($injury, $this->manager, $this->planPayload($injury));
        $expected = $this->expected($injury, $receipt, 'rtw_plan');
        $this->validation(fn () => $this->access()->canonicalSourceForViewer($this->medicalReader, $injury->id,
            [...$expected, 'expected_source_version_id' => $receipt['source_version_id'] + 100]), 'expected_source_revision');
        DB::table('return_to_work_plans')->where('id', $receipt['source_id'])->update(['worker_id' => $this->factual->id]);
        $this->denial(fn () => $this->access()->canonicalSourceForViewer($this->medicalReader, $injury->id, $expected), 404);
    }

    public function test_missing_or_mutated_typed_version_and_current_medical_revoke_fail_closed(): void
    {
        $injury = $this->injury();
        $receipt = $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury));
        $expected = $this->expected($injury, $receipt, 'capacity_assessment');
        $version = WorkplaceInjurySourceVersion::findOrFail($receipt['source_version_id']);
        DB::table('workplace_injury_source_versions')->where('id', $version->id)->update(['content_digest' => str_repeat('0', 64)]);
        try {
            $this->access()->canonicalSourceForViewer($this->medicalReader, $injury->id, $expected);
            $this->fail('Corrupt source digest cannot be operational authority.');
        } catch (LogicException $error) { $this->assertStringContainsString('immutable', $error->getMessage()); }
        DB::table('workplace_injury_source_versions')->where('id', $version->id)->delete();
        try {
            $this->access()->canonicalSourceForViewer($this->medicalReader, $injury->id, $expected);
            $this->fail('Typed missing source cannot become legacy.');
        } catch (LogicException $error) { $this->assertStringContainsString('immutable', $error->getMessage()); }
        $this->revoke($this->medicalReader, 'hr.wellbeing.view');
        $this->denial(fn () => $this->access()->canonicalSourceForViewer($this->medicalReader, $injury->id, $expected));
    }

    public function test_governed_source_marker_downgrade_does_not_claim_unknown_legacy_authority(): void
    {
        $injury = $this->injury();
        $receipt = $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury));
        DB::table('work_capacity_assessments')->where('id', $receipt['source_id'])->update(['source_schema_version' => 0, 'source_revision' => 0]);
        try {
            $this->access()->canonicalSourceForViewer($this->medicalReader, $injury->id,
                ['expected_injury_revision' => 2, 'source_type' => 'capacity_assessment', 'source_id' => $receipt['source_id'],
                    'expected_source_revision' => 0, 'expected_source_version_id' => null]);
            $this->fail('Retained governed history must not be downgraded to legacy authority.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('downgraded', $error->getMessage());
        }
        $legacy = WorkCapacityAssessment::factory()->create(['workplace_injury_id' => $injury->id,
            'user_id' => $this->manager->id, 'source_schema_version' => 0, 'source_revision' => 7]);
        try {
            $this->access()->canonicalSourceForViewer($this->medicalReader, $injury->id,
                ['expected_injury_revision' => 2, 'source_type' => 'capacity_assessment', 'source_id' => $legacy->id,
                    'expected_source_revision' => 0, 'expected_source_version_id' => null]);
            $this->fail('A positive ungoverned revision is not an untouched legacy source.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('downgraded', $error->getMessage());
        }
    }

    public function test_intact_older_child_does_not_prove_a_missing_current_aggregate_revision(): void
    {
        $injury = $this->injury();
        $receipt = $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury));
        $this->source()->assign($injury, $this->manager, $this->command($injury,
            ['coordinator_user_id' => $this->factual->id, 'next_review_date' => '2026-10-20', 'reason' => 'Actual coordination']));
        DB::table('workplace_injury_source_versions')->where('workplace_injury_id', $injury->id)->where('aggregate_revision', 3)->delete();
        try {
            $this->access()->canonicalSourceForViewer($this->medicalReader, $injury->id,
                [...$this->expected($injury, $receipt, 'capacity_assessment'), 'expected_injury_revision' => 3]);
            $this->fail('An intact prior child does not prove the missing current aggregate source.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('aggregate revision', $error->getMessage());
        }
        $this->assertSame(3, $injury->fresh()->source_revision);
        $this->assertSame(2, WorkCapacityAssessment::findOrFail($receipt['source_id'])->source_revision);
    }

    public function test_injury_level_source_has_own_version_distinct_from_later_child_aggregate(): void
    {
        $injury = $this->injury();
        $injuryVersion = WorkplaceInjurySourceVersion::where('source_type', 'injury')->where('source_id', $injury->id)->sole();
        $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury));
        $dto = $this->access()->canonicalSourceForViewer($this->medicalReader, $injury->id,
            ['expected_injury_revision' => 2, 'source_type' => 'injury', 'source_id' => $injury->id,
                'expected_source_revision' => 1, 'expected_source_version_id' => $injuryVersion->id]);
        $this->assertSame(2, $dto['injury_revision']);
        $this->assertSame(1, $dto['source']['revision']);
        $this->assertSame($injuryVersion->id, $dto['source']['version_id']);
    }
    public function test_fake_legacy_reparent_node_cannot_hide_exact_subject_prior_typed_history(): void
    {
        $original = $this->injury();
        $receipt = $this->recovery()->recordCapacity($original, $this->manager, $this->capacityPayload($original));
        $destination = $this->injury();
        DB::table('work_capacity_assessments')->where('id', $receipt['source_id'])->update([
            'workplace_injury_id' => $destination->id, 'source_schema_version' => 0, 'source_revision' => 0]);
        $content = ['scope' => 'medical', 'before' => null, 'after' => [], 'command_facts' => [], 'attribution_state' => 'legacy_unknown'];
        WorkplaceInjurySourceVersion::create(['workplace_injury_id' => $destination->id, 'aggregate_revision' => 0,
            'source_schema_version' => 0, 'source_type' => 'capacity_assessment', 'source_id' => $receipt['source_id'],
            'command' => 'legacy_baseline', 'worker_id' => $this->worker->id, 'site_id' => $this->site->id,
            'recorded_by' => $this->manager->id, 'recorder_snapshot' => ['id' => $this->manager->id, 'name' => $this->manager->name],
            'content' => $content, 'content_digest' => \App\Services\HealthSafety\RestraintCommandValues::digest($content),
            'factual_content' => null, 'factual_digest' => null, 'recorded_at' => now()->utc()]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->access()->canonicalSourceForViewer($this->medicalReader, $destination->id,
                ['expected_injury_revision' => 1, 'source_type' => 'capacity_assessment', 'source_id' => $receipt['source_id'],
                    'expected_source_revision' => 0, 'expected_source_version_id' => null]);
            $this->fail('A forged legacy node must not erase earlier typed ownership of the same source identity.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('downgraded', $error->getMessage());
        } finally {
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
        }
        $identityProof = array_values(array_filter($queries, fn ($query) =>
            str_contains(strtolower($query['query']), 'workplace_injury_source_versions')
            && str_contains(strtolower($query['query']), 'exists')
            && str_contains(strtolower($query['query']), 'source_type')
            && ! str_contains(strtolower($query['query']), 'workplace_injury_id')));
        $this->assertNotEmpty($identityProof);
        foreach ($identityProof as $query) {
            $this->assertStringNotContainsString('select *', strtolower($query['query']));
            $this->assertContains('capacity_assessment', $query['bindings']);
            $this->assertContains($receipt['source_id'], $query['bindings']);
        }
        $this->assertDatabaseHas('workplace_injury_source_versions', ['id' => $receipt['source_version_id'],
            'workplace_injury_id' => $original->id, 'source_schema_version' => 1]);
    }

    public function test_later_fake_schema_zero_version_cannot_mask_governed_same_parent_child(): void
    {
        $injury = $this->injury();
        $receipt = $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury));
        DB::table('work_capacity_assessments')->where('id', $receipt['source_id'])->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $content = ['scope' => 'medical', 'before' => null, 'after' => [], 'command_facts' => [], 'attribution_state' => 'legacy_unknown'];
        WorkplaceInjurySourceVersion::create(['workplace_injury_id' => $injury->id, 'aggregate_revision' => 3,
            'source_schema_version' => 0, 'source_type' => 'capacity_assessment', 'source_id' => $receipt['source_id'],
            'command' => 'legacy_baseline', 'worker_id' => $this->worker->id, 'site_id' => $this->site->id,
            'recorded_by' => $this->manager->id, 'recorder_snapshot' => ['id' => $this->manager->id, 'name' => $this->manager->name],
            'content' => $content, 'content_digest' => \App\Services\HealthSafety\RestraintCommandValues::digest($content),
            'factual_content' => null, 'factual_digest' => null, 'recorded_at' => now()->utc()]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('downgraded');
        $this->access()->canonicalSourceForViewer($this->medicalReader, $injury->id,
            ['expected_injury_revision' => 2, 'source_type' => 'capacity_assessment', 'source_id' => $receipt['source_id'],
                'expected_source_revision' => 0, 'expected_source_version_id' => null]);
    }
}
