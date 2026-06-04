<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single external busy block pulled from a house's mapped resource calendar for a
 * two-way mapping. Read-only on our side — surfaced as the calendar's "external"
 * source and (optionally) counted as a clash in the create dialog.
 *
 * @see \App\Services\Sites\Calendar\CalendarSyncService::pullBusy()
 */
class CalendarSyncBusyBlock extends Model
{
    protected $fillable = [
        'tenant_id',
        'site_id',
        'provider',
        'external_event_id',
        'title',
        'starts_at',
        'ends_at',
        'all_day',
        'is_busy',
        'synced_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'all_day' => 'boolean',
        'is_busy' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
