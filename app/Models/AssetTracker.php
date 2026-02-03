<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetTracker extends Model
{
    protected $fillable = [
        'asset_id',
        'vendor',
        'device_uid',
        'imei',
        'serial_number',
        'status',
        'paired_at',
        'unpaired_at',
        'last_seen_at',
        'consent_id',
        'vendor_metadata',
    ];

    protected $casts = [
        'paired_at' => 'datetime',
        'unpaired_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'vendor_metadata' => 'array',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function consent(): BelongsTo
    {
        return $this->belongsTo(ClientConsent::class, 'consent_id');
    }

    public function telemetrySnapshots(): HasMany
    {
        return $this->hasMany(AssetTelemetrySnapshot::class, 'asset_tracker_id');
    }
}
