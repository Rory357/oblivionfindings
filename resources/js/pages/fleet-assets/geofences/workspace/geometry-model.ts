import type { Geometry } from '@/components/client-location/types';
import { geometryError } from '@/components/client-location/types';
import type { Boundary } from './data';

export const geometryEqual = (
    a: Geometry | null | undefined,
    b: Geometry | null | undefined,
) => JSON.stringify(a) === JSON.stringify(b);
export const nextBoundaryId = (boundaries: Boundary[]) =>
    'BG-' +
    (Math.max(
        100,
        ...boundaries
            .map((b) => Number(b.id.replace('BG-', '')))
            .filter(Number.isFinite),
    ) +
        1);
const envelope = (s: Geometry) => {
    if (s.type === 'circle') {
        const lat = s.radius_m / 111320,
            lng =
                lat / Math.max(0.01, Math.cos((s.center.lat * Math.PI) / 180));
        return [
            s.center.lat - lat,
            s.center.lng - lng,
            s.center.lat + lat,
            s.center.lng + lng,
        ];
    }
    return [
        Math.min(...s.coordinates.map((p) => p.lat)),
        Math.min(...s.coordinates.map((p) => p.lng)),
        Math.max(...s.coordinates.map((p) => p.lat)),
        Math.max(...s.coordinates.map((p) => p.lng)),
    ];
};
export function possibleOverlaps(
    shape: Geometry | null,
    boundaries: Boundary[],
) {
    if (!shape || geometryError(shape)) return [];
    return boundaries.filter((b) => {
        const other = b.geometry;
        if (shape.type === 'circle' && other.type === 'circle') {
            const p = Math.PI / 180,
                lat = (other.center.lat - shape.center.lat) * p,
                lng = (other.center.lng - shape.center.lng) * p;
            const a =
                Math.sin(lat / 2) ** 2 +
                Math.cos(shape.center.lat * p) *
                    Math.cos(other.center.lat * p) *
                    Math.sin(lng / 2) ** 2;
            return (
                6371000 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)) <=
                shape.radius_m + other.radius_m
            );
        }
        const a = envelope(shape),
            c = envelope(other);
        return a[0] <= c[2] && a[2] >= c[0] && a[1] <= c[3] && a[3] >= c[1];
    });
}
