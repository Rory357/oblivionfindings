<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit decision authority: the exact record (and revision) a resolution
 * was authored to approve. Terms are immutable once written; only the
 * single-use consumption markers may be set, once.
 */
class GovernanceResolutionBinding extends Model
{
    public const SUBJECT_VOTING_PROFILE = 'voting_profile';

    public const SUBJECT_STRATEGIC_PLAN = 'strategic_plan';

    public const SUBJECT_BUDGET_ADJUSTMENT = 'budget_adjustment';

    public const SUBJECT_BUDGET = 'budget';

    public const SUBJECT_PERFORMANCE_REVIEW = 'performance_review';

    public const SUBJECT_TYPES = [
        self::SUBJECT_VOTING_PROFILE,
        self::SUBJECT_STRATEGIC_PLAN,
        self::SUBJECT_BUDGET_ADJUSTMENT,
        self::SUBJECT_BUDGET,
        self::SUBJECT_PERFORMANCE_REVIEW,
    ];

    private const CONSUMPTION_ATTRIBUTES = ['consumed_at', 'consumed_by', 'updated_at'];

    protected $table = 'governance_resolution_bindings';

    protected $fillable = [
        'resolution_id',
        'subject_type',
        'subject_id',
        'subject_revision',
        'subject_fingerprint',
        'governing_body',
        'board_committee_id',
        'document_reference',
        'document_version',
        'budget_id',
        'budget_line_item_id',
        'amount',
        'direction',
        'bound_terms',
        'bound_by',
        'bound_at',
        'consumed_at',
        'consumed_by',
    ];

    protected $casts = [
        'subject_id' => 'integer',
        'board_committee_id' => 'integer',
        'budget_id' => 'integer',
        'budget_line_item_id' => 'integer',
        'amount' => 'decimal:2',
        'bound_terms' => 'array',
        'bound_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $binding): void {
            $changed = array_keys($binding->getDirty());
            $termChanges = array_diff($changed, self::CONSUMPTION_ATTRIBUTES);

            if ($termChanges !== []) {
                throw new \DomainException('Resolution authority bindings are immutable; bind a new draft paper instead.');
            }

            if ($binding->getOriginal('consumed_at') !== null) {
                throw new \DomainException('This resolution authority has already been used.');
            }
        });

        static::deleting(function (self $binding): void {
            $resolution = Resolution::withTrashed()->find($binding->resolution_id);

            if ($binding->consumed_at !== null || ($resolution && ! $resolution->isDraft())) {
                throw new \DomainException('A resolution authority binding cannot be removed after the paper has been published or used.');
            }
        });
    }

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class);
    }

    public function boundBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bound_by');
    }

    public function consumedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consumed_by');
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }
}
