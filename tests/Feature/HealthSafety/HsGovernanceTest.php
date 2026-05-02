<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Services\HealthSafety\HsComplianceExportService;
use App\Services\HealthSafety\HsGovernanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HsGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private HsGovernanceService $governanceService;

    private HsComplianceExportService $complianceService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->governanceService = app(HsGovernanceService::class);
        $this->complianceService = app(HsComplianceExportService::class);
    }

    // ──────────────────────────────────────────────────────
    // HsGovernanceService — Board Summary
    // ──────────────────────────────────────────────────────

    public function test_board_summary_returns_all_sections(): void
    {
        $summary = $this->governanceService->getBoardSummary();

        $this->assertArrayHasKey('period', $summary);
        $this->assertArrayHasKey('event_summary', $summary);
        $this->assertArrayHasKey('investigation_summary', $summary);
        $this->assertArrayHasKey('corrective_action_summary', $summary);
        $this->assertArrayHasKey('risk_posture', $summary);
        $this->assertArrayHasKey('worksafe_status', $summary);
        $this->assertArrayHasKey('training_compliance', $summary);
        $this->assertArrayHasKey('overall_status', $summary);
    }

    public function test_board_summary_with_data(): void
    {
        HsEvent::factory()->create(['severity' => 'high', 'status' => 'open']);
        HsEvent::factory()->create(['severity' => 'critical', 'status' => 'open', 'worksafe_notifiable' => true, 'worksafe_status' => 'pending']);
        HsEvent::factory()->closed()->create();

        $summary = $this->governanceService->getBoardSummary();

        $this->assertEquals(2, $summary['event_summary']['open_total']);
        $this->assertEquals(2, $summary['event_summary']['open_high_critical']);
        $this->assertEquals(1, $summary['worksafe_status']['pending_notification']);
    }

    public function test_overall_status_critical_when_worksafe_pending(): void
    {
        HsEvent::factory()->create([
            'worksafe_notifiable' => true,
            'worksafe_status' => 'pending',
            'status' => 'open',
        ]);

        $summary = $this->governanceService->getBoardSummary();

        $this->assertEquals('critical', $summary['overall_status']);
    }

    public function test_overall_status_good_when_clean(): void
    {
        $summary = $this->governanceService->getBoardSummary();

        $this->assertEquals('good', $summary['overall_status']);
    }

    public function test_effectiveness_rate_calculation(): void
    {
        HsCorrectiveAction::factory()->verified()->create(['effectiveness_confirmed' => true]);
        HsCorrectiveAction::factory()->verified()->create(['effectiveness_confirmed' => true]);
        HsCorrectiveAction::factory()->verified()->create(['effectiveness_confirmed' => false]);

        $summary = $this->governanceService->getCorrectiveActionSummary();

        $this->assertEquals(67, $summary['effectiveness_rate']); // 2/3 = 67%
    }

    // ──────────────────────────────────────────────────────
    // HsGovernanceService — Widget Data
    // ──────────────────────────────────────────────────────

    public function test_widget_data_returns_compact_format(): void
    {
        HsEvent::factory()->create(['severity' => 'high', 'status' => 'open']);
        HsInvestigation::factory()->inProgress()->create();
        HsCorrectiveAction::factory()->overdue()->create();

        $widget = $this->governanceService->getWidgetData([
            'start' => now()->subMonth()->toDateString(),
            'end' => now()->toDateString(),
        ]);

        $this->assertArrayHasKey('open_events', $widget);
        $this->assertArrayHasKey('active_investigations', $widget);
        $this->assertArrayHasKey('overdue_corrective_actions', $widget);
        $this->assertArrayHasKey('status', $widget);
        $this->assertEquals(3, $widget['open_events']);
    }

    // ──────────────────────────────────────────────────────
    // HsComplianceExportService — WorkSafe Register
    // ──────────────────────────────────────────────────────

    public function test_worksafe_register_structure(): void
    {
        HsEvent::factory()->create([
            'worksafe_notifiable' => true,
            'worksafe_status' => 'pending',
        ]);
        HsEvent::factory()->create([
            'worksafe_notifiable' => true,
            'worksafe_status' => 'notified',
        ]);
        HsEvent::factory()->create([
            'worksafe_notifiable' => false,
        ]);

        $register = $this->complianceService->worksafeRegister();

        $this->assertEquals('WorkSafe Notifiable Events Register', $register['title']);
        $this->assertEquals(2, $register['summary']['total']);
        $this->assertEquals(1, $register['summary']['pending_notification']);
        $this->assertEquals(1, $register['summary']['notified']);
        $this->assertCount(2, $register['items']);
    }

    // ──────────────────────────────────────────────────────
    // HsComplianceExportService — Investigation Outcomes
    // ──────────────────────────────────────────────────────

    public function test_investigation_outcomes_only_completed(): void
    {
        HsInvestigation::factory()->completed()->create();
        HsInvestigation::factory()->inProgress()->create();

        $outcomes = $this->complianceService->investigationOutcomes();

        $this->assertEquals(1, $outcomes['summary']['total_completed']);
        $this->assertCount(1, $outcomes['items']);
    }

    // ──────────────────────────────────────────────────────
    // HsComplianceExportService — Corrective Action Traceability
    // ──────────────────────────────────────────────────────

    public function test_corrective_action_traceability_structure(): void
    {
        HsCorrectiveAction::factory()->inProgress()->create();
        HsCorrectiveAction::factory()->verified()->create();

        $report = $this->complianceService->correctiveActionTraceability();

        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('items', $report);
        $this->assertEquals(2, $report['summary']['total']);
        $this->assertCount(2, $report['items']);
    }

    public function test_corrective_action_traceability_with_status_filter(): void
    {
        HsCorrectiveAction::factory()->inProgress()->create();
        HsCorrectiveAction::factory()->verified()->create();

        $report = $this->complianceService->correctiveActionTraceability('in_progress');

        $this->assertCount(1, $report['items']);
    }

    // ──────────────────────────────────────────────────────
    // HsComplianceExportService — Risk Assessment Register
    // ──────────────────────────────────────────────────────

    public function test_risk_assessment_register_structure(): void
    {
        HsRiskAssessment::factory()->active()->highRisk()->create();
        HsRiskAssessment::factory()->active()->create([
            'likelihood' => 1, 'consequence' => 1, 'risk_score' => 1, 'risk_level' => 'low',
        ]);
        HsRiskAssessment::factory()->create(); // draft — not included

        $register = $this->complianceService->riskAssessmentRegister();

        $this->assertEquals(2, $register['summary']['total_active']);
        $this->assertEquals(1, $register['summary']['high']);
        $this->assertCount(2, $register['items']);
    }
}
