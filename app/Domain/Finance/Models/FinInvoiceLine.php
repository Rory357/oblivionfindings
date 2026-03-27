<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinInvoiceLine extends Model
{
    use HasFactory;

    protected $table = 'fin_invoice_lines';

    protected $fillable = [
        'invoice_id',
        'description',
        'quantity',
        'unit_price',
        'tax_rate_id',
        'tax_amount',
        'line_total',
        'sort_order',
        'account_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'sort_order' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinInvoice::class, 'invoice_id');
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(FinTaxRate::class, 'tax_rate_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'account_id');
    }
}
