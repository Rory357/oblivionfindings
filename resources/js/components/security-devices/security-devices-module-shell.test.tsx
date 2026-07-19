import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { SecurityDevicesModuleShell } from './security-devices-module-shell';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => ({
        url: '/security-devices/security?tab=cctv',
        props: {
            auth: {
                can: {
                    securityDevices: {
                        viewAny: true,
                        devicesView: true,
                        groupsManage: true,
                        eventsView: true,
                        maintenanceView: true,
                        integrationsView: true,
                        integrationsManage: true,
                        reportsView: true,
                    },
                },
            },
        },
    }),
}));

describe('Security & Devices module shell', () => {
    it('shows grouped desktop and mobile navigation with the current workspace selected', () => {
        render(
            <SecurityDevicesModuleShell>
                <p>Workspace content</p>
            </SecurityDevicesModuleShell>,
        );

        expect(screen.getByText('Workspace content')).toBeVisible();
        for (const group of ['Overview', 'Workspaces', 'Operations', 'Setup']) {
            expect(screen.getAllByText(group)).toHaveLength(2);
        }

        const securityLinks = screen.getAllByRole('link', {
            name: /^Security$/,
        });
        expect(securityLinks).toHaveLength(2);
        for (const link of securityLinks) {
            expect(link).toHaveAttribute('href', '/security-devices/security');
            expect(link).toHaveAttribute('aria-current', 'page');
        }

        expect(screen.getByText('Security & Devices navigation')).toBeVisible();
    });
});
