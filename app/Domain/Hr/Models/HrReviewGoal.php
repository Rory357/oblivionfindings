<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single structured goal on an HR performance review. Replaces the free-text
 * entries previously stored in `hr_performance_reviews.goals` (JSON), so review
 * goals are queryable, can carry status/rating, and can link to an OKR objective.
 */
class HrReviewGoal extends Model
{
    use AuditableChanges, HasFactory, WritesLegacyStorageContext;

    protected $table = 'hr_review_goals';

    protected $fillable = [
        'performance_review_id',
        'tenant_id',
        'description',
        'hr_goal_id',
        'status',
        'rating',
        'sort_order',
    ];

    protected $casts = [
        'rating' => 'integer',
        'sort_order' => 'integer',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(HrPerformanceReview::class, 'performance_review_id');
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(HrGoal::class, 'hr_goal_id');
    }
}
