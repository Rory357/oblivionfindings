import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { AnchorHTMLAttributes } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ConnectedServicesMenu } from './_entry-points';
const state = vi.hoisted(() => ({ can: {} as Record<string, boolean> }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { can: { medications: state.can } } } }),
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
}));
afterEach(cleanup);
beforeEach(() => {
    state.can = {};
});
describe('connected service shortcuts', () => {
    it('does not offer management routes without access', () => {
        render(<ConnectedServicesMenu clientId={16} />);
        expect(
            screen.queryByRole('button', { name: /Connected services/ }),
        ).not.toBeInTheDocument();
    });
    it('takes an order reviewer straight to requests for the current person', () => {
        state.can = { ordersManage: true };
        render(<ConnectedServicesMenu clientId={16} />);
        fireEvent.pointerDown(
            screen.getByRole('button', { name: /Connected services/ }),
            { button: 0, ctrlKey: false },
        );
        expect(
            screen.getByRole('menuitem', { name: 'Prescriber requests' }),
        ).toHaveAttribute('href', '/emar/connected-care?client_id=16#requests');
        expect(
            screen.queryByRole('menuitem', { name: 'Prescriber access' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('menuitem', { name: 'Provider handovers' }),
        ).not.toBeInTheDocument();
    });
    it('shows the permitted services and never implies a person scope for global configuration', () => {
        state.can = {
            externalManage: true,
            transfersManage: true,
            pharmacyConnectManage: true,
            catalogueManage: true,
            reportsView: true,
            reportsExport: true,
        };
        render(<ConnectedServicesMenu clientId={16} />);
        fireEvent.pointerDown(
            screen.getByRole('button', { name: /Connected services/ }),
            { button: 0, ctrlKey: false },
        );
        expect(
            screen.getByRole('menuitem', { name: 'Provider handovers' }),
        ).toHaveAttribute(
            'href',
            '/emar/connected-care?client_id=16#transfers',
        );
        expect(
            screen.getByRole('menuitem', { name: 'Pharmacy connections' }),
        ).toHaveAttribute('href', '/emar/pharmacy-connections');
        expect(
            screen.getByRole('menuitem', { name: 'Medicine picture library' }),
        ).toHaveAttribute('href', '/emar/catalogue');
        expect(
            screen.getByRole('menuitem', { name: 'Protected chart backups' }),
        ).toHaveAttribute('href', '/emar/backups');
    });
});
