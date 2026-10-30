import type { Coordinate, Geometry } from '@/components/client-location/types';
import type { Boundary, Rule } from './data';
import type { MapResource } from './map-data';
export type PositionKey =
    | 'inside'
    | 'outside'
    | 'uncertain'
    | 'stale'
    | 'missing'
    | 'unlinked'
    | 'unavailable';
export type Relation = {
    boundary: Boundary;
    state: 'inside' | 'outside' | 'uncertain';
};
export type PositionSummary = {
    key: PositionKey;
    label: string;
    detail: string;
    relations: Relation[];
};
export function metres(a: Coordinate, b: Coordinate) {
    const p = Math.PI / 180,
        dLat = (b.lat - a.lat) * p,
        dLng = (b.lng - a.lng) * p;
    const h =
        Math.sin(dLat / 2) ** 2 +
        Math.cos(a.lat * p) * Math.cos(b.lat * p) * Math.sin(dLng / 2) ** 2;
    return (
        6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(Math.max(0, 1 - h)))
    );
}
function polygonDistance(point: Coordinate, points: Coordinate[]) {
    let inside = false,
        min = Infinity;
    const xy = (p: Coordinate) => ({
        x: (p.lng - point.lng) * 111320 * Math.cos((point.lat * Math.PI) / 180),
        y: (p.lat - point.lat) * 111320,
    });
    for (let i = 0, j = points.length - 1; i < points.length; j = i++) {
        const a = points[i],
            b = points[j];
        if (
            a.lat > point.lat !== b.lat > point.lat &&
            point.lng <
                ((b.lng - a.lng) * (point.lat - a.lat)) / (b.lat - a.lat) +
                    a.lng
        )
            inside = !inside;
        const av = xy(a),
            bv = xy(b),
            dx = bv.x - av.x,
            dy = bv.y - av.y,
            l = dx * dx + dy * dy;
        const t = l
            ? Math.max(0, Math.min(1, -(av.x * dx + av.y * dy) / l))
            : 0;
        min = Math.min(min, Math.hypot(av.x + t * dx, av.y + t * dy));
    }
    return { inside, distance: min };
}
export function relation(
    point: Coordinate,
    accuracy: number | null,
    shape: Geometry,
): Relation['state'] {
    if (accuracy === null || !Number.isFinite(accuracy) || accuracy < 0)
        return 'uncertain';
    const r =
        shape.type === 'circle'
            ? {
                  inside: metres(point, shape.center) < shape.radius_m,
                  distance: Math.abs(
                      metres(point, shape.center) - shape.radius_m,
                  ),
              }
            : polygonDistance(point, shape.coordinates);
    return r.distance <= accuracy
        ? 'uncertain'
        : r.inside
          ? 'inside'
          : 'outside';
}
export function matchesResource(rule: Rule, resource: MapResource) {
    return rule.resourceId
        ? rule.resourceId === resource.id
        : rule.resource === `${resource.name} · ${resource.tag}`;
}
export function linkedIds(resource: MapResource, rules: Rule[]) {
    return [
        ...new Set([
            ...resource.boundaries,
            ...rules
                .filter((r) => matchesResource(r, resource))
                .map((r) => r.boundary),
        ]),
    ];
}
export function resourceStatus(
    resource: MapResource,
    boundaries: Boundary[],
    rules: Rule[] = [],
): PositionSummary {
    if (!resource.position)
        return {
            key: 'missing',
            label: 'No location',
            detail: resource.unavailable ?? 'No usable recorded position.',
            relations: [],
        };
    const ids = linkedIds(resource, rules),
        linked = ids.map((id) =>
            boundaries.find((b) => b.id === id && b.status !== 'Retired'),
        );
    const relations = linked
        .filter((b): b is Boundary => !!b)
        .map((b) => ({
            boundary: b,
            state: relation(resource.position!, resource.accuracy, b.geometry),
        }));
    if (!resource.fresh)
        return {
            key: 'stale',
            label: 'Location out of date',
            detail: 'Last-known position only. Current geofence status is unknown.',
            relations,
        };
    if (!ids.length)
        return {
            key: 'unlinked',
            label: 'No linked boundaries',
            detail: 'A recorded position is available; no boundary is assigned to this record.',
            relations,
        };
    if (linked.some((b) => !b))
        return {
            key: 'unavailable',
            label: 'Boundary unavailable',
            detail: 'An assigned area is retired or unavailable. Review the assignment in its owning profile.',
            relations,
        };
    if (relations.some((r) => r.state === 'uncertain'))
        return {
            key: 'uncertain',
            label: 'Position uncertain',
            detail:
                resource.accuracy === null
                    ? 'Accuracy was not supplied. Containment cannot be confirmed.'
                    : 'The reported accuracy reaches a boundary edge. A crossing is not confirmed.',
            relations,
        };
    const inside = relations.filter((r) => r.state === 'inside').length;
    return inside
        ? {
              key: 'inside',
              label: `Inside ${inside} linked ${inside === 1 ? 'area' : 'areas'}`,
              detail:
                  inside < relations.length
                      ? `Outside ${relations.length - inside} other linked areas. Each rule is assessed independently.`
                      : 'Based on the latest usable recorded position.',
              relations,
          }
        : {
              key: 'outside',
              label: `Outside ${relations.length} linked ${relations.length === 1 ? 'area' : 'areas'}`,
              detail: 'Outside is a position relationship. It does not by itself establish a breach or an emergency.',
              relations,
          };
}
export function clusterPoints<T extends { position: Coordinate | null }>(
    records: T[],
    project: (p: Coordinate) => { x: number; y: number },
    size = 52,
) {
    const groups = new Map<string, T[]>();
    for (const r of records) {
        if (!r.position) continue;
        const p = project(r.position),
            key = `${Math.floor(p.x / size)}:${Math.floor(p.y / size)}`;
        groups.set(key, [...(groups.get(key) ?? []), r]);
    }
    return [...groups.values()];
}
