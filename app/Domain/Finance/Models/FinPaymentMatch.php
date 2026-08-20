<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class FinPaymentMatch extends Model
{
    use AuditableChanges, HasFactory;

    protected $table = 'fin_payment_matches';

    protected $fillable = [
        'organization_id',
        'site_id',
        'bank_transaction_id',
        'matchable_type',
        'matchable_id',
        'suggestion_key',
        'confidence_score',
        'match_reasons',
        'status',
        'confirmed_by',
        'confirmed_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'journal_id',
    ];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'match_reasons' => 'array',
        'confirmed_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $match): void {
            if ($match->getOriginal('status') === 'rejected') {
                throw new LogicException('Rejected payment match proposals are immutable.');
            }

            foreach (['rejected_by', 'rejected_at', 'rejection_reason'] as $attribute) {
                if ($match->getOriginal($attribute) !== null && $match->isDirty($attribute)) {
                    throw new LogicException('Payment match rejection evidence is immutable.');
                }
            }
        });

        static::deleting(function (self $match): void {
            if ($match->status === 'rejected') {
                throw new LogicException('Rejected payment match proposals are append-only.');
            }
        });
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(FinBankTransaction::class, 'bank_transaction_id');
    }

    public function matchable(): MorphTo
    {
        return $this->morphTo();
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function allocation(): MorphOne
    {
        return $this->morphOne(FinPaymentAllocation::class, 'source');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn ($q) => $q->where($query->qualifyColumn('organization_id'), $orgId));
    }

    public function scopeSuggested($query)
    {
        return $query->where('status', 'suggested');
    }

    public function scopeConfirmed($query)
    {
        return $query->whereIn('status', ['confirmed', 'auto_confirmed']);
    }

    public function scopeHighConfidence($query, float $threshold = 80.0)
    {
        return $query->where('confidence_score', '>=', $threshold);
    }
}
