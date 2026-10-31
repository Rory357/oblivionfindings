<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ModifiedDuty;
use App\Models\ReturnToWorkPlan;
use App\Models\Site;
use App\Models\WorkCapacityAssessment;
use App\Services\HealthSafety\RestraintCommandValues;

/** Complete recovery/attribution/history projection, without inferred fitness or delivery success. */
class InjuryWorkspaceDetailTest extends InjuryWorkspaceReadTestCase
{
    public function test_typed_plan_downgrade_is_concealed_while_genuine_legacy_neighbour_and_accepted_history_remain_honest(): void
    {
        $plan = ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $this->worker->id,
            'source_schema_version' => 1, 'source_revision' => 1,
            'worker_snapshot' => ['id' => $this->worker->id, 'name' => $this->worker->name],
            'medical_clearance_notes' => 'Historically accepted plan fact',
        ]));
        $version = $this->version($plan, 'rtw_plan');
        $this->assertSame($version->id, $this->detail()['rtw_plans'][0]['source_version_id']);
        $legacy = ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $this->worker->id,
            'source_schema_version' => 0, 'source_revision' => 0,
        ]));
        $plan->getConnection()->table($plan->getTable())->where('id', $plan->id)->update([
            'source_schema_version' => 0, 'source_revision' => 0, 'medical_clearance_notes' => 'Unaccepted downgraded private fact',
        ]);
        $dto = $this->detail();
        $this->assertSame('repair_required', $dto['recovery_integrity']);
        $this->assertSame([$legacy->id], array_column($dto['rtw_plans'], 'id'));
        $this->assertSame('legacy_unknown', $dto['rtw_plans'][0]['attribution_state']);
        $this->assertNull($dto['rtw_plans'][0]['source_version_id']);
        $this->assertFalse($dto['can']['recovery']);
        $this->assertSame('Historically accepted plan fact', $dto['history'][0]['after']['medical_clearance_notes']);
        $this->assertSame($version->content_digest, $version->fresh()->content_digest);
        $this->assertStringNotContainsString('Unaccepted downgraded private fact', json_encode($dto));
    }

    public function test_typed_capacity_downgrade_does_not_become_an_unknown_legacy_assessor(): void
    {
        $assessment = WorkCapacityAssessment::withoutEvents(fn () => WorkCapacityAssessment::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'user_id' => $this->worker->id, 'worker_id' => $this->worker->id,
            'source_schema_version' => 1, 'source_revision' => 1,
            'worker_snapshot' => ['id' => $this->worker->id, 'name' => $this->worker->name],
        ]));
        $this->version($assessment, 'capacity_assessment');
        $this->assertSame($assessment->id, $this->detail()['capacity_assessments'][0]['id']);
        $assessment->getConnection()->table($assessment->getTable())->where('id', $assessment->id)->update([
            'source_schema_version' => 0, 'source_revision' => 0, 'assessment_summary' => 'Unaccepted downgraded capacity',
        ]);
        $legacy = WorkCapacityAssessment::withoutEvents(fn () => WorkCapacityAssessment::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'user_id' => $this->worker->id,
            'source_schema_version' => 0, 'source_revision' => 0,
        ]));
        $dto = $this->detail();
        $this->assertSame('repair_required', $dto['recovery_integrity']);
        $this->assertSame([$legacy->id], array_column($dto['capacity_assessments'], 'id'));
        $this->assertSame('legacy_unknown', $dto['capacity_assessments'][0]['attribution_state']);
        $this->assertNull($dto['capacity_assessments'][0]['source_version_id']);
        $this->assertStringNotContainsString('Unaccepted downgraded capacity', json_encode($dto));
    }

    public function test_typed_duty_downgrade_is_not_projected_under_a_valid_parent_plan(): void
    {
        $plan = ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $this->worker->id,
            'source_schema_version' => 1, 'source_revision' => 1,
            'worker_snapshot' => ['id' => $this->worker->id, 'name' => $this->worker->name],
        ]));
        $this->version($plan, 'rtw_plan');
        $duty = ModifiedDuty::withoutEvents(fn () => ModifiedDuty::factory()->create([
            'return_to_work_plan_id' => $plan->id, 'user_id' => $this->worker->id,
            'source_schema_version' => 1, 'source_revision' => 2,
            'worker_snapshot' => ['id' => $this->worker->id, 'name' => $this->worker->name],
        ]));
        $this->version($duty, 'modified_duty', 2);
        $this->assertSame($duty->id, $this->detail()['rtw_plans'][0]['modified_duties'][0]['id']);
        $duty->getConnection()->table($duty->getTable())->where('id', $duty->id)->update([
            'source_schema_version' => 0, 'source_revision' => 0, 'modified_duties_description' => 'Unaccepted downgraded duty',
        ]);
        $legacy = ModifiedDuty::withoutEvents(fn () => ModifiedDuty::factory()->create([
            'return_to_work_plan_id' => $plan->id, 'user_id' => $this->worker->id,
            'source_schema_version' => 0, 'source_revision' => 0,
        ]));
        $dto = $this->detail();
        $this->assertSame('repair_required', $dto['recovery_integrity']);
        $this->assertSame($plan->id, $dto['rtw_plans'][0]['id']);
        $this->assertSame('recorded', $dto['rtw_plans'][0]['attribution_state']);
        $this->assertSame([$legacy->id], array_column($dto['rtw_plans'][0]['modified_duties'], 'id'));
        $this->assertSame('legacy_unknown', $dto['rtw_plans'][0]['modified_duties'][0]['attribution_state']);
        $this->assertStringNotContainsString('Unaccepted downgraded duty', json_encode($dto));
    }

    public function test_zero_schema_with_nonzero_revision_cannot_claim_genuine_legacy_without_retained_history(): void
    {
        ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $this->worker->id,
            'source_schema_version' => 0, 'source_revision' => 7,
        ]));
        $dto = $this->detail();
        $this->assertSame('repair_required', $dto['recovery_integrity']);
        $this->assertSame([], $dto['rtw_plans']);
        $this->assertSame([], $dto['history']);
    }
    public function test_legacy_same_id_assessment_remains_unknown_not_verified_internal_assessor(): void
    {
        $assessment = WorkCapacityAssessment::withoutEvents(fn () => WorkCapacityAssessment::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'user_id' => $this->worker->id,
            'assessor_name' => 'Actually retained assessor claim', 'assessment_summary' => 'Retained clinical narrative',
        ]));
        $dto = $this->detail()['capacity_assessments'][0];
        $this->assertSame($assessment->id, $dto['id']);
        $this->assertSame($this->worker->id, $dto['worker']['id']);
        $this->assertNull($dto['assessor']);
        $this->assertNull($dto['recorded_worker']);
        $this->assertNull($dto['recorder']);
        $this->assertSame('Actually retained assessor claim', $dto['assessor_name']);
        $this->assertSame('legacy_unknown', $dto['attribution_state']);
        $this->assertSame('unknown', $dto['assessor_identity_kind']);
        $this->assertSame('unverified', $dto['medical_certification_state']);
    }

    public function test_typed_internal_assessment_separates_worker_assessor_and_actual_recorder(): void
    {
        $assessor = $this->staff([]);
        $assessment = WorkCapacityAssessment::withoutEvents(fn () => WorkCapacityAssessment::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'user_id' => $assessor->id, 'source_schema_version' => 1,
            'source_revision' => 1, 'worker_id' => $this->worker->id, 'assessor_user_id' => $assessor->id,
            'recorded_by' => $this->viewer->id, 'attribution_state' => 'recorded',
            'worker_snapshot' => ['id' => $this->worker->id, 'name' => 'Worker at observation'],
            'assessor_snapshot' => ['id' => $assessor->id, 'name' => 'Named internal observer'],
            'recorder_snapshot' => ['id' => $this->viewer->id, 'name' => 'Actual recorder at acceptance'],
        ]));
        $this->version($assessment, 'capacity_assessment');
        $dto = $this->detail()['capacity_assessments'][0];
        $this->assertSame($this->worker->id, $dto['recorded_worker']['id']);
        $this->assertSame($assessor->id, $dto['assessor']['id']);
        $this->assertSame($this->viewer->id, $dto['recorder']['id']);
        $this->assertSame('recorded_internal', $dto['assessor_identity_kind']);
        $this->assertSame('unverified', $dto['medical_certification_state']);
    }

    public function test_reported_external_assessor_never_becomes_an_internal_user_or_professional_validation(): void
    {
        $assessment = WorkCapacityAssessment::withoutEvents(fn () => WorkCapacityAssessment::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'user_id' => $this->worker->id, 'source_schema_version' => 1,
            'source_revision' => 1, 'worker_id' => $this->worker->id, 'assessor_user_id' => null, 'recorded_by' => $this->viewer->id,
            'attribution_state' => 'recorded', 'worker_snapshot' => ['id' => $this->worker->id, 'name' => $this->worker->name],
            'assessor_snapshot' => ['name' => 'Reported external clinician', 'type' => 'gp', 'attribution' => 'reported_external'],
            'recorder_snapshot' => ['id' => $this->viewer->id, 'name' => $this->viewer->name],
        ]));
        $this->version($assessment, 'capacity_assessment');
        $dto = $this->detail()['capacity_assessments'][0];
        $this->assertNull($dto['assessor']['id']);
        $this->assertSame('Reported external clinician', $dto['assessor']['name']);
        $this->assertSame('reported_external', $dto['assessor_identity_kind']);
        $this->assertSame('unverified', $dto['medical_certification_state']);
    }

    public function test_cross_worker_legacy_plan_and_duty_are_concealed_but_healthy_neighbour_is_retained(): void
    {
        $otherWorker = $this->staff([]);
        $healthy = ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $this->worker->id, 'goals' => ['Actual recovery goal'],
        ]));
        ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $otherWorker->id, 'medical_clearance_notes' => 'Foreign worker medical fact',
        ]));
        ModifiedDuty::withoutEvents(fn () => ModifiedDuty::factory()->create([
            'return_to_work_plan_id' => $healthy->id, 'user_id' => $otherWorker->id, 'modified_duties_description' => 'Foreign worker duty',
        ]));
        $dto = $this->detail();
        $this->assertSame('repair_required', $dto['recovery_integrity']);
        $this->assertCount(1, $dto['rtw_plans']);
        $this->assertSame($healthy->id, $dto['rtw_plans'][0]['id']);
        $this->assertSame([], $dto['rtw_plans'][0]['modified_duties']);
        $this->assertFalse($dto['can']['recovery']);
        $this->assertStringNotContainsString('Foreign worker', json_encode($dto));
    }

    public function test_typed_child_without_immutable_acceptance_is_unknown_and_concealed(): void
    {
        ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $this->worker->id, 'source_schema_version' => 1,
            'source_revision' => 1, 'worker_snapshot' => ['id' => $this->worker->id, 'name' => $this->worker->name],
            'medical_clearance_notes' => 'Unaccepted external mutation',
        ]));
        $dto = $this->detail();
        $this->assertSame('repair_required', $dto['recovery_integrity']);
        $this->assertSame([], $dto['rtw_plans']);
        $this->assertStringNotContainsString('Unaccepted external mutation', json_encode($dto));
    }

    public function test_external_typed_child_factual_mutation_cannot_hide_behind_valid_old_digest(): void
    {
        $plan = ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $this->worker->id, 'source_schema_version' => 1,
            'source_revision' => 1, 'worker_snapshot' => ['id' => $this->worker->id, 'name' => $this->worker->name],
            'medical_clearance_notes' => 'Accepted medical fact',
        ]));
        $version = $this->version($plan, 'rtw_plan');
        $this->assertSame($plan->id, $this->detail()['rtw_plans'][0]['id']);
        $plan->updateQuietly(['medical_clearance_notes' => 'Changed outside accepted source command']);
        $dto = $this->detail();
        $this->assertSame('repair_required', $dto['recovery_integrity']);
        $this->assertSame([], $dto['rtw_plans']);
        $this->assertSame('Accepted medical fact', $dto['history'][0]['after']['medical_clearance_notes']);
        $this->assertSame($version->content_digest, $version->fresh()->content_digest);
        $this->assertStringNotContainsString('Changed outside accepted source command', json_encode($dto));
    }

    public function test_damaged_version_digest_cannot_project_history_or_certify_typed_capacity(): void
    {
        $assessment = WorkCapacityAssessment::withoutEvents(fn () => WorkCapacityAssessment::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'user_id' => $this->worker->id, 'worker_id' => $this->worker->id,
            'source_schema_version' => 1, 'source_revision' => 1,
            'worker_snapshot' => ['id' => $this->worker->id, 'name' => $this->worker->name], 'assessment_summary' => 'Corrupted private fact',
        ]));
        $version = $this->version($assessment, 'capacity_assessment');
        $version->getConnection()->table($version->getTable())->where('id', $version->id)->update(['content_digest' => str_repeat('0', 64)]);
        $dto = $this->detail();
        $this->assertSame('repair_required', $dto['history_integrity']);
        $this->assertSame('repair_required', $dto['recovery_integrity']);
        $this->assertSame([], $dto['history']);
        $this->assertSame([], $dto['capacity_assessments']);
        $this->assertStringNotContainsString('Corrupted private fact', json_encode($dto));
    }

    public function test_intact_former_worker_correction_history_is_retained_without_relabelling_or_corruption(): void
    {
        $first = $this->version($this->injury, 'injury');
        $old = $this->injury->fresh()->attributesToArray();
        $replacement = $this->staff([]);
        $this->injury->updateQuietly(['user_id' => $replacement->id, 'source_revision' => 2]);
        $this->version($this->injury, 'injury', 2, ['worker_id' => $replacement->id,
            'content' => ['before' => $old, 'after' => $this->injury->fresh()->attributesToArray()]]);
        $dto = $this->detail();
        $this->assertSame('valid', $dto['history_integrity']);
        $this->assertSame($replacement->id, $dto['worker']['id']);
        $this->assertSame($this->worker->id, $dto['history'][1]['source_context']['worker_id']);
        $this->assertSame($this->worker->id, $dto['history'][0]['before']['user_id']);
        $this->assertSame($replacement->id, $dto['history'][0]['after']['user_id']);
        $this->assertSame($first->content_digest, $first->fresh()->content_digest);
    }

    public function test_original_site_revocation_redacts_former_history_without_turning_correction_into_corruption(): void
    {
        $oldVersion = $this->version($this->injury, 'injury');
        $old = $this->injury->fresh()->attributesToArray();
        $newSite = Site::factory()->create(['is_active' => true]);
        $this->viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$newSite->id]]);
        $this->injury->updateQuietly(['site_id' => $newSite->id, 'notes' => 'Current private recovery fact', 'source_revision' => 2]);
        $this->version($this->injury, 'injury', 2, ['site_id' => $newSite->id,
            'content' => ['before' => $old, 'after' => $this->injury->fresh()->attributesToArray()]]);
        $this->assertSame('Private recovery note', $this->detail()['history'][0]['before']['notes']);
        $this->viewer->hrEmployeeProfile()->update(['primary_site_id' => $newSite->id, 'secondary_site_ids' => []]);
        $dto = $this->detail();
        $this->assertSame('valid', $dto['history_integrity']);
        $this->assertSame('restricted', $dto['history'][1]['access']);
        $this->assertNull($dto['history'][1]['source_context']);
        $this->assertNull($dto['history'][1]['after']);
        $this->assertNull($dto['history'][0]['before']);
        $this->assertSame('Current private recovery fact', $dto['history'][0]['after']['notes']);
        $this->assertSame($oldVersion->content_digest, $oldVersion->fresh()->content_digest);
    }

    public function test_active_recovery_and_capacity_status_do_not_invent_workforce_or_medical_authorisation(): void
    {
        ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $this->worker->id, 'status' => 'active',
        ]));
        WorkCapacityAssessment::withoutEvents(fn () => WorkCapacityAssessment::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'capacity_status' => 'fit_for_full_duties',
        ]));
        $dto = $this->detail();
        $this->assertSame('unknown', $dto['rtw_plans'][0]['approval_state']);
        $this->assertSame('unverified', $dto['capacity_assessments'][0]['medical_certification_state']);
        $this->assertSame('pending_interface', $dto['scheduling_impact']['state']);
        $this->assertSame('unknown', $dto['scheduling_impact']['outcome']);
        $this->assertNull($dto['scheduling_impact']['affected_duties']);
        $this->assertFalse($dto['can']['declare_fit']);
        $this->assertFalse($dto['can']['approve_restrictions']);
    }

    public function test_recovered_and_claim_recorded_facts_do_not_invent_actual_return_or_external_receipt(): void
    {
        $this->injury->updateQuietly(['status' => 'recovered', 'actual_return_date' => null, 'acc_claim_lodged' => true]);
        $dto = $this->detail();
        $this->assertNull($dto['actual_return_date']);
        $this->assertTrue($dto['claim_delivery']['internally_recorded']);
        $this->assertSame('unconfirmed', $dto['claim_delivery']['external_receipt_state']);
        $this->assertNull($dto['claim_delivery']['acknowledged_at']);
    }
    public function test_factual_version_history_does_not_fill_missing_medical_fields_from_current_parent(): void
    {
        $after = array_intersect_key($this->injury->fresh()->attributesToArray(), array_flip(\App\Services\HealthSafety\InjuryAccessService::FACTUAL_COLUMNS));
        $this->version($this->injury, 'injury', 1, ['content' => ['scope' => 'factual', 'after' => $after]]);
        $dto = $this->detail();
        $this->assertSame('factual', $dto['history'][0]['content_scope']);
        $this->assertArrayNotHasKey('notes', $dto['history'][0]['after']);
        $this->assertArrayNotHasKey('acc_claim_number', $dto['history'][0]['after']);
        $this->assertSame('Private recovery note', $dto['notes']);
        $this->assertSame('valid', $dto['history_integrity']);
    }

    public function test_recomputed_digest_cannot_hide_wrong_historical_source_identity(): void
    {
        $before = $this->injury->fresh()->attributesToArray();
        $before['id'] = $this->injury->id + 100;
        $before['notes'] = 'Foreign historical source narrative';
        $this->version($this->injury, 'injury', 1, ['content' => ['before' => $before]]);
        $dto = $this->detail();
        $this->assertSame('repair_required', $dto['history_integrity']);
        $this->assertSame([], $dto['history']);
        $this->assertStringNotContainsString('Foreign historical source narrative', json_encode($dto));
    }
}
