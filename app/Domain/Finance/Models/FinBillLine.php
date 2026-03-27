<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinBillLine extends Model
{
    use HasFactory;

    protected $table = 'fin_bill_lines';

    protected $fillable = [
        'bill_id',
        'description',
        'quantity',
        'unit_price',
        'gst_rate',
        'gst_amount',
        'line_total',
        'account_id',
        'cost_centre_id',
        'funding_stream_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'gst_rate' => 'decimal:4',
        'gst_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(FinBill::class, 'bill_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'account_id');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(FinCostCentre::class, 'cost_centre_id');
    }

    public function fundingStream(): BelongsTo
    {
        return $this->belongsTo(FinFundingStream::class, 'funding_stream_id');
    }
}
