<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinCostAllocation extends Model
{
    protected $table = 'fin_cost_allocations';

    protected $fillable = [
        'journal_id',
        'journal_line_id',
        'financial_event_id',
        'site_id',
        'client_id',
        'staff_id',
        'asset_id',
        'shift_id',
        'amount',
        'event_type',
        'event_date',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'event_date' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(FinJournalLine::class, 'journal_line_id');
    }

    public function financialEvent(): BelongsTo
    {
        return $this->belongsTo(FinFinancialEvent::class, 'financial_event_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeForStaff($query, int $staffId)
    {
        return $query->where('staff_id', $staffId);
    }

    public function scopeForAsset($query, int $assetId)
    {
        return $query->where('asset_id', $assetId);
    }

    public function scopeForPeriod($query, $start, $end)
    {
        return $query->whereBetween('event_date', [$start, $end]);
    }

    public function scopeOfType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }
}
