<?php

namespace App\Domain\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\AuditableChanges;
class MeetingAttendance extends Model
{
    use HasFactory, AuditableChanges;

    protected $table = 'meeting_attendances';

    protected $fillable = [
        'governance_meeting_id',
        'board_member_id',
        'status',
        'marked_at',
        'marked_by',
        'apology_reason',
        'arrived_late',
        'arrival_time',
        'left_early',
        'departure_time',
    ];

    protected $casts = [
        'marked_at' => 'datetime',
        'arrival_time' => 'datetime',
        'departure_time' => 'datetime',
        'arrived_late' => 'boolean',
        'left_early' => 'boolean',
    ];

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(GovernanceMeeting::class, 'governance_meeting_id');
    }

    public function boardMember(): BelongsTo
    {
        return $this->belongsTo(BoardMember::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function isPresent(): bool
    {
        return $this->status === 'present';
    }

    public function isApology(): bool
    {
        return $this->status === 'apology';
    }

    public function isNoShow(): bool
    {
        return $this->status === 'no_show';
    }

    public function scopePresent($query)
    {
        return $query->where('status', 'present');
    }

    public function scopeApologies($query)
    {
        return $query->where('status', 'apology');
    }
}
