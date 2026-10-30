import {
    geometryError,
    type Coordinate,
    type Geometry,
} from '@/components/client-location/types';
import { metres } from './map-model';
export function rectangleAt(c: Coordinate): Geometry {
    const dy = 100 / 111195,
        dx = 150 / (111195 * Math.max(0.1, Math.cos((c.lat * Math.PI) / 180)));
    return {
        type: 'polygon',
        coordinates: [
            { lat: c.lat + dy, lng: c.lng - dx },
            { lat: c.lat + dy, lng: c.lng + dx },
            { lat: c.lat - dy, lng: c.lng + dx },
            { lat: c.lat - dy, lng: c.lng - dx },
        ],
    };
}
export function geometryMeasures(shape: Geometry | null) {
    if (!shape || geometryError(shape)) return null;
    if (shape.type === 'circle')
        return {
            area: Math.PI * shape.radius_m ** 2,
            perimeter: 2 * Math.PI * shape.radius_m,
        };
    const origin = shape.coordinates[0],
        cos = Math.cos((origin.lat * Math.PI) / 180);
    const points = shape.coordinates.map((p) => ({
        x: (p.lng - origin.lng) * 111195 * cos,
        y: (p.lat - origin.lat) * 111195,
    }));
    let twice = 0,
        perimeter = 0;
    points.forEach((p, i) => {
        const j = (i + 1) % points.length;
        twice += p.x * points[j].y - points[j].x * p.y;
        perimeter += metres(shape.coordinates[i], shape.coordinates[j]);
    });
    return { area: Math.abs(twice) / 2, perimeter };
}
export function encodeGeometry(shape: Geometry): string {
    const geometry =
        shape.type === 'circle'
            ? {
                  type: 'Point',
                  coordinates: [shape.center.lng, shape.center.lat],
              }
            : {
                  type: 'Polygon',
                  coordinates: [
                      [...shape.coordinates, shape.coordinates[0]].map((p) => [
                          p.lng,
                          p.lat,
                      ]),
                  ],
              };
    return JSON.stringify(
        {
            type: 'Feature',
            properties:
                shape.type === 'circle' ? { radius_m: shape.radius_m } : {},
            geometry,
        },
        null,
        2,
    );
}
export function decodeGeometry(text: string): {
    shape?: Geometry;
    error?: string;
} {
    try {
        if (text.length > 100000)
            return { error: 'Use a single boundary with at most 200 corners.' };
        const item = JSON.parse(text),
            g = item?.type === 'Feature' ? item.geometry : item;
        const valid = (p: unknown) =>
            Array.isArray(p) &&
            p.length === 2 &&
            p.every((n) => typeof n === 'number' && Number.isFinite(n));
        let shape: Geometry;
        if (g?.type === 'Point') {
            if (
                !valid(g.coordinates) ||
                typeof item.properties?.radius_m !== 'number'
            )
                return {
                    error: 'A circle needs a Point with [longitude, latitude] and a numeric properties.radius_m.',
                };
            shape = {
                type: 'circle',
                center: { lng: g.coordinates[0], lat: g.coordinates[1] },
                radius_m: item.properties.radius_m,
            };
            if (shape.radius_m > 50000)
                return { error: 'Use a radius of 50,000 metres or less.' };
        } else if (g?.type === 'Polygon') {
            if (!Array.isArray(g.coordinates) || g.coordinates.length !== 1)
                return {
                    error: 'Use one polygon outline. Holes and multiple areas require separate boundaries.',
                };
            const ring = g.coordinates[0];
            if (
                !Array.isArray(ring) ||
                ring.length < 4 ||
                ring.length > 201 ||
                !ring.every(valid)
            )
                return {
                    error: 'Use a closed ring of 3–200 corners with [longitude, latitude] coordinates.',
                };
            if (ring[0][0] !== ring.at(-1)[0] || ring[0][1] !== ring.at(-1)[1])
                return {
                    error: 'Close the polygon ring by repeating its first coordinate at the end.',
                };
            shape = {
                type: 'polygon',
                coordinates: ring
                    .slice(0, -1)
                    .map((p: number[]) => ({ lng: p[0], lat: p[1] })),
            };
        } else
            return {
                error: 'Paste one GeoJSON Polygon or a circle Feature with a Point and radius_m. Collections and paths are not supported.',
            };
        const error = geometryError(shape);
        return error ? { error } : { shape };
    } catch {
        return {
            error: 'This is not valid GeoJSON. Check the JSON format and coordinate order.',
        };
    }
}
