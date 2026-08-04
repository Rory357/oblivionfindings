<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyOrganizationStorageContext;
use App\Services\References\ReferenceNumberGenerator;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HsRiskAssessment extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes, WritesLegacyOrganizationStorageContext;

    protected $table = 'hs_risk_assessments';

    /* ------------------------------------------------------------------ */
    /*  Constants */
    /* ------------------------------------------------------------------ */

    // Status lifecycle
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_ARCHIVED = 'archived';

    // Risk levels (calculated from 5x5 matrix)
    public const LEVEL_LOW = 'low';

    public const LEVEL_MEDIUM = 'medium';

    public const LEVEL_HIGH = 'high';

    public const LEVEL_EXTREME = 'extreme';

    /**
     * 5x5 Risk Matrix — Likelihood × Consequence = Score.
     *
     * Score bands:
     *   1–4:   low
     *   5–9:   medium
     *   10–15: high
     *   16–25: extreme
     */
    public const RISK_BANDS = [
        'low' => [1, 4],
        'medium' => [5, 9],
        'high' => [10, 15],
        'extreme' => [16, 25],
    ];

    /* ------------------------------------------------------------------ */
    /*  Fillable / Casts */
    /* ------------------------------------------------------------------ */

    protected $fillable = [
        'reference_number',
        'assessable_type',
        'assessable_id',
        'hs_event_id',
        'title',
        'risk_description',
        'status',
        'likelihood',
        'consequence',
        'risk_score',
        'risk_level',
        'existing_controls',
        'additional_controls',
        'residual_likelihood',
        'residual_consequence',
        'residual_risk_score',
        'residual_risk_level',
        'risk_acceptable',
        'assessed_by_user_id',
        'assessed_at',
        'approved_by_user_id',
        'approved_at',
        'approval_note',
        'review_due_at',
        'review_frequency_days',
        'last_review_note',
        'superseded_by_id',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'likelihood' => 'integer',
        'consequence' => 'integer',
        'risk_score' => 'integer',
        'residual_likelihood' => 'integer',
        'residual_consequence' => 'integer',
        'residual_risk_score' => 'integer',
        'risk_acceptable' => 'boolean',
        'assessed_at' => 'datetime',
        'approved_at' => 'datetime',
        'review_due_at' => 'date',
        'review_frequency_days' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function assessable(): MorphTo
    {
        return $this->morphTo();
    }

    public function hsEvent(): BelongsTo
    {
        return $this->belongsTo(HsEvent::class, 'hs_event_id');
    }

    public function assessedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(HsRiskAssessmentAttachment::class)->latest();
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeHighOrExtreme($query)
    {
        return $query->whereIn('risk_level', [self::LEVEL_HIGH, self::LEVEL_EXTREME]);
    }

    public function scopeDueForReview($query)
    {
        return $query->whereNotNull('review_due_at')
            ->where('review_due_at', '<=', now()->toDateString())
            ->whereIn('status', [self::STATUS_ACTIVE]);
    }

    public function scopeForAssessable($query, string $type, int $id)
    {
        $query->where('assessable_type', $type)->where('assessable_id', $id);

        return app(UserSiteAccessService::class)
            ->applyHsRiskAssessmentApplicationScope($query);
    }

    /* ------------------------------------------------------------------ */
    /*  Scoring helpers */
    /* ------------------------------------------------------------------ */

    /**
     * Calculate risk score and level from likelihood and consequence.
     */
    public static function calculateScore(int $likelihood, int $consequence): array
    {
        $likelihood = max(1, min(5, $likelihood));
        $consequence = max(1, min(5, $consequence));
        $score = $likelihood * $consequence;

        return [
            'score' => $score,
            'level' => self::scoreToLevel($score),
        ];
    }

    /**
     * Map a numeric score (1–25) to a risk level string.
     */
    public static function scoreToLevel(int $score): string
    {
        return match (true) {
            $score >= 16 => self::LEVEL_EXTREME,
            $score >= 10 => self::LEVEL_HIGH,
            $score >= 5 => self::LEVEL_MEDIUM,
            default => self::LEVEL_LOW,
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle helpers */
    /* ------------------------------------------------------------------ */

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isDueForReview(): bool
    {
        return $this->review_due_at && $this->review_due_at->isPast() && $this->isActive();
    }

    public function isHighOrExtreme(): bool
    {
        return in_array($this->risk_level, [self::LEVEL_HIGH, self::LEVEL_EXTREME], true);
    }

    /* ------------------------------------------------------------------ */
    /*  Reference number generation */
    /* ------------------------------------------------------------------ */

    public static function generateReferenceNumber(): string
    {
        return app(ReferenceNumberGenerator::class)->next('RA');
    }
}
