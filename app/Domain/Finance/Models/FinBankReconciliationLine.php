<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinBankReconciliationLine extends Model
{
    use HasFactory;

    protected $table = 'fin_bank_reconciliation_lines';

    protected $fillable = [
        'reconciliation_id',
        'bank_transaction_id',
        'journal_line_id',
        'is_matched',
    ];

    protected $casts = [
        'is_matched' => 'boolean',
    ];

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
}
