<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinGstReturnLine extends Model
{
    use HasFactory;

    protected $table = 'fin_gst_return_lines';

    protected $fillable = [
        'gst_return_id',
        'journal_line_id',
        'account_id',
        'description',
        'net_amount',
        'gst_amount',
        'tax_rate_id',
        'side',
        'source_type',
        'source_id',
        'source_line_type',
        'source_line_id',
        'recognition_type',
        'recognition_id',
        'recognition_date',
        'source_key',
    ];

    protected $casts = [
        'net_amount' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'recognition_date' => 'date',
    ];

    public function gstReturn(): BelongsTo
    {
        return $this->belongsTo(FinGstReturn::class, 'gst_return_id');
    }

    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(FinJournalLine::class, 'journal_line_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'account_id');
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(FinTaxRate::class, 'tax_rate_id');
    }
}
