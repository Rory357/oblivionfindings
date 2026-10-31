<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringThresholdRule extends Model
{
    protected $fillable = [
        'name',
        'scope_kind',
        'site_id',
        'device_id',
        'monitor_id',
        'device_domain',
        'device_category',
        'monitor_kind',
        'metric_key',
        'threshold_direction',
        'warning_rising_threshold',
        'warning_falling_threshold',
        'critical_rising_threshold',
        'critical_falling_threshold',
        'is_active',
        'version',
        'created_by_user_id',
        'updated_by_user_id',
        'deactivated_at',
        'deactivated_by_user_id',
        'deactivation_reason',
    ];

    protected $casts = [
        'site_id' => 'integer',
        'device_id' => 'integer',
        'monitor_id' => 'integer',
        'warning_rising_threshold' => 'decimal:6',
        'warning_falling_threshold' => 'decimal:6',
        'critical_rising_threshold' => 'decimal:6',
        'critical_falling_threshold' => 'decimal:6',
        'is_active' => 'boolean',
        'version' => 'integer',
        'deactivated_at' => 'immutable_datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class, 'monitor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by_user_id');
    }
}
