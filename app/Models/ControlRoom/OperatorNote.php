<?php

namespace App\Models\ControlRoom;

use App\Models\ControlRoomAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatorNote extends Model
{
    protected $table = 'control_room_operator_notes';

    protected $fillable = [
        'alert_id',
        'shift_id',
        'type',
        'content',
        'is_pinned',
        'requires_followup',
        'followup_at',
        'user_id',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'requires_followup' => 'boolean',
        'followup_at' => 'datetime',
    ];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'alert_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
