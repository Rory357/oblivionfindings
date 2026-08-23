<?php

namespace App\Domain\SecurityDevices\Models;

use App\Domain\Monitoring\Discovery\Models\DeviceIdentityEvidence;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Models\AssetTracker;
use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\User;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Canonical Security & Devices registry.
 *
 * Remaining legacy bridge columns kept intentionally in PR26:
 * - legacy_location_hardware_id: temporarily retained for integration event
 *   backfill plus the narrow LocationHardware compatibility shadow that still
 *   supports integration event provenance and UniFi bridge metadata
 * - legacy_asset_tracker_id: retained only for optional historical telemetry
 *   lineage, consent compatibility, and migration metadata; it is not an
 *   ownership binding
 *
 * Removed in PR26:
 * - legacy_control_room_device_id: superseded by the surviving
 *   control_room_devices.canonical_device_id projection bridge
 */
class Device extends Model
{
    use AuditableChanges;
    use HasFactory;
    use SoftDeletes;
    use WritesLegacyStorageContext;

    protected $table = 'devices';

    protected $fillable = [
        'tenant_id',
        'device_uid',
        'name',
        'domain',
        'category',
        'subcategory',
        'manufacturer',
        'model',
        'serial_number',
        'mac_address',
        'imei',
        'asset_tag',
        'firmware_version',
        'ip_address',
        'next_service_due',
        'status',
        'health_status',
        'last_seen_at',
        'last_signal_at',
        'battery_level',
        'battery_updated_at',
        'commissioned_at',
        'warranty_expires_at',
        'expected_lifespan_months',
        'purchase_price',
        'provider',
        'external_ref',
        'config',
        'meta',
        'local_intended_state',
        'provider_observed_state',
        'provider_field_overrides',
        'latitude',
        'longitude',
        'location_description',
        'notes',
        'legacy_location_hardware_id',
        'legacy_asset_tracker_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'status' => DeviceStatus::class,
        'health_status' => HealthStatus::class,
        'last_seen_at' => 'datetime',
        'last_signal_at' => 'datetime',
        'battery_updated_at' => 'datetime',
        'commissioned_at' => 'date',
        'warranty_expires_at' => 'date',
        'next_service_due' => 'date',
        'purchase_price' => 'decimal:2',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'external_ref' => 'array',
        'config' => 'array',
        'meta' => 'array',
        'local_intended_state' => 'array',
        'provider_observed_state' => 'array',
        'provider_field_overrides' => 'array',
    ];

    protected $hidden = [
        'local_intended_state',
        'provider_observed_state',
        'provider_field_overrides',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $device): void {
            if (empty($device->device_uid)) {
                $device->device_uid = self::generateUid($device->domain, $device->category);
            }
        });
    }

    protected static function newFactory(): DeviceFactory
    {
        return DeviceFactory::new();
    }

    public static function generateUid(?string $domain, ?string $category): string
    {
        $prefix = strtoupper(substr($category ?? $domain ?? 'DEV', 0, 3));

        return $prefix.'-'.strtoupper(Str::random(8));
    }

    // ── Relationships ─────────────────────────────────────────────

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeviceAssignment::class);
    }

    public function activeAssignment(): ?DeviceAssignment
    {
        return $this->assignments()->whereNull('released_at')->first();
    }

    public function assetLinks(): HasMany
    {
        return $this->hasMany(DeviceAssetLink::class);
    }

    public function activeAssetLinks(): HasMany
    {
        return $this->assetLinks()->whereNull('unlinked_at');
    }

    public function legacyAssetTracker(): BelongsTo
    {
        return $this->belongsTo(AssetTracker::class, 'legacy_asset_tracker_id');
    }

    public function parentRelationships(): HasMany
    {
        return $this->hasMany(DeviceRelationship::class, 'child_device_id')->active();
    }

    public function childRelationships(): HasMany
    {
        return $this->hasMany(DeviceRelationship::class, 'parent_device_id')->active();
    }

    public function relationshipHistoryAsChild(): HasMany
    {
        return $this->hasMany(DeviceRelationship::class, 'child_device_id')->unlinked();
    }

    public function relationshipHistoryAsParent(): HasMany
    {
        return $this->hasMany(DeviceRelationship::class, 'parent_device_id')->unlinked();
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(DeviceGroup::class, 'device_group_members');
    }

    public function events(): HasMany
    {
        return $this->hasMany(DeviceEvent::class);
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(DeviceMaintenanceRecord::class);
    }

    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    public function configurationSnapshots(): HasMany
    {
        return $this->hasMany(ConfigurationSnapshot::class, 'device_id');
    }

    public function latestConfigurationSnapshot(): HasOne
    {
        return $this->hasOne(ConfigurationSnapshot::class, 'device_id')
            ->ofMany(['captured_at' => 'max', 'id' => 'max']);
    }

    public function identityEvidence(): HasMany
    {
        return $this->hasMany(DeviceIdentityEvidence::class, 'canonical_device_id');
    }

    public function discoveryCandidates(): HasMany
    {
        return $this->hasMany(DiscoveryCandidate::class, 'canonical_device_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DeviceDocument::class)->available();
    }

    public function documentHistory(): HasMany
    {
        return $this->hasMany(DeviceDocument::class)->history();
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeByDomain($query, string|DeviceDomain $domain)
    {
        $value = $domain instanceof DeviceDomain ? $domain->value : $domain;

        return $query->where('domain', $value);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByStatus($query, string|DeviceStatus $status)
    {
        $value = $status instanceof DeviceStatus ? $status->value : $status;

        return $query->where('status', $value);
    }

    public function scopeOperational($query)
    {
        return $query->whereIn('status', [
            DeviceStatus::Active->value,
            DeviceStatus::Degraded->value,
        ]);
    }

    public function scopeByProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    public function scopeByHealth($query, string|HealthStatus $health)
    {
        $value = $health instanceof HealthStatus ? $health->value : $health;

        return $query->where('health_status', $value);
    }

    public function scopeNeedingAttention($query)
    {
        return $query->where(function ($q) {
            $q->where('health_status', HealthStatus::Critical->value)
                ->orWhere('health_status', HealthStatus::Warning->value)
                ->orWhere('status', DeviceStatus::Offline->value);
        });
    }

    public function scopeLowBattery($query, int $threshold = 20)
    {
        return $query->whereNotNull('battery_level')
            ->where('battery_level', '<=', $threshold);
    }

    // ── Helpers ────────────────────────────────────────────────────

    public function isOnline(): bool
    {
        return $this->status === DeviceStatus::Active;
    }

    public function getDomainEnum(): ?DeviceDomain
    {
        return DeviceDomain::tryFrom($this->domain);
    }
}
