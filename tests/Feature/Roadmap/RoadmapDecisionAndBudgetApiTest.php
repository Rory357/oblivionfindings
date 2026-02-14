<?php

namespace Tests\Feature\Roadmap;

use App\Domain\Roadmap\Services\RoadmapDecisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RoadmapTestHelpers;
use Tests\TestCase;

class RoadmapDecisionAndBudgetApiTest extends TestCase
{
    use RefreshDatabase;
    use RoadmapTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoadmapModule();
    }

    public function test_admin_can_list_and_resolve_decision_requests(): void
    {
        $admin = $this->createAdminUser();
        $initiative = $this->createInitiative($admin, [
            'cost_estimate_high' => 60000,
            'priority_band' => 'high',
        ]);

        $request = app(RoadmapDecisionService::class)
            ->ensureDecisionRequestForInitiative($initiative, $admin->id);

        $this->assertNotNull($request);

        $listResponse = $this->actingAs($admin)->getJson('/roadmap/decisions?status=pending');
        $listResponse->assertOk();
        $this->assertGreaterThanOrEqual(1, count($listResponse->json('items.data')));

        $resolveResponse = $this->actingAs($admin)
            ->postJson("/roadmap/decisions/{$request->id}/resolve", [
                'status' => 'approved',
                'notes' => 'Approved via test',
            ]);

        $resolveResponse->assertOk();
        $this->assertSame('approved', $resolveResponse->json('item.status'));
    }

    public function test_budget_replan_endpoint_returns_kept_and_deferred_sets(): void
    {
        $admin = $this->createAdminUser();
        $this->createInitiative($admin, [
            'title' => 'Initiative A',
            'cost_estimate_high' => 80,
            'priority_score' => 80,
            'impact_profile' => [
                'safety' => 2,
                'compliance' => 2,
                'reputation' => 2,
                'financial' => 3,
                'efficiency' => 3,
                'urgency' => 2,
                'complexity' => 2,
                'dependency' => 2,
                'multi_site' => 2,
            ],
        ]);
        $this->createInitiative($admin, [
            'title' => 'Initiative B',
            'cost_estimate_high' => 60,
            'priority_score' => 70,
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

        $response = $this->actingAs($admin)->postJson('/roadmap/budget/replan', [
            'new_envelope' => 100,
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'result' => [
                'new_envelope',
                'kept_total',
                'kept',
                'deferred',
                'required_decisions',
            ],
            'generated_at',
        ]);
    }
}
