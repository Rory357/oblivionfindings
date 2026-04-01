<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrFeedbackRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'subject_user_id',
        'requester_user_id',
        'reviewer_user_id',
        'review_type',
        'performance_review_id',
        'template_id',
        'questions_snapshot',
        'status',
        'due_date',
        'completed_at',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'datetime',
        'questions_snapshot' => 'array',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function performanceReview(): BelongsTo
    {
        return $this->belongsTo(HrPerformanceReview::class, 'performance_review_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(HrFeedbackTemplate::class, 'template_id');
    }

    /**
     * Get the questions for this request (from snapshot or fallback to standard).
     */
    public function getQuestionsMap(): array
    {
        if ($this->questions_snapshot) {
            return collect($this->questions_snapshot)->pluck('question', 'key')->all();
        }

        return \App\Domain\Hr\Services\FeedbackService::FEEDBACK_QUESTIONS;
    }

    public function responses(): HasMany
    {
        return $this->hasMany(HrFeedbackResponse::class, 'feedback_request_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
