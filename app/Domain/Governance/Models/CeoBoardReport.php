<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CeoBoardReport extends Model
{
    use AuditableChanges;

    protected $fillable = [
        'governance_meeting_id', 'submitted_by', 'status',
        'operational_summary', 'key_achievements', 'challenges_and_risks',
        'staffing_update', 'compliance_status', 'financial_summary',
        'recommendations', 'attachments', 'submitted_at', 'deadline',
    ];

    protected $casts = [
        'attachments' => 'array',
        'submitted_at' => 'datetime',
        'deadline' => 'datetime',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(GovernanceMeeting::class, 'governance_meeting_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isSubmitted(): bool
    {
        return $this->status === 'submitted';
    }

    public function isOverdue(): bool
    {
        return $this->isDraft() && $this->deadline && $this->deadline->isPast();
    }

    public function submit(): void
    {
        $this->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
    }
}
