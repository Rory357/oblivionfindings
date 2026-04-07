<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetDrivingMetric extends Model
{
    protected $fillable = [
        'asset_id',
        'period_start',
        'period_end',
        'harsh_brake_count',
        'accel_count',
        'speeding_events',
        'idle_minutes',
        'score',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'harsh_brake_count' => 'integer',
        'accel_count' => 'integer',
        'speeding_events' => 'integer',
        'idle_minutes' => 'integer',
        'score' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
