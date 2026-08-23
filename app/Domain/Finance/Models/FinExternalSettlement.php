<?php

namespace App\Domain\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FinExternalSettlement extends Model
{
    protected $table = 'fin_external_settlements';

    protected $guarded = [];

    protected $casts = [
        'attempt_number' => 'integer',
        'amount' => 'decimal:2',
        'prepared_at' => 'datetime',
        'exported_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'settled_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'acceptance_evidence' => 'array',
        'rejection_evidence' => 'array',
        'reconciliation_evidence' => 'array',
    ];

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function events(): HasMany
    {
        return $this->hasMany(FinExternalSettlementEvent::class, 'external_settlement_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(FinBankAccount::class, 'bank_account_id');
    }

    public function reconciledBankTransaction(): BelongsTo
    {
        return $this->belongsTo(FinBankTransaction::class, 'reconciled_bank_transaction_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }
}
