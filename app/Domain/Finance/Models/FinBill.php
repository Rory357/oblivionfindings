<?php

namespace App\Domain\Finance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Database\Factories\Finance\FinBillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinBill extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected static function newFactory()
    {
        return FinBillFactory::new();
    }

    protected $table = 'fin_bills';

    protected $fillable = [
        'organization_id',
        'vendor_id',
        'purchase_order_id',
        'spend_approval_id',
        'bill_number',
        'vendor_reference',
        'status',
        'bill_date',
        'due_date',
        'subtotal',
        'gst_amount',
        'total_amount',
        'amount_paid',
        'approved_by',
        'approved_at',
        'journal_id',
        'notes',
        'xero_invoice_id',
        'myob_invoice_id',
        'created_by',
    ];

    protected $casts = [
        'bill_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'gst_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(FinVendor::class, 'vendor_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(FinPurchaseOrder::class, 'purchase_order_id');
    }

    /**
     * Optional governance sign-off linked to this bill. Finance-side link only —
     * the SpendApproval is owned by the Governance domain; this bill never
     * creates one. When the spend-approval gate is enforced (config
     * finance.spend_approval), a bill at/above threshold can only be approved
     * once this points at an APPROVED approval covering the full amount.
     */
    public function spendApproval(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Governance\Models\SpendApproval::class, 'spend_approval_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FinBillLine::class, 'bill_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(FinJournal::class, 'journal_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function paymentAllocations(): MorphMany
    {
        return $this->morphMany(FinPaymentAllocation::class, 'allocatable');
    }

    public function scopeForOrganization($query, ?int $orgId)
    {
        return $query->when($orgId, fn ($q) => $q->where($query->qualifyColumn('organization_id'), $orgId));
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now())
            ->whereColumn('amount_paid', '<', 'total_amount');
    }

    public function scopeUnpaid($query)
    {
        return $query->whereColumn('amount_paid', '<', 'total_amount');
    }

    public function getAmountDue(): float
    {
        return (float) $this->total_amount - (float) $this->amount_paid;
    }

    /**
     * Next sequential bill number for an organisation (BILL-YYYYMM-001), scoped to
     * the current month. The canonical generator — both AccountsPayableService
     * (direct bills) and PurchaseOrderController (PO→bill) use this, so the two
     * paths can't drift or collide. MAX(numeric suffix) handles >999/month; the
     * `unique(organization_id, bill_number)` index guards concurrent races.
     */
    public static function nextNumber(?int $orgId): string
    {
        $prefix = 'BILL-'.now()->format('Ym').'-';

        $maxNumber = static::forOrganization($orgId)
            ->where('bill_number', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(bill_number, '.(strlen($prefix) + 1).') AS UNSIGNED)) as max_num')
            ->value('max_num');

        return $prefix.str_pad((string) (($maxNumber ?? 0) + 1), 3, '0', STR_PAD_LEFT);
    }
}
