<?php

namespace App\Models\ControlRoom;

use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertTask extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_TRANSFERRED = 'transferred';

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_TRANSFERRED,
    ];

    protected $table = 'control_room_alert_tasks';

    protected $fillable = [
        'alert_id',
        'title',
        'description',
        'assigned_to_user_id',
        'created_by_user_id',
        'status',
        'priority',
        'due_at',
        'completed_at',
        'estimated_minutes',
        'actual_minutes',
        'sort_order',
        'parent_task_id',
        'transferred_to_hs_corrective_action_id',
        'transferred_at',
        'transferred_by_user_id',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'transferred_at' => 'datetime',
    ];

    // ── Relations ──────────────────────────────────────────────

    public function alert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'alert_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function transferredCorrectiveAction(): BelongsTo
    {
        return $this->belongsTo(HsCorrectiveAction::class, 'transferred_to_hs_corrective_action_id');
    }

    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by_user_id');
    }

    public function parentTask(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class, 'task_id');
    }

    // ── Scopes ─────────────────────────────────────────────────

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNotIn('status', self::TERMINAL_STATUSES);
    }

    public function scopeAssignedTo($query, $userId)
    {
        return $query->where('assigned_to_user_id', $userId);
    }
}
