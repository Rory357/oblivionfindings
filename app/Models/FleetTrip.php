<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetTrip extends Model
{
    protected $fillable = [
        'asset_id',
        'driver_session_id',
        'started_at',
        'ended_at',
        'start_latitude',
        'start_longitude',
        'end_latitude',
        'end_longitude',
        'distance_km',
        'duration_s',
        'status',
        'consent_blocked',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'start_latitude' => 'decimal:7',
        'start_longitude' => 'decimal:7',
        'end_latitude' => 'decimal:7',
        'end_longitude' => 'decimal:7',
        'distance_km' => 'decimal:3',
        'consent_blocked' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function driverSession(): BelongsTo
    {
        return $this->belongsTo(FleetDriverSession::class, 'driver_session_id');
    }

    public function segments(): HasMany
    {
        return $this->hasMany(FleetTripSegment::class, 'fleet_trip_id');
    }
}
