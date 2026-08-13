<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Exceptions\BankReconciliationConflict;
use App\Domain\Finance\Support\BankReconciliationMutationGuard;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinBankReconciliationLine extends Model
{
    use AuditableChanges, HasFactory;

    protected $table = 'fin_bank_reconciliation_lines';

    protected $fillable = [
        'reconciliation_id',
        'bank_account_id',
        'bank_transaction_id',
        'journal_line_id',
        'journal_id',
        'adjustment_journal_id',
        'reversal_journal_id',
        'active_bank_transaction_id',
        'active_journal_line_id',
        'is_matched',
        'matched_by',
        'matched_at',
        'unmatched_by',
        'unmatched_at',
        'aggregate_version',
        'idempotency_key',
    ];

    protected $casts = [
        'is_matched' => 'boolean',
        'matched_at' => 'datetime',
        'unmatched_at' => 'datetime',
        'aggregate_version' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (): void {
            if (! BankReconciliationMutationGuard::allowsCanonicalMutation()) {
                throw BankReconciliationConflict::generic();
            }
        });

        static::updating(function (): void {
            if (! BankReconciliationMutationGuard::allowsCanonicalMutation()) {
                throw BankReconciliationConflict::generic();
            }
        });

        static::deleting(function (): void {
            throw new BankReconciliationConflict('Reconciliation match history is immutable.');
        });
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(FinBankReconciliation::class, 'reconciliation_id');
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(FinBankTransaction::class, 'bank_transaction_id');
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(FinJournalLine::class, 'journal_line_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function adjustmentJournal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'adjustment_journal_id');
    }

    public function reversalJournal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'reversal_journal_id');
    }

    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
