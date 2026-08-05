<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Governance\Services\DashboardAggregatorService;
use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrStaffComplianceStatus;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use App\Models\HsTrainingRequirement;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsComplianceExportService;
use App\Services\HealthSafety\HsGovernanceService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HsGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private HsGovernanceService $governanceService;

    private HsComplianceExportService $complianceService;

    private User $applicationViewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->applicationViewer = User::factory()->create(['approved_at' => now()]);
        $allSitesPermission = Permission::query()->where('key', 'healthSafety.viewAllSites')->firstOrFail();
        $this->applicationViewer->permissionOverrides()->attach($allSitesPermission, ['allowed' => true]);
        $this->applicationViewer->refresh();
        $this->governanceService = app(HsGovernanceService::class);
        $this->complianceService = app(HsComplianceExportService::class);
    }

    // ──────────────────────────────────────────────────────
    // HsGovernanceService — Board Summary
    // ──────────────────────────────────────────────────────

    public function test_board_summary_returns_all_sections(): void
    {
        $summary = $this->governanceService->getBoardSummary(viewer: $this->applicationViewer);

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

        $summary = $this->governanceService->getBoardSummary(viewer: $this->applicationViewer);

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

        $summary = $this->governanceService->getBoardSummary(viewer: $this->applicationViewer);

        $this->assertEquals('critical', $summary['overall_status']);
    }

    public function test_overall_status_good_when_clean(): void
    {
        $summary = $this->governanceService->getBoardSummary(viewer: $this->applicationViewer);

        $this->assertEquals('good', $summary['overall_status']);
    }

    public function test_effectiveness_rate_calculation(): void
    {
        HsCorrectiveAction::factory()->verified()->create(['effectiveness_confirmed' => true]);
        HsCorrectiveAction::factory()->verified()->create(['effectiveness_confirmed' => true]);
        HsCorrectiveAction::factory()->verified()->create(['effectiveness_confirmed' => false]);

        $summary = $this->governanceService->getCorrectiveActionSummary($this->applicationViewer);

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
        ], $this->applicationViewer);

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

        $register = $this->complianceService->worksafeRegister(viewer: $this->applicationViewer);

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

        $outcomes = $this->complianceService->investigationOutcomes(viewer: $this->applicationViewer);

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

        $report = $this->complianceService->correctiveActionTraceability(viewer: $this->applicationViewer);

        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('items', $report);
        $this->assertEquals(2, $report['summary']['total']);
        $this->assertCount(2, $report['items']);
    }

    public function test_corrective_action_traceability_with_status_filter(): void
    {
        HsCorrectiveAction::factory()->inProgress()->create();
        HsCorrectiveAction::factory()->verified()->create();

        $report = $this->complianceService->correctiveActionTraceability(
            statusFilter: 'in_progress',
            viewer: $this->applicationViewer,
        );

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

        $register = $this->complianceService->riskAssessmentRegister($this->applicationViewer);

        $this->assertEquals(2, $register['summary']['total_active']);
        $this->assertEquals(1, $register['summary']['high']);
        $this->assertCount(2, $register['items']);
    }

    public function test_board_summary_widget_and_training_counts_are_scoped_to_the_viewers_sites(): void
    {
        $localSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->siteBoundViewer($localSite);
        $localWorker = $this->siteBoundViewer($localSite);
        $otherWorker = $this->siteBoundViewer($otherSite);

        $localEvent = HsEvent::factory()->high()->worksafeNotifiable()->create([
            'site_id' => $localSite->id,
        ]);
        $otherEvent = HsEvent::factory()->critical()->worksafeNotifiable()->create([
            'site_id' => $otherSite->id,
        ]);
        HsInvestigation::factory()->inProgress()->create(['hs_event_id' => $localEvent->id]);
        HsInvestigation::factory()->inProgress()->create(['hs_event_id' => $otherEvent->id]);
        HsCorrectiveAction::factory()->overdue()->create(['hs_event_id' => $localEvent->id]);
        HsCorrectiveAction::factory()->overdue()->create(['hs_event_id' => $otherEvent->id]);

        $sharedHrRequirement = HrComplianceRequirement::factory()->create();
        HsTrainingRequirement::factory()->create([
            'hr_compliance_requirement_id' => $sharedHrRequirement->id,
        ]);
        HrStaffComplianceStatus::factory()->expired()->create([
            'user_id' => $localWorker->id,
            'requirement_id' => $sharedHrRequirement->id,
        ]);
        HrStaffComplianceStatus::factory()->expired()->create([
            'user_id' => $otherWorker->id,
            'requirement_id' => $sharedHrRequirement->id,
        ]);
        HsTrainingRequirement::factory()->forSite($otherSite->id)->create([
            'hr_compliance_requirement_id' => HrComplianceRequirement::factory(),
        ]);

        $summary = $this->governanceService->getBoardSummary(null, null, $viewer);
        $widget = $this->governanceService->getWidgetData([
            'start' => now()->subMonth()->toDateString(),
            'end' => now()->toDateString(),
        ], $viewer);

        $this->assertSame(1, $summary['event_summary']['open_total']);
        $this->assertSame(1, $summary['investigation_summary']['active']);
        $this->assertSame(1, $summary['corrective_action_summary']['overdue']);
        $this->assertSame(1, $summary['worksafe_status']['pending_notification']);
        $this->assertSame(1, $summary['training_compliance']['total_requirements']);
        $this->assertSame(1, $summary['training_compliance']['non_compliant_staff']);
        $this->assertSame(1, $widget['open_events']);
        $this->assertSame(1, $widget['active_investigations']);
        $this->assertSame(1, $widget['overdue_corrective_actions']);
    }

    public function test_compliance_exports_exclude_other_site_items_and_summary_counts(): void
    {
        $localSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->siteBoundViewer($localSite);
        $localEvent = HsEvent::factory()->worksafeNotifiable()->create(['site_id' => $localSite->id]);
        $otherEvent = HsEvent::factory()->worksafeNotifiable()->create(['site_id' => $otherSite->id]);
        $localInvestigation = HsInvestigation::factory()->completed()->create(['hs_event_id' => $localEvent->id]);
        HsInvestigation::factory()->completed()->create(['hs_event_id' => $otherEvent->id]);
        $localAction = HsCorrectiveAction::factory()->verified()->create(['hs_event_id' => $localEvent->id]);
        HsCorrectiveAction::factory()->verified()->create(['hs_event_id' => $otherEvent->id]);

        $worksafe = $this->complianceService->worksafeRegister(null, null, $viewer);
        $investigations = $this->complianceService->investigationOutcomes(null, null, $viewer);
        $actions = $this->complianceService->correctiveActionTraceability(null, null, null, $viewer);

        $this->assertSame(1, $worksafe['summary']['total']);
        $this->assertSame([$localEvent->reference_number], array_column($worksafe['items'], 'reference_number'));
        $this->assertSame(1, $investigations['summary']['total_completed']);
        $this->assertSame([$localInvestigation->reference_number], array_column($investigations['items'], 'reference_number'));
        $this->assertSame(1, $actions['summary']['total']);
        $this->assertSame([$localAction->reference_number], array_column($actions['items'], 'reference_number'));
    }

    public function test_report_endpoints_deny_an_inaccessible_requested_site(): void
    {
        $localSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->siteBoundViewer($localSite, ['governance.view']);

        foreach ([
            'board-summary',
            'worksafe-register',
            'investigation-outcomes',
            'corrective-action-traceability',
            'risk-assessment-register',
        ] as $report) {
            $this->actingAs($viewer)
                ->getJson("/health-safety/reports/{$report}?site_id={$otherSite->id}")
                ->assertForbidden();
        }
    }

    public function test_explicit_all_sites_permission_is_the_only_user_facing_global_bypass(): void
    {
        $localSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->siteBoundViewer($localSite, [
            'governance.view',
            'healthSafety.viewAllSites',
        ]);
        HsEvent::factory()->worksafeNotifiable()->create(['site_id' => $localSite->id]);
        $otherEvent = HsEvent::factory()->worksafeNotifiable()->create(['site_id' => $otherSite->id]);

        $allSites = $this->complianceService->worksafeRegister(null, null, $viewer);
        $requestedSite = $this->complianceService->worksafeRegister(null, null, $viewer, $otherSite->id);

        $this->assertSame(2, $allSites['summary']['total']);
        $this->assertSame([$otherEvent->reference_number], array_column($requestedSite['items'], 'reference_number'));
    }

    public function test_report_services_fail_closed_without_an_authorized_viewer(): void
    {
        HsEvent::factory()->worksafeNotifiable()->create();

        $summary = $this->governanceService->getBoardSummary();
        $register = $this->complianceService->worksafeRegister();

        $this->assertSame(0, $summary['event_summary']['open_total']);
        $this->assertSame(0, $summary['worksafe_status']['pending_notification']);
        $this->assertSame(0, $register['summary']['total']);
        $this->assertSame([], $register['items']);
    }

    public function test_snapshot_capture_fails_closed_without_an_authorized_viewer(): void
    {
        HsEvent::factory()->worksafeNotifiable()->create([
            'severity' => HsEvent::SEVERITY_CRITICAL,
            'status' => HsEvent::STATUS_OPEN,
            'worksafe_status' => HsEvent::WORKSAFE_PENDING,
        ]);

        try {
            app(DashboardAggregatorService::class)->captureSnapshot('month');
            $this->fail('A snapshot without an authorized viewer must not be persisted.');
        } catch (\LogicException $exception) {
            $this->assertSame(
                'Dashboard snapshots require an approved authorized viewer.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('dashboard_snapshots', 0);
    }

    public function test_scheduled_snapshot_uses_the_explicit_site_scoped_viewer(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->siteBoundViewer($site);
        HsEvent::factory()->worksafeNotifiable()->create([
            'site_id' => $site->id,
            'severity' => HsEvent::SEVERITY_CRITICAL,
            'status' => HsEvent::STATUS_OPEN,
            'worksafe_status' => HsEvent::WORKSAFE_PENDING,
        ]);
        HsEvent::factory()->worksafeNotifiable()->create([
            'site_id' => $otherSite->id,
            'severity' => HsEvent::SEVERITY_CRITICAL,
            'status' => HsEvent::STATUS_OPEN,
            'worksafe_status' => HsEvent::WORKSAFE_PENDING,
        ]);

        $snapshot = app(DashboardAggregatorService::class)->captureSnapshot(
            'month',
            viewer: $viewer,
        );
        $metrics = $snapshot->getWidgetData('hs_backbone');

        $this->assertSame($viewer->id, $snapshot->captured_by);
        $this->assertSame(1, $metrics['open_events']);
        $this->assertSame(1, $metrics['open_high_critical']);
        $this->assertSame(1, $metrics['worksafe_pending']);
        $this->assertTrue($snapshot->verifyIntegrity());
    }

    /** @param list<string> $permissionKeys */
    private function siteBoundViewer(Site $site, array $permissionKeys = []): User
    {
        $viewer = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        if ($permissionKeys !== []) {
            $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
            $viewer->permissionOverrides()->sync($permissionIds->mapWithKeys(
                fn ($permissionId) => [$permissionId => ['allowed' => true]],
            ));
        }

        return $viewer->refresh();
    }
}
