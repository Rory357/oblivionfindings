<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\AuditableChanges;
class PerformanceFeedback extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'performance_feedback';

    protected $fillable = [
        'performance_review_id',
        'reviewer_id',
        'reviewer_role',
        'ratings',
        'strengths',
        'areas_for_improvement',
        'comments',
        'is_anonymous',
        'submitted_at',
    ];

    protected $casts = [
        'ratings' => 'array',
        'is_anonymous' => 'boolean',
        'submitted_at' => 'datetime',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(PerformanceReview::class, 'performance_review_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    public function submit(): void
    {
        $this->update(['submitted_at' => now()]);
    }

    public function getAverageRating(): ?float
    {
        if (empty($this->ratings)) {
            return null;
        }
        return round(collect($this->ratings)->avg(), 2);
    }
}
