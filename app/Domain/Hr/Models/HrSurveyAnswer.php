<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrSurveyAnswer extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'response_id',
        'question_id',
        'answer_text',
        'answer_rating',
        'answer_choice',
    ];

    protected $casts = [
        'answer_rating' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function response(): BelongsTo
    {
        return $this->belongsTo(HrSurveyResponse::class, 'response_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(HrSurveyQuestion::class, 'question_id');
    }
}
