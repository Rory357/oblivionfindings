<?php

namespace App\Models\ControlRoom;

use App\Models\ControlRoomAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatorNote extends Model
{
    public const TYPE_NOTE = 'note';

    public const TYPE_ACTION = 'action';

    public const TYPE_ESCALATION = 'escalation';

    public const TYPE_DECISION = 'decision';

    public const TYPE_HANDOVER = 'handover';

    public const TYPES = [
        self::TYPE_NOTE,
        self::TYPE_ACTION,
        self::TYPE_ESCALATION,
        self::TYPE_DECISION,
        self::TYPE_HANDOVER,
    ];

    public const PURPOSE_GENERAL = 'general';

    public const PURPOSE_IMMEDIATE_CONTROLS = 'immediate_controls';

    public const PURPOSE_ESCALATION_HANDOVER = 'escalation_handover';

    public const PURPOSES = [
        self::PURPOSE_GENERAL,
        self::PURPOSE_IMMEDIATE_CONTROLS,
        self::PURPOSE_ESCALATION_HANDOVER,
    ];

    protected $table = 'control_room_operator_notes';

    protected $fillable = [
        'alert_id',
        'shift_id',
        'type',
        'purpose',
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
