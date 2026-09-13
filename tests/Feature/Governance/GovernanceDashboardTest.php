<?php

namespace Tests\Feature\Governance;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
