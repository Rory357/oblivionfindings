<?php

namespace Tests\Unit\Roadmap;

use App\Domain\Roadmap\Models\InitiativeSiteScope;
use App\Domain\Roadmap\Models\InitiativeSiteScopeSite;
use App\Domain\Roadmap\Services\QuarterlyRoadmapPlannerService;
use App\Domain\Roadmap\Services\RoadmapReportService;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RoadmapTestHelpers;
use Tests\TestCase;

class RoadmapReportServiceTest extends TestCase
{
    use RefreshDatabase;
    use RoadmapTestHelpers;

    protected RoadmapReportService $reportService;

    protected QuarterlyRoadmapPlannerService $plannerService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoadmapModule();
        $this->reportService = app(RoadmapReportService::class);
        $this->plannerService = app(QuarterlyRoadmapPlannerService::class);
    }

    public function test_generate_supports_all_report_types_with_immutable_snapshots(): void
    {
        $admin = $this->createAdminUser();

        $initiative = $this->createInitiative($admin, [
            'title' => 'Ops efficiency uplift',
            'status' => 'approved',
            'stream' => 'operations',
        ]);

        $initiative->tasks()->create([
            'title' => 'Create SOP placeholder',
            'task_type' => 'sop_placeholder',
            'status' => 'pending',
        ]);

        $site = Site::create(['name' => 'House A', 'type' => 'house']);
        $scope = InitiativeSiteScope::create([
            'initiative_id' => $initiative->id,
            'scope_type' => 'house_by_house',
            'rollout_mode' => 'multi_wave',
            'wave_count' => 2,
        ]);
        InitiativeSiteScopeSite::create([
            'initiative_site_scope_id' => $scope->id,
            'site_id' => $site->id,
            'wave_no' => 1,
            'status' => 'in_progress',
            'readiness_status' => 'ready',
        ]);

        $plan = $this->plannerService->generateDraft(now()->year, now()->quarter, 'board_ceo', $admin->id);
        $plan = $this->plannerService->submitForManagerReview($plan, $admin->id);
        $plan = $this->plannerService->submitForExecutiveReview($plan, $admin->id);
        $plan = $this->plannerService->approve($plan, $admin->id);
        $plan = $this->plannerService->publish($plan, $admin->id);

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
            $snapshot = $this->reportService->generate($type, $plan, $admin->id);

            $this->assertSame($type, $snapshot->report_type);
            $this->assertSame(true, $snapshot->immutable);
            $this->assertNotEmpty($snapshot->checksum);
            $this->assertIsArray($snapshot->payload);
            $this->assertSame($snapshot->payload['type'] ?? null, $type === 'best_all_round' ? 'best_all_round' : $type);
        }
    }

    public function test_scoring_transparency_report_is_limited_to_plan_items_when_plan_provided(): void
    {
        $admin = $this->createAdminUser();

        $included = $this->createInitiative($admin, ['status' => 'approved', 'title' => 'Included']);
        $excluded = $this->createInitiative($admin, ['status' => 'approved', 'title' => 'Excluded']);

        $plan = $this->plannerService->generateDraft(now()->year, now()->quarter, 'board_ceo', $admin->id);

        // Keep only one initiative in the plan to verify filtering.
        $plan->items()->where('initiative_id', $excluded->id)->delete();

        $snapshot = $this->reportService->generate('scoring_transparency', $plan->fresh(), $admin->id);

        $rows = $snapshot->payload['rows'] ?? [];
        $this->assertCount(1, $rows);
        $this->assertSame($included->code, $rows[0]['initiative_code']);
    }
}
