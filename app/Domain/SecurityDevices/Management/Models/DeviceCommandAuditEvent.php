<?php

namespace App\Domain\SecurityDevices\Management\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

class DeviceCommandAuditEvent extends Model
{
    protected $table = 'device_command_audit_events';

    protected $fillable = [
        'device_command_request_id', 'actor_user_id', 'action', 'safe_context',
        'previous_hash', 'event_hash', 'occurred_at',
    ];

    protected $casts = [
        'safe_context' => 'array',
        'occurred_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new UnexpectedValueException('Device command audit events are immutable.');
        });
        static::deleting(function (): never {
            throw new UnexpectedValueException('Device command audit events are immutable.');
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(DeviceCommandRequest::class, 'device_command_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
