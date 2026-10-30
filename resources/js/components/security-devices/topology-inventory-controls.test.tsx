import { router } from '@inertiajs/react';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { TopologyInventoryControls } from './topology-inventory-controls';
import { TopologyMap } from './topology-map';
import type { TopologyInventory, TopologyMapData } from './topology-types';

const inventory: TopologyInventory = {
    pageKey: 'second-page',
    siteId: null,
    sites: [
        { id: 7, name: 'Kauri House' },
        { id: 8, name: 'Harbour House' },
    ],
    search: '',
    shown: 5,
    total: 105,
    limit: 100,
    previousHref:
        '/security-devices/network-it?tab=map&map_device_cursor=previous',
    nextHref: null,
    resetHref: '/security-devices/network-it?tab=map',
    positionReset: false,
    isPartial: true,
};
afterEach(() => vi.restoreAllMocks());
describe('Topology inventory controls', () => {
    it('applies only the chosen Site and full-inventory name search, dropping both paging positions', () => {
        const get = vi.spyOn(router, 'get').mockImplementation(() => {});
        render(<TopologyInventoryControls inventory={inventory} />);
        fireEvent.keyDown(screen.getByRole('combobox', { name: 'Site' }), {
            key: 'ArrowDown',
        });
        fireEvent.click(screen.getByRole('option', { name: 'Kauri House' }));
        fireEvent.change(screen.getByRole('textbox', { name: 'Device name' }), {
            target: { value: '  Zulu switch  ' },
        });
        fireEvent.submit(
            screen.getByRole('form', { name: 'Choose topology devices' }),
        );
        expect(get).toHaveBeenCalledWith(
            '/security-devices/network-it',
            {
                tab: 'map',
                map_site_id: '7',
                map_search: 'Zulu switch',
            },
            expect.objectContaining({
                preserveState: false,
                preserveScroll: true,
            }),
        );
        expect(
            screen.getByText('Current view: All visible devices'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Showing 5 of 105 matching devices.'),
        ).toBeInTheDocument();
        expect(screen.getByText('Limited device view')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Previous devices' }),
        ).toHaveAttribute('href', inventory.previousHref);
    });
    it('clears filters explicitly and restores submission after a failed view change', () => {
        const get = vi.spyOn(router, 'get').mockImplementation(() => {});
        render(
            <TopologyInventoryControls
                inventory={{ ...inventory, siteId: 7, search: 'switch' }}
            />,
        );
        fireEvent.keyDown(screen.getByRole('combobox', { name: 'Site' }), {
            key: 'ArrowDown',
        });
        fireEvent.click(
            screen.getByRole('option', { name: 'All visible devices' }),
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'Device name' }), {
            target: { value: ' ' },
        });
        fireEvent.submit(
            screen.getByRole('form', { name: 'Choose topology devices' }),
        );
        expect(get).toHaveBeenCalledWith(
            '/security-devices/network-it',
            { tab: 'map' },
            expect.any(Object),
        );
        const options = get.mock.calls[0][2] as {
            onStart: () => void;
            onError: () => void;
            onFinish: () => void;
        };
        act(() => options.onStart());
        expect(
            screen.getByRole('button', { name: 'Applying view…' }),
        ).toBeDisabled();
        act(() => {
            options.onError();
            options.onFinish();
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'The view could not be applied',
        );
        expect(
            screen.getByRole('button', { name: 'Apply view' }),
        ).toBeEnabled();
        expect(
            screen.getByRole('link', { name: 'Clear view' }),
        ).toHaveAttribute('href', inventory.resetHref);
    });
    it('replaces the map and filter draft on a new server view and keeps an empty result recoverable', () => {
        const data: TopologyMapData = {
            inventory,
            nodes: [
                {
                    id: 105,
                    name: 'Zulu switch',
                    href: '/security-devices/devices/105',
                    site: 'Harbour House',
                    health: 'healthy',
                },
            ],
            edges: [],
        };
        const view = render(<TopologyMap topology={data} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Inspect Zulu switch' }),
        );
        fireEvent.change(screen.getByRole('textbox', { name: 'Device name' }), {
            target: { value: 'unapplied change' },
        });
        view.rerender(
            <TopologyMap
                topology={{
                    inventory: {
                        ...inventory,
                        pageKey: 'empty-filter',
                        search: 'No match',
                        siteId: 7,
                        shown: 0,
                        total: 0,
                        previousHref: null,
                        positionReset: true,
                    },
                    nodes: [],
                    edges: [],
                }}
            />,
        );
        expect(
            screen.getByRole('textbox', { name: 'Device name' }),
        ).toHaveValue('No match');
        expect(
            screen.getByText('No devices match this view'),
        ).toBeInTheDocument();
        expect(screen.queryByText('Zulu switch')).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Clear view' }),
        ).toBeInTheDocument();
        expect(
            within(
                screen.getByRole('region', { name: 'Workspace filters' }),
            ).getByRole('status'),
        ).toHaveTextContent('That device page is no longer available');
    });
});
