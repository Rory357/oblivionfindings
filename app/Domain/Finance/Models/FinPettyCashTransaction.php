<?php

namespace App\Domain\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinPettyCashTransaction extends Model
{
    use HasFactory;

    protected $table = 'fin_petty_cash_transactions';

    protected $fillable = [
        'petty_cash_fund_id',
        'transaction_date',
        'type',
        'amount',
        'description',
        'receipt_path',
        'account_id',
        'journal_id',
        'created_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function fund(): BelongsTo
    {
        return $this->belongsTo(FinPettyCashFund::class, 'petty_cash_fund_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'account_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
