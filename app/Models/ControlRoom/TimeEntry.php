<?php

namespace App\Models\ControlRoom;

use App\Models\ControlRoomAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    protected $table = 'control_room_time_entries';

    protected $fillable = [
        'alert_id',
        'task_id',
        'user_id',
        'started_at',
        'ended_at',
        'duration_minutes',
        'description',
        'billable',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'billable' => 'boolean',
    ];

    // ── Relations ──────────────────────────────────────────────

    public function alert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'alert_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AlertTask::class, 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ── Methods ────────────────────────────────────────────────

    /**
     * Determine if this time entry is currently running (timer started but not stopped).
     */
    public function isRunning(): bool
    {
        return $this->started_at !== null && $this->ended_at === null;
    }
}
