<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrGoal extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'title',
        'description',
        'goal_type',
        'category',
        'parent_goal_id',
        'target_value',
        'current_value',
        'unit',
        'progress_percentage',
        'status',
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
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
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

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
