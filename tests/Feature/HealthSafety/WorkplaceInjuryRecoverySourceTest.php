<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ModifiedDuty;
use App\Models\ReturnToWorkPlan;
use App\Models\WorkCapacityAssessment;
use App\Models\WorkplaceInjuryCommandReceipt;
use App\Models\WorkplaceInjurySourceVersion;
use Illuminate\Support\Facades\DB;
use LogicException;

final class WorkplaceInjuryRecoverySourceTest extends WorkplaceInjurySourceTestCase
{
    public function test_new_internal_assessment_records_distinct_worker_assessor_and_recorder(): void
    {
        $injury = $this->injury();
        $assessor = $this->staff();
        $accepted = $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury,
            ['assessor_user_id' => $assessor->id, 'assessor_name' => null, 'assessor_type' => 'employer']));
        $assessment = WorkCapacityAssessment::findOrFail($accepted['source_id']);
        $this->assertSame($this->worker->id, (int) $assessment->worker_id);
        $this->assertSame($assessor->id, (int) $assessment->assessor_user_id);
        $this->assertSame($this->manager->id, (int) $assessment->recorded_by);
        $this->assertSame($assessor->name, $assessment->assessor_name);
        $this->assertSame($assessor->id, (int) $assessment->user_id);
        $this->assertSame('recorded', $assessment->attribution_state);
        $this->assertSame([$assessment->id], WorkCapacityAssessment::forWorker($this->worker->id)->pluck('id')->all());
        $this->assertSame([], WorkCapacityAssessment::forWorker($assessor->id)->pluck('id')->all());
    }

    public function test_external_assessment_is_attributed_without_invented_internal_identity_or_competence(): void
    {
        $injury = $this->injury();
        $accepted = $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury));
        $assessment = WorkCapacityAssessment::findOrFail($accepted['source_id']);
        $this->assertNull($assessment->assessor_user_id);
        $this->assertSame('reported_external', $assessment->assessor_snapshot['attribution']);
        $this->assertSame('Attributed external issuer', $assessment->assessor_snapshot['name']);
        $this->assertSame($this->worker->id, (int) $assessment->worker_id);
        $this->assertArrayNotHasKey('qualification_verified', $assessment->assessor_snapshot);
        $this->assertStringNotContainsString('approved', json_encode($accepted));
        $this->validation(fn () => $this->recovery()->recordCapacity($injury, $this->manager,
            $this->capacityPayload($injury, ['assessor_name' => null])), 'assessor_name');
    }

    public function test_legacy_assessor_carrier_is_retained_and_worker_scope_uses_canonical_parent(): void
    {
        $injury = $this->injury();
        $formerAssessor = $this->staff();
        // The legacy provenance markers are database defaults, so read the stored row.
        $legacy = WorkCapacityAssessment::factory()->create(['workplace_injury_id' => $injury->id, 'user_id' => $formerAssessor->id])->refresh();
        $this->assertSame(0, $legacy->source_schema_version);
        $this->assertSame('legacy_unknown', $legacy->attribution_state);
        $this->assertNull($legacy->worker_id);
        $this->assertNull($legacy->assessor_user_id);
        $this->assertSame([$legacy->id], WorkCapacityAssessment::forWorker($this->worker->id)->pluck('id')->all());
        $this->assertSame($formerAssessor->id, (int) $legacy->fresh()->user_id);
    }

    public function test_same_site_different_worker_is_denied_for_plan_and_duty_not_just_foreign_site(): void
    {
        $injury = $this->injury();
        $other = $this->staff();
        $this->denial(fn () => $this->recovery()->createPlan($injury, $this->manager,
            $this->planPayload($injury, ['worker_id' => $other->id])), 404);
        $accepted = $this->recovery()->createPlan($injury, $this->manager, $this->planPayload($injury));
        $plan = ReturnToWorkPlan::findOrFail($accepted['source_id']);
        $this->denial(fn () => $this->recovery()->addModifiedDuty($injury, $plan, $this->manager,
            $this->command($injury, ['start_date' => '2026-10-12', 'modified_duties_description' => 'Proposed duty',
                'hours_per_day' => 4, 'user_id' => $other->id])), 404);
        $this->assertSame(0, ModifiedDuty::count());
        $foreignPlan = ReturnToWorkPlan::factory()->create(['workplace_injury_id' => $injury->id, 'worker_id' => $other->id]);
        $this->denial(fn () => $this->recovery()->updatePlan($injury, $foreignPlan, $this->manager,
            $this->command($injury, ['review_notes' => 'Cannot adopt foreign worker source'])), 404);
    }

    public function test_typed_recovery_even_soft_deleted_prevents_parent_worker_rebinding(): void
    {
        $injury = $this->injury();
        $accepted = $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury));
        WorkCapacityAssessment::findOrFail($accepted['source_id'])->deleteQuietly();
        $other = $this->staff();
        $this->validation(fn () => $this->source()->update($injury, $this->manager,
            $this->command($injury, ['user_id' => $other->id])), 'user_id');
        $this->assertSame($this->worker->id, (int) $injury->fresh()->user_id);
    }


    public function test_typed_child_also_blocks_site_rebinding(): void
    {
        $injury = $this->injury();
        $this->recovery()->recordCapacity($injury, $this->manager, $this->capacityPayload($injury));
        $otherSite = \App\Models\Site::factory()->create();
        \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $this->manager->id)->update(['secondary_site_ids' => json_encode([$otherSite->id])]);
        \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $this->worker->id)->update(['secondary_site_ids' => json_encode([$otherSite->id])]);
        $this->validation(fn () => $this->source()->update($injury, $this->manager,
            $this->command($injury, ['site_id' => $otherSite->id])), 'site_id');
        $this->assertSame($this->site->id, (int) $injury->fresh()->site_id);
    }

    public function test_stage_dates_write_back_and_merged_update_cannot_move_plan_inside_existing_stage(): void
    {
        $injury = $this->injury();
        $accepted = $this->recovery()->createPlan($injury, $this->manager, $this->planPayload($injury));
        $plan = ReturnToWorkPlan::findOrFail($accepted['source_id']);
        $this->assertSame('2026-10-12', $plan->stages[0]['start_date']);
        $this->validation(fn () => $this->recovery()->updatePlan($injury, $plan, $this->manager,
            $this->command($injury, ['plan_start_date' => '2026-10-15'])), 'stages');
        $this->validation(fn () => $this->recovery()->updatePlan($injury, $plan, $this->manager,
            $this->command($injury, ['plan_end_date' => '2026-10-20'])), 'stages');
        $this->validation(fn () => $this->recovery()->createPlan($injury, $this->manager,
            $this->planPayload($injury, ['stages' => [['name' => 'Invalid', 'start_date' => '2026-02-30']]])), 'stages');
        $this->assertSame('2026-10-12', $plan->fresh()->plan_start_date->toDateString());
    }

    public function test_actual_clearance_agreement_and_review_facts_are_not_invented_or_future_dated(): void
    {
        $injury = $this->injury();
        $this->validation(fn () => $this->recovery()->createPlan($injury, $this->manager,
            $this->planPayload($injury, ['medical_clearance_date' => '2026-10-13'])), 'medical_clearance_date');
        $this->validation(fn () => $this->recovery()->createPlan($injury, $this->manager,
            $this->planPayload($injury, ['worker_agreed_at' => '2026-10-11', 'worker_agreed' => false])), 'worker_agreed_at');
        $this->validation(fn () => $this->recovery()->recordReview($injury, $this->manager, $this->command($injury,
            ['reviewed_at' => '2026-10-13T00:00:00Z', 'next_review_date' => '2026-10-20', 'notes' => 'Future fact'])), 'reviewed_at');
        $accepted = $this->recovery()->recordReview($injury, $this->manager, $this->command($injury,
            ['reviewed_at' => '2026-10-11T01:00:00Z', 'next_review_date' => '2026-10-20', 'notes' => 'Actual review']));
        $version = WorkplaceInjurySourceVersion::findOrFail($accepted['source_version_id']);
        $this->assertSame('Actual review', $version->content['command_facts']['notes']);
        $this->assertSame('reported', $injury->fresh()->status);
        $this->assertNull($injury->fresh()->actual_return_date);
    }

    public function test_attributed_assessor_withdrawal_denies_exact_retry_and_receipt_recovery(): void
    {
        $injury = $this->injury();
        $assessor = $this->staff();
        $payload = $this->capacityPayload($injury, ['assessor_user_id' => $assessor->id]);
        $accepted = $this->recovery()->recordCapacity($injury, $this->manager, $payload);
        \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $assessor->id)->update(['is_active' => false]);
        $this->denial(fn () => $this->recovery()->recordCapacity($injury, $this->manager, $payload));
        $this->denial(fn () => $this->source()->confirmationForViewer($this->manager, $injury->id, $payload['idempotency_key']));
        $this->assertSame(1, WorkCapacityAssessment::whereKey($accepted['source_id'])->count());
    }

    public function test_identical_plan_update_by_another_authorised_actor_retains_source_revision_and_recorder(): void
    {
        $injury = $this->injury();
        $created = $this->recovery()->createPlan($injury, $this->manager, $this->planPayload($injury));
        $plan = ReturnToWorkPlan::findOrFail($created['source_id']);
        $original = $plan->attributesToArray();
        $otherManager = $this->staff(['hazards.manage', 'hr.wellbeing.view']);
        $payload = $this->command($injury, ['goals' => $plan->goals, 'review_notes' => $plan->review_notes]);
        $accepted = $this->recovery()->updatePlan($injury, $plan, $otherManager, $payload);
        $this->assertFalse($accepted['changed']);
        $this->assertSame(2, $accepted['version']);
        $this->assertSame(2, $injury->fresh()->source_revision);
        $this->assertSame($original, $plan->fresh()->attributesToArray());
        $this->assertSame(2, WorkplaceInjurySourceVersion::count());
        $this->assertSame($otherManager->id, $accepted['actor_id']);
        $this->assertTrue($this->recovery()->updatePlan($injury, $plan, $otherManager, $payload)['replayed']);
        $this->assertSame($accepted['receipt_id'], $this->source()->confirmationForViewer($otherManager, $injury->id, $payload['idempotency_key'])['receipt_id']);
    }

    public function test_child_mutated_outside_source_commands_is_not_silently_reversioned(): void
    {
        $injury = $this->injury();
        $accepted = $this->recovery()->createPlan($injury, $this->manager, $this->planPayload($injury));
        $plan = ReturnToWorkPlan::findOrFail($accepted['source_id']);
        DB::table('return_to_work_plans')->where('id', $plan->id)->update(['review_notes' => 'Ungoverned mutation']);
        $this->expectException(LogicException::class);
        $this->recovery()->updatePlan($injury, $plan, $this->manager, $this->command($injury, ['review_notes' => 'Cannot conceal mutation']));
    }
}
