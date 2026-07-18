import { render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';
import ItChangesIndex from './index';
import ItChangeShow from './show';

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <main>{children}</main>
    ),
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
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        setData: vi.fn(),
        post: vi.fn(),
        patch: vi.fn(),
        reset: vi.fn(),
        processing: false,
        errors: {},
    }),
}));

const ticket = {
    id: 52,
    reference: 'IT-000052',
    title: 'Replace gateway policy',
    priority: 'high',
    status: 'in_progress',
    workflow_state: 'scheduled',
    href: '/it/tickets/52',
};

describe('IT change management workspaces', () => {
    it('renders a scannable change register with governed deep links', () => {
        render(
            <ItChangesIndex
                changes={{
                    data: [
                        {
                            ...ticket,
                            change_id: 9,
                            change_type: 'normal',
                            risk_level: 'high',
                            is_restricted: true,
                            impact_summary: 'Site traffic will fail over.',
                            maintenance_starts_at: '2026-07-19T22:00:00Z',
                            maintenance_ends_at: '2026-07-19T23:00:00Z',
                            maintenance_state: 'upcoming',
                        },
                    ],
                    links: [],
                    total: 1,
                }}
                filters={{ type: null, risk: null, state: null, q: null }}
                can={{ manage: true }}
            />,
        );

        expect(screen.getByRole('heading', { name: 'Changes' })).toBeVisible();
        expect(screen.getByRole('link', { name: /IT-000052/ })).toHaveAttribute(
            'href',
            '/it/changes/9',
        );
        expect(screen.getByText('Upcoming window')).toBeVisible();
        expect(
            screen.getByRole('button', { name: /New change/i }),
        ).toBeVisible();
    });

    it('makes maintenance approval plans validation and the shared ticket explicit', () => {
        render(
            <ItChangeShow
                change={{
                    id: 9,
                    change_type: 'normal',
                    risk_level: 'high',
                    is_restricted: true,
                    impact_summary: 'Site traffic will fail over.',
                    implementation_plan: 'Apply tested policy.',
                    validation_plan: 'Verify critical services.',
                    backout_plan: 'Restore signed export.',
                    maintenance_starts_at: '2026-07-19T22:00:00Z',
                    maintenance_ends_at: '2026-07-19T23:00:00Z',
                    maintenance_state: 'upcoming',
                    actual_outcome: null,
                    validation_result: null,
                    validation_summary: null,
                    backout_summary: null,
                    pir_summary: null,
                    implemented_at: null,
                    implemented_by: null,
                    validated_at: null,
                    validated_by: null,
                    backed_out_at: null,
                    reviewed_at: null,
                    reviewed_by: null,
                }}
                ticket={{
                    ...ticket,
                    description: 'Controlled gateway update.',
                    category: 'network',
                    next_action: 'Wait for maintenance window.',
                    requires_approval: true,
                    approval: {
                        id: 4,
                        status: 'approved',
                        reason: 'CAB approved.',
                        requester: null,
                        approver: { id: 2, name: 'Approver' },
                    },
                    sla_state: 'ok',
                    comments_count: 2,
                    tasks_count: 3,
                    approvals_count: 1,
                    attachments_count: 1,
                    events_count: 8,
                }}
                links={{
                    services: [],
                    sites: [],
                    devices: [],
                    alerts: [],
                    incidents: [],
                    problems: [],
                }}
                options={{
                    services: [],
                    sites: [],
                    devices: [],
                    alerts: [],
                    incidents: [],
                    problems: [],
                }}
                can={{ manage: false }}
            />,
        );

        expect(
            screen.getByRole('link', {
                name: /Open canonical ticket workspace/i,
            }),
        ).toHaveAttribute('href', '/it/tickets/52');
        expect(screen.getByText('Maintenance window')).toBeVisible();
        expect(screen.getByText('Approved')).toBeVisible();
        expect(screen.getByText('Implementation plan')).toBeVisible();
        expect(screen.getByText('Independent validation')).toBeVisible();
        expect(screen.getByText('Shared work record')).toBeVisible();
    });
});
