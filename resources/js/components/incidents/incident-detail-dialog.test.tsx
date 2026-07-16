import { cleanup, fireEvent, render, screen } from '@testing-library/react';
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
    router: { post: vi.fn(), delete: vi.fn(), patch: vi.fn() },
    usePage: () => ({ props: { flash: {} } }),
    useForm: (initial: Record<string, unknown>) => ({
        data: initial,
        errors: {},
        processing: false,
        setData: vi.fn(),
        post: vi.fn(),
        reset: vi.fn(),
        clearErrors: vi.fn(),
    }),
}));

vi.mock('@/components/wizard/shell', async () => {
    const actual = await vi.importActual<
        typeof import('@/components/wizard/shell')
    >('@/components/wizard/shell');

    return {
        ...actual,
        WizardShell: ({
            children,
            footerStart,
            footerEnd,
        }: {
            children?: ReactNode;
            footerStart?: ReactNode;
            footerEnd?: ReactNode;
        }) => (
            <div>
                <main>{children}</main>
                <footer>
                    {footerStart}
                    {footerEnd}
                </footer>
            </div>
        ),
    };
});

import {
    IncidentDetailDialog,
    InvestigationSection,
    LinkedSection,
    type IncidentDetail,
} from './incident-detail-dialog';

function incidentDetail(): IncidentDetail {
    return {
        id: 42,
        ref: 'INC-2026-0042',
        type: 'fall',
        source: 'control_room',
        interactive: true,
        severity: 'high',
        status: 'submitted',
        occurred_at: '2026-07-14T01:30:00Z',
        description: 'A contractor fell beside the loading bay.',
        immediate_action_taken: 'The loading bay was isolated.',
        witnesses: 'Hana Te Rangi',
        is_notifiable: false,
        worksafe_notification_status: null,
        worksafe_notified_at: null,
        worksafe_reference: null,
        potential_severity: null,
        potential_consequence: null,
        investigation_status: null,
        submitted_at: '2026-07-14T01:45:00Z',
        reviewed_at: null,
        review_notes: null,
        closed_at: null,
        closed_outcome: null,
        closed_notes: null,
        reopened_at: null,
        reopened_reason: null,
        control_room_alert_id: 11,
        client: null,
        reporter: { name: 'Ari Patel', email: 'ari@example.test' },
        investigator: null,
        attachments: [],
        followups: [],
        control_room_alert: {
            id: 11,
            status: 'resolved',
            severity: 'high',
            alert_type: 'incident',
            triggered_at: '2026-07-14T01:30:00Z',
            resolved_at: '2026-07-14T02:00:00Z',
            url: '/control-room/alerts/11',
        },
        hs_event: {
            id: 17,
            reference_number: 'HS-2026-0017',
            status: 'open',
            url: '/health-safety/events/17',
            corrective_actions_url:
                '/health-safety/corrective-actions?event=17',
            investigation_required: true,
            worksafe_notifiable: true,
            worksafe_status: 'acknowledged',
            worksafe_reference: 'WS-2026-7788',
            worksafe_notified_at: '2026-07-14T02:05:00Z',
            worksafe_acknowledged_at: '2026-07-14T02:25:00Z',
            handover: {
                status: 'accepted',
                owner: { id: 8, name: 'Moana Rangi' },
                accepted_by: { id: 9, name: 'Tama Lewis' },
                accepted_at: '2026-07-14T02:15:00Z',
                notes: 'Accepted for formal investigation.',
                can_accept: false,
            },
            investigation: null,
            corrective_actions: [],
        },
        can: {
            update: false,
            submit: false,
            review: false,
            close: false,
            reopen: false,
            followupsManage: false,
            followupsComplete: false,
            portalManage: false,
            raiseCorrectiveAction: false,
        },
        assignable_staff: [],
        corrective_action_owners: [],
    } as IncidentDetail;
}

afterEach(cleanup);

describe('IncidentDetailDialog H&S handover', () => {
    it('shows the canonical H&S WorkSafe and accepted ownership state in the overview', () => {
        render(
            <IncidentDetailDialog
                detail={incidentDetail()}
                open
                onClose={() => {}}
            />,
        );

        expect(
            screen.getByText('WorkSafe NZ notifiable event.'),
        ).toBeInTheDocument();
        expect(screen.getByText(/Acknowledged by WorkSafe/)).toHaveTextContent(
            'WS-2026-7788',
        );
        expect(screen.getByText('Accepted into H&S')).toBeInTheDocument();
        expect(screen.getByText('Moana Rangi')).toBeInTheDocument();
        expect(screen.getByText('Tama Lewis')).toBeInTheDocument();
        expect(
            screen.getByText('Accepted for formal investigation.'),
        ).toBeInTheDocument();
    });

    it('names the unassigned ownership state while H&S acceptance is pending', () => {
        const detail = incidentDetail();
        if (detail.hs_event) {
            detail.hs_event.handover = {
                status: 'awaiting_acceptance',
                owner: null,
                accepted_by: null,
                accepted_at: null,
                notes: null,
                can_accept: true,
            };
        }

        render(
            <IncidentDetailDialog detail={detail} open onClose={() => {}} />,
        );

        expect(screen.getByText('Awaiting H&S acceptance')).toBeInTheDocument();
        expect(screen.getByText('No H&S owner assigned')).toBeInTheDocument();
    });

    it('keeps restricted H&S and Control Room records visible without dead-end links', () => {
        const detail = incidentDetail();
        Object.assign(detail.control_room_alert ?? {}, { url: null });
        Object.assign(detail.hs_event ?? {}, {
            url: null,
            corrective_actions_url: null,
        });

        const { unmount } = render(<InvestigationSection d={detail} />);

        expect(screen.getByText('HS-2026-0017')).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Open in Health & Safety/i }),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Open register')).not.toBeInTheDocument();

        unmount();
        render(<LinkedSection d={detail} clientName="No client linked" />);

        expect(screen.getByText('Control Room alert')).toBeInTheDocument();
        expect(screen.getByText('Health & Safety event')).toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Control Room alert/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /Health & Safety event/i }),
        ).not.toBeInTheDocument();
    });

    it('offers only eligible H&S owners when raising a corrective action', () => {
        const detail = incidentDetail();
        detail.can.raiseCorrectiveAction = true;
        detail.assignable_staff = [
            { id: 41, name: 'General Follow-up Assignee' },
        ];
        detail.corrective_action_owners = [
            { id: 82, name: 'Eligible H&S Owner' },
        ];

        render(<InvestigationSection d={detail} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Raise corrective action' }),
        );
        fireEvent.click(screen.getByRole('combobox', { name: 'Choose owner' }));

        expect(
            screen.getByRole('option', { name: 'Eligible H&S Owner' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('option', {
                name: 'General Follow-up Assignee',
            }),
        ).not.toBeInTheDocument();
    });
});
