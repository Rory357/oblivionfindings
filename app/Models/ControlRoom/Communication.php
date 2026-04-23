<?php

namespace App\Models\ControlRoom;

use App\Models\ControlRoomAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Communication extends Model
{
    protected $table = 'control_room_communications';

    protected $fillable = [
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
        'template_used',
        'status',
        'status_detail',
        'force_delivery',
        'sent_at',
        'delivered_at',
        'retry_count',
        'call_duration_seconds',
        'call_recording_path',
        'initiated_by_user_id',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'force_delivery' => 'boolean',
    ];

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
