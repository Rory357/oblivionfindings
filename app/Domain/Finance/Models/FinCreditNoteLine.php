<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinCreditNoteLine extends Model
{
    use HasFactory;

    protected $table = 'fin_credit_note_lines';

    protected $fillable = [
        'credit_note_id',
        'description',
        'quantity',
        'unit_price',
        'gst_rate',
        'gst_amount',
        'line_total',
        'account_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'gst_rate' => 'decimal:4',
        'gst_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(FinCreditNote::class, 'credit_note_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'account_id');
    }
}
