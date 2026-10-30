<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One delivery attempt or acknowledgement of an obligation reminder. Never edited. */
class FleetObligationReminderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'reminder_id', 'action', 'channel', 'recipient_user_id', 'actor_user_id', 'note', 'request_key',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Obligation reminder events are a permanent record.'));
        static::deleting(fn () => throw new LogicException('Obligation reminder events are a permanent record.'));
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(FleetObligationReminder::class, 'reminder_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
