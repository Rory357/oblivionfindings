import type { PlanDoor, PlanWall, PlanWindow } from './_types';

export type CanvasSize = { width: number; height: number };
export type Point = { x: number; y: number };

export type AttachedOpening = {
    id: string;
    wall_id?: string | null;
    wall_segment_index?: number | null;
    wall_t?: number | null;
    width?: number | null;
};

export type ResolvedOpening = {
    attached: boolean;
    x: number;
    y: number;
    rotation_deg: number;
    wall_id?: string | null;
    wall_segment_index?: number | null;
    wall_t?: number | null;
    width: number;
};

export type WallRenderSegment = {
    id: string;
    segmentIndex: number;
    a: Point;
    b: Point;
};

type Projection = {
    wall: PlanWall;
    segmentIndex: number;
    t: number;
    point: Point;
    distancePx: number;
    lengthPx: number;
    rotation_deg: number;
};

function clamp(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, value));
}

function clamp01(value: number): number {
    return clamp(value, 0, 1);
}

function toPx(point: Point, canvas: CanvasSize): Point {
    return { x: point.x * canvas.width, y: point.y * canvas.height };
}

function fromPx(point: Point, canvas: CanvasSize): Point {
    return { x: point.x / canvas.width, y: point.y / canvas.height };
}

function openingWidth(
    opening: AttachedOpening | PlanDoor | PlanWindow,
    fallback = 0.08,
): number {
    const width =
        typeof opening.width === 'number' && Number.isFinite(opening.width)
            ? opening.width
            : fallback;
    return clamp(width, 0.02, 0.4);
}

function segmentLengthPx(a: Point, b: Point, canvas: CanvasSize): number {
    const ax = a.x * canvas.width;
    const ay = a.y * canvas.height;
    const bx = b.x * canvas.width;
    const by = b.y * canvas.height;
    return Math.hypot(bx - ax, by - ay);
}

function projectionOnSegment(
    point: Point,
    a: Point,
    b: Point,
    canvas: CanvasSize,
): Omit<Projection, 'wall' | 'segmentIndex'> | null {
    const p = toPx(point, canvas);
    const ap = toPx(a, canvas);
    const bp = toPx(b, canvas);
    const vx = bp.x - ap.x;
    const vy = bp.y - ap.y;
    const lenSq = vx * vx + vy * vy;
    if (lenSq <= 0.0001) return null;

    const rawT = ((p.x - ap.x) * vx + (p.y - ap.y) * vy) / lenSq;
    const t = clamp01(rawT);
    const projectedPx = { x: ap.x + vx * t, y: ap.y + vy * t };
    const distancePx = Math.hypot(p.x - projectedPx.x, p.y - projectedPx.y);
    const rotation_deg = (Math.atan2(vy, vx) * 180) / Math.PI;

    return {
        t,
        point: fromPx(projectedPx, canvas),
        distancePx,
        lengthPx: Math.sqrt(lenSq),
        rotation_deg,
    };
}

export function nearestWallProjection(
    point: Point,
    walls: PlanWall[],
    canvas: CanvasSize,
    maxDistancePx = 48,
): Projection | null {
    let nearest: Projection | null = null;

    for (const wall of walls) {
        const points = wall.points ?? [];
        for (let index = 0; index < points.length - 1; index += 1) {
            const projection = projectionOnSegment(
                point,
                points[index],
                points[index + 1],
                canvas,
            );
            if (!projection || projection.distancePx > maxDistancePx) continue;
            const candidate: Projection = {
                ...projection,
                wall,
                segmentIndex: index,
            };
            if (!nearest || candidate.distancePx < nearest.distancePx)
                nearest = candidate;
        }
    }

    return nearest;
}

export function openingCentreFromTopLeft(
    opening: PlanDoor | PlanWindow | (AttachedOpening & Point),
    canvas: CanvasSize,
): Point {
    return {
        x: clamp01((opening as Point).x + openingWidth(opening) / 2),
        y: clamp01((opening as Point).y),
    };
}

export function snapOpeningToNearestWall(
    point: Point,
    walls: PlanWall[],
    canvas: CanvasSize,
    options: { width?: number; maxDistancePx?: number } = {},
): ResolvedOpening | null {
    const width = openingWidth({ id: '__draft', width: options.width ?? 0.08 });
    const projection = nearestWallProjection(
        point,
        walls,
        canvas,
        options.maxDistancePx ?? 48,
    );
    if (!projection) return null;

    const halfOpeningPx = (width * canvas.width) / 2;
    const safeInset =
        projection.lengthPx > 0 ? halfOpeningPx / projection.lengthPx : 0;
    const wallT = clamp(projection.t, safeInset, 1 - safeInset);
    const a = projection.wall.points[projection.segmentIndex];
    const b = projection.wall.points[projection.segmentIndex + 1];
    const attachedPoint = {
        x: a.x + (b.x - a.x) * wallT,
        y: a.y + (b.y - a.y) * wallT,
    };

    return {
        attached: true,
        x: clamp01(attachedPoint.x - width / 2),
        y: clamp01(attachedPoint.y),
        rotation_deg: projection.rotation_deg,
        wall_id: projection.wall.id,
        wall_segment_index: projection.segmentIndex,
        wall_t: wallT,
        width,
    };
}

export function resolveAttachedOpening(
    opening: (PlanDoor | PlanWindow | AttachedOpening) & Partial<Point>,
    walls: PlanWall[],
    canvas: CanvasSize,
): ResolvedOpening {
    const width = openingWidth(opening);
    const wallId = opening.wall_id ?? null;
    const segmentIndex =
        typeof opening.wall_segment_index === 'number'
            ? opening.wall_segment_index
            : null;
    const wallT = typeof opening.wall_t === 'number' ? opening.wall_t : null;
    const wall = wallId
        ? walls.find((candidate) => candidate.id === wallId)
        : null;
    const a = wall && segmentIndex !== null ? wall.points[segmentIndex] : null;
    const b =
        wall && segmentIndex !== null ? wall.points[segmentIndex + 1] : null;

    if (a && b && wallT !== null) {
        const safeT = clamp01(wallT);
        const anchor = {
            x: a.x + (b.x - a.x) * safeT,
            y: a.y + (b.y - a.y) * safeT,
        };
        const ap = toPx(a, canvas);
        const bp = toPx(b, canvas);
        return {
            attached: true,
            x: clamp01(anchor.x - width / 2),
            y: clamp01(anchor.y),
            rotation_deg:
                (Math.atan2(bp.y - ap.y, bp.x - ap.x) * 180) / Math.PI,
            wall_id: wallId,
            wall_segment_index: segmentIndex,
            wall_t: safeT,
            width,
        };
    }

    return {
        attached: false,
        x: clamp01(opening.x ?? 0),
        y: clamp01(opening.y ?? 0),
        rotation_deg:
            'rotation_deg' in opening ? (opening.rotation_deg ?? 0) : 0,
        wall_id: wallId,
        wall_segment_index: segmentIndex,
        wall_t: wallT,
        width,
    };
}

export function wallSegmentsWithOpenings(
    wall: PlanWall,
    openings: AttachedOpening[],
    canvas: CanvasSize,
): WallRenderSegment[] {
    const points = wall.points ?? [];
    const segments: WallRenderSegment[] = [];

    for (
        let segmentIndex = 0;
        segmentIndex < points.length - 1;
        segmentIndex += 1
    ) {
        const a = points[segmentIndex];
        const b = points[segmentIndex + 1];
        const lengthPx = segmentLengthPx(a, b, canvas);
        if (lengthPx <= 0.0001) continue;

        const cuts = openings
            .filter(
                (opening) =>
                    opening.wall_id === wall.id &&
                    (opening.wall_segment_index ?? 0) === segmentIndex &&
                    typeof opening.wall_t === 'number',
            )
            .map((opening) => {
                const half =
                    (openingWidth(opening) * canvas.width) / 2 / lengthPx;
                const start = clamp((opening.wall_t ?? 0) - half, 0, 1);
                const end = clamp((opening.wall_t ?? 0) + half, 0, 1);
                return { start, end };
            })
            .filter((cut) => cut.end - cut.start > 0.001)
            .sort((left, right) => left.start - right.start);

        let cursor = 0;
        const pushRange = (start: number, end: number) => {
            if (end - start <= 0.001) return;
            segments.push({
                id: `${wall.id}-${segmentIndex}-${segments.length}`,
                segmentIndex,
                a: {
                    x: a.x + (b.x - a.x) * start,
                    y: a.y + (b.y - a.y) * start,
                },
                b: { x: a.x + (b.x - a.x) * end, y: a.y + (b.y - a.y) * end },
            });
        };

        for (const cut of cuts) {
            pushRange(cursor, cut.start);
            cursor = Math.max(cursor, cut.end);
        }
        pushRange(cursor, 1);
    }

    return segments;
}
