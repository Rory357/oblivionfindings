<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrEngagementSurveyResponse extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'survey_id',
        'user_id',
        'respondent_hash',
        'answers',
        'overall_score',
        'submitted_at',
    ];

    protected $casts = [
        'answers' => 'array',
        'overall_score' => 'decimal:2',
        'submitted_at' => 'datetime',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(HrEngagementSurvey::class, 'survey_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
