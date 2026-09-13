<?php

namespace App\Domain\Governance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\AuditableChanges;
class CommitteeMembership extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'board_committee_id',
        'board_member_id',
        'role',
        'has_voting_seat',
        'appointed_at',
        'term_end',
        'is_active',
    ];

    protected $casts = [
        'has_voting_seat' => 'boolean',
        'appointed_at' => 'date',
        'term_end' => 'date',
        'is_active' => 'boolean',
    ];

    public function committee(): BelongsTo
    {
        return $this->belongsTo(BoardCommittee::class, 'board_committee_id');
    }

    public function boardMember(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->whereDate('appointed_at', '<=', today())
            ->where(function ($q) {
                $q->whereNull('term_end')
                    ->orWhereDate('term_end', '>=', today());
            });
    }

    public function isChair(): bool
    {
        return $this->role === 'chair';
    }

    public function canVote(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $today = today();
        if ($this->appointed_at && $this->appointed_at->isAfter($today)) {
            return false;
        }

        if ($this->term_end && $this->term_end->isBefore($today)) {
            return false;
        }

        if (in_array($this->role, ['adviser', 'observer'], true)) {
            return false;
        }

        if ($this->has_voting_seat === false) {
            return false;
        }

        return $this->boardMember ? $this->boardMember->canVote() : true;
    }
}
