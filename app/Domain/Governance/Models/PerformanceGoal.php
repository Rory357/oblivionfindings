<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceGoal extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'performance_review_id',
        'pillar',
        'goal_description',
        'success_criteria',
        'weight',
        'target_score',
        'actual_score',
        'self_assessment',
        'board_assessment',
        'evidence_links',
        'evidence_summary',
        'status',
    ];

    protected $casts = [
        'weight' => 'decimal:2',
        'target_score' => 'decimal:1',
        'actual_score' => 'decimal:1',
        'evidence_links' => 'array',
    ];

    public function performanceReview(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class);
    }

    public function scopeByPillar($query, string $pillar)
    {
        return $query->where('pillar', $pillar);
    }

    public function isAchieved(): bool
    {
        return $this->status === 'achieved';
    }

    public function isMissed(): bool
    {
        return $this->status === 'missed';
    }

    public function getAchievementPercentage(): float
    {
        if (!$this->actual_score || !$this->target_score) {
            return 0;
        }
        return min(100, ($this->actual_score / $this->target_score) * 100);
    }
}
