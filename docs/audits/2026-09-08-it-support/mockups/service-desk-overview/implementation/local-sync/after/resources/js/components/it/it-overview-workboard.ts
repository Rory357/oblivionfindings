import { formatDateTime } from '@/lib/datetime';
import type { SlaClock, SlaVerdict } from './sla-evidence';
import type { TicketConversation } from './ticket-conversation-summary';

export const overviewQueues = {
    attention: 'SLA attention',
    mine: 'My work',
    unassigned: 'Unassigned',
    breached: 'SLA breached',
    breaching: 'SLA at risk',
    awaiting_it: 'Awaiting IT',
    awaiting_reply: 'First reply needed',
    waiting_requester: 'Waiting on requester',
    aging: 'Ageing tickets',
    waiting: 'All waiting work',
    unmeasured: 'SLA unmeasured',
    all_open: 'All open tickets',
} as const;
export type OverviewQueue = keyof typeof overviewQueues;
export interface OverviewTicket {
    id: number;
    reference: string | null;
    lock_version: number;
    title: string;
    priority: string;
    status: string;
    site: string | null;
    assignee: string | null;
    assigned_to_user_id: number | null;
    age: string | null;
    first_reply_needed: boolean;
    waiting_party: string | null;
    conversation: TicketConversation | null;
    can_manage: boolean;
    sla: SlaVerdict;
}
export interface OverviewScope {
    queues: Record<OverviewQueue, { total: number; ids: number[] }>;
    actions: {
        response: number | null;
        assignment: number | null;
        follow_up: number | null;
    };
}
export interface OverviewWorkboard {
    scopes: Record<string, OverviewScope>;
    tickets: Record<number, OverviewTicket>;
    unmeasured_total: number;
    evaluated_at: string;
}
export function overviewQueueHref(
    queue: OverviewQueue,
    priority?: string | null,
): string {
    const params = new URLSearchParams({
        tab: 'tickets',
        view: ['attention', 'aging'].includes(queue) ? 'all_open' : queue,
    });
    if (queue === 'aging') {
        params.set('sort', 'created');
        params.set('dir', 'asc');
    }
    if (priority) params.set('ticket_priority', priority);
    return `/it?${params}`;
}
/** Business-calendar minutes come from the server; never extrapolate with the browser clock. */
export function overviewClockDetail(clock?: SlaClock): string {
    if (!clock || clock.state === 'unmeasured')
        return 'Clock evidence unavailable';
    if (clock.completed_at)
        return `Completed ${formatDateTime(clock.completed_at)}`;
    if (clock.paused || clock.state === 'paused') return 'Paused while waiting';
    if (typeof clock.remaining_minutes === 'number') {
        const minutes = Math.abs(Math.round(clock.remaining_minutes));
        return clock.remaining_minutes < 0
            ? `${minutes} business min overdue`
            : `${minutes} business min remaining`;
    }
    return clock.due_at
        ? `Due ${formatDateTime(clock.due_at)}`
        : 'No deadline recorded';
}
export function overviewConversation(
    ticket: OverviewTicket,
    ready: boolean,
): string {
    if (!ready) return 'Public reply status unavailable';
    if (ticket.conversation?.state === 'awaiting_it') return 'Awaiting IT';
    if (ticket.conversation?.state === 'awaiting_requester')
        return 'Awaiting requester';
    return 'Next public reply not recorded';
}
