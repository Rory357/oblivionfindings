import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

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
    router: {},
    usePage: () => ({ props: { flash: {} } }),
    useForm: () => ({}),
}));

import {
    LinkedSection,
    type AlertWorkspaceDetail,
} from './alert-workspace-dialog';

function detail(
    handover: AlertWorkspaceDetail['linked_hs_event'] extends infer T
        ? T extends { handover: infer H }
            ? H
            : never
        : never,
    href: string | null,
): AlertWorkspaceDetail {
    return {
        alert: { context: {}, asset: null },
        client: null,
        linked_hs_event: {
            id: 17,
            reference_number: 'HS-2026-0017',
            status: 'open',
            worksafe_notifiable: true,
            worksafe_status: 'pending',
            worksafe_reference: null,
            worksafe_notified_at: null,
            worksafe_acknowledged_at: null,
            handover,
            investigation_required: true,
            investigation: null,
            href,
        },
    } as unknown as AlertWorkspaceDetail;
}

afterEach(cleanup);

describe('Control Room linked H&S handover', () => {
    it('shows accepted owner, actor and time without advertising an inaccessible H&S link', () => {
        render(
            <LinkedSection
                d={detail(
                    {
                        status: 'accepted',
                        owner: { id: 8, name: 'Moana Rangi' },
                        accepted_by: { id: 9, name: 'Tama Lewis' },
                        accepted_at: '2026-07-14T02:15:00Z',
                        notes: 'Accepted for formal investigation.',
                    },
                    null,
                )}
            />,
        );

        const row = screen.getByText('Health & Safety event').closest('div');
        expect(row).toHaveTextContent('Accepted into H&S');
        expect(row).toHaveTextContent('owner Moana Rangi');
        expect(row).toHaveTextContent('accepted by Tama Lewis');
        expect(
            screen.getByText('Health & Safety event').closest('a'),
        ).toBeNull();
    });

    it('shows awaiting acceptance and links only when the viewer can open H&S', () => {
        render(
            <LinkedSection
                d={detail(
                    {
                        status: 'awaiting_acceptance',
                        owner: null,
                        accepted_by: null,
                        accepted_at: null,
                        notes: null,
                    },
                    '/health-safety/events/17',
                )}
            />,
        );

        expect(screen.getByText(/Awaiting H&S acceptance/)).toBeInTheDocument();
        expect(
            screen.getByText('Health & Safety event').closest('a'),
        ).toHaveAttribute('href', '/health-safety/events/17');
    });

    it('opens the canonical live IT incident without creating another work register', () => {
        const d = detail(
            {
                status: 'awaiting_acceptance',
                owner: null,
                accepted_by: null,
                accepted_at: null,
                notes: null,
            },
            null,
        );
        d.linked_it_work = {
            id: 42,
            reference: 'IT-000042',
            title: 'Core switch monitoring outage',
            status: 'in_progress',
            status_reason: 'monitoring_recovered',
            priority: 'urgent',
            sla_state: 'at_risk',
            resolution_due_at: '2026-07-18T02:00:00Z',
            monitoring_recovered_at: '2026-07-18T01:30:00Z',
            assignee: { id: 8, name: 'Moana Rangi' },
            href: '/it/tickets/42',
        };

        render(<LinkedSection d={d} />);

        const link = screen.getByText('IT incident work').closest('a');
        expect(link).toHaveAttribute('href', '/it/tickets/42');
        expect(link).toHaveTextContent('IT-000042');
        expect(link).toHaveTextContent('with Moana Rangi');
        expect(link).toHaveTextContent(
            'monitoring recovered; technician closure still required',
        );
        expect(screen.queryByText(/create IT ticket/i)).not.toBeInTheDocument();
    });
});
