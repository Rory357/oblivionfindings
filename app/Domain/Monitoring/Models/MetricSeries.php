<?php

namespace App\Domain\Monitoring\Models;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class MetricSeries extends Model
{
    protected $table = 'monitoring_metric_series';

    protected $fillable = [
        'site_id',
        'device_id',
        'monitor_id',
        'metric',
        'dimensions',
        'dimensions_hash',
        'unit',
        'source',
        'data_class',
        'privacy_class',
        'retention_tier',
        'external_key',
        'first_point_at',
        'last_point_at',
    ];

    protected $casts = [
        'dimensions' => 'array',
        'first_point_at' => 'immutable_datetime',
        'last_point_at' => 'immutable_datetime',
    ];

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

    public function currentSummary(): HasOne
    {
        return $this->hasOne(MetricCurrentSummary::class, 'series_id');
    }
}
