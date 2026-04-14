<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @deprecated This model is retired as an active source of truth for device identity.
 * The canonical device registry is now App\Domain\SecurityDevices\Models\Device.
 * Device-to-asset links are managed via device_asset_links (DeviceAssetLink model).
 *
 * Remaining intentional uses:
 * - FleetTelemetryIngestService: vendor+device_uid lookup for telemetry routing
 * - Compatibility fallback for consent-linked tracker rows when no canonical
 *   assignment consent exists yet
 * - Telemetry/signal lineage: asset_tracker_id remains on historical tables
 * - Canonical device backfill/fallback via devices.legacy_asset_tracker_id
 *
 * Do NOT add new queries against this model for device identity or ownership.
 * Use Device + DeviceAssetLink + DeviceAssignment instead.
 * The temporary asset_trackers.device_id bridge FK was removed in PR26 after
 * audit confirmed no live consumers still depended on it.
 * This model remains intentionally for telemetry lineage, ingest routing, and
 * consent compatibility until those remaining bridges are explicitly retired.
 */
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
