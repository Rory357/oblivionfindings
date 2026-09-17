import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

const inertiaLocation = vi.hoisted(() => ({ url: '/vendors' }));

// Mock the app shell so the page renders without the full sidebar/layout tree.
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
}));

// Minimal @inertiajs/react surface used by the page + dialogs.
vi.mock('@inertiajs/react', async () => {
    const ReactActual = await vi.importActual<typeof import('react')>('react');
    return {
        Head: () => null,
        Link: ({ children, ...rest }: { children: React.ReactNode }) => (
            <a {...rest}>{children}</a>
        ),
        usePage: () => ({
            url: inertiaLocation.url,
            props: { auth: { user: { name: 'Rangi Morgan' } } },
        }),
        router: {
            patch: vi.fn(),
            post: vi.fn(),
            delete: vi.fn(),
            visit: vi.fn(),
            reload: vi.fn(),
            replace: vi.fn(),
            on: vi.fn(() => vi.fn()),
        },
        useForm: (initial: Record<string, unknown>) => {
            const [data, setData] = ReactActual.useState(initial);
            const form = {
                data,
                errors: {} as Record<string, string>,
                processing: false,
                setData: (key: string, value: unknown) =>
                    setData((current) => ({ ...current, [key]: value })),
                transform: () => form,
                post: (_url: string, opts?: { onSuccess?: () => void }) =>
                    opts?.onSuccess?.(),
                put: (_url: string, opts?: { onSuccess?: () => void }) =>
                    opts?.onSuccess?.(),
            };
            return form;
        },
    };
});

import { router } from '@inertiajs/react';
import { AuditLogDialog } from './_audit-dialog';
import GlobalVendorsCredentials from './global';

const sites = [
    { id: 1, name: 'Te Whare — Hamilton', type: 'house' },
    { id: 2, name: 'Head Office', type: 'head_office' },
];

const vendors = [
    {
        id: 10,
        site_id: 1,
        site_name: 'Te Whare — Hamilton',
        site_type: 'house',
        service_type: 'Plumbing',
        company_name: 'Capital Plumbing & Gas',
        contact_name: 'Jo Tāne',
        phone: '+64 21 555 0100',
        after_hours_phone: '',
        email: 'jobs@capital.co.nz',
        account_number: '',
        notes: '',
        preferred_contact_method: 'phone' as const,
        is_preferred: true,
        is_active: true,
    },
];

const credentials = [
    {
        id: 20,
        site_id: 1,
        site_name: 'Te Whare — Hamilton',
        site_type: 'house',
        label: 'Front Door Smart Lock',
        credential_type: 'pin',
        username: '',
        url: '',
        notes: '',
        vendor_id: null,
        vendor_name: null,
        requires_reauth: true,
        is_shareable: false,
        password_strength: 3,
        has_totp: true,
        last_rotated_at: '2026-01-02T00:00:00Z',
    },
];

const baseProps = {
    vendors,
    credentials,
    sites,
    serviceTypes: ['Plumbing'],
    credentialTypes: ['pin', 'password'],
    credentialTypeOptions: [
        {
            key: 'password',
            label: 'Password',
            description: 'Username + secret',
            icon: 'lock',
        },
        {
            key: 'pin',
            label: 'PIN / Code',
            description: 'Door, alarm, panel',
            icon: 'fingerprint',
        },
    ],
    filters: {},
    can: {
        vendors: true,
        credentials: true,
        vendorsManage: true,
        credentialsManage: true,
        credentialsReveal: true,
        credentialsAudit: true,
        manageCredentialTypes: true,
    },
};

describe('GlobalVendorsCredentials', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.clearAllMocks();
        inertiaLocation.url = '/vendors';
    });
    it('keeps sidebar URLs and restored tab state aligned while retaining site and runbook context', () => {
        inertiaLocation.url =
            '/vendors?tab=vendors&site_id=1&return_to=%2Fit%2Fknowledge%2F9&credential_id=20';
        render(<GlobalVendorsCredentials {...baseProps} />);
        fireEvent.click(screen.getByRole('tab', { name: /Credentials/ }));
        const credentialsVisit = vi
            .mocked(router.replace)
            .mock.calls.at(-1)![0];
        const credentialsUrl = new URL(
            credentialsVisit.url!,
            'http://local.invalid',
        );
        expect(credentialsUrl.searchParams.get('tab')).toBe('credentials');
        expect(credentialsUrl.searchParams.get('site_id')).toBe('1');
        expect(credentialsUrl.searchParams.get('return_to')).toBe(
            '/it/knowledge/9',
        );
        expect(credentialsUrl.searchParams.get('credential_id')).toBe('20');
        expect(typeof credentialsVisit.props).toBe('function');
        if (typeof credentialsVisit.props !== 'function')
            throw new Error('Page state update is missing.');
        expect(
            credentialsVisit.props({ filters: { site_id: 1 } }, {}).filters,
        ).toEqual({ site_id: 1, tab: 'credentials' });
        fireEvent.click(screen.getByRole('tab', { name: /^Vendors/ }));
        const vendorsVisit = vi.mocked(router.replace).mock.calls.at(-1)![0];
        expect(
            new URL(vendorsVisit.url!, 'http://local.invalid').searchParams.has(
                'credential_id',
            ),
        ).toBe(false);
        expect(typeof vendorsVisit.props).toBe('function');
        if (typeof vendorsVisit.props !== 'function')
            throw new Error('Page state update is missing.');
        expect(
            vendorsVisit.props(
                { filters: {}, selectedCredential: credentials[0] },
                {},
            ).selectedCredential,
        ).toBeNull();
    });
    it('shows a reported clipboard failure separately from its authorized intent and storage maintenance', async () => {
        const shared = {
            at: new Date().toISOString(),
            actor: { name: 'Synthetic auditor', initials: 'SA' },
            target: 'Synthetic access',
            target_type: 'password',
            site_name: 'Synthetic house',
            ip: '127.0.0.1',
        };
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    logs: [
                        {
                            ...shared,
                            id: 1,
                            action: 'copy_intent',
                            result: 'intent',
                        },
                        {
                            ...shared,
                            id: 2,
                            action: 'copy_reported_failed',
                            result: 'failed',
                        },
                        {
                            ...shared,
                            id: 3,
                            action: 'storage_key_maintenance',
                            result: 'ok',
                        },
                    ],
                }),
            }),
        );
        render(<AuditLogDialog isOpen onClose={vi.fn()} />);
        expect(await screen.findByText('Reported failure')).toBeVisible();
        expect(screen.getByText('Intent recorded')).toBeVisible();
        expect(
            screen.getAllByText('Encryption key maintained').length,
        ).toBeGreaterThan(0);
        expect(screen.getByText('External changes')).toBeVisible();
        expect(
            screen.queryByText('Clipboard success reported', {
                selector: 'td *',
            }),
        ).not.toBeInTheDocument();
    });
    it('renders the hero, health strip and vendor table without crashing', () => {
        render(<GlobalVendorsCredentials {...baseProps} />);

        expect(
            screen.getByRole('heading', { name: 'Vendors & Credentials' }),
        ).toBeInTheDocument();
        // Default (vendors) tab table + row.
        expect(screen.getByText('Service providers')).toBeInTheDocument();
        expect(screen.getByText('Capital Plumbing & Gas')).toBeInTheDocument();
        // Health strip zones.
        expect(screen.getByText('Vault health')).toBeInTheDocument();
        expect(screen.getByText('Rotation due')).toBeInTheDocument();
    });

    it('switches to the credentials tab and shows rotation health', () => {
        render(<GlobalVendorsCredentials {...baseProps} />);

        fireEvent.click(screen.getByRole('tab', { name: /Credentials/ }));

        expect(screen.getByText('Access vault')).toBeInTheDocument();
        expect(screen.getByText('Front Door Smart Lock')).toBeInTheDocument();
        // PIN credential rotated 2026-01-02 is well past the 180d threshold today.
        expect(
            screen.getAllByText(/Rotation overdue|Rotation due/).length,
        ).toBeGreaterThan(0);
    });

    it('opens the Add credential dialog with the tile picker and site picker', async () => {
        render(<GlobalVendorsCredentials {...baseProps} />);

        fireEvent.click(
            screen.getByRole('button', { name: /Add credential/i }),
        );

        // Dialog header + a couple of tile-picker options + the required site picker.
        expect(
            await screen.findByText('Create credential'),
        ).toBeInTheDocument();
        expect(screen.getByText('Password')).toBeInTheDocument();
        expect(screen.getByText('PIN / Code')).toBeInTheDocument();
        expect(screen.getByText('Choose site')).toBeInTheDocument();
    });

    it('opens the canonical vendor detail page from a row', async () => {
        render(<GlobalVendorsCredentials {...baseProps} />);

        fireEvent.click(screen.getByText('Capital Plumbing & Gas'));

        expect(router.visit).toHaveBeenCalledWith('/vendors/10');
    });

    it('offers history to an auditor without reveal rights and conceals it immediately after grant revocation', async () => {
        const fetchAudit = vi
            .fn()
            .mockResolvedValue({ ok: true, json: async () => ({ logs: [] }) });
        vi.stubGlobal('fetch', fetchAudit);
        const auditorCan = {
            ...baseProps.can,
            vendorsManage: false,
            credentialsManage: false,
            credentialsReveal: false,
            credentialsAudit: true,
            manageCredentialTypes: false,
        };
        const { rerender } = render(
            <GlobalVendorsCredentials {...baseProps} can={auditorCan} />,
        );
        fireEvent.click(screen.getByRole('tab', { name: /Credentials/ }));
        fireEvent.contextMenu(screen.getByText('Front Door Smart Lock'));
        expect(
            screen.queryByRole('button', { name: 'Reveal for 30 seconds' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('menuitem', { name: 'Reveal history' }),
        );
        expect(await screen.findByText('Reveal & audit log')).toBeVisible();
        expect(fetchAudit).toHaveBeenCalledWith(
            expect.stringContaining('/vendors/audit'),
            expect.objectContaining({ signal: expect.any(AbortSignal) }),
        );
        rerender(
            <GlobalVendorsCredentials
                {...baseProps}
                can={{ ...auditorCan, credentialsAudit: false }}
            />,
        );
        expect(
            screen.queryByText('Reveal & audit log'),
        ).not.toBeInTheDocument();
        expect(
            (fetchAudit.mock.calls[0][1] as { signal: AbortSignal }).signal
                .aborted,
        ).toBe(true);
    });

    it('does not offer history solely because an actor can reveal credentials', () => {
        render(
            <GlobalVendorsCredentials
                {...baseProps}
                can={{ ...baseProps.can, credentialsAudit: false }}
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: /Credentials/ }));
        fireEvent.contextMenu(screen.getByText('Front Door Smart Lock'));
        expect(
            screen.queryByRole('menuitem', { name: 'Reveal history' }),
        ).not.toBeInTheDocument();
    });
});
