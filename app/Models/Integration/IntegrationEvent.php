<?php

namespace App\Models\Integration;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\Concerns\AuditableChanges;
use App\Models\Concerns\WritesLegacyStorageContext;
use App\Models\ControlRoom\Alert;
use App\Models\ControlRoomAlert;
use App\Models\LocationHardware;
use App\Models\Site;
use App\Models\SiteRoom;
use Database\Factories\IntegrationEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IntegrationEvent extends Model
{
    use AuditableChanges;
    use HasFactory;
    use WritesLegacyStorageContext;

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARN = 'warn';

    public const SEVERITY_CRITICAL = 'critical';

    protected $table = 'integration_events';

    protected $fillable = [
        'site_id',
        'room_id',
        'hardware_id',
        'canonical_device_id',
        'provider',
        'source_app',
        'source_event_id',
        'occurred_at',
        'received_at',
        'severity',
        'event_type',
        'tags',
        'normalized_payload',
        'raw_payload',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'tags' => 'array',
        'normalized_payload' => 'array',
        'raw_payload' => 'array',
    ];

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

    public function hardware(): BelongsTo
    {
        return $this->belongsTo(LocationHardware::class, 'hardware_id');
    }

    /**
     * Canonical device identity for user-facing history and future reads.
     * hardware_id remains as legacy provenance / fallback only.
     */
    public function canonicalDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'canonical_device_id');
    }

    /**
     * Get the canonical Control Room alert created from this integration event.
     *
     * Integration events now flow through the signal pipeline.
     * The resulting ControlRoomAlert stores the integration_event_id
     * in its context->normalized_data JSON.
     *
     * Note: This uses a query scope instead of a true hasOne relationship
     * since the FK is inside a JSON column. If performance is a concern,
     * consider caching or adding a direct FK column in a future migration.
     */
    public function controlRoomAlert(): ?ControlRoomAlert
    {
        return ControlRoomAlert::where('source', 'like', 'integration_%')
            ->whereJsonContains('context->normalized_data->integration_event_id', $this->id)
            ->first();
    }

    /**
     * @deprecated Use controlRoomAlert() instead. Retained for backward compatibility.
     */
    public function alert(): HasOne
    {
        // Return a hasOne on the deprecated integration_alerts table.
        // This will return null for new events routed through the signal pipeline.
        // Use controlRoomAlert() for the canonical alert.
        return $this->hasOne(Alert::class, 'integration_event_id');
    }

    /* ---------------------------------------------------------------
     * Scopes
     * ------------------------------------------------------------- */

    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    public function scopeForCanonicalDevice($query, int $deviceId)
    {
        return $query->where('canonical_device_id', $deviceId);
    }

    public function scopeSince($query, $datetime)
    {
        return $query->where('occurred_at', '>=', $datetime);
    }

    /**
     * Resolve the factory for this model.
     *
     * Required because the model namespace (App\Models\Integration) doesn't
     * match the default factory namespace convention.
     */
    protected static function newFactory(): IntegrationEventFactory
    {
        return IntegrationEventFactory::new();
    }
}
