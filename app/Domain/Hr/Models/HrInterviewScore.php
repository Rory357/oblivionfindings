<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrInterviewScore extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'interview_id',
        'kit_id',
        'interviewer_user_id',
        'criteria_scores',
        'overall_score',
        'recommendation',
        'notes',
        'submitted_at',
    ];

    protected $casts = [
        'criteria_scores' => 'array',
        'overall_score' => 'decimal:2',
        'submitted_at' => 'datetime',
    ];

    public function interview(): BelongsTo
    {
        return $this->belongsTo(HrInterview::class, 'interview_id');
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(HrInterviewKit::class, 'kit_id');
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_user_id');
    }
}

