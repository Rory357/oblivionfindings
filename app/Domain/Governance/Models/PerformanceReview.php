<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PerformanceReview extends Model
{
    use HasFactory, SoftDeletes, AuditableChanges;

    protected $fillable = [
        'reviewee_id',
        'review_cycle',
        'review_type',
        'period_start',
        'period_end',
        'status',
        'overall_rating',
        'overall_assessment',
        'board_decision',
        'decision_notes',
        'approval_resolution_id',
        'approved_by_board_at',
        'self_assessment',
        'self_assessment_submitted_at',
        'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'approved_by_board_at' => 'datetime',
        'self_assessment_submitted_at' => 'datetime',
    ];

    public function reviewee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvalResolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class, 'approval_resolution_id');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(PerformanceGoal::class);
    }

    public function kpis(): HasMany
    {
        return $this->hasMany(PerformanceKpi::class);
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(PerformanceFeedback::class);
    }

    public function getFeedbackSummary(): array
    {
        $feedback = $this->feedback()->whereNotNull('submitted_at')->get();
        if ($feedback->isEmpty()) {
            return ['count' => 0, 'avg_rating' => null, 'by_role' => []];
        }

        return [
            'count' => $feedback->count(),
            'avg_rating' => round($feedback->map->getAverageRating()->filter()->avg(), 2),
            'by_role' => $feedback->groupBy('reviewer_role')->map(fn($group) => [
                'count' => $group->count(),
                'avg_rating' => round($group->map->getAverageRating()->filter()->avg(), 2),
            ])->toArray(),
        ];
    }

    public function scopeByReviewee($query, int $userId)
    {
        return $query->where('reviewee_id', $userId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', '!=', 'completed');
    }

    public function scopeAnnual($query)
    {
        return $query->where('review_type', 'annual');
    }

    public function isDrafting(): bool
    {
        return $this->status === 'drafting';
    }

    public function isSelfReview(): bool
    {
        return $this->status === 'self_review';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function getWeightedScore(): ?float
    {
        $goals = $this->goals;
        if ($goals->isEmpty()) {
            return null;
        }

        $totalWeight = $goals->sum('weight');
        if ($totalWeight === 0) {
            return null;
        }

        $weightedSum = $goals->sum(fn($g) => ($g->actual_score ?? 0) * $g->weight);
        return round($weightedSum / $totalWeight, 2);
    }

    public function submitSelfAssessment(string $assessment): void
    {
        $this->update([
            'self_assessment' => $assessment,
            'self_assessment_submitted_at' => now(),
            'status' => 'board_review',
        ]);
    }

    public function approve(?int $resolutionId = null): void
    {
        $this->update([
            'status' => 'completed',
            'approval_resolution_id' => $resolutionId,
            'approved_by_board_at' => now(),
        ]);
    }
}
