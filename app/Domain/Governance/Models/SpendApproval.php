<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Records a board / finance-committee sign-off on spend above a configured
 * threshold. Polymorphic source — can attach to a FinBill, FinPurchaseOrder,
 * FinPaymentRun, or stand alone as a "future commitment" approval.
 */
class SpendApproval extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    public const CATEGORY_CAPEX = 'capex';
    public const CATEGORY_OPEX = 'opex';
    public const CATEGORY_SUPPLIER_CONTRACT = 'supplier_contract';
    public const CATEGORY_DONOR_RESTRICTED = 'donor_restricted';

    protected $fillable = [
        'reference', 'title', 'description', 'category',
        'amount', 'currency',
        'source_type', 'source_id',
        'site_id', 'cost_centre_id', 'funding_stream_id', 'donor_fund_id',
        'budget_id', 'budget_line_item_id',
        'status', 'requested_by', 'submitted_at',
        'decided_by', 'decided_at', 'decision_notes',
        'resolution_id', 'requires_board',
        'attachments', 'valid_until',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        'valid_until' => 'date',
        'attachments' => 'array',
        'requires_board' => 'boolean',
    ];

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class);
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function budgetLineItem(): BelongsTo
    {
        return $this->belongsTo(BudgetLineItem::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SUBMITTED], true);
    }

    public function isDecided(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true);
    }

    /**
     * Determine whether a given spend amount + category exceeds the configured
     * threshold (kept in `governance_settings`).
     *
     * Settings keys:
     * - spend_approval.threshold.capex (default 5000)
     * - spend_approval.threshold.opex (default 10000)
     * - spend_approval.threshold.supplier_contract (default 10000)
     * - spend_approval.threshold.donor_restricted (default 25000)
     */
    public static function thresholdFor(string $category): float
    {
        return match ($category) {
            self::CATEGORY_CAPEX => GovernanceSetting::getFloat('spend_approval.threshold.capex', 5000.0),
            self::CATEGORY_OPEX => GovernanceSetting::getFloat('spend_approval.threshold.opex', 10000.0),
            self::CATEGORY_SUPPLIER_CONTRACT => GovernanceSetting::getFloat('spend_approval.threshold.supplier_contract', 10000.0),
            self::CATEGORY_DONOR_RESTRICTED => GovernanceSetting::getFloat('spend_approval.threshold.donor_restricted', 25000.0),
            default => 0.0,
        };
    }

    public static function categories(): array
    {
        return [
            self::CATEGORY_CAPEX => 'Capital expenditure',
            self::CATEGORY_OPEX => 'Operating expenditure',
            self::CATEGORY_SUPPLIER_CONTRACT => 'Supplier contract',
            self::CATEGORY_DONOR_RESTRICTED => 'Donor-restricted spend',
        ];
    }
}
