<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingRsvp extends Model
{
    protected $fillable = [
        'governance_meeting_id', 'board_member_id', 'response',
        'decline_reason', 'dietary_requirements', 'dietary_notes', 'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
        'dietary_requirements' => 'boolean',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(GovernanceMeeting::class, 'governance_meeting_id');
    }

    public function boardMember(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class);
    }

    public function isAccepted(): bool
    {
        return $this->response === 'accepted';
    }

    public function isDeclined(): bool
    {
        return $this->response === 'declined';
    }

    public function isTentative(): bool
    {
        return $this->response === 'tentative';
    }
}
