<?php

namespace Tests\Feature\Governance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceStrategyTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

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

        $approveResponse = $this->actingAs($admin)->post("/governance/strategy/{$plan->id}/approve");
        $approveResponse->assertRedirect();

        $plan->refresh();
        $this->assertEquals('active', $plan->status);
        $this->assertEquals($admin->id, $plan->approved_by);
    }
}
