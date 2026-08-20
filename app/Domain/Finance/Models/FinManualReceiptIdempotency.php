<?php

namespace App\Domain\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FinManualReceiptIdempotency extends Model
{
    protected $table = 'fin_manual_receipt_idempotencies';

    protected $fillable = [
        'idempotency_key',
        'organization_id',
        'invoice_id',
        'request_hash',
        'allocation_id',
        'created_by',
    ];

    protected $hidden = [
        'idempotency_key',
        'request_hash',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(FinInvoice::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(FinPaymentAllocation::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
