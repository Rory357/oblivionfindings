<?php

namespace Tests\Unit\Governance;

use App\Domain\Governance\Models\GovernancePolicy;
use App\Domain\Governance\Models\MeetingRsvp;
use App\Domain\Governance\Models\Vote;
use App\Domain\Governance\Services\GovernanceWorkflowService;
use App\Models\Permission;
use App\Models\User;
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
        $this->assertSame('The agenda is empty', $packGenerated['blocked_by']);

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
        $this->assertSame('No voting deadline has been set.', $nullDeadlineVote['reason']);
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

    /**
     * P0-7 — a member's Board priorities never list the chair's voting
     * administration, and every register source follows the viewer's view
     * permission: no count, title or link for a register they can't open.
     */
    public function test_board_priorities_are_permission_filtered_and_lead_with_plain_titles(): void
    {
        $chair = $this->createAdminUser();
        $meeting = $this->createMeeting($chair, ['title' => 'September board meeting', 'scheduled_at' => now()->addDays(10)]);

        $open = $this->createResolution($chair, [
            'governance_meeting_id' => $meeting->id,
            'resolution_reference' => 'RES-2026-004',
            'title' => 'Approve the new complaints process',
            'status' => 'open',
            'deadline' => now()->addDays(3),
        ]);
        $draft = $this->createResolution($chair, [
            'governance_meeting_id' => $meeting->id,
            'resolution_reference' => 'RES-2026-005',
            'title' => 'Adopt the updated delegations',
            'status' => 'draft',
        ]);

        $risk = $this->createRisk($chair, [
            'risk_reference' => 'R-2026-003',
            'title' => 'SECRET staffing ratios risk',
            'likelihood_score' => 5,
            'impact_score' => 5,
            'control_effectiveness' => 'weak',
            'appetite_threshold' => 10,
            'status' => 'active',
        ]);
        $obligation = $this->createComplianceObligation($chair, [
            'obligation_code' => 'CO-01',
            'obligation_title' => 'SECRET annual return',
            'framework' => 'charities',
            'due_date' => now()->addDays(3)->toDateString(),
        ]);
        $budget = $this->createBudget($chair, [
            'title' => 'SECRET operating budget',
            'status' => 'proposed',
            'total_budget' => 85000,
        ]);
        $policy = GovernancePolicy::create([
            'policy_code' => 'POL-SECRET-1',
            'title' => 'SECRET complaints policy',
            'category' => 'governance',
            'content' => 'Policy text.',
            'status' => 'approved',
            'next_review_date' => now()->addDays(5)->toDateString(),
            'owner_id' => $chair->id,
            'created_by' => $chair->id,
        ]);

        $service = app(GovernanceWorkflowService::class);

        // The chair runs voting: both papers are priorities, led by their titles.
        $chairActions = collect($service->dashboardWorkflow($chair)['actions'])->keyBy('id');
        $openAction = $chairActions->get("resolution:{$open->id}");
        $this->assertNotNull($openAction);
        $this->assertStringStartsWith('Voting closes in ', $openAction['title']);
        $this->assertStringEndsWith(': Approve the new complaints process', $openAction['title']);
        $this->assertStringNotContainsString('RES-', $openAction['title']);
        $this->assertSame('RES-2026-004', $openAction['source']['reference']);
        $this->assertSame("/governance/meetings/{$meeting->id}?tab=resolutions&paper={$open->id}", $openAction['action_url']);
        $this->assertSame('Open paper', $openAction['action_label']);
        $this->assertSame('Draft resolution: Adopt the updated delegations', $chairActions->get("resolution:{$draft->id}")['title']);

        // An ordinary member never sees open/close voting work.
        $member = $this->createUserWithRole('board_member');
        $this->createBoardMember($member);
        $memberActions = collect($service->dashboardWorkflow($member)['actions']);
        $this->assertFalse($memberActions->contains(fn (array $a) => str_starts_with($a['id'], 'resolution:')));

        // With the register permissions, the member sees plain, code-free priorities.
        $byId = $memberActions->keyBy('id');
        $riskAction = $byId->get("risk:{$risk->id}");
        $this->assertSame("Risk above the board's limit: SECRET staffing ratios risk", $riskAction['title']);
        $this->assertStringContainsString("the board's limit is 10", $riskAction['detail']);
        $this->assertSame('R-2026-003', $riskAction['source']['reference']);
        $this->assertStringStartsWith('Requirement due ', $byId->get("compliance:{$obligation->id}")['title']);
        $this->assertStringContainsString('Charities Act', $byId->get("compliance:{$obligation->id}")['detail']);
        $this->assertSame('Budget waiting for the board: SECRET operating budget', $byId->get("budget:{$budget->id}:proposed")['title']);
        $this->assertStringContainsString('$85,000', $byId->get("budget:{$budget->id}:proposed")['detail']);
        $this->assertStringContainsString('Policy review due', $byId->get("policy:{$policy->id}:review")['title']);

        // Without them, nothing from those registers is counted, titled or linked.
        $restricted = $this->createUserWithRole('board_member');
        $this->createBoardMember($restricted);
        foreach (['governance.risks.view', 'governance.compliance.view', 'governance.budgets.view', 'governance.policies.view'] as $key) {
            $this->denyPermission($restricted, $key);
        }

        $restrictedWorkflow = $service->dashboardWorkflow($restricted);
        $ids = collect($restrictedWorkflow['actions'])->pluck('id');
        foreach (['risk:', 'compliance:', 'budget', 'policy:'] as $prefix) {
            $this->assertFalse($ids->contains(fn (string $id) => str_starts_with($id, $prefix)), "{$prefix} priorities leaked");
        }
        $this->assertSame(0, $restrictedWorkflow['summary']['by_tab']['risks']);
        $this->assertSame(0, $restrictedWorkflow['summary']['by_tab']['compliance']);
        $this->assertSame(0, $restrictedWorkflow['summary']['by_tab']['policies']);
        $this->assertStringNotContainsString('SECRET', json_encode($restrictedWorkflow));
    }

    /** "Record who attended" can only be done once the meeting is under way. */
    public function test_attendance_priority_is_only_raised_on_or_after_the_meeting_day(): void
    {
        $chair = $this->createAdminUser();
        $member = $this->createUserWithRole('board_member');
        $this->createBoardMember($member);

        $nextWeek = $this->createMeeting($chair, ['title' => 'Planning day', 'scheduled_at' => now()->addDays(5)]);
        $yesterday = $this->createMeeting($chair, ['title' => 'Budget workshop', 'scheduled_at' => now()->subDay()]);

        $actions = collect(app(GovernanceWorkflowService::class)->dashboardWorkflow($chair)['actions'])->keyBy('id');

        $this->assertFalse($actions->has("meeting:{$nextWeek->id}:quorum"));

        $quorum = $actions->get("meeting:{$yesterday->id}:quorum");
        $this->assertNotNull($quorum);
        $this->assertSame('Record who attended Budget workshop', $quorum['title']);
        $this->assertStringContainsString('members needed for decisions to be valid', $quorum['detail']);
        $this->assertSame('Record attendance', $quorum['action_label']);
    }

    /**
     * Regression: an invited member who has replied, with no board pack yet
     * and an open resolution, used to hit a missing ResolutionVote class.
     */
    public function test_member_checklist_next_step_asks_for_votes_until_the_member_has_voted(): void
    {
        $chair = $this->createAdminUser();
        $member = $this->createUserWithRole('board_member');
        $boardMember = $this->createBoardMember($member);
        $meeting = $this->createMeeting($chair, ['scheduled_at' => now()->addDays(5)]);

        MeetingRsvp::create([
            'governance_meeting_id' => $meeting->id,
            'board_member_id' => $boardMember->id,
            'response' => 'accepted',
            'responded_at' => now(),
        ]);
        $resolution = $this->createResolution($chair, [
            'governance_meeting_id' => $meeting->id,
            'status' => 'open',
            'deadline' => now()->addDays(4),
        ]);

        $service = app(GovernanceWorkflowService::class);

        $before = $service->meetingChecklist($meeting->fresh(), $member);
        $this->assertSame('vote_resolutions', $before['next_step']['key']);
        $this->assertSame('Vote', $before['next_step']['action_label']);

        Vote::create([
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
            'vote' => 'for',
            'voted_at' => now(),
            'voting_method' => 'electronic',
            'recorded_by' => $member->id,
        ]);

        $after = $service->meetingChecklist($meeting->fresh(), $member);
        $this->assertNotSame('vote_resolutions', $after['next_step']['key'] ?? null);
    }

    private function denyPermission(User $user, string $key): void
    {
        $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key]);
        $user->permissionOverrides()->attach($permission->id, ['allowed' => false]);
    }
}
