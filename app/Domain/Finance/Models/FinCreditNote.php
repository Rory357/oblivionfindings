<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinCreditNote extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'fin_credit_notes';

    protected $fillable = [
        'organization_id',
        'credit_note_number',
        'type',
        'vendor_id',
        'client_id',
        'bill_id',
        'invoice_id',
        'status',
        'credit_date',
        'subtotal',
        'gst_amount',
        'total_amount',
        'reason',
        'journal_id',
        'approved_by',
        'approved_at',
        'created_by',
    ];

    protected $casts = [
        'credit_date' => 'date',
        'subtotal' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(FinVendor::class, 'vendor_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(FinBill::class, 'bill_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinCreditNoteLine::class, 'credit_note_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrganization($query, int $orgId)
    {
        return $query->where('organization_id', $orgId);
    }

    public function scopePayable($query)
    {
        return $query->where('type', 'payable');
    }

    public function scopeReceivable($query)
    {
        return $query->where('type', 'receivable');
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
