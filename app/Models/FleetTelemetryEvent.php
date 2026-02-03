<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetTelemetryEvent extends Model
{
    protected $fillable = [
        'asset_id',
        'asset_tracker_id',
        'vendor',
        'vendor_message_id',
        'occurred_at',
        'received_at',
        'latitude',
        'longitude',
        'accuracy_m',
        'speed_kph',
        'heading_deg',
        'altitude_m',
        'ignition',
        'motion_status',
        'battery_pct',
        'external_power',
        'odometer_km',
        'event_type',
        'idempotency_key',
        'raw_payload',
        'consent_blocked',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'speed_kph' => 'decimal:2',
        'odometer_km' => 'decimal:3',
        'ignition' => 'boolean',
        'external_power' => 'boolean',
        'consent_blocked' => 'boolean',
        'raw_payload' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function tracker(): BelongsTo
    {
        return $this->belongsTo(AssetTracker::class, 'asset_tracker_id');
    }
}
