<?php

namespace Tests\Unit\Roadmap;

use App\Domain\Roadmap\Services\RoadmapBudgetReplanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RoadmapTestHelpers;
use Tests\TestCase;

class RoadmapBudgetReplanServiceTest extends TestCase
{
    use RefreshDatabase;
    use RoadmapTestHelpers;

    public function test_budget_replan_keeps_protected_items_and_flags_high_risk_deferrals(): void
    {
        $this->seedRoadmapModule();

        $admin = $this->createAdminUser();

        $protected = $this->createInitiative($admin, [
            'title' => 'Safety-critical alarms',
            'cost_estimate_high' => 90,
            'priority_score' => 85,
            'impact_profile' => [
                'safety' => 5,
                'compliance' => 2,
                'reputation' => 2,
                'financial' => 1,
                'efficiency' => 1,
                'urgency' => 4,
                'complexity' => 2,
                'dependency' => 2,
                'multi_site' => 3,
            ],
        ]);

        $deferredHigh = $this->createInitiative($admin, [
            'title' => 'Secondary network uplift',
            'cost_estimate_high' => 80,
            'priority_score' => 75,
            'impact_profile' => [
                'safety' => 2,
                'compliance' => 2,
                'reputation' => 2,
                'financial' => 3,
                'efficiency' => 3,
                'urgency' => 2,
                'complexity' => 3,
                'dependency' => 3,
                'multi_site' => 2,
            ],
        ]);

        $deferredLow = $this->createInitiative($admin, [
            'title' => 'Back-office tidy up',
            'cost_estimate_high' => 20,
            'priority_score' => 40,
            'impact_profile' => [
                'safety' => 1,
                'compliance' => 1,
                'reputation' => 1,
                'financial' => 2,
                'efficiency' => 2,
                'urgency' => 1,
                'complexity' => 2,
                'dependency' => 2,
                'multi_site' => 1,
            ],
        ]);

        $service = app(RoadmapBudgetReplanService::class);
        $result = $service->replanForBudgetCut(100);

        $keptIds = array_column($result['kept'], 'initiative_id');
        $deferredIds = array_column($result['deferred'], 'initiative_id');
        $requiredDecisionIds = array_column($result['required_decisions'], 'initiative_id');

        $this->assertContains($protected->id, $keptIds);
        $this->assertContains($deferredHigh->id, $deferredIds);
        $this->assertContains($deferredLow->id, $deferredIds);
        $this->assertContains($deferredHigh->id, $requiredDecisionIds);
        $this->assertNotContains($deferredLow->id, $requiredDecisionIds);
    }
}
