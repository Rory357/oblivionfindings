import { fireEvent, render, screen, within } from '@testing-library/react';
import { expect, it, vi } from 'vitest';
import { ItOverview } from '../it-overview';
import {
    overviewClockDetail,
    overviewQueueHref,
    overviewQueues,
    type OverviewQueue,
    type OverviewTicket,
    type OverviewWorkboard,
} from '../it-overview-workboard';
import type { SlaClock, SlaVerdict } from '../sla-evidence';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }: React.ComponentProps<'a'>) => (
        <a {...props}>{children}</a>
    ),
    router: { visit: vi.fn(), reload: vi.fn() },
}));
function clock(
    state: SlaClock['state'],
    remaining: number | null = null,
): SlaClock {
    return {
        state,
        remaining_minutes: remaining,
        due_at: '2026-09-13T03:00:00Z',
        completed_at: null,
        breached_at: null,
        ever_breached: state === 'breached',
        reason: null,
        policy_recorded: true,
        paused: state === 'paused',
        paused_minutes: null,
    };
}
function ticket(
    id: number,
    extras: Partial<OverviewTicket> = {},
): OverviewTicket {
    const sla: SlaVerdict = {
        state: 'at_risk',
        coverage: 'full',
        ever_breached: false,
        evaluated_at: '2026-09-13T02:00:00Z',
        clocks: {
            first_response: clock('at_risk', 24),
            resolution: clock('ok', 180),
        },
    };
    return {
        id,
        reference: `IT-${id}`,
        title: `Ticket ${id}`,
        lock_version: 7,
        priority: 'urgent',
        status: 'open',
        site: 'Approved site',
        assignee: null,
        assigned_to_user_id: null,
        age: '8d ago',
        first_reply_needed: true,
        waiting_party: null,
        conversation: {
            last_public: null,
            next_response_party: 'it',
            state: 'awaiting_it',
        },
        can_manage: true,
        sla,
        ...extras,
    };
}
function board(): OverviewWorkboard {
    const records = [
        ticket(1),
        ticket(2),
        ticket(3, {
            first_reply_needed: false,
            assignee: 'Agent',
            assigned_to_user_id: 12,
        }),
        ticket(4),
        ticket(5),
        ticket(6),
        ticket(7),
    ];
    const queues = Object.fromEntries(
        Object.keys(overviewQueues).map((key) => [
            key,
            {
                total: key === 'attention' ? 7 : 100,
                ids: records.slice(0, 6).map((t) => t.id),
            },
        ]),
    ) as Record<OverviewQueue, { total: number; ids: number[] }>;
    const empty = Object.fromEntries(
        Object.keys(overviewQueues).map((key) => [
            key,
            { total: 0, ids: [] as number[] },
        ]),
    ) as typeof queues;
    return {
        tickets: Object.fromEntries(records.map((t) => [t.id, t])),
        scopes: {
            all: {
                queues,
                actions: { response: 1, assignment: 2, follow_up: 3 },
            },
            low: {
                queues: empty,
                actions: { response: null, assignment: null, follow_up: null },
            },
        },
        unmeasured_total: 3,
        evaluated_at: '2026-09-13T02:00:00Z',
    };
}
function overview(workboard = board(), ready = true) {
    return {
        workboard,
        avg_first_response_mins: null,
        conversation_ready: ready,
        sla_lane: [],
        awaiting_lane: [],
        aging_lane: [],
        awaiting_it_lane: [],
        unassigned_by_priority: {},
        recent_activity: [],
    };
}

it('shows three distinct next actions and excludes their IDs from the supporting preview without pretending the preview is the full queue', () => {
    const open = vi.fn();
    render(
        <ItOverview
            overview={overview()}
            actorId={12}
            canManage
            onOpenTicket={open}
            onAssign={vi.fn()}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Draft first reply' }));
    expect(open).toHaveBeenCalledWith(1);
    fireEvent.click(screen.getByRole('button', { name: 'Review follow-up' }));
    expect(open).toHaveBeenCalledWith(3);
    const table = screen.getByRole('table');
    expect(within(table).queryByText('Ticket 1')).not.toBeInTheDocument();
    expect(within(table).queryByText('Ticket 2')).not.toBeInTheDocument();
    expect(within(table).queryByText('Ticket 3')).not.toBeInTheDocument();
    expect(within(table).getByText('Ticket 4')).toBeVisible();
    expect(
        screen.getByText('3 of 4 shown · featured actions excluded'),
    ).toBeVisible();
    expect(
        within(screen.getByLabelText('Ageing follow-up action')).getByText(
            'Resolution SLA',
        ),
    ).toBeVisible();
    expect(
        screen.getByRole('link', { name: /Browse all tickets/ }),
    ).toHaveAttribute('href', '/it?tab=tickets&view=all_open');
});

it('keeps a proposed assignment pending until refreshed canonical data confirms the current actor, then moves keyboard focus', () => {
    const assign = vi.fn();
    const data = board();
    const { rerender } = render(
        <ItOverview
            overview={overview(data)}
            actorId={12}
            canManage
            onOpenTicket={vi.fn()}
            onAssign={assign}
        />,
    );
    fireEvent.click(
        within(screen.getByLabelText('Assignment action')).getByRole('button', {
            name: 'Assign to me',
        }),
    );
    expect(assign).toHaveBeenCalledWith(
        expect.objectContaining({ id: 2, lock_version: 7 }),
    );
    expect(screen.queryByText('Assignment saved')).not.toBeInTheDocument();
    const updated = structuredClone(data);
    updated.tickets[2] = {
        ...updated.tickets[2],
        assigned_to_user_id: 12,
        assignee: 'Agent',
        lock_version: 8,
    };
    updated.scopes.all.actions.assignment = 4;
    // The saved assignment can become the server's ageing choice next time.
    // Keep the confirmed assignment in one action card only.
    updated.scopes.all.actions.follow_up = 2;
    rerender(
        <ItOverview
            overview={overview(updated)}
            actorId={12}
            canManage
            onOpenTicket={vi.fn()}
            onAssign={assign}
        />,
    );
    expect(screen.getByText('Assignment saved')).toBeVisible();
    expect(
        within(screen.getByLabelText('Ageing follow-up action')).queryByText(
            'Ticket 2',
        ),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: /Remaining work/ }),
    ).toHaveFocus();
    fireEvent.click(screen.getByRole('button', { name: /Remaining work/ }));
    expect(screen.getByText('3 of 100 shown')).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Show 3 more' }));
    expect(screen.getByText('6 of 100 shown')).toBeVisible();
});

it('requires record-level management permission and does not carry successful assignment state across actors', () => {
    const data = board();
    data.tickets[2].can_manage = false;
    const { rerender } = render(
        <ItOverview
            overview={overview(data)}
            actorId={12}
            onOpenTicket={vi.fn()}
            onAssign={vi.fn()}
        />,
    );
    expect(
        within(screen.getByLabelText('Assignment action')).queryByRole(
            'button',
            { name: 'Assign to me' },
        ),
    ).not.toBeInTheDocument();
    data.tickets[1].can_manage = false;
    rerender(
        <ItOverview
            overview={overview(data)}
            actorId={13}
            onOpenTicket={vi.fn()}
            onAssign={vi.fn()}
        />,
    );
    expect(
        screen.queryByRole('button', { name: 'Draft first reply' }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('button', { name: 'View conversation' }),
    ).toBeVisible();
    expect(screen.queryByText('Assignment saved')).not.toBeInTheDocument();
});

it('uses the priority-specific source slice and retains honest unavailable states', () => {
    const clear = vi.fn();
    render(
        <ItOverview
            overview={overview(board(), false)}
            priority="low"
            onOpenTicket={vi.fn()}
            onClearPriority={clear}
        />,
    );
    expect(screen.queryByText('Ticket 1')).not.toBeInTheDocument();
    expect(screen.getByText('No tickets in this selection')).toBeVisible();
    expect(
        screen.getByText(/Reply responsibility is unavailable/),
    ).toBeVisible();
    expect(
        screen.getByText(/Average first response · 30d: Unavailable/),
    ).toBeVisible();
    fireEvent.click(screen.getByRole('button', { name: 'Clear Priority' }));
    expect(clear).toHaveBeenCalledOnce();
});

it('preserves queue contracts and business-calendar evidence without inferring countdowns', () => {
    expect(overviewQueueHref('breached', 'high')).toBe(
        '/it?tab=tickets&view=breached&ticket_priority=high',
    );
    expect(overviewQueueHref('breaching')).toBe(
        '/it?tab=tickets&view=breaching',
    );
    expect(overviewQueueHref('aging')).toBe(
        '/it?tab=tickets&view=all_open&sort=created&dir=asc',
    );
    expect(overviewClockDetail(clock('breached', -32))).toBe(
        '32 business min overdue',
    );
    expect(overviewClockDetail(clock('paused', -32))).toBe(
        'Paused while waiting',
    );
    expect(overviewClockDetail(clock('unmeasured', -32))).toBe(
        'Clock evidence unavailable',
    );
});
