import { EntityCard, EntityCardGrid } from '@/components/lists/entity-card';
import {
    EntityChip,
    EntityStatusChip,
    PersonCell,
} from '@/components/lists/entity-cells';
import {
    EntityContextMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import {
    EntityTable,
    type EntityTableColumn,
} from '@/components/lists/entity-table';
import type { StatusVariant } from '@/components/ui/status-badge';
import { Ticket } from 'lucide-react';
import type { ReactNode } from 'react';
import type { TicketRow } from './it-wizards';
import { SlaChip } from './sla-chip';
import { TicketConversationSummary } from './ticket-conversation-summary';
import { TicketRoutingSummary } from './ticket-routing-summary';
import { waitingStatusLabel } from './ticket-waiting-dialog';

const label = (value: string) =>
    value.replace(/_/g, ' ').replace(/^\w/, (letter) => letter.toUpperCase());
const priorityTone: Record<string, StatusVariant> = {
    urgent: 'critical',
    high: 'critical',
    normal: 'info',
    low: 'neutral',
};
const stateTone: Record<string, StatusVariant> = {
    open: 'warning',
    in_progress: 'info',
    waiting: 'warning',
    resolved: 'success',
    closed: 'neutral',
};
const state = (ticket: TicketRow) =>
    ticket.status === 'waiting'
        ? waitingStatusLabel(ticket.waiting_party)
        : label(ticket.status);

/** Authorized ticket data and one action list feed both approved list shells. */
export function ItTicketList({
    rows,
    view,
    actionsFor,
    onPeek,
    canSelect,
    selected,
    onSelect,
    busy,
    emptyState,
    conversationReady = false,
}: {
    rows: TicketRow[];
    view: 'cards' | 'table';
    actionsFor: (ticket: TicketRow) => MenuItem[];
    onPeek: (ticket: TicketRow) => void;
    canSelect: (ticket: TicketRow) => boolean;
    selected: ReadonlySet<number>;
    onSelect: (ticket: TicketRow, checked: boolean) => void;
    busy?: boolean;
    emptyState?: ReactNode;
    conversationReady?: boolean;
}) {
    const menu = useEntityContextMenu<number>();
    const current = menu.ctx
        ? rows.find((row) => row.id === menu.ctx?.record)
        : undefined;
    const columns: EntityTableColumn<TicketRow>[] = [
        {
            key: 'requester',
            label: 'Requester',
            width: '1fr',
            cell: (row) => <PersonCell name={row.requester} />,
        },
        {
            key: 'assignee',
            label: 'Technician / accountability',
            width: '1.7fr',
            cell: (row) => (
                <span className="min-w-0">
                    <PersonCell name={row.assignee?.name} />
                    {row.routing && (
                        <span
                            className="text-caption block truncate"
                            title={`${row.routing.queue?.name ?? 'Queue not configured'} · ${row.routing.team?.name ?? 'Team not configured'} · Accountable owner: ${row.routing.accountable_owner?.name ?? row.routing.owner?.name ?? 'not assigned'}`}
                        >
                            Owner:{' '}
                            {row.routing.accountable_owner?.name ??
                                row.routing.owner?.name ??
                                'not assigned'}{' '}
                            ·{' '}
                            {row.routing.queue?.name ?? 'Queue not configured'}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'priority',
            label: 'Priority',
            width: '.7fr',
            cell: (row) => (
                <EntityStatusChip
                    variant={priorityTone[row.priority] ?? 'neutral'}
                >
                    {label(row.priority)}
                </EntityStatusChip>
            ),
        },
        {
            key: 'status',
            label: 'Status / public reply',
            width: '1.5fr',
            cell: (row) => (
                <span className="flex min-w-0 flex-col items-start gap-1">
                    <EntityStatusChip
                        variant={stateTone[row.status] ?? 'neutral'}
                    >
                        {state(row)}
                    </EntityStatusChip>
                    <TicketConversationSummary
                        conversation={row.conversation}
                        ready={conversationReady}
                    />
                </span>
            ),
        },
        {
            key: 'sla',
            label: 'SLA',
            width: '1.2fr',
            cell: (row) => <SlaChip ticket={row} />,
        },
        {
            key: 'age',
            label: 'Raised',
            width: '.8fr',
            cell: (row) => row.age ?? '—',
        },
    ];
    return (
        <>
            {current && menu.ctx && (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    title={`${current.reference ?? 'Ticket'} · ${current.title}`}
                    icon={Ticket}
                    items={actionsFor(current)}
                    onClose={menu.close}
                />
            )}
            {rows.length === 0 ? (
                emptyState
            ) : view === 'table' ? (
                <EntityTable
                    rows={rows}
                    rowKey={(row) => row.id}
                    identity={(row) => ({
                        icon: Ticket,
                        name: row.title,
                        linkLabel: `${row.title} · ${row.reference ?? 'Reference unavailable'}`,
                        subline: `${row.reference ?? 'Reference unavailable'} · ${label(row.work_type)} · ${row.service?.name ?? label(row.category)}`,
                    })}
                    identityLabel="Ticket"
                    identityWidth="2.5fr"
                    columns={columns}
                    minWidth={1080}
                    hrefFor={(row) => `/it/tickets/${row.id}`}
                    onOpen={onPeek}
                    actionsFor={actionsFor}
                    onRowContextMenu={(event, row) => menu.open(event, row.id)}
                    selection={{
                        keys: selected,
                        onToggle: onSelect,
                        labelFor: (row) =>
                            `Select ${row.reference ?? row.title}`,
                        canSelect,
                        disabled: busy,
                    }}
                />
            ) : (
                <EntityCardGrid>
                    {rows.map((row) => (
                        <EntityCard
                            key={row.id}
                            className="[&_h3]:overflow-visible [&_h3]:break-words [&_h3]:text-clip [&_h3]:whitespace-normal"
                            icon={Ticket}
                            name={row.title}
                            linkLabel={`${row.title} · ${row.reference ?? 'Reference unavailable'}`}
                            subline={`${row.reference ?? 'Reference unavailable'} · ${label(row.work_type)} · ${row.service?.name ?? label(row.category)}`}
                            href={`/it/tickets/${row.id}`}
                            onOpen={() => onPeek(row)}
                            meridian={
                                row.sla_state === 'breached'
                                    ? 'critical'
                                    : row.sla_state === 'at_risk' ||
                                        row.sla_state === 'unmeasured' ||
                                        (row.routing?.gaps?.length ?? 0) > 0
                                      ? 'warning'
                                      : 'success'
                            }
                            actions={actionsFor(row)}
                            onContextMenu={(event) => menu.open(event, row.id)}
                            selection={
                                canSelect(row)
                                    ? {
                                          checked: selected.has(row.id),
                                          label: `Select ${row.reference ?? row.title}`,
                                          onToggle: (checked) =>
                                              onSelect(row, checked),
                                          disabled: busy,
                                      }
                                    : undefined
                            }
                            chips={
                                <>
                                    <EntityStatusChip
                                        variant={
                                            stateTone[row.status] ?? 'neutral'
                                        }
                                    >
                                        {state(row)}
                                    </EntityStatusChip>
                                    <EntityStatusChip
                                        variant={
                                            priorityTone[row.priority] ??
                                            'neutral'
                                        }
                                    >
                                        {label(row.priority)}
                                    </EntityStatusChip>
                                    <EntityChip>
                                        {row.age ?? 'Raised time unavailable'}
                                    </EntityChip>
                                </>
                            }
                            alerts={
                                <>
                                    <SlaChip ticket={row} />
                                    <TicketConversationSummary
                                        conversation={row.conversation}
                                        ready={conversationReady}
                                    />
                                    {row.next_action && (
                                        <span className="text-caption">
                                            Next: {row.next_action}
                                        </span>
                                    )}
                                </>
                            }
                            footer={{
                                personName: row.assignee?.name,
                                primary:
                                    row.assignee?.name ??
                                    'No technician assigned',
                                secondary: row.routing ? (
                                    <TicketRoutingSummary
                                        routing={row.routing}
                                        compact
                                    />
                                ) : (
                                    'Accountable ownership not assessed'
                                ),
                            }}
                        />
                    ))}
                </EntityCardGrid>
            )}
        </>
    );
}
