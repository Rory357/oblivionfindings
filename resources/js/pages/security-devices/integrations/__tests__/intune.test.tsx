import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import IntuneIntegrationPage, { type IntuneIntegrationProps } from '../intune';

const routerPost = vi.fn();
const routerDelete = vi.fn();
const routerVisit = vi.fn();
const formPost = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    router: {
        post: (...args: unknown[]) => routerPost(...args),
        delete: (...args: unknown[]) => routerDelete(...args),
        visit: (...args: unknown[]) => routerVisit(...args),
    },
    usePage: () => ({
        props: {
            flash: {},
            auth: {
                can: {
                    securityDevices: {
                        integrationsView: true,
                        integrationsManage: true,
                        commandsAdmin: true,
                    },
                },
            },
        },
    }),
    useForm: (initial: Record<string, string>) => ({
        data: initial,
        errors: {},
        processing: false,
        setData: vi.fn(),
        reset: vi.fn(),
        post: (...args: unknown[]) => formPost(...args),
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => (
        <div data-testid="app-layout">{children}</div>
    ),
}));

const sampleProps: IntuneIntegrationProps = {
    actor_id: 1,
    as_of: '2026-10-29T01:00:00Z',
    providerConnection: {
        status: 'connected',
        secret_last4: '9821',
        last_tested_at: '2026-10-29T00:50:00Z',
        last_synced_at: '2026-10-29T00:55:00Z',
        directory_id: '11111111-1111-4111-8111-111111111111',
        client_id: '22222222-2222-4222-8222-222222222222',
        directory_id_configured: true,
        client_id_configured: true,
        endpoint_configured: true,
        discovered_at: '2026-10-29T00:55:00Z',
    },
    discoveredDevices: [
        {
            managed_device_id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            device_name: 'KAURI-OFFICE-01',
            operating_system: 'Windows',
            os_version: '10.0.22631.4317',
            compliance_state: 'compliant',
            is_encrypted: true,
            manufacturer: 'Dell Inc.',
            model: 'OptiPlex 7010',
            serial_number: 'DL7010NZ',
            owner_type: 'company',
            last_sync_at: '2026-10-29T00:45:00Z',
            link_state: 'unlinked',
            linked_device: null,
        },
        {
            managed_device_id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            device_name: 'TOTARA-NURSE-PC',
            operating_system: 'Windows',
            os_version: '10.0.22631.4317',
            compliance_state: 'noncompliant',
            is_encrypted: false,
            manufacturer: 'Lenovo',
            model: 'ThinkPad T14',
            serial_number: 'LNV14NZ',
            owner_type: 'company',
            last_sync_at: '2026-10-29T00:40:00Z',
            link_state: 'linked',
            linked_device: {
                id: 88,
                name: 'TOTARA-NURSE-PC',
                href: '/security-devices/devices/88',
                site_name: 'Totara House',
            },
        },
    ],
    linkedDevicesCount: 1,
    sites: [
        { id: 10, name: 'Kauri House', type: 'residential' },
        { id: 20, name: 'Totara House', type: 'residential' },
    ],
    syncLogs: [
        {
            id: 5,
            action: 'discover_devices',
            status: 'success',
            items_processed: 2,
            items_created: 2,
            items_updated: 0,
            items_errored: 0,
            failure_category: null,
            started_at: '2026-10-29T00:54:00Z',
            completed_at: '2026-10-29T00:55:00Z',
        },
    ],
    can: {
        manage: true,
        import_devices: true,
    },
};

describe('IntuneIntegrationPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerPost.mockImplementation(
            (
                _url: unknown,
                _data: unknown,
                options?: { onFinish?: () => void },
            ) => {
                options?.onFinish?.();
            },
        );
        routerDelete.mockImplementation(
            (_url: unknown, options?: { onFinish?: () => void }) => {
                options?.onFinish?.();
            },
        );
    });

    afterEach(() => {
        cleanup();
    });

    it('renders Microsoft directory credentials, discovered Windows computers, and bulk Site import actions', () => {
        render(<IntuneIntegrationPage {...sampleProps} />);

        expect(screen.getByLabelText('Microsoft directory ID')).toBeTruthy();
        expect(screen.getByLabelText('Application (client) ID')).toBeTruthy();
        expect(screen.getByLabelText('Client secret')).toBeTruthy();

        expect(screen.getByText('KAURI-OFFICE-01')).toBeTruthy();
        expect(screen.getAllByText('TOTARA-NURSE-PC').length).toBeGreaterThan(
            0,
        );
        expect(screen.getByText('Totara House')).toBeTruthy();
        expect(screen.getByText('Compliant')).toBeTruthy();
        expect(screen.getByText('Non-compliant')).toBeTruthy();

        fireEvent.click(
            screen.getByRole('button', { name: 'Select all ready (1)' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Import selected (1)' }),
        );

        expect(routerPost).toHaveBeenCalledWith(
            '/security-devices/integrations/intune/import',
            {
                site_id: 10,
                managed_device_ids: ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'],
            },
            expect.any(Object),
        );
    });

    it('triggers Windows computer discovery and opens ConfirmDialog before removing credentials', () => {
        render(<IntuneIntegrationPage {...sampleProps} />);

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Discover Windows computers',
            }),
        );
        expect(routerPost).toHaveBeenCalledWith(
            '/security-devices/integrations/intune/discover',
            {},
            expect.any(Object),
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Remove credentials' }),
        );
        expect(
            screen.getByText('Remove Microsoft Intune credentials?'),
        ).toBeTruthy();
    });
});
