import { render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';
import ItMajorIncidentsIndex from './index';
import ItMajorIncidentShow from './show';

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
    id: 60,
    reference: 'IT-000060',
    title: 'All-site identity outage',
    priority: 'urgent',
    status: 'in_progress',
    workflow_state: 'responding',
    href: '/it/tickets/60',
};

describe('IT major incident command workspaces', () => {
    it('renders a command register with severity cadence and declaration action', () => {
        render(
            <ItMajorIncidentsIndex
                majorIncidents={{
                    data: [
                        {
                            ...ticket,
                            major_incident_id: 12,
                            severity: 'sev1',
                            impact_summary:
                                'Authentication is unavailable across all sites.',
                            commander: { id: 2, name: 'Incident Commander' },
                            communications_lead: {
                                id: 3,
                                name: 'Communications Lead',
                            },
                            next_update_due_at: '2026-07-19T22:30:00Z',
                            update_state: 'overdue',
                        },
                    ],
                    links: [],
                    total: 1,
                }}
                filters={{ severity: null, state: null, q: null }}
                options={{ agents: [{ id: 3, name: 'Communications Lead' }] }}
                can={{ manage: true }}
            />,
        );

        expect(
            screen.getByRole('heading', { name: 'Major incidents' }),
        ).toBeVisible();
        expect(screen.getByRole('link', { name: /IT-000060/ })).toHaveAttribute(
            'href',
            '/it/major-incidents/12',
        );
        expect(screen.getAllByText('Update overdue')[0]).toBeVisible();
        expect(
            screen.getByRole('button', { name: /Declare major incident/i }),
        ).toBeVisible();
    });

    it('makes command accountability safe communications and shared work explicit', () => {
        render(
            <ItMajorIncidentShow
                majorIncident={{
                    id: 12,
                    severity: 'sev1',
                    impact_summary:
                        'Authentication is unavailable across all sites.',
                    commander: { id: 2, name: 'Incident Commander' },
                    communications_lead: { id: 3, name: 'Communications Lead' },
                    target_update_minutes: 30,
                    declared_at: '2026-07-19T22:00:00Z',
                    next_update_due_at: '2026-07-19T22:30:00Z',
                    update_state: 'overdue',
                    restoration_summary: null,
                    restored_at: null,
                    root_cause_summary: null,
                    review_summary: null,
                    reviewed_at: null,
                }}
                ticket={{
                    ...ticket,
                    description: 'Staff cannot authenticate.',
                    category: 'account',
                    next_action: 'Identity vendor bridge.',
                    sla_state: 'at_risk',
                    resolution_summary: null,
                    comments_count: 2,
                    tasks_count: 3,
                    approvals_count: 0,
                    attachments_count: 1,
                    events_count: 8,
                }}
                updates={[
                    {
                        id: 1,
                        update_kind: 'stakeholder_update',
                        audience: 'staff',
                        summary: 'Authentication remains unavailable.',
                        service_status: 'major_outage',
                        published_at: '2026-07-19T22:10:00Z',
                        author: { id: 3, name: 'Communications Lead' },
                    },
                ]}
                links={{
                    services: [{ id: 4, name: 'Identity', status: 'degraded' }],
                    sites: [],
                    incidents: [],
                    alert: null,
                }}
                options={{
                    agents: [],
                    services: [],
                    sites: [],
                    incidents: [],
                    alerts: [],
                }}
                can={{ manage: true }}
            />,
        );

        expect(screen.getByText('Live communications')).toBeVisible();
        expect(screen.getAllByText('Update overdue')[0]).toBeVisible();
        expect(screen.getByText('Incident Commander')).toBeVisible();
        expect(screen.getByText('Staff')).toBeVisible();
        expect(screen.getByText('Shared work record')).toBeVisible();
        expect(
            screen.getByRole('link', {
                name: /Open canonical ticket workspace/i,
            }),
        ).toHaveAttribute('href', '/it/tickets/60');
    });
});
