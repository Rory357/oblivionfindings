<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\AuditableChanges;

/**
 * One board member's recorded vote on a resolution.
 *
 * `vote_note` is the member's optional reason for their vote.
 * `conflict_declared` is only ever true when the member made a real
 * conflict-of-interest declaration (ConflictDeclaration) on the resolution
 * and still voted — a note never sets it. `conflict_note` is kept for rows
 * recorded before the 2026-09-14 repair.
 */
class Vote extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'resolution_id',
        'board_member_id',
        'vote',
        'voted_at',
        'voting_method',
        'conflict_declared',
        'conflict_note',
        'vote_note',
        'vote_hash',
        'recorded_by',
    ];

    protected $casts = [
        'voted_at' => 'datetime',
        'conflict_declared' => 'boolean',
    ];

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class);
    }

    public function boardMember(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isFor(): bool
    {
        return $this->vote === 'for';
    }

    public function isAgainst(): bool
    {
        return $this->vote === 'against';
    }

    public function isAbstain(): bool
    {
        return $this->vote === 'abstain';
    }

    /** The receipt reference members also see in My work. */
    public function receiptId(): string
    {
        return "VOTE-RCP-{$this->id}";
    }

    public function generateHash(): string
    {
        $data = [
            'resolution_id' => $this->resolution_id,
            'board_member_id' => $this->board_member_id,
            'vote' => $this->vote,
            'voted_at' => $this->voted_at?->toIso8601String(),
        ];
        return hash('sha256', json_encode($data));
    }

    public function verifyIntegrity(): bool
    {
        return hash_equals($this->vote_hash, $this->generateHash());
    }

    protected static function boot(): void
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->vote_hash)) {
                $model->vote_hash = $model->generateHash();
            }
        });
    }
}
