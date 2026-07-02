<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HsInvestigation extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $table = 'hs_investigations';

    /* ------------------------------------------------------------------ */
    /*  Constants                                                          */
    /* ------------------------------------------------------------------ */

    // Investigation types
    public const TYPE_STANDARD = 'standard';
    public const TYPE_FULL = 'full';
    public const TYPE_WORKSAFE_DIRECTED = 'worksafe_directed';

    // Lifecycle statuses (ordered)
    public const STATUS_DRAFT = 'draft';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_FINDINGS_RECORDED = 'findings_recorded';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_COMPLETED = 'completed';

    /**
     * Ordered lifecycle. Each status can only move forward to the next,
     * except under_review can return to in_progress if findings need rework.
     */
    public const STATUS_ORDER = [
        self::STATUS_DRAFT => 0,
        self::STATUS_IN_PROGRESS => 1,
        self::STATUS_FINDINGS_RECORDED => 2,
        self::STATUS_UNDER_REVIEW => 3,
        self::STATUS_COMPLETED => 4,
    ];

    /**
     * Valid forward transitions from each status.
     * under_review may return to in_progress (rework).
     */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_IN_PROGRESS],
        self::STATUS_IN_PROGRESS => [self::STATUS_FINDINGS_RECORDED],
        self::STATUS_FINDINGS_RECORDED => [self::STATUS_UNDER_REVIEW],
        self::STATUS_UNDER_REVIEW => [self::STATUS_COMPLETED, self::STATUS_IN_PROGRESS],
        self::STATUS_COMPLETED => [],
    ];

    // Methodology options
    public const METHODOLOGY_5_WHYS = '5_whys';
    public const METHODOLOGY_FISHBONE = 'fishbone';
    public const METHODOLOGY_BOW_TIE = 'bow_tie';
    public const METHODOLOGY_ICAM = 'icam';
    public const METHODOLOGY_TAPROOT = 'taproot';
    public const METHODOLOGY_OTHER = 'other';

    public const VALID_METHODOLOGIES = [
        self::METHODOLOGY_5_WHYS,
        self::METHODOLOGY_FISHBONE,
        self::METHODOLOGY_BOW_TIE,
        self::METHODOLOGY_ICAM,
        self::METHODOLOGY_TAPROOT,
        self::METHODOLOGY_OTHER,
    ];

    // Contributing factor types
    public const FACTOR_HUMAN = 'human';
    public const FACTOR_ENVIRONMENTAL = 'environmental';
    public const FACTOR_PROCEDURAL = 'procedural';
    public const FACTOR_ORGANIZATIONAL = 'organizational';
    public const FACTOR_EQUIPMENT = 'equipment';

    // Recommendation priority levels
    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_CRITICAL = 'critical';

    /* ------------------------------------------------------------------ */
    /*  Fillable / Casts                                                   */
    /* ------------------------------------------------------------------ */

    protected $fillable = [
        'hs_event_id',
        'organization_id',
        'reference_number',
        'investigation_type',
        'status',
        'methodology',
        'lead_investigator_id',
        'team_member_ids',
        'started_at',
        'target_completion_date',
        'completed_at',
        'immediate_causes',
        'root_causes',
        'contributing_factors',
        'findings_summary',
        'recommendations',
        'lessons_learned',
        'reviewed_by_id',
        'reviewed_at',
        'review_notes',
        'approved_by_id',
        'approved_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'team_member_ids' => 'array',
        'immediate_causes' => 'array',
        'root_causes' => 'array',
        'contributing_factors' => 'array',
        'recommendations' => 'array',
        'started_at' => 'datetime',
        'target_completion_date' => 'date',
        'completed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function hsEvent(): BelongsTo
    {
        return $this->belongsTo(HsEvent::class, 'hs_event_id');
    }

    public function leadInvestigator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_investigator_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Corrective actions created from this investigation's recommendations.
     */
    public function correctiveActions(): HasMany
    {
        return $this->hasMany(HsCorrectiveAction::class, 'hs_investigation_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeActive($query)
    {
        return $query->whereNotIn('status', [self::STATUS_COMPLETED]);
    }

    public function scopeForInvestigator($query, int $userId)
    {
        return $query->where('lead_investigator_id', $userId);
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('target_completion_date')
            ->where('target_completion_date', '<', now()->toDateString())
            ->whereNotIn('status', [self::STATUS_COMPLETED]);
    }

    public function scopeOfStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle helpers                                                   */
    /* ------------------------------------------------------------------ */

    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = self::ALLOWED_TRANSITIONS[$this->status] ?? [];

        return in_array($newStatus, $allowed, true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isActive(): bool
    {
        return ! $this->isCompleted();
    }

    public function isOverdue(): bool
    {
        return $this->target_completion_date
            && $this->target_completion_date->isPast()
            && $this->isActive();
    }

    public function hasFindings(): bool
    {
        return ! empty($this->immediate_causes)
            || ! empty($this->root_causes)
            || ! empty($this->findings_summary);
    }

    public function hasRecommendations(): bool
    {
        return ! empty($this->recommendations);
    }

    /* ------------------------------------------------------------------ */
    /*  Reference number generation                                        */
    /* ------------------------------------------------------------------ */

    public static function generateReferenceNumber(): string
    {
        return app(\App\Services\References\ReferenceNumberGenerator::class)->next('INV');
    }
}
