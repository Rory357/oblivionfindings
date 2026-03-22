<?php

namespace App\Domain\Hr\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrFeedbackResponse extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'feedback_request_id',
        'question_key',
        'rating',
        'comment',
        'created_at',
    ];

    protected $casts = [
        'rating' => 'integer',
        'created_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function feedbackRequest(): BelongsTo
    {
        return $this->belongsTo(HrFeedbackRequest::class, 'feedback_request_id');
    }
}
