<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Http\Controllers\DashboardController;
use App\Domain\Governance\Models\ConflictDeclaration;
use App\Domain\Governance\Models\MeetingAgendaItem;
use App\Domain\Governance\Services\DashboardAggregatorService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceDashboardTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_dashboard_requires_authentication(): void
    {
        $response = $this->get('/governance/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_dashboard_renders_for_admin(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/governance/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/Dashboard')
        );
    }

    public function test_dashboard_data_endpoint_returns_snapshot(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/governance/dashboard/data?period=month');

        $response->assertOk();
        $response->assertJsonStructure([
            'snapshot_id',
            'period' => ['type', 'start', 'end'],
            'widgets',
            'workflow' => [
                'summary' => ['total', 'critical', 'overdue'],
                'actions',
            ],
            'freshness',
            'cockpit' => ['period_label', 'sections', 'role_actions'],
            'captured_at',
        ]);

        $this->assertNotEmpty($response->json('cockpit.sections', []));
        $this->assertSame('board_focus', $response->json('cockpit.sections.0.key'));
    }

    public function test_dashboard_data_includes_overdue_resolution_in_workflow(): void
    {
        $admin = $this->createAdminUser();
        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->subDay(),
        ]);

        $response = $this->actingAs($admin)->get('/governance/dashboard/data?period=month');
        $response->assertOk();

        $actions = collect($response->json('workflow.actions', []));
        $matching = $actions->firstWhere('id', "resolution:{$resolution->id}");

        $this->assertNotNull($matching);
        $this->assertSame('overdue', $matching['status']);
    }

    public function test_board_member_dashboard_data_includes_self_service_role_actions(): void
    {
        $user = $this->createUserWithRole('board_member');
        $this->createBoardMember($user);

        $response = $this->actingAs($user)->get('/governance/dashboard/data?period=month');
        $response->assertOk();

        $actions = collect($response->json('cockpit.role_actions', []));

        $this->assertTrue($actions->contains(fn (array $action) => $action['href'] === '/governance/interests/mine'));
        $this->assertTrue($actions->contains(fn (array $action) => $action['href'] === '/governance/evaluations'));
        $this->assertTrue($actions->contains(fn (array $action) => $action['href'] === '/governance/meetings'));
    }

    public function test_dashboard_data_fresh_invalidates_cache_without_finance_writes(): void
    {
        $admin = $this->createAdminUser();

        // Count queries or record table states to verify zero finance writes
        \DB::enableQueryLog();

        $response = $this->actingAs($admin)->get('/governance/dashboard/data?period=month&fresh=1');

        $response->assertOk();

        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $writeQueries = collect($queries)->filter(function (array $query) {
            $sql = strtolower(trim($query['query']));
            return str_starts_with($sql, 'insert')
                || str_starts_with($sql, 'update')
                || str_starts_with($sql, 'delete');
        });

        // Ensure zero finance table writes occurred during the GET request
        $financeWrites = $writeQueries->filter(function (array $query) {
            $sql = strtolower($query['query']);
            return str_contains($sql, 'budget')
                || str_contains($sql, 'financial')
                || str_contains($sql, 'actual');
        });

        $this->assertCount(0, $financeWrites, 'GET /governance/dashboard/data?fresh=1 must not perform any Finance writes.');
    }

    public function test_dashboard_data_returns_500_when_aggregator_fails(): void
    {
        $admin = $this->createAdminUser();
        \Illuminate\Support\Facades\Cache::flush();

        $mockAggregator = \Mockery::mock(\App\Domain\Governance\Services\DashboardAggregatorService::class);
        $mockAggregator->shouldReceive('aggregate')
            ->once()
            ->andThrow(new \RuntimeException('Aggregator database outage'));

        $this->app->instance(\App\Domain\Governance\Services\DashboardAggregatorService::class, $mockAggregator);

        $response = $this->actingAs($admin)->get('/governance/dashboard/data?period=month&fresh=1');

        $response->assertStatus(500);
        $response->assertJson([
            'message' => 'Board information could not be loaded.',
        ]);
    }

    private function createOrdinaryMember(): User
    {
        $user = $this->createUserWithRole('board_member');
        $this->createBoardMember($user);

        return $user->fresh();
    }

    public function test_member_home_my_work_uses_the_same_source_and_totals_as_my_work(): void
    {
        $chair = $this->createAdminUser();
        $member = $this->createOrdinaryMember();

        foreach (range(1, 7) as $i) {
            $this->createActionItem($chair, $member, [
                'title' => "Member follow-up {$i}",
                'due_date' => now()->addDays($i)->toDateString(),
            ]);
        }

        $home = $this->actingAs($member)->getJson('/governance/dashboard/data?period=month&fresh=1');
        $home->assertOk();
        $myWork = $this->actingAs($member)->getJson('/governance/my-work/data');
        $myWork->assertOk();

        // Full authorised totals — never the number of cards Home displays.
        $this->assertSame($myWork->json('totals'), $home->json('my_work.totals'));
        $this->assertSame($myWork->json('totals'), $home->json('work_totals'));
        $this->assertSame($myWork->json('pagination.total'), $home->json('my_work.pagination.total'));
        $this->assertGreaterThanOrEqual(7, $home->json('my_work.totals.pending'));
        $this->assertCount(DashboardController::MY_WORK_PREVIEW, $home->json('my_work.items'));
        $this->assertSame('/governance/my-work', $home->json('my_work.href'));

        // Home previews the first items of the same ranked list.
        $this->assertSame(
            collect($myWork->json('items'))->take(DashboardController::MY_WORK_PREVIEW)->pluck('id')->all(),
            collect($home->json('my_work.items'))->pluck('id')->all(),
        );

        // The Inertia page receives the same totals for the header before data loads.
        $this->actingAs($member)->get('/governance/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/Dashboard')
                ->where('workTotals.pending', $myWork->json('totals.pending'))
            );
    }

    public function test_member_next_meeting_readiness_is_personal_and_counts_only_permitted_records(): void
    {
        $chair = $this->createAdminUser();
        $member = $this->createOrdinaryMember();
        $meeting = $this->createMeeting($chair, [
            'title' => 'Next Board Meeting',
            'scheduled_at' => now()->addDays(5),
        ]);

        MeetingAgendaItem::create([
            'governance_meeting_id' => $meeting->id,
            'order' => 1,
            'title' => 'Strategy update',
            'duration_minutes' => 20,
            'item_type' => 'discussion',
            'is_confidential' => false,
        ]);
        MeetingAgendaItem::create([
            'governance_meeting_id' => $meeting->id,
            'order' => 2,
            'title' => 'CONFIDENTIAL acquisition briefing',
            'duration_minutes' => 30,
            'item_type' => 'decision',
            'is_confidential' => true,
        ]);
        $resolution = $this->createResolution($chair, [
            'title' => 'Approve the annual plan',
            'status' => 'open',
            'governance_meeting_id' => $meeting->id,
            'opened_at' => now(),
            'deadline' => now()->addDays(3),
        ]);

        $home = $this->actingAs($member)->getJson('/governance/dashboard/data?period=month&fresh=1');
        $home->assertOk();
        $this->assertSame($meeting->id, $home->json('cockpit.next_meeting.meeting.id'));

        $readiness = $home->json('cockpit.next_meeting.member_readiness');
        $this->assertSame("/governance/meetings/{$meeting->id}", $readiness['workspace_href']);
        // Only the agenda the member may read is counted.
        $this->assertSame(1, $readiness['papers']['count']);
        $this->assertStringNotContainsString('CONFIDENTIAL acquisition briefing', $home->getContent());
        // No pack has been published to the member.
        $this->assertFalse($readiness['pack']['published']);
        $this->assertNull($readiness['pack']['href']);

        // Votes open = exactly the vote obligations My work lists for this meeting.
        $myVotes = collect($this->actingAs($member)->getJson('/governance/my-work/data?kind=vote')->json('items'))
            ->where('source.type', 'resolution')
            ->pluck('source.id');
        $this->assertTrue($readiness['votes']['available']);
        $this->assertSame($myVotes->contains($resolution->id) ? 1 : 0, $readiness['votes']['open']);

        $this->assertTrue($readiness['conflicts']['is_member']);
        $this->assertSame(0, $readiness['conflicts']['declared']);
        $this->assertSame(1, $readiness['conflicts']['decisions_to_check']);

        // Declaring a conflict on the decision is reflected and withdraws the vote obligation.
        ConflictDeclaration::create([
            'governance_meeting_id' => $meeting->id,
            'resolution_id' => $resolution->id,
            'board_member_id' => $member->boardMember->id,
            'declaration_type' => 'material',
            'declaration_text' => 'Director of the proposed supplier.',
            'withdrew_from_voting' => true,
            'recorded_by' => $member->id,
            'declared_at' => now(),
        ]);

        $after = $this->actingAs($member)->getJson('/governance/dashboard/data?period=month&fresh=1');
        $after->assertOk();
        $this->assertSame(1, $after->json('cockpit.next_meeting.member_readiness.conflicts.declared'));
        $this->assertSame(0, $after->json('cockpit.next_meeting.member_readiness.conflicts.decisions_to_check'));
        $this->assertSame(0, $after->json('cockpit.next_meeting.member_readiness.votes.open'));
    }

    public function test_home_assurance_counts_match_the_register_views_they_link_to(): void
    {
        $admin = $this->createAdminUser();

        $this->createRisk($admin, ['title' => 'Above appetite A', 'likelihood_score' => 5, 'impact_score' => 5, 'control_effectiveness' => 'weak', 'status' => 'active']);
        $this->createRisk($admin, ['title' => 'Above appetite B', 'likelihood_score' => 4, 'impact_score' => 5, 'control_effectiveness' => 'weak', 'status' => 'active']);
        $this->createRisk($admin, ['title' => 'Within appetite', 'status' => 'active']);

        foreach (['OVD-1', 'OVD-2'] as $code) {
            $past = now()->subDays(3)->toDateString();
            $this->createComplianceObligation($admin, ['obligation_code' => $code, 'due_date' => $past, 'next_due_date' => $past]);
        }
        $this->createComplianceObligation($admin, ['obligation_code' => 'FUT-1']);

        foreach (range(1, 3) as $i) {
            $this->createActionItem($admin, $admin, ['title' => "Overdue {$i}", 'due_date' => now()->subDays($i + 1)->toDateString()]);
        }
        $this->createActionItem($admin, $admin, ['title' => 'Not yet due']);

        $home = $this->actingAs($admin)->getJson('/governance/dashboard/data?period=month&fresh=1');
        $home->assertOk();
        $assurance = $home->json('cockpit.assurance');

        $this->assertTrue($assurance['risks_above_appetite']['available']);
        $this->assertSame(2, $assurance['risks_above_appetite']['count']);
        $this->assertSame('/governance/risks?above_appetite=1', $assurance['risks_above_appetite']['href']);
        $this->assertSame(2, $assurance['obligations_overdue']['count']);
        $this->assertSame('/governance/compliance?status=overdue', $assurance['obligations_overdue']['href']);
        $this->assertSame(3, $assurance['actions_overdue']['count']);
        $this->assertSame('/governance/actions?status=overdue', $assurance['actions_overdue']['href']);

        // Each linked view lists exactly the counted records.
        $this->actingAs($admin)->get($assurance['risks_above_appetite']['href'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('risks.total', 2));
        $this->actingAs($admin)->get($assurance['obligations_overdue']['href'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('obligations.total', 2));
        $this->actingAs($admin)->get($assurance['actions_overdue']['href'])
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('items.total', 3));

        // The KPI band never says "within appetite" while risks are above it.
        $riskKpi = collect($home->json('cockpit.kpi_band'))->firstWhere('key', 'risks_over_appetite');
        $this->assertSame('2', $riskKpi['value']);
        $this->assertStringNotContainsStringIgnoringCase('within appetite', $riskKpi['sublabel']);
    }

    public function test_home_reports_failed_assurance_sources_as_unavailable_not_zero(): void
    {
        $admin = $this->createAdminUser();
        Cache::flush();

        $mockAggregator = \Mockery::mock(DashboardAggregatorService::class);
        $mockAggregator->shouldReceive('aggregate')->andReturn([
            'data' => [
                'period' => ['type' => 'month', 'start' => now()->startOfMonth()->toDateString(), 'end' => now()->toDateString()],
                'widgets' => [
                    'top_risks' => ['status' => 'unavailable', 'reason' => 'Widget data temporarily unavailable'],
                    'compliance_calendar' => ['status' => 'unavailable', 'reason' => 'Widget data temporarily unavailable'],
                    'financial' => ['status' => 'unavailable', 'reason' => 'Widget data temporarily unavailable'],
                ],
                'captured_at' => now()->toIso8601String(),
            ],
            'freshness' => [],
        ]);
        $this->app->instance(DashboardAggregatorService::class, $mockAggregator);

        $response = $this->actingAs($admin)->getJson('/governance/dashboard/data?period=month&fresh=1');
        $response->assertOk();

        $this->assertFalse($response->json('cockpit.assurance.risks_above_appetite.available'));
        $this->assertNull($response->json('cockpit.assurance.risks_above_appetite.count'));
        $this->assertFalse($response->json('cockpit.assurance.financial_variance.available'));
        $this->assertNull($response->json('cockpit.assurance.financial_variance.variance_percent'));

        $this->assertSame('unknown', $response->json('cockpit.cards_by_key.top_risks.status'));
        $this->assertSame('unknown', $response->json('cockpit.cards_by_key.compliance_calendar.status'));
        $riskKpi = collect($response->json('cockpit.kpi_band'))->firstWhere('key', 'risks_over_appetite');
        $this->assertSame('—', $riskKpi['value']);
        $this->assertStringNotContainsStringIgnoringCase('within appetite', $riskKpi['sublabel']);
    }

    public function test_dashboard_data_timeline_events_is_zero_indexed_list(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/governance/dashboard/data?period=month');
        $response->assertOk();

        $events = $response->json('cockpit.timeline.events', []);
        $this->assertIsArray($events);
        $this->assertTrue(array_is_list($events), 'Timeline events must be a zero-indexed sequential list.');
    }
}
