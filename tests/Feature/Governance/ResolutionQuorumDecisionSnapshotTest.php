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
        $this->assertSame(3, $snapshot['quorum_details']['required']);
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

        // Must be no_quorum because quorum was not met
        $this->assertSame('closed', $resolution->status);
        $this->assertSame('no_quorum', $resolution->outcome);

        $snapshot = $resolution->vote_summary['decision_snapshot'] ?? null;
        $this->assertNotNull($snapshot);
        $this->assertFalse($snapshot['quorum_met']);
        $this->assertSame('no_quorum', $snapshot['outcome']);
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
        $this->assertSame(3, $snapshot['quorum_details']['required']);
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

        $this->assertSame('no_quorum', $resolution->outcome);
        $snapshot = $resolution->vote_summary['decision_snapshot'];
        $this->assertFalse($snapshot['quorum_met']);
        $this->assertSame('no_quorum', $snapshot['outcome']);
        $this->assertSame(1, $snapshot['quorum_details']['present']);
        $this->assertSame(3, $snapshot['quorum_details']['required']);
    }

    public function test_conflict_declaration_excludes_from_presence_and_is_locked_in_snapshot(): void
    {
        $admin = $this->createAdminUser();

        $u1 = $this->createUserWithRole('board_member');
        $m1 = $this->createBoardMember($u1);
        $u2 = $this->createUserWithRole('board_member');
        $m2 = $this->createBoardMember($u2);
        $u3 = $this->createUserWithRole('board_member');
        $m3 = $this->createBoardMember($u3);

        $meeting = $this->createMeeting($admin, [
            'quorum_required' => 50,
        ]);

        $meeting->attendances()->createMany([
            ['board_member_id' => $m1->id, 'status' => 'present', 'marked_at' => now(), 'marked_by' => $admin->id],
            ['board_member_id' => $m2->id, 'status' => 'present', 'marked_at' => now(), 'marked_by' => $admin->id],
            ['board_member_id' => $m3->id, 'status' => 'present', 'marked_at' => now(), 'marked_by' => $admin->id],
        ]);

        $resolution = $this->createResolution($admin, [
            'governance_meeting_id' => $meeting->id,
            'status' => 'open',
            'quorum_required' => true,
            'voting_threshold' => 'simple_majority',
        ]);

        // Members 1 and 2 vote for (quorum floor(3/2)+1 = 2 met)
        $this->votingService->castVote($resolution, $m1, 'for');
        $this->votingService->castVote($resolution, $m2, 'for');

        // Member 3 declares conflict and withdraws (excluded from quorum presence)
        $this->votingService->declareConflict(
            $resolution,
            $m3,
            'material',
            'Financial interest in contract vendor',
            $admin,
            withdrawFromVoting: true
        );

        $this->votingService->closeVoting($resolution, 'Closed with conflict withdrawal');

        $resolution->refresh();

        // 3 active members, 2 non-recused present voters >= 2 required. 2 for, 0 against -> carried.
        $this->assertSame('carried', $resolution->outcome);

        $snapshot = $resolution->vote_summary['decision_snapshot'];
        $this->assertTrue($snapshot['quorum_met']);
        $this->assertSame(2, $snapshot['quorum_details']['present']);
        $this->assertSame(2, $snapshot['quorum_details']['required']);
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

    public function test_resolution_tie_is_defeated_only_after_quorum_is_met(): void
    {
        $admin = $this->createAdminUser();

        // 4 active board members: N=4, quorum required = floor(4/2)+1 = 3
        $u1 = $this->createUserWithRole('board_member');
        $m1 = $this->createBoardMember($u1);
        $u2 = $this->createUserWithRole('board_member');
        $m2 = $this->createBoardMember($u2);
        $u3 = $this->createUserWithRole('board_member');
        $m3 = $this->createBoardMember($u3);
        $u4 = $this->createUserWithRole('board_member');
        $m4 = $this->createBoardMember($u4);

        // Case A: 4 members vote: 2 for, 2 against.
        // Quorum is MET (4 >= 3).
        // Since for (50%) <= 0.5, resolution is DEFEATED after valid quorum.
        $resMet = $this->createResolution($admin, [
            'governance_meeting_id' => null,
            'status' => 'open',
            'quorum_required' => true,
            'voting_threshold' => 'simple_majority',
        ]);
        $this->votingService->castVote($resMet, $m1, 'for');
        $this->votingService->castVote($resMet, $m2, 'for');
        $this->votingService->castVote($resMet, $m3, 'against');
        $this->votingService->castVote($resMet, $m4, 'against');
        $this->votingService->closeVoting($resMet);

        $resMet->refresh();
        $this->assertSame('closed', $resMet->status);
        $this->assertSame('defeated', $resMet->outcome);
        $this->assertTrue($resMet->vote_summary['decision_snapshot']['quorum_met']);

        // Case B: Only 2 members vote: 1 for, 1 against.
        // Quorum is NOT MET (2 < 3).
        // Outcome must be NO_QUORUM ("No valid decision — quorum not met"), not defeated!
        $resUnmet = $this->createResolution($admin, [
            'governance_meeting_id' => null,
            'status' => 'open',
            'quorum_required' => true,
            'voting_threshold' => 'simple_majority',
        ]);
        $this->votingService->castVote($resUnmet, $m1, 'for');
        $this->votingService->castVote($resUnmet, $m2, 'against');
        $this->votingService->closeVoting($resUnmet);

        $resUnmet->refresh();
        $this->assertSame('closed', $resUnmet->status);
        $this->assertSame('no_quorum', $resUnmet->outcome);
        $this->assertFalse($resUnmet->vote_summary['decision_snapshot']['quorum_met']);
    }
}
