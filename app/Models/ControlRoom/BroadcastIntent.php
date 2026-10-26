<?php

namespace App\Models\ControlRoom;

use Illuminate\Database\Eloquent\Model;

final class BroadcastIntent extends Model
{
    protected $table = 'control_room_broadcast_intents';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = ['*'];
    protected $casts = [
        'captured_intent' => 'array',
        'selection_checked_at' => 'immutable_datetime',
        'selected_people_count' => 'integer',
        'selected_channel_attempt_count' => 'integer',
    ];
}
