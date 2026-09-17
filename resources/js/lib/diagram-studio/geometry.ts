import type { DiagramEdgeV2, DiagramNodeV2, DiagramPageV2 } from './contract';

export type Point = { x: number; y: number };
export type Box = Point & { w: number; h: number };
export const clamp = (value: number, min: number, max: number) =>
    Math.min(max, Math.max(min, value));
export const centre = (n: Box): Point => ({
    x: n.x + n.w / 2,
    y: n.y + n.h / 2,
});
export const rotate = (point: Point, origin: Point, degrees: number): Point => {
    const radians = (degrees * Math.PI) / 180,
        x = point.x - origin.x,
        y = point.y - origin.y;
    return {
        x: origin.x + x * Math.cos(radians) - y * Math.sin(radians),
        y: origin.y + x * Math.sin(radians) + y * Math.cos(radians),
    };
};
export function bounds(nodes: readonly DiagramNodeV2[]): Box | null {
    if (!nodes.length) return null;
    const points = nodes.flatMap((n) =>
        [
            { x: n.x, y: n.y },
            { x: n.x + n.w, y: n.y },
            { x: n.x + n.w, y: n.y + n.h },
            { x: n.x, y: n.y + n.h },
        ].map((p) => rotate(p, centre(n), n.rotation)),
    );
    const x = Math.min(...points.map((p) => p.x)),
        y = Math.min(...points.map((p) => p.y));
    return {
        x,
        y,
        w: Math.max(...points.map((p) => p.x)) - x,
        h: Math.max(...points.map((p) => p.y)) - y,
    };
}
export const visible = (node: { layerId: string }, page: DiagramPageV2) =>
    page.layers.some(
        (l) => l.id.toLowerCase() === node.layerId.toLowerCase() && l.visible,
    );
export const editable = (node: DiagramNodeV2, page: DiagramPageV2) =>
    !node.locked &&
    page.layers.some(
        (l) =>
            l.id.toLowerCase() === node.layerId.toLowerCase() &&
            l.visible &&
            !l.locked,
    );
export function descendants(
    page: DiagramPageV2,
    ids: readonly string[],
): string[] {
    const selected = new Set(ids.map((id) => id.toLowerCase()));
    let changed = true;
    while (changed) {
        changed = false;
        for (const item of [...page.groups, ...page.nodes])
            if (
                item.parentId &&
                selected.has(item.parentId.toLowerCase()) &&
                !selected.has(item.id.toLowerCase())
            ) {
                selected.add(item.id.toLowerCase());
                changed = true;
            }
    }
    return page.nodes
        .filter((n) => selected.has(n.id.toLowerCase()))
        .map((n) => n.id);
}
export function selectionForNode(
    page: DiagramPageV2,
    node: DiagramNodeV2,
): string[] {
    const groups = new Map(page.groups.map((g) => [g.id.toLowerCase(), g]));
    let group = node.parentId
        ? groups.get(node.parentId.toLowerCase())
        : undefined;
    const visited = new Set<string>();
    let root = node.id;
    while (group && !visited.has(group.id)) {
        visited.add(group.id);
        root = group.id;
        group = group.parentId
            ? groups.get(group.parentId.toLowerCase())
            : undefined;
    }
    return descendants(page, [root]);
}
export function port(
    node: DiagramNodeV2,
    side: DiagramEdgeV2['fromPort'],
    towards: Point,
): Point & {
    side: Exclude<DiagramEdgeV2['fromPort'], 'auto'>;
    direction: Point;
} {
    if (side === 'auto') {
        const local = rotate(towards, centre(node), -node.rotation);
        const dx = local.x - centre(node).x,
            dy = local.y - centre(node).y;
        side =
            Math.abs(dx / node.w) > Math.abs(dy / node.h)
                ? dx > 0
                    ? 'right'
                    : 'left'
                : dy > 0
                  ? 'bottom'
                  : 'top';
    }
    const positions = {
        top: { x: node.x + node.w / 2, y: node.y },
        right: { x: node.x + node.w, y: node.y + node.h / 2 },
        bottom: { x: node.x + node.w / 2, y: node.y + node.h },
        left: { x: node.x, y: node.y + node.h / 2 },
    };
    const directions = {
        top: { x: 0, y: -1 },
        right: { x: 1, y: 0 },
        bottom: { x: 0, y: 1 },
        left: { x: -1, y: 0 },
    };
    return {
        ...rotate(positions[side], centre(node), node.rotation),
        side,
        direction: rotate(directions[side], { x: 0, y: 0 }, node.rotation),
    };
}
function pointOnPolyline(points: Point[], position: number): Point {
    const lengths = points
        .slice(1)
        .map((p, i) => Math.hypot(p.x - points[i].x, p.y - points[i].y));
    let remaining = lengths.reduce((sum, n) => sum + n, 0) * position;
    for (let i = 0; i < lengths.length; i++) {
        if (remaining <= lengths[i]) {
            const fraction = lengths[i] ? remaining / lengths[i] : 0;
            return {
                x: points[i].x + (points[i + 1].x - points[i].x) * fraction,
                y: points[i].y + (points[i + 1].y - points[i].y) * fraction,
            };
        }
        remaining -= lengths[i];
    }
    return points.at(-1)!;
}
export function edgeGeometry(
    edge: DiagramEdgeV2,
    nodes: readonly DiagramNodeV2[],
): { d: string; label: Point; points: Point[] } | null {
    const from = nodes.find(
            (n) => n.id.toLowerCase() === edge.from.toLowerCase(),
        ),
        to = nodes.find((n) => n.id.toLowerCase() === edge.to.toLowerCase());
    if (!from || !to) return null;
    const start = port(from, edge.fromPort, centre(to)),
        end = port(to, edge.toPort, centre(from));
    let points: Point[] = [start, end];
    if (edge.route === 'curved') {
        const distance = Math.max(
            40,
            Math.hypot(end.x - start.x, end.y - start.y) / 2,
        );
        const a = {
            x: start.x + start.direction.x * distance,
            y: start.y + start.direction.y * distance,
        };
        const b = {
            x: end.x + end.direction.x * distance,
            y: end.y + end.direction.y * distance,
        };
        const t = edge.labelPos,
            u = 1 - t;
        const label = {
            x:
                u ** 3 * start.x +
                3 * u ** 2 * t * a.x +
                3 * u * t ** 2 * b.x +
                t ** 3 * end.x,
            y:
                u ** 3 * start.y +
                3 * u ** 2 * t * a.y +
                3 * u * t ** 2 * b.y +
                t ** 3 * end.y,
        };
        return {
            d: `M ${start.x} ${start.y} C ${a.x} ${a.y} ${b.x} ${b.y} ${end.x} ${end.y}`,
            label,
            points: [start, a, b, end],
        };
    }
    if (edge.route === 'orthogonal') {
        const horizontal =
            Math.abs(start.direction.x) >= Math.abs(start.direction.y);
        const endHorizontal =
            Math.abs(end.direction.x) >= Math.abs(end.direction.y);
        if (horizontal !== endHorizontal)
            points = [
                start,
                horizontal
                    ? { x: end.x, y: start.y }
                    : { x: start.x, y: end.y },
                end,
            ];
        else if (horizontal) {
            const x = (start.x + end.x) / 2 + edge.bend;
            points = [start, { x, y: start.y }, { x, y: end.y }, end];
        } else {
            const y = (start.y + end.y) / 2 + edge.bend;
            points = [start, { x: start.x, y }, { x: end.x, y }, end];
        }
    }
    return {
        d: points.map((p, i) => `${i ? 'L' : 'M'} ${p.x} ${p.y}`).join(' '),
        label: pointOnPolyline(points, edge.labelPos),
        points,
    };
}

export function snapDelta(
    page: DiagramPageV2,
    selected: readonly DiagramNodeV2[],
    dx: number,
    dy: number,
    bypass = false,
): Point {
    if (!selected.length) return { x: dx, y: dy };
    let x = dx,
        y = dy;
    const first = selected[0];
    if (page.grid.snap && !bypass) {
        const size = page.grid.size;
        x = Math.round((first.x + dx) / size) * size - first.x;
        y = Math.round((first.y + dy) / size) * size - first.y;
    }
    return {
        x: clamp(
            x,
            Math.max(...selected.map((n) => -10000 - n.x)),
            Math.min(...selected.map((n) => 10000 - n.x)),
        ),
        y: clamp(
            y,
            Math.max(...selected.map((n) => -10000 - n.y)),
            Math.min(...selected.map((n) => 10000 - n.y)),
        ),
    };
}
