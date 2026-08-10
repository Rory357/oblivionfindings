import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { SecurityDevicesWorkspace } from '@/components/security-devices/security-devices-workspace-shell';

import {
    CategoryRegisterAction,
    CategorySearchInput,
    CategoryWorkspaceHero,
} from './category';

const workspace: SecurityDevicesWorkspace = {
    slug: 'network-it',
    title: 'Network & IT',
    description: 'Network and infrastructure operations.',
    canonicalHref: '/security-devices/network-it',
    activeTab: 'overview',
    activeTabState: 'available',
    tabs: [
        {
            key: 'overview',
            label: 'Overview',
            description: 'Network operations overview.',
            state: 'available',
            stateLabel: 'Available',
        },
        {
            key: 'traffic-capacity',
            label: 'Traffic & capacity',
            description: 'Retained traffic and capacity evidence.',
            state: 'not_configured',
            stateLabel: 'Not configured',
        },
    ],
    summary: {
        devices: 12,
        attention: 2,
        monitored: 9,
        unmonitored: 3,
    },
    freshness: {
        state: 'current',
        label: 'Latest device observation',
        observedAt: '2026-08-11T01:00:00.000Z',
    },
};

describe('CategorySearchInput', () => {
    it('gives the workspace search field an accessible name', () => {
        render(
            <CategorySearchInput
                title="Security"
                value=""
                onChange={vi.fn()}
                onSubmit={vi.fn()}
            />,
        );

        expect(
            screen.getByRole('textbox', { name: 'Search security' }),
        ).toHaveAttribute('placeholder', 'Search security...');
    });
});

describe('CategoryRegisterAction', () => {
    it('does not offer a registration route to view-only operators', () => {
        render(
            <CategoryRegisterAction
                canRegister={false}
                label="Register device"
                onRegister={vi.fn()}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Register device' }),
        ).toBeNull();
    });

    it('opens the governed registration dialog with explicit create capability', () => {
        const onRegister = vi.fn();

        render(
            <CategoryRegisterAction
                canRegister
                label="Register device"
                onRegister={onRegister}
            />,
        );

        const action = screen.getByRole('button', {
            name: 'Register device',
        });

        expect(
            screen.queryByRole('link', { name: 'Register device' }),
        ).toBeNull();
        fireEvent.click(action);
        expect(onRegister).toHaveBeenCalledOnce();
    });
});

describe('CategoryWorkspaceHero', () => {
    it('uses the full branded workspace hero with integrated stats and tabs', () => {
        render(
            <CategoryWorkspaceHero
                pageConfig={{
                    slug: 'network-it',
                    title: 'Network & IT',
                    description: 'Network and infrastructure operations.',
                    emptyTitle: 'No devices',
                    emptyDescription: 'No network devices are available.',
                    icon: 'network-it',
                    domain: 'it_infrastructure',
                    categories: null,
                }}
                workspace={workspace}
                filters={{ status: 'offline' }}
                canRegister
                registerLabel="Register device"
                onRegister={vi.fn()}
            />,
        );

        const heading = screen.getByRole('heading', {
            name: 'Network & IT',
            level: 1,
        });

        expect(heading.closest('.rounded-2xl')).toHaveClass(
            'text-primary-foreground',
        );
        expect(screen.getByText('12')).toBeInTheDocument();
        expect(screen.getByText('Need attention')).toBeInTheDocument();
        expect(
            screen.getByRole('navigation', {
                name: 'Network & IT workspace tabs',
            }),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Overview' })).toHaveAttribute(
            'aria-current',
            'page',
        );
        expect(
            screen.getByRole('link', { name: /Traffic & capacity/ }),
        ).toHaveAttribute(
            'href',
            '/security-devices/network-it?status=offline&tab=traffic-capacity',
        );
        expect(
            screen.getByRole('button', { name: 'Register device' }),
        ).toBeInTheDocument();
    });
});
