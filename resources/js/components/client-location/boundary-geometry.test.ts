import L from 'leaflet';
import { describe, expect, it } from 'vitest';
import { boundaryCentre, moveBoundary } from './boundary-geometry';
import type { Coordinate, Geometry } from './types';

// The helpers used to call Leaflet, which can't load on the server. These pin
// the plain maths to what Leaflet gave, so maps and wizards don't move.
const square: Geometry = {
    type: 'polygon',
    coordinates: [
        { lat: -41.2865, lng: 174.7762 },
        { lat: -41.2871, lng: 174.7791 },
        { lat: -41.2893, lng: 174.7784 },
        { lat: -41.2888, lng: 174.7755 },
    ],
};
const far: Geometry = {
    type: 'polygon',
    coordinates: [
        { lat: 89.5, lng: -179.9 },
        { lat: -89.5, lng: 179.9 },
        { lat: 0, lng: 0 },
    ],
};

function leafletCentre(points: Coordinate[]): Coordinate {
    const c = L.latLngBounds(points.map((p) => [p.lat, p.lng])).getCenter();
    return { lat: c.lat, lng: c.lng };
}

function leafletMove(point: Coordinate, from: Coordinate, to: Coordinate) {
    const projection = L.CRS.EPSG3857;
    const delta = projection
        .project(L.latLng(to))
        .subtract(projection.project(L.latLng(from)));
    const next = projection.unproject(
        projection.project(L.latLng(point)).add(delta),
    );
    return { lat: +next.lat.toFixed(6), lng: +next.lng.toFixed(6) };
}

describe('boundary geometry', () => {
    it('finds the same centre as Leaflet', () => {
        for (const shape of [square, far])
            if (shape.type === 'polygon')
                expect(boundaryCentre(shape)).toEqual(
                    leafletCentre(shape.coordinates),
                );
        expect(
            boundaryCentre({
                type: 'circle',
                center: { lat: -41.3, lng: 174.8 },
                radius_m: 120,
            }),
        ).toEqual({ lat: -41.3, lng: 174.8 });
    });

    it('moves a boundary to the same points as Leaflet', () => {
        const moves: Array<[Coordinate, Coordinate]> = [
            [
                { lat: -41.2865, lng: 174.7762 },
                { lat: -41.2902, lng: 174.7811 },
            ],
            [
                { lat: -36.8485, lng: 174.7633 },
                { lat: -45.8788, lng: 170.5028 },
            ],
            [
                { lat: 0, lng: 0 },
                { lat: 86, lng: -179.5 },
            ],
        ];
        for (const [from, to] of moves) {
            for (const shape of [square, far]) {
                const moved = moveBoundary(shape, from, to);
                if (shape.type === 'polygon' && moved.type === 'polygon')
                    expect(moved.coordinates).toEqual(
                        shape.coordinates.map((p) => leafletMove(p, from, to)),
                    );
            }
            const circle: Geometry = {
                type: 'circle',
                center: { lat: -41.29, lng: 174.776 },
                radius_m: 80,
            };
            expect(moveBoundary(circle, from, to)).toEqual({
                ...circle,
                center: leafletMove(circle.center, from, to),
            });
        }
    });
});
