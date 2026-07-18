import { render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';
import ItProblemsIndex from './index';
import ItProblemShow from './show';

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
    id: 42,
    reference: 'IT-000042',
    title: 'Repeated VPN failures',
    priority: 'high',
    status: 'in_progress',
    workflow_state: 'known_error',
    href: '/it/tickets/42',
};

describe('IT problem management workspaces', () => {
    it('renders the production-backed register with a problem deep link', () => {
        render(
            <ItProblemsIndex
                problems={{
                    data: [
                        {
                            ...ticket,
                            problem_id: 7,
                            impact_summary: 'Remote access is intermittent.',
                            known_error_at: null,
                        },
                    ],
                    links: [],
                    total: 1,
                }}
                filters={{ state: null, q: null }}
                can={{ manage: true }}
            />,
        );
        expect(
            screen.getByRole('heading', { name: 'Problems & known errors' }),
        ).toBeVisible();
        expect(screen.getByRole('link', { name: /IT-000042/ })).toHaveAttribute(
            'href',
            '/it/problems/7',
        );
        expect(
            screen.getByRole('button', { name: /New problem/i }),
        ).toBeVisible();
    });

    it('makes the shared ticket workspace and known-error knowledge explicit', () => {
        render(
            <ItProblemShow
                problem={{
                    id: 7,
                    impact_summary: 'Remote access is intermittent.',
                    root_cause: 'Old certificate chain.',
                    workaround: 'Use the secondary gateway.',
                    corrective_action: 'Replace both chains.',
                    known_error_at: '2026-07-19T00:00:00Z',
                }}
                ticket={{
                    ...ticket,
                    description: 'Shared failure pattern.',
                    category: 'network',
                    next_action: 'Schedule change.',
                    sla_state: 'ok',
                    first_response_due_at: null,
                    resolution_due_at: null,
                    comments_count: 2,
                    tasks_count: 1,
                    approvals_count: 0,
                    attachments_count: 1,
                    events_count: 4,
                }}
                incidents={[]}
                permanentFixChange={null}
                incidentOptions={[]}
                changeOptions={[]}
                can={{ manage: false }}
            />,
        );
        expect(
            screen.getByRole('link', {
                name: /Open canonical ticket workspace/i,
            }),
        ).toHaveAttribute('href', '/it/tickets/42');
        expect(screen.getByText('Root cause')).toBeVisible();
        expect(screen.getByText('Safe workaround')).toBeVisible();
        expect(screen.getByText('Shared work record')).toBeVisible();
    });
});
