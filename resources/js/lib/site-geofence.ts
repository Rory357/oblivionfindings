/**
 * Size limits for a Site's own geofence circle (to-do #9, 4 Nov 2026).
 * The server copy is app/Support/SiteGeofenceLimits.php; the
 * SiteGeofenceLimitsTest feature test fails if the two drift apart.
 */
export const SITE_GEOFENCE_MIN_RADIUS_M = 10;
export const SITE_GEOFENCE_MAX_RADIUS_M = 2000;
export const SITE_GEOFENCE_DEFAULT_RADIUS_M = 120;

/** Quick sizes offered beside the radius slider, smallest first. */
export const SITE_GEOFENCE_RADIUS_PRESETS: readonly number[] = [
    10, 25, 50, 100, 250, 500, 1000, 2000,
];

export function clampSiteGeofenceRadius(radius: number): number {
    if (!Number.isFinite(radius)) return SITE_GEOFENCE_DEFAULT_RADIUS_M;
    return Math.min(
        SITE_GEOFENCE_MAX_RADIUS_M,
        Math.max(SITE_GEOFENCE_MIN_RADIUS_M, Math.round(radius)),
    );
}
