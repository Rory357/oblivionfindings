<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Database\Factories\Hr\HrGoalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrGoal extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes, WritesLegacyStorageContext;

    protected static function newFactory()
    {
        return HrGoalFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'user_id',
        'title',
        'description',
        'goal_type',
        'category',
        'tags',
        'parent_goal_id',
        'cycle_id',
        'target_value',
        'current_value',
        'unit',
        'progress_percentage',
        'status',
        'confidence',
        'checkin_frequency',
        'last_checkin_at',
        'evidence_path',
        'priority',
        'start_date',
        'due_date',
        'completed_at',
        'performance_review_id',
        'created_by',
    ];

    protected $casts = [
        'target_value' => 'decimal:2',
        'current_value' => 'decimal:2',
        'progress_percentage' => 'integer',
        'start_date' => 'date',
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'last_checkin_at' => 'datetime',
        'tags' => 'array',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parentGoal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_goal_id');
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(HrGoalCycle::class, 'cycle_id');
    }

    public function childGoals(): HasMany
    {
        return $this->hasMany(self::class, 'parent_goal_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(HrGoalUpdate::class, 'goal_id');
    }

    public function keyResults(): HasMany
    {
        return $this->hasMany(HrKeyResult::class, 'goal_id');
    }

    public function performanceReview(): BelongsTo
    {
        return $this->belongsTo(HrPerformanceReview::class, 'performance_review_id');
    }

    /** Development plans that roll up into this objective. */
    public function developmentGoals(): HasMany
    {
        return $this->hasMany(HrDevelopmentGoal::class, 'hr_goal_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes */
    /* ------------------------------------------------------------------ */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForCycle(Builder $query, ?int $cycleId): Builder
    {
        return $cycleId ? $query->where('cycle_id', $cycleId) : $query;
    }

    /** Whether progress is derived from key results (vs. a manual %). */
    public function hasKeyResults(): bool
    {
        return $this->keyResults()->exists();
    }
}
