<?php

namespace Tests\Unit\Governance;

use App\Domain\Governance\Services\VotingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class VotingServiceTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    public function test_open_and_cast_vote(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'draft',
        ]);

        $service = new VotingService;
        $service->openVoting($resolution, now()->addDays(2));

        $resolution->refresh();
        $this->assertEquals('open', $resolution->status);

        $vote = $service->castVote($resolution, $boardMember, 'for');
        $this->assertEquals('for', $vote->vote);
        $this->assertDatabaseHas('votes', [
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
        ]);
    }

    public function test_cast_vote_prevents_duplicates(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->addDays(2),
        ]);

        $service = new VotingService;
        $service->castVote($resolution, $boardMember, 'for');

        $this->expectException(\InvalidArgumentException::class);
        $service->castVote($resolution, $boardMember, 'against');
    }

    public function test_declare_conflict_with_withdrawal_does_not_create_abstain_vote(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $meeting = $this->createMeeting($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->addDays(5),
            'governance_meeting_id' => $meeting->id,
        ]);

        $service = new VotingService;
        $conflict = $service->declareConflict(
            $resolution,
            $boardMember,
            'material',
            'Conflict note description that is sufficient length',
            $admin,
            true,
            false
        );

        $this->assertDatabaseHas('conflict_declarations', [
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
            'withdrew_from_voting' => 1,
        ]);

        // Recusal must NOT inject an abstention vote into votes table!
        $this->assertDatabaseMissing('votes', [
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
        ]);
    }

    public function test_calculate_quorum_with_null_meeting(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $this->createBoardMember($admin);

        $service = new VotingService;
        $quorum = $service->calculateQuorum(null);

        $this->assertEquals(1, $quorum['total_eligible']);
        $this->assertTrue($quorum['met']);
    }

    public function test_quorum_formula_candidate_examples_n_0_1_4_5(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $service = new VotingService;

        // Case N=0: No board members exist
        \App\Domain\Governance\Models\BoardMember::query()->forceDelete();
        $res0 = $this->createResolution($admin, ['status' => 'open']);
        $q0 = $service->calculateQuorum(null, $res0);
        $this->assertSame(0, $q0['total_eligible']);
        $this->assertSame(0, $q0['required']);
        $this->assertFalse($q0['met'], 'Quorum must NEVER be met when N=0');

        // Case N=1: 1 member. floor(1/2)+1 = 1 required
        $u1 = $this->createUserWithRole('board_member', ['email' => 'm1@example.test']);
        $m1 = $this->createBoardMember($u1);
        $res1 = $this->createResolution($admin, ['status' => 'open']);
        $q1_unvoted = $service->calculateQuorum(null, $res1);
        $this->assertSame(1, $q1_unvoted['total_eligible']);
        $this->assertSame(1, $q1_unvoted['required']);
        $this->assertFalse($q1_unvoted['met']);

        $service->castVote($res1, $m1, 'for');
        $q1_voted = $service->calculateQuorum(null, $res1);
        $this->assertTrue($q1_voted['met']);

        // Case N=4: 4 members. floor(4/2)+1 = 3 required
        $u2 = $this->createUserWithRole('board_member', ['email' => 'm2@example.test']);
        $m2 = $this->createBoardMember($u2);
        $u3 = $this->createUserWithRole('board_member', ['email' => 'm3@example.test']);
        $m3 = $this->createBoardMember($u3);
        $u4 = $this->createUserWithRole('board_member', ['email' => 'm4@example.test']);
        $m4 = $this->createBoardMember($u4);

        $res4 = $this->createResolution($admin, ['status' => 'open']);
        // 2 votes cast: 2 < 3 -> met is false
        $service->castVote($res4, $m1, 'for');
        $service->castVote($res4, $m2, 'against');
        $q4_2votes = $service->calculateQuorum(null, $res4);
        $this->assertSame(4, $q4_2votes['total_eligible']);
        $this->assertSame(3, $q4_2votes['required']);
        $this->assertFalse($q4_2votes['met']);

        // 3 votes cast: 3 >= 3 -> met is true
        $service->castVote($res4, $m3, 'abstain');
        $q4_3votes = $service->calculateQuorum(null, $res4);
        $this->assertTrue($q4_3votes['met']);

        // Case N=5: 5 members. floor(5/2)+1 = 3 required
        $u5 = $this->createUserWithRole('board_member', ['email' => 'm5@example.test']);
        $m5 = $this->createBoardMember($u5);

        $res5 = $this->createResolution($admin, ['status' => 'open']);
        $service->castVote($res5, $m1, 'for');
        $service->castVote($res5, $m2, 'for');
        $q5_2votes = $service->calculateQuorum(null, $res5);
        $this->assertSame(5, $q5_2votes['total_eligible']);
        $this->assertSame(3, $q5_2votes['required']);
        $this->assertFalse($q5_2votes['met']);

        $service->castVote($res5, $m3, 'for');
        $q5_3votes = $service->calculateQuorum(null, $res5);
        $this->assertTrue($q5_3votes['met']);
    }

    public function test_observer_excluded_from_voting_and_quorum(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $observerUser = $this->createUserWithRole('board_observer', ['email' => 'observer@example.test']);
        $observer = $this->createBoardMember($observerUser, [
            'board_role' => 'observer',
            'has_voting_seat' => false,
        ]);

        $this->assertFalse($observer->canVote());

        $resolution = $this->createResolution($admin, ['status' => 'open']);
        $service = new VotingService;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Board member is not eligible to vote');
        $service->castVote($resolution, $observer, 'for');
    }

    public function test_expired_terms_excluded_from_voting_and_quorum(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $expiredUser = $this->createUserWithRole('board_member', ['email' => 'expired@example.test']);
        $expiredMember = $this->createBoardMember($expiredUser, [
            'board_role' => 'member',
            'term_start' => now()->subYears(3),
            'term_end' => now()->subDay(),
        ]);

        $this->assertFalse($expiredMember->canVote());

        $resolution = $this->createResolution($admin, ['status' => 'open']);
        $service = new VotingService;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Board member is not eligible to vote');
        $service->castVote($resolution, $expiredMember, 'for');
    }

    public function test_voting_secretary_versus_administrative_secretary(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();

        // Administrative secretary: no explicit voting seat
        $adminSecUser = $this->createUserWithRole('board_secretary', ['email' => 'admin-sec@example.test']);
        $adminSecretary = $this->createBoardMember($adminSecUser, [
            'board_role' => 'secretary',
            'has_voting_seat' => false,
        ]);
        $this->assertFalse($adminSecretary->canVote());

        // Appointed voting secretary: explicit voting seat
        $votingSecUser = $this->createUserWithRole('board_secretary', ['email' => 'voting-sec@example.test']);
        $votingSecretary = $this->createBoardMember($votingSecUser, [
            'board_role' => 'secretary',
            'has_voting_seat' => true,
        ]);
        $this->assertTrue($votingSecretary->canVote());

        $resolution = $this->createResolution($admin, ['status' => 'open']);
        $service = new VotingService;

        $vote = $service->castVote($resolution, $votingSecretary, 'for');
        $this->assertSame('for', $vote->vote);
    }

    public function test_treasurer_retains_member_voting_entitlement(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();

        $treasurerUser = $this->createUserWithRole('board_member', ['email' => 'treasurer@example.test']);
        $treasurer = $this->createBoardMember($treasurerUser, [
            'board_role' => 'treasurer',
        ]);

        $this->assertTrue($treasurer->isTreasurer());
        $this->assertTrue($treasurer->canVote());

        $resolution = $this->createResolution($admin, ['status' => 'open']);
        $service = new VotingService;

        $vote = $service->castVote($resolution, $treasurer, 'for');
        $this->assertSame('for', $vote->vote);
    }

    public function test_committee_electorate_resolution(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();

        $committee = \App\Domain\Governance\Models\BoardCommittee::create([
            'committee_type' => 'finance',
            'name' => 'Finance Committee',
            'is_active' => true,
        ]);

        $u1 = $this->createUserWithRole('board_member', ['email' => 'comm1@example.test']);
        $m1 = $this->createBoardMember($u1);
        \App\Domain\Governance\Models\CommitteeMembership::create([
            'board_committee_id' => $committee->id,
            'board_member_id' => $m1->id,
            'role' => 'member',
            'has_voting_seat' => true,
            'appointed_at' => now()->subMonth(),
            'is_active' => true,
        ]);

        $u2 = $this->createUserWithRole('board_member', ['email' => 'noncomm@example.test']);
        $m2 = $this->createBoardMember($u2); // Not a member of committee

        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'board_committee_id' => $committee->id,
        ]);

        $service = new VotingService;

        // Committee member can vote
        $vote = $service->castVote($resolution, $m1, 'for');
        $this->assertSame('for', $vote->vote);

        // Non-committee member cannot vote
        try {
            $service->castVote($resolution, $m2, 'for');
            $this->fail('Non-committee member should not be able to vote on committee resolution');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('not an eligible voting member of this committee', $e->getMessage());
        }

        // Quorum calculates strictly from committee membership
        $quorum = $service->calculateQuorum(null, $resolution);
        $this->assertSame(1, $quorum['total_eligible']);
        $this->assertSame(1, $quorum['required']);
        $this->assertTrue($quorum['met']);
    }

    public function test_profile_activation_without_evidence_rejected(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $profileService = app(\App\Domain\Governance\Services\GovernanceVotingProfileService::class);

        $profile = \App\Domain\Governance\Models\GovernanceVotingProfile::create([
            'governing_body' => 'board',
            'legal_form' => 'charitable_trust',
            'governing_document_reference' => 'Candidate Governance Profile (Pending D1 Legal Authority)',
            'is_active' => false,
        ]);

        // Attempting activation with candidate reference is rejected
        try {
            $profileService->activateProfile($profile, $admin, null, 'Candidate Governance Profile (Pending D1 Legal Authority)');
            $this->fail('Activation with candidate reference must be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('actual governing document reference', $e->getMessage());
        }

        // Attempting activation without approval authority is rejected
        try {
            $profileService->activateProfile($profile, $admin, null, 'Trust Deed 2024');
            $this->fail('Activation without approval authority evidence must be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('approval authority evidence', $e->getMessage());
        }

        // Activation with document reference and approval resolution succeeds
        $approvalRes = $this->createResolution($admin, ['status' => 'closed', 'outcome' => 'carried']);
        $activated = $profileService->activateProfile($profile, $admin, $approvalRes, 'Constitution 2024 Adopted');
        $this->assertTrue($activated->is_active);
        $this->assertTrue($activated->isConfirmed());
        $this->assertSame('Constitution 2024 Adopted', $activated->governing_document_reference);
    }

    public function test_unconfirmed_profile_blocks_open_voting(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();

        // Deactivate all active profiles so only unconfirmed candidate profile remains
        \App\Domain\Governance\Models\GovernanceVotingProfile::query()->update(['is_active' => false]);
        \App\Domain\Governance\Models\GovernanceVotingProfile::create([
            'governing_body' => 'board',
            'legal_form' => 'charitable_trust',
            'governing_document_reference' => 'Candidate Profile',
            'is_active' => false,
        ]);

        $resolution = $this->createResolution($admin, ['status' => 'draft']);
        $service = new VotingService;

        // Draft work remains intact
        $this->assertTrue($resolution->isDraft());

        // But opening voting is blocked with clear guidance
        // Let's test that when an unconfirmed profile is linked or active profile is unconfirmed, openVoting throws
        $unconfirmedProfile = \App\Domain\Governance\Models\GovernanceVotingProfile::create([
            'governing_body' => 'board',
            'legal_form' => 'charitable_trust',
            'governing_document_reference' => 'Candidate Profile',
            'is_active' => true,
            'approved_at' => null,
            'approved_by_resolution_id' => null,
        ]);
        $this->assertFalse($unconfirmedProfile->isConfirmed());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Voting rules not confirmed — live voting unavailable');
        $service->openVoting($resolution);
    }

    public function test_cast_vote_idempotent_replay_returns_existing_receipt(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->addDays(2),
        ]);

        $service = new VotingService;
        $firstVote = $service->castVote($resolution, $boardMember, 'for');
        $this->assertEquals('for', $firstVote->vote);

        // Replaying the exact same vote returns the existing receipt without error
        $replayedVote = $service->castVote($resolution, $boardMember, 'for');
        $this->assertEquals($firstVote->id, $replayedVote->id);
        $this->assertEquals('for', $replayedVote->vote);
        $this->assertEquals(1, \App\Domain\Governance\Models\Vote::where('resolution_id', $resolution->id)->count());
    }

    public function test_cast_vote_conflicting_duplicate_throws_exception(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->addDays(2),
        ]);

        $service = new VotingService;
        $service->castVote($resolution, $boardMember, 'for');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Board member has already cast a vote on this resolution');
        $service->castVote($resolution, $boardMember, 'against');
    }

    public function test_recused_member_cannot_cast_vote(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->addDays(2),
        ]);

        $service = new VotingService;
        $service->declareConflict(
            $resolution,
            $boardMember,
            'material',
            'Sufficient length conflict text for validation',
            $admin,
            withdrawFromVoting: true
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Board member has recused and withdrawn from voting on this resolution');
        $service->castVote($resolution, $boardMember, 'for');
    }

    public function test_recusal_removes_prior_vote_if_member_withdraws(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->addDays(2),
        ]);

        $service = new VotingService;
        $service->castVote($resolution, $boardMember, 'for');
        $this->assertDatabaseHas('votes', [
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
        ]);

        // Member declares conflict and withdraws
        $service->declareConflict(
            $resolution,
            $boardMember,
            'material',
            'Discovered conflict after initial vote was submitted',
            $admin,
            withdrawFromVoting: true
        );

        // Prior vote must be cleanly removed so member does not participate in quorum or tally
        $this->assertDatabaseMissing('votes', [
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
        ]);
    }

    public function test_cast_vote_blocks_when_deadline_has_passed(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $boardMember = $this->createBoardMember($admin);
        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->subMinute(),
        ]);

        $service = new VotingService;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Voting deadline has passed');
        $service->castVote($resolution, $boardMember, 'for');
    }

    public function test_mark_implemented_fails_if_resolution_not_carried(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $resolution = $this->createResolution($admin, [
            'status' => 'closed',
            'outcome' => 'no_quorum',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage("Cannot implement resolution with outcome 'no_quorum'");
        $resolution->markImplemented('Attempting to implement unmet quorum');
    }

    public function test_closed_voting_results_use_immutable_snapshot_after_member_removal(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $u1 = $this->createUserWithRole('board_member', ['name' => 'Alice Auditor']);
        $m1 = $this->createBoardMember($u1);
        $u2 = $this->createUserWithRole('board_member', ['name' => 'Bob Trustee']);
        $m2 = $this->createBoardMember($u2);

        $resolution = $this->createResolution($admin, [
            'status' => 'open',
            'deadline' => now()->addDays(5),
            'voting_threshold' => 'simple_majority',
            'quorum_required' => true,
        ]);

        $service = new VotingService;
        $service->castVote($resolution, $m1, 'for');
        $service->castVote($resolution, $m2, 'for');
        $service->closeVoting($resolution, 'Passed unanimously');

        $resolution->refresh();
        $this->assertSame('closed', $resolution->status);
        $this->assertSame('carried', $resolution->outcome);

        // Now remove Bob (e.g. member deleted or term expired)
        $m2->delete();

        // Calling getVotingResults MUST still return Bob's vote from the frozen decision_snapshot
        $results = $service->getVotingResults($resolution);
        $this->assertTrue($results['is_frozen']);
        $this->assertSame(2, $results['summary']['for']);
        $this->assertSame('carried', $results['outcome']);
        $this->assertTrue($results['quorum_met']);

        $names = collect($results['individual_votes'])->pluck('board_member')->all();
        $this->assertContains('Alice Auditor', $names);
        $this->assertContains('Bob Trustee', $names);
    }
}

