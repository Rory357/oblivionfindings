<?php

namespace Tests\Unit\Governance;

use App\Domain\Governance\Services\RiskScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class RiskScoringServiceTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    public function test_calculations_and_labels(): void
    {
        $service = new RiskScoringService();

        $this->assertEquals(25, $service->calculateInherentScore(5, 5));
        $this->assertEquals(10, $service->calculateResidualScore(20, 'moderate'));
        $this->assertEquals('High', $service->getRiskLevel(15));
        $this->assertEquals('#ea580c', $service->getRiskColor(15));
    }

    public function test_is_within_appetite_uses_thresholds(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $risk = $this->createRisk($admin, [
            'category' => 'financial',
            'residual_score' => 8,
            'appetite_threshold' => 10,
        ]);

        $service = new RiskScoringService();
        $this->assertTrue($service->isWithinAppetite($risk));

        $risk->update(['residual_score' => 14]);
        $this->assertFalse($service->isWithinAppetite($risk));
    }

    public function test_generate_heatmap_data_shape(): void
    {
        $service = new RiskScoringService();

        $heatmap = $service->generateHeatmapData();

        $this->assertCount(5, $heatmap);
        foreach ($heatmap as $row) {
            $this->assertCount(5, $row);
            $this->assertArrayHasKey('score', $row[0]);
            $this->assertArrayHasKey('count', $row[0]);
            $this->assertArrayHasKey('color', $row[0]);
        }
    }
}
