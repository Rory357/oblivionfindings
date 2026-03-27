<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinBankTransaction extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'fin_bank_transactions';

    protected $fillable = [
        'organization_id',
        'bank_account_id',
        'transaction_date',
        'amount',
        'description',
        'reference',
        'payee',
        'source',
        'reconciliation_id',
        'matched_journal_line_id',
        'status',
        'bank_feed_id',
        'external_id',
        'is_from_feed',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
        'is_from_feed' => 'boolean',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinBankAccount::class, 'bank_account_id');
    }

    public function bankFeed(): BelongsTo
    {
        return $this->belongsTo(FinBankFeed::class, 'bank_feed_id');
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(FinBankReconciliation::class, 'reconciliation_id');
    }

    public function matchedJournalLine(): BelongsTo
    {
        return $this->belongsTo(FinJournalLine::class, 'matched_journal_line_id');
    }

    public function paymentMatches(): HasMany
    {
        return $this->hasMany(FinPaymentMatch::class, 'bank_transaction_id');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where('organization_id', $orgId));
    }

    public function scopeUnreconciled($query)
    {
        return $query->where('status', 'unreconciled');
    }

    public function scopeReconciled($query)
    {
        return $query->where('status', 'reconciled');
    }
}
