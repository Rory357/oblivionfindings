import type { UnifiProps } from '@/pages/security-devices/integrations/unifi';
import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { readFileSync } from 'node:fs';
import * as React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import UnifiIntegration from '@/pages/security-devices/integrations/unifi';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

// Derive the fixture's public field names from the production permission producer.
const permissionSource = readFileSync(
    'app/Http/Middleware/HandleInertiaRequests.php',
    'utf8',
);
const permissionBlock = /'securityDevices' => \[([\s\S]*?)\n\s*\],/.exec(
    permissionSource,
)?.[1];
if (!permissionBlock)
    throw new Error(
        'Production Security & Devices permission projection not found',
    );
const permissionProjection = Object.fromEntries(
    [
        ...permissionBlock.matchAll(/'([^']+)' => \$user->canDo\('([^']+)'\)/g),
    ].map((match) => [match[1], match[2]]),
);
const fixturePermissions = () =>
    Object.fromEntries(
        Object.entries(permissionProjection).map(
            ([publicField, permission]) => [
                publicField,
                [
                    'securityDevices.viewAny',
                    'securityDevices.integrations.manage',
                ].includes(permission),
            ],
        ),
    );

const inertiaMocks = vi.hoisted(() => ({
    auth: {
        user: { id: 42 },
        can: {
            securityDevices: {
                viewAny: true,
                integrationsManage: true,
            } as Record<string, boolean>,
        },
    },
    invalid: null as null | ((event: unknown) => void),
    invalidCallbacks: new Set<(event: unknown) => void>(),
    router: {
        reload: vi.fn(),
        visit: vi.fn(),
        on: vi.fn(),
        delete: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
    },
}));

vi.mock('@inertiajs/react', async () => {
    const actual =
        await vi.importActual<typeof import('@inertiajs/react')>(
            '@inertiajs/react',
        );
    return {
        ...actual,
        Head: ({ title }: { title: string }) => <title>{title}</title>,
        router: inertiaMocks.router,
        Link: ({
            href,
            children,
        }: {
            href: string;
            children: React.ReactNode;
        }) => <a href={href}>{children}</a>,
        usePage: () => ({ props: { auth: inertiaMocks.auth } }),
        useForm: actual.useForm,
    };
});

beforeEach(() => {
    vi.clearAllMocks();
    inertiaMocks.auth.user.id = 42;
    inertiaMocks.auth.can = { securityDevices: fixturePermissions() };
    inertiaMocks.invalid = null;
    inertiaMocks.invalidCallbacks.clear();
    inertiaMocks.router.on.mockImplementation((name, callback) => {
        if (name === 'invalid') {
            inertiaMocks.invalidCallbacks.add(callback);
            inertiaMocks.invalid = (event) =>
                inertiaMocks.invalidCallbacks.forEach((listener) =>
                    listener(event),
                );
        }
        return () => inertiaMocks.invalidCallbacks.delete(callback);
    });
});

describe('UniFi integration', () => {
    it('requires a reason before confirming the fail-closed disable workflow', () => {
        render(
            <UnifiIntegration
                actor_id={42}
                as_of="2026-10-13T08:00:00Z"
                can={{ manage: true }}
                providerConnection={{
                    status: 'connected',
                    secret_last4: '0042',
                }}
                discoveredSites={[]}
                siteConfigs={[]}
                sites={[]}
                rooms={[]}
                syncedDevices={[]}
                syncLogs={[]}
                siteCredentials={[]}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Disable connection' }),
        );

        expect(
            screen.getByRole('heading', {
                name: 'Disable UniFi connection?',
            }),
        ).toBeVisible();
        expect(
            screen.getByText(/Stop provider collection and new intake/),
        ).toBeVisible();
        expect(
            screen.getByText(/Saved records and mappings remain available/),
        ).toBeVisible();

        const confirm = screen.getByRole('button', {
            name: 'Confirm containment',
        });
        expect(confirm).toBeDisabled();

        fireEvent.click(
            screen.getByLabelText('Provider outage or instability'),
        );
        expect(confirm).toBeEnabled();
        fireEvent.click(confirm);

        expect(inertiaMocks.router.post).toHaveBeenCalledWith(
            '/security-devices/integrations/unifi/disable',
            { reason: 'provider_outage' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('explains disabled containment and requires credential replacement before recovery', () => {
        render(
            <UnifiIntegration
                actor_id={42}
                as_of="2026-10-13T08:00:00Z"
                can={{ manage: true }}
                providerConnection={{
                    status: 'disabled',
                    secret_last4: '0042',
                    disabled_at: '2026-08-05T08:00:00Z',
                    disabled_reason: 'security_review',
                    requires_credential_replacement: true,
                }}
                discoveredSites={[]}
                siteConfigs={[]}
                sites={[]}
                rooms={[]}
                syncedDevices={[]}
                syncLogs={[]}
                siteCredentials={[]}
            />,
        );

        expect(screen.getByText('Provider traffic is disabled')).toBeVisible();
        expect(screen.getByText('Security review')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Test connection' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('tab', { name: 'Site mappings' }));
        expect(
            screen.getByRole('button', { name: 'Sync UniFi locations' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('tab', { name: 'Connection' }));
        expect(
            screen.getByRole('button', { name: 'Replace key to recover' }),
        ).toBeEnabled();
        expect(
            screen.queryByRole('button', { name: 'Disable connection' }),
        ).not.toBeInTheDocument();
    });

    it('disables inactive mapping sync with an accessible recovery explanation', async () => {
        render(
            <UnifiIntegration
                actor_id={42}
                as_of="2026-10-13T08:00:00Z"
                can={{ manage: true }}
                providerConnection={{
                    status: 'connected',
                    secret_last4: '0042',
                }}
                discoveredSites={[]}
                siteConfigs={[
                    {
                        id: 10,
                        site_id: 101,
                        site_name: 'Active House',
                        status: 'hybrid',
                        mapped_external_site_name: 'Active controller',
                        is_active: true,
                    },
                    {
                        id: 20,
                        site_id: 202,
                        site_name: 'Inactive House',
                        status: 'hybrid',
                        mapped_external_site_name: 'Inactive controller',
                        is_active: false,
                    },
                ]}
                sites={[]}
                rooms={[]}
                syncedDevices={[]}
                syncLogs={[]}
                siteCredentials={[]}
            />,
        );

        fireEvent.click(screen.getByRole('tab', { name: 'Site mappings' }));
        const inactiveRow = screen
            .getByText('Inactive House')
            .closest('[role="row"]');
        expect(inactiveRow).not.toBeNull();
        fireEvent.keyDown(
            within(inactiveRow as HTMLElement).getByRole('button', {
                name: /Actions/,
            }),
            { key: 'ArrowDown' },
        );
        await screen.findByRole('menuitem', { name: /Sync devices/ });
        const inactiveSync = screen.getByRole('menuitem', {
            name: /Sync devices/,
        });
        expect(inactiveSync).toHaveAttribute('aria-disabled', 'true');
        expect(inactiveSync).toHaveAccessibleDescription(
            'This mapping is inactive. Remove it and map the location again before syncing devices.',
        );
        fireEvent.click(inactiveSync);
        expect(inertiaMocks.router.post).not.toHaveBeenCalled();
        fireEvent.keyDown(inactiveSync, { key: 'Escape' });
        const activeRow = screen
            .getByText('Active House')
            .closest('[role="row"]');
        await waitFor(() =>
            expect(screen.queryByRole('menu')).not.toBeInTheDocument(),
        );
        fireEvent.keyDown(
            within(activeRow as HTMLElement).getByRole('button', {
                name: /Actions/,
            }),
            { key: 'ArrowDown' },
        );
        await screen.findByRole('menuitem', { name: /Sync devices/ });
        fireEvent.click(screen.getByRole('menuitem', { name: /Sync devices/ }));
        expect(inertiaMocks.router.post).toHaveBeenCalledWith(
            '/security-devices/integrations/unifi/sync-devices',
            { site_config_id: 10 },
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});

const fixture: UnifiProps = {
    actor_id: 42,
    as_of: '2026-10-13T08:00:00Z',
    can: { manage: true },
    providerConnection: { status: 'connected', secret_last4: '0042' },
    discoveredSites: [
        {
            mapping_token: 'opaque-do-not-search',
            name: 'Controller north',
            device_count: 0,
        },
    ],
    siteConfigs: [
        {
            id: 10,
            site_id: 101,
            site_name: 'North House',
            status: 'hybrid',
            is_active: true,
            mapped_external_site_name: 'Controller north',
        },
        {
            id: 20,
            site_id: 202,
            site_name: 'South House',
            status: 'hybrid',
            is_active: false,
        },
    ],
    sites: [
        { id: 101, name: 'North House' },
        { id: 202, name: 'South House' },
    ],
    rooms: [],
    syncedDevices: [
        {
            id: 9,
            site_id: null,
            site_name: 'Unassigned',
            room_id: null,
            room_name: null,
            name: 'North gateway',
            domain: 'network',
            category: 'router',
            subcategory: null,
            status: 'active',
            health_status: null,
            model: 'Model A',
            manufacturer: 'Vendor',
            firmware_version: null,
            last_seen_at: '2026-10-13T07:00:00Z',
            detail_url: '/security-devices/devices/9',
        },
    ],
    syncLogs: [
        {
            id: 4,
            action: 'device_sync',
            status: 'success',
            items_processed: 0,
            items_created: 0,
            items_updated: 0,
            items_errored: 0,
            started_at: '2026-10-13T07:00:00Z',
            completed_at: null,
        },
    ],
    siteCredentials: [],
};
function invalid(
    status: number,
    method = 'get',
    url = '/security-devices/integrations/unifi',
) {
    const preventDefault = vi.fn();
    inertiaMocks.invalid?.({
        detail: {
            response: {
                status,
                config: {
                    method,
                    url,
                    headers:
                        inertiaMocks.router.visit.mock.calls.at(-1)?.[1]
                            ?.headers ?? {},
                },
                data: 'PRIVATE raw provider error',
            },
        },
        preventDefault,
    });
    return preventDefault;
}

describe('UniFi current read and connected views', () => {
    it('uses the production nested shared-permission projection for authorised and withdrawn access', () => {
        expect(permissionProjection).toMatchObject({
            viewAny: 'securityDevices.viewAny',
            integrationsManage: 'securityDevices.integrations.manage',
            monitoringManage: 'securityDevices.monitoring.manage',
        });
        const { rerender } = render(<UnifiIntegration {...fixture} />);
        expect(
            screen.getByRole('button', { name: 'Test connection' }),
        ).toBeEnabled();
        inertiaMocks.auth.can.securityDevices.integrationsManage = false;
        rerender(<UnifiIntegration {...fixture} />);
        expect(
            screen.queryByRole('button', { name: 'Test connection' }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText(/0042/)).not.toBeInTheDocument();
    });
    it('connects every meter to its view and searches permitted records', () => {
        render(<UnifiIntegration {...fixture} />);
        expect(
            screen.getByRole('heading', { name: 'UniFi integration' }),
        ).toBeVisible();
        fireEvent.click(
            screen.getAllByRole('button', {
                name: 'Open Site mappings view',
            })[0],
        );
        expect(screen.getByText('North House')).toBeVisible();
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search UniFi records' }),
            { target: { value: 'South House' } },
        );
        expect(screen.getByText('South House')).toBeVisible();
        expect(screen.queryByText('North House')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Reset filters' }),
        ).not.toBeInTheDocument();
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search UniFi records' }),
            { target: { value: '' } },
        );
        expect(screen.getByText('North House')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Open Devices view' }),
        );
        expect(screen.getByText('North gateway')).toBeVisible();
        expect(screen.getByText('Active')).toBeVisible();
        expect(screen.queryByText('Online')).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Open Sync history view' }),
        );
        expect(screen.getByText('Device sync · Run 4')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Open Site credentials view' }),
        );
        expect(
            screen.getByText('No Site credential status has been recorded.'),
        ).toBeVisible();
    });
    it('withholds private old records from another actor and withdrawn management', () => {
        const { rerender } = render(<UnifiIntegration {...fixture} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Site mappings' }));
        expect(screen.getByText('North House')).toBeVisible();
        inertiaMocks.auth.user.id = 77;
        rerender(<UnifiIntegration {...fixture} />);
        expect(screen.queryByText('North House')).not.toBeInTheDocument();
        expect(screen.queryByText(/0042/)).not.toBeInTheDocument();
        inertiaMocks.auth.user.id = 42;
        inertiaMocks.auth.can.securityDevices.integrationsManage = false;
        rerender(<UnifiIntegration {...fixture} />);
        expect(screen.queryByText(/0042/)).not.toBeInTheDocument();
        expect(screen.getByText(/UniFi records are restricted/)).toBeVisible();
    });
    it.each([401, 403, 404])(
        'quarantines an exact-owned denied refresh %s and ignores its late success',
        (status) => {
            render(<UnifiIntegration {...fixture} />);
            fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
            const options = inertiaMocks.router.visit.mock.calls[0][1];
            expect(options.preserveState).toBe(false);
            expect(options.only).toBeUndefined();
            let prevented: ReturnType<typeof invalid>;
            React.act(() => {
                prevented = invalid(status);
            });
            expect(prevented!).toHaveBeenCalled();
            expect(screen.queryByText(/0042/)).not.toBeInTheDocument();
            React.act(() =>
                options.onSuccess({
                    component: 'security-devices/integrations/unifi',
                    props: { ...fixture, auth: inertiaMocks.auth },
                }),
            );
            expect(
                screen.getByText(/UniFi records are restricted/),
            ).toBeVisible();
            expect(
                screen.queryByText('PRIVATE raw provider error'),
            ).not.toBeInTheDocument();
        },
    );
    it('ignores a same-route denied reply from a previous page instance and accepts only its own marker', () => {
        const first = render(<UnifiIntegration {...fixture} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        const oldOptions = inertiaMocks.router.visit.mock.calls.at(-1)![1];
        first.unmount();
        render(<UnifiIntegration {...fixture} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        const currentOptions = inertiaMocks.router.visit.mock.calls.at(-1)![1];
        expect(currentOptions.headers['X-Obli-UniFi-Read']).not.toBe(
            oldOptions.headers['X-Obli-UniFi-Read'],
        );
        const preventDefault = vi.fn();
        React.act(() =>
            inertiaMocks.invalid?.({
                detail: {
                    response: {
                        status: 403,
                        config: {
                            method: 'get',
                            url: '/security-devices/integrations/unifi',
                            headers: oldOptions.headers,
                        },
                    },
                },
                preventDefault,
            }),
        );
        expect(preventDefault).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Test connection' }),
        ).toBeVisible();
        React.act(() => invalid(403));
        expect(screen.getByText(/UniFi records are restricted/)).toBeVisible();
    });
    it('retains dated reads after failed refresh without swallowing unrelated workflow errors', () => {
        render(<UnifiIntegration {...fixture} />);
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        let unrelated: ReturnType<typeof invalid>;
        React.act(() => {
            unrelated = invalid(
                403,
                'post',
                '/security-devices/integrations/unifi/disable',
            );
        });
        expect(unrelated!).not.toHaveBeenCalled();
        let failed: ReturnType<typeof invalid>;
        React.act(() => {
            failed = invalid(503);
        });
        expect(failed!).toHaveBeenCalled();
        expect(screen.getByText(/Refresh failed/)).toBeVisible();
        expect(screen.getByText(/0042/)).toBeVisible();
        expect(
            screen.queryByText('PRIVATE raw provider error'),
        ).not.toBeInTheDocument();
    });
    it('closes private connection drafts when management is withdrawn', () => {
        const { rerender } = render(
            <UnifiIntegration {...fixture} providerConnection={null} />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Set up connection' }),
        );
        const field = screen.getByLabelText(/^API key/);
        fireEvent.change(field, { target: { value: 'private unsaved key' } });
        expect(field).toHaveValue('private unsaved key');
        inertiaMocks.auth.can.securityDevices.integrationsManage = false;
        rerender(<UnifiIntegration {...fixture} providerConnection={null} />);
        expect(
            screen.queryByDisplayValue('private unsaved key'),
        ).not.toBeInTheDocument();
        inertiaMocks.auth.can.securityDevices.integrationsManage = true;
        rerender(<UnifiIntegration {...fixture} providerConnection={null} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Set up connection' }),
        );
        expect(screen.getByLabelText(/^API key/)).toHaveValue('');
    });
    it('keeps an unsaved key on cancelled close and clears it only after reviewed discard', () => {
        render(<UnifiIntegration {...fixture} providerConnection={null} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Set up connection' }),
        );
        fireEvent.change(screen.getByLabelText(/^API key/), {
            target: { value: 'synthetic draft' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(
            screen.getByRole('heading', {
                name: 'Discard unsaved UniFi settings?',
            }),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(screen.getByLabelText(/^API key/)).toHaveValue(
            'synthetic draft',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Site mappings' }));
        expect(
            screen.getByText(/Save and test the provider API key before/),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('tab', { name: 'Connection' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Set up connection' }),
        );
        expect(screen.getByLabelText(/^API key/)).toHaveValue('');
    });
    it('advances Defaults to the authoritative saved response and keeps them after navigation', () => {
        const defaults = {
            refresh_interval_minutes: 15,
            alert_motion_events: false,
            alert_device_offline: true,
            quiet_hours_start: '',
            quiet_hours_end: '',
        };
        const { rerender } = render(
            <UnifiIntegration
                {...fixture}
                providerConnection={{ status: 'connected', defaults }}
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Defaults' }));
        fireEvent.click(screen.getByRole('button', { name: 'Edit defaults' }));
        fireEvent.change(
            screen.getByLabelText(/^Refresh interval \(minutes\)/),
            {
                target: { value: '30' },
            },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save defaults' }));
        expect(inertiaMocks.router.put.mock.calls[0].slice(0, 2)).toEqual([
            '/security-devices/integrations/unifi/defaults',
            {
                config: {
                    refresh_interval_minutes: 30,
                    alert_motion_events: false,
                    alert_device_offline: true,
                    quiet_hours_start: null,
                    quiet_hours_end: null,
                },
            },
        ]);
        const options = inertiaMocks.router.put.mock.calls[0][2];
        const saved = {
            ...fixture,
            providerConnection: {
                status: 'connected' as const,
                defaults: { ...defaults, refresh_interval_minutes: 30 },
            },
        };
        rerender(<UnifiIntegration {...saved} />);
        React.act(() => {
            options.onSuccess({
                component: 'security-devices/integrations/unifi',
                props: {
                    ...saved,
                    auth: inertiaMocks.auth,
                    actionOutcome: requestAcknowledgement(),
                },
            });
            options.onFinish();
        });
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        fireEvent.click(screen.getByRole('tab', { name: 'Sync history' }));
        expect(
            screen.queryByRole('heading', {
                name: 'Discard unsaved UniFi settings?',
            }),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('tab', { name: 'Defaults' }));
        fireEvent.click(screen.getByRole('button', { name: 'Edit defaults' }));
        expect(
            screen.getByLabelText(/^Refresh interval \(minutes\)/),
        ).toHaveValue(30);
    });
    it('shows saved quiet hours in the app time format while keeping the stored 24-hour values', () => {
        render(
            <UnifiIntegration
                {...fixture}
                providerConnection={{
                    status: 'connected',
                    defaults: {
                        refresh_interval_minutes: 15,
                        alert_motion_events: false,
                        alert_device_offline: true,
                        quiet_hours_start: '22:00',
                        quiet_hours_end: '06:30',
                    },
                }}
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Defaults' }));
        expect(screen.getByText('10:00 pm')).toBeVisible();
        expect(screen.getByText('6:30 am')).toBeVisible();
        expect(screen.queryByText('22:00')).not.toBeInTheDocument();
        expect(
            screen.getByText(/Quiet hours use New Zealand time/),
        ).toBeVisible();
    });
    it('uses newly refreshed canonical room context without inventing an unsaved edit', () => {
        const { rerender } = render(<UnifiIntegration {...fixture} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Devices' }));
        const device = {
            ...fixture.syncedDevices[0],
            id: 10,
            name: 'Newly imported gateway',
            site_id: 101,
            site_name: 'North House',
            room_id: 11,
            room_name: 'Office',
        };
        rerender(
            <UnifiIntegration
                {...fixture}
                syncedDevices={[device]}
                rooms={[{ id: 11, site_id: 101, name: 'Office' }]}
            />,
        );
        expect(
            screen.getAllByText(/North House · Office/).length,
        ).toBeGreaterThan(0);
        fireEvent.click(screen.getByRole('tab', { name: 'Connection' }));
        expect(
            screen.queryByRole('heading', {
                name: 'Discard unsaved UniFi settings?',
            }),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Test connection' }),
        ).toBeVisible();
    });
    it('does not rebind retained private DTOs after a paired actor and metadata-only update', () => {
        const { rerender } = render(<UnifiIntegration {...fixture} />);
        expect(screen.getByText(/0042/)).toBeVisible();
        inertiaMocks.auth.user.id = 77;
        rerender(<UnifiIntegration {...fixture} actor_id={77} />);
        expect(screen.queryByText(/0042/)).not.toBeInTheDocument();
        expect(screen.getByText(/UniFi records are restricted/)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(inertiaMocks.router.visit.mock.calls[0][1]).toMatchObject({
            preserveState: false,
        });
        expect(inertiaMocks.router.visit.mock.calls[0][1].only).toBeUndefined();
    });
    it('reconciles a clean externally refreshed Defaults record using the installed form hook', () => {
        const { rerender } = render(
            <UnifiIntegration
                {...fixture}
                providerConnection={{
                    status: 'connected',
                    defaults: { refresh_interval_minutes: 15 },
                }}
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Defaults' }));
        fireEvent.click(screen.getByRole('button', { name: 'Edit defaults' }));
        rerender(
            <UnifiIntegration
                {...fixture}
                providerConnection={{
                    status: 'connected',
                    defaults: { refresh_interval_minutes: 45 },
                }}
            />,
        );
        expect(
            screen.getByLabelText(/^Refresh interval \(minutes\)/),
        ).toHaveValue(45);
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(screen.getByRole('tab', { name: 'Connection' }));
        expect(
            screen.queryByRole('heading', {
                name: 'Discard unsaved UniFi settings?',
            }),
        ).not.toBeInTheDocument();
    });
    it('keeps no-key and no-history states distinct from connected and successful', () => {
        render(
            <UnifiIntegration
                {...fixture}
                providerConnection={null}
                syncLogs={[]}
            />,
        );
        expect(screen.getByText('Not configured')).toBeVisible();
        fireEvent.click(screen.getByRole('tab', { name: 'Sync history' }));
        expect(
            screen.getByText('No sync run has been recorded.'),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('tab', { name: 'Site mappings' }));
        expect(
            screen.getByText(/Save and test the provider API key before/),
        ).toBeVisible();
    });
});

function requestAcknowledgement() {
    const candidates = [
        ['post', inertiaMocks.router.post],
        ['put', inertiaMocks.router.put],
        ['delete', inertiaMocks.router.delete],
    ] as const;
    const [method, mock] = [...candidates].sort(
        (a, b) =>
            (b[1].mock.invocationCallOrder.at(-1) ?? 0) -
            (a[1].mock.invocationCallOrder.at(-1) ?? 0),
    )[0];
    const call = mock.mock.calls.at(-1)!;
    const options = call[method === 'delete' ? 1 : 2];
    return {
        actor_id: 42,
        request_id: options.headers['X-Obli-UniFi-Action'] as string,
        method,
        path: call[0] as string,
        outcome: 'saved' as const,
    };
}
function completedPage(
    props: Partial<UnifiProps> & { flash?: { error?: string } } = {},
) {
    return {
        component: 'security-devices/integrations/unifi',
        props: {
            ...fixture,
            actionOutcome: requestAcknowledgement(),
            ...props,
            auth: inertiaMocks.auth,
        },
    };
}
function startKeySave() {
    render(<UnifiIntegration {...fixture} providerConnection={null} />);
    fireEvent.click(screen.getByRole('button', { name: 'Set up connection' }));
    fireEvent.change(screen.getByLabelText(/^API key/), {
        target: { value: 'private-test-key-8844' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
    expect(screen.queryByText('private-test-key-8844')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Save key' }));
    return inertiaMocks.router.post.mock.calls.at(-1)![2];
}
describe('UniFi save outcomes', () => {
    it('retains the key draft after an operational error redirect and confirms only a later saved key', () => {
        const failed = startKeySave();
        expect(inertiaMocks.router.post.mock.calls.at(-1)!.slice(0, 2)).toEqual(
            [
                '/security-devices/integrations/unifi/key',
                { api_key: 'private-test-key-8844' },
            ],
        );
        React.act(() => {
            failed.onSuccess(
                completedPage({
                    providerConnection: null,
                    flash: { error: 'PRIVATE raw provider error' },
                }),
            );
            failed.onFinish();
        });
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Saving the key did not complete',
        );
        expect(
            screen.queryByText('PRIVATE raw provider error'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Key saved')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(screen.getByLabelText(/^API key/)).toHaveValue(
            'private-test-key-8844',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save key' }));
        const retry = inertiaMocks.router.post.mock.calls.at(-1)![2];
        React.act(() => {
            retry.onSuccess(
                completedPage({
                    providerConnection: {
                        status: 'disconnected',
                        secret_last4: '8844',
                    },
                }),
            );
            retry.onFinish();
        });
        expect(screen.getByText('Key saved')).toBeVisible();
        expect(
            screen.getByText(
                /Test the connection before collection can resume\./,
            ),
        ).toBeVisible();
        expect(
            screen.queryByDisplayValue('private-test-key-8844'),
        ).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        expect(
            screen.queryByText('Discard unsaved UniFi settings?'),
        ).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Set up connection' }),
        );
        expect(screen.getByLabelText(/^API key/)).toHaveValue('');
    });
    it.each([401, 403])(
        'clears the private key and records for an exact-owned denied mutation %s',
        (status) => {
            const options = startKeySave();
            const preventDefault = vi.fn();
            React.act(() =>
                inertiaMocks.invalid?.({
                    detail: {
                        response: {
                            status,
                            config: {
                                method: 'post',
                                url: '/security-devices/integrations/unifi/key',
                                headers: options.headers,
                            },
                            data: 'PRIVATE raw provider error',
                        },
                    },
                    preventDefault,
                }),
            );
            expect(preventDefault).toHaveBeenCalled();
            expect(
                screen.queryByDisplayValue('private-test-key-8844'),
            ).not.toBeInTheDocument();
            expect(screen.queryByText('North gateway')).not.toBeInTheDocument();
            expect(
                screen.getByText(/UniFi records are restricted/),
            ).toBeVisible();
            React.act(() => {
                options.onSuccess(
                    completedPage({
                        providerConnection: {
                            status: 'connected',
                            secret_last4: '8844',
                        },
                    }),
                );
                options.onFinish();
            });
            expect(screen.queryByText('Key saved')).not.toBeInTheDocument();
            expect(
                screen.queryByText('PRIVATE raw provider error'),
            ).not.toBeInTheDocument();
        },
    );
    it('retains an unconfirmed key draft, blocks repeats and recovers through a full current read', () => {
        const options = startKeySave();
        const preventDefault = vi.fn();
        React.act(() =>
            inertiaMocks.invalid?.({
                detail: {
                    response: {
                        status: 503,
                        config: {
                            method: 'post',
                            url: '/security-devices/integrations/unifi/key',
                            headers: options.headers,
                        },
                        data: 'PRIVATE raw provider error',
                    },
                },
                preventDefault,
            }),
        );
        expect(preventDefault).toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Save key' })).toBeDisabled();
        expect(
            screen
                .getAllByRole('alert')
                .some((alert) =>
                    alert.textContent?.includes('The reply was not confirmed'),
                ),
        ).toBe(true);
        React.act(() => {
            options.onSuccess(
                completedPage({
                    providerConnection: {
                        status: 'connected',
                        secret_last4: '8844',
                    },
                }),
            );
            options.onFinish();
        });
        expect(screen.queryByText('Key saved')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save key' })).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Discard and continue' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Refresh' }));
        expect(inertiaMocks.router.visit.mock.calls.at(-1)![1]).toMatchObject({
            preserveState: false,
        });
        expect(inertiaMocks.router.post).toHaveBeenCalledTimes(1);
    });
    it('does not convert absent defaults into saved false or an invented interval', () => {
        render(<UnifiIntegration {...fixture} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Defaults' }));
        expect(
            screen.getAllByText('Not recorded').length,
        ).toBeGreaterThanOrEqual(5);
        expect(screen.queryByText('15 minutes')).not.toBeInTheDocument();
    });
});

async function recordAction(name: string, action: string) {
    const row = screen.getByText(name).closest('[role="row"]') as HTMLElement;
    fireEvent.keyDown(within(row).getByRole('button', { name: /Actions/ }), {
        key: 'ArrowDown',
    });
    fireEvent.click(await screen.findByRole('menuitem', { name: action }));
}
describe('UniFi canonical placement and mapping', () => {
    it('sends the opaque discovered token and current approved Site, then retains a failed mapping draft', async () => {
        const token = 'a'.repeat(64);
        render(
            <UnifiIntegration
                {...fixture}
                siteConfigs={[]}
                discoveredSites={[
                    {
                        mapping_token: token,
                        name: 'Controller north',
                        device_count: null,
                    },
                ]}
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Site mappings' }));
        await recordAction('Controller north', 'Map location');
        fireEvent.click(
            screen.getByRole('combobox', { name: 'Approved Site' }),
        );
        fireEvent.click(
            screen.getByRole('option', { name: 'North House · Site 101' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        expect(document.body.textContent).not.toContain(token);
        fireEvent.click(screen.getByRole('button', { name: 'Save mapping' }));
        expect(inertiaMocks.router.post.mock.calls.at(-1)!.slice(0, 2)).toEqual(
            [
                '/security-devices/integrations/unifi/map-site',
                { site_id: '101', mapping_token: token },
            ],
        );
        const options = inertiaMocks.router.post.mock.calls.at(-1)![2];
        React.act(() => {
            options.onSuccess(
                completedPage({
                    siteConfigs: [],
                    flash: { error: 'provider mapping conflict' },
                }),
            );
            options.onFinish();
        });
        expect(screen.queryByText('Site mapped')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(
            screen.getByRole('combobox', { name: 'Approved Site' }),
        ).toHaveTextContent('North House');
        expect(
            screen.getByRole('combobox', { name: 'UniFi location' }),
        ).toHaveTextContent('Controller north');
    });
    it('clears only the canonical room using a nullable payload and leaves the Site placement intact', async () => {
        const device = {
            ...fixture.syncedDevices[0],
            site_id: 101,
            site_name: 'North House',
            room_id: 11,
            room_name: 'Office',
        };
        render(
            <UnifiIntegration
                {...fixture}
                syncedDevices={[device]}
                rooms={[
                    { id: 11, site_id: 101, name: 'Office' },
                    { id: 22, site_id: 202, name: 'Other Site room' },
                ]}
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Devices' }));
        await recordAction('North gateway', 'Assign room');
        fireEvent.keyDown(screen.getByRole('combobox', { name: 'Room' }), {
            key: 'ArrowDown',
        });
        expect(
            screen.queryByRole('option', { name: 'Other Site room' }),
        ).not.toBeInTheDocument();
        fireEvent.click(
            await screen.findByRole('option', { name: 'No room assigned' }),
        );
        expect(screen.getByText('North House')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Save room' }));
        expect(inertiaMocks.router.put.mock.calls.at(-1)!.slice(0, 2)).toEqual([
            '/security-devices/integrations/unifi/hardware/9/room',
            { room_id: null },
        ]);
        const options = inertiaMocks.router.put.mock.calls.at(-1)![2];
        React.act(() => {
            options.onSuccess(
                completedPage({
                    syncedDevices: [
                        { ...device, room_id: null, room_name: null },
                    ],
                }),
            );
            options.onFinish();
        });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(
            inertiaMocks.router.put.mock.calls.at(-1)![1],
        ).not.toHaveProperty('site_id');
    });
    it('resets a reviewed room draft when the canonical device moves to another Site', async () => {
        const device = {
            ...fixture.syncedDevices[0],
            site_id: 101,
            site_name: 'North House',
            room_id: 11,
            room_name: 'Office',
        };
        const rooms = [
            { id: 11, site_id: 101, name: 'Office' },
            { id: 12, site_id: 101, name: 'Equipment room' },
            { id: 22, site_id: 202, name: 'Other Site room' },
        ];
        const ui = render(
            <UnifiIntegration
                {...fixture}
                syncedDevices={[device]}
                rooms={rooms}
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Devices' }));
        await recordAction('North gateway', 'Assign room');
        fireEvent.keyDown(screen.getByRole('combobox', { name: 'Room' }), {
            key: 'ArrowDown',
        });
        fireEvent.click(
            await screen.findByRole('option', { name: 'Equipment room' }),
        );
        expect(
            screen.getByRole('combobox', { name: 'Room' }),
        ).toHaveTextContent('Equipment room');
        ui.rerender(
            <UnifiIntegration
                {...fixture}
                syncedDevices={[
                    {
                        ...device,
                        site_id: 202,
                        site_name: 'South House',
                        room_id: 22,
                        room_name: 'Other Site room',
                    },
                ]}
                rooms={rooms}
            />,
        );
        expect(
            screen.getByRole('combobox', { name: 'Room' }),
        ).toHaveTextContent('Other Site room');
        fireEvent.click(screen.getByRole('button', { name: 'Save room' }));
        expect(inertiaMocks.router.put.mock.calls.at(-1)!.slice(0, 2)).toEqual([
            '/security-devices/integrations/unifi/hardware/9/room',
            { room_id: 22 },
        ]);
    });
});

describe('UniFi reviewed recovery details', () => {
    it('keeps cached provider mappings available while discovery requires a connected key', () => {
        render(
            <UnifiIntegration
                {...fixture}
                providerConnection={{
                    status: 'disconnected',
                    secret_last4: '0042',
                }}
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Site mappings' }));
        expect(
            screen.getByRole('button', { name: 'Map location' }),
        ).toBeEnabled();
        expect(
            screen.getByRole('button', { name: 'Sync UniFi locations' }),
        ).toBeDisabled();
    });
    it('retains replacement-key recovery facts after replacement leaves the provider disconnected', () => {
        render(
            <UnifiIntegration
                {...fixture}
                providerConnection={{
                    status: 'disconnected',
                    secret_last4: '0042',
                    recovery_credentials_replaced_at: '2026-10-14T02:00:00Z',
                }}
            />,
        );
        expect(screen.getByText('Replacement key saved')).toBeVisible();
        expect(
            screen.getByText(
                'The replacement key is saved. A connection test must pass before collection resumes.',
            ),
        ).toBeVisible();
    });
    it('blocks room assignment for unassigned stock without sending a known-invalid mutation', async () => {
        render(<UnifiIntegration {...fixture} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Devices' }));
        const row = screen
            .getByText('North gateway')
            .closest('[role="row"]') as HTMLElement;
        fireEvent.keyDown(
            within(row).getByRole('button', { name: /Actions/ }),
            { key: 'ArrowDown' },
        );
        const item = await screen.findByRole('menuitem', {
            name: /Assign room/,
        });
        expect(item).toHaveAttribute('aria-disabled', 'true');
        fireEvent.click(item);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(inertiaMocks.router.put).not.toHaveBeenCalled();
    });
    it('keeps an old key with matching final characters unconfirmed without a fresh write acknowledgement', () => {
        const options = startKeySave();
        React.act(() => {
            options.onSuccess(
                completedPage({
                    providerConnection: {
                        status: 'disconnected',
                        secret_last4: '8844',
                    },
                    actionOutcome: null,
                }),
            );
            options.onFinish();
        });
        expect(screen.queryByText('Key saved')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save key' })).toBeDisabled();
    });
});

describe('UniFi unchanged mapping confirmation', () => {
    it.each(['missing acknowledgement', 'unchanged returned mapping'])(
        'does not confirm location B from an existing active location A with %s',
        async (kind) => {
            const token = 'b'.repeat(64);
            render(
                <UnifiIntegration
                    {...fixture}
                    discoveredSites={[
                        {
                            mapping_token: token,
                            name: 'Controller south',
                            device_count: 0,
                        },
                    ]}
                />,
            );
            fireEvent.click(screen.getByRole('tab', { name: 'Site mappings' }));
            await recordAction('Controller south', 'Map location');
            fireEvent.click(
                screen.getByRole('combobox', { name: 'Approved Site' }),
            );
            fireEvent.click(
                screen.getByRole('option', { name: 'North House · Site 101' }),
            );
            fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
            fireEvent.click(
                screen.getByRole('button', { name: 'Save mapping' }),
            );
            const options = inertiaMocks.router.post.mock.calls.at(-1)![2];
            React.act(() => {
                options.onSuccess(
                    completedPage(
                        kind === 'missing acknowledgement'
                            ? { actionOutcome: null }
                            : {},
                    ),
                );
                options.onFinish();
            });
            expect(screen.queryByText('Site mapped')).not.toBeInTheDocument();
            expect(
                screen.getByRole('button', { name: 'Save mapping' }),
            ).toBeDisabled();
            expect(
                within(
                    screen.getByRole('dialog', { name: 'Map UniFi location' }),
                ).getByText('Controller south'),
            ).toBeVisible();
            fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
            expect(
                screen.getByRole('heading', {
                    name: 'Discard unsaved UniFi settings?',
                }),
            ).toBeVisible();
        },
    );
});

describe('UniFi refused writes, outcomes and filters', () => {
    it('keeps the page and the draft after a 404 write once a recovery read confirms access', () => {
        const options = startKeySave();
        const preventDefault = vi.fn();
        React.act(() =>
            inertiaMocks.invalid?.({
                detail: {
                    response: {
                        status: 404,
                        config: {
                            method: 'post',
                            url: '/security-devices/integrations/unifi/key',
                            headers: options.headers,
                        },
                        data: 'PRIVATE raw provider error',
                    },
                },
                preventDefault,
            }),
        );
        expect(preventDefault).toHaveBeenCalled();
        expect(
            screen.queryByText(/UniFi records are restricted/),
        ).not.toBeInTheDocument();
        const [url, recovery] = inertiaMocks.router.visit.mock.calls.at(-1)!;
        expect(url).toBe('/security-devices/integrations/unifi');
        expect(recovery).toMatchObject({ method: 'get', preserveState: true });
        React.act(() => {
            recovery.onSuccess({
                component: 'security-devices/integrations/unifi',
                props: {
                    ...fixture,
                    providerConnection: null,
                    auth: inertiaMocks.auth,
                },
            });
            recovery.onFinish();
        });
        expect(
            screen
                .getAllByRole('alert')
                .some((alert) =>
                    alert.textContent?.includes('nothing was saved'),
                ),
        ).toBe(true);
        expect(
            screen.queryByText('PRIVATE raw provider error'),
        ).not.toBeInTheDocument();
        // Nothing was saved, so saving again is allowed and the draft is kept.
        expect(screen.getByRole('button', { name: 'Save key' })).toBeEnabled();
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));
        expect(screen.getByLabelText(/^API key/)).toHaveValue(
            'private-test-key-8844',
        );
    });
    it('still clears the page when the recovery read after a 404 is refused', () => {
        const options = startKeySave();
        React.act(() =>
            inertiaMocks.invalid?.({
                detail: {
                    response: {
                        status: 404,
                        config: {
                            method: 'post',
                            url: '/security-devices/integrations/unifi/key',
                            headers: options.headers,
                        },
                    },
                },
                preventDefault: vi.fn(),
            }),
        );
        const recovery = inertiaMocks.router.visit.mock.calls.at(-1)![1];
        React.act(() =>
            inertiaMocks.invalid?.({
                detail: {
                    response: {
                        status: 403,
                        config: {
                            method: 'get',
                            url: '/security-devices/integrations/unifi',
                            headers: recovery.headers,
                        },
                    },
                },
                preventDefault: vi.fn(),
            }),
        );
        expect(screen.getByText(/UniFi records are restricted/)).toBeVisible();
        expect(
            screen.queryByDisplayValue('private-test-key-8844'),
        ).not.toBeInTheDocument();
    });
    it('confirms a pasted key with surrounding spaces by its trimmed last four characters', () => {
        render(<UnifiIntegration {...fixture} providerConnection={null} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Set up connection' }),
        );
        fireEvent.change(screen.getByLabelText(/^API key/), {
            target: { value: '  private-test-key-8844   ' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save key' }));
        const [url, payload, options] =
            inertiaMocks.router.post.mock.calls.at(-1)!;
        expect(url).toBe('/security-devices/integrations/unifi/key');
        expect(payload).toEqual({ api_key: 'private-test-key-8844' });
        React.act(() => {
            options.onSuccess(
                completedPage({
                    providerConnection: {
                        status: 'disconnected',
                        secret_last4: '8844',
                    },
                }),
            );
            options.onFinish();
        });
        expect(screen.getByText('Key saved')).toBeVisible();
    });
    it('says when disabling made no change instead of claiming a save', () => {
        render(<UnifiIntegration {...fixture} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Disable connection' }),
        );
        fireEvent.click(
            screen.getByLabelText('Provider outage or instability'),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Confirm containment' }),
        );
        const options = inertiaMocks.router.post.mock.calls.at(-1)![2];
        React.act(() => {
            options.onSuccess(
                completedPage({
                    providerConnection: {
                        status: 'disabled',
                        secret_last4: '0042',
                        requires_credential_replacement: true,
                    },
                    actionOutcome: {
                        ...requestAcknowledgement(),
                        outcome: 'unchanged',
                    },
                }),
            );
            options.onFinish();
        });
        expect(
            screen.getByText(
                'The UniFi connection was already disabled, so nothing changed.',
            ),
        ).toBeVisible();
        expect(
            screen.queryByRole('heading', {
                name: 'Disable UniFi connection?',
            }),
        ).not.toBeInTheDocument();
    });
    it('shows the server reason when saving the defaults is refused', () => {
        render(
            <UnifiIntegration
                {...fixture}
                providerConnection={{
                    status: 'connected',
                    defaults: { refresh_interval_minutes: 15 },
                }}
            />,
        );
        fireEvent.click(screen.getByRole('tab', { name: 'Defaults' }));
        fireEvent.click(screen.getByRole('button', { name: 'Edit defaults' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save defaults' }));
        const options = inertiaMocks.router.put.mock.calls.at(-1)![2];
        React.act(() => {
            options.onError({
                'config.quiet_hours_end':
                    'Set an end time as well as a start time, or clear both to turn quiet hours off.',
            });
            options.onFinish();
        });
        expect(
            screen
                .getAllByRole('alert')
                .some((alert) =>
                    alert.textContent?.includes(
                        'Saving the defaults did not complete: Set an end time as well as a start time',
                    ),
                ),
        ).toBe(true);
        expect(
            screen.getByRole('button', { name: 'Save defaults' }),
        ).toBeEnabled();
    });
    it('filters a list view by its own status and searches only visible text', () => {
        render(<UnifiIntegration {...fixture} />);
        fireEvent.click(screen.getByRole('tab', { name: 'Site mappings' }));
        fireEvent.click(screen.getByRole('button', { name: 'Status' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Inactive mapping' }),
        );
        expect(screen.getByText('South House')).toBeVisible();
        expect(screen.queryByText('North House')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Clear Status' }));
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search UniFi records' }),
            { target: { value: 'opaque-do-not-search' } },
        );
        expect(screen.queryByText('Controller north')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('tab', { name: 'Devices' }));
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search UniFi records' }),
            { target: { value: '/security-devices/devices/9' } },
        );
        expect(screen.queryByText('North gateway')).not.toBeInTheDocument();
        fireEvent.change(
            screen.getByRole('searchbox', { name: 'Search UniFi records' }),
            { target: { value: 'model a' } },
        );
        expect(screen.getByText('North gateway')).toBeVisible();
    });
});
