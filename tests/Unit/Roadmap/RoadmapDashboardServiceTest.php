<?php

namespace Tests\Unit\Roadmap;

use App\Domain\Roadmap\Models\AssuranceEvidencePlan;
use App\Domain\Roadmap\Models\DecisionRequest;
use App\Domain\Roadmap\Models\InitiativeSiteScope;
use App\Domain\Roadmap\Models\InitiativeSiteScopeSite;
use App\Domain\Roadmap\Models\QuarterlyRoadmapPlan;
use App\Domain\Roadmap\Models\QuarterlyRoadmapPlanItem;
use App\Domain\Roadmap\Services\RoadmapDashboardService;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RoadmapTestHelpers;
use Tests\TestCase;

class RoadmapDashboardServiceTest extends TestCase
{
    use RefreshDatabase;
    use RoadmapTestHelpers;

    public function test_governance_widget_returns_board_summary_metrics(): void
    {
        $this->seedRoadmapModule();

        $admin = $this->createAdminUser();

        $initiativeA = $this->createInitiative($admin, [
            'title' => 'House rollout A',
            'status' => 'in_progress',
            'priority_score' => 81,
            'cost_estimate_high' => 12000,
        ]);
        $initiativeB = $this->createInitiative($admin, [
            'title' => 'House rollout B',
            'status' => 'deferred',
            'priority_score' => 55,
            'cost_estimate_high' => 6000,
        ]);

        AssuranceEvidencePlan::create([
            'initiative_id' => $initiativeA->id,
            'control_name' => 'Control A',
            'evidence_type' => 'report',
            'verify_due_date' => now()->subDay()->toDateString(),
        ]);

        AssuranceEvidencePlan::create([
            'initiative_id' => $initiativeB->id,
            'control_name' => 'Control B',
            'evidence_type' => 'report',
            'verify_due_date' => now()->toDateString(),
            'verified_at' => now(),
            'verification_result' => 'effective',
        ]);

        DecisionRequest::create([
            'source_type' => get_class($initiativeA),
            'source_id' => $initiativeA->id,
            'request_type' => 'initiative_approval',
            'status' => 'pending',
            'amount' => 12000,
            'required_role' => 'board_chair',
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $site = Site::create(['name' => 'House 101', 'type' => 'house']);
        $site2 = Site::create(['name' => 'House 102', 'type' => 'house']);
        $scope = InitiativeSiteScope::create([
            'initiative_id' => $initiativeA->id,
            'scope_type' => 'house_by_house',
            'rollout_mode' => 'multi_wave',
            'wave_count' => 1,
        ]);

        InitiativeSiteScopeSite::create([
            'initiative_site_scope_id' => $scope->id,
            'site_id' => $site->id,
            'status' => 'not_started',
            'readiness_status' => 'pending',
        ]);

        InitiativeSiteScopeSite::create([
            'initiative_site_scope_id' => $scope->id,
            'site_id' => $site2->id,
            'status' => 'completed',
            'readiness_status' => 'ready',
        ]);

        $plan = QuarterlyRoadmapPlan::create([
            'fiscal_year' => now()->year,
            'quarter' => now()->quarter,
            'status' => QuarterlyRoadmapPlan::STATUS_PUBLISHED,
            'preset_profile' => 'board_ceo',
            'revision_no' => 1,
            'published_at' => now(),
            'published_by' => $admin->id,
        ]);

        QuarterlyRoadmapPlanItem::create([
            'quarterly_plan_id' => $plan->id,
            'initiative_id' => $initiativeA->id,
            'rank' => 1,
            'score_at_snapshot' => 81,
        ]);
        QuarterlyRoadmapPlanItem::create([
            'quarterly_plan_id' => $plan->id,
            'initiative_id' => $initiativeB->id,
            'rank' => 2,
            'score_at_snapshot' => 55,
        ]);

        $service = app(RoadmapDashboardService::class);
        $summary = $service->governanceWidget();

        $this->assertNotNull($summary['published_plan']);
        $this->assertSame(2, $summary['initiatives']['total']);
        $this->assertSame(1, $summary['assurance']['overdue']);
        $this->assertSame(1, $summary['assurance']['verified']);
        $this->assertSame(1, $summary['decisions_required']);
        $this->assertSame(1, $summary['house_rollout']['not_started']);
        $this->assertSame(1, $summary['house_rollout']['completed']);

        $decisions = $service->decisionsRequired();
        $this->assertSame(1, $decisions['count']);
        $this->assertSame(1, $decisions['overdue']);
    }

    public function test_governance_widget_falls_back_when_roadmap_schema_is_missing(): void
    {
        $this->seedRoadmapModule();

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('roadmap_quarterly_plans');
        Schema::enableForeignKeyConstraints();

        $service = app(RoadmapDashboardService::class);
        $summary = $service->governanceWidget();

        $this->assertSame('unavailable', $summary['status']);
        $this->assertSame('roadmap module not migrated', $summary['reason']);
        $this->assertSame(0, $summary['initiatives']['total']);
        $this->assertSame(0, $summary['decisions_required']);
    }

    public function test_decisions_required_falls_back_when_decision_table_is_missing(): void
    {
        $this->seedRoadmapModule();

        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('roadmap_decision_requests');
        Schema::enableForeignKeyConstraints();

        $service = app(RoadmapDashboardService::class);
        $decisions = $service->decisionsRequired();

        $this->assertSame(0, $decisions['count']);
        $this->assertSame(0, $decisions['overdue']);
        $this->assertSame([], $decisions['items']);
    }
}
