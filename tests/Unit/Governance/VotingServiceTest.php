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
        $this->expectExceptionMessage("You can't vote on this resolution.");
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
        $this->expectExceptionMessage("You can't vote on this resolution.");
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
            $this->assertStringContainsString('Only voting members of the Finance Committee can vote', $e->getMessage());
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
            $this->assertStringContainsString('actual governing document', $e->getMessage());
        }

        // Attempting activation without approval authority is rejected
        try {
            $profileService->activateProfile($profile, $admin, null, 'Trust Deed 2024');
            $this->fail('Activation without approval authority evidence must be rejected');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString("board's approval hasn't been recorded", $e->getMessage());
        }

        // Activation with document reference and a resolution explicitly bound to
        // this exact profile, body, document and rule revision succeeds.
        $profile->update(['governing_document_reference' => 'Constitution 2024 Adopted']);
        $approvalRes = $this->createBoundCarriedResolution(
            $admin,
            \App\Domain\Governance\Models\GovernanceResolutionBinding::SUBJECT_VOTING_PROFILE,
            $profile->id,
            [
                'title' => 'Approve Constitution 2024 Adopted',
                'exact_motion' => 'Adopt voting rules under Constitution 2024 Adopted.',
            ],
        );
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
        $this->expectExceptionMessage('Board voting is switched off');
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
        $this->expectExceptionMessage("You've already voted on this resolution");
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
        $this->expectExceptionMessage('You stepped aside from this vote');
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
        $this->expectExceptionMessage('Voting closed at the deadline');
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
        $this->expectExceptionMessage('Only resolutions that passed can be marked as done');
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

    // ── Plain-language UX audit 2026-09-14 ───────────────────────────────────

    public function test_two_thirds_rule_counts_only_for_and_against_votes_and_special_is_the_same_rule(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $members = collect(range(1, 6))->map(fn (int $i) => $this->createBoardMember(
            $this->createUserWithRole('board_member', ['email' => "two-thirds-{$i}@example.test"])
        ))->values();
        $service = new VotingService;

        // 2 For, 1 Against, 3 abstain: two-thirds of the For and Against votes are For.
        $passes = $this->createResolution($admin, ['status' => 'open', 'voting_threshold' => 'two_thirds', 'deadline' => now()->addDay()]);
        foreach (['for', 'for', 'against', 'abstain', 'abstain', 'abstain'] as $i => $vote) {
            $service->castVote($passes, $members[$i], $vote);
        }
        $service->closeVoting($passes);
        $this->assertSame('carried', $passes->fresh()->outcome);
        $this->assertSame('two_thirds', $passes->fresh()->vote_summary['decision_snapshot']['applied_threshold']);

        // 3 For, 2 Against is 60% — more For than Against, but short of two-thirds.
        $fails = $this->createResolution($admin, ['status' => 'open', 'voting_threshold' => 'two_thirds', 'deadline' => now()->addDay()]);
        foreach (['for', 'for', 'for', 'against', 'against'] as $i => $vote) {
            $service->castVote($fails, $members[$i], $vote);
        }
        $service->closeVoting($fails);
        $this->assertSame('defeated', $fails->fresh()->outcome);

        // The wizard's "special" key is the same two-thirds rule (it used to
        // fall back to "more For than Against").
        $special = $this->createResolution($admin, ['status' => 'open', 'voting_threshold' => 'special', 'deadline' => now()->addDay()]);
        foreach (['for', 'for', 'for', 'against', 'against'] as $i => $vote) {
            $service->castVote($special, $members[$i], $vote);
        }
        $service->closeVoting($special);
        $this->assertSame('defeated', $special->fresh()->outcome);
        $this->assertSame('two_thirds', $special->fresh()->appliedThreshold());
    }

    public function test_written_resolution_follows_the_everyones_agreement_rule_and_results_say_so(): void
    {
        $this->seedGovernance();
        \App\Domain\Governance\Models\GovernanceVotingProfile::query()->update(['written_unanimity_required' => true]);
        $admin = $this->createAdminUser();
        $m1 = $this->createBoardMember($this->createUserWithRole('board_member', ['email' => 'written-1@example.test']));
        $m2 = $this->createBoardMember($this->createUserWithRole('board_member', ['email' => 'written-2@example.test']));
        $m3 = $this->createBoardMember($this->createUserWithRole('board_member', ['email' => 'written-3@example.test']));
        $service = new VotingService;

        $written = $this->createResolution($admin, ['status' => 'draft', 'voting_threshold' => 'simple_majority']);
        $service->openVoting($written, now()->addDays(3));
        $service->castVote($written, $m1, 'for');
        $service->castVote($written, $m2, 'for');
        $service->castVote($written, $m3, 'against');
        $service->closeVoting($written);

        $written->refresh();
        $this->assertSame('defeated', $written->outcome, 'More For than Against is not enough when written votes need everyone.');

        $results = $service->getVotingResults($written);
        $this->assertSame('simple_majority', $results['threshold']);
        $this->assertSame('unanimous', $results['applied_threshold']);
        $this->assertTrue($results['written_unanimity_applied']);
    }

    public function test_a_reason_for_a_vote_is_never_recorded_as_a_conflict(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $member = $this->createBoardMember($admin);
        $resolution = $this->createResolution($admin, ['status' => 'open', 'deadline' => now()->addDays(2)]);

        $vote = (new VotingService)->castVote($resolution, $member, 'against', 'electronic', 'Not this year.');

        $this->assertSame('Not this year.', $vote->fresh()->vote_note);
        $this->assertFalse($vote->fresh()->conflict_declared);
        $this->assertNull($vote->fresh()->conflict_note);
    }

    public function test_open_voting_refuses_papers_that_are_not_for_decision(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $paper = $this->createResolution($admin, ['purpose' => 'discussion', 'status' => 'draft']);

        try {
            (new VotingService)->openVoting($paper, now()->addDays(2));
            $this->fail('A paper for discussion was opened for voting.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString("the board doesn't vote on it", $e->getMessage());
        }

        $this->assertSame('draft', $paper->fresh()->status);
    }

    public function test_a_vote_outside_a_meeting_needs_a_deadline_before_voting_opens(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $paper = $this->createResolution($admin, ['status' => 'draft', 'deadline' => null]);

        $this->assertArrayHasKey('deadline', $paper->validateForPublication());

        try {
            (new VotingService)->openVoting($paper);
            $this->fail('A vote outside a meeting opened without a deadline.');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Set a voting deadline', $e->getMessage());
        }

        $this->assertSame('draft', $paper->fresh()->status);
    }

    public function test_first_switch_on_records_the_boards_existing_approval(): void
    {
        $this->seedGovernance();
        \App\Domain\Governance\Models\GovernanceVotingProfile::query()->delete();
        $chair = $this->createAdminUser();
        $meeting = $this->createMeeting($chair, ['scheduled_at' => now()->subMonth()]);
        $service = app(\App\Domain\Governance\Services\GovernanceVotingProfileService::class);
        $profile = $service->getOrCreateCandidateDefault('board');
        $profile->update(['governing_document_reference' => 'Trust deed 2019', 'governing_document_version' => 'v2']);
        $this->assertFalse($service->votingIsSwitchedOn('board'));
        $this->assertFalse($service->hasEverBeenActive('board'));

        $activated = $service->recordBoardApproval($profile, $chair, [
            'governing_document_reference' => 'Trust deed 2019',
            'governing_document_version' => 'v2',
            'approved_on' => \Carbon\Carbon::createFromFormat('!Y-m-d', now('Pacific/Auckland')->subMonth()->toDateString(), 'Pacific/Auckland'),
            'approval_minutes_reference' => 'Minutes of the August board meeting, item 4',
            'approval_meeting_id' => $meeting->id,
        ]);

        $this->assertTrue($activated->is_active);
        $this->assertTrue($activated->isConfirmed());
        $this->assertSame('recorded_board_approval', $activated->approval_source);
        $this->assertSame('Minutes of the August board meeting, item 4', $activated->approval_minutes_reference);
        $this->assertSame($meeting->id, $activated->approval_meeting_id);
        $this->assertNull($activated->approved_by_resolution_id);
        $this->assertSame($chair->id, $activated->approved_by_user_id);
        $this->assertTrue($service->votingIsSwitchedOn('board'));
        $this->assertDatabaseHas('governance_audit_log', [
            'action' => 'governance_rules.activated',
            'resource_type' => 'GovernanceVotingProfile',
            'resource_id' => $profile->id,
        ]);

        // Board voting can now open.
        $this->createBoardMember($chair);
        $paper = $this->createResolution($chair, ['status' => 'draft', 'governance_meeting_id' => $meeting->id]);
        (new VotingService)->openVoting($paper);
        $this->assertSame('open', $paper->fresh()->status);
    }

    public function test_recorded_approval_is_refused_once_voting_rules_are_live_and_changes_need_a_resolution(): void
    {
        $this->seedGovernance(); // a live, approved board profile
        $chair = $this->createAdminUser();
        $service = app(\App\Domain\Governance\Services\GovernanceVotingProfileService::class);
        $live = $service->getActiveProfile('board');
        $this->assertTrue($live->isConfirmed());

        $changes = $service->createProfile([
            'governing_body' => 'board',
            'governing_document_reference' => 'Trust deed 2026',
            'governing_document_version' => 'v3',
            'written_unanimity_required' => true,
        ], $chair);

        try {
            $service->recordBoardApproval($changes, $chair, [
                'governing_document_reference' => 'Trust deed 2026',
                'approved_on' => now()->subDay(),
                'approval_minutes_reference' => 'Minutes, 1 September',
            ]);
            $this->fail('A recorded approval replaced live voting rules without a resolution.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('already been switched on', $e->getMessage());
        }

        $this->assertFalse($changes->fresh()->is_active);
        $this->assertTrue($live->fresh()->is_active, 'The live rules stay in force.');

        // Changing the rules still works through a passed resolution linked to them.
        $approval = $this->createBoundCarriedResolution(
            $chair,
            \App\Domain\Governance\Models\GovernanceResolutionBinding::SUBJECT_VOTING_PROFILE,
            $changes->id,
        );
        $activated = $service->activateProfile($changes, $chair, $approval);
        $this->assertTrue($activated->is_active);
        $this->assertSame('resolution', $activated->approval_source);
        $this->assertSame($approval->id, $activated->approved_by_resolution_id);
        $this->assertFalse($live->fresh()->is_active);
    }

    public function test_recorded_approval_needs_a_real_document_a_past_date_and_the_minutes(): void
    {
        $this->seedGovernance();
        \App\Domain\Governance\Models\GovernanceVotingProfile::query()->delete();
        $chair = $this->createAdminUser();
        $service = app(\App\Domain\Governance\Services\GovernanceVotingProfileService::class);
        $profile = $service->getOrCreateCandidateDefault('board');

        $attempts = [
            'Enter the name of your governing document' => ['governing_document_reference' => '', 'approved_on' => now()->subDay(), 'approval_minutes_reference' => 'Minutes'],
            "can't be in the future" => ['governing_document_reference' => 'Constitution', 'approved_on' => now()->addWeek(), 'approval_minutes_reference' => 'Minutes'],
            'Enter where the approval is recorded' => ['governing_document_reference' => 'Constitution', 'approved_on' => now()->subDay(), 'approval_minutes_reference' => ' '],
        ];

        foreach ($attempts as $message => $record) {
            try {
                $service->recordBoardApproval($profile, $chair, $record);
                $this->fail("Recorded approval accepted: {$message}");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }

        $this->assertFalse($profile->fresh()->is_active);
    }
}

