<?php

namespace App\Domain\SecurityDevices\Models;

use App\Domain\SecurityDevices\Enums\DeviceDomain;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Models\Asset;
use App\Models\Concerns\AuditableChanges;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Device extends Model
{
    use HasFactory;
    use SoftDeletes;
    use AuditableChanges;

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
        'latitude',
        'longitude',
        'location_description',
        'notes',
        'legacy_location_hardware_id',
        'legacy_control_room_device_id',
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
        'purchase_price' => 'decimal:2',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'external_ref' => 'array',
        'config' => 'array',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $device): void {
            if (empty($device->device_uid)) {
                $device->device_uid = self::generateUid($device->domain, $device->category);
            }
        });
    }

    public static function generateUid(?string $domain, ?string $category): string
    {
        $prefix = strtoupper(substr($category ?? $domain ?? 'DEV', 0, 3));

        return $prefix . '-' . strtoupper(Str::random(8));
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

    public function parentRelationships(): HasMany
    {
        return $this->hasMany(DeviceRelationship::class, 'child_device_id');
    }

    public function childRelationships(): HasMany
    {
        return $this->hasMany(DeviceRelationship::class, 'parent_device_id');
    }

    public function groups(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
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

    public function documents(): HasMany
    {
        return $this->hasMany(DeviceDocument::class);
    }

    // ── Scopes ────────────────────────────────────────────────────

    public function scopeForTenant($query, int $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

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
