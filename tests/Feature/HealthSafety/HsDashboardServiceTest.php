<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\HsTrainingRequirement;
use App\Services\HealthSafety\HsDashboardService;
use App\Services\HealthSafety\HsModuleSummaryService;
use App\Models\Site;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HsDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private HsDashboardService $dashboardService;
    private HsModuleSummaryService $moduleSummaryService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dashboardService = app(HsDashboardService::class);
        $this->moduleSummaryService = app(HsModuleSummaryService::class);
    }

    // ──────────────────────────────────────────────────────
    // Dashboard Service — Event KPIs
    // ──────────────────────────────────────────────────────

    public function test_event_kpis_return_correct_counts(): void
    {
        HsEvent::factory()->create(['severity' => 'high', 'status' => 'open']);
        HsEvent::factory()->create(['severity' => 'critical', 'status' => 'investigating']);
        HsEvent::factory()->create(['severity' => 'low', 'status' => 'open']);
        HsEvent::factory()->closed()->create();

        $kpis = $this->dashboardService->getEventKpis();

        $this->assertEquals(3, $kpis['open_events']); // 3 non-closed
        $this->assertEquals(2, $kpis['open_events_high_critical']); // high + critical
    }

    public function test_event_kpis_with_empty_database(): void
    {
        $kpis = $this->dashboardService->getEventKpis();

        $this->assertEquals(0, $kpis['open_events']);
        $this->assertEquals(0, $kpis['open_events_high_critical']);
        $this->assertEmpty($kpis['events_by_category']);
    }

    // ──────────────────────────────────────────────────────
    // Dashboard Service — Investigation KPIs
    // ──────────────────────────────────────────────────────

    public function test_investigation_kpis(): void
    {
        HsInvestigation::factory()->inProgress()->create();
        HsInvestigation::factory()->inProgress()->create([
            'target_completion_date' => now()->subDays(5),
        ]);
        HsInvestigation::factory()->completed()->create();

        $kpis = $this->dashboardService->getInvestigationKpis();

        $this->assertEquals(2, $kpis['active_investigations']);
        $this->assertEquals(1, $kpis['overdue_investigations']);
    }

    // ──────────────────────────────────────────────────────
    // Dashboard Service — Corrective Action KPIs
    // ──────────────────────────────────────────────────────

    public function test_corrective_action_kpis(): void
    {
        HsCorrectiveAction::factory()->inProgress()->create();
        HsCorrectiveAction::factory()->overdue()->create();
        HsCorrectiveAction::factory()->completed()->create(); // awaiting verification
        HsCorrectiveAction::factory()->closed()->create();

        $kpis = $this->dashboardService->getCorrectiveActionKpis();

        $this->assertEquals(3, $kpis['open_actions']); // in_progress + overdue + completed
        $this->assertEquals(1, $kpis['overdue_actions']);
        $this->assertEquals(1, $kpis['awaiting_verification']);
    }

    // ──────────────────────────────────────────────────────
    // Dashboard Service — Risk Assessment KPIs
    // ──────────────────────────────────────────────────────

    public function test_risk_assessment_kpis(): void
    {
        HsRiskAssessment::factory()->active()->highRisk()->create();
        HsRiskAssessment::factory()->active()->create([
            'likelihood' => 1, 'consequence' => 1, 'risk_score' => 1, 'risk_level' => 'low',
        ]);
        // Pin the review-due assessment to a low level — dueForReview() inherits the base
        // factory's RANDOM risk_score, which intermittently lands high/extreme and skews
        // the high_extreme_active assertion below (pre-existing flake).
        HsRiskAssessment::factory()->dueForReview()->create([
            'likelihood' => 1, 'consequence' => 1, 'risk_score' => 1, 'risk_level' => 'low',
        ]);

        $kpis = $this->dashboardService->getRiskAssessmentKpis();

        $this->assertEquals(3, $kpis['active_assessments']);
        $this->assertEquals(1, $kpis['high_extreme_active']);
        $this->assertEquals(1, $kpis['due_for_review']);
    }

    // ──────────────────────────────────────────────────────
    // Dashboard Service — Combined summary
    // ──────────────────────────────────────────────────────

    public function test_dashboard_summary_returns_all_sections(): void
    {
        $summary = $this->dashboardService->getDashboardSummary();

        $this->assertArrayHasKey('events', $summary);
        $this->assertArrayHasKey('investigations', $summary);
        $this->assertArrayHasKey('corrective_actions', $summary);
        $this->assertArrayHasKey('risk_assessments', $summary);
        $this->assertArrayHasKey('training', $summary);
    }

    // ──────────────────────────────────────────────────────
    // Module Summary Service — Site context
    // ──────────────────────────────────────────────────────

    public function test_site_summary_returns_correct_data(): void
    {
        $site = Site::factory()->create();

        HsEvent::factory()->create(['site_id' => $site->id, 'severity' => 'high', 'status' => 'open']);
        HsEvent::factory()->create(['site_id' => $site->id, 'severity' => 'low', 'status' => 'open']);
        HsEvent::factory()->closed()->create(['site_id' => $site->id]);

        $summary = $this->moduleSummaryService->forSite($site->id);

        $this->assertEquals(2, $summary['open_events']);
        $this->assertEquals(1, $summary['open_events_high_critical']);
        $this->assertCount(3, $summary['recent_events']); // All 3 returned as recent
    }

    public function test_site_summary_empty_when_no_data(): void
    {
        $site = Site::factory()->create();

        $summary = $this->moduleSummaryService->forSite($site->id);

        $this->assertEquals(0, $summary['open_events']);
        $this->assertEquals(0, $summary['active_risk_assessments']);
        $this->assertEmpty($summary['recent_events']);
    }

    // ──────────────────────────────────────────────────────
    // Module Summary Service — Client context
    // ──────────────────────────────────────────────────────

    public function test_client_summary_returns_correct_data(): void
    {
        $client = Client::factory()->create();

        HsEvent::factory()->create(['client_id' => $client->id, 'status' => 'open']);
        HsEvent::factory()->closed()->create(['client_id' => $client->id]);

        $summary = $this->moduleSummaryService->forClient($client->id);

        $this->assertEquals(1, $summary['open_events']);
        $this->assertEquals(2, $summary['total_events']);
    }

    // ──────────────────────────────────────────────────────
    // Module Summary Service — Staff context
    // ──────────────────────────────────────────────────────

    public function test_staff_summary_returns_correct_data(): void
    {
        $user = User::factory()->create();

        HsEvent::factory()->create(['staff_id' => $user->id, 'status' => 'open']);
        HsCorrectiveAction::factory()->inProgress()->create(['assigned_to_user_id' => $user->id]);
        HsInvestigation::factory()->inProgress()->create(['lead_investigator_id' => $user->id]);

        $summary = $this->moduleSummaryService->forStaff($user->id);

        $this->assertEquals(1, $summary['involved_events']);
        $this->assertEquals(1, $summary['assigned_corrective_actions']);
        $this->assertEquals(1, $summary['active_investigations']);
    }

    // ──────────────────────────────────────────────────────
    // Training compliance KPIs
    // ──────────────────────────────────────────────────────

    public function test_training_kpis_with_no_requirements(): void
    {
        $kpis = $this->dashboardService->getTrainingComplianceKpis();

        $this->assertEquals(0, $kpis['total_requirements']);
        $this->assertEquals(0, $kpis['staff_non_compliant']);
    }

    public function test_training_kpis_with_active_requirements(): void
    {
        HsTrainingRequirement::factory()->blocking()->create();
        HsTrainingRequirement::factory()->warning()->create();
        HsTrainingRequirement::factory()->inactive()->create();

        $kpis = $this->dashboardService->getTrainingComplianceKpis();

        $this->assertEquals(2, $kpis['total_requirements']); // Only active
        $this->assertEquals(1, $kpis['blocking_requirements']);
    }
}
