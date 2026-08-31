<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Jobs\SendVotingReminder;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\ConflictDeclaration;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\Vote;
use App\Domain\Governance\Notifications\VotingReminderNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

class VotingReminderQueuedAuthorizationTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-31 10:00:00');
        $this->seedGovernance();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_queued_reminder_revalidates_resolution_member_vote_conflict_and_user_state(): void
    {
        $proposer = $this->createAdminUser();
        $resolution = $this->openResolution($proposer, 'QUEUE-VOTE-001');

        [$validUser, $validMember] = $this->votingMember('member');
        [$votedUser, $votedMember] = $this->votingMember('member');
        [$conflictedUser, $conflictedMember] = $this->votingMember('member');
        [$inactiveUser, $inactiveMember] = $this->votingMember('member', ['is_active' => false]);
        [$expiredUser, $expiredMember] = $this->votingMember('member', ['term_end' => today()->subDay()]);
        [$observerUser, $observerMember] = $this->votingMember('observer');
        [$unapprovedUser, $unapprovedMember] = $this->votingMember('member', [], ['approved_at' => null]);

        Vote::create([
            'resolution_id' => $resolution->id,
            'board_member_id' => $votedMember->id,
            'vote' => 'for',
            'voted_at' => now(),
            'voting_method' => 'electronic',
            'recorded_by' => $votedUser->id,
        ]);
        ConflictDeclaration::create([
            'governance_meeting_id' => $resolution->governance_meeting_id,
            'resolution_id' => $resolution->id,
            'board_member_id' => $conflictedMember->id,
            'declaration_type' => 'material',
            'declaration_text' => 'Withdrew after the reminder was queued.',
            'withdrew_from_voting' => true,
            'withdrew_from_discussion' => true,
            'recorded_in_minutes' => false,
            'recorded_by' => $proposer->id,
            'declared_at' => now(),
        ]);

        foreach ([$validMember, $votedMember, $conflictedMember, $inactiveMember, $expiredMember, $observerMember, $unapprovedMember] as $member) {
            (new SendVotingReminder($resolution, $member))->handle();
        }

        Notification::assertSentToTimes($validUser, VotingReminderNotification::class, 1);
        Notification::assertNotSentTo($votedUser, VotingReminderNotification::class);
        Notification::assertNotSentTo($conflictedUser, VotingReminderNotification::class);
        Notification::assertNotSentTo($inactiveUser, VotingReminderNotification::class);
        Notification::assertNotSentTo($expiredUser, VotingReminderNotification::class);
        Notification::assertNotSentTo($observerUser, VotingReminderNotification::class);
        Notification::assertNotSentTo($unapprovedUser, VotingReminderNotification::class);
        Notification::assertCount(1);

        Notification::assertSentTo($validUser, VotingReminderNotification::class, function (
            VotingReminderNotification $notification,
            array $channels,
        ) use ($validUser, $resolution): bool {
            return $channels === ['mail', 'database']
                && $notification->toArray($validUser) === [
                    'type' => 'voting_reminder',
                    'resolution_id' => $resolution->id,
                    'resolution_reference' => 'QUEUE-VOTE-001',
                ];
        });
    }

    public function test_closed_or_deleted_resolution_and_deleted_member_are_no_ops(): void
    {
        $proposer = $this->createAdminUser();
        [$closedUser, $closedMember] = $this->votingMember('chair');
        [$deletedResolutionUser, $deletedResolutionMember] = $this->votingMember('secretary');
        [$deletedMemberUser, $deletedMember] = $this->votingMember('member');

        $closed = $this->openResolution($proposer, 'QUEUE-VOTE-002');
        $closed->update(['status' => 'closed', 'closed_at' => now()]);

        $deletedResolution = $this->openResolution($proposer, 'QUEUE-VOTE-003');
        $deletedResolution->delete();

        $open = $this->openResolution($proposer, 'QUEUE-VOTE-004');
        $deletedMember->delete();

        (new SendVotingReminder($closed, $closedMember))->handle();
        (new SendVotingReminder($deletedResolution, $deletedResolutionMember))->handle();
        (new SendVotingReminder($open, $deletedMember))->handle();

        Notification::assertNotSentTo($closedUser, VotingReminderNotification::class);
        Notification::assertNotSentTo($deletedResolutionUser, VotingReminderNotification::class);
        Notification::assertNotSentTo($deletedMemberUser, VotingReminderNotification::class);
        Notification::assertNothingSent();
    }

    /** @return array{User, BoardMember} */
    private function votingMember(string $boardRole, array $memberOverrides = [], array $userOverrides = []): array
    {
        $user = $this->createUserWithRole('board_member', $userOverrides);
        $member = $this->createBoardMember($user, array_merge([
            'board_role' => $boardRole,
            'term_start' => today()->subYear(),
            'term_end' => today()->addYear(),
            'is_active' => true,
        ], $memberOverrides));

        return [$user, $member];
    }

    private function openResolution(User $proposer, string $reference): Resolution
    {
        $meeting = $this->createMeeting($proposer, [
            'title' => $reference.' meeting',
            'scheduled_at' => now()->subHour(),
            'status' => 'in_progress',
        ]);

        return $this->createResolution($proposer, [
            'resolution_reference' => $reference,
            'governance_meeting_id' => $meeting->id,
            'title' => 'Queued voting authorization',
            'status' => 'open',
            'opened_at' => now()->subHour(),
            'deadline' => now()->addDay(),
        ]);
    }
}
