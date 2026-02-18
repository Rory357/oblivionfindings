<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrEngagementSurveyQuestion extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'survey_id',
        'question_type',
        'question_text',
        'options',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(HrEngagementSurvey::class, 'survey_id');
    }
}
