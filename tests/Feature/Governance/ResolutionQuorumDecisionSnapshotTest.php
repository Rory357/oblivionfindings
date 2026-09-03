<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Services\VotingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class ResolutionQuorumDecisionSnapshotTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    private VotingService $votingService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGovernance();
        $this->votingService = app(VotingService::class);
    }

    public function test_meeting_resolution_with_quorum_met_and_majority_carries_and_locks_snapshot(): void
    {
        $admin = $this->createAdminUser();

        // 4 active board members
        $u1 = $this->createUserWithRole('board_member');
        $m1 = $this->createBoardMember($u1);
        $u2 = $this->createUserWithRole('board_member');
        $m2 = $this->createBoardMember($u2);
        $u3 = $this->createUserWithRole('board_member');
        $m3 = $this->createBoardMember($u3);
        $u4 = $this->createUserWithRole('board_member');
        $m4 = $this->createBoardMember($u4);

        $meeting = $this->createMeeting($admin, [
            'quorum_required' => 50, // 50% of 4 = 2 required
        ]);

        // 3 present, 1 apology
        $meeting->attendances()->createMany([
            ['board_member_id' => $m1->id, 'status' => 'present', 'marked_at' => now(), 'marked_by' => $admin->id],
            ['board_member_id' => $m2->id, 'status' => 'present', 'marked_at' => now(), 'marked_by' => $admin->id],
            ['board_member_id' => $m3->id, 'status' => 'present', 'marked_at' => now(), 'marked_by' => $admin->id],
            ['board_member_id' => $m4->id, 'status' => 'apology', 'marked_at' => now(), 'marked_by' => $admin->id],
        ]);

        $resolution = $this->createResolution($admin, [
            'governance_meeting_id' => $meeting->id,
            'status' => 'open',
            'quorum_required' => true,
            'voting_threshold' => 'simple_majority',
        ]);

        // Votes: 2 for, 1 against
        $this->votingService->castVote($resolution, $m1, 'for');
        $this->votingService->castVote($resolution, $m2, 'for');
        $this->votingService->castVote($resolution, $m3, 'against');

        $this->votingService->closeVoting($resolution, 'Passed by majority at meeting');

        $resolution->refresh();

        $this->assertSame('closed', $resolution->status);
        $this->assertSame('carried', $resolution->outcome);

        $snapshot = $resolution->vote_summary['decision_snapshot'] ?? null;
        $this->assertNotNull($snapshot, 'Decision snapshot must be saved in vote_summary');
        $this->assertTrue($snapshot['quorum_met']);
        $this->assertSame('simple_majority', $snapshot['threshold']);
        $this->assertSame(2, $snapshot['vote_summary']['for']);
        $this->assertSame(1, $snapshot['vote_summary']['against']);
        $this->assertCount(3, $snapshot['individual_votes']);
        $this->assertSame('meeting', $snapshot['quorum_details']['resolution_mode']);
        $this->assertSame(3, $snapshot['quorum_details']['present']);
        $this->assertSame(2, $snapshot['quorum_details']['required']);
    }

    public function test_meeting_resolution_with_quorum_unmet_is_defeated_even_if_all_votes_are_for(): void
    {
        $admin = $this->createAdminUser();

        $u1 = $this->createUserWithRole('board_member');
        $m1 = $this->createBoardMember($u1);
        $u2 = $this->createUserWithRole('board_member');
        $m2 = $this->createBoardMember($u2);
        $u3 = $this->createUserWithRole('board_member');
        $m3 = $this->createBoardMember($u3);
        $u4 = $this->createUserWithRole('board_member');
        $m4 = $this->createBoardMember($u4);

        $meeting = $this->createMeeting($admin, [
            'quorum_required' => 75, // 75% of 4 = 3 required
        ]);

        // Only 1 present (quorum not met)
        $meeting->attendances()->createMany([
            ['board_member_id' => $m1->id, 'status' => 'present', 'marked_at' => now(), 'marked_by' => $admin->id],
            ['board_member_id' => $m2->id, 'status' => 'apology', 'marked_at' => now(), 'marked_by' => $admin->id],
            ['board_member_id' => $m3->id, 'status' => 'absent', 'marked_at' => now(), 'marked_by' => $admin->id],
            ['board_member_id' => $m4->id, 'status' => 'apology', 'marked_at' => now(), 'marked_by' => $admin->id],
        ]);

        $resolution = $this->createResolution($admin, [
            'governance_meeting_id' => $meeting->id,
            'status' => 'open',
            'quorum_required' => true,
            'voting_threshold' => 'simple_majority',
        ]);

        // 1 unanimous 'for' vote
        $this->votingService->castVote($resolution, $m1, 'for');

        $this->votingService->closeVoting($resolution, 'Attempted close without meeting quorum');

        $resolution->refresh();

        // Must be defeated because quorum was not met
        $this->assertSame('closed', $resolution->status);
        $this->assertSame('defeated', $resolution->outcome);

        $snapshot = $resolution->vote_summary['decision_snapshot'] ?? null;
        $this->assertNotNull($snapshot);
        $this->assertFalse($snapshot['quorum_met']);
        $this->assertSame('defeated', $snapshot['outcome']);
        $this->assertSame(1, $snapshot['quorum_details']['present']);
        $this->assertSame(3, $snapshot['quorum_details']['required']);
    }

    public function test_out_of_session_resolution_quorum_evaluated_by_active_member_participation(): void
    {
        $admin = $this->createAdminUser();

        $u1 = $this->createUserWithRole('board_member');
        $m1 = $this->createBoardMember($u1);
        $u2 = $this->createUserWithRole('board_member');
        $m2 = $this->createBoardMember($u2);
        $u3 = $this->createUserWithRole('board_member');
        $m3 = $this->createBoardMember($u3);
        $u4 = $this->createUserWithRole('board_member');
        $m4 = $this->createBoardMember($u4);

        // Out-of-session written resolution (no meeting)
        $resolution = $this->createResolution($admin, [
            'governance_meeting_id' => null,
            'status' => 'open',
            'quorum_required' => true,
            'voting_threshold' => 'simple_majority',
        ]);

        // 3 out of 4 members participate (75% > 50% quorum required)
        $this->votingService->castVote($resolution, $m1, 'for');
        $this->votingService->castVote($resolution, $m2, 'for');
        $this->votingService->castVote($resolution, $m3, 'against');

        $this->votingService->closeVoting($resolution, 'Closed circular resolution');

        $resolution->refresh();

        $this->assertSame('carried', $resolution->outcome);
        $snapshot = $resolution->vote_summary['decision_snapshot'];
        $this->assertTrue($snapshot['quorum_met']);
        $this->assertSame('out_of_session', $snapshot['quorum_details']['resolution_mode']);
        $this->assertSame(3, $snapshot['quorum_details']['present']);
        $this->assertSame(2, $snapshot['quorum_details']['required']);
    }

    public function test_out_of_session_resolution_fails_quorum_when_participation_insufficient(): void
    {
        $admin = $this->createAdminUser();

        $u1 = $this->createUserWithRole('board_member');
        $m1 = $this->createBoardMember($u1);
        $u2 = $this->createUserWithRole('board_member');
        $m2 = $this->createBoardMember($u2);
        $u3 = $this->createUserWithRole('board_member');
        $m3 = $this->createBoardMember($u3);
        $u4 = $this->createUserWithRole('board_member');
        $m4 = $this->createBoardMember($u4);

        $resolution = $this->createResolution($admin, [
            'governance_meeting_id' => null,
            'status' => 'open',
            'quorum_required' => true,
            'voting_threshold' => 'simple_majority',
        ]);

        // Only 1 out of 4 members votes (25% < 50% required participation)
        $this->votingService->castVote($resolution, $m1, 'for');

        $this->votingService->closeVoting($resolution, 'Closed with insufficient participation');

        $resolution->refresh();

        $this->assertSame('defeated', $resolution->outcome);
        $snapshot = $resolution->vote_summary['decision_snapshot'];
        $this->assertFalse($snapshot['quorum_met']);
        $this->assertSame(1, $snapshot['quorum_details']['present']);
        $this->assertSame(2, $snapshot['quorum_details']['required']);
    }

    public function test_conflict_declaration_counts_towards_participation_and_is_locked_in_snapshot(): void
    {
        $admin = $this->createAdminUser();

        $u1 = $this->createUserWithRole('board_member');
        $m1 = $this->createBoardMember($u1);
        $u2 = $this->createUserWithRole('board_member');
        $m2 = $this->createBoardMember($u2);

        $meeting = $this->createMeeting($admin, [
            'quorum_required' => 50,
        ]);

        $meeting->attendances()->createMany([
            ['board_member_id' => $m1->id, 'status' => 'present', 'marked_at' => now(), 'marked_by' => $admin->id],
            ['board_member_id' => $m2->id, 'status' => 'present', 'marked_at' => now(), 'marked_by' => $admin->id],
        ]);

        $resolution = $this->createResolution($admin, [
            'governance_meeting_id' => $meeting->id,
            'status' => 'open',
            'quorum_required' => true,
            'voting_threshold' => 'simple_majority',
        ]);

        // Member 1 votes for
        $this->votingService->castVote($resolution, $m1, 'for');

        // Member 2 declares conflict and withdraws
        $this->votingService->declareConflict(
            $resolution,
            $m2,
            'material',
            'Financial interest in contract vendor',
            $admin,
            withdrawFromVoting: true
        );

        $this->votingService->closeVoting($resolution, 'Closed with conflict withdrawal');

        $resolution->refresh();

        // 2 active members, 1 vote + 1 conflict. 1 for, 0 against -> 100% for.
        $this->assertSame('carried', $resolution->outcome);

        $snapshot = $resolution->vote_summary['decision_snapshot'];
        $this->assertTrue($snapshot['quorum_met']);
        $this->assertSame(1, $snapshot['vote_summary']['conflicts']);
        $this->assertCount(1, $snapshot['conflicts']);
        $this->assertSame('material', $snapshot['conflicts'][0]['type']);
        $this->assertTrue($snapshot['conflicts'][0]['withdrew_from_voting']);
    }

    public function test_two_thirds_threshold_enforced_strictly(): void
    {
        $admin = $this->createAdminUser();

        $u1 = $this->createUserWithRole('board_member');
        $m1 = $this->createBoardMember($u1);
        $u2 = $this->createUserWithRole('board_member');
        $m2 = $this->createBoardMember($u2);
        $u3 = $this->createUserWithRole('board_member');
        $m3 = $this->createBoardMember($u3);

        $resolution = $this->createResolution($admin, [
            'governance_meeting_id' => null,
            'status' => 'open',
            'quorum_required' => true,
            'voting_threshold' => 'two_thirds',
        ]);

        // 3 members participate (quorum met). 2 for, 1 against = 66.67% >= 2/3 (passes)
        $this->votingService->castVote($resolution, $m1, 'for');
        $this->votingService->castVote($resolution, $m2, 'for');
        $this->votingService->castVote($resolution, $m3, 'against');

        $this->votingService->closeVoting($resolution);
        $resolution->refresh();
        $this->assertSame('carried', $resolution->outcome);

        // Another resolution with 1 for, 1 against = 50% < 66.7% (fails)
        $res2 = $this->createResolution($admin, [
            'governance_meeting_id' => null,
            'status' => 'open',
            'quorum_required' => true,
            'voting_threshold' => 'two_thirds',
        ]);

        $this->votingService->castVote($res2, $m1, 'for');
        $this->votingService->castVote($res2, $m2, 'against');
        $this->votingService->closeVoting($res2);
        $res2->refresh();
        $this->assertSame('defeated', $res2->outcome);
    }
}
