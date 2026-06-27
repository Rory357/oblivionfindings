<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrInterview extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'application_id',
        'scheduled_at',
        'duration_minutes',
        'location',
        'interview_type',
        'interviewers',
        'status',
        'notes',
        'rating',
        'outcome',
        'completed_by',
        'invite_sent_at',
        'reminder_sent_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'interviewers' => 'array',
        'rating' => 'integer',
        'duration_minutes' => 'integer',
        'invite_sent_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function application(): BelongsTo
    {
        return $this->belongsTo(HrApplication::class, 'application_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(HrInterviewScore::class, 'interview_id');
    }
}
