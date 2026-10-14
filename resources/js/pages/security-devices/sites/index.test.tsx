import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SiteTechnologyIndex, {
    type SiteTechnologyItem,
    type SiteTechnologyProps,
} from './index';
const navigation = vi.hoisted(() => ({ visit: vi.fn(), actorId: 7 }));
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({
            props: {
                auth: {
                    user: { id: navigation.actorId },
                    can: { 'securityDevices.devices.view': true },
                },
            },
        }),
        router: { ...actual.router, visit: navigation.visit },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
const baseSite: SiteTechnologyItem = {
    id: 4,
    name: 'Kauri House',
    type: 'supported_living',
    city: 'Auckland',
    is_active: true,
    health: 'warning',
    devices: 4,
    attention_devices: 1,
    offline_devices: 1,
    monitored_devices: 3,
    unmonitored_devices: 1,
    coverage_percent: 75,
    failed_monitors: 2,
    active_findings: 3,
    active_control_room_alerts: null,
    open_it_work: null,
    overdue_maintenance: 1,
    collector: {
        state: 'stale',
        label: 'Site collector',
        count: 1,
        last_seen_at: '2026-10-11T01:00:00Z',
    },
    last_change_at: '2026-10-10T01:00:00Z',
    requires_action: true,
    href: '/security-devices/sites/4',
};
function fixture(): SiteTechnologyProps {
    return {
        sites: [
            structuredClone(baseSite),
            {
                ...structuredClone(baseSite),
                id: 5,
                name: 'Totara House',
                city: 'Hamilton',
                health: 'unknown',
                devices: 0,
                monitored_devices: 0,
                attention_devices: 0,
                offline_devices: 0,
                unmonitored_devices: 0,
                failed_monitors: 0,
                active_findings: 0,
                overdue_maintenance: 0,
                coverage_percent: null,
                open_it_work: 0,
                active_control_room_alerts: 0,
                requires_action: false,
                href: '/security-devices/sites/5',
            },
            {
                ...structuredClone(baseSite),
                id: 6,
                name: 'Rimu House',
                health: 'healthy',
                attention_devices: 0,
                offline_devices: 0,
                monitored_devices: 4,
                unmonitored_devices: 0,
                coverage_percent: 100,
                failed_monitors: 0,
                active_findings: 0,
                overdue_maintenance: 0,
                open_it_work: 0,
                active_control_room_alerts: 0,
                requires_action: false,
                href: '/security-devices/sites/6',
            },
        ],
        summary: {
            total: 3,
            with_devices: 2,
            requiring_attention: 1,
            unknown: 1,
        },
    };
}
afterEach(cleanup);
beforeEach(() => {
    navigation.visit.mockReset();
    navigation.actorId = 7;
});
describe('Site technology list', () => {
    it('keeps restricted work distinct from zero and preserves monitoring meaning in both layouts', () => {
        render(<SiteTechnologyIndex {...fixture()} />);
        const card = screen.getByRole('article', { name: 'Kauri House' });
        expect(within(card).getAllByText('Restricted')).toHaveLength(2);
        expect(within(card).getByText(/75% monitored/)).toBeInTheDocument();
        expect(
            within(
                screen.getByRole('article', { name: 'Totara House' }),
            ).getByText(/Not measured/),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        const table = screen.getByRole('table');
        expect(within(table).getAllByText('Restricted')).toHaveLength(1);
        expect(within(table).getByText('Not measured')).toBeInTheDocument();
        expect(within(table).getByText('75% monitored')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Kauri House',
            }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: /^Findings and work/ }),
        );
        expect(
            within(screen.getByRole('dialog')).getAllByText('Restricted'),
        ).toHaveLength(2);
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Totara House',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: /^Monitoring/ }));
        expect(
            within(screen.getByRole('dialog')).getByText('0 of 0'),
        ).toBeInTheDocument();
    });
    it('clears unrelated search when a summary meter selects its owner-defined records', () => {
        render(<SiteTechnologyIndex {...fixture()} />);
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search Site technology' }),
            { target: { value: 'Hamilton' } },
        );
        expect(
            screen.queryByRole('article', { name: 'Kauri House' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'View need attention' }),
        );
        expect(
            screen.getByRole('searchbox', { name: 'Search Site technology' }),
        ).toHaveValue('');
        expect(
            screen.getByRole('article', { name: 'Kauri House' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('article', { name: 'Totara House' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'View unknown health' }),
        );
        expect(
            screen.getByRole('article', { name: 'Totara House' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('article', { name: 'Rimu House' }),
        ).not.toBeInTheDocument();
    });
    it('selects a shared rail view and returns to all Sites without losing table preference', () => {
        render(<SiteTechnologyIndex {...fixture()} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        fireEvent.click(screen.getByRole('tab', { name: /Unknown health/ }));
        expect(
            screen.getByRole('link', { name: 'Totara House' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Kauri House' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('tab', { name: /All Sites/ }));
        expect(screen.getByRole('table')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Kauri House' }),
        ).toBeInTheDocument();
    });
    it('keeps local search and the supplied record link while switching layouts', () => {
        render(<SiteTechnologyIndex {...fixture()} />);
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search Site technology' }),
            { target: { value: 'Hamilton' } },
        );
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(
            screen.getByRole('searchbox', { name: 'Search Site technology' }),
        ).toHaveValue('Hamilton');
        expect(
            screen.getByRole('link', { name: 'Totara House' }),
        ).toHaveAttribute('href', '/security-devices/sites/5');
        expect(
            screen.queryByRole('link', { name: 'Kauri House' }),
        ).not.toBeInTheDocument();
    });
    it('uses the same permitted opening action for the menu and context menu', async () => {
        render(<SiteTechnologyIndex {...fixture()} />);
        fireEvent.keyDown(
            screen.getByRole('button', { name: 'Actions for Kauri House' }),
            { key: 'Enter' },
        );
        fireEvent.click(
            await screen.findByRole('menuitem', {
                name: 'Open Site technology',
            }),
        );
        expect(navigation.visit).toHaveBeenLastCalledWith(
            '/security-devices/sites/4',
        );
        fireEvent.contextMenu(
            screen.getByRole('article', { name: 'Kauri House' })
                .firstElementChild!,
            { clientX: 40, clientY: 50 },
        );
        fireEvent.click(
            await screen.findByRole('menuitem', {
                name: 'Open Site technology',
            }),
        );
        expect(navigation.visit).toHaveBeenCalledTimes(2);
    });
    it('clears old search and private context when actor or supplied Site records change', async () => {
        const data = fixture();
        const result = render(<SiteTechnologyIndex {...data} />);
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search Site technology' }),
            { target: { value: 'Kauri' } },
        );
        fireEvent.contextMenu(
            screen.getByRole('article', { name: 'Kauri House' })
                .firstElementChild!,
            { clientX: 40, clientY: 50 },
        );
        expect(
            await screen.findByRole('menuitem', {
                name: 'Open Site technology',
            }),
        ).toBeInTheDocument();
        navigation.actorId = 8;
        result.rerender(
            <SiteTechnologyIndex
                sites={[data.sites[1]]}
                summary={{
                    total: 1,
                    with_devices: 0,
                    requiring_attention: 0,
                    unknown: 1,
                }}
            />,
        );
        expect(
            screen.getByRole('searchbox', { name: 'Search Site technology' }),
        ).toHaveValue('');
        expect(
            screen.queryByRole('article', { name: 'Kauri House' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('menuitem', { name: 'Open Site technology' }),
        ).not.toBeInTheDocument();
    });
    it('returns keyboard focus after reading the complete Site evidence without navigating', async () => {
        render(<SiteTechnologyIndex {...fixture()} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        const trigger = screen.getByRole('button', {
            name: 'View evidence for Kauri House',
        });
        fireEvent.click(trigger);
        fireEvent.click(screen.getByRole('button', { name: /^Device checks/ }));
        expect(
            within(screen.getByRole('dialog')).getByText('Failed checks:'),
        ).toBeInTheDocument();
        expect(navigation.visit).not.toHaveBeenCalled();
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        await waitFor(() => expect(trigger).toHaveFocus());
        expect(navigation.visit).not.toHaveBeenCalled();
    });
    it('removes an open evidence view when the approved Site records change', () => {
        const data = fixture();
        const result = render(<SiteTechnologyIndex {...data} />);
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Kauri House',
            }),
        );
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        navigation.actorId = 8;
        result.rerender(
            <SiteTechnologyIndex
                sites={[data.sites[1]]}
                summary={{
                    total: 1,
                    with_devices: 0,
                    requiring_attention: 0,
                    unknown: 1,
                }}
            />,
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Kauri House' }),
        ).not.toBeInTheDocument();
    });
    it('keeps denied monitoring and collector details distinct from no measurements', () => {
        const data = fixture();
        data.sites[0].monitored_devices = null;
        data.sites[0].unmonitored_devices = null;
        data.sites[0].coverage_percent = null;
        render(<SiteTechnologyIndex {...data} />);
        expect(
            within(
                screen.getByRole('article', { name: 'Kauri House' }),
            ).getByText('Monitoring permission required'),
        ).toBeInTheDocument();
        expect(
            within(
                screen.getByRole('article', { name: 'Kauri House' }),
            ).queryByText(/Not measured/),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View evidence for Kauri House',
            }),
        );
        fireEvent.click(screen.getByRole('button', { name: /^Monitoring/ }));
        expect(
            within(screen.getByRole('dialog')).getByText('Restricted'),
        ).toBeInTheDocument();
        expect(
            within(screen.getByRole('dialog')).queryByText('Not measured'),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /^Collection/ }));
        expect(
            within(screen.getByRole('dialog')).getByText(
                'Monitoring permission required',
            ),
        ).toBeInTheDocument();
    });
    it('keeps an empty approved scope distinct from a search with no matches', () => {
        const result = render(
            <SiteTechnologyIndex
                sites={[]}
                summary={{
                    total: 0,
                    with_devices: 0,
                    requiring_attention: 0,
                    unknown: 0,
                }}
            />,
        );
        expect(
            screen.getByRole('heading', { name: 'No accessible Sites' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /add|register/i }),
        ).not.toBeInTheDocument();
        result.rerender(<SiteTechnologyIndex {...fixture()} />);
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search Site technology' }),
            { target: { value: 'no match' } },
        );
        expect(
            screen.getByRole('heading', { name: 'No results found' }),
        ).toBeInTheDocument();
    });
});
