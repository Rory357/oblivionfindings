import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DevicesIndex from './index';
import type { DeviceCardData, DevicesIndexProps } from './_types';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    put: vi.fn(),
    visit: vi.fn(),
    reload: vi.fn(),
    delete: vi.fn(),
}));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
        router: { ...actual.router, ...inertia, on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

function card(overrides: Partial<DeviceCardData>): DeviceCardData {
    return {
        id: 1,
        name: 'Synthetic switch',
        sub: 'Synthetic SW-1 · 10.0.0.2',
        kind: 'switch',
        kind_label: 'Network switch',
        state: 'online',
        site: 'Synthetic House',
        site_id: 4,
        address: '10.0.0.2',
        model: 'Synthetic SW-1',
        battery: null,
        watching: 'Online',
        href: '/security-devices/devices/1',
        badge: { label: 'Online', tone: 'success' },
        note: { text: 'Checked {ago} · replies in 2 ms', at: new Date(Date.now() - 60_000).toISOString() },
        reading: { value: '2 ms', sub: 'reply time at the last check', from: null },
        graph: { label: 'Online, last 24 hours', note: { text: 'All 24 h', at: null }, hours: 'o'.repeat(24), spark: null, unit: null },
        seen: { label: 'Seen', at: new Date().toISOString() },
        ...overrides,
    };
}

function fixture(overrides: Partial<DevicesIndexProps> = {}): DevicesIndexProps {
    return {
        tab: 'all',
        layout: 'cards',
        upkeep: 'batteries',
        filters: { site: 'all', status: 'all', q: '' },
        tabs: [
            { key: 'all', count: 3 },
            { key: 'network', count: 2 },
            { key: 'cameras', count: 1 },
            { key: 'alarms_doors', count: 0 },
            { key: 'sensors', count: 0 },
            { key: 'trackers', count: 0 },
            { key: 'computers', count: 0 },
            { key: 'other', count: 0 },
            { key: 'upkeep', count: null },
        ],
        meters: {
            online: { count: 1, caption: { text: 'Watched and answering', at: null } },
            offline: { count: 1, caption: { text: 'Synthetic router, since {time}', at: new Date().toISOString() } },
            cant_check: { count: 1, caption: { text: 'Behind Synthetic router · grey, not red', at: null } },
            batteries_low: { count: 0, caption: { text: 'No battery at 20% or below', at: null } },
        },
        sites: [
            { value: 'all', label: 'All Sites' },
            { value: '4', label: 'Synthetic House' },
        ],
        statuses: [{ value: 'all', label: 'Any status' }],
        can: { add: true, assign: true, scan: true, connect: true, edit: true, move: true, retire: true, watch: true },
        cards: {
            groups: [
                {
                    key: 'attention',
                    title: 'Needs attention',
                    count: 2,
                    sub: '1 offline · 1 can’t be checked',
                    truncated: false,
                    items: [
                        card({
                            id: 2,
                            name: 'Synthetic router',
                            state: 'offline',
                            badge: { label: 'Offline', tone: 'critical' },
                            note: { text: 'Since {time} · 1 device behind it can’t be checked', at: new Date().toISOString() },
                            reading: { value: null, sub: 'offline', from: new Date(Date.now() - 2 * 3600_000).toISOString() },
                            graph: { label: 'Online, last 24 hours', note: { text: 'Down since {time}', at: new Date().toISOString() }, hours: `${'o'.repeat(21)}ccc`, spark: null, unit: null },
                            seen: { label: 'Last seen', at: new Date().toISOString() },
                        }),
                        card({
                            id: 3,
                            name: 'Synthetic door',
                            kind: 'door',
                            state: 'cant_check',
                            badge: { label: 'Can’t check', tone: 'neutral' },
                            note: { text: 'Synthetic router is offline', at: null },
                            reading: null,
                            graph: { label: 'Checked, last 24 hours', note: null, hours: null, spark: null, unit: null },
                        }),
                    ],
                },
                { key: 'normal', title: 'Working normally', count: 1, sub: '', truncated: false, items: [card({})] },
            ],
            not_watched: { count: 1, names: ['Synthetic phone base (Synthetic DP)'] },
        },
        cameras: null,
        list: null,
        batteries: null,
        updates: null,
        openDialog: null,
        ...overrides,
    };
}

beforeEach(() => {
    Object.values(inertia).forEach((fn) => fn.mockReset());
});
afterEach(cleanup);

describe('Devices page', () => {
    it('puts problems first, keeps can’t check grey and says Not available where data is missing', () => {
        render(<DevicesIndex {...fixture()} />);

        const attention = screen.getByRole('region', { name: 'Needs attention' });
        const names = within(attention).getAllByRole('article').map((node) => node.getAttribute('aria-label'));
        expect(names).toEqual(['Synthetic router', 'Synthetic door']);

        const door = screen.getByRole('article', { name: 'Synthetic door' });
        expect(within(door).getByText('Can’t check')).toBeTruthy();
        expect(within(door).getByText('Reading: Not available')).toBeTruthy();
        expect(within(door).getByText(/Not available · no checks have run on it/)).toBeTruthy();

        const router = screen.getByRole('article', { name: 'Synthetic router' });
        expect(within(router).getByText('2 h')).toBeTruthy();
        expect(within(router).getByRole('img', { name: /failed in 3/ })).toBeTruthy();

        expect(screen.getByText(/1 not watched:/)).toBeTruthy();
    });

    it('remembers Cards or List for the person and follows the tabs and header numbers', () => {
        render(<DevicesIndex {...fixture()} />);

        fireEvent.click(screen.getByRole('radio', { name: 'List' }));
        expect(inertia.put).toHaveBeenCalledWith(
            '/settings/ui-preferences/security-devices.devices.view',
            { value: ['list'] },
            expect.objectContaining({ only: [] }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Show offline devices' }));
        expect(inertia.get).toHaveBeenLastCalledWith('/security-devices/devices', { status: 'offline' }, expect.anything());
    });

    it('removes a device and keeps its history (Stephan, 1 Nov)', () => {
        render(<DevicesIndex {...fixture()} />);

        fireEvent.contextMenu(screen.getByRole('article', { name: 'Synthetic switch' }));
        fireEvent.click(screen.getByRole('menuitem', { name: 'Remove device' }));

        const confirm = screen.getByRole('alertdialog');
        expect(within(confirm).getByText(/readings, alerts and settings backups are kept/)).toBeTruthy();
        expect(within(confirm).queryByRole('button', { name: 'Retire instead' })).toBeNull();

        fireEvent.click(within(confirm).getByRole('button', { name: 'Remove device' }));
        expect(inertia.delete).toHaveBeenCalledWith('/security-devices/devices/1', expect.objectContaining({ data: { _modal: true } }));
    });

    it('shows the camera wall honestly: no stored pictures, and live viewing asks for a reason', () => {
        render(
            <DevicesIndex
                {...fixture({
                    tab: 'cameras',
                    cards: null,
                    cameras: {
                        stills: false,
                        items: [{ id: 9, name: 'Synthetic camera', site: 'Synthetic House', state: 'online', line: 'online', line_at: null, href: '/security-devices/devices/9' }],
                        cant_check: [{ site: 'Synthetic Lodge', text: 'Its 4 cameras can’t be checked: Synthetic router is offline.' }],
                    },
                })}
            />,
        );

        expect(screen.getByText(/Watch live asks for a reason/)).toBeTruthy();
        expect(screen.getByText(/Picture not available/)).toBeTruthy();
        expect(screen.getByText(/Its 4 cameras can’t be checked/)).toBeTruthy();
    });

    it('lists batteries lowest first with Not available where there is no forecast, and says plainly there is no update source', () => {
        const { unmount } = render(
            <DevicesIndex
                {...fixture({
                    tab: 'upkeep',
                    cards: null,
                    batteries: {
                        forecast: false,
                        rows: [{ id: 5, name: 'Synthetic sensor', model: 'Synthetic AM', kind: 'sensor', where: 'Synthetic House · Room 3', battery: 12, tone: 'critical', cant_check: false, behind: null, charged: false, read_at: null, href: '/security-devices/devices/5' }],
                        meta: { total: 1, page: 1, last_page: 1, from: 1, to: 1 },
                    },
                })}
            />,
        );
        expect(screen.getByText('Synthetic sensor')).toBeTruthy();
        expect(screen.getAllByText('Not available').length).toBeGreaterThanOrEqual(2);
        expect(screen.getByText(/No forecast yet/)).toBeTruthy();
        unmount();

        render(<DevicesIndex {...fixture({ tab: 'upkeep', upkeep: 'updates', cards: null, updates: { source: false, rows: [], unchecked: 0, can: { quiet_time: false }, recent: [] } })} />);
        expect(screen.getByText(/Oblivion can’t tell yet which devices have newer software/)).toBeTruthy();
        expect(screen.getByText(/it doesn’t install them/)).toBeTruthy();
    });
});
