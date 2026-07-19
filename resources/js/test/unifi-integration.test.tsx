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
    it('disables inactive mapping sync with an accessible recovery explanation', () => {
        render(
            <UnifiIntegration
                tenantSecret={{ status: 'connected', secret_last4: '0042' }}
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
