<?php

namespace App\Models;

use App\Domain\Finance\Models\FinFinancialEvent;
use App\Domain\Finance\Models\FinJournal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteUtilityPosting extends Model
{
    protected $fillable = [
        'site_utility_id',
        'period',
        'posting_type',
        'amount',
        'financial_event_id',
        'journal_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function utility(): BelongsTo
    {
        return $this->belongsTo(SiteUtility::class, 'site_utility_id');
    }

    public function financialEvent(): BelongsTo
    {
        return $this->belongsTo(FinFinancialEvent::class, 'financial_event_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    /**
     * Get the most recent posting for a utility + period.
     */
    public static function latestFor(int $utilityId, string $period): ?self
    {
        return static::where('site_utility_id', $utilityId)
            ->where('period', $period)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Has an actual already been posted for this utility + period?
     */
    public static function hasActualPosted(int $utilityId, string $period): bool
    {
        return static::where('site_utility_id', $utilityId)
            ->where('period', $period)
            ->where('posting_type', 'actual')
            ->exists();
    }
}
