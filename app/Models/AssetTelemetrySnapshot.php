<?php

namespace App\Models;

use App\Domain\SecurityDevices\Models\Device;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetTelemetrySnapshot extends Model
{
    protected $fillable = [
        'asset_id',
        'asset_tracker_id',
        'device_id',
        'occurred_at',
        'received_at',
        'latitude',
        'longitude',
        'accuracy_m',
        'speed_kph',
        'movement_status',
        'battery_pct',
        'power_source',
        'tamper_flag',
        'sos_flag',
        'vendor_payload_hash',
        'vendor_metadata',
        'consent_blocked',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'speed_kph' => 'decimal:2',
        'tamper_flag' => 'boolean',
        'sos_flag' => 'boolean',
        'vendor_metadata' => 'array',
        'consent_blocked' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function tracker(): BelongsTo
    {
        return $this->belongsTo(AssetTracker::class, 'asset_tracker_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
