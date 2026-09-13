<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MeetingMinute extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'governance_meeting_id',
        'content_blocks',
        'version_number',
        'status',
        'version_history',
        'drafted_by',
        'drafted_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'signed_by',
        'signed_at',
        'archived_at',
        'approval_resolution_id',
    ];

    protected $casts = [
        'content_blocks' => 'array',
        'version_history' => 'array',
        'drafted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'signed_at' => 'datetime',
        'archived_at' => 'datetime',
    ];

    protected $appends = [
        'content_hash',
        'reviewer_name',
        'signer_name',
        'drafter_name',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(GovernanceMeeting::class, 'governance_meeting_id');
    }

    public function draftedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'drafted_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class, 'reviewed_by');
    }

    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class, 'signed_by');
    }

    public function approvalResolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class, 'approval_resolution_id');
    }

    public function getContentHashAttribute(): string
    {
        return hash('sha256', json_encode($this->content_blocks ?? []));
    }

    public function getDrafterNameAttribute(): ?string
    {
        return $this->draftedBy?->name;
    }

    public function getReviewerNameAttribute(): ?string
    {
        if ($this->reviewedBy && $this->reviewedBy->user) {
            return $this->reviewedBy->user->name;
        }
        if ($this->reviewed_by && ($user = User::find($this->reviewed_by))) {
            return $user->name;
        }
        if ($this->reviewed_at) {
            return 'Legacy attribution unavailable';
        }
        return null;
    }

    public function getSignerNameAttribute(): ?string
    {
        if ($this->signedBy && $this->signedBy->user) {
            return $this->signedBy->user->name;
        }
        if ($this->signed_by && ($user = User::find($this->signed_by))) {
            return $user->name;
        }
        if ($this->signed_at) {
            return 'Legacy attribution unavailable';
        }
        return null;
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isUnderReview(): bool
    {
        return $this->status === 'reviewed';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isSigned(): bool
    {
        return $this->status === 'signed';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function canEdit(): bool
    {
        return in_array($this->status, ['draft', 'reviewed']);
    }

    public function canSign(): bool
    {
        return $this->status === 'approved';
    }

    public function canArchive(): bool
    {
        return $this->status === 'signed';
    }

    public function hasReviewableContent(): bool
    {
        if (empty($this->content_blocks) || !is_array($this->content_blocks)) {
            return false;
        }
        foreach ($this->content_blocks as $block) {
            if (!empty(trim($block['content'] ?? ''))) {
                return true;
            }
        }
        return false;
    }

    public function incrementVersion(): void
    {
        $currentHistory = $this->version_history ?? [];
        $currentHistory[] = [
            'version' => $this->version_number,
            'status' => $this->status,
            'content_blocks' => $this->content_blocks,
            'content_hash' => hash('sha256', json_encode($this->content_blocks ?? [])),
            'updated_at' => now()->toIso8601String(),
        ];

        $this->update([
            'version_number' => $this->version_number + 1,
            'version_history' => $currentHistory,
        ]);
    }

    /**
     * State machine: Draft -> Reviewed -> Approved -> Signed -> Archived
     */
    public function advanceStatus(string $newStatus, int $actorId): bool
    {
        $validTransitions = [
            'draft' => ['reviewed', 'approved'],
            'reviewed' => ['draft', 'approved'],
            'approved' => ['signed'],
            'signed' => ['archived'],
        ];

        $allowed = $validTransitions[$this->status] ?? [];
        if (!in_array($newStatus, $allowed)) {
            return false;
        }

        $updates = ['status' => $newStatus];

        match($newStatus) {
            'reviewed' => $updates = array_merge($updates, [
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
            ]),
            'approved' => $updates = array_merge($updates, [
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
            ]),
            'signed' => $updates = array_merge($updates, [
                'signed_by' => $actorId,
                'signed_at' => now(),
            ]),
            'archived' => $updates = array_merge($updates, [
                'archived_at' => now(),
            ]),
            default => null,
        };

        $this->update($updates);
        return true;
    }

    public function sign(int $actorId): bool
    {
        return $this->advanceStatus('signed', $actorId);
    }

    public function archive(): bool
    {
        return $this->advanceStatus('archived', auth()->id() ?? 0);
    }
}
