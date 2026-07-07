<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant, per-priority SLA targets for the helpdesk. A tenant row
 * overrides; otherwise the §G defaults below apply — every ticket gets due
 * dates either way. v1 clocks run 24/7 (business-hours calendars are a
 * recorded stretch question).
 */
class ItSlaPolicy extends Model
{
    /**
     * §G defaults, minutes: [first_response, resolution].
     *
     * @var array<string, array{0: int, 1: int}>
     */
    public const DEFAULTS = [
        'urgent' => [60, 240],
        'high' => [240, 1440],
        'normal' => [1440, 4320],
        'low' => [4320, 10080],
    ];

    protected $fillable = [
        'tenant_id',
        'priority',
        'first_response_minutes',
        'resolution_minutes',
    ];

    protected $casts = [
        'first_response_minutes' => 'integer',
        'resolution_minutes' => 'integer',
    ];

    /**
     * Effective [first_response_minutes, resolution_minutes] for a tenant +
     * priority — the tenant's row when set, the §G default otherwise.
     *
     * @return array{0: int, 1: int}
     */
    public static function minutesFor(int $tenantId, string $priority): array
    {
        $row = static::query()
            ->where('tenant_id', $tenantId)
            ->where('priority', $priority)
            ->first();

        if ($row) {
            return [(int) $row->first_response_minutes, (int) $row->resolution_minutes];
        }

        return self::DEFAULTS[$priority] ?? self::DEFAULTS['normal'];
    }
}
