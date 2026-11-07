import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Overview, { type OverviewProps } from './index';

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<typeof import('@inertiajs/react')>('@inertiajs/react');
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
        router: { ...actual.router, get: vi.fn(), visit: vi.fn(), on: () => () => undefined },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));

function fixture(overrides: Partial<OverviewProps> = {}): OverviewProps {
    return {
        tab: 'overview',
        site: 'all',
        sites: [{ value: '4', label: 'Synthetic House' }],
        first_time: null,
        meters: {
            online: { count: 2, of: 3, caption: { text: 'Checked {ago}', at: new Date(Date.now() - 60_000).toISOString() } },
            offline: { count: 1, caption: { text: 'Synthetic router, since {time}', at: new Date(Date.now() - 3_600_000).toISOString() } },
            cant_check: { count: 0, caption: 'Everything can be checked' },
            alerts: { count: 0, caption: 'None open' },
        },
        overview: {
            attention: [
                { key: 'device-11', at: new Date(Date.now() - 3_600_000).toISOString(), tone: 'critical', title: 'Synthetic router is offline', sub: 'Synthetic R1 · Synthetic House', href: '/security-devices/devices/11', action: 'Open device' },
            ],
            attention_count: 1,
            sites: [],
            cameras: [],
            camera_count: 0,
            hubs: { online: 1, offline: 0, not_installed: 0, calm: 'The hub is answering' },
            activity: [],
        },
        trends: null,
        can: { add: true, scan: true, connect: true, assign: true },
        ...overrides,
    };
}

afterEach(() => {
    cleanup();
});

describe('Security & devices home', () => {
    it('leads with what needs attention and the hubs, in plain words', () => {
        render(<Overview {...fixture()} />);

        expect(screen.getByRole('heading', { name: 'Security & devices' })).toBeInTheDocument();
        expect(screen.getByText('Synthetic router is offline')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open device: Synthetic router is offline' })).toHaveAttribute('href', '/security-devices/devices/11');
        expect(screen.getByText('The hub is answering')).toBeInTheDocument();
        expect(screen.queryByText(/collector|observation/i)).not.toBeInTheDocument();
    });

    it('shows the four setup steps instead of empty counters the first time', () => {
        render(
            <Overview
                {...fixture({
                    overview: null,
                    meters: {
                        online: { count: 0, of: 0, caption: { text: 'Add a hub to start checking', at: null } },
                        offline: { count: 0, caption: { text: 'Nothing offline', at: null } },
                        cant_check: { count: 0, caption: 'Everything can be checked' },
                        alerts: { count: 0, caption: 'None open' },
                    },
                    first_time: {
                        site_count: 2,
                        steps: [
                            { label: 'Add a hub', meta: 'Download it for your Site', state: 'now' },
                            { label: 'Scan your network', meta: 'The hub looks for devices', state: 'todo' },
                            { label: 'Add the devices you want', meta: 'Tick them', state: 'todo' },
                            { label: 'Turn on alerts', meta: 'Choose who is told', state: 'todo' },
                        ],
                    },
                })}
            />,
        );

        expect(screen.getByText('Get started in 4 steps')).toBeInTheDocument();
        expect(screen.getByText('What a hub is')).toBeInTheDocument();
        expect(screen.getByText('Not checked yet')).toBeInTheDocument();
        expect(screen.queryByText('Offline')).not.toBeInTheDocument();
    });

    it('has no Activity tab for someone who may not see device events', () => {
        render(<Overview {...fixture()} />);

        expect(screen.queryByRole('tab', { name: 'Activity' })).not.toBeInTheDocument();
    });

    it('shows the day’s stream newest first, with the night as its own block (18c)', () => {
        const at = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString();
        const line = {
            site_id: 4,
            site: 'Synthetic House',
            device_id: null,
            device_kind: null,
            by: null,
            action: null,
            sub: null,
            next: null,
        };
        render(
            <Overview
                {...fixture({
                    tab: 'activity',
                    overview: null,
                    can: { add: true, scan: true, connect: true, assign: true, activity: true, export: true },
                    activity: {
                        day: '2026-10-29',
                        today: true,
                        kind: 'all',
                        kinds: [
                            { value: 'all', label: 'Every kind' },
                            { value: 'alerts', label: 'Alerts' },
                        ],
                        rows: [
                            {
                                ...line,
                                key: 'alert-1',
                                at: at(10),
                                tone: 'critical',
                                kind: 'alert',
                                group: 'alerts',
                                title: 'Synthetic router is offline',
                                device_id: 11,
                                device_kind: 'router',
                                href: '/security-devices/alerts?alert=1',
                                next: 'Nobody was told · still open, not acknowledged',
                                action: { label: 'Open alert', href: '/security-devices/alerts?alert=1' },
                            },
                            { ...line, key: 'mode-2', at: at(40), tone: 'info', kind: 'mode', group: 'alarm', title: 'House mode Night → Day', sub: 'By schedule', href: '/security-devices/sites/4?tab=modes' },
                        ],
                        total: 2,
                        overnight: {
                            from: at(900),
                            to: at(300),
                            sites: [],
                            rows: [{ ...line, key: 'scan-3', at: at(400), tone: 'success', kind: 'device', title: 'Scan of Synthetic House finished', sub: '12 found', href: '/security-devices/scans/3' }],
                        },
                        by_kind: [
                            { key: 'devices', label: 'Devices and scans', count: 1 },
                            { key: 'alarm', label: 'Alarm and modes', count: 1 },
                            { key: 'alerts', label: 'Alerts', count: 1 },
                            { key: 'doors', label: 'Doors worth a look', count: 0 },
                            { key: 'planned', label: 'Planned work', count: 0 },
                            { key: 'hs', label: 'H&S', count: 0 },
                        ],
                        doors: false,
                    },
                })}
            />,
        );

        expect(screen.getByRole('tab', { name: 'Activity' })).toBeInTheDocument();
        expect(screen.getByText('Synthetic router is offline')).toBeInTheDocument();
        expect(screen.getByText('Nobody was told · still open, not acknowledged')).toBeInTheDocument();
        const rows = screen.getAllByRole('row');
        expect(rows.findIndex((row) => row.textContent?.includes('Synthetic router is offline'))).toBeLessThan(
            rows.findIndex((row) => row.textContent?.includes('House mode Night → Day')),
        );
        expect(screen.getByText('Overnight')).toBeInTheDocument();
        expect(screen.getByText('Scan of Synthetic House finished')).toBeInTheDocument();
        expect(screen.getByText('What’s in this stream')).toBeInTheDocument();
        expect(screen.getByText(/residents are never named/)).toBeInTheDocument();
        // Filters: Site, kind and day; Export… is the events CSV.
        expect(screen.getByText('Every kind')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Day: Today' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Export/ })).toHaveAttribute('href', '/security-devices/exports/events.csv');
        expect(screen.queryByText(/doors worth a look,/)).not.toBeInTheDocument();
    });

    it('says plainly when there is no history yet on Trends', () => {
        render(
            <Overview
                {...fixture({
                    tab: 'trends',
                    overview: null,
                    trends: { online: null, kinds: [], device_count: 0, alerts_per_week: null, batteries: [], slowest: [] },
                })}
            />,
        );

        expect(screen.getByText('No device is checked yet, so there’s no history to show.')).toBeInTheDocument();
        expect(screen.getByText('No battery is at 35% or below.')).toBeInTheDocument();
    });
});
