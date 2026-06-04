<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Idempotency link between a local SiteCalendarEvent (optionally a specific
 * occurrence of a recurring series) and the external event it created in a
 * resource calendar.
 */
class CalendarSyncEventLink extends Model
{
    protected $fillable = [
        'tenant_id',
        'site_id',
        'provider',
        'site_calendar_event_id',
        'occurrence_key',
        'external_event_id',
        'last_pushed_at',
    ];

    protected $casts = [
        'last_pushed_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(SiteCalendarEvent::class, 'site_calendar_event_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
