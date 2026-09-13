<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\StrategicPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceStrategyTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_admin_can_create_plan(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->post('/governance/strategy', [
            'title' => 'Strategy Plan',
            'planning_horizon' => '3_year',
            'period_start' => now()->toDateString(),
            'period_end' => now()->addYears(3)->toDateString(),
            'vision_statement' => 'Vision',
            'mission_statement' => 'Mission',
            'values' => ['integrity'],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('strategic_plans', [
            'title' => 'Strategy Plan',
            'planning_horizon' => '3_year',
        ]);
    }

    public function test_admin_can_add_goal_and_approve_plan(): void
    {
        $admin = $this->createAdminUser();
        $plan = $this->createStrategicPlan($admin);

        $goalResponse = $this->actingAs($admin)->post("/governance/strategy/{$plan->id}/goals", [
            'title' => 'Improve quality',
            'description' => 'Lift quality outcomes',
            'order' => 1,
        ]);
        $goalResponse->assertRedirect();

        $this->assertDatabaseHas('strategic_goals', [
            'strategic_plan_id' => $plan->id,
            'title' => 'Improve quality',
        ]);

        $resolution = $this->createResolution($admin, [
            'status' => 'closed',
            'outcome' => 'carried',
        ]);

        $approveResponse = $this->actingAs($admin)->post("/governance/strategy/{$plan->id}/approve", [
            'resolution_id' => $resolution->id,
        ]);
        $approveResponse->assertRedirect();

        $plan->refresh();
        $this->assertEquals('approved', $plan->status);
        $this->assertEquals($resolution->id, $plan->approval_resolution_id);
        $this->assertNotNull($plan->approved_by_board_at);
        $this->assertNotNull($plan->last_snapshot);
        $this->assertCount(1, $plan->last_snapshot);
    }

    public function test_plan_approval_rejects_draft_open_or_defeated_resolution(): void
    {
        $admin = $this->createAdminUser();
        $plan = $this->createStrategicPlan($admin);

        // Draft resolution
        $draftRes = $this->createResolution($admin, [
            'status' => 'draft',
            'outcome' => 'pending',
        ]);

        $response = $this->actingAs($admin)->post("/governance/strategy/{$plan->id}/approve", [
            'resolution_id' => $draftRes->id,
        ]);
        $response->assertSessionHasErrors('resolution_id');
        $plan->refresh();
        $this->assertEquals('draft', $plan->status);

        // Defeated resolution
        $defeatedRes = $this->createResolution($admin, [
            'status' => 'closed',
            'outcome' => 'defeated',
        ]);

        $response2 = $this->actingAs($admin)->post("/governance/strategy/{$plan->id}/approve", [
            'resolution_id' => $defeatedRes->id,
        ]);
        $response2->assertSessionHasErrors('resolution_id');
        $plan->refresh();
        $this->assertEquals('draft', $plan->status);
    }

    public function test_plan_approval_rejects_already_used_resolution_for_active_plan(): void
    {
        $admin = $this->createAdminUser();
        $planA = $this->createStrategicPlan($admin);
        $planB = $this->createStrategicPlan($admin, ['title' => 'Second Plan']);

        $resolution = $this->createResolution($admin, [
            'status' => 'closed',
            'outcome' => 'carried',
        ]);

        $this->actingAs($admin)->post("/governance/strategy/{$planA->id}/approve", [
            'resolution_id' => $resolution->id,
        ])->assertRedirect();

        $planA->refresh();
        $this->assertEquals('approved', $planA->status);

        // Attempting to use the same carried resolution for another active plan must fail
        $response = $this->actingAs($admin)->post("/governance/strategy/{$planB->id}/approve", [
            'resolution_id' => $resolution->id,
        ]);
        $response->assertSessionHasErrors('resolution_id');
        $planB->refresh();
        $this->assertEquals('draft', $planB->status);
    }

    public function test_plan_approval_supersedes_prior_plan_and_captures_snapshot(): void
    {
        $admin = $this->createAdminUser();
        $planA = $this->createStrategicPlan($admin, ['version_number' => 1]);

        $res1 = $this->createResolution($admin, [
            'status' => 'closed',
            'outcome' => 'carried',
        ]);

        $planA->approve($res1->id);
        $planA->refresh();
        $this->assertEquals('approved', $planA->status);

        // Create version 2
        $planB = $planA->createNewVersion('Annual refresh', $admin->id);
        $this->assertEquals(2, $planB->version_number);
        $this->assertEquals($planA->id, $planB->supersedes_plan_id);
        $this->assertEquals('draft', $planB->status);

        $res2 = $this->createResolution($admin, [
            'status' => 'closed',
            'outcome' => 'carried',
        ]);

        $planB->approve($res2->id);
        $planB->refresh();
        $planA->refresh();

        $this->assertEquals('approved', $planB->status);
        $this->assertEquals('superseded', $planA->status);
    }

    public function test_version_creation_preserves_goal_lineage_and_prevents_false_positive_added_changes(): void
    {
        $admin = $this->createAdminUser();
        $planA = $this->createStrategicPlan($admin, ['version_number' => 1]);

        $goal = $planA->goals()->create([
            'title' => 'Expand specialist care',
            'description' => 'Add two regional centers',
            'pillar' => 'quality',
            'timeframe' => '2026-2029',
            'progress_pct' => 10,
            'status' => 'in_progress',
            'lead_executive_id' => $admin->id,
            'order' => 1,
        ]);

        $res1 = $this->createResolution($admin, [
            'status' => 'closed',
            'outcome' => 'carried',
        ]);

        $planA->approve($res1->id);
        $planA->refresh();

        // Create new version
        $planB = $planA->createNewVersion('Update targets', $admin->id);
        $planB->refresh();

        $newGoal = $planB->goals()->first();
        $this->assertNotNull($newGoal);
        $this->assertNotEquals($goal->id, $newGoal->id);
        $this->assertEquals($goal->id, $newGoal->origin_goal_id);

        // Compare changes on planB before modifications
        $changeData = $planB->getChangesSinceLastSnapshot();
        $this->assertTrue($changeData['has_snapshot']);
        $this->assertStringContainsString('approved baseline', $changeData['baseline_label']);
        $this->assertEmpty($changeData['changes'], 'Cloned goals must preserve lineage and not be falsely reported as added.');

        // Now modify goal on Plan B
        $newGoal->update([
            'progress_pct' => 60,
            'status' => 'achieved',
        ]);

        // Add a brand new goal on Plan B
        $planB->goals()->create([
            'title' => 'Digital Health Record',
            'description' => 'Rollout EHR',
            'pillar' => 'it_resilience',
            'timeframe' => '2026-2029',
            'lead_executive_id' => $admin->id,
            'order' => 2,
        ]);

        $diffs = $planB->getChangesSinceLastSnapshot();
        $this->assertCount(2, $diffs['changes']);

        $types = collect($diffs['changes'])->pluck('type')->toArray();
        $this->assertContains('updated', $types);
        $this->assertContains('added', $types);
    }

    public function test_get_changes_without_snapshot_or_prior_baseline_returns_explicit_unavailable(): void
    {
        $admin = $this->createAdminUser();
        $plan = $this->createStrategicPlan($admin);

        $changes = $plan->getChangesSinceLastSnapshot();
        $this->assertFalse($changes['has_snapshot']);
        $this->assertEquals('Comparison not available', $changes['baseline_label']);
        $this->assertEmpty($changes['changes']);
    }

    public function test_legacy_status_mutator_and_scopes(): void
    {
        $admin = $this->createAdminUser();
        $plan = $this->createStrategicPlan($admin);

        $plan->status = 'active';
        $this->assertEquals('approved', $plan->status);

        $plan->status = 'completed';
        $this->assertEquals('archived', $plan->status);

        $plan->save();

        $this->assertTrue(StrategicPlan::active()->where('id', $plan->id)->doesntExist());
        $this->assertTrue(StrategicPlan::archived()->where('id', $plan->id)->exists());
    }
}
