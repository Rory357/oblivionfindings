<?php

namespace App\Models\Queclink;

use App\Domain\SecurityDevices\Management\Models\DeviceCommandAttempt;
use App\Domain\SecurityDevices\Management\Models\DeviceCommandRequest;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\FleetTelemetryEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

class QueclinkPendingCommand extends Model
{
    use WritesLegacyStorageContext;

    protected $table = 'queclink_pending_commands';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACKED = 'acked';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'queclink_device_id',
        'imei',
        'command_word',
        'raw_command',
        'raw_command_encrypted',
        'serial_number',
        'status',
        'created_by_user_id',
        'device_command_request_id',
        'device_command_attempt_id',
        'governed_sequence',
        'governed_role',
        'fulfilled_telemetry_event_id',
        'fulfilled_raw_frame_id',
        'sent_at',
        'sent_session_id',
        'acked_at',
        'fulfilled_at',
        'reconciliation_dispatched_at',
        'cancelled_at',
        'cancelled_by_user_id',
        'ack_response',
        'failed_reason',
        'expires_at',
    ];

    protected $hidden = [
        'raw_command',
        'raw_command_encrypted',
        'ack_response',
        'sent_session_id',
    ];

    protected $casts = [
        'governed_sequence' => 'integer',
        'sent_at' => 'datetime',
        'acked_at' => 'datetime',
        'fulfilled_at' => 'datetime',
        'reconciliation_dispatched_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected function rawCommand(): Attribute
    {
        return Attribute::make(
            get: function (?string $value, array $attributes): ?string {
                $encrypted = $attributes['raw_command_encrypted'] ?? null;

                return is_string($encrypted) && $encrypted !== ''
                    ? Crypt::decryptString($encrypted)
                    : $value;
            },
            set: function (?string $value): array {
                if ($value === null || $value === '') {
                    return [
                        'raw_command' => $value,
                        'raw_command_encrypted' => null,
                    ];
                }

                return [
                    'raw_command' => '[encrypted command payload]',
                    'raw_command_encrypted' => Crypt::encryptString($value),
                ];
            },
        );
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(QueclinkDevice::class, 'queclink_device_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function governedRequest(): BelongsTo
    {
        return $this->belongsTo(DeviceCommandRequest::class, 'device_command_request_id');
    }

    public function governedAttempt(): BelongsTo
    {
        return $this->belongsTo(DeviceCommandAttempt::class, 'device_command_attempt_id');
    }

    public function fulfilledTelemetryEvent(): BelongsTo
    {
        return $this->belongsTo(FleetTelemetryEvent::class, 'fulfilled_telemetry_event_id');
    }

    public function fulfilledRawFrame(): BelongsTo
    {
        return $this->belongsTo(QueclinkRawFrame::class, 'fulfilled_raw_frame_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function scopeQueued(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_QUEUED);
    }

    public function scopeForDevice(Builder $q, int $deviceId): Builder
    {
        return $q->where('queclink_device_id', $deviceId);
    }

    public function scopeRecentFor(Builder $q, QueclinkDevice $device, int $limit = 50): Builder
    {
        return $q
            ->where('queclink_device_id', $device->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit);
    }

    public function cancel(int $userId, ?Carbon $at = null): void
    {
        if ($this->status !== self::STATUS_QUEUED) {
            throw new \InvalidArgumentException('Only queued Queclink commands can be cancelled.');
        }

        $this->forceFill([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => $at ?? now(),
            'cancelled_by_user_id' => $userId,
        ])->save();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
