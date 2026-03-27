<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinDonorFundTransaction extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'fin_donor_fund_transactions';

    protected $fillable = [
        'fund_id',
        'transaction_date',
        'type',
        'description',
        'amount',
        'journal_id',
        'bill_id',
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

    public function fund(): BelongsTo
    {
        return $this->belongsTo(FinDonorFund::class, 'fund_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(FinBill::class, 'bill_id');
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
