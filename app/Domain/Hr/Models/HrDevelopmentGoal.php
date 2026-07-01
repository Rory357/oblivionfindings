<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrDevelopmentGoal extends Model
{
    use AuditableChanges, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'employee_user_id',
        'manager_user_id',
        'hr_goal_id',
        'title',
        'description',
        'category',
        'competency_area',
        'competency_id',
        'target_level',
        'current_level',
        'status',
        'progress_percent',
        'start_date',
        'due_date',
        'next_review_at',
        'completed_at',
        'review_frequency',
        'review_notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'target_level' => 'integer',
        'current_level' => 'integer',
        'progress_percent' => 'integer',
        'start_date' => 'date',
        'due_date' => 'date',
        'next_review_at' => 'date',
        'completed_at' => 'date',
    ];

    /** Days between reviews for each cadence. */
    public const REVIEW_CADENCE_DAYS = [
        'weekly' => 7,
        'fortnightly' => 14,
        'monthly' => 30,
        'quarterly' => 90,
    ];

    /** The next review date from a base date + the plan's cadence. */
    public function nextReviewFrom(\DateTimeInterface $base): ?\Illuminate\Support\Carbon
    {
        $days = self::REVIEW_CADENCE_DAYS[$this->review_frequency] ?? null;

        return $days ? \Illuminate\Support\Carbon::parse($base)->addDays($days) : null;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_user_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_user_id');
    }

    /** Optional OKR objective this development plan rolls up into. */
    public function goal(): BelongsTo
    {
        return $this->belongsTo(HrGoal::class, 'hr_goal_id');
    }

    /** Optional formal competency this plan develops. */
    public function competency(): BelongsTo
    {
        return $this->belongsTo(HrCompetency::class, 'competency_id');
    }

    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['not_started', 'in_progress', 'blocked']);
    }
}
