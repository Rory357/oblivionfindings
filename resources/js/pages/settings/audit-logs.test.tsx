import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { routerGet } = vi.hoisted(() => ({
    routerGet: vi.fn(),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <main>{children}</main>
    ),
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <section>{children}</section>
    ),
}));

vi.mock('@/components/page', () => ({
    PageHero: ({
        title,
        description,
        actions,
    }: {
        title: string;
        description: string;
        actions?: React.ReactNode;
    }) => (
        <header>
            <h1>{title}</h1>
            <p>{description}</p>
            {actions}
        </header>
    ),
}));

vi.mock('@/components/ops-stat-card', () => ({
    OpsStatCard: ({ label, value }: { label: string; value: number }) => (
        <div>
            {label}: {value}
        </div>
    ),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    router: {
        get: routerGet,
    },
}));

import AuditLogs from './audit-logs';

const emptyEvents = {
    data: [],
    links: [],
    total: 0,
};

describe('Settings audit logs', () => {
    beforeEach(() => {
        routerGet.mockReset();
    });

    it('exports the submitted server filters rather than an unsubmitted search draft', () => {
        render(
            <AuditLogs
                events={emptyEvents}
                users={[{ id: 42, name: 'Application Auditor' }]}
                filters={{
                    search: 'approved change',
                    user: '42',
                    module: 'security_devices',
                    action: 'updated',
                    date_from: '2026-08-01',
                    date_to: '2026-08-04',
                }}
                stats={{ today: 1, this_week: 2, this_month: 3 }}
            />,
        );

        const exportLink = screen.getByRole('link', { name: /Export CSV/i });
        expect(exportLink).toHaveAttribute(
            'href',
            '/settings/audit-logs/export?search=approved+change&user=42&module=security_devices&action=updated&date_from=2026-08-01&date_to=2026-08-04',
        );

        fireEvent.change(
            screen.getByPlaceholderText('Search audit events...'),
            { target: { value: 'unsubmitted draft' } },
        );

        expect(exportLink.getAttribute('href')).not.toContain(
            'unsubmitted+draft',
        );

        fireEvent.click(screen.getByRole('button', { name: /^Search$/i }));

        expect(routerGet).toHaveBeenCalledWith(
            '/settings/audit-logs',
            {
                search: 'unsubmitted draft',
                user: '42',
                module: 'security_devices',
                action: 'updated',
                date_from: '2026-08-01',
                date_to: '2026-08-04',
            },
            { preserveState: true, preserveScroll: true },
        );
    });

    it('renders canonical audit presentation without exposing unexpected sensitive fields', () => {
        const event = {
            id: 17,
            description: 'Device policy updated',
            event: 'updated',
            module: 'security_devices',
            subject_type: 'DevicePolicy',
            subject_id: 88,
            properties: {
                fields: ['status'],
                before: { status: 'draft' },
                after: { status: 'approved' },
            },
            actor: {
                id: 7,
                name: 'Application Auditor',
                email: 'private-auditor@example.test',
            },
            created_at: null,
            ip_address: '203.0.113.90',
            user_agent: 'Private browser fingerprint',
            meta: 'raw audit metadata',
        };

        render(
            <AuditLogs
                events={{
                    data: [
                        event,
                        {
                            id: 18,
                            description: 'Monitoring observation recorded',
                            event: 'created',
                            module: 'monitoring',
                            subject_type: null,
                            subject_id: null,
                            properties: {},
                            actor: null,
                            created_at: null,
                        },
                    ],
                    links: [],
                    total: 2,
                }}
                users={[]}
                filters={{}}
                stats={{ today: 2, this_week: 2, this_month: 2 }}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Audit Logs' }),
        ).toBeVisible();
        expect(screen.getByText('Application Auditor')).toBeVisible();
        expect(screen.getByText('System')).toBeVisible();
        expect(screen.getAllByText('Security & Devices')[0]).toBeVisible();
        expect(screen.getAllByText('Monitoring')[0]).toBeVisible();
        expect(screen.getByText('DevicePolicy #88')).toBeVisible();

        expect(
            screen.queryByText('private-auditor@example.test'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('203.0.113.90')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Private browser fingerprint'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('raw audit metadata'),
        ).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'View changes' }));

        expect(screen.getByText('status:')).toBeVisible();
        expect(screen.getByText('"draft"')).toBeVisible();
        expect(screen.getByText('"approved"')).toBeVisible();
    });
});
