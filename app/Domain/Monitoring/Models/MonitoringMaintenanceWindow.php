<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use UnexpectedValueException;

final class MonitoringMaintenanceWindow extends Model
{
    protected $fillable = [
        'site_id',
        'monitor_id',
        'device_id',
        'name',
        'starts_at',
        'ends_at',
        'recurrence',
        'recurrence_until',
        'policy',
        'status',
        'reason',
    ];

    protected $casts = [
        'starts_at' => 'immutable_datetime',
        'ends_at' => 'immutable_datetime',
        'recurrence_until' => 'immutable_datetime',
    ];

    protected static function booted(): void
    {
        self::saving(function (self $window): void {
            if ($window->ends_at === null || $window->starts_at === null || $window->ends_at <= $window->starts_at) {
                throw new UnexpectedValueException('Maintenance window must end after it starts.');
            }

            if (! in_array($window->recurrence, [null, 'daily', 'weekly'], true)) {
                throw new UnexpectedValueException('Maintenance recurrence is unsupported.');
            }

            if (! in_array($window->status, ['active', 'cancelled', 'completed'], true)) {
                throw new UnexpectedValueException('Maintenance window status is unsupported.');
            }

            $deviceIds = [];
            if ($window->monitor_id !== null) {
                $deviceIds[] = (int) Monitor::query()->findOrFail($window->monitor_id)->device_id;
            }
            if ($window->device_id !== null) {
                $deviceIds[] = (int) $window->device_id;
            }

            foreach (array_unique($deviceIds) as $deviceId) {
                if (app(CanonicalDeviceSiteResolver::class)->resolve($deviceId) !== (int) $window->site_id) {
                    throw new UnexpectedValueException('Maintenance scope must belong to its canonical Site.');
                }
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
