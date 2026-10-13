import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { TopologyMap } from './topology-map';
import type {
    TopologyExploration,
    TopologyMapData,
    TopologyNode,
} from './topology-types';

const node = (id: number, name: string): TopologyNode => ({
    id,
    name,
    site: 'Kauri House',
    siteId: 7,
    health: 'healthy',
    href: '/security-devices/devices/' + id,
    exploreHref:
        '/security-devices/network-it?tab=map&map_site_id=7&map_anchor_id=' +
        id,
});
const gateway = node(1, 'Main switch');
const accessPoint = node(205, 'Hall access point');
const exploration: TopologyExploration = {
    anchorId: 1,
    siteId: 7,
    pageKey: 'switch-first',
    neighborLimit: 40,
    neighborsShown: 1,
    neighborsTotal: 41,
    previousHref: null,
    nextHref:
        '/security-devices/network-it?tab=map&map_site_id=7&map_anchor_id=1&map_neighbor_cursor=next',
    refreshHref: gateway.exploreHref!,
    inventoryHref: '/security-devices/network-it?tab=map&map_site_id=7',
    positionReset: false,
    isPartial: true,
    sourcesTruncated: false,
    edgesTruncated: false,
};
const topology: TopologyMapData = {
    nodes: [gateway, accessPoint],
    edges: [
        {
            id: 'observed-1',
            parentId: 1,
            parentName: gateway.name,
            childId: 205,
            childName: accessPoint.name,
            label: 'Ethernet',
            port: 'Port 8 → uplink',
            reviewState: 'inferred',
        },
    ],
};

describe('Current topology connection exploration', () => {
    it('opens current server-scoped connections from an ordinary selected node', () => {
        render(<TopologyMap topology={topology} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Inspect Hall access point' }),
        );
        expect(
            screen.getByRole('link', { name: 'Explore current connections' }),
        ).toHaveAttribute('href', accessPoint.exploreHref);
        expect(
            screen.getByRole('link', { name: 'Open Hall access point' }),
        ).toHaveAttribute('href', accessPoint.href);
        expect(
            screen.queryByText('Focused device view'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', {
                name: /restart|configure|execute/i,
            }),
        ).not.toBeInTheDocument();
    });

    it('selects the anchor and keeps current connection paging separate from history and inventory filters', () => {
        render(
            <TopologyMap
                topology={{
                    ...topology,
                    exploration,
                    history: {
                        limit: 10,
                        truncated: false,
                        entries: [],
                        latestHref: '/security-devices/network-it?tab=map',
                    },
                    inventory: {
                        pageKey: 'ordinary',
                        siteId: 7,
                        sites: [{ id: 7, name: 'Kauri House' }],
                        search: '',
                        shown: 100,
                        total: 205,
                        limit: 100,
                        previousHref: null,
                        nextHref: '/inventory-next',
                        resetHref: '/security-devices/network-it?tab=map',
                        positionReset: false,
                        isPartial: true,
                    },
                }}
            />,
        );
        expect(
            screen.getByRole('heading', {
                name: 'Connections around Main switch',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Showing 1 of 41 visible connected devices in this evidence view.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Open Main switch' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'More connected devices' }),
        ).toHaveAttribute('href', exploration.nextHref);
        expect(screen.getByRole('link', { name: 'Site map' })).toHaveAttribute(
            'href',
            exploration.inventoryHref,
        );
        expect(
            screen.getByRole('link', { name: 'Refresh connections' }),
        ).toHaveAttribute('href', exploration.refreshHref);
        expect(
            screen.queryByRole('region', { name: 'Snapshot history' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('combobox', { name: 'Topology snapshot' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('form', { name: 'Choose topology devices' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Inspect Hall access point' }),
        );
        expect(
            screen.getByRole('link', { name: 'Explore current connections' }),
        ).toHaveAttribute('href', accessPoint.exploreHref);
        expect(
            screen.getByRole('button', {
                name: 'Inspect connection Main switch to Hall access point on Port 8 → uplink',
            }),
        ).toBeInTheDocument();
    });

    it('replaces an old connected-device selection and search when the server returns another page', () => {
        const view = render(
            <TopologyMap topology={{ ...topology, exploration }} />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Inspect Hall access point' }),
        );
        fireEvent.change(screen.getByLabelText('Find within this view'), {
            target: { value: 'Hall' },
        });
        const next = node(301, 'Office access point');
        view.rerender(
            <TopologyMap
                topology={{
                    nodes: [gateway, next],
                    edges: [
                        {
                            ...topology.edges[0],
                            id: 'observed-2',
                            childId: next.id,
                            childName: next.name,
                        },
                    ],
                    exploration: {
                        ...exploration,
                        pageKey: 'switch-next',
                        nextHref: null,
                        previousHref: gateway.exploreHref!,
                    },
                }}
            />,
        );
        expect(screen.queryByText('Hall access point')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Find within this view')).toHaveValue('');
        expect(
            screen.getByRole('link', { name: 'Open Main switch' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Previous connected devices' }),
        ).toHaveAttribute('href', gateway.exploreHref);
        expect(
            screen.queryByRole('link', { name: 'More connected devices' }),
        ).not.toBeInTheDocument();
        view.rerender(
            <TopologyMap
                topology={{
                    nodes: [next],
                    edges: [],
                    exploration: {
                        ...exploration,
                        anchorId: next.id,
                        pageKey: 'office-first',
                        neighborsShown: 0,
                        neighborsTotal: 0,
                        nextHref: null,
                        refreshHref: next.exploreHref!,
                        positionReset: true,
                        sourcesTruncated: true,
                        edgesTruncated: true,
                    },
                }}
            />,
        );
        expect(
            screen.getByRole('heading', {
                name: 'Connections around Office access point',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'No visible connected devices were found in this evidence view.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/That connection page is no longer available/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /Some current evidence sources are outside this view/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /Some additional connection evidence between these devices is not shown/,
            ),
        ).toBeInTheDocument();
        expect(
            within(
                screen.getByRole('region', { name: 'Topology selection' }),
            ).getByText(
                'No connections are shown for this device in this view.',
            ),
        ).toBeInTheDocument();
    });
});
