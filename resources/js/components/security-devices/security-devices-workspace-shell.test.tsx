import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    SecurityDevicesWorkspaceShell,
    type SecurityDevicesWorkspace,
} from './security-devices-workspace-shell';

const workspace: SecurityDevicesWorkspace = {
    slug: 'security',
    title: 'Security',
    description: 'Physical security technology across every authorised site.',
    canonicalHref: '/security-devices/security',
    activeTab: 'access-control',
    activeTabState: 'available',
    tabs: [
        {
            key: 'overview',
            label: 'Overview',
            description: 'Security technology posture.',
            state: 'available',
            stateLabel: 'Available',
        },
        {
            key: 'access-control',
            label: 'Access Control',
            description: 'Doors, locks, readers and panels.',
            state: 'available',
            stateLabel: 'Available',
        },
    ],
    summary: {
        devices: 12,
        attention: 2,
        monitored: 9,
        unmonitored: 3,
    },
    freshness: {
        state: 'stale',
        label: 'Latest device observation',
        observedAt: '2026-07-19T01:00:00.000Z',
    },
};

describe('SecurityDevicesWorkspaceShell', () => {
    it('renders understandable URL-driven tabs, summary and freshness', () => {
        render(
            <SecurityDevicesWorkspaceShell
                workspace={workspace}
                filters={{ status: 'offline', search: 'door reader' }}
            >
                <p>Canonical access-control inventory</p>
            </SecurityDevicesWorkspaceShell>,
        );

        expect(
            screen.getByRole('navigation', { name: 'Security workspace tabs' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Access Control' }),
        ).toHaveAttribute('aria-current', 'page');
        expect(screen.getByRole('link', { name: 'Overview' })).toHaveAttribute(
            'href',
            '/security-devices/security?status=offline&search=door+reader&tab=overview',
        );
        expect(screen.getByText('12')).toBeInTheDocument();
        expect(screen.getByText('Unmonitored')).toBeInTheDocument();
        expect(
            screen.getByText('Latest device observation'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Canonical access-control inventory'),
        ).toBeInTheDocument();
    });

    it('explains unavailable runtime capability and does not render misleading content', () => {
        render(
            <SecurityDevicesWorkspaceShell
                workspace={{
                    ...workspace,
                    activeTab: 'traffic-capacity',
                    activeTabState: 'not_configured',
                    tabs: [
                        ...workspace.tabs,
                        {
                            key: 'traffic-capacity',
                            label: 'Traffic & capacity',
                            description:
                                'Retained traffic and utilisation evidence.',
                            state: 'not_configured',
                            stateLabel: 'Not configured',
                        },
                    ],
                }}
                filters={{}}
            >
                <p>Fabricated traffic chart</p>
            </SecurityDevicesWorkspaceShell>,
        );

        expect(screen.getByText('Not configured')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Retained traffic and utilisation evidence is not configured for this workspace yet.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Fabricated traffic chart'),
        ).not.toBeInTheDocument();
    });
});
