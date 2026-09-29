import type { FleetNavigationPermissions } from '@/lib/fleet-navigation';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { FleetPageMenu } from './fleet-page-menu';

const fixture = vi.hoisted(() => ({
    url: '/fleet-assets/reports',
    can: {} as FleetNavigationPermissions,
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: fixture.url,
        props: { auth: { can: fixture.can } },
    }),
    Link: (props: React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
        <a {...props} />
    ),
    router: {},
}));
afterEach(cleanup);

function openMenu() {
    fireEvent.keyDown(screen.getByRole('button', { name: /^More .* pages$/ }), {
        key: 'Enter',
        code: 'Enter',
    });
}

describe('Fleet hero page menu', () => {
    it('keeps specialist reports reachable for report-only readers without exposing operational links', async () => {
        fixture.url = '/fleet-assets/reports?view=costs';
        fixture.can = { fleet: { reportsView: true } };
        render(<FleetPageMenu />);
        openMenu();
        expect(
            await screen.findByRole('menuitem', {
                name: 'Operating summary & exports',
            }),
        ).toHaveAttribute('href', '/fleet-assets/reports/operating-summary');
        expect(
            screen.getByRole('menuitem', { name: 'Report library' }),
        ).toHaveAttribute('href', '/fleet-assets/reports');
        expect(
            screen.getByRole('menuitem', { name: 'Mileage reimbursement' }),
        ).toHaveAttribute('href', '/fleet-assets/reports/reimbursement');
        expect(
            screen.queryByRole('menuitem', { name: 'Mileage claims' }),
        ).not.toBeInTheDocument();
    });

    it('restores fuel, driver and custody access while retaining the site on the compliance entry', async () => {
        fixture.url = '/fleet-assets/vehicles?site_id=7&search=van';
        fixture.can = { fleet: { viewAny: true } };
        render(<FleetPageMenu />);
        openMenu();
        expect(
            await screen.findByRole('menuitem', { name: 'Fuel logs' }),
        ).toHaveAttribute('href', '/fleet-assets/fuel');
        expect(
            screen.getByRole('menuitem', { name: 'Drivers & eligibility' }),
        ).toHaveAttribute('href', '/fleet-assets/drivers');
        expect(screen.getByRole('menuitem', { name: 'Keys' })).toHaveAttribute(
            'href',
            '/fleet-assets/keys',
        );
        expect(
            screen.getByRole('menuitem', { name: 'Compliance & renewals' }),
        ).toHaveAttribute('href', '/fleet-assets/compliance?site_id=7');
    });

    it('renders nothing outside Fleet or when the workspace has no permitted destinations', () => {
        fixture.url = '/operations/people-location-reports/client';
        fixture.can = { fleet: { viewAny: true } };
        const { rerender } = render(<FleetPageMenu />);
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        fixture.url = '/fleet-assets/reports';
        fixture.can = {};
        rerender(<FleetPageMenu />);
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });
});
