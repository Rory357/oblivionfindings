<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetTripSegment extends Model
{
    protected $fillable = [
        'fleet_trip_id',
        'seq',
        'started_at',
        'ended_at',
        'distance_km',
        'duration_s',
        'polyline',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'distance_km' => 'decimal:3',
        'duration_s' => 'integer',
        'seq' => 'integer',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(FleetTrip::class, 'fleet_trip_id');
    }
}
