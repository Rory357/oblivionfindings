<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteUtility extends Model
{
    use AuditableChanges, WritesLegacyStorageContext;

    protected $fillable = [
        'site_id',
        'type',
        'provider',
        'account_number',
        'monthly_estimate',
        'last_actual_amount',
        'last_actual_date',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'monthly_estimate' => 'decimal:2',
        'last_actual_amount' => 'decimal:2',
        'last_actual_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    /**
     * Get the cost to post: actual if recent, otherwise estimate.
     * "Recent" = last_actual_date is within the same month as the posting period.
     */
    public function getCostForPeriod(string $periodMonth): string
    {
        if (
            $this->last_actual_amount
            && $this->last_actual_date
            && $this->last_actual_date->format('Y-m') === $periodMonth
        ) {
            return (string) $this->last_actual_amount;
        }

        return (string) $this->monthly_estimate;
    }
}
