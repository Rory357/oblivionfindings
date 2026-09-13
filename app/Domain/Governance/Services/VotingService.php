<?php

namespace App\Domain\Governance\Services;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\CommitteeMembership;
use App\Domain\Governance\Models\ConflictDeclaration;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Models\Vote;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VotingService
{
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

            if (!$lockedRes->isDraft()) {
                throw new \InvalidArgumentException('Resolution must be in draft status to open voting');
            }

            // Publication validation: verify motion, context, options/recommendation, implications
            $validationErrors = $lockedRes->validateForPublication();
            if (!empty($validationErrors)) {
                throw new \DomainException('Resolution paper cannot be published for voting: ' . implode(' ', $validationErrors));
            }

            $committeeId = $lockedRes->board_committee_id ?? $lockedRes->meeting?->board_committee_id;
            $profile = $this->profileService->getActiveProfile(
                $committeeId ? 'committee' : 'board',
                $committeeId
            );

            if (! $profile || ! $profile->isConfirmed()) {
                throw new \DomainException(
                    'Voting rules not confirmed — live voting unavailable. A secretary or chair must activate an approved voting profile with governing document authority.'
                );
            }

            if ($lockedRes->isOutOfSession() && ! $profile->written_voting_permitted) {
                throw new \DomainException(
                    'Written / out-of-session voting is prohibited by active voting rules profile.'
                );
            }

            $lockedRes->voting_profile_id = $profile->id;
            $effectiveDeadline = $deadline ?? $lockedRes->deadline;
            $lockedRes->openForVoting($effectiveDeadline);
        });

        $resolution->refresh();
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
        if (!in_array($vote, ['for', 'against', 'abstain'], true)) {
            throw new \InvalidArgumentException("Invalid vote value '{$vote}'. Must be for, against, or abstain.");
        }

        return DB::transaction(function () use ($resolution, $boardMember, $vote, $method, $conflictNote) {
            $lockedRes = Resolution::where('id', $resolution->id)->lockForUpdate()->firstOrFail();

            if (!$lockedRes->isOpen()) {
                throw new \InvalidArgumentException('Voting is not open for this resolution');
            }

            if ($lockedRes->isOverdue()) {
                throw new \InvalidArgumentException('Voting deadline has passed');
            }

            // Validate that the member is eligible to vote
            $freshMember = BoardMember::findOrFail($boardMember->id);
            if (!$freshMember->canVote()) {
                throw new \InvalidArgumentException('Board member is not eligible to vote');
            }

            // Enforce frozen electorate if captured at opening
            if (!empty($lockedRes->electorate_at_open)) {
                $electorateIds = collect($lockedRes->electorate_at_open)->map(function ($item) {
                    if (is_array($item)) {
                        return $item['board_member_id'] ?? null;
                    }
                    if (is_object($item)) {
                        return $item->board_member_id ?? null;
                    }
                    return $item;
                })->filter()->all();

                if (!in_array((int) $freshMember->id, array_map('intval', $electorateIds), true)) {
                    throw new \DomainException('Board member was not part of the electorate when voting opened.');
                }
            }

            // Validate committee electorate if committee resolution
            $committeeId = $lockedRes->board_committee_id ?? $lockedRes->meeting?->board_committee_id;
            if ($committeeId) {
                $membership = CommitteeMembership::where('board_committee_id', $committeeId)
                    ->where('board_member_id', $freshMember->id)
                    ->active()
                    ->first();

                if (!$membership || !$membership->canVote()) {
                    throw new \InvalidArgumentException('Board member is not an eligible voting member of this committee');
                }
            }

            // Check if member has recused from voting
            $conflict = ConflictDeclaration::where('resolution_id', $lockedRes->id)
                ->where('board_member_id', $freshMember->id)
                ->where('withdrew_from_voting', true)
                ->first();

            if ($conflict) {
                throw new \InvalidArgumentException('Board member has recused and withdrawn from voting on this resolution');
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
                throw new \InvalidArgumentException('Board member has already cast a vote on this resolution');
            }

            return Vote::create([
                'resolution_id' => $lockedRes->id,
                'board_member_id' => $freshMember->id,
                'vote' => $vote,
                'voted_at' => now(),
                'voting_method' => $method,
                'conflict_declared' => !is_null($conflictNote),
                'conflict_note' => $conflictNote,
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
        if (!in_array($type, ['material', 'related', 'prejudicial', 'other'], true)) {
            throw new \InvalidArgumentException("Invalid conflict declaration type '{$type}'");
        }

        if (empty(trim($description))) {
            throw new \InvalidArgumentException('Conflict description is required');
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

            // Recusal is separate from abstention — NEVER create an abstention vote.
            // If the member previously cast a vote and now withdraws, remove the prior vote.
            if ($withdrawFromVoting) {
                Vote::where('resolution_id', $lockedRes->id)
                    ->where('board_member_id', $freshMember->id)
                    ->delete();
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

            if (!$lockedRes->isOpen()) {
                throw new \InvalidArgumentException('Voting is not open');
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
     * Calculate quorum for a meeting or out-of-session resolution
     */
    public function calculateQuorum(?int $meetingId, ?Resolution $resolution = null): array
    {
        $committeeId = $resolution?->board_committee_id;
        if (! $committeeId && $meetingId) {
            $meeting = \App\Domain\Governance\Models\GovernanceMeeting::find($meetingId);
            $committeeId = $meeting?->board_committee_id;
        }

        // 1. Resolve entitled electorate IDs (committee voting members or board eligible voters)
        if ($committeeId) {
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
        if ($resolution?->voting_profile_id) {
            $profile = \App\Domain\Governance\Models\GovernanceVotingProfile::find($resolution->voting_profile_id);
        }
        if (!$profile) {
            $profile = $this->profileService->getActiveProfile($committeeId ? 'committee' : 'board', $committeeId);
        }

        if ($totalEligible <= 0) {
            $required = 0;
        } elseif ($profile && $profile->quorum_mode === 'fixed_count' && is_numeric($profile->quorum_formula)) {
            $required = (int) $profile->quorum_formula;
        } elseif ($profile && $profile->quorum_mode === 'percentage' && is_numeric(rtrim($profile->quorum_formula, '%'))) {
            $pct = (float) rtrim($profile->quorum_formula, '%');
            $required = (int) ceil(($totalEligible * $pct) / 100);
        } else {
            $required = (int) floor($totalEligible / 2) + 1;
        }

        // 3. Participating / present count (recused members excluded from presence, never double-counted)
        $recusedMemberIds = [];
        if ($resolution) {
            $recusedMemberIds = $resolution->conflictDeclarations()
                ->where('withdrew_from_voting', true)
                ->pluck('board_member_id')
                ->all();
        }

        if (!$meetingId) {
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
        if (!empty($recusedMemberIds)) {
            $presentAttendances = $presentAttendances->whereNotIn('board_member_id', $recusedMemberIds);
        }
        $present = $presentAttendances->count();
        $apologies = $attendances->where('status', 'apology')->count();
        
        // If meeting specifies higher quorum percentage above 50%, calculate higher requirement
        $quorumPct = $meeting->quorum_required ?? 50;
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
        if (!$resolution->quorum_required) {
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

            return [
                'summary' => $summary,
                'percentages' => [
                    'for' => $forPct,
                    'against' => $againstPct,
                ],
                'outcome' => $snapshot['outcome'] ?? $resolution->outcome,
                'threshold' => $snapshot['threshold'] ?? $resolution->voting_threshold,
                'quorum_met' => $snapshot['quorum_met'] ?? true,
                'quorum_details' => $snapshot['quorum_details'] ?? null,
                'individual_votes' => collect($snapshot['individual_votes'] ?? [])->map(fn($v) => [
                    'board_member' => $v['board_member_name'] ?? 'Unknown',
                    'vote' => $v['vote'] ?? null,
                    'conflict_declared' => $v['conflict_declared'] ?? false,
                    'voted_at' => $v['voted_at'] ?? null,
                ])->all(),
                'conflicts' => collect($snapshot['conflicts'] ?? [])->map(fn($c) => [
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
                'voted_at' => $v->voted_at?->toDateTimeString(),
            ])->all(),
            'conflicts' => $resolution->conflictDeclarations->map(fn($c) => [
                'board_member' => $c->boardMember?->full_name,
                'type' => $c->declaration_type,
                'description' => $c->declaration_text,
                'withdrew' => $c->withdrew_from_voting,
            ])->all(),
            'is_frozen' => false,
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
