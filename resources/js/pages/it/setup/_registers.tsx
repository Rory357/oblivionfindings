import { EntityCard, EntityCardGrid } from '@/components/lists/entity-card';
import {
    CounterPill,
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
import { ListCaption } from '@/components/lists/list-caption';
import { Boxes, Network, Pencil, UsersRound } from 'lucide-react';
import type { ReactNode } from 'react';
import type { Queue, Service, Team } from './_types';

type SetupRow = Team | Queue | Service;
const label = (value: string) =>
    value.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
export function setupNeedsAttention(row: SetupRow): boolean {
    if ('filter_rules' in row) return row.readiness.gaps.length > 0;
    if ('members' in row) return !row.manager || row.members.length === 0;
    return !row.owner || ['degraded', 'outage'].includes(row.status);
}
export function setupSearch(row: SetupRow): string {
    return [
        row.name,
        row.description,
        'key' in row ? row.key : '',
        'members' in row
            ? [
                  row.manager?.name,
                  ...row.members.map((member) => member.name),
              ].join(' ')
            : 'filter_rules' in row
              ? [
                    row.team?.name,
                    row.readiness.accountable_owner?.name,
                    row.readiness.cover?.name,
                    ...row.readiness.gaps,
                    ...(row.filter_rules.categories ?? []),
                    ...(row.filter_rules.work_types ?? []),
                ].join(' ')
              : [row.owner?.name, row.status, row.criticality].join(' '),
    ]
        .join(' ')
        .toLowerCase();
}
export function SetupRegister<T extends SetupRow>({
    title,
    rows,
    total,
    layout,
    onEdit,
}: {
    title: string;
    rows: T[];
    total: number;
    layout: 'cards' | 'table';
    onEdit: (row: T) => void;
}) {
    const context = useEntityContextMenu<number>();
    const current = rows.find((row) => row.id === context.ctx?.record);
    const actions = (row: T): MenuItem[] => [
        { label: 'Edit', icon: Pencil, onClick: () => onEdit(row) },
    ];
    const icon = (row: T) =>
        'members' in row ? UsersRound : 'filter_rules' in row ? Network : Boxes;
    const owner = (row: T) =>
        'members' in row
            ? row.manager
            : 'filter_rules' in row
              ? row.readiness.accountable_owner
              : row.owner;
    const description = (row: T) =>
        'filter_rules' in row
            ? `${row.key} · ${row.team?.name ?? 'No accountable team'}`
            : 'members' in row
              ? row.description || 'Team membership and ownership'
              : row.key;
    const state = (row: T) => (
        <EntityStatusChip variant={row.is_active ? 'success' : 'neutral'}>
            {row.is_active ? 'Active' : 'Inactive'}
        </EntityStatusChip>
    );
    const facts = (row: T): ReactNode => (
        <>
            {state(row)}
            <EntityChip>{row.workload.open_tickets} open tickets</EntityChip>
            {'members' in row ? (
                <>
                    <EntityChip>{row.workload.members} members</EntityChip>
                    <EntityChip>{row.workload.open_tasks} tasks</EntityChip>
                    <EntityChip>{row.workload.queues} queues</EntityChip>
                </>
            ) : 'filter_rules' in row ? (
                <>
                    <EntityChip>
                        {row.workload.unassigned} unassigned
                    </EntityChip>
                    {row.filter_rules.is_default && (
                        <EntityStatusChip variant="info">
                            Fallback
                        </EntityStatusChip>
                    )}
                    {[
                        ...(row.filter_rules.work_types ?? []),
                        ...(row.filter_rules.categories ?? []),
                        ...(row.filter_rules.priorities ?? []),
                    ]
                        .slice(0, 5)
                        .map((rule) => (
                            <EntityChip key={rule}>{label(rule)}</EntityChip>
                        ))}
                </>
            ) : (
                <>
                    <EntityStatusChip
                        variant={
                            row.status === 'outage'
                                ? 'critical'
                                : row.status === 'operational'
                                  ? 'success'
                                  : 'neutral'
                        }
                    >
                        {label(row.status)}
                    </EntityStatusChip>
                    <EntityChip>
                        {label(row.criticality)} criticality
                    </EntityChip>
                </>
            )}
        </>
    );
    const alerts = (row: T): ReactNode => (
        <>
            {'filter_rules' in row
                ? row.readiness.gaps.map((gap) => (
                      <p key={gap} className="text-xs text-status-warning">
                          {gap}
                      </p>
                  ))
                : !owner(row) && (
                      <EntityStatusChip variant="warning">
                          {'members' in row ? 'No manager' : 'No owner'}
                      </EntityStatusChip>
                  )}
            {'members' in row && row.members.length === 0 && (
                <EntityStatusChip variant="warning">
                    No members
                </EntityStatusChip>
            )}
            {'sla_risk' in row.workload && row.workload.sla_risk > 0 && (
                <EntityStatusChip variant="warning">
                    {row.workload.sla_risk} tickets at SLA risk
                </EntityStatusChip>
            )}
        </>
    );
    const columns: EntityTableColumn<T>[] = [
        { key: 'status', label: 'Status', width: '100px', cell: state },
        {
            key: 'owner',
            label: 'Accountable person',
            width: '1fr',
            cell: (row) => <PersonCell name={owner(row)?.name} />,
        },
        {
            key: 'workload',
            label: 'Open tickets',
            width: '100px',
            cell: (row) => (
                <CounterPill tone="neutral">
                    {row.workload.open_tickets}
                </CounterPill>
            ),
        },
        {
            key: 'context',
            label: 'Context',
            width: '1.4fr',
            cell: (row) => (
                <div className="space-y-1 text-xs text-muted-foreground">
                    {'members' in row ? (
                        <>
                            {row.workload.members} members ·{' '}
                            {row.workload.open_tasks} tasks ·{' '}
                            {row.workload.queues} queues
                        </>
                    ) : 'filter_rules' in row ? (
                        <>
                            {row.team?.name ?? 'No team'} · Cover:{' '}
                            {row.readiness.cover?.name ?? 'Not configured'} ·{' '}
                            {row.workload.unassigned} unassigned
                            {row.filter_rules.is_default && ' · Fallback'}
                        </>
                    ) : (
                        <>
                            <EntityStatusChip
                                variant={
                                    row.status === 'outage'
                                        ? 'critical'
                                        : row.status === 'operational'
                                          ? 'success'
                                          : 'neutral'
                                }
                            >
                                {label(row.status)}
                            </EntityStatusChip>{' '}
                            · {label(row.criticality)} criticality
                        </>
                    )}
                    {alerts(row)}
                </div>
            ),
        },
    ];
    return (
        <section className="space-y-4" aria-label={title}>
            <ListCaption
                title={title}
                caption={`${rows.length} of ${total} shown`}
            />
            {rows.length === 0 ? (
                <div className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                    {total === 0
                        ? `No ${title.toLowerCase()} configured. Use the header action to add one.`
                        : 'No matching records. Clear or change the filters.'}
                </div>
            ) : layout === 'table' ? (
                <EntityTable
                    rows={rows}
                    rowKey={(row) => row.id}
                    identity={(row) => ({
                        name: row.name,
                        icon: icon(row),
                        subline: description(row),
                    })}
                    columns={columns}
                    actionsFor={actions}
                    onOpen={onEdit}
                    onRowContextMenu={(event, row) =>
                        context.open(event, row.id)
                    }
                    mutedFor={(row) => !row.is_active}
                />
            ) : (
                <EntityCardGrid>
                    {rows.map((row) => (
                        <EntityCard
                            className="[&_h3]:overflow-visible [&_h3]:break-words [&_h3]:text-clip [&_h3]:whitespace-normal"
                            key={row.id}
                            name={row.name}
                            icon={icon(row)}
                            subline={description(row)}
                            meridian={
                                setupNeedsAttention(row) ? 'warning' : 'success'
                            }
                            muted={!row.is_active}
                            actions={actions(row)}
                            onOpen={() => onEdit(row)}
                            openLabel="Edit"
                            onContextMenu={(event) =>
                                context.open(event, row.id)
                            }
                            chips={facts(row)}
                            alerts={alerts(row)}
                            footer={{
                                personName: owner(row)?.name,
                                primary:
                                    owner(row)?.name ?? 'No accountable person',
                                secondary:
                                    'filter_rules' in row
                                        ? `Manager · Cover: ${row.readiness.cover?.name ?? 'Not configured'}`
                                        : 'members' in row
                                          ? 'Team manager'
                                          : 'Service owner',
                            }}
                        />
                    ))}
                </EntityCardGrid>
            )}
            {current && context.ctx && (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={current.name}
                    icon={icon(current)}
                    items={actions(current)}
                    onClose={context.close}
                />
            )}
        </section>
    );
}
