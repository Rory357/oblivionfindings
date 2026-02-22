<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BoardMemberInterest extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'board_member_id', 'interest_type', 'entity_name', 'description',
        'nature', 'declared_at', 'ceased_at', 'is_current', 'notes', 'recorded_by',
    ];

    protected $casts = [
        'declared_at' => 'date',
        'ceased_at' => 'date',
        'is_current' => 'boolean',
    ];

    public function boardMember(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    public function scopeForMember($query, int $memberId)
    {
        return $query->where('board_member_id', $memberId);
    }

    public function cease(): void
    {
        $this->update([
            'is_current' => false,
            'ceased_at' => now(),
        ]);
    }
}
