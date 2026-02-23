<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\ConflictDeclaration;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\Vote;
use App\Models\User;
use Illuminate\Support\Collection;

class VotingService
{
    /**
     * Open a resolution for voting
     */
    public function openVoting(Resolution $resolution, ?\DateTime $deadline = null): void
    {
        if (!$resolution->isDraft()) {
            throw new \InvalidArgumentException('Resolution must be in draft status to open voting');
        }

        $resolution->openForVoting($deadline);
    }

    /**
     * Cast a vote
     */
    public function castVote(
        Resolution $resolution,
        BoardMember $boardMember,
        string $vote, // for, against, abstain
        string $method = 'electronic',
        ?string $conflictNote = null
    ): Vote {
        if (!$resolution->isOpen()) {
            throw new \InvalidArgumentException('Voting is not open for this resolution');
        }

        if ($resolution->isOverdue()) {
            throw new \InvalidArgumentException('Voting deadline has passed');
        }

        // Check for conflict
        $conflict = ConflictDeclaration::where('resolution_id', $resolution->id)
            ->where('board_member_id', $boardMember->id)
            ->where('withdrew_from_voting', true)
            ->first();

        if ($conflict && $vote !== 'abstain') {
            throw new \InvalidArgumentException('Cannot vote on resolution with declared conflict (unless abstaining)');
        }

        // Check if already voted
        if ($resolution->hasBoardMemberVoted($boardMember->id)) {
            throw new \InvalidArgumentException('Already voted on this resolution');
        }

        return Vote::create([
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
            'vote' => $vote,
            'voted_at' => now(),
            'voting_method' => $method,
            'conflict_declared' => !is_null($conflictNote) || $conflict,
            'conflict_note' => $conflictNote,
            'recorded_by' => auth()->id() ?? $boardMember->user_id,
        ]);
    }

    /**
     * Declare a conflict of interest
     */
    public function declareConflict(
        Resolution $resolution,
        BoardMember $boardMember,
        string $type,
        string $description,
        User $recordedBy,
        bool $withdrawFromVoting = true,
        bool $withdrawFromDiscussion = false,
    ): ConflictDeclaration {
        $conflict = ConflictDeclaration::create([
            'governance_meeting_id' => $resolution->governance_meeting_id,
            'resolution_id' => $resolution->id,
            'board_member_id' => $boardMember->id,
            'declaration_type' => $type,
            'declaration_text' => $description,
            'withdrew_from_voting' => $withdrawFromVoting,
            'withdrew_from_discussion' => $withdrawFromDiscussion,
            'recorded_by' => $recordedBy->id,
            'declared_at' => now(),
        ]);

        // If withdrawing from voting, record an abstention
        if ($withdrawFromVoting && $resolution->isOpen()) {
            $this->castVote($resolution, $boardMember, 'abstain', 'conflict_declaration', $description);
        }

        return $conflict;
    }

    /**
     * Close voting and determine outcome
     */
    public function closeVoting(Resolution $resolution, ?string $notes = null): void
    {
        if (!$resolution->isOpen()) {
            throw new \InvalidArgumentException('Voting is not open');
        }

        $resolution->closeVoting();

        if ($notes) {
            $resolution->update(['outcome_notes' => $notes]);
        }
    }

    /**
     * Calculate quorum for a meeting
     */
    public function calculateQuorum(?int $meetingId): array
    {
        if (!$meetingId) {
            return [
                'present' => 0,
                'apologies' => 0,
                'total_eligible' => BoardMember::active()->count(),
                'required' => 0,
                'met' => true,
                'percentage_present' => 0,
            ];
        }

        $meeting = \App\Domain\Governance\Models\GovernanceMeeting::findOrFail($meetingId);
        
        $attendances = $meeting->attendances;
        $present = $attendances->where('status', 'present')->count();
        $apologies = $attendances->where('status', 'apology')->count();
        
        $totalActive = BoardMember::active()->count();
        $required = ceil($totalActive * ($meeting->quorum_required / 100));
        
        return [
            'present' => $present,
            'apologies' => $apologies,
            'total_eligible' => $totalActive,
            'required' => $required,
            'met' => $present >= $required,
            'percentage_present' => $totalActive > 0 ? round(($present / $totalActive) * 100, 1) : 0,
        ];
    }

    /**
     * Check if quorum is met for a resolution
     */
    public function isQuorumMet(Resolution $resolution): bool
    {
        if (!$resolution->quorum_required) {
            return true;
        }

        $quorum = $this->calculateQuorum($resolution->governance_meeting_id);
        return $quorum['met'];
    }

    /**
     * Get voting results with detailed breakdown
     */
    public function getVotingResults(Resolution $resolution): array
    {
        $votes = $resolution->votes;
        $summary = $resolution->vote_summary ?? $resolution->calculateVoteSummary();
        
        $total = $summary['for'] + $summary['against'];
        $forPct = $total > 0 ? round(($summary['for'] / $total) * 100, 1) : 0;
        $againstPct = $total > 0 ? round(($summary['against'] / $total) * 100, 1) : 0;

        return [
            'summary' => $summary,
            'percentages' => [
                'for' => $forPct,
                'against' => $againstPct,
            ],
            'outcome' => $resolution->outcome,
            'threshold' => $resolution->voting_threshold,
            'quorum_met' => $this->isQuorumMet($resolution),
            'individual_votes' => $votes->map(fn($v) => [
                'board_member' => $v->boardMember?->full_name,
                'vote' => $v->vote,
                'conflict_declared' => $v->conflict_declared,
                'voted_at' => $v->voted_at->toDateTimeString(),
            ]),
            'conflicts' => $resolution->conflictDeclarations->map(fn($c) => [
                'board_member' => $c->boardMember?->full_name,
                'type' => $c->declaration_type,
                'description' => $c->declaration_text,
                'withdrew' => $c->withdrew_from_voting,
            ]),
        ];
    }

    /**
     * Get pending votes for a board member
     */
    public function getPendingVotes(int $boardMemberId): Collection
    {
        $boardMember = BoardMember::findOrFail($boardMemberId);
        
        return Resolution::where('status', 'open')
            ->whereDoesntHave('votes', fn($q) => $q->where('board_member_id', $boardMemberId))
            ->whereDoesntHave('conflictDeclarations', fn($q) => 
                $q->where('board_member_id', $boardMemberId)
                  ->where('withdrew_from_voting', true)
            )
            ->where('deadline', '>', now())
            ->orderBy('deadline')
            ->get();
    }

    /**
     * Create a written resolution (out-of-session)
     */
    public function createWrittenResolution(
        string $title,
        string $context,
        array $options,
        User $proposedBy,
        ?string $recommendation = null,
        string $threshold = 'simple_majority',
        ?int $deadlineDays = 7
    ): Resolution {
        $resolution = Resolution::create([
            'title' => $title,
            'context' => $context,
            'options' => $options,
            'recommendation' => $recommendation,
            'voting_threshold' => $threshold,
            'quorum_required' => true,
            'status' => 'draft',
            'proposed_by' => $proposedBy->id,
            'proposed_at' => now(),
            'deadline' => $deadlineDays ? now()->addDays($deadlineDays) : null,
        ]);

        return $resolution;
    }

    /**
     * Check for conflicts automatically based on related entities
     */
    public function detectPotentialConflicts(Resolution $resolution, BoardMember $boardMember): array
    {
        $conflicts = [];

        // Check if board member is mentioned in the resolution
        if (str_contains(strtolower($resolution->context), strtolower($boardMember->full_name))) {
            $conflicts[] = [
                'type' => 'mentioned_in_resolution',
                'severity' => 'low',
                'description' => 'Board member is mentioned in the resolution context',
            ];
        }

        // Check if board member is the proposer
        if ($resolution->proposed_by === $boardMember->user_id) {
            $conflicts[] = [
                'type' => 'proposer',
                'severity' => 'low',
                'description' => 'Board member proposed the resolution',
            ];
        }

        return $conflicts;
    }

    /**
     * Send voting reminders to members who haven't voted
     */
    public function sendVotingReminders(Resolution $resolution): int
    {
        if (!$resolution->isOpen()) {
            return 0;
        }

        $votedMemberIds = $resolution->votes->pluck('board_member_id')->toArray();
        $conflictedMemberIds = $resolution->conflictDeclarations
            ->where('withdrew_from_voting', true)
            ->pluck('board_member_id')
            ->toArray();
        
        $reminderRecipients = BoardMember::active()
            ->whereNotIn('id', $votedMemberIds)
            ->whereNotIn('id', $conflictedMemberIds)
            ->get();

        foreach ($reminderRecipients as $member) {
            // Dispatch notification job
            \App\Domain\Governance\Jobs\SendVotingReminder::dispatch($resolution, $member);
        }

        return $reminderRecipients->count();
    }
}
