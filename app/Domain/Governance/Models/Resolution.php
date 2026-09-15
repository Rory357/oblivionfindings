<?php

namespace App\Domain\Governance\Models;

use App\Domain\Governance\Services\GovernanceVotingProfileService;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Resolution extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'resolution_reference',
        'governance_meeting_id',
        'board_committee_id',
        'voting_profile_id',
        'title',
        'exact_motion',
        'purpose',
        'decision_type',
        'context',
        'options',
        'single_option_reason',
        'recommendation',
        'cost_impact',
        'risk_impact',
        'service_user_implications',
        'risk_equity_implications',
        'attachments',
        'voting_threshold',
        'quorum_required',
        'status',
        'version_number',
        'opened_at',
        'closed_at',
        'deadline',
        'outcome',
        'vote_summary',
        'outcome_notes',
        'follow_up_actions',
        'auto_generate_actions',
        'paper_snapshot',
        'electorate_at_open',
        'published_at',
        'published_by',
        'proposed_by',
        'proposed_at',
    ];

    protected $casts = [
        'options' => 'array',
        'vote_summary' => 'array',
        'cost_impact' => 'array',
        'risk_impact' => 'array',
        'attachments' => 'array',
        'follow_up_actions' => 'array',
        'paper_snapshot' => 'array',
        'electorate_at_open' => 'array',
        'auto_generate_actions' => 'boolean',
        'version_number' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'deadline' => 'datetime',
        'published_at' => 'datetime',
        'proposed_at' => 'datetime',
        'quorum_required' => 'boolean',
    ];

    protected static function boot(): void
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->resolution_reference)) {
                $model->resolution_reference = static::generateReference();
            }
        });
    }

    public static function generateReference(): string
    {
        $year = now()->year;
        $prefix = "RES-{$year}-";
        $last = static::whereYear('created_at', $year)->count() + 1;
        return $prefix . str_pad($last, 3, '0', STR_PAD_LEFT);
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(GovernanceMeeting::class, 'governance_meeting_id');
    }

    public function committee(): BelongsTo
    {
        return $this->belongsTo(BoardCommittee::class, 'board_committee_id');
    }

    public function votingProfile(): BelongsTo
    {
        return $this->belongsTo(GovernanceVotingProfile::class, 'voting_profile_id');
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(Vote::class);
    }

    public function conflictDeclarations(): HasMany
    {
        return $this->hasMany(ConflictDeclaration::class);
    }

    public function agendaItem(): HasMany
    {
        return $this->hasMany(MeetingAgendaItem::class);
    }

    /**
     * Explicit records (and revisions) this paper was authored to approve.
     */
    public function authorityBindings(): HasMany
    {
        return $this->hasMany(GovernanceResolutionBinding::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['closed', 'cancelled', 'implemented', 'archived']);
    }

    public function isOverdue(): bool
    {
        return $this->deadline && $this->deadline->isPast() && $this->isOpen();
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function isCarried(): bool
    {
        return $this->outcome === 'carried';
    }

    public function isOutOfSession(): bool
    {
        return empty($this->governance_meeting_id);
    }

    /**
     * For decision papers go to a vote. For discussion / For information
     * papers are shared with members and never voted on.
     */
    public function isDecisionPaper(): bool
    {
        return ($this->purpose ?? 'decision') === 'decision';
    }

    /**
     * What still stops this paper being published. Messages match the
     * authoring wizard's plain wording (vocabulary.md).
     *
     * @return array<string, string>
     */
    public function validateForPublication(): array
    {
        $errors = [];

        if (empty(trim((string) $this->title))) {
            $errors['title'] = 'Give the resolution a title.';
        }

        $motion = trim((string) ($this->exact_motion ?? ''));
        if (empty($motion)) {
            $errors['exact_motion'] = 'Write the resolution wording — the exact words the board votes on.';
        }

        $context = trim((string) ($this->context ?? ''));
        if (empty($context)) {
            $errors['context'] = 'Explain why this is before the board now.';
        }

        $purpose = $this->purpose ?? 'decision';
        if (! in_array($purpose, ['decision', 'discussion', 'information'], true)) {
            $errors['purpose'] = 'Choose whether this paper is for decision, for discussion or for information.';
        }

        if ($purpose === 'decision') {
            $options = is_array($this->options) ? $this->options : [];
            $validOptions = array_filter($options, fn ($opt) => ! empty(trim((string) ($opt['label'] ?? ''))));

            if (count($validOptions) < 2 && empty(trim((string) ($this->single_option_reason ?? '')))) {
                $errors['options'] = "Describe at least two options the board could choose, or explain why there's only one option.";
            }

            if (empty(trim((string) ($this->recommendation ?? '')))) {
                $errors['recommendation'] = "Add management's recommendation and the reason for it.";
            }

            // A vote outside a meeting has no meeting to close it: members
            // need to know when voting ends.
            if ($this->isOutOfSession() && empty($this->deadline)) {
                $errors['deadline'] = 'Set a voting deadline — votes outside a meeting (written resolutions) need one.';
            }
        }

        // Financial implications
        $costImpact = is_array($this->cost_impact) ? $this->cost_impact : [];
        $hasCostDetails = ! empty($costImpact['amount']) || ! empty($costImpact['funding_source']) || ! empty($costImpact['budget_source']) || ! empty($costImpact['description']);
        $isExplicitNone = ! empty($costImpact['is_none']) || (isset($costImpact['has_cost']) && $costImpact['has_cost'] === false);
        if (! $hasCostDetails && ! $isExplicitNone) {
            $errors['cost_impact'] = "Say what it will cost and where the money comes from, or confirm there's no cost.";
        }

        // Risk / Safety / Equity implications
        $riskImpact = is_array($this->risk_impact) ? $this->risk_impact : [];
        $hasRisk = ! empty($riskImpact['level']) || ! empty($riskImpact['description']) || ! empty($this->service_user_implications) || ! empty($this->risk_equity_implications);
        $isRiskNone = ! empty($riskImpact['is_none']) || (isset($riskImpact['level']) && $riskImpact['level'] === 'none');
        if (! $hasRisk && ! $isRiskNone) {
            $errors['risk_impact'] = 'Describe the effect on the people we support and safety, and the risks and fairness.';
        }

        return $errors;
    }

    /**
     * Written resolutions (no meeting) follow the board's "written votes need
     * everyone's agreement" rule when their voting rules say so — the rule
     * frozen at open wins, otherwise the linked or active profile.
     */
    public function writtenUnanimityApplies(?GovernanceVotingProfile $fallbackProfile = null): bool
    {
        if (! $this->isOutOfSession()) {
            return false;
        }

        if (isset($this->paper_snapshot['voting_profile']) && is_array($this->paper_snapshot['voting_profile'])) {
            return ! empty($this->paper_snapshot['voting_profile']['written_unanimity_required']);
        }

        $profile = $this->voting_profile_id ? GovernanceVotingProfile::find($this->voting_profile_id) : null;
        if (! $profile) {
            $profile = $fallbackProfile;
        }
        if (! $profile) {
            $committeeId = $this->board_committee_id ?? $this->meeting?->board_committee_id;
            $profile = app(GovernanceVotingProfileService::class)->getActiveProfile($committeeId ? 'committee' : 'board', $committeeId);
        }

        return (bool) $profile?->written_unanimity_required;
    }

    /**
     * The voting rule the outcome engine actually applies:
     *   - `unanimous` — everyone entitled votes For (also used for written
     *     resolutions when the voting rules require everyone's agreement);
     *   - `two_thirds` (stored as `two_thirds` or `special`) — at least
     *     two-thirds of the votes cast are For;
     *   - anything else — more For than Against.
     */
    public function appliedThreshold(?GovernanceVotingProfile $fallbackProfile = null): string
    {
        if ($this->voting_threshold === 'unanimous' || $this->writtenUnanimityApplies($fallbackProfile)) {
            return 'unanimous';
        }

        return match ($this->voting_threshold) {
            'two_thirds', 'special' => 'two_thirds',
            null, '' => 'simple_majority',
            default => (string) $this->voting_threshold,
        };
    }

    public function freezePaperSnapshot(): array
    {
        $snapshot = [
            'resolution_id' => $this->id,
            'resolution_reference' => $this->resolution_reference,
            'version_number' => $this->version_number ?? 1,
            'title' => $this->title,
            'exact_motion' => $this->exact_motion,
            'purpose' => $this->purpose ?? 'decision',
            'decision_type' => $this->decision_type ?? 'resolution',
            'context' => $this->context,
            'options' => $this->options ?? [],
            'single_option_reason' => $this->single_option_reason,
            'recommendation' => $this->recommendation,
            'cost_impact' => $this->cost_impact ?? [],
            'risk_impact' => $this->risk_impact ?? [],
            'service_user_implications' => $this->service_user_implications,
            'risk_equity_implications' => $this->risk_equity_implications,
            'attachments' => $this->attachments ?? [],
            'follow_up_actions' => $this->follow_up_actions ?? [],
            'voting_threshold' => $this->voting_threshold,
            'quorum_required' => (bool) $this->quorum_required,
            'deadline' => $this->deadline?->toIso8601String(),
            'governance_meeting_id' => $this->governance_meeting_id,
            'board_committee_id' => $this->board_committee_id,
            'authority_bindings' => $this->authorityBindings()
                ->orderBy('id')
                ->get()
                ->map(fn (GovernanceResolutionBinding $binding) => [
                    'subject_type' => $binding->subject_type,
                    'subject_id' => $binding->subject_id,
                    'subject_revision' => $binding->subject_revision,
                    'subject_fingerprint' => $binding->subject_fingerprint,
                    'bound_terms' => $binding->bound_terms,
                ])
                ->values()
                ->all(),
            'frozen_at' => now()->toIso8601String(),
        ];

        $this->update([
            'paper_snapshot' => $snapshot,
            'published_at' => now(),
            'published_by' => auth()->id() ?? $this->proposed_by,
        ]);

        return $snapshot;
    }

    public function captureElectorateSnapshot(): array
    {
        $committeeId = $this->board_committee_id ?? $this->meeting?->board_committee_id;

        return $committeeId
            ? CommitteeMembership::where('board_committee_id', $committeeId)
                ->active()
                ->whereNotIn('role', ['adviser', 'observer'])
                ->where(function ($q) {
                    $q->whereNull('has_voting_seat')->orWhere('has_voting_seat', true);
                })
                ->whereHas('boardMember', fn ($q) => $q->eligibleVoters())
                ->with('boardMember')
                ->get()
                ->map(fn ($m) => [
                    'board_member_id' => $m->board_member_id,
                    'name' => $m->boardMember?->full_name,
                    'role' => $m->role,
                ])->values()->all()
            : BoardMember::eligibleVoters()->get()->map(fn ($m) => [
                'board_member_id' => $m->id,
                'name' => $m->full_name,
                'role' => $m->role,
            ])->values()->all();
    }

    public function openForVoting(?\DateTime $deadline = null): void
    {
        $snapshot = $this->paper_snapshot;
        if (empty($snapshot)) {
            $snapshot = $this->freezePaperSnapshot();
        }

        $electorate = $this->captureElectorateSnapshot();

        $this->update([
            'status' => 'open',
            'opened_at' => now(),
            'deadline' => $deadline ?? $this->deadline,
            'electorate_at_open' => $electorate,
            'paper_snapshot' => $snapshot,
        ]);
    }

    public function closeVoting(?array $quorumSnapshot = null): void
    {
        $summary = $this->calculateVoteSummary();
        $isQuorumMet = $quorumSnapshot !== null ? (bool) ($quorumSnapshot['met'] ?? true) : null;
        $outcome = $this->determineOutcome($summary, $isQuorumMet);

        $votes = $this->votes()->with('boardMember.user')->get();
        $conflicts = $this->conflictDeclarations()->with('boardMember.user')->get();

        // Electorate snapshot: use frozen electorate at open or resolve current
        $electorateMembers = $this->electorate_at_open ?? $this->captureElectorateSnapshot();

        $appliedThreshold = $this->appliedThreshold();

        $snapshot = [
            'resolution_id' => $this->id,
            'resolution_reference' => $this->resolution_reference,
            'title' => $this->title,
            'closed_at' => now()->toIso8601String(),
            'threshold' => $this->voting_threshold,
            // The rule the outcome was actually decided by (a written
            // resolution may need everyone's agreement).
            'applied_threshold' => $appliedThreshold,
            'written_unanimity_applied' => $appliedThreshold === 'unanimous' && $this->voting_threshold !== 'unanimous',
            'quorum_required' => (bool) $this->quorum_required,
            'quorum_met' => $isQuorumMet ?? true,
            'quorum_details' => $quorumSnapshot,
            'electorate' => $electorateMembers,
            'vote_summary' => $summary,
            'outcome' => $outcome,
            'individual_votes' => $votes->map(fn ($v) => [
                'id' => $v->id,
                'board_member_id' => $v->board_member_id,
                'board_member_name' => $v->boardMember?->full_name,
                'vote' => $v->vote,
                'method' => $v->voting_method,
                'conflict_declared' => (bool) $v->conflict_declared,
                'vote_note' => $v->vote_note,
                'voted_at' => $v->voted_at?->toIso8601String(),
            ])->all(),
            'conflicts' => $conflicts->map(fn ($c) => [
                'id' => $c->id,
                'board_member_id' => $c->board_member_id,
                'board_member_name' => $c->boardMember?->full_name,
                'type' => $c->declaration_type,
                'description' => $c->declaration_text,
                'withdrew_from_voting' => (bool) $c->withdrew_from_voting,
                'declared_at' => $c->declared_at?->toIso8601String(),
            ])->all(),
        ];

        $summary['decision_snapshot'] = $snapshot;

        $this->update([
            'status' => 'closed',
            'closed_at' => now(),
            'vote_summary' => $summary,
            'outcome' => $outcome,
        ]);

        // Auto-generate action items from follow_up_actions if resolution carried
        if ($outcome === 'carried' && $this->auto_generate_actions && !empty($this->follow_up_actions)) {
            $this->generateActionItems();
        }
    }

    public function generateActionItems(): array
    {
        if (empty($this->follow_up_actions)) {
            return [];
        }

        $createdActions = [];

        foreach ($this->follow_up_actions as $index => $action) {
            $followUpKey = "res-{$this->id}-act-{$index}";

            // Idempotency: check if action already exists for this resolution and key/description
            $existing = ActionItem::where('source_type', 'resolution')
                ->where('source_id', $this->id)
                ->where(function ($q) use ($followUpKey, $action) {
                    $q->where('follow_up_key', $followUpKey);
                    if (!empty($action['description']) || !empty($action['title'])) {
                        $q->orWhere('description', $action['description'] ?? $action['title']);
                    }
                })
                ->first();

            if ($existing) {
                $createdActions[] = $existing;
                continue;
            }

            // Resolve valid assignee user: prefer stable ID, and only resolve by name if exactly one user matches
            $assigneeId = $action['assigned_to'] ?? $action['assignee_id'] ?? null;
            if ($assigneeId && ! \App\Models\User::where('id', $assigneeId)->exists()) {
                $assigneeId = null;
            }

            if (! $assigneeId && ! empty($action['assignee_name'])) {
                $matchedUsers = \App\Models\User::where('name', $action['assignee_name'])->get();
                if ($matchedUsers->count() === 1) {
                    $assigneeId = $matchedUsers->first()->id;
                } elseif ($matchedUsers->count() > 1) {
                    throw new \DomainException("More than one person is called {$action['assignee_name']}, so the follow-up action can't be given to the right person. Choose the person responsible for each follow-up action.");
                }
            }

            if (! $assigneeId || ! \App\Models\User::where('id', $assigneeId)->exists()) {
                $label = trim((string) ($action['title'] ?? $action['description'] ?? ''));
                throw new \DomainException(
                    $label !== ''
                        ? "The follow-up action \"{$label}\" doesn't have a person responsible for it, so it can't be created. Choose who is responsible for each follow-up action."
                        : "A follow-up action doesn't have a person responsible for it, so it can't be created. Choose who is responsible for each follow-up action."
                );
            }

            $title = $action['title'] ?? ('Follow-up from '.($this->title ?: 'a resolution'));
            $description = $action['description'] ?? $title;

            $createdActions[] = ActionItem::create([
                'source_type' => 'resolution',
                'source_id' => $this->id,
                'follow_up_key' => $followUpKey,
                'title' => $title,
                'description' => $description,
                'assigned_to' => $assigneeId,
                'due_date' => isset($action['due_date']) ? \Carbon\Carbon::parse($action['due_date']) : now()->addWeeks(2),
                'priority' => $action['priority'] ?? 'medium',
                'evidence_required' => (bool) ($action['evidence_required'] ?? false),
                'created_by' => $this->proposed_by ?? auth()->id() ?? 1,
                'status' => 'open',
                'version_number' => 1,
            ]);
        }

        return $createdActions;
    }

    /**
     * The frozen paper as a safe presentation payload: the snapshot's copy of
     * the supporting documents never carries their storage paths.
     *
     * @return array<string, mixed>|null
     */
    public function presentPaperSnapshot(): ?array
    {
        $snapshot = $this->paper_snapshot;
        if (! is_array($snapshot)) {
            return null;
        }

        if (is_array($snapshot['attachments'] ?? null)) {
            $snapshot['attachments'] = collect($snapshot['attachments'])
                ->filter(fn ($row) => is_array($row))
                ->map(fn (array $row) => \Illuminate\Support\Arr::except($row, ['path']))
                ->values()
                ->all();
        }

        return $snapshot;
    }

    /**
     * Supporting documents as a safe presentation payload (never the stored
     * path). A download URL is issued only when the caller has confirmed the
     * viewer can pass the download route's gates.
     *
     * @return array<int, array{id: ?string, original_name: string, mime_type: ?string, size_bytes: ?int, uploaded_at: ?string, uploaded_by_name: ?string, download_url: ?string}>
     */
    public function presentAttachments(bool $canDownload = true): array
    {
        $existing = is_array($this->attachments) ? $this->attachments : [];

        return collect($existing)
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => [
                'id' => $row['id'] ?? null,
                'original_name' => $row['original_name'] ?? 'attachment',
                'mime_type' => $row['mime_type'] ?? null,
                'size_bytes' => isset($row['size_bytes']) ? (int) $row['size_bytes'] : null,
                'uploaded_at' => $row['uploaded_at'] ?? null,
                'uploaded_by_name' => $row['uploaded_by_name'] ?? null,
                'download_url' => $canDownload && isset($row['id'])
                    ? "/governance/resolutions/{$this->id}/attachments/{$row['id']}/download"
                    : null,
            ])
            ->values()
            ->all();
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(ActionItem::class, 'source_id')
            ->where(function ($q) {
                $q->where('source_type', 'resolution')
                  ->orWhere('source_type', static::class);
            });
    }

    public function markImplemented(?string $notes = null, ?string $noActionReason = null): void
    {
        if ($this->outcome !== 'carried') {
            throw new \DomainException('Only resolutions that passed can be marked as done. This one\'s result is: '.\App\Domain\Governance\Support\GovernanceLabels::label('resolution_outcome', $this->outcome).'.');
        }

        $actions = $this->actionItems()->get();
        $incompleteActions = $actions->filter(fn ($a) => $a->status !== 'complete');

        if ($incompleteActions->isNotEmpty() && empty($noActionReason)) {
            $count = $incompleteActions->count();
            $phrase = $count === 1 ? '1 follow-up action is' : "{$count} follow-up actions are";
            throw new \DomainException("{$phrase} still open. Finish them first, or say why the resolution is done anyway.");
        }

        if ($actions->isEmpty() && empty($noActionReason) && empty($notes)) {
            $notes = 'Marked as done.';
        }

        $outcomeNote = $noActionReason
            ? "Done while follow-up actions were still open, because: {$noActionReason}".($notes ? " — {$notes}" : '')
            : ($notes ?? $this->outcome_notes);

        $this->update([
            'status' => 'implemented',
            'outcome_notes' => $outcomeNote,
        ]);
    }

    /**
     * Share a For discussion / For information paper with members: the
     * wording becomes final (saved copy) and the paper waits for the board
     * to talk about or note it. It never goes to a vote.
     */
    public function publishToMembers(): void
    {
        if ($this->isDecisionPaper()) {
            throw new \DomainException('This paper is for decision, so it goes to a vote. Use "Publish & open voting" instead.');
        }

        if (! $this->isDraft()) {
            throw new \DomainException('Only a draft paper can be published to members.');
        }

        if (empty($this->paper_snapshot)) {
            $this->freezePaperSnapshot();
        }

        $this->update([
            'status' => 'proposed',
            'published_at' => $this->published_at ?? now(),
            'published_by' => $this->published_by ?? auth()->id() ?? $this->proposed_by,
        ]);
    }

    /** Mark a published For discussion / For information paper as done (no vote). */
    public function markDoneWithoutVote(?string $notes = null): void
    {
        if ($this->isDecisionPaper() || $this->status !== 'proposed') {
            throw new \DomainException('Only a published paper that is for discussion or for information can be marked as done without a vote.');
        }

        $this->update([
            'status' => 'implemented',
            'outcome_notes' => $notes ?? $this->outcome_notes,
        ]);
    }

    public function markArchived(?string $notes = null): void
    {
        $this->update([
            'status' => 'archived',
            'outcome_notes' => $notes ?? $this->outcome_notes,
        ]);
    }

    public function calculateVoteSummary(): array
    {
        $votes = $this->votes;
        return [
            'for' => $votes->where('vote', 'for')->count(),
            'against' => $votes->where('vote', 'against')->count(),
            'abstain' => $votes->where('vote', 'abstain')->count(),
            'total_votes' => $votes->count(),
            'conflicts' => $this->conflictDeclarations->where('withdrew_from_voting', true)->count(),
        ];
    }

    /**
     * The outcome under the rule in appliedThreshold():
     *   - no_quorum when quorum is required and not met;
     *   - unanimous: carried only if every entitled voter (frozen electorate)
     *     voted For — any Against, abstention or step-aside defeats it;
     *   - two_thirds (`two_thirds` / `special`): carried if at least
     *     two-thirds of the votes cast (For + Against) are For; abstentions
     *     and members who stepped aside don't count; no For votes → defeated;
     *   - otherwise: carried if more For than Against.
     */
    public function determineOutcome(array $summary, ?bool $isQuorumMet = null, ?int $totalEntitled = null): string
    {
        if ($this->quorum_required && $isQuorumMet === false) {
            return 'no_quorum';
        }

        $for = (int) ($summary['for'] ?? 0);
        $against = (int) ($summary['against'] ?? 0);
        $abstain = (int) ($summary['abstain'] ?? 0);
        $conflicts = (int) ($summary['conflicts'] ?? 0);
        $totalCast = $for + $against;

        $rule = $this->appliedThreshold();

        if ($rule === 'unanimous') {
            // Unanimous requires assent from ALL entitled voters in the frozen electorate
            $entitledCount = $totalEntitled;
            if ($entitledCount === null) {
                $electorate = $this->electorate_at_open ?? $this->captureElectorateSnapshot();
                $entitledCount = is_array($electorate) ? count($electorate) : 0;
            }

            if ($entitledCount <= 0) {
                return 'defeated';
            }

            // Every entitled voter must vote 'for', with 0 against, 0 abstain, 0 conflicts
            if ($for === $entitledCount && $against === 0 && $abstain === 0 && $conflicts === 0) {
                return 'carried';
            }

            return 'defeated';
        }

        if ($totalCast === 0) {
            return 'defeated';
        }

        return match ($rule) {
            'two_thirds' => ($for * 3 >= $totalCast * 2) && ($for > 0) ? 'carried' : 'defeated',
            default => $for > $against ? 'carried' : 'defeated',
        };
    }

    public function hasBoardMemberVoted(int $boardMemberId): bool
    {
        return $this->votes()->where('board_member_id', $boardMemberId)->exists();
    }

    public function getBoardMemberVote(int $boardMemberId): ?Vote
    {
        return $this->votes()->where('board_member_id', $boardMemberId)->first();
    }
}
