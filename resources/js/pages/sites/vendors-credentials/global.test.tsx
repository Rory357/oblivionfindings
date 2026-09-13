import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

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
            props: { auth: { user: { name: 'Rangi Morgan' } } },
        }),
        router: {
            patch: vi.fn(),
            post: vi.fn(),
            delete: vi.fn(),
            visit: vi.fn(),
            reload: vi.fn(),
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
    afterEach(() => vi.unstubAllGlobals());
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
        expect(screen.getByText('Select a site…')).toBeInTheDocument();
    });

    it('opens the read-only vendor detail dialog from a row', async () => {
        render(<GlobalVendorsCredentials {...baseProps} />);

        fireEvent.click(screen.getByText('Capital Plumbing & Gas'));

        // Read-first detail dialog shows contact affordances + Edit action.
        expect(await screen.findByText('Call now')).toBeInTheDocument();
        expect(screen.getByText('Preferred method')).toBeInTheDocument();
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
        fireEvent.click(screen.getByText('Front Door Smart Lock'));
        expect(
            screen.getByRole('button', { name: 'Re-authenticate & reveal' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Reveal history' }));
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
        fireEvent.click(screen.getByText('Front Door Smart Lock'));
        expect(
            screen.queryByRole('button', { name: 'Reveal history' }),
        ).not.toBeInTheDocument();
    });
});
