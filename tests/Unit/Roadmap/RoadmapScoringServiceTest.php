<?php

namespace Tests\Unit\Roadmap;

use App\Domain\Roadmap\Models\Initiative;
use App\Domain\Roadmap\Models\InitiativeCategory;
use App\Domain\Roadmap\Services\RoadmapScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoadmapScoringServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scores_initiative_and_persists_breakdown(): void
    {
        $category = InitiativeCategory::create([
            'key' => 'it',
            'name' => 'IT',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $initiative = Initiative::create([
            'title' => 'MFA rollout',
            'category_id' => $category->id,
            'status' => Initiative::STATUS_PROPOSED,
            'impact_profile' => [
                'safety' => 4,
                'compliance' => 5,
                'reputation' => 4,
                'financial' => 2,
                'efficiency' => 3,
                'urgency' => 4,
                'complexity' => 2,
                'dependency' => 1,
                'multi_site' => 5,
            ],
            'decision_due_at' => now()->addDays(10),
        ]);

        $service = app(RoadmapScoringService::class);
        $result = $service->score($initiative, 'security_compliance', true);

        $this->assertArrayHasKey('score', $result);
        $this->assertGreaterThan(0, $result['score']);

        $initiative->refresh();
        $this->assertNotNull($initiative->priority_score);
        $this->assertNotNull($initiative->score_breakdown);
        $this->assertEquals('security_compliance', $initiative->score_profile);
    }
}
