<?php

namespace App\Models\ControlRoom;

use App\Models\Concerns\AuditableChanges;
use App\Models\Integration\IntegrationEvent;
use App\Models\LocationHardware;
use App\Models\Site;
use App\Models\SiteRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    use HasFactory;
    use AuditableChanges;

    public const STATUS_NEW = 'new';
    public const STATUS_ACK = 'ack';
    public const STATUS_ASSIGNED = 'assigned';
    public const STATUS_CLOSED = 'closed';

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARN = 'warn';
    public const SEVERITY_CRITICAL = 'critical';

    protected $table = 'integration_alerts';

    protected $fillable = [
        'tenant_id',
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

    public function scopeForTenant($query, ?int $tenantId)
    {
        if ($tenantId === null) {
            return $query;
        }

        return $query->where('tenant_id', $tenantId);
    }

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

    public function acknowledge(int $userId): void
    {
        $this->status = self::STATUS_ACK;
        $this->acknowledged_at = now();
        $this->acknowledged_by_user_id = $userId;
        $this->save();
    }

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
