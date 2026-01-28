<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SafeguardingAlert extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'alertable_type',
        'alertable_id',
        'safeguarding_concern_id',
        'alert_type',
        'alert_summary',
        'alert_details',
        'severity',
        'active',
        'expires_at',
        'last_reviewed_at',
        'last_reviewed_by',
        'next_review_date',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'active' => 'boolean',
        'expires_at' => 'datetime',
        'last_reviewed_at' => 'datetime',
        'next_review_date' => 'datetime',
    ];

    /**
     * Alertable (polymorphic - Client or User/Staff).
     */
    public function alertable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Safeguarding concern.
     */
    public function concern(): BelongsTo
    {
        return $this->belongsTo(SafeguardingConcern::class, 'safeguarding_concern_id');
    }

    /**
     * User who last reviewed the alert.
     */
    public function lastReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reviewed_by');
    }

    /**
     * User who created the record.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * User who last updated the record.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scope: Active alerts.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope: Expired alerts.
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Scope: Due for review.
     */
    public function scopeDueForReview($query)
    {
        return $query->where('active', true)
            ->where('next_review_date', '<=', now());
    }

    /**
     * Check if alert is currently active.
     */
    public function isActive(): bool
    {
        return $this->active
            && (!$this->expires_at || $this->expires_at->isFuture());
    }

    /**
     * Check if alert is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Check if alert is due for review.
     */
    public function isDueForReview(): bool
    {
        return $this->active
            && $this->next_review_date
            && $this->next_review_date->isPast();
    }
}
