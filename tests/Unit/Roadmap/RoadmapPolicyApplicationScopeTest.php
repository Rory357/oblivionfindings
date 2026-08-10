<?php

namespace Tests\Unit\Roadmap;

use App\Domain\Roadmap\Models\DecisionRequest;
use App\Domain\Roadmap\Models\Initiative;
use App\Domain\Roadmap\Models\InitiativeBudget;
use App\Domain\Roadmap\Models\InitiativeSuggestion;
use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use App\Domain\Roadmap\Policies\DecisionRequestPolicy;
use App\Domain\Roadmap\Policies\InitiativeBudgetPolicy;
use App\Domain\Roadmap\Policies\InitiativePolicy;
use App\Domain\Roadmap\Policies\InitiativeSuggestionPolicy;
use App\Domain\Roadmap\Policies\QuarterlyRoadmapPlanPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RoadmapTestHelpers;
use Tests\TestCase;

class RoadmapPolicyApplicationScopeTest extends TestCase
{
    use RefreshDatabase;
    use RoadmapTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoadmapModule();
    }

    public function test_permissions_authorize_the_shared_application_records(): void
    {
        $admins = [
            $this->createAdminUser(),
            $this->createAdminUser(),
        ];
        $plan = new QuarterlyRoadmapPlan(['status' => QuarterlyRoadmapPlan::STATUS_DRAFT]);
        $initiative = new Initiative;
        $decisionRequest = new DecisionRequest;
        $suggestion = new InitiativeSuggestion;
        $budget = new InitiativeBudget;

        foreach ($admins as $admin) {
            $this->assertTrue((new QuarterlyRoadmapPlanPolicy)->view($admin, $plan));
            $this->assertTrue((new QuarterlyRoadmapPlanPolicy)->update($admin, $plan));
            $this->assertTrue((new QuarterlyRoadmapPlanPolicy)->publish($admin, $plan));
            $this->assertTrue((new InitiativePolicy)->view($admin, $initiative));
            $this->assertTrue((new InitiativePolicy)->update($admin, $initiative));
            $this->assertTrue((new InitiativePolicy)->delete($admin, $initiative));
            $this->assertTrue((new DecisionRequestPolicy)->view($admin, $decisionRequest));
            $this->assertTrue((new DecisionRequestPolicy)->resolve($admin, $decisionRequest));
            $this->assertTrue((new InitiativeSuggestionPolicy)->view($admin, $suggestion));
            $this->assertTrue((new InitiativeSuggestionPolicy)->update($admin, $suggestion));
            $this->assertTrue((new InitiativeSuggestionPolicy)->approve($admin, $suggestion));
            $this->assertTrue((new InitiativeBudgetPolicy)->view($admin, $budget));
            $this->assertTrue((new InitiativeBudgetPolicy)->update($admin, $budget));
        }
    }

    public function test_permissions_still_deny_an_unprivileged_user(): void
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $plan = new QuarterlyRoadmapPlan(['status' => QuarterlyRoadmapPlan::STATUS_DRAFT]);

        $this->assertFalse((new QuarterlyRoadmapPlanPolicy)->view($user, $plan));
        $this->assertFalse((new QuarterlyRoadmapPlanPolicy)->update($user, $plan));
        $this->assertFalse((new InitiativePolicy)->view($user, new Initiative));
        $this->assertFalse((new DecisionRequestPolicy)->view($user, new DecisionRequest));
        $this->assertFalse((new InitiativeSuggestionPolicy)->view($user, new InitiativeSuggestion));
        $this->assertFalse((new InitiativeBudgetPolicy)->view($user, new InitiativeBudget));
    }

    public function test_published_plans_remain_immutable(): void
    {
        $admin = $this->createAdminUser();
        $plan = new QuarterlyRoadmapPlan([
            'status' => QuarterlyRoadmapPlan::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->assertFalse((new QuarterlyRoadmapPlanPolicy)->update($admin, $plan));
    }
}
