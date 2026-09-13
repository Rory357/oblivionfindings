<?php

namespace App\Domain\Governance\Models;

use App\Domain\Governance\Services\GovernanceResolutionAuthorityService;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    /**
     * Board approval — completes the review.
     *
     * Completing without a resolution records no resolution authority. When a
     * resolution is cited, authority comes only from an explicit binding
     * created while the paper was a draft, naming this exact review and a
     * fingerprint of the board decision (rating, assessment, decision, notes
     * and goal scores). Motion wording never confers authority; the binding is
     * verified under row locks and consumed once.
     *
     * @throws ValidationException when the cited resolution does not authorise this review
     */
    public function approve(?int $resolutionId = null, ?int $actorId = null): void
    {
        if ($resolutionId === null) {
            $this->update([
                'status' => 'completed',
                'approval_resolution_id' => null,
                'approved_by_board_at' => now(),
            ]);

            return;
        }

        $authority = app(GovernanceResolutionAuthorityService::class);

        DB::transaction(function () use ($resolutionId, $actorId, $authority) {
            $lockedReview = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();
            $resolution = Resolution::query()->whereKey($resolutionId)->lockForUpdate()->first();

            if (! $resolution) {
                throw ValidationException::withMessages([
                    'resolution_id' => 'The selected resolution does not exist.',
                ]);
            }

            if ($resolution->status !== 'closed' || $resolution->outcome !== 'carried') {
                throw ValidationException::withMessages([
                    'resolution_id' => 'Only a closed resolution with a carried outcome can approve a performance review.',
                ]);
            }

            if ($lockedReview->isCompleted()) {
                throw ValidationException::withMessages([
                    'resolution_id' => 'This performance review is already completed.',
                ]);
            }

            $alreadyUsed = static::query()
                ->where('approval_resolution_id', $resolutionId)
                ->where('id', '!=', $lockedReview->id)
                ->exists();

            if ($alreadyUsed) {
                throw ValidationException::withMessages([
                    'resolution_id' => 'The selected resolution has already been applied to another performance review.',
                ]);
            }

            // Hold the scored goals steady while the decision is compared.
            $lockedReview->goals()->lockForUpdate()->get(['id']);

            try {
                $authority->verifyAndConsume(
                    $resolution,
                    GovernanceResolutionBinding::SUBJECT_PERFORMANCE_REVIEW,
                    (int) $lockedReview->getKey(),
                    $authority->performanceReviewTerms($lockedReview),
                    $actorId ?? auth()->id(),
                );
            } catch (\DomainException $exception) {
                throw ValidationException::withMessages([
                    'resolution_id' => 'The selected resolution does not authorize approval of this performance review. '.$exception->getMessage(),
                ]);
            }

            $lockedReview->update([
                'status' => 'completed',
                'approval_resolution_id' => $resolutionId,
                'approved_by_board_at' => now(),
            ]);
        }, 3);

        $this->refresh();
    }
}
