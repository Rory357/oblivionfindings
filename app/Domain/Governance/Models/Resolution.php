<?php

namespace App\Domain\Governance\Models;

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
        'title',
        'decision_type',
        'context',
        'options',
        'recommendation',
        'cost_impact',
        'risk_impact',
        'attachments',
        'voting_threshold',
        'quorum_required',
        'status',
        'opened_at',
        'closed_at',
        'deadline',
        'outcome',
        'vote_summary',
        'outcome_notes',
        'follow_up_actions',
        'auto_generate_actions',
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
        'auto_generate_actions' => 'boolean',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'deadline' => 'datetime',
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

    public function openForVoting(?\DateTime $deadline = null): void
    {
        $this->update([
            'status' => 'open',
            'opened_at' => now(),
            'deadline' => $deadline,
        ]);
    }

    public function closeVoting(): void
    {
        $summary = $this->calculateVoteSummary();
        $outcome = $this->determineOutcome($summary);

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

    public function generateActionItems(): void
    {
        foreach ($this->follow_up_actions ?? [] as $action) {
            ActionItem::create([
                'governance_meeting_id' => $this->governance_meeting_id,
                'resolution_id' => $this->id,
                'title' => $action['title'] ?? 'Follow-up from ' . $this->resolution_reference,
                'description' => $action['description'] ?? null,
                'assigned_to' => $action['assigned_to'] ?? null,
                'due_date' => isset($action['due_date']) ? \Carbon\Carbon::parse($action['due_date']) : now()->addWeeks(2),
                'priority' => $action['priority'] ?? 'medium',
                'status' => 'open',
            ]);
        }
    }

    public function actionItems(): HasMany
    {
        return $this->hasMany(ActionItem::class);
    }

    public function markImplemented(?string $notes = null): void
    {
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

    public function determineOutcome(array $summary): string
    {
        $total = $summary['for'] + $summary['against'];
        if ($total === 0) {
            return 'defeated';
        }

        $forPct = $summary['for'] / $total;

        return match($this->voting_threshold) {
            'simple_majority' => $forPct > 0.5 ? 'carried' : 'defeated',
            'two_thirds' => $forPct >= (2/3) ? 'carried' : 'defeated',
            'unanimous' => $forPct === 1.0 ? 'carried' : 'defeated',
            default => $forPct > 0.5 ? 'carried' : 'defeated',
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
