<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An in-app vehicle follow-up with an owner and a due time. It appears in All
 * Tasks and on calendars. Completing it records the follow-up only; the
 * linked obligation (a renewal, service or evidence) stays with its source.
 */
class FleetVehicleReminder extends Model
{
    public const STATES = ['scheduled', 'acknowledged', 'completed', 'paused'];

    protected $fillable = [
        'asset_id', 'title', 'action_text', 'source_type', 'source_id', 'due_at', 'repeat_months',
        'owner_user_id', 'backup_user_id', 'state', 'lock_version', 'created_by_user_id', 'completed_at',
        'request_key', 'request_fingerprint',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'repeat_months' => 'integer',
        'lock_version' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(User::class, 'backup_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FleetVehicleReminderEvent::class, 'reminder_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->state, ['scheduled', 'acknowledged'], true);
    }
}
