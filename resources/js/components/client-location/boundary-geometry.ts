import L from 'leaflet';
import type { Coordinate, Geometry } from './types';

export function boundaryCentre(shape: Geometry): Coordinate {
    if (shape.type === 'circle') return shape.center;
    const center = L.latLngBounds(
        shape.coordinates.map((p) => [p.lat, p.lng]),
    ).getCenter();
    return { lat: center.lat, lng: center.lng };
}

export function moveBoundary(
    shape: Geometry,
    from: Coordinate,
    to: Coordinate,
): Geometry {
    const projection = L.CRS.EPSG3857;
    const delta = projection
        .project(L.latLng(to))
        .subtract(projection.project(L.latLng(from)));
    const move = (point: Coordinate): Coordinate => {
        const next = projection.unproject(
            projection.project(L.latLng(point)).add(delta),
        );
        return { lat: +next.lat.toFixed(6), lng: +next.lng.toFixed(6) };
    };
    return shape.type === 'circle'
        ? { ...shape, center: move(shape.center) }
        : { ...shape, coordinates: shape.coordinates.map(move) };
}
