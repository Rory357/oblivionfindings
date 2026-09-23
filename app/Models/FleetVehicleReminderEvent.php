<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Retained activity on a vehicle reminder. */
class FleetVehicleReminderEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'reminder_id', 'action', 'actor_user_id', 'note', 'before_json', 'after_json',
        'request_key', 'request_fingerprint', 'occurred_at',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Reminder activity is retained as recorded.'));
        static::deleting(fn () => throw new \LogicException('Reminder activity is retained as recorded.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
