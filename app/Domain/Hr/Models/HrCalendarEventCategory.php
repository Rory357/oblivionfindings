<?php

namespace App\Domain\Hr\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;

/**
 * An application-wide selectable category for HR calendar events. `key`
 * mirrors the legacy `hr_calendar_events.event_type` string; `color_token` /
 * `icon` are design-token / lucide names the wizard resolves client-side.
 */
class HrCalendarEventCategory extends Model
{
    use WritesLegacyStorageContext;

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
}
