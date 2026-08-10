<?php

namespace App\Models;

use App\Models\Concerns\WritesLegacyStorageContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Application-wide, per-priority SLA targets for the helpdesk. A configured
 * row overrides; otherwise the §G defaults below apply — every ticket gets due
 * dates either way. v1 clocks run 24/7 (business-hours calendars are a
 * recorded stretch question).
 */
class ItSlaPolicy extends Model
{
    use WritesLegacyStorageContext;

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
        'priority',
        'first_response_minutes',
        'resolution_minutes',
        'business_hours',
        'holiday_dates',
    ];

    protected $casts = [
        'first_response_minutes' => 'integer',
        'resolution_minutes' => 'integer',
        'business_hours' => 'array',
        'holiday_dates' => 'array',
    ];

    /**
     * Effective [first_response_minutes, resolution_minutes] for a priority.
     *
     * @return array{0: int, 1: int}
     */
    public static function minutesFor(string $priority): array
    {
        $row = static::query()
            ->where('priority', $priority)
            ->first();

        if ($row) {
            return [(int) $row->first_response_minutes, (int) $row->resolution_minutes];
        }

        return self::DEFAULTS[$priority] ?? self::DEFAULTS['normal'];
    }

    /**
     * The business-hours calendar for a priority, in the shape
     * App\Support\It\BusinessHours consumes — or null when the application hasn't
     * set one. Null means the 24/7 clock, so the v1 behaviour is preserved.
     * Read alongside minutesFor() when stamping (wired in S2).
     *
     * @return array{business_hours: array<string, mixed>, holiday_dates: array<int, string>}|null
     */
    public static function calendarFor(string $priority): ?array
    {
        $row = static::query()
            ->where('priority', $priority)
            ->first();

        if (! $row || empty($row->business_hours)) {
            return null;
        }

        return [
            'business_hours' => $row->business_hours,
            'holiday_dates' => $row->holiday_dates ?? [],
        ];
    }
}
