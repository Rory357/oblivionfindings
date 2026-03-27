<?php

namespace App\Models\ControlRoom;

use App\Models\ControlRoomAlert;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AlertDiscussion extends Model
{
    protected $table = 'control_room_alert_discussions';

    protected $fillable = [
        'alert_id',
        'parent_id',
        'user_id',
        'type',
        'content',
        'is_internal',
        'attachments',
        'mentions',
        'edited_at',
    ];

    protected $casts = [
        'attachments' => 'array',
        'mentions' => 'array',
        'edited_at' => 'datetime',
        'is_internal' => 'boolean',
    ];

    // ── Relations ──────────────────────────────────────────────

    public function alert(): BelongsTo
    {
        return $this->belongsTo(ControlRoomAlert::class, 'alert_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }
}
