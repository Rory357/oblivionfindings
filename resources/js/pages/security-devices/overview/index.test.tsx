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
