<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\Vote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceSyntheticFixtures;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceMyWorkTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    protected array $fixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtures = GovernanceSyntheticFixtures::seed();
    }

    public function test_my_work_requires_authentication(): void
    {
        $response = $this->get('/governance/my-work');
        $response->assertRedirect('/login');
    }

    public function test_my_work_requires_approval(): void
    {
        $unapprovedUser = User::factory()->create([
            'approved_at' => null,
            'role' => 'board_member',
        ]);

        $response = $this->actingAs($unapprovedUser)->get('/governance/my-work');
        $response->assertRedirect();
    }

    public function test_my_work_requires_governance_view_permission(): void
    {
        $userWithoutPerm = User::factory()->create([
            'approved_at' => now(),
            'role' => 'staff',
        ]);

        $response = $this->actingAs($userWithoutPerm)->get('/governance/my-work');
        $response->assertForbidden();
    }

    public function test_my_work_renders_inertia_component_for_authorized_member(): void
    {
        $memberUser = User::find($this->fixtures['users']['member']);

        $response = $this->actingAs($memberUser)->get('/governance/my-work');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Governance/MyWork/Index')
            ->has('feed')
            ->has('feed.items')
            ->has('feed.totals')
            ->has('feed.pagination')
            ->has('feed.scope')
            ->has('feed.availability')
            ->has('filters')
        );
    }

    public function test_my_work_data_endpoint_returns_json(): void
    {
        $memberUser = User::find($this->fixtures['users']['member']);

        $response = $this->actingAs($memberUser)->getJson('/governance/my-work/data');

        $response->assertOk();
        $response->assertJsonStructure([
            'items',
            'totals' => [
                'all',
                'vote',
                'read',
                'act',
                'know',
                'pending',
                'overdue',
                'blocked',
                'completed',
            ],
            'pagination' => [
                'total',
                'per_page',
                'current_page',
                'last_page',
            ],
            'scope' => [
                'viewer' => ['user_id', 'name'],
                'filters',
            ],
            'availability',
            'all_sources_succeeded',
            'generated_at',
        ]);
    }

    public function test_viewer_obligations_are_isolated_and_untrusted_parameters_are_ignored(): void
    {
        $memberA = User::find($this->fixtures['users']['member']);
        $memberB = User::find($this->fixtures['users']['chair']);
        $boardMemberB = BoardMember::find($this->fixtures['board_members']['chair']);

        $meeting = GovernanceMeeting::find($this->fixtures['meetings']['regular']);

        // Create action item for Member A
        $actionA = ActionItem::create([
            'source_type' => 'meeting',
            'source_id' => $meeting->id,
            'governance_meeting_id' => $meeting->id,
            'assigned_to' => $memberA->id,
            'created_by' => $memberA->id,
            'description' => 'Personal Task for Member A Only',
            'status' => 'open',
            'priority' => 'high',
            'due_date' => now()->addDays(3)->toDateString(),
        ]);

        // Create action item for Member B
        $actionB = ActionItem::create([
            'source_type' => 'meeting',
            'source_id' => $meeting->id,
            'governance_meeting_id' => $meeting->id,
            'assigned_to' => $memberB->id,
            'created_by' => $memberB->id,
            'description' => 'Personal Task for Member B Only',
            'status' => 'open',
            'priority' => 'high',
            'due_date' => now()->addDays(3)->toDateString(),
        ]);

        // Member A attempts to spoof user_id or board_member_id to inspect Member B's work
        $response = $this->actingAs($memberA)->getJson("/governance/my-work/data?user_id={$memberB->id}&board_member_id={$boardMemberB->id}");

        $response->assertOk();
        $items = collect($response->json('items'));

        // Member A sees their item
        $this->assertTrue(
            $items->contains(fn ($item) => str_contains($item['title'], 'Personal Task for Member A Only')),
            'Member A must see their own assigned action item.'
        );

        // Member A NEVER sees Member B's item
        $this->assertFalse(
            $items->contains(fn ($item) => str_contains($item['title'], 'Personal Task for Member B Only')),
            'Member A must never see another member assigned action item through spoofed parameters.'
        );

        // Scope viewer must remain Member A
        $this->assertSame($memberA->id, $response->json('scope.viewer.user_id'));
    }

    public function test_same_name_members_are_isolated_by_id_attribution(): void
    {
        $memberA = User::find($this->fixtures['users']['member']);
        $duplicateNameUser = User::find($this->fixtures['users']['member_duplicate_name']);

        $this->assertSame($memberA->name, $duplicateNameUser->name);

        $meeting = GovernanceMeeting::find($this->fixtures['meetings']['regular']);

        // Create action assigned to Member A
        ActionItem::create([
            'source_type' => 'meeting',
            'source_id' => $meeting->id,
            'governance_meeting_id' => $meeting->id,
            'assigned_to' => $memberA->id,
            'created_by' => $memberA->id,
            'description' => 'Task Specifically For First Synthetic Member',
            'status' => 'open',
            'priority' => 'medium',
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        // Duplicate name user queries feed
        $response = $this->actingAs($duplicateNameUser)->getJson('/governance/my-work/data?kind=act');

        $response->assertOk();
        $items = collect($response->json('items'));

        // Duplicate name user must NOT see Member A's item despite sharing identical name
        $this->assertFalse(
            $items->contains(fn ($item) => str_contains($item['title'], 'Task Specifically For First Synthetic Member')),
            'Duplicate name user must not be attributed items owned by another user with the same name.'
        );
    }

    public function test_completed_view_displays_durable_receipts(): void
    {
        $member = User::find($this->fixtures['users']['member']);
        $meeting = GovernanceMeeting::find($this->fixtures['meetings']['regular']);

        $action = ActionItem::create([
            'source_type' => 'meeting',
            'source_id' => $meeting->id,
            'governance_meeting_id' => $meeting->id,
            'assigned_to' => $member->id,
            'created_by' => $member->id,
            'description' => 'Signed off and done',
            'status' => 'completed',
            'priority' => 'low',
            'due_date' => now()->subDay()->toDateString(),
            'completed_at' => now()->subHours(2),
            'completion_notes' => 'Implemented and verified.',
        ]);

        $response = $this->actingAs($member)->getJson('/governance/my-work/data?status=completed');

        $response->assertOk();
        $items = collect($response->json('items'));

        $completedItem = $items->firstWhere('source.id', $action->id);
        $this->assertNotNull($completedItem, 'Completed item must appear in completed status view.');
        $this->assertSame('completed', $completedItem['status']);
        $this->assertNotNull($completedItem['receipt'], 'Completed item must include a durable receipt.');
        $this->assertStringContainsString((string) $action->id, $completedItem['receipt']['receipt_id']);
        $this->assertSame('Implemented and verified.', $completedItem['receipt']['completion_notes']);
    }

    public function test_pagination_and_kind_filters(): void
    {
        $member = User::find($this->fixtures['users']['member']);
        $meeting = GovernanceMeeting::find($this->fixtures['meetings']['regular']);

        // Create 30 action items to test pagination (page size = 25)
        for ($i = 1; $i <= 30; $i++) {
            ActionItem::create([
                'source_type' => 'meeting',
                'source_id' => $meeting->id,
                'governance_meeting_id' => $meeting->id,
                'assigned_to' => $member->id,
                'created_by' => $member->id,
                'description' => "Description for item {$i}",
                'status' => 'open',
                'priority' => 'medium',
                'due_date' => now()->addDays($i)->toDateString(),
            ]);
        }

        // Page 1
        $responsePage1 = $this->actingAs($member)->getJson('/governance/my-work/data?kind=act&page=1');
        $responsePage1->assertOk();
        $this->assertCount(25, $responsePage1->json('items'));
        $this->assertSame(1, $responsePage1->json('pagination.current_page'));
        $this->assertSame(2, $responsePage1->json('pagination.last_page'));
        $this->assertGreaterThanOrEqual(30, $responsePage1->json('pagination.total'));

        // Page 2
        $responsePage2 = $this->actingAs($member)->getJson('/governance/my-work/data?kind=act&page=2');
        $responsePage2->assertOk();
        $this->assertLessThanOrEqual(25, count($responsePage2->json('items')));
        $this->assertSame(2, $responsePage2->json('pagination.current_page'));
    }
}
