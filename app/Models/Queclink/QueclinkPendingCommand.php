<?php

namespace App\Models\Queclink;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class QueclinkPendingCommand extends Model
{
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
        'tenant_id',
        'command_word',
        'raw_command',
        'serial_number',
        'status',
        'created_by_user_id',
        'sent_at',
        'acked_at',
        'cancelled_at',
        'cancelled_by_user_id',
        'ack_response',
        'failed_reason',
        'expires_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'acked_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(QueclinkDevice::class, 'queclink_device_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
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
