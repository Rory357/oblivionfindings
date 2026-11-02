import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { FirmwareUpdate } from '@/components/security-devices/firmware-update-popup';
import DevicesIndex from './index';
import type { DevicesIndexProps } from './_types';

/** Devices › Upkeep › Updates (pg-sdr-2d) with synthetic devices only. */

const inertia = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), visit: vi.fn(), reload: vi.fn(), delete: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
        router: { ...actual.router, ...inertia, on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children: React.ReactNode }) => children }));

const hoursAhead = (hours: number) => new Date(Date.now() + hours * 3600_000).toISOString();

function update(overrides: Partial<FirmwareUpdate> = {}): FirmwareUpdate {
    return {
        id: 11,
        name: 'Synthetic switch',
        model: 'UniFi USW-24-PoE',
        kind: 'switch',
        domain: 'it_infrastructure',
        where: 'Synthetic House',
        site_id: 4,
        state: 'online',
        badge: { label: 'Online', tone: 'success' },
        href: '/security-devices/devices/11',
        source: 'unifi',
        source_label: 'UniFi',
        maker: 'Ubiquiti',
        current: '6.6.61',
        next: '6.6.65',
        notes: null,
        current_since: '2026-09-17T02:00:00Z',
        install: { label: 'Open in UniFi', href: 'https://unifi.ui.com' },
        plan: { label: 'Not planned', tone: 'neutral', note: 'Update in UniFi', at: null, order: 1, quiet: null },
        ...overrides,
    };
}

function fixture(updates: NonNullable<DevicesIndexProps['updates']>): DevicesIndexProps {
    return {
        tab: 'upkeep',
        layout: 'cards',
        upkeep: 'updates',
        filters: { site: 'all', status: 'all', q: '' },
        tabs: [
            { key: 'all', count: 2 },
            { key: 'upkeep', count: null },
        ],
        meters: {
            online: { count: 2, caption: { text: 'Watched and answering', at: null } },
            offline: { count: 0, caption: { text: 'None offline', at: null } },
            cant_check: { count: 0, caption: { text: 'None', at: null } },
            batteries_low: { count: 0, caption: { text: 'No battery at 20% or below', at: null } },
        },
        sites: [{ value: 'all', label: 'All Sites' }],
        statuses: [{ value: 'all', label: 'Any status' }],
        can: { add: false, assign: false, scan: false, connect: false, edit: false, move: false, retire: false, watch: true },
        cards: null,
        cameras: null,
        list: null,
        batteries: null,
        updates,
        openDialog: null,
    };
}

beforeEach(() => {
    Object.values(inertia).forEach((fn) => fn.mockReset());
    window.localStorage.clear();
});
afterEach(cleanup);

describe('Devices › Upkeep › Updates (pg-sdr-2d)', () => {
    it('lists only what UniFi said, with the versions, plan and Not available for what it fixes', () => {
        const quiet = { starts_at: hoursAhead(3), ends_at: hoursAhead(4), by: 'Synthetic Planner', now: false };
        render(
            <DevicesIndex
                {...fixture({
                    source: true,
                    rows: [
                        update({ plan: { label: 'Quiet time {time}', tone: 'info', note: 'Quiet time set by Synthetic Planner · update in UniFi', at: quiet.starts_at, order: 0, quiet } }),
                        update({ id: 12, name: 'Synthetic gateway', kind: 'router', model: 'UniFi UX7', current: '4.2.9', next: null }),
                    ],
                    unchecked: 3,
                    can: { quiet_time: true },
                    recent: [],
                })}
            />,
        );

        const table = screen.getByRole('table');
        expect(within(table).getByText('Synthetic switch')).toBeInTheDocument();
        expect(within(table).getByText('6.6.61 → 6.6.65')).toBeInTheDocument();
        expect(within(table).getByText('4.2.9 → a newer version')).toBeInTheDocument();
        expect(within(table).getAllByText('Not available')).toHaveLength(2);
        expect(within(table).getByText(/^Quiet time (\d|Mon|Tue|Wed|Thu|Fri|Sat|Sun)/)).toBeInTheDocument();
        expect(within(table).getByText('Quiet time set by Synthetic Planner · update in UniFi')).toBeInTheDocument();
        expect(within(table).getByText('Not planned')).toBeInTheDocument();
        expect(screen.getByText(/3 devices can’t be checked for updates here yet/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Quiet time' })).toBeInTheDocument();
        expect(screen.getByText(/it doesn’t install them/)).toBeInTheDocument();
    });

    it('opens pop-up 62 from a row: the maker’s app to install, Not now first, nothing installed from Oblivion', () => {
        render(<DevicesIndex {...fixture({ source: true, rows: [update()], unchecked: 0, can: { quiet_time: false }, recent: [] })} />);

        fireEvent.click(within(screen.getByRole('table')).getByText('Synthetic switch'));
        const popup = screen.getByTestId('sd-firmware-update');
        expect(within(popup).getByText('Firmware update')).toBeInTheDocument();
        expect(within(popup).getByText('Version 6.6.65 is ready for Synthetic switch, which is on 6.6.61')).toBeInTheDocument();
        expect(within(popup).getByText('6.6.65 · from Ubiquiti, as UniFi reports it')).toBeInTheDocument();
        expect(within(popup).getByText(/Not sent to Oblivion/)).toBeInTheDocument();
        const install = within(popup).getByRole('link', { name: 'Open in UniFi' });
        expect(install).toHaveAttribute('href', 'https://unifi.ui.com');
        expect(install).toHaveAttribute('target', '_blank');
        expect(within(popup).queryByRole('button', { name: /Update and restart/ })).not.toBeInTheDocument();

        const footer = within(popup).getByRole('button', { name: 'Not now' }).parentElement as HTMLElement;
        const order = Array.from(footer.querySelectorAll('a, button')).map((element) => element.textContent?.trim());
        expect(order).toEqual(['Not now', 'Open in UniFi']);
        expect(screen.queryByRole('button', { name: 'Quiet time' })).not.toBeInTheDocument();
    });

    it('never says devices are up to date when no source reports them', () => {
        const { unmount } = render(<DevicesIndex {...fixture({ source: false, rows: [], unchecked: 5, can: { quiet_time: true }, recent: [] })} />);
        expect(screen.getByText(/Oblivion can’t tell yet which devices have newer software/)).toBeInTheDocument();
        expect(screen.getByText(/5 devices can’t be checked for updates here yet/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Quiet time' })).not.toBeInTheDocument();
        unmount();

        render(<DevicesIndex {...fixture({ source: true, rows: [], unchecked: 0, can: { quiet_time: false }, recent: [] })} />);
        expect(screen.getByText('No update is ready for the UniFi devices here, as UniFi reports it.')).toBeInTheDocument();
        expect(screen.getByText('Windows updates are on each computer’s Health tab.')).toBeInTheDocument();
    });

    it('hides a column chosen off in Columns', () => {
        render(<DevicesIndex {...fixture({ source: true, rows: [update()], unchecked: 0, can: { quiet_time: false }, recent: [] })} />);
        expect(within(screen.getByRole('table')).getByText('What it fixes')).toBeInTheDocument();
        window.localStorage.setItem('sd.devices.updates.columns', JSON.stringify(['where', 'version', 'plan']));
        cleanup();
        render(<DevicesIndex {...fixture({ source: true, rows: [update()], unchecked: 0, can: { quiet_time: false }, recent: [] })} />);
        expect(within(screen.getByRole('table')).queryByText('What it fixes')).not.toBeInTheDocument();
    });
});
