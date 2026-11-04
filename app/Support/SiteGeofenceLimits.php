<?php

namespace App\Support;

/**
 * The size limits for a Site's own geofence circle (to-do #9, 4 Nov 2026):
 * 10 m to 2,000 m. The browser copy lives in resources/js/lib/site-geofence.ts;
 * SiteGeofenceLimitsTest fails if the two drift apart.
 */
final class SiteGeofenceLimits
{
    public const MIN_RADIUS_M = 10;

    public const MAX_RADIUS_M = 2000;

    /** Starting size for a new Site geofence. */
    public const DEFAULT_RADIUS_M = 120;

    /** @return array<int, string> */
    public static function radiusRules(): array
    {
        return ['nullable', 'integer', 'min:'.self::MIN_RADIUS_M, 'max:'.self::MAX_RADIUS_M];
    }

    public static function clamp(int $radius): int
    {
        return max(self::MIN_RADIUS_M, min(self::MAX_RADIUS_M, $radius));
    }
}
