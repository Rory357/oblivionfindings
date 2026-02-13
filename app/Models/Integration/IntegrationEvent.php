<?php

namespace App\Models\Integration;

use App\Models\Concerns\AuditableChanges;
use App\Models\ControlRoom\Alert;
use App\Models\LocationHardware;
use App\Models\Site;
use App\Models\SiteRoom;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class IntegrationEvent extends Model
{
    use HasFactory;
    use AuditableChanges;

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARN = 'warn';
    public const SEVERITY_CRITICAL = 'critical';

    protected $table = 'integration_events';

    protected $fillable = [
        'tenant_id',
        'site_id',
        'room_id',
        'hardware_id',
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

    public function alert(): HasOne
    {
        return $this->hasOne(Alert::class, 'integration_event_id');
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

    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    public function scopeForSite($query, int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    public function scopeSince($query, $datetime)
    {
        return $query->where('occurred_at', '>=', $datetime);
    }
}
