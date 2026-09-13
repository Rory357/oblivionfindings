import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { TicketThread, type ThreadComment } from '../ticket-thread';

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
    it('shows current public delivery, preserves the scoped review link and delegates refresh to its host', () => {
        const refreshed = vi.fn();
        const comment: ThreadComment = {
            id: 21,
            body: 'Public service update',
            is_internal: false,
            author: { id: 3, name: 'Technician', is_requester: false },
            attachments: [],
            at: null,
            at_human: 'now',
            delivery: {
                tracking: 'recorded',
                requested: true,
                attempt_statuses: { accepted: 1 },
                checked_at: '2026-09-09T08:00:00Z',
                review_url: '/it/setup?tab=operations&delivery_comment_id=21',
            },
        };
        const props = {
            ticketId: 42,
            actorId: 3,
            requesterName: 'Requester',
            description: null,
            comments: [comment],
            events: [],
            canInternal: true,
            canReply: false,
            onPosted: refreshed,
        };
        const view = render(<TicketThread {...props} />);
        expect(screen.getByText('Accepted by provider: 1')).toBeVisible();
        expect(
            screen.getByText('Provider acceptance does not confirm delivery.'),
        ).toBeVisible();
        expect(
            screen.getByRole('link', { name: 'Review delivery log' }),
        ).toHaveAttribute('href', comment.delivery!.review_url);
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh delivery status' }),
        );
        expect(refreshed).toHaveBeenCalledOnce();
        view.rerender(<TicketThread {...props} refreshingDelivery />);
        expect(
            screen.getByRole('button', { name: 'Refreshing delivery status…' }),
        ).toBeDisabled();
        expect(screen.getByText('Accepted by provider: 1')).toBeVisible();
        expect(
            screen.getByRole('group', { name: 'Email delivery' }),
        ).toHaveAttribute('aria-busy', 'true');
        view.rerender(<TicketThread {...props} accessState="session" />);
        expect(
            screen.queryByText('Public service update'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('group', { name: 'Email delivery' }),
        ).not.toBeInTheDocument();
        view.rerender(
            <TicketThread
                {...props}
                comments={[
                    {
                        ...comment,
                        delivery: {
                            ...comment.delivery!,
                            attempt_statuses: { delivered: 1 },
                            checked_at: '2026-09-09T08:01:00Z',
                        },
                    },
                ]}
            />,
        );
        expect(screen.getByText('Delivered: 1')).toBeVisible();
        expect(
            screen.queryByText('Accepted by provider: 1'),
        ).not.toBeInTheDocument();
    });
    it('never renders delivery controls for an internal note even when a stale payload contains them', () => {
        render(
            <TicketThread
                ticketId={42}
                actorId={3}
                requesterName="Requester"
                description={null}
                canInternal
                canReply={false}
                onPosted={vi.fn()}
                events={[]}
                comments={[
                    {
                        id: 22,
                        body: 'Private IT note',
                        is_internal: true,
                        author: {
                            id: 3,
                            name: 'Technician',
                            is_requester: false,
                        },
                        attachments: [],
                        at: null,
                        at_human: 'now',
                        delivery: {
                            tracking: 'recorded',
                            requested: true,
                            attempt_statuses: { delivered: 12 },
                            checked_at: '2026-09-09T08:00:00Z',
                            review_url:
                                '/it/setup?tab=operations&delivery_comment_id=22',
                        },
                    },
                ]}
            />,
        );
        expect(screen.getByText('Private IT note')).toBeVisible();
        expect(
            screen.queryByRole('group', { name: 'Email delivery' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Review delivery log' }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Refresh delivery status' }),
        ).not.toBeInTheDocument();
    });
    it('conceals another author’s stale delivery after in-place access loss while retaining the actor’s public result', () => {
        const comments: ThreadComment[] = [3, 9].map((authorId) => ({
            id: authorId,
            body: `Public update by actor ${authorId}`,
            is_internal: false,
            author: {
                id: authorId,
                name: `Actor ${authorId}`,
                is_requester: false,
            },
            attachments: [],
            at: null,
            at_human: 'now',
            delivery: {
                tracking: 'recorded',
                requested: true,
                attempt_statuses:
                    authorId === 3 ? { accepted: 2 } : { bounced: 3 },
                checked_at: '2026-09-09T08:00:00Z',
                review_url:
                    authorId === 9
                        ? '/it/setup?tab=operations&delivery_comment_id=9'
                        : null,
            },
        }));
        const props = {
            ticketId: 42,
            actorId: 3,
            requesterName: 'Requester',
            description: null,
            comments,
            events: [],
            canInternal: true,
            canReply: false,
        };
        const view = render(<TicketThread {...props} />);
        expect(
            screen.getAllByRole('group', { name: 'Email delivery' }),
        ).toHaveLength(2);
        expect(screen.getByText('Bounced: 3')).toBeVisible();
        view.rerender(<TicketThread {...props} canInternal={false} />);
        expect(
            screen.getAllByRole('group', { name: 'Email delivery' }),
        ).toHaveLength(1);
        expect(screen.getByText('Accepted by provider: 2')).toBeVisible();
        expect(screen.queryByText('Bounced: 3')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: 'Review delivery log' }),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Public update by actor 9')).toBeVisible();
        view.rerender(
            <TicketThread {...props} actorId={8} canInternal={false} />,
        );
        expect(
            screen.queryByRole('group', { name: 'Email delivery' }),
        ).not.toBeInTheDocument();
    });
    it('conceals previously loaded internal comments and their file links when internal access changes', () => {
        const comments = [
            {
                id: 1,
                body: 'Internal diagnostics retained from a prior payload',
                is_internal: true,
                author: { id: 3, name: 'Technician', is_requester: false },
                attachments: [
                    {
                        id: 2,
                        name: 'private-diagnostics.txt',
                        size: 8,
                        url: '/it/attachments/2',
                    },
                ],
                at: null,
                at_human: 'now',
            },
        ];
        const view = render(
            <TicketThread
                ticketId={42}
                requesterName="Requester"
                description={null}
                comments={comments}
                events={[]}
                canInternal
                canReply={false}
            />,
        );
        expect(screen.getByText(comments[0].body)).toBeVisible();
        view.rerender(
            <TicketThread
                ticketId={42}
                requesterName="Requester"
                description={null}
                comments={comments}
                events={[]}
                canInternal={false}
                canReply={false}
            />,
        );
        expect(screen.queryByText(comments[0].body)).not.toBeInTheDocument();
        expect(
            screen.queryByRole('link', { name: /private-diagnostics/ }),
        ).not.toBeInTheDocument();
    });
    it('shows explicit triage reasons and assessment without exposing unrelated payload fields', () => {
        const events = [
            {
                id: 1,
                type: 'priority_assessed',
                actor: 'Technician',
                at: null,
                at_human: 'now',
                payload: {
                    impact: 'organization',
                    urgency: 'critical',
                    priority: 'high',
                    decision: {
                        mode: 'override',
                        reason: 'The alternate service is keeping staff connected.',
                        secret: 'never render this',
                    },
                },
            },
            {
                id: 2,
                type: 'routing_override_applied',
                actor: 'Technician',
                at: null,
                at_human: 'now',
                payload: {
                    reason: 'The network team is coordinating recovery.',
                },
            },
            {
                id: 3,
                type: 'routing_override_released',
                actor: 'Technician',
                at: null,
                at_human: 'now',
                payload: { reason: 'Resume the agreed service desk routing.' },
            },
        ];
        const view = render(
            <TicketThread
                ticketId={42}
                requesterName="Requester"
                description={null}
                comments={[]}
                events={events}
                canInternal
                canReply={false}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /Activity/ }));
        expect(
            screen.getByText('recorded a reasoned priority override'),
        ).toBeVisible();
        expect(
            screen.getByText(
                'Impact: Organisation · Urgency: Critical · Priority: High',
            ),
        ).toBeVisible();
        expect(
            screen.getByText(
                'The alternate service is keeping staff connected.',
            ),
        ).toBeVisible();
        expect(screen.getByText('set a manual routing override')).toBeVisible();
        expect(
            screen.getByText('Resume the agreed service desk routing.'),
        ).toBeVisible();
        expect(screen.queryByText('never render this')).not.toBeInTheDocument();
        view.rerender(
            <TicketThread
                ticketId={42}
                requesterName="Requester"
                description={null}
                comments={[]}
                events={events}
                canInternal={false}
                canReply={false}
            />,
        );
        expect(
            screen.queryByText(
                'The alternate service is keeping staff connected.',
            ),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('The network team is coordinating recovery.'),
        ).not.toBeInTheDocument();
    });

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
