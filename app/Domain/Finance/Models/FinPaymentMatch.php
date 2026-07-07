<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FinPaymentMatch extends Model
{
    use AuditableChanges, HasFactory;

    protected $table = 'fin_payment_matches';

    protected $fillable = [
        'organization_id',
        'bank_transaction_id',
        'matchable_type',
        'matchable_id',
        'confidence_score',
        'match_reasons',
        'status',
        'confirmed_by',
        'confirmed_at',
        'journal_id',
    ];

    protected $casts = [
        'confidence_score' => 'decimal:2',
        'match_reasons' => 'array',
        'confirmed_at' => 'datetime',
    ];

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

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
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
