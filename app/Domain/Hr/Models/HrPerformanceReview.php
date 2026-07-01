<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrPerformanceReview extends Model
{
    use HasFactory, AuditableChanges;

    protected static function newFactory()
    {
        return \Database\Factories\Hr\HrPerformanceReviewFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'employee_user_id',
        'reviewer_user_id',
        'review_type',
        'review_period_start',
        'review_period_end',
        'status',
        'overall_rating',
        'strengths',
        'development_areas',
        'goals',
        'evidence_path',
        'training_recommendations',
        'employee_comments',
        'employee_signed_off',
        'employee_signed_off_at',
        'manager_signed_off',
        'manager_signed_off_at',
        'next_review_date',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'review_period_start' => 'date',
        'review_period_end' => 'date',
        'overall_rating' => 'integer',
        'goals' => 'array',
        'training_recommendations' => 'array',
        'employee_signed_off' => 'boolean',
        'employee_signed_off_at' => 'datetime',
        'manager_signed_off' => 'boolean',
        'manager_signed_off_at' => 'datetime',
        'next_review_date' => 'date',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    /** Structured review goals (supersedes the legacy `goals` JSON blob). */
    public function reviewGoals(): HasMany
    {
        return $this->hasMany(HrReviewGoal::class, 'performance_review_id')->orderBy('sort_order');
    }

    /**
     * Review goals as a list of description strings, preferring the structured
     * child rows and falling back to the legacy JSON blob during the transition.
     */
    public function reviewGoalList(): array
    {
        if ($this->relationLoaded('reviewGoals') ? $this->reviewGoals->isNotEmpty() : $this->reviewGoals()->exists()) {
            return $this->reviewGoals()->orderBy('sort_order')->pluck('description')->all();
        }

        return collect($this->goals ?? [])
            ->map(fn ($g) => is_array($g) ? ($g['description'] ?? $g['title'] ?? '') : (string) $g)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Replace this review's structured goals from a plain list of strings
     * (dual-writes the legacy JSON column so nothing regresses mid-transition).
     */
    public function syncReviewGoals(array $descriptions): void
    {
        $clean = collect($descriptions)->map(fn ($d) => trim((string) $d))->filter()->values();

        $this->reviewGoals()->delete();
        $clean->each(fn ($desc, $i) => $this->reviewGoals()->create([
            'tenant_id' => $this->tenant_id,
            'description' => mb_substr($desc, 0, 500),
            'status' => 'open',
            'sort_order' => $i,
        ]));

        // Keep the JSON column in sync until the read path is fully cut over.
        $this->forceFill(['goals' => $clean->all()])->saveQuietly();
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', 'draft');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }
}
