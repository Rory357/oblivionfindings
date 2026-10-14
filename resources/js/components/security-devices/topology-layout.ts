import type { TopologyEdge, TopologyNode } from './topology-types';

export const NODE_WIDTH = 248;
export const NODE_HEIGHT = 96;
const GAP_X = 112,
    GAP_Y = 48,
    MARGIN = 64;
export const edgeKey = (edge: TopologyEdge) => typeof edge.id + ':' + edge.id;

export function visibleEdges(nodes: TopologyNode[], edges: TopologyEdge[]) {
    const ids = new Set(nodes.map((node) => node.id));
    const seen = new Set<string>();
    return edges.filter((edge) => {
        const key = edgeKey(edge);
        if (!ids.has(edge.parentId) || !ids.has(edge.childId) || seen.has(key))
            return false;
        seen.add(key);
        return true;
    });
}

export function topologyLayout(
    nodes: TopologyNode[],
    supplied: TopologyEdge[],
) {
    const unique = [...new Map(nodes.map((node) => [node.id, node])).values()];
    const edges = visibleEdges(unique, supplied);
    const byId = new Map(unique.map((node) => [node.id, node]));
    const compare = (a: number, b: number) =>
        (byId.get(a)?.name ?? '').localeCompare(byId.get(b)?.name ?? '') ||
        a - b;
    const adjacency = new Map(
        unique.map((node) => [node.id, new Set<number>()]),
    );
    const incoming = new Map(unique.map((node) => [node.id, 0]));
    for (const edge of edges) {
        if (edge.parentId === edge.childId) continue;
        adjacency.get(edge.parentId)!.add(edge.childId);
        adjacency.get(edge.childId)!.add(edge.parentId);
        incoming.set(edge.childId, incoming.get(edge.childId)! + 1);
    }
    const placed: Array<TopologyNode & { x: number; y: number }> = [];
    const visited = new Set<number>();
    let offset = MARGIN,
        maxX = MARGIN;
    // Prefer observed upstream roots, without inventing an Internet or gateway node.
    const roots = unique
        .map((node) => node.id)
        .sort(
            (a, b) =>
                (incoming.get(a)! > 0 ? 1 : 0) -
                    (incoming.get(b)! > 0 ? 1 : 0) || compare(a, b),
        );
    for (const root of roots) {
        if (visited.has(root)) continue;
        const queue: Array<[number, number]> = [[root, 0]];
        const layers = new Map<number, number[]>();
        visited.add(root);
        for (let cursor = 0; cursor < queue.length; cursor++) {
            const [id, depth] = queue[cursor];
            layers.set(depth, [...(layers.get(depth) ?? []), id]);
            for (const neighbour of [...adjacency.get(id)!].sort(compare)) {
                if (!visited.has(neighbour)) {
                    visited.add(neighbour);
                    queue.push([neighbour, depth + 1]);
                }
            }
        }
        const rows = Math.max(
            ...[...layers.values()].map((layer) => layer.length),
        );
        for (const [depth, ids] of layers) {
            ids.sort(compare).forEach((id, index) => {
                const x = MARGIN + depth * (NODE_WIDTH + GAP_X);
                const y =
                    offset +
                    (index + (rows - ids.length) / 2) * (NODE_HEIGHT + GAP_Y);
                placed.push({ ...byId.get(id)!, x, y });
                maxX = Math.max(maxX, x + NODE_WIDTH);
            });
        }
        offset += rows * (NODE_HEIGHT + GAP_Y) + MARGIN;
    }
    return {
        nodes: placed,
        edges,
        width: maxX + 160,
        height: Math.max(280, offset),
    };
}

export function connectionPath(
    nodes: TopologyNode[],
    supplied: TopologyEdge[],
    from: number,
    to: number,
) {
    const ids = new Set(nodes.map((node) => node.id));
    if (!ids.has(from) || !ids.has(to)) return null;
    const adjacent = new Map(
        nodes.map((node) => [node.id, [] as { node: number; edge: string }[]]),
    );
    for (const edge of visibleEdges(nodes, supplied)) {
        adjacent
            .get(edge.parentId)!
            .push({ node: edge.childId, edge: edgeKey(edge) });
        adjacent
            .get(edge.childId)!
            .push({ node: edge.parentId, edge: edgeKey(edge) });
    }
    const previous = new Map<number, { node: number; edge: string } | null>([
        [from, null],
    ]);
    const queue = [from];
    for (let cursor = 0; cursor < queue.length && !previous.has(to); cursor++) {
        for (const next of adjacent.get(queue[cursor])!) {
            if (previous.has(next.node)) continue;
            previous.set(next.node, { node: queue[cursor], edge: next.edge });
            queue.push(next.node);
        }
    }
    if (!previous.has(to)) return null;
    const pathNodes = [to],
        pathEdges: string[] = [];
    let current = to;
    while (current !== from) {
        const before = previous.get(current)!;
        pathEdges.unshift(before.edge);
        pathNodes.unshift(before.node);
        current = before.node;
    }
    return { nodes: pathNodes, edges: pathEdges };
}
