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
        'approval_resolution_id',
    ];

    protected $casts = [
        'content_blocks' => 'array',
        'version_history' => 'array',
        'drafted_at' => 'datetime',
        'reviewed_at' => 'datetime',
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

    public function approvalResolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class, 'approval_resolution_id');
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

    public function incrementVersion(): void
    {
        $this->update([
            'version_number' => $this->version_number + 1,
            'version_history' => array_merge($this->version_history ?? [], [
                [
                    'version' => $this->version_number,
                    'updated_at' => now()->toIso8601String(),
                    'content_hash' => hash('sha256', json_encode($this->content_blocks)),
                ]
            ])
        ]);
    }

    /**
     * State machine: Draft -> Reviewed -> Approved -> Signed -> Archived
     */
    public function advanceStatus(string $newStatus, int $userId): bool
    {
        $validTransitions = [
            'draft' => ['reviewed'],
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
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
            ]),
            'signed' => $updates = array_merge($updates, [
                'signed_by' => $userId,
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

    public function sign(int $userId): bool
    {
        return $this->advanceStatus('signed', $userId);
    }

    public function archive(): bool
    {
        return $this->advanceStatus('archived', auth()->id() ?? 0);
    }
}
