<?php

namespace App\Models\ControlRoom;

use App\Models\ControlRoomAlert;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertQueue extends Model
{
    protected $table = 'control_room_alert_queue';

    protected $fillable = [
        'alert_id',
        'queue_id',
        'entered_at',
        'exited_at',
        'exit_reason',
    ];

    protected $casts = [
        'entered_at' => 'datetime',
        'exited_at' => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'alert_id');
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(TriageQueue::class, 'queue_id');
    }
}
