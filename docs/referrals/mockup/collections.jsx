import { EntityCard, EntityCardGrid } from '@/components/lists/entity-card';
import {
    EntityChip,
    EntityStatusChip,
    PersonCell,
    PersonDisc,
} from '@/components/lists/entity-cells';
import {
    EntityContextMenu,
    useEntityContextMenu,
} from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Pagination } from '@/components/ui/pagination';
import {
    ArrowUpRight,
    CalendarDays,
    Check,
    Phone,
    Users,
    Wallet,
    Workflow,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { usePrototype } from './context';
import { TODAY, date } from './data';
import { followupsFor } from './model';
import { ReferralPipeline } from './pipeline';
import { Badge, Empty } from './ui';

const overdue = (r) =>
    !r.nextDone && r.due < TODAY && !['Closed', 'Converted'].includes(r.stage);
export function ReferralCollection({ records, total, view }) {
    const { can, request, navigate, data, failure } = usePrototype();
    const menu = useEntityContextMenu();
    const open = (r) => navigate('Referrals', r.id);
    const actions = (r) => [
        { label: 'Open referral', icon: ArrowUpRight, onClick: () => open(r) },
        {
            label: 'Assign owner',
            icon: Users,
            onClick: () => request('assign', r),
            disabled: can('assign') ? undefined : 'Requires Assign referrals',
        },
        {
            label: 'Log contact',
            icon: Phone,
            onClick: () => request('contact', r),
            disabled: can('contact') ? undefined : 'Requires Log contact',
        },
        {
            label: 'Review handover',
            icon: ArrowUpRight,
            onClick: () => request('convert', r),
            disabled: can('convert')
                ? undefined
                : 'Requires Create / link supported person',
        },
        ...(can('transition') && !['Closed', 'Converted'].includes(r.stage)
            ? [
                  {
                      label: 'Review stage change',
                      icon: Workflow,
                      onClick: () => request('lifecycle', r),
                  },
              ]
            : []),
    ];
    const card = (r) => (
        <EntityCard
            key={r.id}
            name={r.name}
            href={'#referral=' + r.id}
            mark={<PersonDisc name={r.name} size={40} />}
            subline={`${r.id} · ${r.source}`}
            meridian={overdue(r) ? 'warning' : 'neutral'}
            actions={actions(r)}
            onOpen={() => open(r)}
            onContextMenu={(e) => menu.open(e, r)}
            chips={
                <>
                    <Badge>{r.stage}</Badge>
                    <EntityChip>{r.site}</EntityChip>
                    {overdue(r) ? (
                        <EntityStatusChip variant="warning">
                            Overdue · {date(r.due)}
                        </EntityStatusChip>
                    ) : (
                        <EntityChip icon={CalendarDays}>
                            {r.due === TODAY && !r.nextDone
                                ? 'Due today'
                                : date(r.due)}
                        </EntityChip>
                    )}
                </>
            }
            alerts={
                <div className="referral-next-step">
                    <span className="text-caption text-muted-foreground">
                        {r.nextDone || ['Closed', 'Converted'].includes(r.stage)
                            ? 'Last recorded action'
                            : 'Next step'}
                    </span>
                    <p>{r.next}</p>
                </div>
            }
            footer={{
                personName: r.owner === 'Intake team' ? null : r.owner,
                primary: r.owner,
                secondary:
                    r.owner === 'Intake team'
                        ? 'Coordinator not yet assigned'
                        : undefined,
            }}
        />
    );
    if (failure === 'Loading')
        return (
            <div role="status" className="panel text-subtle animate-pulse">
                Loading referrals…
            </div>
        );
    if (!records.length || failure === 'Empty')
        return (
            <Empty title="No referrals match this view">
                Change the search, site or stage filter to see other permitted
                records.
            </Empty>
        );
    return (
        <div className="grid gap-5">
            <ListCaption
                title="Referrals"
                caption={`${records.length} of ${total} shown`}
            />
            {view === 'Pipeline' ? (
                <ReferralPipeline
                    records={records}
                    actions={actions}
                    open={open}
                    contextMenu={menu.open}
                />
            ) : (
                <>
                    {view === 'List' && (
                        <div className="hidden md:block">
                            <EntityTable
                                rows={records}
                                rowKey={(r) => r.id}
                                identityLabel="Person / referral"
                                identityWidth="1.6fr"
                                identity={(r) => ({
                                    mark: (
                                        <PersonDisc name={r.name} size={30} />
                                    ),
                                    name: r.name,
                                    subline: `${r.id} · ${r.source}`,
                                })}
                                columns={[
                                    {
                                        key: 'stage',
                                        label: 'Stage',
                                        width: '1.1fr',
                                        cell: (r) => <Badge>{r.stage}</Badge>,
                                    },
                                    {
                                        key: 'owner',
                                        label: 'Owner',
                                        width: '1.15fr',
                                        cell: (r) => (
                                            <PersonCell
                                                name={
                                                    r.owner === 'Intake team'
                                                        ? null
                                                        : r.owner
                                                }
                                            />
                                        ),
                                    },
                                    {
                                        key: 'site',
                                        label: 'Site',
                                        width: '1fr',
                                        cell: (r) => (
                                            <EntityChip>{r.site}</EntityChip>
                                        ),
                                    },
                                    {
                                        key: 'next',
                                        label: 'Next action',
                                        width: '1.8fr',
                                        cell: (r) => (
                                            <span>
                                                {r.nextDone
                                                    ? 'Complete'
                                                    : r.next}
                                            </span>
                                        ),
                                    },
                                    {
                                        key: 'due',
                                        label: 'Follow up by',
                                        width: '1fr',
                                        cell: (r) => (
                                            <div>
                                                <span>{date(r.due)}</span>
                                                {overdue(r) && (
                                                    <Badge tone="warning">
                                                        Overdue
                                                    </Badge>
                                                )}
                                            </div>
                                        ),
                                    },
                                ]}
                                actionsFor={actions}
                                onOpen={open}
                                onRowContextMenu={menu.open}
                                minWidth={980}
                            />
                        </div>
                    )}
                    <div className={view === 'List' ? 'md:hidden' : ''}>
                        <EntityCardGrid>{records.map(card)}</EntityCardGrid>
                    </div>
                </>
            )}
            {menu.ctx && (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    title={menu.ctx.record.name}
                    items={actions(menu.ctx.record)}
                    onClose={menu.close}
                />
            )}
        </div>
    );
}

export function WorkCollection({ records, total, view, mode }) {
    const { can, request, navigate, update, filter, actor, advanced, query } =
        usePrototype();
    const [page, setPage] = useState(1);
    useEffect(
        () => setPage(1),
        [records.map((r) => r.id).join(','), filter, advanced, mode],
    );
    const menu = useEntityContextMenu();
    const funding = mode === 'Funding assistance';
    if (funding && !can('fundingRead'))
        return (
            <Empty title="Funding details are restricted">
                View funding information is required.
            </Empty>
        );
    const allRows = records
        .filter((r) => r.stage !== 'Closed')
        .flatMap((r) =>
            funding
                ? r.funding.map((f) => ({
                      id: f.id,
                      r,
                      f,
                      label: f.path,
                      owner: f.navigator,
                      due: f.due || r.due,
                      status: f.status,
                  }))
                : followupsFor(r)
                      .filter((w) => !w.financial || can('fundingRead'))
                      .map((w) => ({ ...w, r, work: w })),
        )
        .filter(
            (w) =>
                (!query ||
                    `${w.r.name} ${w.r.id} ${w.label} ${w.r.phone} ${w.r.email || ''} ${w.f?.reference || ''} ${w.owner}`
                        .toLowerCase()
                        .includes(query.toLowerCase())) &&
                (!funding ||
                    advanced.fundingStatus === 'All funding states' ||
                    w.status === advanced.fundingStatus) &&
                (filter !== 'Mine' || w.owner === actor) &&
                (filter !== 'Overdue' || w.due < TODAY) &&
                (advanced.owner === 'All owners' ||
                    w.owner === advanced.owner) &&
                (advanced.path === 'All pathways' ||
                    w.f?.path === advanced.path ||
                    w.r.funding.find((f) => f.id === w.fundingId)?.path ===
                        advanced.path),
        );
    allRows.sort((a, b) =>
        advanced.sort === 'Name A–Z'
            ? a.r.name.localeCompare(b.r.name)
            : a.due.localeCompare(b.due),
    );
    const rows = allRows.slice((page - 1) * 10, page * 10);
    const open = (row) =>
        navigate('Referrals', row.r.id, funding ? 'Funding' : 'Tasks');
    const actions = (row) => [
        {
            label: 'Open referral',
            icon: ArrowUpRight,
            onClick: () => open(row),
        },
        ...(funding
            ? [
                  {
                      label: 'Work on funding case',
                      icon: Wallet,
                      onClick: () =>
                          request('fundingCase', row.r, { funding: row.f }),
                      disabled: can('funding')
                          ? undefined
                          : 'Requires Manage funding assistance',
                  },
              ]
            : [
                  {
                      label: 'Work on follow-up',
                      icon: Check,
                      disabled: can(row.financial ? 'funding' : 'tasks')
                          ? undefined
                          : 'Requires follow-up permission',
                      onClick: () =>
                          request('workEdit', row.r, { work: row.work }),
                  },
              ]),
    ];
    if (!rows.length)
        return (
            <Empty
                title={
                    funding
                        ? 'No funding cases in this view'
                        : 'No outstanding follow-ups'
                }
            >
                Change the filters to see other permitted work.
            </Empty>
        );
    const card = (row) => (
        <EntityCard
            key={row.id}
            name={row.r.name}
            href={
                '#referral=' +
                row.r.id +
                '&section=' +
                (funding ? 'Funding' : 'Tasks')
            }
            mark={<PersonDisc name={row.r.name} size={40} />}
            subline={row.label}
            meridian={row.due < TODAY ? 'warning' : 'success'}
            actions={actions(row)}
            onOpen={() => open(row)}
            onContextMenu={(e) => menu.open(e, row)}
            chips={
                <>
                    <Badge>{row.status}</Badge>
                    <EntityChip>{row.r.site}</EntityChip>
                </>
            }
            alerts={
                <EntityStatusChip
                    variant={row.due < TODAY ? 'warning' : 'neutral'}
                    icon={CalendarDays}
                >
                    {row.due < TODAY ? 'Overdue · ' : ''}
                    {date(row.due)}
                </EntityStatusChip>
            }
            footer={{
                personName: row.owner,
                primary: row.owner,
                secondary: row.r.id,
            }}
        />
    );
    return (
        <div className="grid gap-5">
            <ListCaption
                title={mode}
                caption={`${allRows.length} items · ${records.length} of ${total} referrals`}
            />
            {view === 'List' && (
                <div className="hidden md:block">
                    <EntityTable
                        rows={rows}
                        rowKey={(r) => r.id}
                        identityLabel="Person / work"
                        identityWidth="2fr"
                        identity={(row) => ({
                            name: row.r.name,
                            mark: <PersonDisc name={row.r.name} size={30} />,
                            subline: row.label,
                        })}
                        columns={[
                            {
                                key: 'owner',
                                label: funding ? 'Navigator' : 'Owner',
                                width: '1.2fr',
                                cell: (row) => <PersonCell name={row.owner} />,
                            },
                            {
                                key: 'status',
                                label: 'Status',
                                width: '1fr',
                                cell: (row) => <Badge>{row.status}</Badge>,
                            },
                            {
                                key: 'site',
                                label: 'Site',
                                width: '1fr',
                                cell: (row) => (
                                    <EntityChip>{row.r.site}</EntityChip>
                                ),
                            },
                            {
                                key: 'due',
                                label: 'Due',
                                width: '1fr',
                                cell: (row) => (
                                    <span>
                                        {date(row.due)}
                                        {row.due < TODAY ? ' · Overdue' : ''}
                                    </span>
                                ),
                            },
                        ]}
                        actionsFor={actions}
                        onOpen={open}
                        onRowContextMenu={menu.open}
                        minWidth={850}
                    />
                </div>
            )}
            <div className={view === 'List' ? 'md:hidden' : ''}>
                <EntityCardGrid>{rows.map(card)}</EntityCardGrid>
            </div>
            <Pagination
                currentPage={page}
                lastPage={Math.ceil(allRows.length / 10)}
                onPageChange={setPage}
            />
            {menu.ctx && (
                <EntityContextMenu
                    x={menu.ctx.x}
                    y={menu.ctx.y}
                    title={menu.ctx.record.r.name}
                    items={actions(menu.ctx.record)}
                    onClose={menu.close}
                />
            )}
        </div>
    );
}
