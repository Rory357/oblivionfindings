<?php

namespace App\Domain\SecurityDevices\Management\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use UnexpectedValueException;

class DeviceCommandIntakeAudit extends Model
{
    protected $table = 'device_command_intake_audits';

    protected $fillable = [
        'event_uuid', 'device_command_request_id', 'actor_user_id', 'outcome',
        'safe_reason_code', 'target_fingerprint', 'capability', 'capability_fingerprint',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $audit): void {
            $audit->event_uuid ??= (string) Str::orderedUuid();
        });
        static::updating(function (): never {
            throw new UnexpectedValueException('Device command intake audit evidence is immutable.');
        });
        static::deleting(function (): never {
            throw new UnexpectedValueException('Device command intake audit evidence is retained permanently.');
        });
    }

    public function command(): BelongsTo
    {
        return $this->belongsTo(DeviceCommandRequest::class, 'device_command_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
