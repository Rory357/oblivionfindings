<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FinFinancialEvent extends Model
{
    use AuditableChanges;

    protected $table = 'fin_financial_events';

    protected $fillable = [
        'organization_id',
        'source_type',
        'source_id',
        'event_type',
        'description',
        'amount',
        'currency',
        'payment_type',
        'debit_account_id',
        'credit_account_id',
        'cost_centre_id',
        'funding_stream_id',
        'site_id',
        'client_id',
        'staff_id',
        'asset_id',
        'shift_id',
        'event_date',
        'status',
        'journal_id',
        'posted_at',
        'failure_reason',
        'retry_count',
        'idempotency_key',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'event_date' => 'date',
        'posted_at' => 'datetime',
        'retry_count' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Constants                                                          */
    /* ------------------------------------------------------------------ */

    /** Maximum queue retry attempts before marking as permanently failed. */
    public const MAX_RETRIES = 3;

    /** Payment types determine which credit account is used. */
    public const PAYMENT_AP = 'ap';                 // Accounts Payable — vendor invoice
    public const PAYMENT_CASH = 'cash';             // Direct bank/cash payment
    public const PAYMENT_REIMBURSEMENT = 'reimburse'; // Staff reimbursement payable

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function debitAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'debit_account_id');
    }

    public function creditAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'credit_account_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(FinCostCentre::class, 'cost_centre_id');
    }

    public function costAllocations(): HasMany
    {
        return $this->hasMany(FinCostAllocation::class, 'financial_event_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeRetryable($query)
    {
        return $query->where('status', 'failed')
            ->where('retry_count', '<', self::MAX_RETRIES);
    }

    public function scopeOfType($query, string $eventType)
    {
        return $query->where('event_type', $eventType);
    }

    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    public function scopeForAsset($query, int $assetId)
    {
        return $query->where('asset_id', $assetId);
    }

    public function scopeForPeriod($query, $start, $end)
    {
        return $query->whereBetween('event_date', [$start, $end]);
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    public function isPosted(): bool
    {
        return $this->status === 'posted' && $this->journal_id !== null;
    }

    public function canRetry(): bool
    {
        return $this->status === 'failed' && $this->retry_count < self::MAX_RETRIES;
    }

    public function markFailed(string $reason): void
    {
        $this->update([
            'status' => 'failed',
            'failure_reason' => $reason,
            'retry_count' => $this->retry_count + 1,
        ]);
    }

    /**
     * Generate a deterministic idempotency key.
     *
     * Includes amount and source updated_at so that legitimate corrections
     * (same source, different amount or timestamp) produce a new key,
     * while identical re-submissions are blocked.
     */
    public static function buildIdempotencyKey(
        string $sourceType,
        int $sourceId,
        string $eventType,
        string $amount,
        ?string $sourceUpdatedAt = null,
    ): string {
        $parts = "{$sourceType}:{$sourceId}:{$eventType}:{$amount}";

        if ($sourceUpdatedAt !== null) {
            $parts .= ":{$sourceUpdatedAt}";
        }

        return hash('sha256', $parts);
    }
}
