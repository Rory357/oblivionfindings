import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DevicesIndex, { type DevicesIndexProps } from './index';
import { deviceHealthMeridian } from './shared';

const owner = vi.hoisted(() => ({ actorId: 7, get: vi.fn(), visit: vi.fn() }));
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({ props: { auth: { user: { id: owner.actorId } } } }),
        router: { ...actual.router, get: owner.get, visit: owner.visit },
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => children,
}));
vi.mock('@/components/security-devices/add-device-dialog', () => ({
    AddDeviceDialog: () => null,
    useAddDeviceDialogState: () => ({
        open: false,
        openDialog: vi.fn(),
        closeDialog: vi.fn(),
    }),
}));
function fixture(): DevicesIndexProps {
    return {
        devices: {
            data: [
                {
                    id: 41,
                    device_uid: 'synthetic-41',
                    name: 'Synthetic switch',
                    domain: 'it_infrastructure',
                    category: 'networking',
                    subcategory: null,
                    manufacturer: 'Synthetic',
                    model: 'Test',
                    status: 'active',
                    health_status: 'healthy',
                    provider: null,
                    last_seen_at: null,
                    battery_level: null,
                    assigned_to: 'Test Site',
                    assignment_type: 'site',
                    monitoring_state: 'unmonitored',
                },
            ],
            links: [],
            meta: { current_page: 1, last_page: 1, total: 1 },
        },
        stats: { total: 1, active: 1, offline: 0, attention: 0 },
        savedViews: [
            { key: 'all', label: 'All devices', count: 1 },
            { key: 'offline', label: 'Offline', count: 0 },
        ],
        filters: { site_id: '14' },
        filterOptions: {
            domains: [],
            statuses: [],
            healthStatuses: [],
            providers: [],
        },
        can: { create: false, export: true, bulk_select: true },
        exportHref: '/security-devices/reports/devices.csv?format=csv',
        scopeLabel: 'Test Site',
    };
}
beforeEach(() => {
    owner.actorId = 7;
    owner.get.mockReset();
    owner.visit.mockReset();
});
afterEach(cleanup);

describe('Device inventory scope and selection', () => {
    it('preserves Site scope when clearing search and when following a summary count', () => {
        const props = fixture();
        props.filters.search = 'no match';
        render(<DevicesIndex {...props} />);
        fireEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(owner.get).toHaveBeenLastCalledWith(
            '/security-devices/devices',
            { site_id: '14', view: 'all' },
            { preserveState: true },
        );
        fireEvent.click(screen.getByRole('button', { name: 'View offline' }));
        expect(owner.get).toHaveBeenLastCalledWith(
            '/security-devices/devices',
            { site_id: '14', view: 'offline', page: '1' },
            { preserveState: true },
        );
    });
    it('keeps selection across cards and table and preserves existing export query parameters', async () => {
        const props = fixture();
        const view = render(<DevicesIndex {...props} />);
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Select Synthetic switch' }),
        );
        expect(
            screen.getByRole('link', { name: 'Export selected' }),
        ).toHaveAttribute(
            'href',
            '/security-devices/reports/devices.csv?format=csv&ids=41',
        );
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(
            screen.getByRole('checkbox', { name: 'Select Synthetic switch' }),
        ).toBeChecked();
        expect(owner.visit).not.toHaveBeenCalled();
        fireEvent.click(screen.getByText('synthetic-41'));
        expect(owner.visit).toHaveBeenLastCalledWith(
            '/security-devices/devices/41',
        );
        fireEvent.keyDown(
            screen.getByRole('button', {
                name: 'Actions for Synthetic switch',
            }),
            { key: 'Enter' },
        );
        expect(
            await screen.findByRole('menuitem', { name: 'Open device' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('menuitem', { name: 'Deselect device' }),
        ).toBeInTheDocument();
        view.rerender(
            <DevicesIndex
                {...props}
                filters={{ site_id: '14', search: 'switch' }}
            />,
        );
        expect(screen.getByRole('radio', { name: 'Table' })).toBeChecked();
        expect(
            screen.getByRole('checkbox', { name: 'Select Synthetic switch' }),
        ).not.toBeChecked();
        expect(
            screen.getByRole('button', { name: 'Export selected' }),
        ).toBeDisabled();
    });
    it('clears selected export and local search before rendering a changed actor or route scope', () => {
        const props = fixture();
        const view = render(<DevicesIndex {...props} />);
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Select Synthetic switch' }),
        );
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search devices' }),
            { target: { value: 'private local query' } },
        );
        owner.actorId = 8;
        view.rerender(<DevicesIndex {...props} />);
        expect(
            screen.getByRole('button', { name: 'Export selected' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('searchbox', { name: 'Search devices' }),
        ).toHaveValue('');
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Select Synthetic switch' }),
        );
        view.rerender(<DevicesIndex {...props} filters={{ site_id: '15' }} />);
        expect(
            screen.getByRole('button', { name: 'Export selected' }),
        ).toBeDisabled();
    });
    it('removes selection and export entry points after report permission is withdrawn', () => {
        const props = fixture();
        const view = render(<DevicesIndex {...props} />);
        fireEvent.click(
            screen.getByRole('checkbox', { name: 'Select Synthetic switch' }),
        );
        view.rerender(
            <DevicesIndex
                {...props}
                can={{ create: false, export: false, bulk_select: true }}
            />,
        );
        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(screen.queryByText('Export selected')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Export inventory' }),
        ).not.toBeInTheDocument();
    });
    it('keeps unknown health neutral even when the equipment lifecycle says active', () => {
        const device = fixture().devices.data[0];
        expect(
            deviceHealthMeridian({
                ...device,
                status: 'active',
                health_status: 'unknown',
                monitoring_state: 'unknown',
            }),
        ).toBe('neutral');
        expect(
            deviceHealthMeridian({
                ...device,
                status: 'in_stock',
                health_status: 'critical',
            }),
        ).toBe('critical');
    });
});
