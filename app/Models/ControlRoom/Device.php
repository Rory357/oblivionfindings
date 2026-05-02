<?php

namespace App\Models\ControlRoom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Control Room device projection — signal pipeline support model.
 *
 * This is NOT the canonical device registry. The source of truth for device
 * identity (name, domain, category, health, assignment) is:
 *   App\Domain\SecurityDevices\Models\Device
 *
 * This model exists to support Control Room-specific concerns:
 * - Signal pipeline: control_room_signals.device_id references this table
 * - Alert linkage: control_room_alerts.device_id references this table
 * - CR-specific status: last_signal_at, stale detection, battery alerts
 * - Offline detection: DetectCrDeviceOfflineJob monitors this table
 *
 * For canonical device identity, use the canonicalDevice() relationship
 * which follows the canonical_device_id bridge FK to the devices table.
 * That FK is intentionally retained in PR26 because Control Room device pages,
 * map views, and alert/signal enrichment still depend on it.
 *
 * Do NOT use this model for device inventory, assignment, or ownership queries.
 */
class Device extends Model
{
    use SoftDeletes;

    protected $table = 'control_room_devices';

    protected $fillable = [
        'name',
        'device_uid',
        'identifier',
        'type',
        'device_type',
        'vendor',
        'model',
        'site_id',
        'location_description',
        'latitude',
        'longitude',
        'client_id',
        'asset_id',
        'signal_source_id',
        'external_ref',
        'config',
        'status',
        'last_seen_at',
        'last_signal_at',
        'battery_level',
        'battery_updated_at',
        'low_battery_alert_sent',
        // Bridge FK to the canonical Security & Devices registry (PR3).
        // Mass-assigned by the migration command and consumed by the
        // canonicalDevice() relationship plus the CR device controller's
        // canonical-enrichment payload.
        'canonical_device_id',
    ];

    protected $casts = [
        'config' => 'array',
        'last_seen_at' => 'datetime',
        'last_signal_at' => 'datetime',
        'battery_updated_at' => 'datetime',
        'low_battery_alert_sent' => 'boolean',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $device): void {
            if (empty($device->device_uid)) {
                $device->device_uid = 'dev-' . (string) \Illuminate\Support\Str::uuid();
            }

            if (empty($device->type)) {
                $device->type = self::TYPE_SENSOR;
            }
        });
    }

    public function setDeviceTypeAttribute($value): void
    {
        $this->attributes['type'] = $value;
    }

    public function setIdentifierAttribute($value): void
    {
        $this->attributes['device_uid'] = $value;
    }

    public function signalSource(): BelongsTo
    {
        return $this->belongsTo(SignalSource::class, 'signal_source_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class, 'device_id');
    }

    /**
     * Link to the canonical Security & Devices device record.
     * This is the bridge relationship — canonical_device_id was added in
     * the PR3 bridge migration and populated by sd:migrate-devices.
     */
    public function canonicalDevice(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\SecurityDevices\Models\Device::class, 'canonical_device_id');
    }

    public function scopeOnline($query)
    {
        return $query->where('status', 'online');
    }

    public function scopeOffline($query)
    {
        return $query->where('status', 'offline');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeBySite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    public function scopeStale($query, int $minutes = 30)
    {
        return $query->where('status', 'online')
            ->where(function ($q) use ($minutes) {
                $q->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subMinutes($minutes));
            });
    }

    public function scopeLowBattery($query, int $threshold = 20)
    {
        return $query->whereNotNull('battery_level')
            ->where('battery_level', '<=', $threshold);
    }

    public function markOnline(): void
    {
        $this->update([
            'status' => 'online',
            'last_seen_at' => now(),
        ]);
    }

    public function markOffline(): void
    {
        $this->update(['status' => 'offline']);
    }

    public function updateBattery(int $level): void
    {
        $this->update([
            'battery_level' => $level,
            'battery_updated_at' => now(),
        ]);
    }

    public function recordSignal(): void
    {
        $this->update([
            'last_signal_at' => now(),
            'last_seen_at' => now(),
            'status' => 'online',
        ]);
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }

    public function isStale(int $minutes = 30): bool
    {
        if (!$this->last_seen_at) {
            return true;
        }

        return $this->last_seen_at->lt(now()->subMinutes($minutes));
    }

    public function hasLowBattery(int $threshold = 20): bool
    {
        return $this->battery_level !== null && $this->battery_level <= $threshold;
    }

    // Device types
    public const TYPE_CAMERA = 'camera';
    public const TYPE_DOOR = 'door';
    public const TYPE_SENSOR = 'sensor';
    public const TYPE_ALARM_PANEL = 'alarm_panel';
    public const TYPE_BED_SENSOR = 'bed_sensor';
    public const TYPE_PERSONAL_TRACKER = 'personal_tracker';
    public const TYPE_VEHICLE_TRACKER = 'vehicle_tracker';
    public const TYPE_ENVIRONMENTAL = 'environmental';
    public const TYPE_NETWORK = 'network';

    public static function types(): array
    {
        return [
            self::TYPE_CAMERA => 'Camera',
            self::TYPE_DOOR => 'Door/Access Point',
            self::TYPE_SENSOR => 'Sensor',
            self::TYPE_ALARM_PANEL => 'Alarm Panel',
            self::TYPE_BED_SENSOR => 'Bed Sensor',
            self::TYPE_PERSONAL_TRACKER => 'Personal Tracker',
            self::TYPE_VEHICLE_TRACKER => 'Vehicle Tracker',
            self::TYPE_ENVIRONMENTAL => 'Environmental Sensor',
            self::TYPE_NETWORK => 'Network Device',
        ];
    }
}
