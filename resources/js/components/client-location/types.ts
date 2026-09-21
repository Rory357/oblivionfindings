export type Coordinate = { lat: number; lng: number };
export type Geometry =
    | { type: 'circle'; center: Coordinate; radius_m: number }
    | { type: 'polygon'; coordinates: Coordinate[] };
export type ZoneSchedule = {
    timezone: 'Pacific/Auckland';
    weekdays: number[];
    start: string;
    end: string;
    following_day: boolean;
    first_date: string;
    last_date: string;
    exception_dates: string[];
};
export type ZoneDraft = {
    id: number;
    revision: number;
    status: 'draft';
    name: string;
    purpose: string;
    classification: 'agreed' | 'attention';
    geometry_source: 'custom' | 'canonical';
    geometry: Geometry;
    canonical_geofence_id?: number | null;
    canonical_geometry_hash?: string | null;
    schedule: ZoneSchedule;
    response_proposal: string | null;
    saved_at: string;
    monitoring?: {
        id: number;
        status: 'active' | 'paused';
        authority_current?: boolean;
        started_at: string;
        ended_at: string | null;
        last_observed_at: string | null;
        position_status: string;
        breach_count: number;
        in_schedule: boolean;
        destination: 'Control Room';
    } | null;
};
export type Boundary = {
    id: number;
    name: string;
    geometry: Geometry;
    hash: string;
};

export function zoneMonitoringLabel(zone: ZoneDraft): string {
    if (!zone.monitoring) return 'Draft · not monitoring';
    if (zone.monitoring.status === 'paused') return 'Paused · not monitoring';
    if (zone.monitoring.authority_current === false)
        return 'Review required · alerts stopped';
    return zone.monitoring.in_schedule
        ? 'Monitoring · Control Room'
        : 'Monitoring · outside scheduled hours';
}
export type ZoneEnvelope = {
    zones: ZoneDraft[];
    boundaries: Boundary[];
    access_fingerprint: string;
    checked_at: string;
};
export type HistoryPoint = Coordinate & {
    timestamp: string;
    address?: string | null;
    address_source?: 'recorded' | 'nearest' | null;
    speed?: number | null;
    battery?: number | null;
    accuracy?: number | null;
    display_location?: string | null;
    event_type?: string | null;
};

export function geometryError(shape: Geometry | null): string | null {
    if (!shape) return 'Draw a boundary on the map first.';
    const points = shape.type === 'circle' ? [shape.center] : shape.coordinates;
    if (
        points.some(
            ({ lat, lng }) =>
                !Number.isFinite(lat) ||
                !Number.isFinite(lng) ||
                Math.abs(lat) > 90 ||
                Math.abs(lng) > 180,
        )
    )
        return 'Enter valid latitude and longitude coordinates.';
    if (shape.type === 'circle')
        return Number.isFinite(shape.radius_m) && shape.radius_m > 0
            ? null
            : 'Set a radius greater than zero.';
    if (points.length < 3) return 'Add at least three corners.';
    const cross = (a: Coordinate, b: Coordinate, c: Coordinate) =>
        (b.lng - a.lng) * (c.lat - a.lat) - (b.lat - a.lat) * (c.lng - a.lng);
    const on = (a: Coordinate, b: Coordinate, p: Coordinate) =>
        Math.abs(cross(a, b, p)) < 1e-12 &&
        p.lat >= Math.min(a.lat, b.lat) &&
        p.lat <= Math.max(a.lat, b.lat) &&
        p.lng >= Math.min(a.lng, b.lng) &&
        p.lng <= Math.max(a.lng, b.lng);
    let area = 0;
    for (let i = 0; i < points.length; i++) {
        const a = points[i],
            b = points[(i + 1) % points.length];
        if (
            (a.lat === b.lat && a.lng === b.lng) ||
            Math.abs(a.lng - b.lng) > 180
        )
            return 'Use distinct corners without crossing the date line.';
        area += cross(points[0], a, b);
        for (let j = i + 2; j < points.length; j++) {
            if (i === 0 && j === points.length - 1) continue;
            const c = points[j],
                d = points[(j + 1) % points.length];
            if (
                (cross(a, b, c) * cross(a, b, d) < 0 &&
                    cross(c, d, a) * cross(c, d, b) < 0) ||
                on(a, b, c) ||
                on(a, b, d) ||
                on(c, d, a) ||
                on(c, d, b)
            )
                return 'The boundary crosses or touches itself. Move a corner.';
        }
    }
    return Math.abs(area) < 1e-12
        ? 'Spread the corners out to enclose an area.'
        : null;
}

export async function readJson<T>(response: Response): Promise<T> {
    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        const messages = Object.values(data.errors ?? {})
            .flat()
            .join(' ');
        throw new Error(
            messages ||
                (response.status === 409
                    ? 'This draft or boundary changed. Reload the zones and review your changes.'
                    : data.message ||
                      'The request failed. Your changes are still here; try again.'),
        );
    }
    return response.json() as Promise<T>;
}
export const privateHeaders = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
};
