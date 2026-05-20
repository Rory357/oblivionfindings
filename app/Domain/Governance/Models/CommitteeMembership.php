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
        'appointed_at',
        'term_end',
        'is_active',
    ];

    protected $casts = [
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
            ->where(function ($q) {
                $q->whereNull('term_end')
                    ->orWhere('term_end', '>=', now());
            });
    }

    public function isChair(): bool
    {
        return $this->role === 'chair';
    }
}
