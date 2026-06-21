<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BehaviourSupportPlanReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'behaviour_support_plan_id',
        'reviewed_by',
        'reviewed_at',
        'outcome',
        'next_review_date',
        'resulting_status',
        'notes',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'next_review_date' => 'date',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BehaviourSupportPlan::class, 'behaviour_support_plan_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
