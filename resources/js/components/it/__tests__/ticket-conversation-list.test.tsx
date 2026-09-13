import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ItHero, type ItHeroSummary } from '../it-hero';
import { ItOverview, type OverviewPayload } from '../it-overview';
import { ItTicketList } from '../it-ticket-list';
import type { TicketRow } from '../it-wizards';
import { MyTicketsList, type MyTicketRow } from '../my-tickets-list';
import {
    TicketConversationSummary,
    type TicketConversation,
} from '../ticket-conversation-summary';
import { ticketViewLabel, ticketViewOptions } from '../ticket-view-options';

const conversation: TicketConversation = {
    last_public: {
        comment_id: 7,
        at: '2026-09-09T02:10:00Z',
        speaker_side: 'requester',
    },
    next_response_party: 'it',
    state: 'awaiting_it',
};
const ticket: TicketRow = {
    id: 35,
    can: { manage: false },
    lock_version: 2,
    reference: 'IT-000035',
    title: 'Visible public follow-up',
    description: null,
    work_type: 'incident',
    service: null,
    category: 'hardware',
    priority: 'normal',
    status: 'waiting',
    waiting_party: 'vendor',
    sla_state: 'unmeasured',
    first_response_due_at: null,
    resolution_due_at: null,
    first_responded_at: '2026-09-08T02:00:00Z',
    requester: 'Request participant',
    assignee: { id: 9, name: 'Current IT assignee' },
    age: '1d ago',
    updated: null,
    resolved: null,
    conversation,
};
const mine: MyTicketRow = {
    ...ticket,
    waiting_party: 'other',
    assignee: 'Current IT assignee',
    can_rate: false,
    csat_score: null,
};
const overview: OverviewPayload = {
    avg_first_response_mins: 25,
    sla_lane: [],
    awaiting_lane: [
        {
            id: 36,
            reference: 'IT-000036',
            title: 'Never answered',
            requester: 'Requester',
            priority: 'low',
            age: '2d ago',
        },
    ],
    aging_lane: [],
    unassigned_by_priority: {},
    recent_activity: [],
    conversation_ready: true,
    awaiting_it_lane: [
        {
            id: 35,
            reference: ticket.reference,
            title: ticket.title,
            requester: ticket.requester,
            priority: ticket.priority,
            created_age: '1d ago',
            conversation,
        },
    ],
};
const summary: ItHeroSummary = {
    my: { total: 0, open: 0, waiting: 0, resolved_30d: 0 },
    tickets: {
        open: 3,
        unassigned: 1,
        urgent_unassigned: 0,
        urgent_open: 0,
        at_risk: 0,
        breached: 0,
        awaiting_reply: 1,
        awaiting_it: 2,
        conversation_ready: true,
        waiting: 1,
        resolved_30d: 0,
        met_30d: 0,
    },
};
const can = { view: true, manage: true, request: true };
const noop = () => {};

describe('Canonical public conversation presentation', () => {
    it.each(['cards', 'table'] as const)(
        'shows current public responsibility independently of vendor waiting in technician %s',
        (view) => {
            render(
                <ItTicketList
                    rows={[ticket]}
                    view={view}
                    conversationReady
                    actionsFor={() => []}
                    onPeek={noop}
                    canSelect={() => false}
                    selected={new Set()}
                    onSelect={noop}
                />,
            );
            expect(screen.getByText('Awaiting IT')).toBeVisible();
            expect(screen.getByText(/Waiting.*vendor/i)).toBeVisible();
            expect(
                screen.getByText('Last public reply: Requester'),
            ).toBeVisible();
            expect(screen.getByText(/Wed 9 Sep/)).toBeVisible();
            expect(
                screen.queryByText('Last public reply: IT'),
            ).not.toBeInTheDocument();
            for (const link of screen.getAllByRole('link', {
                name: /Visible public follow-up/,
            }))
                expect(link).toHaveAttribute('href', '/it/tickets/35');
        },
    );
    it.each(['cards', 'table'] as const)(
        'uses the same public evidence for requester %s without inferring private waiting',
        (view) => {
            render(
                <MyTicketsList
                    tickets={[mine]}
                    view={view}
                    conversationReady
                    actionsFor={() => []}
                />,
            );
            expect(screen.getByText('Awaiting IT')).toBeVisible();
            expect(
                screen.getByText('Last public reply: Requester'),
            ).toBeVisible();
            expect(screen.queryByText(/vendor/)).not.toBeInTheDocument();
        },
    );
    it('does not invent an old speaker or infer a next party from an assigned technician', () => {
        render(
            <ItTicketList
                rows={[
                    {
                        ...ticket,
                        conversation: {
                            last_public: {
                                comment_id: 7,
                                at: null,
                                speaker_side: null,
                            },
                            next_response_party: null,
                            state: 'unknown',
                        },
                    },
                ]}
                view="cards"
                conversationReady
                actionsFor={() => []}
                onPeek={noop}
                canSelect={() => false}
                selected={new Set()}
                onSelect={noop}
            />,
        );
        expect(
            screen.getByText('Next public reply not recorded'),
        ).toBeVisible();
        expect(
            screen.getByText('Last public reply: Speaker not recorded'),
        ).toBeVisible();
        expect(screen.queryByText('Awaiting IT')).not.toBeInTheDocument();
    });
    it('conceals stale projected evidence while readiness is false', () => {
        render(
            <TicketConversationSummary
                conversation={conversation}
                ready={false}
            />,
        );
        expect(
            screen.getByText('Public reply status unavailable'),
        ).toBeVisible();
        expect(
            screen.queryByText(/Last public reply:/),
        ).not.toBeInTheDocument();
    });
    it.each([
        [
            { ...conversation, state: 'settled', next_response_party: null },
            'Conversation settled',
        ],
        [
            {
                ...conversation,
                state: 'awaiting_requester',
                next_response_party: 'requester',
            },
            'Awaiting requester',
        ],
        [
            {
                ...conversation,
                state: 'awaiting_it',
                next_response_party: 'requester',
            },
            'Next public reply not recorded',
        ],
    ] as const)(
        'uses the canonical state and refuses contradictory responsibility',
        (projection, expected) => {
            render(
                <TicketConversationSummary conversation={projection} ready />,
            );
            expect(screen.getByText(expected)).toBeVisible();
        },
    );
    it('retains a recorded observer as another participant, without changing the next responsible party', () => {
        render(
            <TicketConversationSummary
                conversation={{
                    ...conversation,
                    last_public: {
                        comment_id: 8,
                        at: null,
                        speaker_side: 'observer',
                    },
                }}
                ready
            />,
        );
        expect(
            screen.getByText('Last public reply: Another participant'),
        ).toBeVisible();
        expect(screen.getByText('Awaiting IT')).toBeVisible();
    });
});

describe('Conversation view navigation and counts', () => {
    it('keeps first-reply URLs and excludes the new view until readiness, including restored defaults', () => {
        const before = ticketViewOptions(true, false);
        const ready = ticketViewOptions(false, true);
        expect(before.some((view) => view.key === 'awaiting_it')).toBe(false);
        expect(
            before.find((view) => view.key === 'awaiting_reply')?.label,
        ).toBe('Awaiting first reply');
        expect(ready.find((view) => view.key === 'awaiting_it')?.label).toBe(
            'Awaiting IT',
        );
        expect(ready.some((view) => view.key === 'waiting_vendor')).toBe(false);
        expect(
            ticketViewLabel(
                ready.find((view) => view.key === 'awaiting_it')!,
                null,
            ),
        ).toBe('Awaiting IT');
        expect(
            ticketViewLabel(
                ready.find((view) => view.key === 'awaiting_it')!,
                0,
            ),
        ).toBe('Awaiting IT (0)');
    });
    it('shows separate canonical current and first-reply counts with distinct native destinations', () => {
        render(
            <ItHero summary={summary} can={can} onLog={noop} onRaise={noop} />,
        );
        const current = screen.getByRole('link', {
            name: 'View tickets currently awaiting IT',
        });
        expect(current).toHaveAttribute(
            'href',
            '/it?tab=tickets&view=awaiting_it',
        );
        expect(within(current).getByText('2')).toBeVisible();
        const first = screen.getByRole('link', {
            name: 'View tickets awaiting their first reply',
        });
        expect(first).toHaveAttribute(
            'href',
            '/it?tab=tickets&view=awaiting_reply',
        );
        expect(within(first).getByText('1')).toBeVisible();
    });
    it.each([false, true])(
        'does not render a dead current-reply control or fabricate a count when unavailable (ready=%s)',
        (ready) => {
            render(
                <ItHero
                    summary={{
                        ...summary,
                        tickets: {
                            ...summary.tickets!,
                            conversation_ready: ready,
                            awaiting_it: null,
                        },
                    }}
                    can={can}
                    onLog={noop}
                    onRaise={noop}
                />,
            );
            expect(screen.queryByText('Awaiting IT')).not.toBeInTheDocument();
            expect(
                screen.getByRole('link', {
                    name: 'View tickets awaiting their first reply',
                }),
            ).toBeVisible();
        },
    );
    it('separates the current lane from first-response history and labels creation age accurately', () => {
        const open = vi.fn();
        render(<ItOverview overview={overview} onOpenTicket={open} />);
        expect(screen.getByText('Awaiting first reply')).toBeVisible();
        const link = screen.getByRole('link', { name: 'View all awaiting it' });
        expect(link).toHaveAttribute(
            'href',
            '/it?tab=tickets&view=awaiting_it',
        );
        expect(
            screen.getByRole('link', { name: 'View all awaiting first reply' }),
        ).toHaveAttribute('href', '/it?tab=tickets&view=awaiting_reply');
        expect(screen.getByText('Ticket age: 1d ago')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: /Visible public follow-up/ }),
        );
        expect(open).toHaveBeenCalledWith(35);
    });
    it('does not show historical or stale lane rows when current conversation data is unavailable', () => {
        render(
            <ItOverview
                overview={{ ...overview, conversation_ready: false }}
                onOpenTicket={noop}
            />,
        );
        expect(screen.queryByText(ticket.title)).not.toBeInTheDocument();
        expect(
            screen.getByText(/Reply responsibility is unavailable/),
        ).toBeVisible();
        expect(screen.getByText('Never answered')).toBeVisible();
        expect(
            screen.queryByRole('link', { name: 'View all awaiting it' }),
        ).not.toBeInTheDocument();
    });
    it('filters the current lane by the selected priority without labelling ticket age as reply wait', () => {
        render(
            <ItOverview
                overview={overview}
                priority="urgent"
                onOpenTicket={noop}
            />,
        );
        expect(screen.queryByText(ticket.title)).not.toBeInTheDocument();
        expect(
            screen.getByText(
                /No open tickets in this selection have a recorded next public response with IT/,
            ),
        ).toBeVisible();
        expect(screen.queryByText(/Waiting for 1d/)).not.toBeInTheDocument();
    });
});
