import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SiteShow, { type SitePageProps } from './show';

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

function fixture(overrides: Partial<SitePageProps> = {}): SitePageProps {
    return {
        site: { id: 4, name: 'Synthetic House', address: '1 Test Street, Testville' },
        tab: 'overview',
        flag: { tone: 'success', text: 'All online' },
        hub: { label: 'Synthetic House hub', own: true, answering: true, version: '1.0.0', seen_at: new Date().toISOString() },
        meters: {
            online: { count: 3, of: 3, caption: { text: 'Checked {ago}', at: new Date(Date.now() - 60_000).toISOString() } },
            alerts: { count: 0, caption: 'None open' },
            scan: null,
        },
        overview: {
            states: [{ state: 'online', count: 3 }],
            cameras: [{ id: 9, name: 'Synthetic front door camera', state: 'online', href: '/security-devices/devices/9' }],
            camera_count: 1,
            week: { values: [100, null, 99.2, 100, 100, 100, 100], labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], note: 'Lowest on Wed, when 99.2% of checks answered.' },
            hub_down: null,
        },
        network: null,
        internet: null,
        can: { add_device: true, assign: true, scan: true },
        ...overrides,
    };
}

afterEach(() => {
    cleanup();
});

describe('Site page', () => {
    it('shows the Site, how it is watched, and its overview, with the Rooms and Modes tabs (Phase F)', () => {
        render(<SiteShow {...fixture()} />);

        expect(screen.getByRole('heading', { name: 'Synthetic House' })).toBeInTheDocument();
        expect(screen.getByText(/its own hub is Synthetic House hub/)).toBeInTheDocument();
        expect(screen.getByText('Devices at Synthetic House')).toBeInTheDocument();
        expect(screen.getByText('Synthetic front door camera')).toBeInTheDocument();
        expect(screen.getByText('Lowest on Wed, when 99.2% of checks answered.')).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Rooms' })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Modes' })).toBeInTheDocument();
        // The Devices, Alerts and Logbook tabs wait for their drawings' approval.
        expect(screen.queryByRole('tab', { name: /devices|alerts|logbook/i })).not.toBeInTheDocument();
        // No modes and no switch permission: no Change mode.
        expect(screen.queryByText(/change mode/i)).not.toBeInTheDocument();
        expect(screen.getByText('No house modes yet')).toBeInTheDocument();
    });

    it('says plainly what still works when the hub stops answering', () => {
        render(
            <SiteShow
                {...fixture({
                    hub: { label: 'Synthetic House hub', own: true, answering: false, version: null, seen_at: new Date(Date.now() - 3_600_000).toISOString() },
                    overview: {
                        states: [{ state: 'cant_check', count: 2 }],
                        cameras: [],
                        camera_count: 0,
                        week: null,
                        hub_down: { hub: 'Synthetic House hub', seen_at: new Date(Date.now() - 3_600_000).toISOString(), cant_check: 2, still_seen: [] },
                    },
                })}
            />,
        );

        expect(screen.getByText('Synthetic House hub has stopped answering')).toBeInTheDocument();
        expect(screen.getByText(/2 devices it watches can’t be checked until it’s back/)).toBeInTheDocument();
        expect(screen.getByText('No cameras at this Site.')).toBeInTheDocument();
    });

    it('is honest about what it doesn’t know about the internet line', () => {
        render(<SiteShow {...fixture({ tab: 'internet', overview: null, internet: { gateways: [], outages: [] } })} />);

        expect(screen.getByText('No router or gateway is added for this Site yet.')).toBeInTheDocument();
        expect(screen.getByText('Which internet line the Site uses and whether it has a backup line.')).toBeInTheDocument();
    });
});
