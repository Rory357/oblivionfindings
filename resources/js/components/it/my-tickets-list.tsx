import { CsatStars } from '@/components/it/csat';
import { waitingStatusLabel } from '@/components/it/ticket-waiting-dialog';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Link } from '@inertiajs/react';
import { Ticket } from 'lucide-react';
import type { MouseEventHandler, ReactNode } from 'react';

export interface MyTicketRow {
    id: number;
    reference: string | null;
    title: string;
    description: string | null;
    category: string;
    priority: string;
    status: string;
    waiting_party: 'requester' | 'other' | null;
    assignee: string | null;
    age: string | null;
    resolved: string | null;
    can_rate: boolean;
    csat_score: number | null;
}

const ticketStatusVariant: Record<string, StatusVariant> = {
    open: 'warning',
    in_progress: 'info',
    resolved: 'success',
    closed: 'neutral',
};

const priorityVariant: Record<string, StatusVariant> = {
    urgent: 'critical',
    high: 'critical',
    normal: 'info',
    low: 'neutral',
};

const label = (raw: string) =>
    raw.replace(/[_-]/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

function StatusDots({ status }: { status: string }) {
    const stages = ['open', 'in_progress', 'resolved', 'closed'];
    const reached = status === 'waiting' ? 1 : stages.indexOf(status);

    return (
        <span aria-hidden className="flex items-center gap-1 pl-0.5">
            {stages.map((stage, index) => (
                <span
                    key={stage}
                    className={
                        index <= reached
                            ? 'h-1.5 w-1.5 rounded-full bg-primary'
                            : 'h-1.5 w-1.5 rounded-full bg-border'
                    }
                />
            ))}
        </span>
    );
}

interface MyTicketsListProps {
    tickets: MyTicketRow[];
    emptyState?: ReactNode;
    onTicketContextMenu?: (
        ticket: MyTicketRow,
    ) => MouseEventHandler<HTMLAnchorElement>;
}

/** Requester ticket worklist. Each complete row is one native link, so its
 *  visible ticket summary is reachable and opens with standard keyboard use. */
export function MyTicketsList({
    tickets,
    emptyState,
    onTicketContextMenu,
}: MyTicketsListProps) {
    return (
        <div className="overflow-hidden rounded-2xl border border-border bg-card">
            <div className="grid grid-cols-[3fr_1.3fr_0.9fr_1fr_0.8fr] gap-3 border-b border-border bg-muted px-4.5 py-2.5 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                <span>Ticket</span>
                <span>Assignee</span>
                <span>Priority</span>
                <span>Status</span>
                <span>Raised</span>
            </div>
            {tickets.map((ticket) => (
                <Link
                    key={ticket.id}
                    href={`/it/tickets/${ticket.id}`}
                    onContextMenu={onTicketContextMenu?.(ticket)}
                    className="grid cursor-pointer grid-cols-[3fr_1.3fr_0.9fr_1fr_0.8fr] items-center gap-3 border-b border-border/55 px-4.5 py-3 transition-colors last:border-0 hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                >
                    <div className="flex min-w-0 items-center gap-2">
                        <span className="grid h-7 w-7 flex-none place-items-center rounded-lg bg-accent text-primary">
                            <Ticket className="h-3.5 w-3.5" />
                        </span>
                        <span className="min-w-0">
                            <span className="block truncate text-[13px] font-semibold">
                                {ticket.title}
                            </span>
                            <span className="block truncate text-[11px] text-muted-foreground">
                                {ticket.reference
                                    ? `${ticket.reference} · `
                                    : ''}
                                {label(ticket.category)}
                                {ticket.description
                                    ? ` · ${ticket.description}`
                                    : ''}
                            </span>
                        </span>
                    </div>
                    <span className="truncate text-[12.5px] text-muted-foreground">
                        {ticket.assignee ?? 'With IT for triage'}
                    </span>
                    <span>
                        <StatusBadge
                            variant={
                                priorityVariant[ticket.priority] ?? 'neutral'
                            }
                            size="sm"
                        >
                            {label(ticket.priority)}
                        </StatusBadge>
                    </span>
                    <span className="flex flex-col items-start gap-1">
                        <StatusBadge
                            variant={
                                ticket.status === 'waiting'
                                    ? 'warning'
                                    : (ticketStatusVariant[ticket.status] ??
                                      'neutral')
                            }
                            size="sm"
                        >
                            {ticket.status === 'waiting'
                                ? waitingStatusLabel(ticket.waiting_party, true)
                                : label(ticket.status)}
                        </StatusBadge>
                        <StatusDots status={ticket.status} />
                        {ticket.csat_score != null ? (
                            <span className="inline-flex items-center gap-1 text-[10.5px] text-muted-foreground">
                                You rated{' '}
                                <CsatStars
                                    score={ticket.csat_score}
                                    size="h-3 w-3"
                                />
                            </span>
                        ) : null}
                    </span>
                    <span className="text-[12px] text-muted-foreground">
                        {ticket.age ?? '—'}
                    </span>
                </Link>
            ))}
            {tickets.length === 0 ? emptyState : null}
        </div>
    );
}
