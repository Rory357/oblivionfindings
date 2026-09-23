<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Evidence from a vehicle routed to Finance for a decision (a quote, an
 * invoice query, an ownership correction or a cost allocation). Finance
 * resolves or declines it; the request never approves spend or posts
 * anything itself.
 */
class FleetFinanceReviewRequest extends Model
{
    use Concerns\HasReferenceNumber;

    public const REFERENCE_PREFIX = 'FRQ';

    /** Request types, as offered in the approved design. */
    public const TYPES = [
        'supplier_invoice_review' => 'Supplier invoice review',
        'purchase_approval' => 'Purchase approval',
        'fixed_asset_update' => 'Fixed asset / ownership update',
        'cost_allocation_correction' => 'Cost allocation correction',
    ];

    public const STATUSES = ['submitted', 'resolved', 'declined'];

    public const DECISIONS = ['resolved', 'declined'];

    protected $fillable = [
        'reference_number', 'asset_id', 'request_type', 'source_type', 'source_id', 'source_label', 'amount',
        'note', 'existing_document_id', 'status', 'lock_version', 'requested_by_user_id', 'decided_by_user_id',
        'decided_at', 'decision_note', 'request_key', 'request_fingerprint',
    ];

    protected $casts = [
        'source_id' => 'integer',
        'amount' => 'decimal:2',
        'lock_version' => 'integer',
        'decided_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function existingDocument(): BelongsTo
    {
        return $this->belongsTo(AssetDocument::class, 'existing_document_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FleetFinanceReviewRequestEvent::class, 'review_request_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'submitted';
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->request_type] ?? 'Finance review';
    }
}
