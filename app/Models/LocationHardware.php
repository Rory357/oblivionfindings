<?php

namespace App\Models;

use App\Models\Concerns\AuditableChanges;
use App\Models\ControlRoomAlert;
use App\Models\Integration\IntegrationEvent;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @deprecated This model is retired as an active source of truth.
 * The canonical hardware registry is now App\Domain\SecurityDevices\Models\Device.
 *
 * Remaining valid uses:
 * - Bridge FK lookups via device_id for consumers not yet fully migrated
 * - integration_events.hardware_id references for legacy location history
 * - UniFi sync adapter writes (will be migrated to write to devices table)
 *
 * Do NOT add new queries against this model. Use Device + DeviceAssignment instead.
 * This model and its table will be archived in a future cleanup PR.
 */
class LocationHardware extends Model
{
    use HasFactory;
    use AuditableChanges;
    use SoftDeletes;

    public const STATUS_ONLINE = 'online';
    public const STATUS_OFFLINE = 'offline';
    public const STATUS_UNKNOWN = 'unknown';
    public const STATUS_RETIRED = 'retired';

    public const CATEGORY_GATEWAY = 'gateway';
    public const CATEGORY_SWITCH = 'switch';
    public const CATEGORY_AP = 'ap';
    public const CATEGORY_CAMERA = 'camera';
    public const CATEGORY_DOOR = 'door';
    public const CATEGORY_SENSOR = 'sensor';
    public const CATEGORY_NVR = 'nvr';
    public const CATEGORY_AI = 'ai';
    public const CATEGORY_TRACKER = 'tracker';
    public const CATEGORY_OTHER = 'other';

    protected $table = 'location_hardware';

    protected $fillable = [
        'tenant_id',
        'site_id',
        'room_id',
        'provider',
        'category',
        'name',
        'asset_tag',
        'serial',
        'mac',
        'status',
        'last_seen_at',
        'external_ref',
        'linked_asset_id',
        'linked_person_type',
        'linked_person_id',
        'notes',
        'meta',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'external_ref' => 'array',
        'meta' => 'array',
    ];

    /* ---------------------------------------------------------------
     * Static Helpers
     * ------------------------------------------------------------- */

    /**
     * Return a mapping of category constants to human-readable labels.
     */
    public static function categories(): array
    {
        return [
            self::CATEGORY_GATEWAY => 'Gateway',
            self::CATEGORY_SWITCH => 'Switch',
            self::CATEGORY_AP => 'Access Point',
            self::CATEGORY_CAMERA => 'Camera',
            self::CATEGORY_DOOR => 'Door Controller',
            self::CATEGORY_SENSOR => 'Sensor',
            self::CATEGORY_NVR => 'NVR',
            self::CATEGORY_AI => 'AI Device',
            self::CATEGORY_TRACKER => 'Tracker',
            self::CATEGORY_OTHER => 'Other',
        ];
    }

    /* ---------------------------------------------------------------
     * Relationships
     * ------------------------------------------------------------- */

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(SiteRoom::class, 'room_id');
    }

    public function linkedAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'linked_asset_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(IntegrationEvent::class, 'hardware_id');
    }

    /**
     * Get canonical Control Room alerts for this hardware device.
     *
     * Integration events now create ControlRoomAlert records via the signal pipeline.
     * The device_id field on ControlRoomAlert stores the hardware_id.
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(ControlRoomAlert::class, 'device_id');
    }

    /* ---------------------------------------------------------------
     * Scopes
     * ------------------------------------------------------------- */

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query;
        }

        return $query->where('tenant_id', $tenantId);
    }

    public function scopeOnline($query)
    {
        return $query->where('status', self::STATUS_ONLINE);
    }

    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeForProvider($query, string $provider)
    {
        return $query->where('provider', $provider);
    }

    /* ---------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------- */

    public function isOnline(): bool
    {
        return $this->status === self::STATUS_ONLINE;
    }

    /**
     * Resolve the linked person entity based on the polymorphic type.
     */
    public function linkedPerson(): ?Model
    {
        return match ($this->linked_person_type) {
            'staff' => User::find($this->linked_person_id),
            'client' => Client::find($this->linked_person_id),
            default => null,
        };
    }
}
