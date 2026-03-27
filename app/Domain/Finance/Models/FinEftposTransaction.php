<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinEftposTransaction extends Model
{
    use HasFactory;

    protected $table = 'fin_eftpos_transactions';

    protected $fillable = [
        'batch_id',
        'transaction_reference',
        'transaction_date',
        'card_type',
        'transaction_type',
        'amount',
        'fee_amount',
        'auth_code',
        'card_last_four',
        'status',
    ];

    protected $casts = [
        'transaction_date' => 'datetime',
        'amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(FinEftposBatch::class, 'batch_id');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePurchases($query)
    {
        return $query->where('transaction_type', 'purchase');
    }

    public function scopeRefunds($query)
    {
        return $query->where('transaction_type', 'refund');
    }
}
