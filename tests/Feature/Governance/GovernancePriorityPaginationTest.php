<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Services\GovernanceWorkflowService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * GOV-R10 — the board priorities panel must be able to reach every priority
 * its totals count. With more than one page of priorities, the dashboard
 * payload returns the first page plus a pagination contract, and
 * `/governance/dashboard/data?section=priorities` serves the remaining pages
 * for the same viewer and tab.
 */
class GovernancePriorityPaginationTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    private const EXTRA_ACTIONS = 128;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
        Cache::flush();
    }

    /**
     * @return array{member: User, chair: User}
     */
    private function seedManyPriorities(): array
    {
        $chair = $this->createAdminUser();
        $member = $this->createUserWithRole('board_member');
        $this->createBoardMember($member);

        $meeting = $this->createMeeting($chair, ['title' => 'Visible Board Meeting']);

        for ($i = 0; $i < self::EXTRA_ACTIONS; $i++) {
            $this->createActionItem($chair, $chair, [
                'action_reference' => sprintf('ACT-PAGE-%03d', $i),
                'description' => "Synthetic board follow-up {$i}",
                'source_type' => 'meeting',
                'source_id' => $meeting->id,
                'due_date' => now()->subDays(10 + $i)->toDateString(),
                'priority' => 'high',
            ]);
        }

        // Out-of-audience work: an executive-session action the member cannot see.
        $executive = $this->createMeeting($chair, [
            'title' => 'Executive Session',
            'meeting_type' => 'executive_session',
        ]);
        $this->createActionItem($chair, $chair, [
            'action_reference' => 'ACT-EXEC-HIDDEN',
            'description' => 'Executive-only follow-up',
            'source_type' => 'meeting',
            'source_id' => $executive->id,
            'due_date' => now()->subDays(3)->toDateString(),
            'priority' => 'critical',
        ]);

        return ['member' => $member, 'chair' => $chair];
    }

    public function test_service_pages_reach_every_counted_priority(): void
    {
        ['member' => $member] = $this->seedManyPriorities();
        $service = app(GovernanceWorkflowService::class);

        $first = $service->dashboardWorkflow($member, 100);
        $total = $first['summary']['total'];

        $this->assertGreaterThan(100, $total);
        $this->assertCount(100, $first['actions']);
        $this->assertSame($total, $first['pagination']['total']);
        $this->assertTrue($first['pagination']['has_more']);
        $this->assertSame((int) ceil($total / 100), $first['pagination']['last_page']);

        $collected = collect($first['actions']);
        for ($page = 2; $page <= $first['pagination']['last_page']; $page++) {
            $next = $service->dashboardWorkflow($member, 100, $page);
            $this->assertSame($total, $next['summary']['total']);
            $collected = $collected->merge($next['actions']);
        }

        $ids = $collected->pluck('id');
        $this->assertSame($total, $ids->count());
        $this->assertSame($total, $ids->unique()->count(), 'Pages must not overlap.');
        $this->assertFalse($ids->contains(fn (string $id) => $this->isExecutiveAction($id)));

        // Paging agrees with the full ranking.
        $unpaged = $service->dashboardWorkflow($member, $total + 50);
        $this->assertSame(collect($unpaged['actions'])->pluck('id')->all(), $ids->all());
        $this->assertFalse($unpaged['pagination']['has_more']);
    }

    public function test_dashboard_endpoint_serves_remaining_pages_for_the_same_viewer_and_tab(): void
    {
        ['member' => $member] = $this->seedManyPriorities();

        $dashboard = $this->actingAs($member)->getJson('/governance/dashboard/data?period=month');
        $dashboard->assertOk();

        $total = $dashboard->json('workflow.summary.total');
        $byTab = $dashboard->json('workflow.summary.by_tab');
        $this->assertGreaterThan(100, $total);
        $this->assertLessThanOrEqual(200, $total, 'Fixture is expected to span exactly two pages.');
        $this->assertCount(100, $dashboard->json('workflow.actions'));
        $this->assertSame($total, $dashboard->json('workflow.pagination.total'));
        $this->assertTrue($dashboard->json('workflow.pagination.has_more'));

        // "Show all" on the All tab: load page 2 and every counted priority is reachable.
        $page2 = $this->actingAs($member)->getJson('/governance/dashboard/data?section=priorities&tab=all&page=2');
        $page2->assertOk();
        $page2->assertJsonPath('workflow.pagination.page', 2);
        $page2->assertJsonPath('workflow.pagination.has_more', false);
        $page2->assertJsonCount($total - 100, 'workflow.actions');

        $allIds = collect($dashboard->json('workflow.actions'))
            ->merge($page2->json('workflow.actions'))
            ->pluck('id');
        $this->assertSame($total, $allIds->unique()->count());

        // A category tab is paged against its own count (by_tab), not the top sample.
        $actionIds = collect();
        $page = 1;
        do {
            $response = $this->actingAs($member)
                ->getJson("/governance/dashboard/data?section=priorities&tab=actions&page={$page}&per_page=50");
            $response->assertOk();
            $response->assertJsonPath('workflow.pagination.tab', 'actions');
            $response->assertJsonPath('workflow.pagination.total', $byTab['actions']);
            $actionIds = $actionIds->merge(collect($response->json('workflow.actions'))->pluck('id'));
            $hasMore = $response->json('workflow.pagination.has_more');
            $page++;
        } while ($hasMore && $page < 20);

        $this->assertGreaterThanOrEqual(self::EXTRA_ACTIONS, $byTab['actions']);
        $this->assertSame($byTab['actions'], $actionIds->unique()->count());
        $this->assertTrue($actionIds->every(fn (string $id) => str_starts_with($id, 'action-item:')));
        $this->assertFalse($actionIds->contains(fn (string $id) => $this->isExecutiveAction($id)));
    }

    public function test_priorities_section_validates_tab_and_page_size(): void
    {
        ['member' => $member] = $this->seedManyPriorities();

        $this->actingAs($member)
            ->getJson('/governance/dashboard/data?section=priorities&tab=secrets')
            ->assertStatus(422);

        $this->actingAs($member)
            ->getJson('/governance/dashboard/data?section=priorities&per_page=500')
            ->assertStatus(422);

        $this->actingAs($member)
            ->getJson('/governance/dashboard/data?section=priorities&page=0')
            ->assertStatus(422);
    }

    private function isExecutiveAction(string $id): bool
    {
        $hidden = ActionItem::where('action_reference', 'ACT-EXEC-HIDDEN')->value('id');

        return $id === "action-item:{$hidden}";
    }
}
