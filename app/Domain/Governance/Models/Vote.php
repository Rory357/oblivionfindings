<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vote extends Model
{
    use HasFactory;

    protected $fillable = [
        'resolution_id',
        'board_member_id',
        'vote',
        'voted_at',
        'voting_method',
        'conflict_declared',
        'conflict_note',
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
