<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FinPaymentAllocation extends Model
{
    use AuditableChanges, HasFactory;

    public const INTEGRITY_TRACEABLE = 'traceable';

    public const INTEGRITY_REVIEW_REQUIRED = 'review_required';

    protected $table = 'fin_payment_allocations';

    protected $fillable = [
        'organization_id',
        'site_id',
        'type',
        'payment_date',
        'amount',
        'allocatable_type',
        'allocatable_id',
        'source_type',
        'source_id',
        'settlement_source_key',
        'integrity_state',
        'journal_id',
        'settlement_journal_id',
        'bank_transaction_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $allocation): never {
            throw new \LogicException('Payment allocation history is append-only; record a traceable correction instead.');
        });
        static::deleting(function (self $allocation): never {
            throw new \LogicException('Payment allocation history is append-only; record a traceable correction instead.');
        });
    }

    public function allocatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn ($q) => $q->where($query->qualifyColumn('organization_id'), $orgId));
    }

    public function scopePayable($query)
    {
        return $query->where('type', 'payable');
    }

    public function scopeReceivable($query)
    {
        return $query->where('type', 'receivable');
    }

    public function scopeRequiresLegacyReview($query)
    {
        return $query->where('integrity_state', self::INTEGRITY_REVIEW_REQUIRED);
    }

    public function requiresLegacyReview(): bool
    {
        return $this->integrity_state !== self::INTEGRITY_TRACEABLE
            || $this->site_id === null
            || $this->journal_id === null
            || $this->settlement_journal_id === null
            || $this->source_type === null
            || $this->source_id === null
            || $this->settlement_source_key === null;
    }
}
