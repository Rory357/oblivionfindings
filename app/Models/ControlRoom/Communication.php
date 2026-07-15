<?php

namespace App\Models\ControlRoom;

use App\Models\ControlRoomAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Communication extends Model
{
    protected $table = 'control_room_communications';

    protected $fillable = [
        'delivery_key',
        'alert_id',
        'broadcast_group_id',
        'playbook_run_id',
        'channel',
        'direction',
        'purpose',
        'target_user_id',
        'target_phone',
        'target_email',
        'target_external',
        'subject',
        'content',
        'notification_payload',
        'template_used',
        'status',
        'status_detail',
        'force_delivery',
        'sent_at',
        'delivered_at',
        'superseded_at',
        'retry_count',
        'call_duration_seconds',
        'call_recording_path',
        'initiated_by_user_id',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'superseded_at' => 'datetime',
        'notification_payload' => 'array',
        'force_delivery' => 'boolean',
    ];

    /**
     * Rows that represent operator-authored conversation messages.
     *
     * Notification/escalation rows are delivery ledgers, not chat messages,
     * and superseded rows are retained only as outbox audit history.
     */
    public function scopeConversational(Builder $query): Builder
    {
        return $query
            ->whereNull('superseded_at')
            ->where(function (Builder $purposeQuery): void {
                $purposeQuery->whereNull('purpose')
                    ->orWhereNotIn('purpose', ['notification', 'escalation']);
            });
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'alert_id');
    }

    public function playbookRun(): BelongsTo
    {
        return $this->belongsTo(PlaybookRun::class, 'playbook_run_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
