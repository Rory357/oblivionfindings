<?php

namespace App\Domain\Governance\Models;

use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCostCentre;
use App\Domain\Finance\Models\FinDonorFund;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinPurchaseOrder;
use App\Models\Concerns\AuditableChanges;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Records a board / finance-committee sign-off on spend above a configured
 * threshold. Polymorphic source — can attach to a FinBill, FinPurchaseOrder,
 * FinPaymentRun, or stand alone as a "future commitment" approval.
 */
class SpendApproval extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_EXPIRED = 'expired';

    public const CATEGORY_CAPEX = 'capex';

    public const CATEGORY_OPEX = 'opex';

    public const CATEGORY_SUPPLIER_CONTRACT = 'supplier_contract';

    public const CATEGORY_DONOR_RESTRICTED = 'donor_restricted';

    /** @var array<int, class-string<Model>> */
    public const SOURCE_TYPES = [
        FinBill::class,
        FinPurchaseOrder::class,
        FinPaymentRun::class,
    ];

    protected $fillable = [
        'reference', 'title', 'description', 'category',
        'amount', 'currency',
        'source_type', 'source_id',
        'site_id', 'cost_centre_id', 'funding_stream_id', 'donor_fund_id',
        'budget_id', 'budget_line_item_id',
        'status', 'requested_by', 'submitted_by', 'submitted_at',
        'version', 'submission_version', 'content_digest',
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
        'version' => 'integer',
        'submission_version' => 'integer',
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

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function costCentre(): BelongsTo
    {
        return $this->belongsTo(FinCostCentre::class, 'cost_centre_id');
    }

    public function fundingStream(): BelongsTo
    {
        return $this->belongsTo(FinFundingStream::class, 'funding_stream_id');
    }

    public function donorFund(): BelongsTo
    {
        return $this->belongsTo(FinDonorFund::class, 'donor_fund_id');
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

    public function decisions(): HasMany
    {
        return $this->hasMany(SpendApprovalDecision::class);
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SUBMITTED], true);
    }

    public function isDecided(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true);
    }

    public function decisionContentDigest(?array $sourceEvidence = null): string
    {
        $attachments = collect($this->attachments ?? [])
            ->map(fn (array $attachment) => [
                'id' => $attachment['id'] ?? null,
                'original_name' => $attachment['original_name'] ?? null,
                'mime_type' => $attachment['mime_type'] ?? null,
                'size_bytes' => $attachment['size_bytes'] ?? null,
                'sha256' => $attachment['sha256'] ?? null,
            ])
            ->sortBy('id')
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'reference' => $this->reference,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'source' => $sourceEvidence,
            'site_id' => $this->site_id,
            'cost_centre_id' => $this->cost_centre_id,
            'funding_stream_id' => $this->funding_stream_id,
            'donor_fund_id' => $this->donor_fund_id,
            'budget_id' => $this->budget_id,
            'budget_line_item_id' => $this->budget_line_item_id,
            'requires_board' => (bool) $this->requires_board,
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'attachments' => $attachments,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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
