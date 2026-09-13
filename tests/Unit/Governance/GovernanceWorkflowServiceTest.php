<?php

namespace Tests\Unit\Governance;

use App\Domain\Governance\Services\GovernanceWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceWorkflowServiceTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    public function test_dashboard_workflow_prioritizes_overdue_resolution(): void
    {
        $admin = $this->createAdminUser();

        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->subDay(),
        ]);

        $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->addDays(20),
        ]);

        $service = app(GovernanceWorkflowService::class);
        $workflow = $service->dashboardWorkflow($admin);

        $this->assertNotEmpty($workflow['actions']);
        $first = $workflow['actions'][0];

        $this->assertSame("resolution:{$resolution->id}", $first['id']);
        $this->assertSame('overdue', $first['status']);
        $this->assertSame('critical', $first['priority']);
    }

    public function test_meeting_checklist_marks_pack_as_blocked_when_agenda_missing(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin, [
            'scheduled_at' => now()->addDays(3),
        ]);

        $service = app(GovernanceWorkflowService::class);
        $checklist = $service->meetingChecklist($meeting, $admin);

        $packGenerated = collect($checklist['items'])->firstWhere('key', 'pack_generated');
        $agenda = collect($checklist['items'])->firstWhere('key', 'agenda');

        $this->assertNotNull($packGenerated);
        $this->assertSame('blocked', $packGenerated['status']);
        $this->assertSame('Agenda is empty', $packGenerated['blocked_by']);

        $this->assertNotNull($agenda);
        $this->assertSame('todo', $agenda['status']);
        $this->assertSame('Agenda prepared', $checklist['next_step']['label']);
    }

    public function test_workflow_summary_counts_full_scope_before_limiting_preview(): void
    {
        $data = \Tests\Support\GovernanceSyntheticFixtures::seed();
        $admin = \App\Models\User::findOrFail($data['users']['chair']);

        $service = app(GovernanceWorkflowService::class);
        $workflow = $service->dashboardWorkflow($admin, limit: 15);

        // In synthetic fixtures, 40 actions + resolutions + meetings exist
        $this->assertGreaterThanOrEqual(40, $workflow['summary']['total']);
        // The actions array itself is capped at 15 for dashboard preview
        $this->assertLessThanOrEqual(15, count($workflow['actions']));
        $this->assertNotEmpty($workflow['actions']);
    }

    public function test_work_query_separates_personal_obligations_and_prevents_duplicate_name_leak(): void
    {
        $data = \Tests\Support\GovernanceSyntheticFixtures::seed();
        $memberUser = \App\Models\User::findOrFail($data['users']['member']);
        $dupUser = \App\Models\User::findOrFail($data['users']['member_duplicate_name']);

        $this->assertSame($memberUser->name, $dupUser->name);
        $this->assertNotSame($memberUser->id, $dupUser->id);

        $query = app(\App\Domain\Governance\Services\GovernanceWorkQuery::class);
        $feed = $query->queryFeed($memberUser, perPage: 50);

        $items = collect($feed['items']);
        $action1 = $items->firstWhere('source.reference', 'ACT-SYN-001');
        $action2 = $items->firstWhere('source.reference', 'ACT-SYN-002');

        // Action 1 belongs to memberUser
        $this->assertNotNull($action1);
        $this->assertSame($memberUser->id, $action1['assignee_user_id']);
        $this->assertSame('blocked', $action1['status']);
        $this->assertSame('Awaiting external supply chain quote.', $action1['required_action']['blocked_reason']);

        // Action 2 belongs to duplicateNameUser and MUST NOT appear in memberUser feed
        $this->assertNull($action2);
    }

    public function test_work_query_pagination_allows_accessing_last_record(): void
    {
        $data = \Tests\Support\GovernanceSyntheticFixtures::seed();
        $memberUser = \App\Models\User::findOrFail($data['users']['member']);

        $query = app(\App\Domain\Governance\Services\GovernanceWorkQuery::class);

        // Page 1 with small page size
        $page1 = $query->queryFeed($memberUser, ['kind' => 'act'], perPage: 5, page: 1);
        $this->assertGreaterThan(5, $page1['pagination']['total']);
        $this->assertGreaterThan(1, $page1['pagination']['last_page']);

        // Query last page
        $lastPageNum = $page1['pagination']['last_page'];
        $lastPage = $query->queryFeed($memberUser, ['kind' => 'act'], perPage: 5, page: $lastPageNum);

        $this->assertNotEmpty($lastPage['items']);
        $this->assertSame($lastPageNum, $lastPage['pagination']['current_page']);

        // Check action 30 is accessible
        $allMemberAct = $query->queryFeed($memberUser, ['kind' => 'act'], perPage: 100, page: 1);
        $hasAction30 = collect($allMemberAct['items'])->contains('source.reference', 'ACT-SYN-030');
        $this->assertTrue($hasAction30);
    }

    public function test_work_query_flags_null_deadline_and_prioritizes_expired_vote(): void
    {
        $data = \Tests\Support\GovernanceSyntheticFixtures::seed();
        $memberUser = \App\Models\User::findOrFail($data['users']['member']);

        $query = app(\App\Domain\Governance\Services\GovernanceWorkQuery::class);
        $feed = $query->queryFeed($memberUser, ['kind' => 'vote']);

        $items = collect($feed['items']);

        $expiredVote = $items->first(fn ($item) => str_contains($item['title'], 'Expired Fleet Replacement'));
        $nullDeadlineVote = $items->first(fn ($item) => str_contains($item['title'], 'Null Deadline Governance Policy'));

        $this->assertNotNull($expiredVote);
        $this->assertSame('overdue', $expiredVote['status']);
        $this->assertSame('critical', $expiredVote['priority']);

        $this->assertNotNull($nullDeadlineVote);
        $this->assertNull($nullDeadlineVote['due_date']);
        $this->assertSame('high', $nullDeadlineVote['priority']);
        $this->assertStringContainsString('missing', strtolower($nullDeadlineVote['reason']));
    }

    public function test_read_obligations_tracks_pack_revisions(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $data = \Tests\Support\GovernanceSyntheticFixtures::seed();
        $admin = \App\Models\User::findOrFail($data['users']['chair']);
        $memberUser = \App\Models\User::findOrFail($data['users']['member']);
        $member = \App\Domain\Governance\Models\BoardMember::findOrFail($data['board_members']['member']);
        $pack = \App\Domain\Governance\Models\BoardPack::findOrFail($data['pack']);

        $query = app(\App\Domain\Governance\Services\GovernanceWorkQuery::class);

        // Pack v1 is distributed and unread -> appears in read items
        $feed = $query->queryFeed($memberUser, ['kind' => 'read']);
        $packItem = collect($feed['items'])->firstWhere('source.type', 'board_pack');
        $this->assertNotNull($packItem);
        $this->assertSame(1, $packItem['source_version']);

        // Member marks v1 as read
        $pack->recordRead($member->id);

        // Pack v1 should no longer appear in pending read items
        $feedAfterRead = $query->queryFeed($memberUser, ['kind' => 'read']);
        $packItemAfterRead = collect($feedAfterRead['items'])->firstWhere('source.type', 'board_pack');
        $this->assertNull($packItemAfterRead);

        // Manager generates revision 2 and distributes
        $builder = app(\App\Domain\Governance\Services\BoardPackBuilderService::class);
        $this->actingAs($admin);
        $packV2 = $builder->regenerate($pack);
        $builder->distribute($packV2, [$member->id]);

        // Revision 2 must now appear as a new reading obligation
        $feedV2 = $query->queryFeed($memberUser, ['kind' => 'read']);
        $packItemV2 = collect($feedV2['items'])->firstWhere('source.type', 'board_pack');
        $this->assertNotNull($packItemV2);
        $this->assertSame(2, $packItemV2['source_version']);
    }
}

