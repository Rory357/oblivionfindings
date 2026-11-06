import type { Coordinate, Geometry } from './types';

// Plain maths, so wizards can call these while rendering on the server, where
// Leaflet can't load. They match Leaflet's LatLngBounds.getCenter() and its
// EPSG:3857 (spherical Mercator) projection.
const EARTH_RADIUS = 6378137;
const MAX_LATITUDE = 85.0511287798;

function project({ lat, lng }: Coordinate) {
    const d = Math.PI / 180;
    const sin = Math.sin(
        Math.max(Math.min(MAX_LATITUDE, lat), -MAX_LATITUDE) * d,
    );
    return {
        x: EARTH_RADIUS * lng * d,
        y: (EARTH_RADIUS * Math.log((1 + sin) / (1 - sin))) / 2,
    };
}

function unproject({ x, y }: { x: number; y: number }): Coordinate {
    const d = 180 / Math.PI;
    return {
        lat: (2 * Math.atan(Math.exp(y / EARTH_RADIUS)) - Math.PI / 2) * d,
        lng: (x * d) / EARTH_RADIUS,
    };
}

export function boundaryCentre(shape: Geometry): Coordinate {
    if (shape.type === 'circle') return shape.center;
    if (!shape.coordinates.length)
        throw new RangeError('A boundary needs at least one point.');
    const lats = shape.coordinates.map((p) => p.lat);
    const lngs = shape.coordinates.map((p) => p.lng);
    return {
        lat: (Math.min(...lats) + Math.max(...lats)) / 2,
        lng: (Math.min(...lngs) + Math.max(...lngs)) / 2,
    };
}

export function moveBoundary(
    shape: Geometry,
    from: Coordinate,
    to: Coordinate,
): Geometry {
    const start = project(from),
        end = project(to);
    const dx = end.x - start.x,
        dy = end.y - start.y;
    const move = (point: Coordinate): Coordinate => {
        const projected = project(point);
        const next = unproject({ x: projected.x + dx, y: projected.y + dy });
        return { lat: +next.lat.toFixed(6), lng: +next.lng.toFixed(6) };
    };
    return shape.type === 'circle'
        ? { ...shape, center: move(shape.center) }
        : { ...shape, coordinates: shape.coordinates.map(move) };
}
