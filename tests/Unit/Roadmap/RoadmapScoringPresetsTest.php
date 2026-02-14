<?php

namespace Tests\Unit\Roadmap;

use App\Domain\Roadmap\Services\RoadmapScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RoadmapTestHelpers;
use Tests\TestCase;

class RoadmapScoringPresetsTest extends TestCase
{
    use RefreshDatabase;
    use RoadmapTestHelpers;

    public function test_all_presets_available_and_unknown_preset_falls_back_to_board_ceo(): void
    {
        $this->seedRoadmapModule();

        $service = app(RoadmapScoringService::class);

        $presets = $service->allPresets();
        $this->assertArrayHasKey('board_ceo', $presets);
        $this->assertArrayHasKey('budget_first', $presets);
        $this->assertArrayHasKey('security_compliance', $presets);
        $this->assertArrayHasKey('house_rollout', $presets);

        $fallback = $service->preset('not_a_real_preset');
        $this->assertSame($presets['board_ceo'], $fallback);
    }

    public function test_score_stays_within_zero_to_hundred_bounds_even_for_extreme_inputs(): void
    {
        $this->seedRoadmapModule();

        $admin = $this->createAdminUser();

        $initiative = $this->createInitiative($admin, [
            'impact_profile' => [
                'safety' => 999,
                'compliance' => -10,
                'reputation' => 8,
                'financial' => -2,
                'efficiency' => 100,
                'urgency' => 100,
                'complexity' => 100,
                'dependency' => 100,
                'multi_site' => 100,
            ],
        ]);

        $service = app(RoadmapScoringService::class);
        $result = $service->score($initiative, 'board_ceo', false);

        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
        $this->assertContains($result['priority_band'], ['low', 'medium', 'high', 'critical']);
    }
}
