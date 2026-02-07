<?php

namespace App\Domain\Governance\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingAgendaItem extends Model
{
    use HasFactory, AuditableChanges;

    protected $fillable = [
        'governance_meeting_id',
        'order',
        'title',
        'description',
        'presenter_id',
        'duration_minutes',
        'item_type',
        'supporting_doc_ids',
        'is_confidential',
        'resolution_id',
    ];

    protected $casts = [
        'supporting_doc_ids' => 'array',
        'is_confidential' => 'boolean',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(GovernanceMeeting::class, 'governance_meeting_id');
    }

    public function presenter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'presenter_id');
    }

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class);
    }

    public function isDecision(): bool
    {
        return $this->item_type === 'decision';
    }

    public function isConsent(): bool
    {
        return $this->item_type === 'consent';
    }

    public function isConfidential(): bool
    {
        return $this->is_confidential;
    }
}
