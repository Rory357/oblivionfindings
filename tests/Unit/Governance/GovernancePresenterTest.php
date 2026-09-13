<?php

namespace Tests\Unit\Governance;

use App\Domain\Governance\Support\GovernancePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernancePresenterTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_dashboard_normalizes_unavailable_metrics_and_role_actions(): void
    {
        $user = $this->createUserWithRole('board_member');
        $this->createBoardMember($user);

        $dashboard = app(GovernancePresenter::class)->dashboard(
            widgets: [
                'decisions_required' => ['count' => 1, 'overdue' => 0, 'items' => [['reference' => 'RES-100', 'title' => 'Approve annual plan']]],
                'roadmap' => [
                    'initiatives' => ['total' => 4, 'in_progress' => 2, 'blocked' => 1, 'top' => [['code' => 'RM-1', 'title' => 'Care platform uplift']]],
                    'decisions_required' => 1,
                ],
                'workforce' => ['overtime_percentage' => 8.5, 'unfilled_shifts' => 2, 'training_compliance' => null, 'status' => 'warning'],
                'financial' => ['budget_utilization' => 42.1, 'variance' => 2.3, 'budget_total' => 100000, 'actual_total' => 42100, 'status' => 'good'],
            ],
            period: [
                'type' => 'month',
                'start' => now()->startOfMonth()->toDateString(),
                'end' => now()->toDateString(),
            ],
            freshness: [],
            workflow: ['summary' => ['total' => 1, 'critical' => 0, 'overdue' => 0], 'actions' => []],
            user: $user,
        );

        $workforceCard = collect($dashboard['cards'])->firstWhere('key', 'workforce');
        $trainingMetric = collect($workforceCard['metrics'])->firstWhere('label', 'Training compliance');

        $this->assertNotNull($workforceCard);
        $this->assertSame('Unavailable', $trainingMetric['value']);
        $this->assertSame('muted', $trainingMetric['tone']);
        $this->assertSame('board_focus', $dashboard['sections'][0]['key']);
        $this->assertTrue(collect($dashboard['role_actions'])->contains(fn (array $action) => $action['href'] === '/governance/interests/mine'));
        $this->assertTrue(collect($dashboard['role_actions'])->contains(fn (array $action) => $action['href'] === '/governance/evaluations'));
    }

    public function test_kpi_band_counts_true_open_actions_not_sample_cap(): void
    {
        $data = \Tests\Support\GovernanceSyntheticFixtures::seed();
        $admin = \App\Models\User::findOrFail($data['users']['chair']);

        $workflow = app(\App\Domain\Governance\Services\GovernanceWorkflowService::class)->dashboardWorkflow($admin, limit: 15);
        $dashboard = app(GovernancePresenter::class)->dashboard(
            widgets: [],
            period: ['type' => 'month', 'start' => now()->startOfMonth()->toDateString(), 'end' => now()->toDateString()],
            freshness: [],
            workflow: $workflow,
            user: $admin,
        );

        $kpiBand = collect($dashboard['kpi_band']);
        $openActionsTile = $kpiBand->firstWhere('key', 'open_actions');

        $this->assertNotNull($openActionsTile);
        // 40 actions exist in synthetic fixtures; 8 are complete, 32 open/in_progress/blocked
        $this->assertGreaterThanOrEqual(30, (int) $openActionsTile['value']);
    }

    public function test_denied_private_earlier_meeting_never_displaces_allowed_next_meeting_or_pack(): void
    {
        $data = \Tests\Support\GovernanceSyntheticFixtures::seed();
        $memberUser = \App\Models\User::findOrFail($data['users']['member']);
        $regularMeeting = \App\Domain\Governance\Models\GovernanceMeeting::findOrFail($data['meetings']['regular']);
        $privateMeeting = \App\Domain\Governance\Models\GovernanceMeeting::findOrFail($data['meetings']['private']);

        // Private meeting is earlier (addDays(3)) than regular meeting (addDays(7))
        $this->assertTrue($privateMeeting->scheduled_at->isBefore($regularMeeting->scheduled_at));

        $dashboard = app(GovernancePresenter::class)->dashboard(
            widgets: [],
            period: ['type' => 'month', 'start' => now()->startOfMonth()->toDateString(), 'end' => now()->toDateString()],
            freshness: [],
            workflow: ['summary' => ['total' => 0, 'critical' => 0, 'overdue' => 0], 'actions' => []],
            user: $memberUser,
        );

        // Next meeting for ordinary member MUST be the regular meeting
        $this->assertNotNull($dashboard['next_meeting']);
        $this->assertSame($regularMeeting->id, $dashboard['next_meeting']['meeting']['id']);

        // Board pack must be for the regular meeting
        $this->assertNotNull($dashboard['board_pack']);
        $this->assertSame($regularMeeting->id, $dashboard['board_pack']['meeting_id']);
    }

    public function test_dashboard_aggregator_counts_12_risks_as_12_before_limit(): void
    {
        \Tests\Support\GovernanceSyntheticFixtures::seed();

        $aggregator = app(\App\Domain\Governance\Services\DashboardAggregatorService::class);
        $topRisks = $aggregator->getTopRisks(10);

        // All 12 active above-appetite risks in fixture must be counted before the 10-item limit
        $this->assertSame(12, $topRisks['count']);
        $this->assertSame(12, $topRisks['above_appetite']);
        $this->assertCount(10, $topRisks['items']);
    }

    public function test_build_timeline_always_returns_zero_indexed_array(): void
    {
        $admin = $this->createAdminUser();

        $dashboard = app(GovernancePresenter::class)->dashboard(
            widgets: [],
            period: ['type' => 'month', 'start' => now()->startOfMonth()->toDateString(), 'end' => now()->toDateString()],
            freshness: [],
            workflow: ['summary' => ['total' => 0, 'critical' => 0, 'overdue' => 0], 'actions' => []],
            user: $admin,
        );

        $this->assertArrayHasKey('timeline', $dashboard);
        $this->assertArrayHasKey('events', $dashboard['timeline']);
        $this->assertTrue(array_is_list($dashboard['timeline']['events']), 'Timeline events must be a zero-indexed list.');
    }

    public function test_risk_changes_does_not_falsely_escalate_when_residual_score_below_inherent(): void
    {
        $admin = $this->createAdminUser();
        // Create a risk where residual score (6) is less than inherent score (12)
        // Historically a broken query `residual_score > inherent_score` existed
        $this->createRisk($admin, [
            'inherent_score' => 12,
            'residual_score' => 6,
            'title' => 'Stable mitigated risk',
        ]);

        $aggregator = app(\App\Domain\Governance\Services\DashboardAggregatorService::class);
        $changes = $aggregator->getRiskChanges([
            'start' => now()->subDays(30)->toDateTimeString(),
            'end' => now()->toDateTimeString(),
        ]);

        // There should be zero escalated risks
        $this->assertSame(0, $changes['escalated']);
    }
}

