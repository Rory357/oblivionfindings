<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinPaymentRunItem extends Model
{
    use HasFactory;

    protected $table = 'fin_payment_run_items';

    protected $fillable = [
        'payment_run_id',
        'site_id',
        'bill_id',
        'settlement_bill_id',
        'vendor_id',
        'amount',
        'reference',
        'bank_account_number',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'bank_account_number' => 'encrypted',
    ];

    public function paymentRun(): BelongsTo
    {
        return $this->belongsTo(FinPaymentRun::class, 'payment_run_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(FinBill::class, 'bill_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(FinVendor::class, 'vendor_id');
    }
}
