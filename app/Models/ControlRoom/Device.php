<?php

namespace App\Models\ControlRoom;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Device extends Model
{
    use SoftDeletes;

    protected $table = 'control_room_devices';

    protected $fillable = [
        'name',
        'device_uid',
        'type',
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

    public function signalSource(): BelongsTo
    {
        return $this->belongsTo(SignalSource::class, 'signal_source_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class, 'device_id');
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
