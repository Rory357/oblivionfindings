<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class HsClosureException extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVIEW_DUE = 'review_due';

    public const CATEGORY_HANDOVER_RECORD = 'handover_record';

    public const CATEGORY_INVESTIGATION_RECORD = 'investigation_record';

    public const CATEGORY_RECOMMENDATION_DECISION = 'recommendation_decision';

    public const CATEGORY_CORRECTIVE_ACTION_MONITORING = 'corrective_action_monitoring';

    /** @var array<string, list<string>> */
    public const CATEGORY_SCOPES = [
        self::CATEGORY_HANDOVER_RECORD => ['hs_acceptance'],
        self::CATEGORY_INVESTIGATION_RECORD => ['hs_investigation'],
        self::CATEGORY_RECOMMENDATION_DECISION => ['recommendation_dispositions'],
        self::CATEGORY_CORRECTIVE_ACTION_MONITORING => ['corrective_actions'],
    ];

    protected $table = 'hs_closure_exceptions';

    protected $fillable = [
        'hs_event_id',
        'site_id',
        'requested_by_user_id',
        'category',
        'reason',
        'evidence_reference',
        'scope',
        'request_provenance',
        'provenance_hash',
        'requested_at',
        'expires_at',
        'review_at',
    ];

    protected $casts = [
        'scope' => 'array',
        'request_provenance' => 'array',
        'requested_at' => 'datetime',
        'expires_at' => 'datetime',
        'review_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::updating(static function (self $exception): never {
            throw new \LogicException('H&S closure exception requests are immutable.');
        });
        self::deleting(static function (self $exception): never {
            throw new \LogicException('H&S closure exception requests are immutable.');
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(HsEvent::class, 'hs_event_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(HsClosureExceptionDecision::class, 'hs_closure_exception_id');
    }

    public function latestDecision(): HasOne
    {
        return $this->hasOne(HsClosureExceptionDecision::class, 'hs_closure_exception_id')->latestOfMany();
    }

    public function status(?\DateTimeInterface $at = null): string
    {
        $at ??= now();
        $decision = $this->relationLoaded('latestDecision')
            ? $this->latestDecision
            : $this->latestDecision()->first();

        if ($decision === null) {
            if ($this->expires_at === null || ! $this->expires_at->isAfter($at)) {
                return self::STATUS_EXPIRED;
            }
            if ($this->review_at === null || ! $this->review_at->isAfter($at)) {
                return self::STATUS_REVIEW_DUE;
            }
        }

        if ($decision?->decision === self::STATUS_APPROVED) {
            if ($this->expires_at === null || ! $this->expires_at->isAfter($at)) {
                return self::STATUS_EXPIRED;
            }
            if ($this->review_at === null || ! $this->review_at->isAfter($at)) {
                return self::STATUS_REVIEW_DUE;
            }
        }

        return $decision?->decision ?? self::STATUS_PENDING;
    }

    public function isCurrentApproved(?\DateTimeInterface $at = null): bool
    {
        $at ??= now();

        return $this->status($at) === self::STATUS_APPROVED
            && $this->expires_at !== null
            && $this->review_at !== null
            && $this->expires_at->isAfter($at)
            && $this->review_at->isAfter($at);
    }
}
