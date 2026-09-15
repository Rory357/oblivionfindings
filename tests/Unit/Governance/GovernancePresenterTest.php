<?php

namespace Tests\Unit\Governance;

use App\Domain\Governance\Support\GovernancePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    private function emptyPeriod(): array
    {
        return ['type' => 'month', 'start' => now()->startOfMonth()->toDateString(), 'end' => now()->toDateString()];
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
                'control_room' => ['critical_alerts' => 1, 'high_alerts' => 0, 'open_critical' => 0, 'mtta_minutes' => 12.4, 'mttr_minutes' => null],
            ],
            period: $this->emptyPeriod(),
            freshness: [],
            workflow: ['summary' => ['total' => 1, 'critical' => 0, 'overdue' => 0], 'actions' => []],
            user: $user,
        );

        $workforceCard = collect($dashboard['cards'])->firstWhere('key', 'workforce');
        $trainingMetric = collect($workforceCard['metrics'])->firstWhere('label', 'Training compliance');

        $this->assertNotNull($workforceCard);
        $this->assertSame('Not available', $trainingMetric['value']);
        $this->assertSame('muted', $trainingMetric['tone']);
        $this->assertSame('board_focus', $dashboard['sections'][0]['key']);
        $this->assertTrue(collect($dashboard['role_actions'])->contains(fn (array $action) => $action['href'] === '/governance/interests/mine'));
        $this->assertTrue(collect($dashboard['role_actions'])->contains(fn (array $action) => $action['href'] === '/governance/evaluations'));

        // Plain metric names — no abbreviations or codes leading.
        $controlRoom = collect($dashboard['cards_by_key']['control_room']['metrics'])->pluck('value', 'label');
        $this->assertSame('12 minutes', $controlRoom['Average time to respond']);
        $this->assertSame('Not available', $controlRoom['Average time to resolve']);
        $this->assertSame(['Approve annual plan'], $dashboard['cards_by_key']['decisions_required']['highlights']);
        $this->assertSame(['Care platform uplift'], $dashboard['cards_by_key']['roadmap']['highlights']);
        $this->assertStringNotContainsString('MTTA', json_encode($dashboard));
        $this->assertStringNotContainsString('backbone', strtolower(json_encode($dashboard['cards'])));
        $this->assertSame('$100,000', collect($dashboard['cards_by_key']['financial']['metrics'])->firstWhere('label', 'Budget')['value']);
    }

    public function test_follow_through_card_counts_true_open_actions_not_sample_cap(): void
    {
        $data = \Tests\Support\GovernanceSyntheticFixtures::seed();
        $admin = \App\Models\User::findOrFail($data['users']['chair']);

        $workflow = app(\App\Domain\Governance\Services\GovernanceWorkflowService::class)->dashboardWorkflow($admin, limit: 15);
        $dashboard = app(GovernancePresenter::class)->dashboard(
            widgets: [],
            period: $this->emptyPeriod(),
            freshness: [],
            workflow: $workflow,
            user: $admin,
        );

        $openActions = collect($dashboard['cards_by_key']['follow_through']['metrics'])->firstWhere('label', 'Open actions');

        // 40 actions exist in synthetic fixtures; 8 are complete, 32 open/in_progress/blocked
        $this->assertGreaterThanOrEqual(30, (int) $openActions['value']);

        // The unused KPI band, calendar feed and pack panel payloads are gone.
        $this->assertArrayNotHasKey('kpi_band', $dashboard);
        $this->assertArrayNotHasKey('calendar_events', $dashboard);
        $this->assertArrayNotHasKey('board_pack', $dashboard);
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
            period: $this->emptyPeriod(),
            freshness: [],
            workflow: ['summary' => ['total' => 0, 'critical' => 0, 'overdue' => 0], 'actions' => []],
            user: $memberUser,
        );

        // Next meeting for ordinary member MUST be the regular meeting
        $this->assertNotNull($dashboard['next_meeting']);
        $this->assertSame($regularMeeting->id, $dashboard['next_meeting']['meeting']['id']);

        // The pack the member is offered belongs to the regular meeting.
        $pack = $dashboard['next_meeting']['member_readiness']['pack'];
        $this->assertTrue($pack['published']);
        $this->assertSame("/governance/packs/{$data['pack']}", $pack['href']);
        $this->assertStringNotContainsString('Synthetic Confidential Executive Session', json_encode($dashboard));
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
            period: $this->emptyPeriod(),
            freshness: [],
            workflow: ['summary' => ['total' => 0, 'critical' => 0, 'overdue' => 0], 'actions' => []],
            user: $admin,
        );

        $this->assertArrayHasKey('timeline', $dashboard);
        $this->assertArrayHasKey('events', $dashboard['timeline']);
        $this->assertTrue(array_is_list($dashboard['timeline']['events']), 'Timeline events must be a zero-indexed list.');
    }

    /**
     * The timeline speaks plainly ("voted on a resolution") and, like the
     * audit log and the registers it links to, never shows an event about a
     * record the viewer can't open. Viewers without audit access get none.
     */
    public function test_timeline_uses_plain_event_names_and_only_records_the_viewer_can_open(): void
    {
        $chair = $this->createAdminUser();
        $secretary = $this->createUserWithRole('board_secretary');

        $resolution = $this->createResolution($chair, ['title' => 'Approve the complaints process']);
        $inCamera = $this->createMeeting($chair, [
            'meeting_type' => 'executive_session',
            'title' => 'SECRET in-camera session',
            'scheduled_at' => now()->addDays(4),
        ]);

        DB::table('governance_audit_log')->insert([
            [
                'user_id' => $chair->id,
                'action' => 'resolution.voted',
                'resource_type' => \App\Domain\Governance\Models\Resolution::class,
                'resource_id' => $resolution->id,
                'created_at' => now()->subHour(),
                'updated_at' => now()->subHour(),
            ],
            [
                'user_id' => $chair->id,
                'action' => 'viewed',
                'resource_type' => 'GovernanceMeeting',
                'resource_id' => $inCamera->id,
                'created_at' => now()->subMinutes(30),
                'updated_at' => now()->subMinutes(30),
            ],
        ]);

        $presenter = app(GovernancePresenter::class);
        $build = fn ($user) => $presenter->dashboard(
            widgets: [],
            period: $this->emptyPeriod(),
            freshness: [],
            workflow: ['summary' => ['total' => 0, 'critical' => 0, 'overdue' => 0], 'actions' => []],
            user: $user,
        )['timeline'];

        // The secretary can read the audit log but has no in-camera authority.
        $timeline = $build($secretary);
        $this->assertCount(1, $timeline['events']);
        $event = $timeline['events'][0];
        $this->assertSame('voted on a resolution', $event['type']);
        $this->assertSame('Resolution', $event['entity_type']);
        $this->assertSame("/governance/resolutions/{$resolution->id}", $event['href']);
        $this->assertMatchesRegularExpression('/^\d{1,2} [A-Z][a-z]+ \d{4}$/', $event['day']);
        $this->assertMatchesRegularExpression('/^\d{1,2}:\d{2} (am|pm)$/', $event['occurred_label']);
        $this->assertStringNotContainsString('SECRET', json_encode($timeline));

        // The chair sees both events.
        $this->assertCount(2, $build($chair)['events']);

        // A board member without audit log access receives no timeline.
        $member = $this->createUserWithRole('board_member');
        $this->createBoardMember($member);
        $memberTimeline = $build($member);
        $this->assertTrue($memberTimeline['restricted']);
        $this->assertSame([], $memberTimeline['events']);
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
