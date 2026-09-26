import {
    AppSidebar,
    buildNavSearchCatalog,
    isSubItemActive,
} from '@/components/app-sidebar';
import type { FleetNavigationPermissions } from '@/lib/fleet-navigation';
import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { FleetWorkspaceNavigation } from './fleet-workspace-navigation';

const fixture = vi.hoisted(() => ({
    url: '/fleet-assets/vehicles',
    can: {} as FleetNavigationPermissions,
}));
vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        Link: React.forwardRef<
            HTMLAnchorElement,
            React.AnchorHTMLAttributes<HTMLAnchorElement> & {
                prefetch?: boolean;
                preserveScroll?: boolean;
            }
        >(
            (
                {
                    children,
                    prefetch: _prefetch,
                    preserveScroll: _preserveScroll,
                    ...props
                },
                ref,
            ) => (
                <a ref={ref} {...props}>
                    {children}
                </a>
            ),
        ),
        usePage: () => ({
            url: fixture.url,
            props: {
                auth: {
                    user: { id: 42, name: 'Navigation tester', role: 'Admin' },
                    can: fixture.can,
                },
                sidebarOpen: true,
            },
        }),
    };
});

const fullAccess: FleetNavigationPermissions = {
    fleet: { viewAny: true },
    assets: {
        viewAny: true,
        alertsView: true,
        telemetryView: true,
        trackersManage: true,
        geofencesManage: true,
    },
    hr: { assets: { view: true }, driver: { view: true } },
    clients: { viewAny: true },
    controlRoom: { viewAny: true },
    securityDevices: { devicesView: true },
};

beforeEach(() => {
    fixture.can = fullAccess;
    fixture.url = '/fleet-assets/vehicles';
    window.localStorage.clear();
});
afterEach(cleanup);

describe('Fleet workspace navigation', () => {
    it('renders exactly seven primary links and selects Fleet on a filtered nested vehicle URL', () => {
        fixture.url =
            '/fleet-assets/vehicles/42?group=operations&view=calendar#day';
        render(<AppSidebar collapsed={false} />);
        const menu = within(
            screen.getByRole('group', { name: 'Fleet & Assets navigation' }),
        );
        expect(
            menu.getAllByRole('link').map((item) => item.textContent),
        ).toEqual([
            'Overview',
            'Fleet',
            'Assets',
            'Maintenance',
            'Maps & boundaries',
            'Reports',
            'Settings',
        ]);
        expect(
            menu.getByRole('link', { name: 'Fleet', exact: true }),
        ).toHaveAttribute('aria-current', 'page');
        expect(
            menu
                .getAllByRole('link')
                .filter((item) => item.getAttribute('aria-current') === 'page'),
        ).toHaveLength(1);
        expect(
            menu.queryByRole('link', { name: 'Trips' }),
        ).not.toBeInTheDocument();
    });

    it.each([
        ['/fleet-assets/trips/12/playback?site_id=7', '/fleet-assets/vehicles'],
        ['/fleet-assets/transports/12/pre-check', '/fleet-assets/vehicles'],
        ['/fleet-assets/handovers/12', '/fleet-assets/vehicles'],
        ['/fleet-assets/assets/12?tab=documents', '/fleet-assets/assets'],
        [
            '/fleet-assets/maintenance/checklists/runs/12',
            '/fleet-assets/maintenance/work-orders',
        ],
        [
            '/fleet-assets/inspections/12',
            '/fleet-assets/maintenance/work-orders',
        ],
        ['/fleet-assets/daily-check', '/fleet-assets/maintenance/work-orders'],
        [
            '/fleet-assets/resident-tracking/history/12?tab=wandering',
            '/fleet-assets/map',
        ],
        ['/fleet-assets/devices/12', '/fleet-assets/map'],
        ['/fleet-assets/geofences?edit=12', '/fleet-assets/map'],
        ['/fleet-assets/mileage?status=pending', '/fleet-assets/reports'],
        ['/fleet-assets/reports/cost-allocation', '/fleet-assets/reports'],
        ['/fleet-assets/incidents?incident=12', '/fleet-assets'],
        [
            '/fleet-assets/settings/notifications',
            '/fleet-assets/settings/notifications',
        ],
    ])(
        'keeps %s in its workspace without selecting the hub root',
        (url, href) => {
            expect(isSubItemActive(url, href)).toBe(true);
            if (href !== '/fleet-assets')
                expect(isSubItemActive(url, '/fleet-assets')).toBe(false);
        },
    );

    it('does not hijack other modules or similarly prefixed paths', () => {
        expect(isSubItemActive('/hr/assets/4', '/hr/assets')).toBe(true);
        expect(
            isSubItemActive(
                '/fleet-assets/vehicles-archive',
                '/fleet-assets/vehicles',
            ),
        ).toBe(false);
        fixture.url = '/my-day';
        render(<FleetWorkspaceNavigation />);
        expect(screen.queryByRole('navigation')).toBeNull();
    });

    it('offers transport and custody through short menus with canonical links', () => {
        render(<FleetWorkspaceNavigation />);
        const trigger = screen.getByRole('button', { name: 'Transport pages' });
        fireEvent.keyDown(trigger, { key: 'Enter' });
        const menu = within(
            screen.getByRole('menu', { name: 'Transport pages' }),
        );
        expect(menu.getAllByRole('menuitem')).toHaveLength(3);
        expect(
            menu.getByRole('menuitem', { name: 'Medication transit' }),
        ).toHaveAttribute('href', '/fleet-assets/transports/medications');
        expect(menu.getByRole('menuitem', { name: 'Outings' })).toHaveAttribute(
            'href',
            '/fleet-assets/outings',
        );
        fireEvent.keyDown(menu.getByRole('menuitem', { name: 'Outings' }), {
            key: 'Escape',
        });
        expect(screen.queryByRole('menu')).toBeNull();
    });

    it('preserves query and hash on the current operating register and provides the return to Vehicles', () => {
        fixture.url = '/fleet-assets/fuel?site_id=7&page=2#logs';
        render(<FleetWorkspaceNavigation />);
        expect(screen.getByRole('link', { name: 'Vehicles' })).toHaveAttribute(
            'href',
            '/fleet-assets/vehicles',
        );
        fireEvent.keyDown(
            screen.getByRole('button', { name: 'Operating records pages' }),
            { key: 'Enter' },
        );
        expect(
            screen.getByRole('menuitem', { name: 'Fuel logs' }),
        ).toHaveAttribute('href', fixture.url);
        expect(
            screen.getByRole('menuitem', { name: 'Fuel logs' }),
        ).toHaveAttribute('aria-current', 'page');
        expect(screen.getByRole('menuitem', { name: 'Trips' })).toHaveAttribute(
            'href',
            '/fleet-assets/trips',
        );
    });

    it('retains assigned inventory and daily checks without denied administrative links', () => {
        fixture.can = { assets: { viewAssigned: true } };
        fixture.url = '/fleet-assets/assets/4';
        render(<AppSidebar collapsed={false} />);
        const menu = within(
            screen.getByRole('group', { name: 'Fleet & Assets navigation' }),
        );
        expect(
            menu.getByRole('link', { name: 'Assets', exact: true }),
        ).toHaveAttribute('href', '/fleet-assets/assets');
        expect(menu.getByRole('link', { name: 'Maintenance' })).toHaveAttribute(
            'href',
            '/fleet-assets/daily-check',
        );
        expect(
            menu.queryByRole('link', { name: 'Fleet', exact: true }),
        ).toBeNull();
        expect(menu.queryByRole('link', { name: 'Reports' })).toBeNull();
        expect(menu.queryByRole('link', { name: 'Settings' })).toBeNull();
    });

    it('uses permitted landings for asset managers with no Fleet permission', () => {
        fixture.can = { assets: { viewAny: true } };
        render(<AppSidebar collapsed={false} />);
        const menu = within(
            screen.getByRole('group', { name: 'Fleet & Assets navigation' }),
        );
        expect(
            menu.getByRole('link', { name: 'Fleet', exact: true }),
        ).toHaveAttribute('href', '/fleet-assets/bookings');
        expect(menu.getByRole('link', { name: 'Reports' })).toHaveAttribute(
            'href',
            '/fleet-assets/mileage',
        );
        expect(
            buildNavSearchCatalog({ role: 'Staff', can: fixture.can }).some(
                (item) => item.href === '/fleet-assets/reports',
            ),
        ).toBe(false);
    });

    it('requires telemetry permission for the specialist tracking path while retaining device pairing', () => {
        fixture.url = '/fleet-assets/map';
        fixture.can = { fleet: { viewAny: true } };
        const view = render(<FleetWorkspaceNavigation />);
        expect(screen.queryByText('Authorised client tracking')).toBeNull();
        expect(
            screen.getByRole('link', { name: 'Tracking devices & pairing' }),
        ).toHaveAttribute('href', '/fleet-assets/devices');
        fixture.can = { ...fixture.can, assets: { telemetryView: true } };
        view.rerender(<FleetWorkspaceNavigation />);
        expect(
            screen.getByRole('link', { name: 'Authorised client tracking' }),
        ).toHaveAttribute('href', '/fleet-assets/resident-tracking');
    });

    it('keeps relocated tools searchable but gives no Fleet entry to staff without Fleet or asset access', () => {
        const catalog = buildNavSearchCatalog({
            role: 'Admin',
            can: fullAccess,
        });
        const fleetCatalog = catalog.filter(
            (item) => item.section === 'Fleet & Assets',
        );
        expect(new Set(fleetCatalog.map((item) => item.id)).size).toBe(
            fleetCatalog.length,
        );
        expect(catalog.find((item) => item.label === 'Checklists')?.href).toBe(
            '/fleet-assets/maintenance/checklists',
        );
        expect(
            catalog.find((item) => item.label === 'Shift handovers')?.href,
        ).toBe('/fleet-assets/handovers');
        expect(
            catalog.find((item) => item.label === 'HR asset register')?.href,
        ).toBe('/hr/assets');
        fixture.can = {};
        render(<AppSidebar collapsed={false} />);
        expect(
            screen.queryByRole('button', { name: 'Fleet & Assets menu' }),
        ).toBeNull();
        expect(
            buildNavSearchCatalog({ role: 'Support Worker', can: {} }).filter(
                (item) => item.section === 'Fleet & Assets',
            ),
        ).toEqual([]);
    });
});
