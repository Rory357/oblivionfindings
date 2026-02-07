<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConflictDeclaration extends Model
{
    use HasFactory;

    protected $fillable = [
        'governance_meeting_id',
        'resolution_id',
        'board_member_id',
        'declaration_type',
        'declaration_text',
        'withdrew_from_voting',
        'withdrew_from_discussion',
        'recorded_in_minutes',
        'recorded_by',
        'declared_at',
    ];

    protected $casts = [
        'withdrew_from_voting' => 'boolean',
        'withdrew_from_discussion' => 'boolean',
        'recorded_in_minutes' => 'boolean',
        'declared_at' => 'datetime',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(GovernanceMeeting::class, 'governance_meeting_id');
    }

    public function resolution(): BelongsTo
    {
        return $this->belongsTo(Resolution::class);
    }

    public function boardMember(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isMaterial(): bool
    {
        return $this->declaration_type === 'material';
    }

    public function isRelated(): bool
    {
        return $this->declaration_type === 'related';
    }

    public function isPrejudicial(): bool
    {
        return $this->declaration_type === 'prejudicial';
    }

    public function preventsVoting(): bool
    {
        return $this->withdrew_from_voting;
    }
}
