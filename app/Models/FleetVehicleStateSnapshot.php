<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetVehicleStateSnapshot extends Model
{
    protected $primaryKey = 'asset_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'asset_id',
        'last_event_id',
        'last_trip_id',
        'last_seen_at',
        'last_moving_at',
        'latitude',
        'longitude',
        'speed_kph',
        'heading_deg',
        'ignition',
        'motion_status',
        'battery_pct',
        'status',
        'consent_blocked',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_moving_at' => 'datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'speed_kph' => 'decimal:2',
        'ignition' => 'boolean',
        'consent_blocked' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function lastEvent(): BelongsTo
    {
        return $this->belongsTo(FleetTelemetryEvent::class, 'last_event_id');
    }

    public function lastTrip(): BelongsTo
    {
        return $this->belongsTo(FleetTrip::class, 'last_trip_id');
    }
}
