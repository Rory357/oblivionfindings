<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinPurchaseOrder extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $table = 'fin_purchase_orders';

    protected $fillable = [
        'organization_id',
        'po_number',
        'vendor_id',
        'status',
        'order_date',
        'expected_date',
        'subtotal',
        'gst_amount',
        'total_amount',
        'approved_by',
        'approved_at',
        'notes',
        'cost_centre_id',
        'funding_stream_id',
        'created_by',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_date' => 'date',
        'subtotal' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(FinVendor::class, 'vendor_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinPurchaseOrderLine::class, 'purchase_order_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(FinCostCentre::class, 'cost_centre_id');
    }

    public function fundingStream(): BelongsTo
    {
        return $this->belongsTo(FinFundingStream::class, 'funding_stream_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(FinBill::class, 'purchase_order_id');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn($q) => $q->where('organization_id', $orgId));
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }
}
