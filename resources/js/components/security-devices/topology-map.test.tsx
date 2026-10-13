import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { TopologyMap } from './topology-map';
import type { TopologyMapData } from './topology-types';

describe('TopologyMap', () => {
    it('provides canonical links and a keyboard-readable evidence fallback', () => {
        render(
            <TopologyMap
                topology={{
                    source: 'native_runtime',
                    nodes: [
                        {
                            id: 1,
                            name: 'WAN gateway',
                            site: 'Central Site',
                            href: '/security-devices/devices/1',
                            health: 'critical',
                        },
                        {
                            id: 2,
                            name: 'Access switch',
                            site: 'Central Site',
                            href: '/security-devices/devices/2',
                            health: 'warning',
                        },
                    ],
                    edges: [
                        {
                            id: 'runtime-9',
                            parentId: 1,
                            parentName: 'WAN gateway',
                            childId: 2,
                            childName: 'Access switch',
                            label: 'LLDP neighbour',
                            port: 'eth0 → uplink',
                            source: 'snmp',
                            confidence: 0.94,
                            reviewState: 'inferred',
                            evidenceLabel: 'SNMP LLDP evidence',
                        },
                    ],
                    snapshots: [
                        {
                            id: 3,
                            site: {
                                id: 4,
                                name: 'Central Site',
                                href: '/security-devices/sites/4',
                            },
                            source: 'snmp',
                            capturedAt: '2026-07-23T08:00:00Z',
                            nodeCount: 2,
                            edgeCount: 1,
                            changeCount: 1,
                        },
                    ],
                    changes: { added: 1, removed: 0, changed: 0 },
                }}
            />,
        );

        expect(
            screen.getByRole('link', { name: /WAN gateway/i }),
        ).toHaveAttribute('href', '/security-devices/devices/1');
        const relationship = screen.getByRole('article');
        expect(relationship).toHaveAttribute('tabindex', '0');
        expect(
            screen.getByText(/SNMP LLDP evidence · 94% confidence/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/SNMP · 2 nodes · 1 edges · 1 changes/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Central Site' }),
        ).toHaveAttribute('href', '/security-devices/sites/4');
        expect(
            screen.getByText(
                'Since the previous snapshots: 1 added · 0 removed · 0 changed',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Inferred')).toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: /unlock|restart|wipe|run command/i,
            }),
        ).not.toBeInTheDocument();
    });
});

const interactive: TopologyMapData = {
    nodes: [
        {
            id: 1,
            name: 'Gateway',
            site: 'Harbour',
            href: '/security-devices/devices/1',
            health: 'healthy',
        },
        {
            id: 2,
            name: 'Care switch',
            site: 'Harbour',
            href: '/security-devices/devices/2',
            health: 'warning',
        },
        {
            id: 3,
            name: 'Nurse station',
            site: 'Harbour',
            href: '/security-devices/devices/3',
            health: 'healthy',
        },
    ],
    edges: [
        {
            id: 1,
            parentId: 1,
            parentName: 'Gateway',
            childId: 2,
            childName: 'Care switch',
            label: 'LLDP neighbour',
            port: 'Port 1 → uplink',
            reviewState: 'inferred',
            lastSeenAt: '2026-10-08T00:00:00Z',
            freshness: {
                state: 'stale',
                observedAt: '2026-10-08T00:00:00Z',
                staleAfterSeconds: 3600,
            },
        },
        {
            id: 2,
            parentId: 2,
            parentName: 'Care switch',
            childId: 3,
            childName: 'Nurse station',
            label: 'Wired client',
            port: 'Port 7',
            reviewState: 'reviewed',
        },
    ],
};
describe('Interactive network topology', () => {
    it('opens precise device and port evidence without dispatching a command', () => {
        render(<TopologyMap topology={interactive} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Inspect Care switch' }),
        );
        const detail = screen.getByRole('region', {
            name: 'Topology selection',
        });
        expect(
            within(detail).getByRole('link', { name: 'Open Care switch' }),
        ).toHaveAttribute('href', '/security-devices/devices/2');
        fireEvent.keyDown(
            screen.getByRole('combobox', { name: 'Trace a connection to' }),
            { key: 'ArrowDown' },
        );
        fireEvent.click(screen.getByRole('option', { name: 'Gateway' }));
        expect(
            within(detail).getByText('This path includes older evidence'),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Inspect connection Gateway to Care switch on Port 1 → uplink',
            }),
        );
        expect(within(detail).getByText('Older evidence')).toBeInTheDocument();
        expect(within(detail).getByText(/Last recorded/)).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /restart|unlock|execute/i }),
        ).not.toBeInTheDocument();
    });
    it('supports keyboard pan, zoom and focused device search', () => {
        render(<TopologyMap topology={interactive} />);
        const zoom = screen.getByLabelText('Map zoom');
        const before = Number(zoom.textContent!.replace('%', ''));
        fireEvent.click(screen.getByRole('button', { name: 'Zoom in' }));
        expect(Number(zoom.textContent!.replace('%', ''))).toBeGreaterThan(
            before,
        );
        const viewport = screen.getByRole('region', {
            name: 'Network topology map',
        });
        const transform = (viewport.firstElementChild as HTMLElement).style
            .transform;
        fireEvent.keyDown(viewport, { key: 'ArrowRight' });
        expect(
            (viewport.firstElementChild as HTMLElement).style.transform,
        ).not.toBe(transform);
        fireEvent.change(screen.getByLabelText('Find within this view'), {
            target: { value: 'nurse' },
        });
        fireEvent.click(
            within(screen.getByLabelText('Device search results')).getByRole(
                'button',
                { name: 'Nurse station' },
            ),
        );
        expect(
            screen.getByRole('link', { name: 'Open Nurse station' }),
        ).toHaveAttribute('href', '/security-devices/devices/3');
    });
    it('excludes hidden endpoint names from the graph and relationship list', () => {
        render(
            <TopologyMap
                topology={{
                    ...interactive,
                    edges: [
                        ...interactive.edges,
                        {
                            id: 3,
                            parentId: 3,
                            parentName: 'Nurse station',
                            childId: 99,
                            childName: 'Private device',
                            label: 'Link',
                            port: 'Secret port',
                        },
                    ],
                }}
            />,
        );
        expect(screen.queryByText('Private device')).not.toBeInTheDocument();
        expect(screen.queryByText('Secret port')).not.toBeInTheDocument();
    });
});

const snapshot = (id: number, nodes = interactive.nodes) => ({
    id,
    site: { id: 7, name: 'Harbour', href: '/security-devices/sites/7' },
    source: 'native:snmp',
    capturedAt: '2026-10-08T00:00:00Z',
    nodeCount: nodes.length,
    edgeCount: nodes.length ? interactive.edges.length : 0,
    changeCount: 0,
    nodes,
    edges: nodes.length ? interactive.edges : [],
    comparison: {
        previousSnapshotId: null,
        state: 'baseline' as const,
        addedEdges: [],
        removedEdges: [],
        changedEdges: [],
    },
});
const newestPage: TopologyMapData = {
    ...interactive,
    history: {
        limit: 10,
        truncated: true,
        entries: [snapshot(30)],
        pageKey: 'latest',
        isOlderPage: false,
        previousHref: null,
        nextHref:
            '/security-devices/network-it?tab=map&topology_cursor=first-boundary',
        latestHref: '/security-devices/network-it?tab=map',
        positionReset: false,
    },
};
describe('Topology history browsing', () => {
    it('uses server navigation and replaces inspection state when the history page changes', () => {
        const view = render(<TopologyMap topology={newestPage} />);
        expect(
            screen.getByRole('link', { name: 'Older snapshots' }),
        ).toHaveAttribute('href', newestPage.history!.nextHref);
        expect(
            screen.queryByRole('link', { name: 'Newer snapshots' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Refresh view' }),
        ).toHaveAttribute('href', '/security-devices/network-it?tab=map');
        expect(
            screen.queryByText('Historical connections'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Inspect Care switch' }),
        );
        fireEvent.change(screen.getByLabelText('Find within this view'), {
            target: { value: 'care' },
        });
        view.rerender(
            <TopologyMap
                topology={{
                    ...interactive,
                    history: {
                        ...newestPage.history!,
                        pageKey: 'older-boundary',
                        isOlderPage: true,
                        entries: [snapshot(20)],
                        previousHref:
                            '/security-devices/network-it?tab=map&topology_cursor=newer-boundary',
                        nextHref: null,
                    },
                }}
            />,
        );
        expect(screen.getByText('Historical connections')).toBeInTheDocument();
        expect(screen.getByLabelText('Find within this view')).toHaveValue('');
        expect(
            screen.queryByRole('link', { name: 'Open Care switch' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Newer snapshots' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/network-it?tab=map&topology_cursor=newer-boundary',
        );
        expect(
            screen.queryByRole('link', { name: 'Older snapshots' }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText('Oldest visible snapshots'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Latest topology' }),
        ).toHaveAttribute('href', '/security-devices/network-it?tab=map');
    });
    it('keeps history navigation when current permissions leave a snapshot with no visible nodes', () => {
        render(
            <TopologyMap
                topology={{
                    nodes: [],
                    edges: [],
                    history: {
                        ...newestPage.history!,
                        pageKey: 'empty-snapshot',
                        isOlderPage: true,
                        entries: [snapshot(4, [])],
                        previousHref:
                            '/security-devices/network-it?tab=map&topology_cursor=newer-empty',
                    },
                }}
            />,
        );
        expect(
            screen.getByText('No visible devices in this snapshot'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('navigation', { name: 'Topology history pages' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Latest topology' }),
        ).toBeInTheDocument();
        expect(screen.queryByText('Care switch')).not.toBeInTheDocument();
    });
    it('explains a server position reset and does not retain the old snapshot or private node names', () => {
        const older: TopologyMapData = {
            ...interactive,
            history: {
                ...newestPage.history!,
                pageKey: 'old-access',
                isOlderPage: true,
                entries: [snapshot(4)],
            },
        };
        const view = render(<TopologyMap topology={older} />);
        expect(screen.getByText('Historical connections')).toBeInTheDocument();
        view.rerender(
            <TopologyMap
                topology={{
                    nodes: [],
                    edges: [],
                    history: {
                        ...newestPage.history!,
                        entries: [],
                        nextHref: null,
                        truncated: false,
                        positionReset: true,
                    },
                }}
            />,
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'That history position is no longer available. Showing the newest visible snapshots.',
        );
        expect(
            screen.getByText('No recorded snapshots are visible in this view.'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Refresh view' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Historical connections'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Care switch')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('navigation', {
                name: 'Topology history pages',
            }),
        ).not.toBeInTheDocument();
    });
});
