<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MonitoringRetentionTombstone extends Model
{
    protected $table = 'monitoring_retention_tombstones';

    protected $fillable = [
        'tombstone_uuid',
        'series_id',
        'snapshot_id',
        'site_id',
        'device_id',
        'monitor_id',
        'data_class',
        'retention_tier',
        'period_start',
        'period_end',
        'policy_id',
        'deleted_by_user_id',
        'job_reference',
        'deleted_at',
    ];

    protected $casts = [
        'period_start' => 'immutable_datetime',
        'period_end' => 'immutable_datetime',
        'deleted_at' => 'immutable_datetime',
    ];

    public function series(): BelongsTo
    {
        return $this->belongsTo(MetricSeries::class, 'series_id');
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ConfigurationSnapshot::class, 'snapshot_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(MonitoringRetentionPolicy::class, 'policy_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }
}
