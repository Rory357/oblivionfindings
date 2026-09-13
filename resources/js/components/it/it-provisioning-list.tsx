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
import { EntityTable } from '@/components/lists/entity-table';
import type { StatusVariant } from '@/components/ui/status-badge';
import { formatDate } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import { Server } from 'lucide-react';
import type { ReactNode } from 'react';
import type { RequestRow } from './it-wizards';

const label = (value: string) =>
    value.replace(/_/g, ' ').replace(/^\w/, (letter) => letter.toUpperCase());
const tones: Record<string, StatusVariant> = {
    pending: 'warning',
    in_progress: 'info',
    failed: 'critical',
    done: 'success',
    cancelled: 'neutral',
};

/** Provisioning retains its canonical step actions; linked tickets are native links. */
export function ItProvisioningList({
    rows,
    view,
    actionsFor,
    selected,
    onSelect,
    canManage,
    today,
    busy,
    emptyState,
}: {
    rows: RequestRow[];
    view: 'cards' | 'table';
    actionsFor: (row: RequestRow) => MenuItem[];
    selected: ReadonlySet<number>;
    onSelect: (row: RequestRow, checked: boolean) => void;
    canManage: boolean;
    today: string;
    busy?: boolean;
    emptyState?: ReactNode;
}) {
    const menu = useEntityContextMenu<number>();
    const current = menu.ctx
        ? rows.find((row) => row.id === menu.ctx?.record)
        : undefined;
    const overdue = (row: RequestRow) =>
        row.due_date !== null &&
        !['done', 'cancelled'].includes(row.status) &&
        row.due_date < today;
    const context = (row: RequestRow) =>
        [
            row.workflow
                ? `${label(row.workflow.lifecycle_type)} · Stage ${row.stage ?? 1}`
                : row.from_onboarding
                  ? 'Onboarding'
                  : label(row.type),
            row.action ? label(row.action) : null,
            row.external_ref ? `Ref: ${row.external_ref}` : null,
        ]
            .filter(Boolean)
            .join(' · ');
    const linked = (row: RequestRow) =>
        row.linked_ticket ? (
            <Link
                href={`/it/tickets/${row.linked_ticket.id}`}
                className="text-primary underline-offset-4 hover:underline"
                onClick={(event) => event.stopPropagation()}
            >
                {row.linked_ticket.reference ?? 'Linked ticket'}
                {row.linked_ticket_count > 1
                    ? ` +${row.linked_ticket_count - 1}`
                    : ''}
            </Link>
        ) : (
            <span>—</span>
        );
    const status = (row: RequestRow) => (
        <EntityStatusChip variant={tones[row.status] ?? 'neutral'}>
            {label(row.status)}
        </EntityStatusChip>
    );
    const due = (row: RequestRow) => (
        <span
            className={
                overdue(row) ? 'font-semibold text-status-critical' : undefined
            }
        >
            {row.due_date
                ? `${overdue(row) ? 'Overdue · ' : ''}${formatDate(row.due_date)}`
                : '—'}
        </span>
    );
    const obligations = (row: RequestRow) =>
        [
            row.approval_required
                ? row.approval_status === 'approved'
                    ? 'Approved'
                    : 'Approval needed'
                : null,
            row.evidence_required ? 'Evidence required' : null,
            row.sign_off_required ? 'Sign-off required' : null,
        ]
            .filter(Boolean)
            .join(' · ');
    return (
        <>
            {current && menu.ctx && (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    title={current.item}
                    icon={Server}
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
                        icon: Server,
                        name: row.item,
                        subline: context(row),
                    })}
                    identityLabel="Provisioning step"
                    identityWidth="2.2fr"
                    minWidth={1040}
                    columns={[
                        {
                            key: 'employee',
                            label: 'Employee',
                            width: '1.2fr',
                            cell: (row) => (
                                <span>
                                    <PersonCell name={row.employee.name} />
                                    <span className="text-caption">
                                        {row.employee.role}
                                    </span>
                                </span>
                            ),
                        },
                        {
                            key: 'assignee',
                            label: 'Assignee',
                            width: '1fr',
                            cell: (row) => (
                                <PersonCell name={row.assignee?.name} />
                            ),
                        },
                        {
                            key: 'priority',
                            label: 'Priority',
                            width: '.7fr',
                            cell: (row) => (
                                <EntityStatusChip
                                    variant={
                                        ['high', 'urgent'].includes(
                                            row.priority,
                                        )
                                            ? 'critical'
                                            : 'neutral'
                                    }
                                >
                                    {label(row.priority)}
                                </EntityStatusChip>
                            ),
                        },
                        {
                            key: 'status',
                            label: 'Status / requirements',
                            width: '1.5fr',
                            cell: (row) => (
                                <span>
                                    {status(row)}
                                    <span
                                        className="text-caption block truncate"
                                        title={obligations(row)}
                                    >
                                        {obligations(row)}
                                    </span>
                                </span>
                            ),
                        },
                        { key: 'due', label: 'Due', width: '1fr', cell: due },
                        {
                            key: 'linked',
                            label: 'Ticket',
                            width: '.8fr',
                            cell: linked,
                        },
                    ]}
                    actionsFor={actionsFor}
                    onRowContextMenu={(event, row) => menu.open(event, row.id)}
                    selection={
                        canManage
                            ? {
                                  keys: selected,
                                  onToggle: onSelect,
                                  labelFor: (row) => `Select ${row.item}`,
                                  disabled: busy,
                              }
                            : undefined
                    }
                />
            ) : (
                <EntityCardGrid>
                    {rows.map((row) => (
                        <EntityCard
                            key={row.id}
                            icon={Server}
                            name={row.item}
                            subline={context(row)}
                            meridian={
                                row.status === 'failed' || overdue(row)
                                    ? 'critical'
                                    : row.approval_required &&
                                        row.approval_status !== 'approved'
                                      ? 'warning'
                                      : 'success'
                            }
                            actions={actionsFor(row)}
                            onContextMenu={(event) => menu.open(event, row.id)}
                            selection={
                                canManage
                                    ? {
                                          checked: selected.has(row.id),
                                          label: `Select ${row.item}`,
                                          onToggle: (checked) =>
                                              onSelect(row, checked),
                                          disabled: busy,
                                      }
                                    : undefined
                            }
                            chips={
                                <>
                                    {status(row)}
                                    <EntityChip>
                                        {label(row.priority)} priority
                                    </EntityChip>
                                    <EntityChip>{row.employee.name}</EntityChip>
                                </>
                            }
                            alerts={
                                <>
                                    {due(row)}
                                    {obligations(row) && (
                                        <span className="text-caption">
                                            {obligations(row)}
                                        </span>
                                    )}
                                    {row.failure_reason && (
                                        <span className="text-caption text-status-critical">
                                            {row.failure_reason}
                                        </span>
                                    )}
                                    {linked(row)}
                                </>
                            }
                            footer={{
                                personName: row.assignee?.name,
                                primary:
                                    row.assignee?.name ??
                                    'No technician assigned',
                                secondary:
                                    row.employee.role ??
                                    row.responsible_team?.name ??
                                    undefined,
                            }}
                        />
                    ))}
                </EntityCardGrid>
            )}
        </>
    );
}
