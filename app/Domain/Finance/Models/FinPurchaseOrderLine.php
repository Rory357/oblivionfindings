<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinPurchaseOrderLine extends Model
{
    use HasFactory;

    protected $table = 'fin_purchase_order_lines';

    protected $fillable = [
        'purchase_order_id',
        'description',
        'quantity',
        'unit_price',
        'gst_rate',
        'gst_amount',
        'line_total',
        'account_id',
        'received_quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'gst_rate' => 'decimal:4',
        'gst_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'received_quantity' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(FinPurchaseOrder::class, 'purchase_order_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'account_id');
    }

    public function isFullyReceived(): bool
    {
        return (float) $this->received_quantity >= (float) $this->quantity;
    }
}
