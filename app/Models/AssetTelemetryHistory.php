<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetTelemetryHistory extends Model
{
    protected $fillable = [
        'asset_id',
        'summary_date',
        'distance_km',
        'time_moving_minutes',
        'last_latitude',
        'last_longitude',
        'battery_min',
        'battery_max',
        'alerts_count',
    ];

    protected $casts = [
        'summary_date' => 'date',
        'distance_km' => 'decimal:3',
        'last_latitude' => 'decimal:7',
        'last_longitude' => 'decimal:7',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
