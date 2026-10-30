<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reminder for one due point of a vehicle obligation (a service schedule or
 * a compliance record), with its in-app delivery and acknowledgement.
 */
class FleetObligationReminder extends Model
{
    public const STATE_SCHEDULED = 'scheduled';

    public const STATE_SENT = 'sent';

    public const STATE_FAILED = 'failed';

    public const STATE_ACKNOWLEDGED = 'acknowledged';

    protected $fillable = [
        'asset_id', 'source_type', 'source_id', 'cycle_key', 'due_on', 'due_km', 'state', 'owner_user_id',
        'last_attempt_at', 'last_error', 'acknowledged_by_user_id', 'acknowledged_at', 'acknowledgement_note',
        'lock_version',
    ];

    protected $casts = [
        'source_id' => 'integer',
        'due_on' => 'date',
        'due_km' => 'float',
        'last_attempt_at' => 'datetime',
        'acknowledged_at' => 'datetime',
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

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FleetObligationReminderEvent::class, 'reminder_id');
    }
}
