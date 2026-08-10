<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps a single house/site to the external resource calendar it syncs with, for one
 * provider, plus the sync direction, the event sources to push, and a secret
 * per-house iCal feed token.
 */
class CalendarSyncMapping extends Model
{
    use WritesLegacyStorageContext;

    public const DIRECTION_ONE_WAY = 'one_way';   // push obligations out only

    public const DIRECTION_TWO_WAY = 'two_way';   // push out + pull external busy back

    protected $fillable = [
        'tenant_id',
        'site_id',
        'provider',
        'external_calendar_id',
        'external_calendar_name',
        'sync_direction',
        'sources',
        'ical_feed_token',
        'is_active',
        'last_synced_at',
        'last_error',
    ];

    protected $casts = [
        'sources' => 'array',
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    /**
     * Is this mapping live enough to sync? (active, has a chosen external calendar.)
     */
    public function isSyncable(): bool
    {
        return $this->is_active
            && $this->external_calendar_id !== null
            && $this->external_calendar_id !== '';
    }

    public function pullsExternalBusy(): bool
    {
        return $this->sync_direction === self::DIRECTION_TWO_WAY;
    }
}
