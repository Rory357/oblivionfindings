<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BoardCommittee extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'board_committees';

    protected $fillable = [
        'committee_type',
        'name',
        'description',
        'terms_of_reference',
        'chair_id',
        'meeting_frequency',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(BoardMember::class, 'committee_memberships')
            ->withPivot(['role', 'appointed_at', 'term_end', 'is_active'])
            ->withTimestamps();
    }

    public function chair(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class, 'chair_id');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(GovernanceMeeting::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('committee_type', $type);
    }

    public function isAuditRisk(): bool
    {
        return $this->committee_type === 'audit_risk';
    }

    public function isPeople(): bool
    {
        return $this->committee_type === 'people';
    }

    public function isFinance(): bool
    {
        return $this->committee_type === 'finance';
    }
}
