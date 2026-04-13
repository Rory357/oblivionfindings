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
 * Remaining valid uses (temporary bridge):
 * - FleetTelemetryIngestService: vendor+device_uid lookup for telemetry routing
 * - Consent management (consentIndex, grantConsent, revokeConsent): tied to consent_id FK
 * - Telemetry snapshot relationships: asset_telemetry_snapshots.asset_tracker_id FK
 * - Fleet signal audit trail: fleet_signals.asset_tracker_id FK
 *
 * Do NOT add new queries against this model for device identity or ownership.
 * Use Device + DeviceAssetLink + DeviceAssignment instead.
 * This model and its table will be archived in a future cleanup PR.
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
