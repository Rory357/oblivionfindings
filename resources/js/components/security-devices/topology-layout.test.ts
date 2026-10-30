import { describe, expect, it } from 'vitest';
import {
    connectionPath,
    edgeKey,
    NODE_HEIGHT,
    NODE_WIDTH,
    topologyLayout,
} from './topology-layout';
import type { TopologyEdge, TopologyNode } from './topology-types';
const nodes: TopologyNode[] = [1, 2, 3, 4, 5].map((id) => ({
    id,
    name: 'Device ' + id,
    site: 'Site',
    href: '/security-devices/devices/' + id,
    health: 'healthy',
}));
const edge = (id: number, parentId: number, childId: number): TopologyEdge => ({
    id,
    parentId,
    childId,
    parentName: 'Device ' + parentId,
    childName: 'Device ' + childId,
    label: 'Observed link',
    port: null,
});
describe('Topology graph evidence', () => {
    it('keeps disconnected devices and cycles without inventing relationships', () => {
        const edges = [
            edge(1, 1, 2),
            edge(2, 2, 3),
            edge(3, 3, 1),
            edge(4, 4, 4),
        ];
        const map = topologyLayout(nodes, edges);
        expect(map.nodes.map((node) => node.id).sort()).toEqual([
            1, 2, 3, 4, 5,
        ]);
        expect(map.edges).toEqual(edges);
        for (let i = 0; i < map.nodes.length; i++)
            for (let j = i + 1; j < map.nodes.length; j++) {
                const a = map.nodes[i],
                    b = map.nodes[j];
                expect(
                    Math.abs(a.x - b.x) >= NODE_WIDTH ||
                        Math.abs(a.y - b.y) >= NODE_HEIGHT,
                ).toBe(true);
            }
    });
    it('never draws or traces an endpoint outside the visible canonical records', () => {
        const edges = [edge(1, 1, 2), edge(2, 2, 99), edge(3, 99, 3)];
        expect(topologyLayout(nodes, edges).edges).toEqual([edges[0]]);
        expect(connectionPath(nodes, edges, 1, 3)).toBeNull();
        expect(connectionPath(nodes, edges, 1, 99)).toBeNull();
    });
    it('traces evidence in either direction and retains exact selected edge identities', () => {
        const edges = [
            edge(1, 1, 2),
            edge(2, 2, 3),
            edge(3, 3, 1),
            edge(4, 3, 4),
        ];
        expect(connectionPath(nodes, edges, 4, 2)).toEqual({
            nodes: [4, 3, 2],
            edges: [edgeKey(edges[3]), edgeKey(edges[1])],
        });
        expect(connectionPath(nodes, edges, 5, 2)).toBeNull();
        expect(connectionPath(nodes, edges, 1, 1)).toEqual({
            nodes: [1],
            edges: [],
        });
    });
    it('produces the same layout when record ordering changes', () => {
        const edges = [edge(1, 1, 2), edge(2, 2, 3)];
        expect(topologyLayout(nodes, edges).nodes).toEqual(
            topologyLayout([...nodes].reverse(), [...edges].reverse()).nodes,
        );
    });
});
