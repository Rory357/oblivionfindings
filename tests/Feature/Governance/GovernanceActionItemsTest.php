<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\VotingService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class GovernanceActionItemsTest extends TestCase
{
    use RefreshDatabase;
    use GovernanceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
    }

    protected function createBoardMemberUser(array $overrides = []): User
    {
        $user = $this->createUserWithRole('board_member', $overrides);
        $this->createBoardMember($user);

        return $user;
    }

    public function test_admin_can_view_action_items(): void
    {
        $admin = $this->createAdminUser();
        $action = $this->createActionItem($admin, $admin);

        $indexResponse = $this->actingAs($admin)->get('/governance/actions');
        $indexResponse->assertOk();
        $indexResponse->assertInertia(fn ($page) => $page
            ->component('Governance/Actions/Index')
        );

        $showResponse = $this->actingAs($admin)->get("/governance/actions/{$action->id}");
        $showResponse->assertOk();
        $showResponse->assertInertia(fn ($page) => $page
            ->component('Governance/Actions/Show')
        );
    }

    public function test_carried_resolution_close_creates_canonical_source_linked_actions_atomically_once(): void
    {
        $admin = $this->createAdminUser();
        $member1 = $this->createBoardMemberUser();
        $member2 = $this->createBoardMemberUser();
        $meeting = $this->createMeeting($admin);
        $meeting->attendances()->createMany([
            ['board_member_id' => $member1->boardMember->id, 'status' => 'present', 'marked_at' => now(), 'marked_by' => $admin->id],
            ['board_member_id' => $member2->boardMember->id, 'status' => 'present', 'marked_at' => now(), 'marked_by' => $admin->id],
        ]);

        $resolution = Resolution::create([
            'title' => 'Equipment Modernisation',
            'exact_motion' => 'That the board approves modernising plant equipment',
            'purpose' => 'decision',
            'context' => 'Aging infrastructure requires renewal',
            'options' => [
                ['label' => 'Renew', 'benefits' => 'Efficiency', 'drawbacks' => 'Capital cost'],
                ['label' => 'Defer', 'benefits' => 'Conserves cash', 'drawbacks' => 'Downtime risk'],
            ],
            'recommendation' => 'Renew equipment',
            'cost_impact' => ['amount' => 50000, 'funding_source' => 'Capex Reserve', 'is_none' => false],
            'risk_impact' => ['level' => 'low', 'description' => 'Managed operational risk', 'is_none' => false],
            'status' => 'draft',
            'voting_threshold' => 'simple_majority',
            'governance_meeting_id' => $meeting->id,
            'proposed_by' => $admin->id,
            'proposed_at' => now(),
            'auto_generate_actions' => true,
            'follow_up_actions' => [
                [
                    'title' => 'Procure safety valves',
                    'description' => 'Install safety valves across plant',
                    'assigned_to' => $admin->id,
                    'due_date' => now()->addWeeks(2)->toDateString(),
                    'priority' => 'high',
                    'evidence_required' => true,
                ],
                [
                    'title' => 'Update SOP manual',
                    'description' => 'Revise SOP-102 with new valve procedures',
                    'assigned_to' => $member1->id,
                    'due_date' => now()->addWeeks(3)->toDateString(),
                    'priority' => 'medium',
                    'evidence_required' => false,
                ],
            ],
        ]);

        $votingService = app(VotingService::class);
        $votingService->openVoting($resolution);

        // Cast votes to carry resolution
        $votingService->castVote($resolution, $member1->boardMember, 'for');
        $votingService->castVote($resolution, $member2->boardMember, 'for');

        // Close voting
        $votingService->closeVoting($resolution);

        $resolution->refresh();
        $this->assertSame('closed', $resolution->status);
        $this->assertSame('carried', $resolution->outcome);

        // Assert exactly 2 canonical action items created
        $actions = ActionItem::where('source_type', 'resolution')
            ->where('source_id', $resolution->id)
            ->get();

        $this->assertCount(2, $actions);
        $this->assertTrue($actions->contains('title', 'Procure safety valves'));
        $this->assertTrue($actions->contains('title', 'Update SOP manual'));

        // Idempotency: calling generateActionItems again does not create duplicates
        $resolution->generateActionItems();
        $this->assertSame(2, ActionItem::where('source_type', 'resolution')->where('source_id', $resolution->id)->count());
    }

    public function test_defeated_or_no_quorum_resolution_close_creates_no_action_items(): void
    {
        $admin = $this->createAdminUser();
        $member1 = $this->createBoardMemberUser();
        $meeting = $this->createMeeting($admin);
        $meeting->attendances()->create([
            'board_member_id' => $member1->boardMember->id,
            'status' => 'present',
            'marked_at' => now(),
            'marked_by' => $admin->id,
        ]);

        $resolution = Resolution::create([
            'title' => 'Rejected Initiative',
            'exact_motion' => 'That the board approves rejected initiative',
            'purpose' => 'decision',
            'context' => 'Testing defeated outcome',
            'options' => [
                ['label' => 'Option A', 'benefits' => 'None', 'drawbacks' => 'Too costly'],
                ['label' => 'Option B', 'benefits' => 'Status quo', 'drawbacks' => 'None'],
            ],
            'recommendation' => 'Option A',
            'cost_impact' => ['amount' => 10000, 'funding_source' => 'Opex', 'is_none' => false],
            'risk_impact' => ['level' => 'low', 'description' => 'Negligible risk', 'is_none' => false],
            'status' => 'draft',
            'voting_threshold' => 'simple_majority',
            'governance_meeting_id' => $meeting->id,
            'proposed_by' => $admin->id,
            'proposed_at' => now(),
            'auto_generate_actions' => true,
            'follow_up_actions' => [
                [
                    'title' => 'Should never be created',
                    'description' => 'This action must not exist',
                    'assigned_to' => $admin->id,
                    'due_date' => now()->addWeeks(1)->toDateString(),
                ],
            ],
        ]);

        $votingService = app(VotingService::class);
        $votingService->openVoting($resolution);

        // Cast against vote
        $votingService->castVote($resolution, $member1->boardMember, 'against');

        $votingService->closeVoting($resolution);

        $resolution->refresh();
        $this->assertSame('closed', $resolution->status);
        $this->assertSame('defeated', $resolution->outcome);

        // No action items created
        $this->assertSame(0, ActionItem::where('source_type', 'resolution')->where('source_id', $resolution->id)->count());
    }

    public function test_progress_100_alone_does_not_close_action_item(): void
    {
        $admin = $this->createAdminUser();
        $action = $this->createActionItem($admin, $admin, [
            'status' => 'open',
            'progress_pct' => 50,
            'version_number' => 1,
        ]);

        // Setting progress to 100% must update progress_pct without changing status to complete
        $response = $this->actingAs($admin)->post("/governance/actions/{$action->id}/progress", [
            'progress_pct' => 100,
            'progress_notes' => 'All milestones finished, pending final sign-off notes',
            'expected_version' => 1,
        ]);

        $response->assertRedirect();
        $action->refresh();

        $this->assertSame(100, $action->progress_pct);
        $this->assertSame('in_progress', $action->status);
        $this->assertNull($action->completed_at);
        $this->assertNull($action->completion_receipt);
    }

    public function test_completion_requires_notes_and_evidence_when_evidence_required(): void
    {
        $admin = $this->createAdminUser();

        // 1. Action with evidence_required = true
        $action = $this->createActionItem($admin, $admin, [
            'status' => 'in_progress',
            'evidence_required' => true,
            'version_number' => 1,
        ]);

        // Attempt completion without evidence fails
        $failResponse = $this->actingAs($admin)->post("/governance/actions/{$action->id}/complete", [
            'completion_notes' => 'Finished the task',
            'evidence_files' => [],
            'expected_version' => 1,
        ]);
        $failResponse->assertRedirect();
        $failResponse->assertSessionHas('error');
        $action->refresh();
        $this->assertNotSame('complete', $action->status);

        // Attempt completion without notes fails validation
        $emptyNotesResponse = $this->actingAs($admin)->post("/governance/actions/{$action->id}/complete", [
            'completion_notes' => '',
            'evidence_files' => ['signoff.pdf'],
            'expected_version' => 1,
        ]);
        $emptyNotesResponse->assertSessionHasErrors(['completion_notes']);

        // Completion with both notes and evidence succeeds
        \Illuminate\Support\Facades\Storage::disk('local')->put('valve-commissioning-certificate.pdf', 'evidence content');
        $successResponse = $this->actingAs($admin)->post("/governance/actions/{$action->id}/complete", [
            'completion_notes' => 'Fully commissioned and verified against standards',
            'evidence_files' => ['valve-commissioning-certificate.pdf'],
            'expected_version' => 1,
        ]);
        $successResponse->assertRedirect();
        $action->refresh();

        $this->assertSame('complete', $action->status);
        $this->assertSame($admin->id, $action->completed_by);
        $this->assertNotNull($action->completed_at);
        $this->assertNotNull($action->completion_receipt);
        $this->assertStringStartsWith('ACT-REC-', $action->completion_receipt);
        $this->assertCount(1, $action->evidence_attachments);

        // Idempotent duplicate complete returns same receipt
        $receipt = $action->completion_receipt;
        $dupReceipt = $action->markComplete($admin->id, 'Another call', ['extra.pdf']);
        $this->assertSame($receipt, $dupReceipt);
    }

    public function test_reassignment_and_mutation_rejects_stale_concurrency_mismatch(): void
    {
        $admin = $this->createAdminUser();
        $otherUser = $this->createBoardMemberUser();

        $action = $this->createActionItem($admin, $admin, [
            'status' => 'open',
            'version_number' => 2,
        ]);

        // Submitting with stale expected_version=1 returns 409 Conflict
        $response = $this->actingAs($admin)->post("/governance/actions/{$action->id}/reassign", [
            'assigned_to' => $otherUser->id,
            'expected_version' => 1,
        ]);
        $response->assertStatus(409);

        // Submitting with matching expected_version=2 succeeds
        $successResponse = $this->actingAs($admin)->post("/governance/actions/{$action->id}/reassign", [
            'assigned_to' => $otherUser->id,
            'expected_version' => 2,
        ]);
        $successResponse->assertRedirect();
        $action->refresh();
        $this->assertSame($otherUser->id, $action->assigned_to);
        $this->assertSame(3, $action->version_number);
    }

    public function test_private_source_resolution_denies_unauthorized_user(): void
    {
        $admin = $this->createAdminUser();
        $member = $this->createBoardMemberUser();
        $executiveMeeting = $this->createMeeting($admin, ['meeting_type' => 'executive_session']);

        $resolution = Resolution::create([
            'title' => 'Restricted Executive Deliberation',
            'exact_motion' => 'Executive confidential motion',
            'purpose' => 'decision',
            'context' => 'Highly confidential matter',
            'options' => [['label' => 'Approve', 'benefits' => 'Yes', 'drawbacks' => 'No'], ['label' => 'Reject', 'benefits' => 'No', 'drawbacks' => 'Yes']],
            'recommendation' => 'Approve',
            'status' => 'closed',
            'outcome' => 'carried',
            'voting_threshold' => 'simple_majority',
            'governance_meeting_id' => $executiveMeeting->id,
            'proposed_by' => $admin->id,
            'proposed_at' => now(),
        ]);

        $action = $this->createActionItem($admin, $admin, [
            'source_type' => 'resolution',
            'source_id' => $resolution->id,
            'status' => 'open',
        ]);

        // Unauthorized member (not assigned, not authorized to view executive session) is denied
        $response = $this->actingAs($member)->get("/governance/actions/{$action->id}");
        $response->assertStatus(403);
    }

    public function test_resolution_mark_implemented_requires_all_actions_complete_or_authorised_no_action_reason(): void
    {
        $admin = $this->createAdminUser();
        $meeting = $this->createMeeting($admin);

        $resolution = Resolution::create([
            'title' => 'Facility Upgrade',
            'exact_motion' => 'Upgrade facility facilities',
            'purpose' => 'decision',
            'context' => 'Plant maintenance',
            'options' => [['label' => 'Go', 'benefits' => 'Good', 'drawbacks' => 'Cost'], ['label' => 'No', 'benefits' => 'Save', 'drawbacks' => 'Rust']],
            'recommendation' => 'Go',
            'status' => 'closed',
            'outcome' => 'carried',
            'voting_threshold' => 'simple_majority',
            'governance_meeting_id' => $meeting->id,
            'proposed_by' => $admin->id,
            'proposed_at' => now(),
        ]);

        $action = $this->createActionItem($admin, $admin, [
            'source_type' => 'resolution',
            'source_id' => $resolution->id,
            'status' => 'open',
        ]);

        // Attempting to finalize as implemented while follow-up action is open fails
        $failResponse = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/finalize", [
            'status' => 'implemented',
            'notes' => 'Attempting premature implementation',
        ]);
        $failResponse->assertRedirect();
        $failResponse->assertSessionHas('error');
        $resolution->refresh();
        $this->assertNotSame('implemented', $resolution->status);

        // Sponsoring an authorised no_action_reason allows implementation even with uncompleted actions
        $authNoActionResponse = $this->actingAs($admin)->post("/governance/resolutions/{$resolution->id}/finalize", [
            'status' => 'implemented',
            'no_action_reason' => 'Facility decommissioned; remaining actions superseded',
        ]);
        $authNoActionResponse->assertRedirect();
        $resolution->refresh();
        $this->assertSame('implemented', $resolution->status);
        $this->assertStringContainsString('Facility decommissioned', $resolution->outcome_notes);
    }
}
