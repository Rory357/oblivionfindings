<?php

namespace App\Domain\Hr\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A selectable category for HR calendar events. System rows (tenant_id null) seed
 * the canonical five + holiday; tenants may add their own. `key` mirrors the
 * legacy `hr_calendar_events.event_type` string; `color_token` / `icon` are
 * design-token / lucide names the wizard resolves client-side.
 */
class HrCalendarEventCategory extends Model
{
    protected $table = 'hr_calendar_event_categories';

    protected $fillable = [
        'tenant_id',
        'key',
        'label',
        'icon',
        'color_token',
        'is_system',
        'sort',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'sort' => 'integer',
    ];

    /** System (tenant-null) categories plus the given tenant's own, sorted. */
    public function scopeForTenant(Builder $query, ?int $tenantId): Builder
    {
        return $query
            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->orderBy('sort');
    }
}
