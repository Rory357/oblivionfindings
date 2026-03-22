<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrSurveyQuestion extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'survey_id',
        'question_text',
        'question_type',
        'options',
        'sort_order',
        'is_required',
    ];

    protected $casts = [
        'options' => 'array',
        'sort_order' => 'integer',
        'is_required' => 'boolean',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function survey(): BelongsTo
    {
        return $this->belongsTo(HrSurvey::class, 'survey_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(HrSurveyAnswer::class, 'question_id');
    }
}
