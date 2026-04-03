<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
