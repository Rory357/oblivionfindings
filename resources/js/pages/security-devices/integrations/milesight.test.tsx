import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import MilesightIntegration, { type MilesightProps } from './milesight';

const mocks = vi.hoisted(() => ({
    actor: 7,
    module: true,
    manage: true,
    post: vi.fn(),
    remove: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    actual.router.post = mocks.post;
    actual.router.delete = mocks.remove;
    actual.router.reload = mocks.reload;
    actual.router.visit = mocks.visit;
    return {
        ...actual,
        Head: () => null,
        usePage: () => ({
            props: {
                auth: {
                    user: mocks.actor ? { id: mocks.actor } : null,
                    can: {
                        securityDevices: {
                            viewAny: mocks.module,
                            integrationsManage: mocks.manage,
                        },
                    },
                },
            },
        }),
    };
});
vi.mock('@/layouts/app-layout', () => ({
    default: ({
        breadcrumbs,
        children,
    }: {
        breadcrumbs: { title: string; href: string }[];
        children: React.ReactNode;
    }) => (
        <>
            <nav aria-label="Breadcrumbs">
                {breadcrumbs.map((crumb) => (
                    <a key={crumb.title} href={crumb.href}>
                        {crumb.title}
                    </a>
                ))}
            </nav>
            {children}
        </>
    ),
}));
const fixture = (): MilesightProps => ({
    actor_id: 7,
    as_of: '2026-10-13T01:02:00Z',
    providerConnection: {
        status: 'connected',
        secret_last4: '1234',
        last_tested_at: '2026-10-01T00:00:00Z',
        last_synced_at: '2026-09-30T00:00:00Z',
        endpoint_configured: true,
        client_id_configured: true,
        applications_synced_at: '2026-09-29T00:00:00Z',
        webhook_configured: true,
        webhook_secret_last4: '9876',
        webhook_url: 'https://oblivion.example.test/webhooks/milesight',
        last_webhook_received_at: '2026-09-28T00:00:00Z',
    },
    discoveredApplications: [
        {
            mapping_token: 'bounded-application-token',
            name: 'North sensors',
            device_count: null,
        },
    ],
    siteConfigs: [
        {
            id: 42,
            site_id: 5,
            site_name: 'North house',
            site_type: 'residential',
            mapped_external_site_name: 'North sensors',
            is_active: true,
        },
        { id: 43, site_id: 5, site_name: 'Inactive mapping', is_active: false },
    ],
    sites: [{ id: 5, name: 'North house', type: 'residential' }],
    syncLogs: [
        {
            id: 99,
            action: 'device_sync',
            status: 'failed',
            items_processed: 12,
            items_created: 3,
            items_updated: 8,
            items_errored: 1,
            failure_category: 'provider_failure',
            started_at: '2026-10-01T00:00:00Z',
            completed_at: '2026-10-01T00:01:00Z',
        },
    ],
    siteCredentials: [
        {
            id: 88,
            site_id: 5,
            site_name: 'North house',
            capability: 'device-sync',
            enabled: true,
            state: 'error',
            failure_category: 'provider_failure',
            last_tested_at: '2026-10-01T00:00:00Z',
        },
    ],
    can: { manage: true },
});
const empty = (): MilesightProps => ({
    ...fixture(),
    providerConnection: null,
    discoveredApplications: [],
    siteConfigs: [],
    sites: [],
    syncLogs: [],
    siteCredentials: [],
});
function tab(name: string) {
    fireEvent.click(screen.getByRole('tab', { name }));
}
function card(name: string) {
    return screen
        .getByText(name, { selector: 'h3' })
        .closest('[role="button"]') as HTMLElement;
}
async function action(name: string, label: string) {
    const record = screen
        .getByText(name, { selector: 'h3' })
        .closest('[role="button"]')!;
    fireEvent.keyDown(
        within(record as HTMLElement).getByRole('button', {
            name: 'Actions for ' + name,
        }),
        { key: 'Enter', code: 'Enter' },
    );
    fireEvent.click(await screen.findByRole('menuitem', { name: label }));
}
function invalid(
    status: number,
    path = '/security-devices/integrations/milesight',
    method = 'get',
) {
    const event = new CustomEvent('inertia:invalid', {
        cancelable: true,
        detail: {
            response: {
                status,
                config: { url: path, method },
                data: { private: 'Private rejection text' },
            },
        },
    });
    act(() => document.dispatchEvent(event));
    return event;
}
function response(data: MilesightProps, success?: string) {
    return {
        component: 'security-devices/integrations/milesight',
        props: {
            ...data,
            auth: {
                user: { id: 7 },
                can: {
                    securityDevices: {
                        viewAny: true,
                        integrationsManage: true,
                    },
                },
            },
            flash: success
                ? { success }
                : { error: 'Secret manager unavailable' },
        },
    };
}
beforeEach(() => {
    mocks.actor = 7;
    mocks.module = true;
    mocks.manage = true;
    mocks.post.mockReset();
    mocks.remove.mockReset();
    mocks.reload.mockReset();
    mocks.visit.mockReset();
});
afterEach(cleanup);
describe('Milesight shared integration workspace', () => {
    it('uses the full Home trail and linked, scoped meters without declaring fresh equipment health', () => {
        render(<MilesightIntegration {...fixture()} />);
        expect(screen.getByRole('link', { name: 'Home' })).toHaveAttribute(
            'href',
            '/dashboard',
        );
        expect(
            screen.getByRole('heading', {
                level: 1,
                name: 'Milesight Integration',
            }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Page read/, {
                selector: '[data-slot="page-header-subline"]',
            }),
        ).toHaveTextContent('13 October 2026');
        expect(
            screen.getByText(/does not establish fresh device readings/),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View discovered applications',
            }),
        );
        expect(
            screen.getByText('Device count not reported'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('0 devices reported'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Active mapping', { exact: true }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'View active site mappings' }),
        );
        expect(screen.getByText('Active mapping')).toBeInTheDocument();
        expect(
            screen.queryByText('Inactive mapping', { selector: 'h3' }),
        ).not.toBeInTheDocument();
    });
    it('offers honest empty states in every returned record view', () => {
        render(<MilesightIntegration {...empty()} />);
        expect(
            screen.getByRole('button', { name: 'Configure credentials' }),
        ).toBeEnabled();
        tab('Applications & Sites');
        expect(
            screen.getByText(
                /No Milesight applications or Site mappings were returned/,
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Discover applications' }),
        ).toBeDisabled();
        tab('Recent activity');
        expect(
            screen.getByText(
                'No connection tests or sync attempts were returned.',
            ),
        ).toBeInTheDocument();
        tab('Site credentials');
        expect(
            screen.getByText(/No Site credential records were returned/),
        ).toBeInTheDocument();
    });
    it('keeps activity counts, diagnostics and timestamps available in the shared reader', () => {
        render(<MilesightIntegration {...fixture()} />);
        fireEvent.click(
            screen.getByRole('button', {
                name: 'View attempts needing attention',
            }),
        );
        expect(screen.getByText(/12 processed/)).toHaveTextContent(
            '3 created · 8 updated',
        );
        fireEvent.click(card('device_sync'));
        const dialog = screen.getByRole('dialog');
        for (const label of [
            'Attempt ID',
            'Status',
            'Processed',
            'Created',
            'Updated',
            'Errored',
            'Failure category',
            'Started',
            'Completed',
        ])
            expect(within(dialog).getByText(label)).toBeInTheDocument();
        expect(
            within(dialog).getByText('provider_failure'),
        ).toBeInTheDocument();
        expect(within(dialog).getByText('99')).toBeInTheDocument();
    });
    it('retains every Site credential field through a reader while reusing its existing safe card', () => {
        render(<MilesightIntegration {...fixture()} />);
        tab('Site credentials');
        fireEvent.click(card('North house'));
        const dialog = screen.getByRole('dialog');
        expect(
            within(dialog).getByText(
                'Safe per-site credential status. Secret values and provider error text are never displayed.',
            ),
        ).toBeInTheDocument();
        for (const label of [
            'Credential ID',
            'Site ID',
            'Capability',
            'Enabled',
            'State',
            'Failure category',
            'Last tested',
        ])
            expect(within(dialog).getByText(label)).toBeInTheDocument();
    });
    it('keeps callback and masked-secret facts separate from live delivery', () => {
        render(<MilesightIntegration {...fixture()} />);
        tab('Webhook');
        expect(screen.getByLabelText('Callback URL')).toHaveAttribute(
            'readonly',
        );
        expect(
            screen.getByText('Signature verification enabled'),
        ).toBeInTheDocument();
        expect(screen.getByText(/Webhook secret ending in/)).toHaveTextContent(
            '•••9876',
        );
        expect(
            screen.getByText(/does not confirm live delivery or the health/),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Replace webhook secret' }),
        );
        expect(
            screen.getByLabelText(/Replace webhook secret key/),
        ).toHaveAttribute('type', 'password');
    });
    it.each(['actor', 'module', 'manage', 'payload'] as const)(
        'clears private facts and unsent secrets after %s authority is lost',
        (lost) => {
            const data = fixture();
            const { rerender } = render(<MilesightIntegration {...data} />);
            fireEvent.click(
                screen.getByRole('button', { name: 'Rotate secret' }),
            );
            fireEvent.change(screen.getByLabelText(/New client secret/), {
                target: { value: 'PRIVATE-UNSENT-SECRET' },
            });
            if (lost === 'actor') mocks.actor = 8;
            if (lost === 'module') mocks.module = false;
            if (lost === 'manage') mocks.manage = false;
            rerender(
                <MilesightIntegration
                    {...data}
                    can={{ manage: lost !== 'payload' }}
                />,
            );
            expect(
                screen.getByText('Integration access unavailable', {
                    selector: '[data-slot="card-title"]',
                }),
            ).toBeInTheDocument();
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            expect(
                screen.queryByDisplayValue('PRIVATE-UNSENT-SECRET'),
            ).not.toBeInTheDocument();
            expect(screen.queryByText('•••1234')).not.toBeInTheDocument();
            expect(mocks.post).not.toHaveBeenCalled();
        },
    );
    it.each([401, 403, 404])(
        'clears old facts and forms when the current page read returns %s',
        (status) => {
            render(<MilesightIntegration {...fixture()} />);
            fireEvent.click(
                screen.getByRole('button', { name: 'Rotate secret' }),
            );
            fireEvent.change(screen.getByLabelText(/New client secret/), {
                target: { value: 'PRIVATE-UNSENT-SECRET' },
            });
            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Refresh page',
                    hidden: true,
                }),
            );
            expect(invalid(status).defaultPrevented).toBe(true);
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
            expect(screen.queryByText('•••1234')).not.toBeInTheDocument();
            expect(
                screen.queryByText('Private rejection text'),
            ).not.toBeInTheDocument();
        },
    );
    it('preserves reviewed credentials on an unconfirmed backend response and sends the existing payload', () => {
        render(<MilesightIntegration {...empty()} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Configure credentials' }),
        );
        fireEvent.change(screen.getByLabelText(/OAuth client ID/), {
            target: { value: 'regional-client' },
        });
        fireEvent.change(screen.getByLabelText(/OAuth client secret/), {
            target: { value: 'SECRET-RETRY' },
        });
        fireEvent.change(screen.getByLabelText(/Server URL/), {
            target: { value: 'https://region.example.test' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(screen.queryByText('SECRET-RETRY')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Save credentials' }),
        );
        const [path, payload, callbacks] = mocks.post.mock.calls[0];
        expect(path).toBe('/security-devices/integrations/milesight/key');
        expect(payload).toEqual({
            client_id: 'regional-client',
            client_secret: 'SECRET-RETRY',
            base_url: 'https://region.example.test',
        });
        act(() => {
            callbacks.onSuccess(response(empty()));
            callbacks.onFinish();
        });
        expect(
            screen.getByText(/Credentials have not been confirmed saved/),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Credentials saved', { selector: 'h2' }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(screen.getByLabelText(/OAuth client secret/)).toHaveValue(
            'SECRET-RETRY',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByText('Discard credential entries?'),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard entries' }),
        );
        expect(
            screen.queryByLabelText('OAuth client secret'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Configure credentials' }),
        );
        expect(screen.getByLabelText(/OAuth client secret/)).toHaveValue('');
    });
    it('maps only the reviewed approved Site using the current discovery token', async () => {
        render(<MilesightIntegration {...fixture()} />);
        tab('Applications & Sites');
        await action('North sensors', 'Map to Site');
        fireEvent.click(
            screen.getByRole('combobox', { name: 'Site for North sensors' }),
        );
        fireEvent.click(screen.getByRole('option', { name: /North house/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Map to Site' }));
        expect(mocks.post).toHaveBeenCalledWith(
            '/security-devices/integrations/milesight/applications/map',
            { site_id: 5, mapping_token: 'bounded-application-token' },
            expect.any(Object),
        );
    });
    it('preserves canonical test, discovery, device-sync and destructive endpoints', async () => {
        render(<MilesightIntegration {...fixture()} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Test connection' }),
        );
        expect(mocks.post).toHaveBeenLastCalledWith(
            '/security-devices/integrations/milesight/test',
            {},
            expect.any(Object),
        );
        act(() => mocks.post.mock.calls.at(-1)![2].onFinish());
        tab('Applications & Sites');
        fireEvent.click(
            screen.getByRole('button', { name: 'Discover applications' }),
        );
        expect(mocks.post).toHaveBeenLastCalledWith(
            '/security-devices/integrations/milesight/applications/sync',
            {},
            expect.any(Object),
        );
        act(() => mocks.post.mock.calls.at(-1)![2].onFinish());
        await action('North house', 'Sync Devices');
        expect(mocks.post).toHaveBeenLastCalledWith(
            '/security-devices/integrations/milesight/devices/sync',
            { site_config_id: 42 },
            expect.any(Object),
        );
        act(() => mocks.post.mock.calls.at(-1)![2].onFinish());
        await action('North house', 'Remove mapping');
        fireEvent.click(screen.getByRole('button', { name: 'Remove mapping' }));
        expect(mocks.remove).toHaveBeenLastCalledWith(
            '/security-devices/integrations/milesight/applications/42',
            expect.any(Object),
        );
    });
});

describe('Milesight request and draft boundaries', () => {
    it('retains validation failures and back-step entries without inventing a save', () => {
        render(<MilesightIntegration {...empty()} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Configure credentials' }),
        );
        fireEvent.change(screen.getByLabelText(/OAuth client ID/), {
            target: { value: 'regional-client' },
        });
        fireEvent.change(screen.getByLabelText(/OAuth client secret/), {
            target: { value: 'SECRET-VALIDATION' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save credentials' }),
        );
        const callbacks = mocks.post.mock.calls[0][2];
        act(() => {
            callbacks.onError({ client_secret: 'Client secret was rejected.' });
            callbacks.onFinish();
        });
        expect(
            screen.getByText('Client secret was rejected.'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(screen.getByLabelText(/OAuth client secret/)).toHaveValue(
            'SECRET-VALIDATION',
        );
    });
    it('clears a rotated-secret draft when the current provider record disappears', () => {
        const data = fixture();
        const { rerender } = render(<MilesightIntegration {...data} />);
        fireEvent.click(screen.getByRole('button', { name: 'Rotate secret' }));
        fireEvent.change(screen.getByLabelText(/New client secret/), {
            target: { value: 'PRIVATE-REMOVED-RECORD' },
        });
        rerender(<MilesightIntegration {...data} providerConnection={null} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByDisplayValue('PRIVATE-REMOVED-RECORD'),
        ).not.toBeInTheDocument();
        rerender(<MilesightIntegration {...data} />);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });
    it('keeps entries after a provider failure and ignores an unrelated denied response', () => {
        render(<MilesightIntegration {...fixture()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh page' }));
        expect(invalid(403, '/security-devices/devices').defaultPrevented).toBe(
            false,
        );
        expect(screen.getByText('•••1234')).toBeInTheDocument();
        expect(invalid(503).defaultPrevented).toBe(true);
        expect(
            screen.getByText(/The request could not be completed/),
        ).toBeInTheDocument();
        expect(screen.getByText('•••1234')).toBeInTheDocument();
        expect(
            screen.queryByText('Private rejection text'),
        ).not.toBeInTheDocument();
    });
    it('searches returned records in the shared table and gives an honest filtered empty state', () => {
        render(<MilesightIntegration {...fixture()} />);
        tab('Applications & Sites');
        fireEvent.click(screen.getByRole('radio', { name: 'Table' }));
        expect(screen.getByRole('table')).toBeInTheDocument();
        fireEvent.change(screen.getByRole('searchbox'), {
            target: { value: 'unreturned-record' },
        });
        expect(
            screen.getByText('No records match the current search and filter.'),
        ).toBeInTheDocument();
        expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });
    it('uses a blocked action with a stated reason for inactive mappings', async () => {
        render(<MilesightIntegration {...fixture()} />);
        tab('Applications & Sites');
        const record = screen
            .getByText('Inactive mapping', { selector: 'h3' })
            .closest('[role="button"]') as HTMLElement;
        fireEvent.keyDown(
            within(record).getByRole('button', {
                name: 'Actions for Inactive mapping',
            }),
            { key: 'Enter', code: 'Enter' },
        );
        const sync = await screen.findByRole('menuitem', {
            name: 'Sync Devices',
        });
        expect(sync).toHaveAttribute('aria-disabled', 'true');
        expect(
            screen.getByText('This mapping is inactive.'),
        ).toBeInTheDocument();
        fireEvent.click(sync);
        expect(mocks.post).not.toHaveBeenCalled();
    });
});

describe('Milesight current response authority', () => {
    it('clears a secret draft when its mutation response belongs to another actor', () => {
        render(<MilesightIntegration {...fixture()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Rotate secret' }));
        fireEvent.change(screen.getByLabelText(/New client secret/), {
            target: { value: 'PRIVATE-STALE-REPLY' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save new key' }));
        expect(mocks.post.mock.calls[0][1]).toEqual({
            client_secret: 'PRIVATE-STALE-REPLY',
        });
        const reply = response(fixture(), 'Milesight client secret rotated.');
        reply.props.actor_id = 8;
        act(() => {
            mocks.post.mock.calls[0][2].onSuccess(reply);
            mocks.post.mock.calls[0][2].onFinish();
        });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByText('•••1234')).not.toBeInTheDocument();
    });
    it('sends only the separate webhook secret through the existing verification endpoint', () => {
        render(<MilesightIntegration {...fixture()} />);
        tab('Webhook');
        fireEvent.click(
            screen.getByRole('button', { name: 'Replace webhook secret' }),
        );
        fireEvent.change(screen.getByLabelText(/Replace webhook secret key/), {
            target: { value: 'separate-webhook-secret' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Replace verification secret' }),
        );
        expect(mocks.post).toHaveBeenCalledWith(
            '/security-devices/integrations/milesight/webhook',
            { webhook_secret: 'separate-webhook-secret' },
            expect.any(Object),
        );
    });
});

describe('Milesight saved response lifecycle', () => {
    it('keeps a genuine credentials success pane through returned record creation without activating the provider', () => {
        const data = empty();
        const { rerender } = render(<MilesightIntegration {...data} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Configure credentials' }),
        );
        fireEvent.change(screen.getByLabelText(/OAuth client ID/), {
            target: { value: 'regional-client' },
        });
        fireEvent.change(screen.getByLabelText(/OAuth client secret/), {
            target: { value: 'SECRET-SAVED' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Save credentials' }),
        );
        const saved = {
            ...data,
            providerConnection: {
                ...fixture().providerConnection!,
                status: 'disconnected' as const,
            },
        };
        rerender(<MilesightIntegration {...saved} />);
        act(() => {
            mocks.post.mock.calls[0][2].onSuccess(
                response(
                    saved,
                    'Milesight OAuth credentials saved. Run Test Connection to verify.',
                ),
            );
            mocks.post.mock.calls[0][2].onFinish();
        });
        expect(
            screen.getByRole('heading', { name: 'Credentials saved' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Milesight OAuth credentials saved. Run Test Connection to verify.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByDisplayValue('SECRET-SAVED'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review connection' }),
        );
        expect(screen.getAllByText('Disconnected').length).toBeGreaterThan(0);
        expect(
            screen.queryByText('Connected', { exact: true }),
        ).not.toBeInTheDocument();
    });
    it('closes a stale removal confirmation when that mapping is absent from current records', async () => {
        const data = fixture();
        const { rerender } = render(<MilesightIntegration {...data} />);
        tab('Applications & Sites');
        await action('North house', 'Remove mapping');
        expect(screen.getByRole('alertdialog')).toBeInTheDocument();
        rerender(<MilesightIntegration {...data} siteConfigs={[]} />);
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
        expect(mocks.remove).not.toHaveBeenCalled();
    });
});

describe('Milesight received DTO source ownership', () => {
    it('rejects paired actor metadata changes while retaining the previous actors private DTOs', () => {
        const original = fixture();
        const { rerender } = render(<MilesightIntegration {...original} />);
        fireEvent.click(screen.getByRole('button', { name: 'Rotate secret' }));
        fireEvent.change(screen.getByLabelText(/New client secret/), {
            target: { value: 'PRIVATE-ACTOR-7-DRAFT' },
        });
        mocks.actor = 8;
        // An Inertia same-page partial change replaces only metadata/auth.
        // Every provider, application, mapping, Site, log and credential DTO is still actor 7's.
        rerender(
            <MilesightIntegration
                {...original}
                actor_id={8}
                as_of="2026-10-13T02:00:00Z"
            />,
        );
        expect(
            screen.getByText('Integration access unavailable', {
                selector: '[data-slot="card-title"]',
            }),
        ).toBeInTheDocument();
        expect(screen.queryByText('•••1234')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Connected', { exact: true }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByDisplayValue('PRIVATE-ACTOR-7-DRAFT'),
        ).not.toBeInTheDocument();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Rotate secret' }),
        ).not.toBeInTheDocument();
        expect(mocks.post).not.toHaveBeenCalled();
    });
});

describe('Milesight complete page read recovery', () => {
    it('uses a complete read and fresh page mount for normal Refresh', () => {
        render(<MilesightIntegration {...fixture()} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh page' }));
        expect(mocks.visit).toHaveBeenCalledWith(
            '/security-devices/integrations/milesight',
            expect.objectContaining({
                method: 'get',
                preserveState: false,
                preserveScroll: true,
            }),
        );
        expect(mocks.visit.mock.calls[0][1]).not.toHaveProperty('only');
        expect(mocks.reload).not.toHaveBeenCalled();
        expect(mocks.post).not.toHaveBeenCalled();
    });
    it('recovers an actor metadata mismatch through a full read without relabelling old DTOs', () => {
        const original = fixture();
        const { rerender, unmount } = render(
            <MilesightIntegration {...original} />,
        );
        mocks.actor = 8;
        rerender(<MilesightIntegration {...original} actor_id={8} />);
        expect(screen.queryByText('•••1234')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload integration' }),
        );
        expect(mocks.visit).toHaveBeenCalledWith(
            '/security-devices/integrations/milesight',
            expect.objectContaining({ method: 'get', preserveState: false }),
        );
        expect(mocks.visit.mock.calls[0][1]).not.toHaveProperty('only');
        expect(screen.queryByText('•••1234')).not.toBeInTheDocument();
        // The complete Inertia visit replaces the page component, unlike a partial metadata change.
        unmount();
        const current = {
            ...empty(),
            actor_id: 8,
            providerConnection: {
                ...fixture().providerConnection!,
                secret_last4: '8888',
                status: 'disconnected' as const,
            },
        };
        render(<MilesightIntegration {...current} />);
        expect(screen.getByText('•••8888')).toBeInTheDocument();
        expect(screen.queryByText('•••1234')).not.toBeInTheDocument();
    });
});
