<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ModifiedDuty;
use App\Models\ReturnToWorkPlan;
use App\Models\User;

/**
 * Decision 10 (Stephan, 1 Nov 2026; board G4-8): a team lead coordinating someone's return to work sees their duty
 * restrictions only — never the medical reason, treatment, notes, clearance, workplace changes, fitness checks, ACC
 * claim number or files. injuries.dutyRestrictions.view (grant migration 2026_11_01_234000, team_lead only) shows them
 * on an injury the person coordinates and can already open, and nowhere else.
 */
class InjuryDutyRestrictionsTest extends InjuryWorkspaceReadTestCase
{
    private const KEY = 'injuries.dutyRestrictions.view';

    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lead = $this->staff(['hazards.view', self::KEY]);
        $this->coordinate($this->lead);
        $plan = ReturnToWorkPlan::withoutEvents(fn () => ReturnToWorkPlan::factory()->create([
            'workplace_injury_id' => $this->injury->id, 'worker_id' => $this->worker->id,
            'source_schema_version' => 0, 'source_revision' => 0,
            'medical_clearance_notes' => 'PRIVATE clearance reason', 'medical_clearance_provider' => 'PRIVATE GP clinic',
            'review_notes' => 'PRIVATE plan review', 'workplace_modifications' => 'PRIVATE workplace change',
        ]));
        ModifiedDuty::withoutEvents(fn () => ModifiedDuty::factory()->create([
            'return_to_work_plan_id' => $plan->id, 'user_id' => $this->worker->id,
            'source_schema_version' => 0, 'source_revision' => 0, 'status' => 'active',
            'start_date' => '2026-10-28', 'end_date' => '2026-11-03', 'hours_per_day' => 8,
            'modified_duties_description' => 'Light kitchen and lounge duties on her 7–3 shift',
            'restrictions' => 'No knife work or wet hand-washing tasks',
            'accommodations' => 'PRIVATE accommodation detail', 'notes' => 'PRIVATE duty note',
        ]));
    }

    private function coordinate(User $person): void
    {
        $this->injury->updateQuietly(['coordinator_user_id' => $person->id, 'coordinator_snapshot' => ['id' => $person->id, 'name' => $person->name]]);
    }

    public function test_the_coordinating_team_lead_sees_duty_restrictions_and_no_medical_detail(): void
    {
        $dto = $this->detail($this->lead);

        $this->assertSame('restricted', $dto['medical_access']);
        $duty = $dto['duty_restrictions']['items'][0];
        $this->assertSame('No knife work or wet hand-washing tasks', $duty['restrictions']);
        $this->assertSame('Light kitchen and lounge duties on her 7–3 shift', $duty['duties']);
        $this->assertSame('2026-11-03', $duty['end_date']);
        $this->assertSame(['id', 'status', 'start_date', 'end_date', 'duties', 'restrictions', 'hours_per_day', 'set_by', 'set_at'], array_keys($duty));

        // Every medical field stays hidden.
        foreach (['immediate_treatment', 'medical_treatment_type', 'medical_treatment_label', 'acc_claim_number', 'notes', 'rtw_plans', 'capacity_assessments', 'attachments', 'history'] as $field) {
            $this->assertNull($dto[$field], $field.' must stay hidden from the coordinator.');
        }
        $json = json_encode($dto);
        foreach (['Private clinical treatment', 'Private recovery note', 'PRIVATE-ACC-123', 'PRIVATE clearance reason', 'PRIVATE GP clinic',
            'PRIVATE plan review', 'PRIVATE workplace change', 'PRIVATE accommodation detail', 'PRIVATE duty note'] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }

    public function test_a_team_lead_who_doesnt_coordinate_this_injury_sees_no_restrictions(): void
    {
        $other = $this->staff(['hazards.view', self::KEY]);

        $dto = $this->detail($other);

        $this->assertNull($dto['duty_restrictions']);
        $this->assertStringNotContainsString('No knife work', json_encode($dto));
    }

    public function test_the_coordinator_without_the_key_sees_no_restrictions(): void
    {
        $this->revoke($this->lead, self::KEY);

        $this->assertNull($this->detail($this->lead)['duty_restrictions']);
    }

    public function test_the_key_never_opens_an_injury_the_person_cant_already_read(): void
    {
        $keyOnly = $this->staff([self::KEY]);
        $this->coordinate($keyOnly);

        $this->denied(fn () => $this->detail($keyOnly), 403);
    }

    public function test_people_with_medical_access_read_the_full_recovery_plan_not_this_summary(): void
    {
        $dto = $this->detail($this->viewer);

        $this->assertSame('readable', $dto['medical_access']);
        $this->assertNull($dto['duty_restrictions']);
        $this->assertSame('No knife work or wet hand-washing tasks', $dto['rtw_plans'][0]['modified_duties'][0]['restrictions']);
    }
}
