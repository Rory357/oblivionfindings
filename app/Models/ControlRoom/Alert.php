<?php

namespace App\Models\ControlRoom;

use App\Models\Concerns\AuditableChanges;
use App\Models\ControlRoomAlert;
use App\Models\Integration\IntegrationEvent;
use App\Models\LocationHardware;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use App\Services\Integration\AlertRoutingService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @deprecated PR1: This model is deprecated. Integration events now flow through
 *             the canonical signal pipeline to create ControlRoomAlert records.
 *
 *             This model is retained ONLY for:
 *             - reading historical integration_alerts data
 *             - backward compatibility with IntegrationEvent::alert() relationship
 *
 *             NO NEW WRITES should target this model. The AlertRoutingService now
 *             emits signals via SignalProcessingService instead.
 *
 *             Scheduled for removal in PR16 after a bake period.
 * @see ControlRoomAlert          — canonical alert model
 * @see AlertRoutingService — now emits signals
 */
class Alert extends Model
{
    use AuditableChanges;
    use HasFactory;

    public const STATUS_NEW = 'new';

    public const STATUS_ACK = 'ack';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_CLOSED = 'closed';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARN = 'warn';

    public const SEVERITY_CRITICAL = 'critical';

    protected $table = 'integration_alerts';

    protected $fillable = [
        'site_id',
        'room_id',
        'hardware_id',
        'integration_event_id',
        'title',
        'description',
        'severity',
        'status',
        'assigned_to_user_id',
        'acknowledged_at',
        'acknowledged_by_user_id',
        'closed_at',
        'closed_by_user_id',
        'close_reason',
        'incident_id',
        'provider',
        'source_event_id',
        'tags',
        'meta',
    ];

    protected $casts = [
        'acknowledged_at' => 'datetime',
        'closed_at' => 'datetime',
        'tags' => 'array',
        'meta' => 'array',
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

    public function integrationEvent(): BelongsTo
    {
        return $this->belongsTo(IntegrationEvent::class, 'integration_event_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /* ---------------------------------------------------------------
     * Scopes
     * ------------------------------------------------------------- */

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::STATUS_NEW, self::STATUS_ACK, self::STATUS_ASSIGNED]);
    }

    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    public function scopeBySeverity($query, string $severity)
    {
        return $query->where('severity', $severity);
    }

    /* ---------------------------------------------------------------
     * Helper Methods
     * ------------------------------------------------------------- */

    /** @deprecated Use ControlRoomAlert lifecycle instead */
    public function acknowledge(int $userId): void
    {
        $this->status = self::STATUS_ACK;
        $this->acknowledged_at = now();
        $this->acknowledged_by_user_id = $userId;
        $this->save();
    }

    /** @deprecated Use ControlRoomAlert lifecycle instead */
    public function close(int $userId, ?string $reason = null): void
    {
        $this->status = self::STATUS_CLOSED;
        $this->closed_at = now();
        $this->closed_by_user_id = $userId;
        $this->close_reason = $reason;
        $this->save();
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_CLOSED;
    }
}
