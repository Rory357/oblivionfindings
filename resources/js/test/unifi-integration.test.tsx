import { fireEvent, render, screen, within } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import UnifiIntegration from '@/pages/security-devices/integrations/unifi';

vi.mock('@/components/page', () => ({
    PageHero: () => null,
    PageLayout: ({ children }: { children: React.ReactNode }) => (
        <main>{children}</main>
    ),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const inertiaMocks = vi.hoisted(() => ({
    router: {
        delete: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: inertiaMocks.router,
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        reset: vi.fn(),
    }),
}));

beforeEach(() => {
    vi.clearAllMocks();
});

describe('UniFi integration', () => {
    it('requires a reason before confirming the fail-closed disable workflow', () => {
        render(
            <UnifiIntegration
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
                name: 'Disable the UniFi connection?',
            }),
        ).toBeVisible();
        expect(
            screen.getByText(/Scheduled collection, manual provider sync/),
        ).toBeVisible();
        expect(
            screen.getByText(
                /Existing Devices, mappings, cursors, sync history/,
            ),
        ).toBeVisible();

        const confirm = screen.getByRole('button', {
            name: 'Disable and revoke use',
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
            screen.getByRole('button', { name: 'Test Connection' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Sync UniFi Locations' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Replace Key to Recover' }),
        ).toBeEnabled();
        expect(
            screen.queryByRole('button', { name: 'Disable connection' }),
        ).not.toBeInTheDocument();
    });

    it('disables inactive mapping sync with an accessible recovery explanation', () => {
        render(
            <UnifiIntegration
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

        const inactiveRow = screen.getByText('Inactive House').closest('tr');
        expect(inactiveRow).not.toBeNull();
        if (!inactiveRow) {
            throw new Error('Expected inactive mapping row');
        }
        const inactiveSync = within(inactiveRow).getByRole('button', {
            name: 'Sync unavailable',
        });
        expect(inactiveSync).toBeDisabled();
        expect(inactiveSync).toHaveAccessibleDescription(
            'This mapping is inactive. Remove it and map the location again before syncing devices.',
        );
        fireEvent.click(inactiveSync);
        expect(inertiaMocks.router.post).not.toHaveBeenCalled();

        const activeRow = screen.getByText('Active House').closest('tr');
        expect(activeRow).not.toBeNull();
        if (!activeRow) {
            throw new Error('Expected active mapping row');
        }
        const activeSync = within(activeRow).getByRole('button', {
            name: 'Sync Devices',
        });
        expect(activeSync).toBeEnabled();
        fireEvent.click(activeSync);
        expect(inertiaMocks.router.post).toHaveBeenCalledWith(
            '/security-devices/integrations/unifi/sync-devices',
            { site_config_id: 10 },
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
