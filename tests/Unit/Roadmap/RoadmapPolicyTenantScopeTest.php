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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RoadmapTestHelpers;
use Tests\TestCase;

class RoadmapPolicyTenantScopeTest extends TestCase
{
    use RefreshDatabase;
    use RoadmapTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoadmapModule();
    }

    public function test_admin_can_manage_roadmap_models_in_their_organization(): void
    {
        $admin = $this->createAdminUser(['organization_id' => 42]);

        $plan = new QuarterlyRoadmapPlan([
            'tenant_id' => 42,
            'status' => QuarterlyRoadmapPlan::STATUS_DRAFT,
        ]);
        $initiative = new Initiative(['tenant_id' => 42]);
        $decisionRequest = new DecisionRequest(['tenant_id' => 42]);
        $suggestion = new InitiativeSuggestion(['tenant_id' => 42]);
        $budget = new InitiativeBudget(['tenant_id' => 42]);

        $planPolicy = new QuarterlyRoadmapPlanPolicy();
        $initiativePolicy = new InitiativePolicy();
        $decisionPolicy = new DecisionRequestPolicy();
        $suggestionPolicy = new InitiativeSuggestionPolicy();
        $budgetPolicy = new InitiativeBudgetPolicy();

        $this->assertTrue($planPolicy->view($admin, $plan));
        $this->assertTrue($planPolicy->update($admin, $plan));
        $this->assertTrue($planPolicy->publish($admin, $plan));

        $this->assertTrue($initiativePolicy->view($admin, $initiative));
        $this->assertTrue($initiativePolicy->update($admin, $initiative));
        $this->assertTrue($initiativePolicy->delete($admin, $initiative));

        $this->assertTrue($decisionPolicy->view($admin, $decisionRequest));
        $this->assertTrue($decisionPolicy->resolve($admin, $decisionRequest));

        $this->assertTrue($suggestionPolicy->view($admin, $suggestion));
        $this->assertTrue($suggestionPolicy->update($admin, $suggestion));
        $this->assertTrue($suggestionPolicy->approve($admin, $suggestion));

        $this->assertTrue($budgetPolicy->view($admin, $budget));
        $this->assertTrue($budgetPolicy->update($admin, $budget));
    }

    public function test_admin_cannot_manage_roadmap_models_from_another_organization(): void
    {
        $admin = $this->createAdminUser(['organization_id' => 42]);

        $plan = new QuarterlyRoadmapPlan([
            'tenant_id' => 7,
            'status' => QuarterlyRoadmapPlan::STATUS_DRAFT,
        ]);

        $this->assertFalse((new QuarterlyRoadmapPlanPolicy())->view($admin, $plan));
    }
}
