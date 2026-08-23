<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

class FinDonorFundTransaction extends Model
{
    use AuditableChanges, HasFactory;

    protected $table = 'fin_donor_fund_transactions';

    private bool $linkingJournal = false;

    protected $fillable = [
        'fund_id',
        'site_id',
        'funding_stream_id',
        'idempotency_key',
        'payload_hash',
        'transaction_date',
        'type',
        'description',
        'amount',
        'bill_id',
        'bank_account_id',
        'expense_account_id',
        'reversal_of_transaction_id',
        'reference',
        'approved_by',
        'approved_at',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'approved_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $transaction): void {
            if ($transaction->journal_id !== null) {
                throw new RuntimeException('Donor-fund journals must be linked by the canonical posting service.');
            }
        });

        static::updating(function (self $transaction): void {
            $immutable = [
                'fund_id',
                'site_id',
                'funding_stream_id',
                'idempotency_key',
                'payload_hash',
                'transaction_date',
                'type',
                'description',
                'amount',
                'bill_id',
                'bank_account_id',
                'expense_account_id',
                'reversal_of_transaction_id',
                'reference',
                'approved_by',
                'approved_at',
                'created_by',
            ];

            if ($transaction->isDirty($immutable)
                || ($transaction->isDirty('journal_id') && ! $transaction->linkingJournal)) {
                throw new RuntimeException('Donor-fund applications are immutable; record a reversal instead.');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Donor-fund applications cannot be deleted; record a reversal instead.');
        });
    }

    public function linkJournal(FinJournal $journal): void
    {
        $organizationId = $this->fund()->value('organization_id');
        if (! $this->exists
            || $this->journal_id !== null
            || $organizationId === null
            || (int) $journal->organization_id !== (int) $organizationId
            || $journal->status !== 'posted'
            || $journal->source_type !== self::class
            || (int) $journal->source_id !== (int) $this->getKey()) {
            throw new RuntimeException('The donor-fund journal cannot be linked to this application.');
        }

        $this->linkingJournal = true;
        try {
            $this->forceFill(['journal_id' => $journal->id])->save();
        } finally {
            $this->linkingJournal = false;
        }
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(FinDonorFund::class, 'fund_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function fundingStream(): BelongsTo
    {
        return $this->belongsTo(FinFundingStream::class, 'funding_stream_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(FinBill::class, 'bill_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinBankAccount::class, 'bank_account_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'expense_account_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_transaction_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_transaction_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeReceipts($query)
    {
        return $query->where('type', 'receipt');
    }

    public function scopeExpenditures($query)
    {
        return $query->where('type', 'expenditure');
    }
}
