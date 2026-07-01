<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrExpenseItem extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'expense_claim_id',
        'description',
        'category',
        'amount',
        'expense_date',
        'receipt_path',
        'tax_amount',
        'notes',
        'source_type',
        'source_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
        'tax_amount' => 'decimal:2',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function expenseClaim(): BelongsTo
    {
        return $this->belongsTo(HrExpenseClaim::class, 'expense_claim_id');
    }
}
