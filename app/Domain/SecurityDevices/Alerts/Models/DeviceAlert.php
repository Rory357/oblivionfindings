<?php

namespace App\Domain\SecurityDevices\Alerts\Models;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One device problem someone can acknowledge, assign or snooze (S&D Phase D). Oblivion opens
 * and closes it from the device's checks; people only add to it.
 *
 * @property int $id
 * @property int $device_id
 * @property int|null $site_id
 * @property string $kind
 * @property string $severity
 * @property string $title
 * @property string $state
 */
final class DeviceAlert extends Model
{
    public const string STATE_OPEN = 'open';

    public const string STATE_SNOOZED = 'snoozed';

    public const string STATE_DONE = 'done';

    public const array ACTIVE_STATES = [self::STATE_OPEN, self::STATE_SNOOZED];

    protected $table = 'security_device_alerts';

    protected $fillable = [
        'device_id', 'site_id', 'kind', 'severity', 'title', 'state', 'monitor_id', 'opened_at', 'last_failing_at',
        'acknowledged_at', 'acknowledged_by_user_id', 'acknowledgement_note', 'remind_at',
        'assigned_to_user_id', 'assigned_at', 'snoozed_until', 'snoozed_by_user_id',
        'closed_at', 'close_reason', 'reminded_at',
    ];

    protected $casts = [
        'opened_at' => 'immutable_datetime',
        'last_failing_at' => 'immutable_datetime',
        'acknowledged_at' => 'immutable_datetime',
        'remind_at' => 'immutable_datetime',
        'assigned_at' => 'immutable_datetime',
        'snoozed_until' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime',
        'reminded_at' => 'immutable_datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeviceAlertEvent::class, 'alert_id')->orderBy('at')->orderBy('id');
    }

    /** @param  Builder<DeviceAlert>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('state', self::ACTIVE_STATES);
    }

    public function isActive(): bool
    {
        return in_array($this->state, self::ACTIVE_STATES, true);
    }
}
