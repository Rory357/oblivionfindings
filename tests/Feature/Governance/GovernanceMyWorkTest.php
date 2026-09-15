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

    public function test_meeting_votes_open_the_paper_inside_its_meeting_workspace(): void
    {
        $member = User::find($this->fixtures['users']['member']);
        $open = Resolution::find($this->fixtures['resolutions']['open']);

        $items = collect(
            $this->actingAs($member)->getJson('/governance/my-work/data?kind=vote')->assertOk()->json('items')
        );
        $vote = $items->firstWhere('source.id', $open->id);

        $this->assertNotNull($vote, 'The open meeting decision must be a vote obligation for the member.');
        $this->assertSame(
            "/governance/meetings/{$open->governance_meeting_id}?tab=resolutions&paper={$open->id}",
            $vote['required_action']['href'],
        );
        // The record identity stays canonical.
        $this->assertSame("/governance/resolutions/{$open->id}", $vote['source']['href']);
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

    public function test_completed_view_shows_the_record_of_completion_the_server_holds(): void
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
            'completion_receipt' => 'ACT-REC-SIGNED-OFF-20260914',
        ]);
        $withoutReceipt = ActionItem::create([
            'source_type' => 'meeting',
            'source_id' => $meeting->id,
            'governance_meeting_id' => $meeting->id,
            'assigned_to' => $member->id,
            'created_by' => $member->id,
            'description' => 'Finished before receipts were recorded',
            'status' => 'completed',
            'priority' => 'low',
            'due_date' => now()->subDays(4)->toDateString(),
            'completed_at' => now()->subDays(3),
        ]);

        $response = $this->actingAs($member)->getJson('/governance/my-work/data?status=completed');

        $response->assertOk();
        $items = collect($response->json('items'));

        $completedItem = $items->firstWhere('source.id', $action->id);
        $this->assertNotNull($completedItem, 'Completed item must appear in completed status view.');
        $this->assertSame('completed', $completedItem['status']);
        $this->assertSame('Signed off and done', $completedItem['title']);
        $this->assertStringStartsWith('Marked as done on ', $completedItem['reason']);
        $this->assertSame('ACT-REC-SIGNED-OFF-20260914', $completedItem['receipt']['receipt_id']);
        $this->assertSame('Implemented and verified.', $completedItem['receipt']['completion_notes']);

        // Nothing is invented: no recorded reference means no reference shown.
        $legacy = $items->firstWhere('source.id', $withoutReceipt->id);
        $this->assertNull($legacy['receipt']['receipt_id']);
        $this->assertNull($legacy['receipt']['completion_notes']);
    }

    /**
     * P0-6 — upcoming meetings are for your information: they are listed
     * apart as "Coming up" and never counted as work to do, so a member can
     * be up to date while meetings are scheduled.
     */
    public function test_upcoming_meetings_are_for_information_and_never_counted_as_work(): void
    {
        $member = User::find($this->fixtures['users']['member']);

        $feed = $this->actingAs($member)->getJson('/governance/my-work/data')->assertOk();
        $totals = $feed->json('totals');

        $this->assertGreaterThanOrEqual(1, $totals['know']);
        $this->assertSame($totals['vote'] + $totals['read'] + $totals['act'], $totals['all']);
        $this->assertSame($totals['all'], $totals['pending']);
        $this->assertFalse(collect($feed->json('items'))->contains('kind', 'know'));

        $comingUp = collect($feed->json('coming_up'));
        $this->assertCount($totals['know'], $comingUp);
        $this->assertTrue($comingUp->every(fn (array $item) => $item['status'] === 'upcoming'
            && $item['kind'] === 'know'
            && $item['required_action']['label'] === 'Open meeting'
            && ! str_starts_with($item['title'], 'Upcoming:')));

        // The "For your information" filter lists exactly those meetings.
        $know = $this->actingAs($member)->getJson('/governance/my-work/data?kind=know')->assertOk();
        $this->assertCount($totals['know'], $know->json('items'));
        $this->assertTrue(collect($know->json('items'))->every(fn (array $item) => $item['kind'] === 'know'));

        // Someone with meetings scheduled but nothing to do is up to date.
        $observer = $this->createUserWithRole('board_observer');
        $observerFeed = $this->actingAs($observer)->getJson('/governance/my-work/data')->assertOk();
        $this->assertSame(0, $observerFeed->json('totals.pending'));
        $this->assertGreaterThanOrEqual(1, $observerFeed->json('totals.know'));
    }

    public function test_work_items_lead_with_titles_and_use_plain_labels(): void
    {
        $member = User::find($this->fixtures['users']['member']);

        $response = $this->actingAs($member)->getJson('/governance/my-work/data?per_page=100')->assertOk();
        $items = collect($response->json('items'));

        $nullDeadline = $items->firstWhere('source.id', $this->fixtures['resolutions']['null_deadline']);
        $this->assertSame('Synthetic Resolution: Null Deadline Governance Policy', $nullDeadline['title']);
        $this->assertSame('No voting deadline has been set.', $nullDeadline['reason']);
        $this->assertSame('Vote', $nullDeadline['required_action']['label']);

        // A vote whose deadline has passed can't be cast — offer the paper instead.
        $expired = $items->firstWhere('source.id', $this->fixtures['resolutions']['expired']);
        $this->assertSame('Open paper', $expired['required_action']['label']);

        $pack = $items->firstWhere('source.type', 'board_pack');
        $this->assertStringStartsWith('Board pack for Synthetic Ordinary Board Meeting Q3', $pack['title']);
        $this->assertSame('Read pack', $pack['required_action']['label']);
        $this->assertStringContainsString("confirm you've read it", $pack['reason']);

        $action = $items->firstWhere('source.reference', 'ACT-SYN-004');
        $this->assertSame('Update action', $action['required_action']['label']);
        $this->assertStringStartsNotWith('ACT-', $action['title']);

        $body = $response->getContent();
        foreach (['Legacy resolution', 'attestation', 'Attest', 'day(s)', 'Cast Vote', 'Read Pack', 'Rev ', 'Upcoming:'] as $developerText) {
            $this->assertStringNotContainsString($developerText, $body);
        }
    }

    /** Regression: completed votes never appeared (the feed checked a table that doesn't exist). */
    public function test_completed_votes_show_the_recorded_vote_and_paper_version(): void
    {
        $member = User::find($this->fixtures['users']['member']);
        $open = Resolution::find($this->fixtures['resolutions']['open']);

        $vote = Vote::create([
            'resolution_id' => $open->id,
            'board_member_id' => $this->fixtures['board_members']['member'],
            'vote' => 'for',
            'voted_at' => now()->subHour(),
            'voting_method' => 'electronic',
            'recorded_by' => $member->id,
        ]);

        $completed = collect(
            $this->actingAs($member)->getJson('/governance/my-work/data?status=completed')->assertOk()->json('items')
        )->firstWhere('id', "resolution:{$open->id}:vote:completed");

        $this->assertNotNull($completed);
        $this->assertStringStartsWith('You voted For on ', $completed['reason']);
        $this->assertSame('for', $completed['receipt']['vote']);
        $this->assertSame((int) ($open->version_number ?? 1), $completed['receipt']['paper_version']);
        $this->assertSame("VOTE-RCP-{$vote->id}", $completed['receipt']['receipt_id']);

        // …and it is no longer waiting for a vote.
        $pendingVotes = collect($this->actingAs($member)->getJson('/governance/my-work/data?kind=vote')->json('items'));
        $this->assertFalse($pendingVotes->contains('source.id', $open->id));
    }

    /**
     * "My performance review": the person being reviewed is asked for their
     * self-assessment — counted like their other work — and told when the
     * board has completed the review. Other members never get these items,
     * and no item carries the board's rating, decision or narrative.
     */
    public function test_only_the_reviewee_gets_their_performance_review_work(): void
    {
        $chair = User::find($this->fixtures['users']['chair']);
        $member = User::find($this->fixtures['users']['member']);
        $reviewee = $this->createUserWithRole('ceo', ['name' => 'Aroha Chief']);
        $review = $this->createPerformanceReview($reviewee, $chair, [
            'review_cycle' => '2026-Annual',
            'status' => 'self_review',
            'overall_rating' => 'needs_improvement',
            'board_decision' => 'performance_improvement',
            'overall_assessment' => 'CONFIDENTIAL board narrative',
        ]);

        $feed = $this->actingAs($reviewee)->getJson('/governance/my-work/data')->assertOk();
        $ask = collect($feed->json('items'))->firstWhere('id', "performance_review:{$review->id}:self_assessment");
        $this->assertNotNull($ask);
        $this->assertSame('act', $ask['kind']);
        $this->assertSame('Write your self-assessment for Annual review 2026', $ask['title']);
        $this->assertSame('pending', $ask['status']);
        $this->assertSame("/governance/performance/{$review->id}#self-assessment", $ask['required_action']['href']);
        $this->assertTrue($ask['required_action']['allowed']);
        $this->assertSame(1, $feed->json('totals.act'));
        $this->assertGreaterThanOrEqual(1, $feed->json('totals.pending'));
        foreach (['needs_improvement', 'Needs improvement', 'performance_improvement', 'CONFIDENTIAL board narrative'] as $secret) {
            $this->assertStringNotContainsString($secret, $feed->getContent());
        }

        // Nobody else is asked about someone else's review.
        $memberFeed = $this->actingAs($member)->getJson('/governance/my-work/data?per_page=100')->assertOk();
        $this->assertFalse(collect($memberFeed->json('items'))->contains('source.type', 'performance_review'));
        $this->assertFalse(collect($memberFeed->json('coming_up'))->contains('source.type', 'performance_review'));

        // Once the self-assessment is sent, the ask is gone.
        $review->submitSelfAssessment('My reflections on the year.');
        $sent = $this->actingAs($reviewee)->getJson('/governance/my-work/data')->assertOk();
        $this->assertFalse(collect($sent->json('items'))->contains('source.type', 'performance_review'));
        $this->assertSame(0, $sent->json('totals.act'));

        // When the board completes the review, the reviewee is told — for information, not as work.
        $review->forceFill(['status' => 'completed', 'approved_by_board_at' => now()])->save();
        $completed = $this->actingAs($reviewee)->getJson('/governance/my-work/data')->assertOk();
        $outcome = collect($completed->json('coming_up'))->firstWhere('id', "performance_review:{$review->id}:outcome");
        $this->assertNotNull($outcome);
        $this->assertSame('know', $outcome['kind']);
        $this->assertSame('Your performance review is complete — read the outcome', $outcome['title']);
        $this->assertSame('Read the outcome', $outcome['required_action']['label']);
        $this->assertSame(0, $completed->json('totals.pending'));
        $this->assertGreaterThanOrEqual(1, $completed->json('totals.know'));
        $this->assertStringNotContainsString('Needs improvement', $completed->getContent());
        $this->assertFalse(collect(
            $this->actingAs($member)->getJson('/governance/my-work/data')->assertOk()->json('coming_up')
        )->contains('source.type', 'performance_review'));

        // A reviewee who can't open reviews still sees the ask, with the reason.
        $restricted = $this->createUserWithRole('ceo');
        $restrictedReview = $this->createPerformanceReview($restricted, $chair, ['status' => 'self_review']);
        $permission = \App\Models\Permission::where('key', 'governance.performance.view')->firstOrFail();
        $restricted->permissionOverrides()->attach($permission->id, ['allowed' => false]);
        $restrictedAsk = collect(
            $this->actingAs($restricted)->getJson('/governance/my-work/data')->assertOk()->json('items')
        )->firstWhere('id', "performance_review:{$restrictedReview->id}:self_assessment");
        $this->assertNotNull($restrictedAsk);
        $this->assertFalse($restrictedAsk['required_action']['allowed']);
        $this->assertSame(
            "You don't have access to open performance reviews yet. Ask the board chair.",
            $restrictedAsk['required_action']['blocked_reason'],
        );
    }

    public function test_my_work_page_gives_pagination_links_that_keep_the_filters(): void
    {
        $member = User::find($this->fixtures['users']['member']);
        $meeting = GovernanceMeeting::find($this->fixtures['meetings']['regular']);

        for ($i = 1; $i <= 30; $i++) {
            ActionItem::create([
                'source_type' => 'meeting',
                'source_id' => $meeting->id,
                'governance_meeting_id' => $meeting->id,
                'assigned_to' => $member->id,
                'created_by' => $member->id,
                'description' => "Paged follow-up {$i}",
                'status' => 'open',
                'priority' => 'medium',
                'due_date' => now()->addDays($i)->toDateString(),
            ]);
        }

        $this->actingAs($member)
            ->get('/governance/my-work?kind=act')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Governance/MyWork/Index')
                ->where('feed.pagination.links', fn ($links) => collect($links)->contains(
                    fn ($link) => str_contains((string) ($link['url'] ?? ''), 'page=2')
                        && str_contains((string) ($link['url'] ?? ''), 'kind=act')
                ))
            );
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
