<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Database\Factories\Finance\FinPurchaseOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinPurchaseOrder extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected static function newFactory()
    {
        return FinPurchaseOrderFactory::new();
    }

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
        return $query->when($orgId, fn ($q) => $q->where($query->qualifyColumn('organization_id'), $orgId));
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Next sequential PO number for an organisation (PO-YYYYMM-001), scoped to the
     * current month. MAX(numeric suffix) handles >999/month (the previous string
     * orderBy broke past 999); the `unique(organization_id, po_number)` index
     * guards concurrent races.
     */
    public static function nextNumber(?int $orgId): string
    {
        $prefix = 'PO-'.now()->format('Ym').'-';

        $maxNumber = static::forOrganization($orgId)
            ->where('po_number', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(po_number, '.(strlen($prefix) + 1).') AS UNSIGNED)) as max_num')
            ->value('max_num');

        return $prefix.str_pad((string) (($maxNumber ?? 0) + 1), 3, '0', STR_PAD_LEFT);
    }
}
