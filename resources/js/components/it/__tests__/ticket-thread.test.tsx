import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { TicketThread } from '../ticket-thread';

vi.mock('@inertiajs/react', () => ({
    useForm: () => ({
        data: { body: '', is_internal: false, attachments: [] },
        processing: false,
        setData: vi.fn(),
        post: vi.fn(),
        reset: vi.fn(),
    }),
}));

describe('TicketThread', () => {
    it('replaces the composer with a clear read-only explanation for settled work', () => {
        render(
            <TicketThread
                ticketId={42}
                requesterName="Taylor Requester"
                description="The VPN stopped connecting."
                comments={[]}
                events={[]}
                canInternal={false}
                canReply={false}
                replyUnavailableReason="Reopen this ticket before adding another reply."
            />,
        );

        expect(
            screen.getByText('This conversation is read-only'),
        ).toBeVisible();
        expect(
            screen.getByText('Reopen this ticket before adding another reply.'),
        ).toBeVisible();
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Send reply' }),
        ).not.toBeInTheDocument();
    });

    it('turns specialised work activity into clear technician language', () => {
        render(
            <TicketThread
                ticketId={42}
                requesterName="Taylor Requester"
                description={null}
                comments={[]}
                canInternal
                canReply={false}
                events={[
                    {
                        id: 1,
                        type: 'workflow_transitioned',
                        payload: {
                            from_workflow_state: 'investigating',
                            to_workflow_state: 'known_error',
                        },
                        actor: 'Taylor Technician',
                        at: null,
                        at_human: '1m',
                    },
                    {
                        id: 2,
                        type: 'problem_updated',
                        payload: {},
                        actor: 'Taylor Technician',
                        at: null,
                        at_human: '1m',
                    },
                    {
                        id: 3,
                        type: 'change_updated',
                        payload: {},
                        actor: 'Taylor Technician',
                        at: null,
                        at_human: '1m',
                    },
                    {
                        id: 4,
                        type: 'major_incident_updated',
                        payload: {},
                        actor: 'Taylor Technician',
                        at: null,
                        at_human: '1m',
                    },
                    {
                        id: 5,
                        type: 'major_incident_update_published',
                        payload: { audience: 'staff' },
                        actor: 'Taylor Technician',
                        at: null,
                        at_human: '1m',
                    },
                    {
                        id: 6,
                        type: 'approval_requested',
                        payload: {},
                        actor: 'Taylor Technician',
                        at: null,
                        at_human: '1m',
                    },
                    {
                        id: 7,
                        type: 'approval_approved',
                        payload: {},
                        actor: 'Alex Approver',
                        at: null,
                        at_human: '1m',
                    },
                    {
                        id: 8,
                        type: 'routing_applied',
                        payload: {},
                        actor: 'System',
                        at: null,
                        at_human: '1m',
                    },
                    {
                        id: 9,
                        type: 'merged',
                        payload: {
                            direction: 'from',
                            source_reference: 'IT-000041',
                        },
                        actor: 'Taylor Technician',
                        at: null,
                        at_human: '1m',
                    },
                    {
                        id: 10,
                        type: 'email_received',
                        payload: {},
                        actor: 'Taylor Requester',
                        at: null,
                        at_human: '1m',
                    },
                    {
                        id: 11,
                        type: 'api_public_comment',
                        payload: {},
                        actor: 'Monitoring API',
                        at: null,
                        at_human: '1m',
                    },
                ]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /Activity/ }));

        expect(
            screen.getByText('moved Investigating → Known error'),
        ).toBeVisible();
        expect(
            screen.getByText('updated the problem investigation'),
        ).toBeVisible();
        expect(screen.getByText('updated the change plan')).toBeVisible();
        expect(
            screen.getByText('updated major incident command'),
        ).toBeVisible();
        expect(
            screen.getByText('published a Staff major incident update'),
        ).toBeVisible();
        expect(screen.getByText('requested approval')).toBeVisible();
        expect(screen.getByText('approved the request')).toBeVisible();
        expect(screen.getByText('updated queue routing')).toBeVisible();
        expect(
            screen.getByText('merged IT-000041 into this ticket'),
        ).toBeVisible();
        expect(
            screen.getByText('received a public reply by email'),
        ).toBeVisible();
        expect(
            screen.getByText('added a public update through an approved API'),
        ).toBeVisible();
    });
});
