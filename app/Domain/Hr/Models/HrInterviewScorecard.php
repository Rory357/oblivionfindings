<?php

namespace App\Domain\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrInterviewScorecard extends Model
{
    protected $fillable = [
        'tenant_id',
        'interview_id',
        'interviewer_user_id',
        'criteria',
        'overall_rating',
        'recommendation',
        'strengths',
        'concerns',
        'overall_notes',
    ];

    protected $casts = [
        'criteria' => 'array',
        'overall_rating' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function interview(): BelongsTo
    {
        return $this->belongsTo(HrInterview::class, 'interview_id');
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_user_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Scopes                                                             */
    /* ------------------------------------------------------------------ */

    public function scopeForTenant($query, ?int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
