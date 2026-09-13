import { CsatStars } from '@/components/it/csat';
import { waitingStatusLabel } from '@/components/it/ticket-waiting-dialog';
import { EntityCard, EntityCardGrid } from '@/components/lists/entity-card';
import { EntityStatusChip, PersonCell } from '@/components/lists/entity-cells';
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import type { StatusVariant } from '@/components/ui/status-badge';
import { router } from '@inertiajs/react';
import { Ticket } from 'lucide-react';
import type { ReactNode } from 'react';
import { TicketConversationSummary } from './ticket-conversation-summary';

export interface MyTicketRow {
    conversation?: import('./ticket-conversation-summary').TicketConversation;
    id: number;
    lock_version: number;
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
    can_reply?: boolean;
    can_reopen?: boolean;
    csat_score: number | null;
}

const ticketStatusVariant: Record<string, StatusVariant> = {
    open: 'warning',
    in_progress: 'info',
    waiting: 'warning',
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
const state = (ticket: MyTicketRow) =>
    ticket.status === 'waiting'
        ? waitingStatusLabel(ticket.waiting_party, true)
        : label(ticket.status);

/** Requester-safe records compose the same card/table and action contracts as IT. */
export function MyTicketsList({
    tickets,
    view = 'table',
    emptyState,
    actionsFor,
    conversationReady = false,
}: {
    tickets: MyTicketRow[];
    view?: 'cards' | 'table';
    emptyState?: ReactNode;
    actionsFor?: (ticket: MyTicketRow) => MenuItem[];
    conversationReady?: boolean;
}) {
    const menu = useEntityContextMenu<number>();
    const current = menu.ctx
        ? tickets.find((row) => row.id === menu.ctx?.record)
        : undefined;
    const actions =
        actionsFor ??
        ((row: MyTicketRow) => [
            {
                label: 'Open request',
                icon: Ticket,
                onClick: () => router.visit(`/it/tickets/${row.id}`),
            },
        ]);
    const status = (row: MyTicketRow) => (
        <span className="flex flex-col items-start gap-1">
            <EntityStatusChip
                variant={ticketStatusVariant[row.status] ?? 'neutral'}
            >
                {state(row)}
            </EntityStatusChip>
            <TicketConversationSummary
                conversation={row.conversation}
                ready={conversationReady}
            />
            {row.csat_score !== null && (
                <span className="text-caption inline-flex gap-1">
                    You rated{' '}
                    <CsatStars score={row.csat_score} size="h-3 w-3" />
                </span>
            )}
        </span>
    );
    return (
        <>
            {current && menu.ctx && (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    title={current.title}
                    icon={Ticket}
                    items={actions(current)}
                    onClose={menu.close}
                />
            )}
            {tickets.length === 0 ? (
                emptyState
            ) : view === 'table' ? (
                <EntityTable
                    rows={tickets}
                    rowKey={(row) => row.id}
                    identity={(row) => ({
                        icon: Ticket,
                        name: row.title,
                        linkLabel: `${row.title} · ${row.reference ?? 'Reference unavailable'}`,
                        subline: `${row.reference ?? 'Reference unavailable'} · ${label(row.category)}`,
                    })}
                    identityLabel="Request"
                    identityWidth="2.6fr"
                    minWidth={800}
                    hrefFor={(row) => `/it/tickets/${row.id}`}
                    columns={[
                        {
                            key: 'assignee',
                            label: 'Helping you',
                            width: '1.2fr',
                            cell: (row) =>
                                row.assignee ? (
                                    <PersonCell name={row.assignee} />
                                ) : (
                                    <span>With IT for triage</span>
                                ),
                        },
                        {
                            key: 'priority',
                            label: 'Priority',
                            width: '.7fr',
                            cell: (row) => (
                                <EntityStatusChip
                                    variant={
                                        priorityVariant[row.priority] ??
                                        'neutral'
                                    }
                                >
                                    {label(row.priority)}
                                </EntityStatusChip>
                            ),
                        },
                        {
                            key: 'status',
                            label: 'Status / public reply',
                            width: '1.5fr',
                            cell: status,
                        },
                        {
                            key: 'raised',
                            label: 'Raised',
                            width: '.8fr',
                            cell: (row) => row.age ?? '—',
                        },
                    ]}
                    actionsFor={actions}
                    onRowContextMenu={(event, row) => menu.open(event, row.id)}
                />
            ) : (
                <EntityCardGrid>
                    {tickets.map((row) => (
                        <EntityCard
                            key={row.id}
                            icon={Ticket}
                            name={row.title}
                            linkLabel={`${row.title} · ${row.reference ?? 'Reference unavailable'}`}
                            subline={`${row.reference ?? 'Reference unavailable'} · ${label(row.category)}`}
                            href={`/it/tickets/${row.id}`}
                            meridian={
                                row.status === 'waiting' &&
                                row.waiting_party === 'requester'
                                    ? 'warning'
                                    : [
                                            'open',
                                            'in_progress',
                                            'waiting',
                                        ].includes(row.status) &&
                                        (row.priority === 'urgent' ||
                                            row.priority === 'high')
                                      ? 'warning'
                                      : 'success'
                            }
                            actions={actions(row)}
                            onContextMenu={(event) => menu.open(event, row.id)}
                            chips={
                                <>
                                    {status(row)}
                                    <EntityStatusChip
                                        variant={
                                            priorityVariant[row.priority] ??
                                            'neutral'
                                        }
                                    >
                                        {label(row.priority)}
                                    </EntityStatusChip>
                                </>
                            }
                            alerts={
                                row.status === 'waiting' &&
                                row.waiting_party === 'requester' ? (
                                    <span className="text-caption">
                                        Open this request to see what IT needs
                                        from you.
                                    </span>
                                ) : undefined
                            }
                            footer={{
                                personName: row.assignee,
                                primary: row.assignee ?? 'With IT for triage',
                                secondary: row.age ?? 'Raised time unavailable',
                            }}
                        />
                    ))}
                </EntityCardGrid>
            )}
        </>
    );
}
