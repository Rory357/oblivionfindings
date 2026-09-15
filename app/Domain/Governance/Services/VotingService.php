<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\CommitteeMembership;
use App\Domain\Governance\Models\ConflictDeclaration;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\Vote;
use App\Domain\Governance\Support\GovernanceLabels;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VotingService
{
    /** Statuses where voting has finished and nothing about it can change. */
    private const FINISHED_STATUSES = ['closed', 'implemented', 'archived', 'cancelled'];

    public function __construct(
        protected ?GovernanceVotingProfileService $profileService = null
    ) {
        $this->profileService = $profileService ?? app(GovernanceVotingProfileService::class);
    }

    /**
     * Open a resolution for voting
     */
    public function openVoting(Resolution $resolution, ?\DateTime $deadline = null): void
    {
        DB::transaction(function () use ($resolution, $deadline) {
            $lockedRes = Resolution::where('id', $resolution->id)->lockForUpdate()->firstOrFail();

            // For discussion / For information papers never go to a vote.
            if (! $lockedRes->isDecisionPaper()) {
                throw new \DomainException(
                    'This paper is for '.($lockedRes->purpose === 'discussion' ? 'discussion' : 'information').", so the board doesn't vote on it."
                    .($lockedRes->isDraft() ? ' Use "Publish to members" to share it.' : '')
                );
            }

            if (! $lockedRes->isDraft()) {
                throw new \InvalidArgumentException(match (true) {
                    $lockedRes->isOpen() => 'Voting is already open on this resolution.',
                    in_array($lockedRes->status, self::FINISHED_STATUSES, true) => 'Voting on this resolution has already closed.',
                    default => 'Voting can only be opened on a draft resolution.',
                });
            }

            $committeeId = $lockedRes->board_committee_id ?? $lockedRes->meeting?->board_committee_id;
            $profile = $this->profileService->getActiveProfile(
                $committeeId ? 'committee' : 'board',
                $committeeId
            );

            if (! $profile || ! $profile->isConfirmed()) {
                throw new \DomainException(
                    'Board voting is switched off until the voting rules are confirmed. The chair or board secretary can confirm them in Settings, under "How the board votes".'
                );
            }

            if ($lockedRes->isOutOfSession() && ! $profile->written_voting_permitted) {
                throw new \DomainException(
                    "The board's voting rules don't allow voting outside a meeting (written resolutions). Add this resolution to a meeting, or change the voting rules in Settings."
                );
            }

            // Publication checks use the deadline voting will actually have.
            if ($deadline !== null) {
                $lockedRes->deadline = $deadline;
            }

            // Publication validation: verify motion, context, options/recommendation, implications
            $validationErrors = $lockedRes->validateForPublication();
            if (! empty($validationErrors)) {
                throw new \DomainException("This resolution isn't ready for voting yet. ".implode(' ', $validationErrors));
            }

            $effectiveDeadline = $deadline ?? $lockedRes->deadline;
            if ($effectiveDeadline !== null && Carbon::instance($effectiveDeadline)->isPast()) {
                throw new \DomainException('The voting deadline has already passed. Choose a later deadline before opening voting.');
            }

            $lockedRes->voting_profile_id = $profile->id;
            $snapshot = $lockedRes->paper_snapshot;
            if (empty($snapshot)) {
                $snapshot = $lockedRes->freezePaperSnapshot();
            }
            $snapshot['voting_profile'] = $profile->toArray();
            if ($lockedRes->governance_meeting_id && $lockedRes->meeting) {
                $snapshot['meeting_quorum_required'] = (int) ($lockedRes->meeting->quorum_required ?? 50);
            }
            $lockedRes->paper_snapshot = $snapshot;
            $lockedRes->save();

            $lockedRes->openForVoting($effectiveDeadline);
        });

        $resolution->refresh();
    }

    /**
     * Share a For discussion / For information paper with members. The
     * wording becomes final; there is no vote.
     */
    public function publishToMembers(Resolution $resolution): void
    {
        DB::transaction(function () use ($resolution) {
            $lockedRes = Resolution::where('id', $resolution->id)->lockForUpdate()->firstOrFail();

            if (! $lockedRes->isDraft()) {
                throw new \InvalidArgumentException('Only a draft paper can be published to members.');
            }

            $validationErrors = $lockedRes->validateForPublication();
            if (! empty($validationErrors)) {
                throw new \DomainException("This paper isn't ready to publish yet. ".implode(' ', $validationErrors));
            }

            $lockedRes->publishToMembers();
        });

        $resolution->refresh();
    }

    /**
     * Cast a vote. `$voteNote` is the member's optional reason for their
     * vote — it never marks the vote as a conflict of interest. A vote is
     * only flagged `conflict_declared` when the member made a real
     * declaration on this resolution (and did not step aside).
     */
    public function castVote(
        Resolution $resolution,
        BoardMember $boardMember,
        string $vote, // for, against, abstain
        string $method = 'electronic',
        ?string $voteNote = null
    ): Vote {
        if (! in_array($vote, ['for', 'against', 'abstain'], true)) {
            throw new \InvalidArgumentException('Choose For, Against or Abstain.');
        }

        $voteNote = $voteNote !== null && trim($voteNote) !== '' ? trim($voteNote) : null;

        return DB::transaction(function () use ($resolution, $boardMember, $vote, $method, $voteNote) {
            $lockedRes = Resolution::where('id', $resolution->id)->lockForUpdate()->firstOrFail();

            if (! $lockedRes->isOpen()) {
                throw new \InvalidArgumentException(
                    in_array($lockedRes->status, self::FINISHED_STATUSES, true)
                        ? 'Voting on this resolution has closed, so your vote can\'t be recorded.'
                        : "Voting hasn't opened on this resolution yet."
                );
            }

            if ($lockedRes->isOverdue()) {
                throw new \InvalidArgumentException(
                    'Voting closed at the deadline ('.GovernanceLabels::date($lockedRes->deadline, true).'), so your vote can\'t be recorded.'
                );
            }

            // Validate that the member is eligible to vote
            $freshMember = BoardMember::findOrFail($boardMember->id);
            if (! $freshMember->canVote()) {
                throw new \InvalidArgumentException("You can't vote on this resolution. ".$this->memberIneligibility($freshMember));
            }

            // Enforce frozen electorate if captured at opening
            if (! empty($lockedRes->electorate_at_open)) {
                $electorateIds = collect($lockedRes->electorate_at_open)->map(function ($item) {
                    if (is_array($item)) {
                        return $item['board_member_id'] ?? null;
                    }
                    if (is_object($item)) {
                        return $item->board_member_id ?? null;
                    }

                    return $item;
                })->filter()->all();

                if (! in_array((int) $freshMember->id, array_map('intval', $electorateIds), true)) {
                    throw new \DomainException("You weren't on the voting list when voting opened, so you can't vote on this resolution.");
                }
            }

            // Validate committee electorate if committee resolution
            $committeeId = $lockedRes->board_committee_id ?? $lockedRes->meeting?->board_committee_id;
            if ($committeeId) {
                $membership = CommitteeMembership::where('board_committee_id', $committeeId)
                    ->where('board_member_id', $freshMember->id)
                    ->active()
                    ->first();

                if (! $membership || ! $membership->canVote()) {
                    throw new \InvalidArgumentException('Only voting members of '.$this->committeeName($committeeId).' can vote on this resolution.');
                }
            }

            // A member who declared a conflict and stepped aside can't vote.
            $declaration = ConflictDeclaration::where('resolution_id', $lockedRes->id)
                ->where('board_member_id', $freshMember->id)
                ->first();

            if ($declaration && $declaration->withdrew_from_voting) {
                throw new \InvalidArgumentException("You stepped aside from this vote because of a conflict of interest, so you can't vote on it.");
            }

            // Check if already voted
            $existingVote = Vote::where('resolution_id', $lockedRes->id)
                ->where('board_member_id', $freshMember->id)
                ->first();

            if ($existingVote) {
                if ($existingVote->vote === $vote) {
                    // Idempotent replay: return existing vote receipt
                    return $existingVote;
                }
                throw new \InvalidArgumentException("You've already voted on this resolution, and a vote can't be changed once it's recorded.");
            }

            return Vote::create([
                'resolution_id' => $lockedRes->id,
                'board_member_id' => $freshMember->id,
                'vote' => $vote,
                'voted_at' => now(),
                'voting_method' => $method,
                'conflict_declared' => $declaration !== null,
                'vote_note' => $voteNote,
                'recorded_by' => auth()->id() ?? $freshMember->user_id,
            ]);
        });
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
        if (! in_array($type, ['material', 'related', 'prejudicial', 'other'], true)) {
            throw new \InvalidArgumentException('Choose what kind of conflict of interest it is.');
        }

        if (empty(trim($description))) {
            throw new \InvalidArgumentException('Describe the conflict of interest.');
        }

        return DB::transaction(function () use (
            $resolution,
            $boardMember,
            $type,
            $description,
            $recordedBy,
            $withdrawFromVoting,
            $withdrawFromDiscussion
        ) {
            $lockedRes = Resolution::where('id', $resolution->id)->lockForUpdate()->firstOrFail();

            // Once voting has closed the result is recorded; stepping aside
            // then would silently remove a vote that was already counted.
            if (in_array($lockedRes->status, self::FINISHED_STATUSES, true)) {
                throw new \DomainException("Voting on this resolution has closed, so a conflict of interest can't be declared here any more. Tell the chair or board secretary so it can be noted in the minutes.");
            }

            $freshMember = BoardMember::findOrFail($boardMember->id);

            $conflict = ConflictDeclaration::where('resolution_id', $lockedRes->id)
                ->where('board_member_id', $freshMember->id)
                ->first();

            if ($conflict) {
                $conflict->update([
                    'declaration_type' => $type,
                    'declaration_text' => $description,
                    'withdrew_from_voting' => $withdrawFromVoting,
                    'withdrew_from_discussion' => $withdrawFromDiscussion,
                    'recorded_by' => $recordedBy->id,
                ]);
            } else {
                $conflict = ConflictDeclaration::create([
                    'governance_meeting_id' => $lockedRes->governance_meeting_id,
                    'resolution_id' => $lockedRes->id,
                    'board_member_id' => $freshMember->id,
                    'declaration_type' => $type,
                    'declaration_text' => $description,
                    'withdrew_from_voting' => $withdrawFromVoting,
                    'withdrew_from_discussion' => $withdrawFromDiscussion,
                    'recorded_by' => $recordedBy->id,
                    'declared_at' => now(),
                ]);
            }

            $existingVote = Vote::where('resolution_id', $lockedRes->id)
                ->where('board_member_id', $freshMember->id);

            // Recusal is separate from abstention — NEVER create an abstention vote.
            // If the member previously cast a vote and now withdraws, remove the prior vote.
            if ($withdrawFromVoting) {
                $existingVote->delete();
            } else {
                // A member who declared but still voted: the vote carries the
                // (real) declaration.
                $existingVote->update(['conflict_declared' => true]);
            }

            return $conflict;
        });
    }

    /**
     * Close voting and determine outcome
     */
    public function closeVoting(Resolution $resolution, ?string $notes = null): void
    {
        DB::transaction(function () use ($resolution, $notes) {
            $lockedRes = Resolution::where('id', $resolution->id)->lockForUpdate()->firstOrFail();

            if (! $lockedRes->isOpen()) {
                throw new \InvalidArgumentException("Voting isn't open on this resolution, so it can't be closed.");
            }

            $quorum = $lockedRes->quorum_required
                ? $this->calculateQuorum($lockedRes->governance_meeting_id, $lockedRes)
                : ['met' => true, 'required' => 0, 'present' => 0, 'total_eligible' => BoardMember::eligibleVoters()->count(), 'percentage_present' => 100.0, 'resolution_mode' => 'not_required'];

            $lockedRes->closeVoting($quorum);

            if ($notes) {
                $lockedRes->update(['outcome_notes' => $notes]);
            }
        });

        $resolution->refresh();
    }

    /**
     * The plain reason a viewer can't vote on an OPEN resolution, or null
     * when they can (or when voting isn't open). Uses the same checks as
     * castVote() so the page never offers a ballot the server would refuse.
     */
    public function ineligibleReason(Resolution $resolution, User $viewer): ?string
    {
        if (! $resolution->isOpen()) {
            return null;
        }

        $member = BoardMember::active()->where('user_id', $viewer->id)->first();
        if (! $member) {
            return 'Only current board members can vote on resolutions.';
        }

        if (! $member->canVote()) {
            return $this->memberIneligibility($member);
        }

        if (! empty($resolution->electorate_at_open)) {
            $ids = collect($resolution->electorate_at_open)
                ->map(fn ($item) => (int) (is_array($item) ? ($item['board_member_id'] ?? 0) : (is_object($item) ? ($item->board_member_id ?? 0) : $item)))
                ->all();

            if (! in_array((int) $member->id, $ids, true)) {
                return "You weren't on the voting list when voting opened, so you can't vote on this resolution.";
            }
        }

        $committeeId = $resolution->board_committee_id ?? $resolution->meeting?->board_committee_id;
        if ($committeeId) {
            $membership = CommitteeMembership::where('board_committee_id', $committeeId)
                ->where('board_member_id', $member->id)
                ->active()
                ->first();

            if (! $membership || ! $membership->canVote()) {
                return 'Only voting members of '.$this->committeeName((int) $committeeId).' can vote on this resolution.';
            }
        }

        return null;
    }

    /** Why a board member has no vote at all right now (one plain sentence). */
    public function memberIneligibility(BoardMember $member): string
    {
        $today = today();

        return match (true) {
            ! $member->is_active => "You're not an active board member.",
            $member->isObserver() => "Observers can't vote.",
            $member->term_start !== null && $member->term_start->isAfter($today) => 'Your board term starts on '.GovernanceLabels::date($member->term_start->toDateString()).'.',
            $member->term_end !== null && $member->term_end->isBefore($today) => 'Your board term ended on '.GovernanceLabels::date($member->term_end->toDateString()).'.',
            $member->board_role === 'secretary' => "The secretary only votes when they've been given a voting seat.",
            default => "You don't have a voting seat on the board.",
        };
    }

    /**
     * Calculate quorum for a meeting or out-of-session resolution
     */
    public function calculateQuorum(?int $meetingId, ?Resolution $resolution = null): array
    {
        $committeeId = $resolution?->board_committee_id;
        if (! $committeeId && $meetingId) {
            $meeting = \App\Domain\Governance\Models\GovernanceMeeting::find($meetingId);
            $committeeId = $meeting?->board_committee_id;
        }

        // 1. Resolve entitled electorate IDs (frozen electorate at open, or committee voting members, or board eligible voters)
        if ($resolution && ! empty($resolution->electorate_at_open)) {
            $eligibleVoterIds = collect($resolution->electorate_at_open)->map(function ($item) {
                if (is_array($item)) {
                    return $item['board_member_id'] ?? null;
                }
                if (is_object($item)) {
                    return $item->board_member_id ?? null;
                }

                return $item;
            })->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        } elseif ($committeeId) {
            $eligibleVoterIds = CommitteeMembership::where('board_committee_id', $committeeId)
                ->active()
                ->whereNotIn('role', ['adviser', 'observer'])
                ->where(function ($q) {
                    $q->whereNull('has_voting_seat')->orWhere('has_voting_seat', true);
                })
                ->whereHas('boardMember', fn ($q) => $q->eligibleVoters())
                ->pluck('board_member_id')
                ->unique()
                ->values()
                ->all();
        } else {
            $eligibleVoterIds = BoardMember::eligibleVoters()->pluck('id')->all();
        }

        $totalEligible = count($eligibleVoterIds);

        // 2. Determine quorum requirement from active profile or standard majority floor(N/2) + 1
        $profile = null;
        if ($resolution && isset($resolution->paper_snapshot['voting_profile']) && is_array($resolution->paper_snapshot['voting_profile'])) {
            $profile = new \App\Domain\Governance\Models\GovernanceVotingProfile;
            $profile->forceFill($resolution->paper_snapshot['voting_profile']);
        } elseif ($resolution?->voting_profile_id) {
            $profile = \App\Domain\Governance\Models\GovernanceVotingProfile::find($resolution->voting_profile_id);
        }
        if (! $profile) {
            $profile = $this->profileService->getActiveProfile($committeeId ? 'committee' : 'board', $committeeId);
        }

        $required = $this->profileService->calculateQuorumRequired($totalEligible, $profile);

        // 3. Participating / present count (recused members excluded from presence, never double-counted)
        $recusedMemberIds = [];
        if ($resolution) {
            $recusedMemberIds = $resolution->conflictDeclarations()
                ->where('withdrew_from_voting', true)
                ->pluck('board_member_id')
                ->all();
        }

        if (! $meetingId) {
            if ($resolution) {
                // Out-of-session written resolution: distinct eligible votes not recused
                $participatingCount = $resolution->votes()
                    ->whereIn('board_member_id', $eligibleVoterIds)
                    ->whereNotIn('board_member_id', $recusedMemberIds)
                    ->distinct('board_member_id')
                    ->count();

                $met = ($totalEligible > 0) && ($participatingCount >= $required);

                return [
                    'present' => $participatingCount,
                    'apologies' => 0,
                    'total_eligible' => $totalEligible,
                    'required' => $required,
                    'met' => $met,
                    'percentage_present' => $totalEligible > 0 ? round(($participatingCount / $totalEligible) * 100, 1) : 0,
                    'resolution_mode' => 'out_of_session',
                ];
            }

            return [
                'present' => 0,
                'apologies' => 0,
                'total_eligible' => $totalEligible,
                'required' => 0,
                'met' => $totalEligible > 0,
                'percentage_present' => 0,
                'resolution_mode' => 'out_of_session',
            ];
        }

        $meeting = \App\Domain\Governance\Models\GovernanceMeeting::findOrFail($meetingId);

        $attendances = $meeting->attendances()
            ->whereIn('board_member_id', $eligibleVoterIds)
            ->get();

        $presentAttendances = $attendances->where('status', 'present');
        if (! empty($recusedMemberIds)) {
            $presentAttendances = $presentAttendances->whereNotIn('board_member_id', $recusedMemberIds);
        }
        $present = $presentAttendances->count();
        $apologies = $attendances->where('status', 'apology')->count();

        // If meeting specifies higher quorum percentage above 50%, calculate higher requirement
        // Priority: frozen meeting_quorum_required from paper_snapshot if resolution was opened
        $quorumPct = 50;
        if ($resolution && isset($resolution->paper_snapshot['meeting_quorum_required'])) {
            $quorumPct = (int) $resolution->paper_snapshot['meeting_quorum_required'];
        } elseif ($meeting->quorum_required) {
            $quorumPct = (int) $meeting->quorum_required;
        }

        if ($quorumPct > 50 && $totalEligible > 0) {
            $pctRequired = (int) ceil($totalEligible * ($quorumPct / 100));
            if ($pctRequired > $required) {
                $required = $pctRequired;
            }
        }

        $met = ($totalEligible > 0) && ($present >= $required);

        return [
            'present' => $present,
            'apologies' => $apologies,
            'total_eligible' => $totalEligible,
            'required' => $required,
            'met' => $met,
            'percentage_present' => $totalEligible > 0 ? round(($present / $totalEligible) * 100, 1) : 0,
            'resolution_mode' => 'meeting',
        ];
    }

    /**
     * Check if quorum is met for a resolution
     */
    public function isQuorumMet(Resolution $resolution): bool
    {
        if (! $resolution->quorum_required) {
            return true;
        }

        $quorum = $this->calculateQuorum($resolution->governance_meeting_id, $resolution);

        return $quorum['met'];
    }

    /**
     * Get voting results with detailed breakdown
     */
    public function getVotingResults(Resolution $resolution): array
    {
        $snapshot = $resolution->vote_summary['decision_snapshot'] ?? null;
        if ($snapshot && $resolution->isClosed()) {
            $summary = $snapshot['vote_summary'] ?? $resolution->vote_summary ?? [];
            $total = ($summary['for'] ?? 0) + ($summary['against'] ?? 0);
            $forPct = $total > 0 ? round((($summary['for'] ?? 0) / $total) * 100, 1) : 0;
            $againstPct = $total > 0 ? round((($summary['against'] ?? 0) / $total) * 100, 1) : 0;
            $threshold = $snapshot['threshold'] ?? $resolution->voting_threshold;
            // Snapshots frozen before applied_threshold was recorded: derive it.
            $applied = $snapshot['applied_threshold'] ?? $resolution->appliedThreshold();

            return [
                'summary' => $summary,
                'percentages' => [
                    'for' => $forPct,
                    'against' => $againstPct,
                ],
                'outcome' => $snapshot['outcome'] ?? $resolution->outcome,
                'threshold' => $threshold,
                'applied_threshold' => $applied,
                'written_unanimity_applied' => (bool) ($snapshot['written_unanimity_applied'] ?? ($applied === 'unanimous' && $threshold !== 'unanimous')),
                'quorum_met' => $snapshot['quorum_met'] ?? true,
                'quorum_details' => $snapshot['quorum_details'] ?? null,
                'closed_at' => $snapshot['closed_at'] ?? $resolution->closed_at?->toIso8601String(),
                'individual_votes' => collect($snapshot['individual_votes'] ?? [])->map(fn ($v) => [
                    'board_member' => $v['board_member_name'] ?? 'Unknown',
                    'vote' => $v['vote'] ?? null,
                    'vote_note' => $v['vote_note'] ?? null,
                    'voted_at' => $v['voted_at'] ?? null,
                ])->all(),
                'conflicts' => collect($snapshot['conflicts'] ?? [])->map(fn ($c) => [
                    'board_member' => $c['board_member_name'] ?? 'Unknown',
                    'type' => $c['type'] ?? null,
                    'description' => $c['description'] ?? null,
                    'withdrew' => $c['withdrew_from_voting'] ?? false,
                ])->all(),
                'is_frozen' => true,
                'decision_snapshot' => $snapshot,
            ];
        }

        $votes = $resolution->votes;
        $summary = $resolution->vote_summary ?? $resolution->calculateVoteSummary();

        $total = ($summary['for'] ?? 0) + ($summary['against'] ?? 0);
        $forPct = $total > 0 ? round((($summary['for'] ?? 0) / $total) * 100, 1) : 0;
        $againstPct = $total > 0 ? round((($summary['against'] ?? 0) / $total) * 100, 1) : 0;
        $applied = $resolution->appliedThreshold();

        return [
            'summary' => $summary,
            'percentages' => [
                'for' => $forPct,
                'against' => $againstPct,
            ],
            'outcome' => $resolution->outcome,
            'threshold' => $resolution->voting_threshold,
            'applied_threshold' => $applied,
            'written_unanimity_applied' => $applied === 'unanimous' && $resolution->voting_threshold !== 'unanimous',
            'quorum_met' => $this->isQuorumMet($resolution),
            'quorum_details' => null,
            'closed_at' => $resolution->closed_at?->toIso8601String(),
            'individual_votes' => $votes->map(fn ($v) => [
                'board_member' => $v->boardMember?->full_name,
                'vote' => $v->vote,
                'vote_note' => $v->vote_note,
                'voted_at' => $v->voted_at?->toDateTimeString(),
            ])->all(),
            'conflicts' => $resolution->conflictDeclarations->map(fn ($c) => [
                'board_member' => $c->boardMember?->full_name,
                'type' => $c->declaration_type,
                'description' => $c->declaration_text,
                'withdrew' => $c->withdrew_from_voting,
            ])->all(),
            'is_frozen' => false,
        ];
    }

    /**
     * Open resolutions still waiting for this member's vote: not voted, not
     * stepped aside, and voting hasn't passed its deadline. Meeting votes
     * without a deadline are included.
     */
    public function getPendingVotes(int $boardMemberId): Collection
    {
        BoardMember::findOrFail($boardMemberId);

        return Resolution::where('status', 'open')
            ->whereDoesntHave('votes', fn ($q) => $q->where('board_member_id', $boardMemberId))
            ->whereDoesntHave('conflictDeclarations', fn ($q) => $q->where('board_member_id', $boardMemberId)
                ->where('withdrew_from_voting', true)
            )
            ->where(fn ($q) => $q->whereNull('deadline')->orWhere('deadline', '>', now()))
            ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END')
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
                'description' => 'You are named in the background to this resolution.',
            ];
        }

        // Check if board member is the proposer
        if ($resolution->proposed_by === $boardMember->user_id) {
            $conflicts[] = [
                'type' => 'proposer',
                'severity' => 'low',
                'description' => 'You wrote this resolution.',
            ];
        }

        return $conflicts;
    }

    /**
     * Send voting reminders to members who haven't voted
     */
    public function sendVotingReminders(Resolution $resolution): int
    {
        if (! $resolution->isOpen()) {
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

    private function committeeName(int $committeeId): string
    {
        $name = BoardCommittee::query()->whereKey($committeeId)->value('name');

        return $name ? "the {$name}" : 'this committee';
    }
}
