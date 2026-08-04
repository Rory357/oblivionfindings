<?php

namespace Tests\Feature\Roadmap;

use App\Domain\Roadmap\Models\ReportSnapshot;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RoadmapTestHelpers;
use Tests\TestCase;

class RoadmapReportsApiTest extends TestCase
{
    use RefreshDatabase;
    use RoadmapTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoadmapModule();
    }

    public function test_admin_can_generate_and_view_all_report_types(): void
    {
        $admin = $this->createAdminUser();
        $this->createInitiative($admin, ['status' => 'approved']);

        $planResponse = $this->actingAs($admin)->postJson('/roadmap/quarterly-plans/generate', [
            'fiscal_year' => now()->year,
            'quarter' => now()->quarter,
            'preset' => 'board_ceo',
        ]);

        $planResponse->assertCreated();
        $planId = $planResponse->json('item.id');

        $this->actingAs($admin)->postJson("/roadmap/quarterly-plans/{$planId}/submit-manager")->assertOk();
        $this->actingAs($admin)->postJson("/roadmap/quarterly-plans/{$planId}/submit-executive")->assertOk();
        $this->actingAs($admin)->postJson("/roadmap/quarterly-plans/{$planId}/approve")->assertOk();
        $this->actingAs($admin)->postJson("/roadmap/quarterly-plans/{$planId}/publish")->assertOk();

        $types = [
            'best_all_round',
            'budget_first',
            'board_ceo_short',
            'security_compliance',
            'house_rollout',
            'maintenance_sop',
            'scoring_transparency',
        ];

        foreach ($types as $type) {
            $response = $this->actingAs($admin)->postJson("/roadmap/reports/{$type}", [
                'plan_id' => $planId,
            ]);

            $response->assertCreated();
            $snapshotId = $response->json('item.id');

            $showResponse = $this->actingAs($admin)->getJson("/roadmap/reports/snapshots/{$snapshotId}");
            $showResponse->assertOk();
            $this->assertSame($type, $showResponse->json('item.report_type'));
        }
    }

    public function test_quarterly_publish_endpoint_returns_422_for_invalid_state(): void
    {
        $admin = $this->createAdminUser();
        $this->createInitiative($admin, ['status' => 'approved']);

        $planResponse = $this->actingAs($admin)->postJson('/roadmap/quarterly-plans/generate', [
            'fiscal_year' => now()->year,
            'quarter' => now()->quarter,
        ]);

        $planResponse->assertCreated();
        $planId = $planResponse->json('item.id');

        $publishResponse = $this->actingAs($admin)
            ->postJson("/roadmap/quarterly-plans/{$planId}/publish");

        $publishResponse->assertStatus(422);
        $publishResponse->assertJsonStructure(['message']);
    }

    public function test_report_snapshot_show_requires_roadmap_permission(): void
    {
        $admin = $this->createAdminUser();

        $snapshot = ReportSnapshot::create([
            'report_type' => 'best_all_round',
            'name' => 'Application roadmap report',
            'checksum' => hash('sha256', 'x'),
            'payload' => ['type' => 'best_all_round'],
            'generated_by' => $admin->id,
            'generated_at' => now(),
            'immutable' => true,
        ]);

        $unprivileged = User::factory()->create(['approved_at' => now()]);
        $response = $this->actingAs($unprivileged)
            ->getJson("/roadmap/reports/snapshots/{$snapshot->id}");

        $response->assertForbidden();
    }
}
