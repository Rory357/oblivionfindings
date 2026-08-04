import { fireEvent, render, screen, within } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AuditIndex from './index';

const mocks = vi.hoisted(() => ({
    routerGet: vi.fn(),
    routerVisit: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
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
        get: mocks.routerGet,
        visit: mocks.routerVisit,
    },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <main>{children}</main>
    ),
}));

const activeFilters = {
    q: 'gateway',
    action: 'updated',
    user_id: 7,
    client_id: 12,
    module: 'it',
    date_from: '2026-08-01',
    date_to: '2026-08-04',
};

const baseProps = () => ({
    logs: {
        data: [
            {
                id: 41,
                action: 'it.change.updated',
                description: 'A controlled change was updated.',
                event: 'updated',
                module: 'it',
                created_at: '2026-08-04T10:15:00+12:00',
                actor: {
                    id: 7,
                    name: 'Aroha Auditor',
                    email: 'private-auditor@example.test',
                },
                client: { id: 12, name: 'Mere Rangi' },
                subject_type: 'It Change',
                subject_id: 88,
                properties: {
                    fields: ['status', 'maintenance_starts_at'],
                    before: {
                        status: 'planned',
                        credential: 'raw-before-secret',
                    },
                    after: {
                        status: 'approved',
                        credential: 'raw-after-secret',
                    },
                },
                ip_address: '203.0.113.72',
                user_agent: 'raw-browser-fingerprint',
                meta: { authorization: 'raw-auth-token' },
            },
        ],
        links: [],
    },
    filters: activeFilters,
    filter_options: {
        users: [{ id: 7, name: 'Aroha Auditor' }],
        clients: [{ id: 12, name: 'Mere Rangi' }],
    },
});

describe('Audit log browser boundary', () => {
    beforeEach(() => {
        mocks.routerGet.mockClear();
        mocks.routerVisit.mockClear();
    });

    it('renders the bounded audit summary without exposing raw sensitive properties', () => {
        render(<AuditIndex {...baseProps()} />);

        const table = screen.getByRole('table');

        expect(within(table).getByText('it.change.updated')).toBeVisible();
        expect(within(table).getByText('It Change#88')).toBeVisible();
        expect(within(table).getByText('Aroha Auditor')).toBeVisible();
        expect(
            within(table).getByRole('link', { name: 'Mere Rangi' }),
        ).toHaveAttribute('href', '/clients/12');
        expect(
            within(table).getByText('Fields: status, maintenance_starts_at'),
        ).toBeVisible();

        expect(document.body).not.toHaveTextContent(
            'private-auditor@example.test',
        );
        expect(document.body).not.toHaveTextContent('raw-before-secret');
        expect(document.body).not.toHaveTextContent('raw-after-secret');
        expect(document.body).not.toHaveTextContent('203.0.113.72');
        expect(document.body).not.toHaveTextContent('raw-browser-fingerprint');
        expect(document.body).not.toHaveTextContent('raw-auth-token');
    });

    it('submits canonical bounded filters while preserving the active query', () => {
        const { container } = render(<AuditIndex {...baseProps()} />);
        const [userFilter, clientFilter, moduleFilter] =
            screen.getAllByRole('combobox');
        const [fromFilter, toFilter] = Array.from(
            container.querySelectorAll<HTMLInputElement>('input[type="date"]'),
        );

        fireEvent.change(
            screen.getByPlaceholderText('action, user or client…'),
            {
                target: { value: 'offline gateway' },
            },
        );
        expect(mocks.routerGet).toHaveBeenLastCalledWith(
            '/audit-logs',
            { ...activeFilters, q: 'offline gateway' },
            { preserveState: true, replace: true },
        );

        fireEvent.change(screen.getByPlaceholderText('clients.view'), {
            target: { value: 'security.device.viewed' },
        });
        expect(mocks.routerGet).toHaveBeenLastCalledWith(
            '/audit-logs',
            { ...activeFilters, action: 'security.device.viewed' },
            { preserveState: true, replace: true },
        );

        fireEvent.change(userFilter, { target: { value: '' } });
        expect(mocks.routerGet).toHaveBeenLastCalledWith(
            '/audit-logs',
            { ...activeFilters, user_id: undefined },
            { preserveState: true, replace: true },
        );

        fireEvent.change(clientFilter, { target: { value: '12' } });
        expect(mocks.routerGet).toHaveBeenLastCalledWith(
            '/audit-logs',
            { ...activeFilters, client_id: 12 },
            { preserveState: true, replace: true },
        );

        fireEvent.change(moduleFilter, { target: { value: 'monitoring' } });
        expect(mocks.routerGet).toHaveBeenLastCalledWith(
            '/audit-logs',
            { ...activeFilters, module: 'monitoring' },
            { preserveState: true, replace: true },
        );

        fireEvent.change(fromFilter, { target: { value: '2026-07-01' } });
        expect(mocks.routerGet).toHaveBeenLastCalledWith(
            '/audit-logs',
            { ...activeFilters, date_from: '2026-07-01' },
            { preserveState: true, replace: true },
        );

        fireEvent.change(toFilter, { target: { value: '' } });
        expect(mocks.routerGet).toHaveBeenLastCalledWith(
            '/audit-logs',
            { ...activeFilters, date_to: undefined },
            { preserveState: true, replace: true },
        );
    });

    it('clears all audit-log filters through the canonical route', () => {
        render(<AuditIndex {...baseProps()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Clear' }));

        expect(mocks.routerGet).toHaveBeenCalledWith(
            '/audit-logs',
            {},
            { preserveState: true, replace: true },
        );
    });
});
