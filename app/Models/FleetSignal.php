<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetSignal extends Model
{
    protected $fillable = [
        'asset_id',
        'asset_tracker_id',
        'geofence_id',
        'trip_id',
        'driver_session_id',
        'signal_type',
        'severity_hint',
        'occurred_at',
        'idempotency_key',
        'payload',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'payload' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function tracker(): BelongsTo
    {
        return $this->belongsTo(AssetTracker::class, 'asset_tracker_id');
    }

    public function geofence(): BelongsTo
    {
        return $this->belongsTo(AssetGeofence::class, 'geofence_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(FleetTrip::class, 'trip_id');
    }

    public function driverSession(): BelongsTo
    {
        return $this->belongsTo(FleetDriverSession::class, 'driver_session_id');
    }
}
