<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskAcceptance extends Model
{
    use HasFactory;

    protected $table = 'risk_acceptances';

    protected $fillable = [
        'risk_register_entry_id',
        'acceptance_type',
        'resolution_id',
        'delegated_to_role',
        'justification',
        'conditions',
        'expires_at',
        'accepted_by',
        'accepted_at',
        'review_due_date',
        'review_completed',
        'expiry_notified',
    ];

    protected $casts = [
        'conditions' => 'array',
        'expires_at' => 'date',
        'accepted_at' => 'datetime',
        'review_due_date' => 'date',
        'review_completed' => 'boolean',
        'expiry_notified' => 'boolean',
    ];

    public function risk(): BelongsTo
    {
        return $this->belongsTo(RiskRegisterEntry::class, 'risk_register_entry_id');
    }

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class);
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function isBoardResolution(): bool
    {
        return $this->acceptance_type === 'board_resolution';
    }

    public function isDelegated(): bool
    {
        return $this->acceptance_type === 'delegated_authority';
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        return !$this->isExpired() && $this->expires_at->diffInDays(now()) <= $days;
    }

    public function scopeActive($query)
    {
        return $query->where('expires_at', '>=', now());
    }

    public function scopeExpiring($query, int $days = 30)
    {
        return $query->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }

    public function completeReview(): void
    {
        $this->update([
            'review_completed' => true,
        ]);
    }
}
