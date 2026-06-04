<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site's emergency / evacuation plan and its periodic review obligation —
 * a core supported-living requirement. The Site Calendar surfaces the
 * `next_review_at` date (auto-derived from `last_reviewed_at + review_interval`
 * when not set) via the `emergency` obligation source.
 */
class SiteEmergencyPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'tenant_id',
        'plan_type',
        'title',
        'last_reviewed_at',
        'review_interval_months',
        'next_review_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'last_reviewed_at' => 'date',
        'next_review_at' => 'date',
        'review_interval_months' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * The effective review-due date: the explicit `next_review_at`, else derived
     * from the last review plus the configured interval.
     */
    public function dueDate(): ?\Illuminate\Support\Carbon
    {
        if ($this->next_review_at) {
            return $this->next_review_at->copy();
        }

        if ($this->last_reviewed_at) {
            return $this->last_reviewed_at->copy()->addMonths($this->review_interval_months ?: 12);
        }

        return null;
    }
}
