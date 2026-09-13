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

    public function validateForPublication(): array
    {
        $errors = [];

        if (empty(trim((string) $this->title))) {
            $errors['title'] = 'Resolution title is required.';
        }

        $motion = trim((string) ($this->exact_motion ?? ''));
        if (empty($motion)) {
            $errors['exact_motion'] = 'The exact motion (operative text voted upon) is required.';
        }

        $context = trim((string) ($this->context ?? ''));
        if (empty($context)) {
            $errors['context'] = 'Background context and justification (why now) are required.';
        }

        $purpose = $this->purpose ?? 'decision';
        if (! in_array($purpose, ['decision', 'discussion', 'information'], true)) {
            $errors['purpose'] = 'A valid paper purpose (decision, discussion, or information) must be specified.';
        }

        if ($purpose === 'decision') {
            $options = is_array($this->options) ? $this->options : [];
            $validOptions = array_filter($options, fn ($opt) => ! empty(trim((string) ($opt['label'] ?? ''))));

            if (count($validOptions) < 2 && empty(trim((string) ($this->single_option_reason ?? '')))) {
                $errors['options'] = 'At least two substantive options must be evaluated for consequential decisions, or a specific justification provided why only a single option applies.';
            }

            if (empty(trim((string) ($this->recommendation ?? '')))) {
                $errors['recommendation'] = 'A management recommendation and supporting rationale are required for decision papers.';
            }
        }

        // Financial implications
        $costImpact = is_array($this->cost_impact) ? $this->cost_impact : [];
        $hasCostDetails = ! empty($costImpact['amount']) || ! empty($costImpact['funding_source']) || ! empty($costImpact['budget_source']) || ! empty($costImpact['description']);
        $isExplicitNone = ! empty($costImpact['is_none']) || (isset($costImpact['has_cost']) && $costImpact['has_cost'] === false);
        if (! $hasCostDetails && ! $isExplicitNone) {
            $errors['cost_impact'] = 'Financial implications must be specified (amount and funding source, or explicit confirmation of no financial cost).';
        }

        // Risk / Safety / Equity implications
        $riskImpact = is_array($this->risk_impact) ? $this->risk_impact : [];
        $hasRisk = ! empty($riskImpact['level']) || ! empty($riskImpact['description']) || ! empty($this->service_user_implications) || ! empty($this->risk_equity_implications);
        $isRiskNone = ! empty($riskImpact['is_none']) || (isset($riskImpact['level']) && $riskImpact['level'] === 'none');
        if (! $hasRisk && ! $isRiskNone) {
            $errors['risk_impact'] = 'Safety, service-user, and risk/equity implications must be documented (or marked not applicable).';
        }

        return $errors;
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
        if (empty($this->paper_snapshot)) {
            $this->freezePaperSnapshot();
        }

        $electorate = $this->captureElectorateSnapshot();

        $this->update([
            'status' => 'open',
            'opened_at' => now(),
            'deadline' => $deadline ?? $this->deadline,
            'electorate_at_open' => $electorate,
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

        $snapshot = [
            'resolution_id' => $this->id,
            'resolution_reference' => $this->resolution_reference,
            'title' => $this->title,
            'closed_at' => now()->toIso8601String(),
            'threshold' => $this->voting_threshold,
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

            // Resolve valid assignee user
            $assigneeId = $action['assigned_to'] ?? $action['assignee_id'] ?? null;
            if (! $assigneeId || ! \App\Models\User::where('id', $assigneeId)->exists()) {
                if (! empty($action['assignee_name'])) {
                    $matchedUser = \App\Models\User::where('name', $action['assignee_name'])->first();
                    if ($matchedUser) {
                        $assigneeId = $matchedUser->id;
                    }
                }
            }
            if (! $assigneeId || ! \App\Models\User::where('id', $assigneeId)->exists()) {
                $assigneeId = $this->proposed_by ?? auth()->id() ?? 1;
            }

            $title = $action['title'] ?? ('Follow-up from ' . ($this->resolution_reference ?: "Resolution #{$this->id}"));
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
            throw new \DomainException("Cannot implement resolution with outcome '{$this->outcome}'. Only carried resolutions can be marked as implemented.");
        }

        $actions = $this->actionItems()->get();
        $incompleteActions = $actions->filter(fn ($a) => $a->status !== 'complete');

        if ($incompleteActions->isNotEmpty() && empty($noActionReason)) {
            throw new \DomainException("Cannot mark resolution as implemented: {$incompleteActions->count()} follow-up action(s) remain uncompleted. All actions must be completed or an authorised no-action reason provided.");
        }

        if ($actions->isEmpty() && empty($noActionReason) && empty($notes)) {
            $notes = 'Marked as implemented by governance authority.';
        }

        $outcomeNote = $noActionReason 
            ? "Implemented (Authorised no-action reason: {$noActionReason})" . ($notes ? " - {$notes}" : "")
            : ($notes ?? $this->outcome_notes);

        $this->update([
            'status' => 'implemented',
            'outcome_notes' => $outcomeNote,
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

        $isWrittenUnanimityRequired = false;
        if ($this->isOutOfSession()) {
            $profile = $this->voting_profile_id ? GovernanceVotingProfile::find($this->voting_profile_id) : null;
            if (! $profile) {
                $committeeId = $this->board_committee_id ?? $this->meeting?->board_committee_id;
                $profile = app(GovernanceVotingProfileService::class)->getActiveProfile($committeeId ? 'committee' : 'board', $committeeId);
            }
            if ($profile && $profile->written_unanimity_required) {
                $isWrittenUnanimityRequired = true;
            }
        }

        if ($this->voting_threshold === 'unanimous' || $isWrittenUnanimityRequired) {
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

        return match ($this->voting_threshold) {
            'two_thirds' => ($for * 3 >= $totalCast * 2) && ($for > 0) ? 'carried' : 'defeated',
            'simple_majority' => $for > $against ? 'carried' : 'defeated',
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
