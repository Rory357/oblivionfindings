<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Retained assignment intent; contains no procedure or safety source narrative. */
class ProcedureReviewDelivery extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['assignment_revision' => 'integer', 'attempts' => 'integer', 'delivered_at' => 'datetime', 'next_attempt_at' => 'datetime'];

    public function reviewRequest(): BelongsTo
    {
        return $this->belongsTo(ProcedureReviewRequest::class, 'procedure_review_request_id');
    }
}
