<?php

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Exceptions\BankReconciliationConflict;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinBankReconciliationEvent extends Model
{
    public $timestamps = false;

    protected $table = 'fin_bank_reconciliation_events';

    protected $fillable = [
        'organization_id',
        'bank_account_id',
        'reconciliation_id',
        'reconciliation_line_id',
        'statement_import_id',
        'bank_transaction_id',
        'journal_id',
        'reversal_journal_id',
        'actor_id',
        'event_type',
        'aggregate_version',
        'idempotency_key',
        'provenance',
        'occurred_at',
    ];

    protected $casts = [
        'provenance' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new BankReconciliationConflict('Reconciliation events are append-only.'));
        static::deleting(fn () => throw new BankReconciliationConflict('Reconciliation events are append-only.'));
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(FinBankReconciliation::class, 'reconciliation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
