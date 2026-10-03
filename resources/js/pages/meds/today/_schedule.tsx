/* Meds today › Schedule (P01 C3, approved P01 v2 `meds-today.tsx`): the
 * doses of the people on your shift as EntityTables, by time or by person,
 * each row saying its state and what you can do; the people you may open
 * who aren't on your shift underneath, so nothing is missed. */
import { Notice, Rich } from '@/components/emar/record-dose/parts';
import { EntityChip, PersonDisc } from '@/components/lists/entity-cells';
import { EntityKebab, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { useIsMobile } from '@/hooks/use-mobile';
import {
    CheckCircle2,
    Clock3,
    ListChecks,
    LogIn,
    RefreshCw,
} from 'lucide-react';
import type { MouseEvent, ReactNode } from 'react';
import {
    DoseActionCell,
    DoseStateCell,
    MedicineCell,
    doseMenu,
    isOpen,
    isStaffDose,
    mainAction,
    needsHelp,
    nzTime,
    type RowActions,
    type RowPermissions,
} from './_rows';
import type { ClientInfo, OnCallContact, ScheduleRow } from './types';

export type StateFilter = 'all' | 'open' | 'help' | 'recorded';
export type Grouping = 'time' | 'person';

export function personOf(row: ScheduleRow, clients: Map<number, ClientInfo>) {
    const c = clients.get(row.client_id);
    const preferred = c?.preferred ?? row.client_name.split(' ')[0];
    const surname = row.client_name.split(' ').slice(1).join(' ');
    return { preferred, surname, legal: row.client_name };
}

/** "**Rangi Parata** (021 555 0142)", why there's nobody, or the Not configured chip. */
export function onCallWords(c: OnCallContact | null | undefined): string {
    if (!c || !c.configured) return '{NC}';
    if (c.name) return `**${c.name}**${c.phone ? ` (${c.phone})` : ''}`;
    return c.warning ?? 'nobody right now';
}

function DoseTable({
    rows,
    clients,
    can,
    actions,
    onContext,
}: {
    rows: ScheduleRow[];
    clients: Map<number, ClientInfo>;
    can: RowPermissions;
    actions: RowActions;
    onContext: (e: MouseEvent, title: string, items: MenuItem[]) => void;
}) {
    const mobile = useIsMobile();
    return (
        <>
            {mobile ? (
                <ul className="divide-y" aria-label="Scheduled doses">
                    {rows.map((r) => {
                        const p = personOf(r, clients);
                        const menu = doseMenu(r, p.preferred, can, actions);
                        return (
                            <li
                                key={r.key}
                                className="min-w-0 space-y-3 px-4 py-3"
                                onContextMenu={(event) =>
                                    onContext(
                                        event,
                                        `${p.preferred} · ${r.medication_name}`,
                                        menu,
                                    )
                                }
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <div className="flex min-w-0 items-center gap-2">
                                        <PersonDisc name={p.legal} size={30} />
                                        <div className="min-w-0">
                                            <p className="font-semibold break-words">
                                                {p.preferred} {p.surname}
                                            </p>
                                            <p className="text-caption">
                                                Due {nzTime(r.scheduled_for)}
                                            </p>
                                        </div>
                                    </div>
                                    <EntityKebab
                                        actions={menu}
                                        label={`Actions for ${p.preferred} · ${r.medication_name}`}
                                    />
                                </div>
                                <MedicineCell row={r} />
                                <DoseStateCell row={r} person={p.preferred} />
                                <DoseActionCell
                                    row={r}
                                    can={can}
                                    actions={actions}
                                />
                            </li>
                        );
                    })}
                </ul>
            ) : (
                <EntityTable<ScheduleRow>
                    rows={rows}
                    rowKey={(r) => r.key}
                    rowHeight="content"
                    minWidth={980}
                    identityLabel="Person"
                    identityWidth="1.05fr"
                    identity={(r) => {
                        const p = personOf(r, clients);
                        return {
                            mark: <PersonDisc name={p.legal} size={30} />,
                            name: p.preferred,
                            subline: p.surname,
                        };
                    }}
                    columns={[
                        {
                            key: 'med',
                            label: 'Medicine',
                            width: '1.7fr',
                            cell: (r) => <MedicineCell row={r} />,
                        },
                        {
                            key: 'due',
                            label: 'Due',
                            width: '0.7fr',
                            cell: (r) => (
                                <span className="text-[12.5px] font-semibold tabular-nums">
                                    {nzTime(r.scheduled_for)}
                                </span>
                            ),
                        },
                        {
                            key: 'state',
                            label: 'State',
                            width: '1.9fr',
                            cell: (r) => (
                                <DoseStateCell
                                    row={r}
                                    person={personOf(r, clients).preferred}
                                />
                            ),
                        },
                        {
                            key: 'act',
                            label: '',
                            width: '196px',
                            align: 'right',
                            cell: (r) => (
                                <DoseActionCell
                                    row={r}
                                    can={can}
                                    actions={actions}
                                />
                            ),
                        },
                    ]}
                    actionsFor={(r) =>
                        doseMenu(
                            r,
                            personOf(r, clients).preferred,
                            can,
                            actions,
                        )
                    }
                    onOpen={(r) => mainAction(r, can, actions)?.()}
                    onRowContextMenu={(e, r) =>
                        onContext(
                            e,
                            `${personOf(r, clients).preferred} · ${r.medication_name}`,
                            doseMenu(
                                r,
                                personOf(r, clients).preferred,
                                can,
                                actions,
                            ),
                        )
                    }
                    mutedFor={(r) => r.status === 'away'}
                />
            )}
        </>
    );
}

export function ScheduleView({
    rows,
    offShift,
    clients,
    search,
    stateFilter,
    grouping,
    can,
    actions,
    onContext,
    clockedIn,
    hasShift,
    onCall,
    notes,
    stale,
    onRefresh,
    houseLabel,
    tzLabel,
    concealedTotal = 0,
    concealedOpen = 0,
    concealedWaiting = 0,
}: {
    rows: ScheduleRow[];
    offShift: ScheduleRow[];
    clients: Map<number, ClientInfo>;
    search: string;
    stateFilter: StateFilter;
    grouping: Grouping;
    can: RowPermissions;
    actions: RowActions;
    onContext: (e: MouseEvent, title: string, items: MenuItem[]) => void;
    clockedIn: boolean;
    hasShift: boolean;
    onCall: OnCallContact | null | undefined;
    notes: string[];
    stale: string | null;
    onRefresh: () => void;
    houseLabel: string | null;
    /** "NZDT" / "NZST". */
    tzLabel: string;
    concealedTotal?: number;
    concealedOpen?: number;
    concealedWaiting?: number;
}) {
    const q = search.trim().toLowerCase();
    const match = (r: ScheduleRow) => {
        if (
            q &&
            !`${r.client_name} ${personOf(r, clients).preferred} ${r.medication_name}`
                .toLowerCase()
                .includes(q)
        )
            return false;
        if (stateFilter === 'open') return isOpen(r);
        if (stateFilter === 'help') return needsHelp(r);
        if (stateFilter === 'recorded') return r.recorded !== null;
        return true;
    };
    const shown = rows.filter(match);
    // [key, title, rows shown, rows in the group before filtering]
    const groups: [string, string, ScheduleRow[], number][] =
        grouping === 'person'
            ? [...new Set(shown.map((r) => r.client_id))].map((id) => {
                  const first = shown.find((r) => r.client_id === id)!;
                  return [
                      `p${id}`,
                      first.client_name,
                      shown.filter((r) => r.client_id === id),
                      rows.filter((r) => r.client_id === id).length,
                  ];
              })
            : [...new Set(shown.map((r) => r.scheduled_for))].map((at) => [
                  at,
                  `${nzTime(at)} doses`,
                  shown.filter((r) => r.scheduled_for === at),
                  rows.filter((r) => r.scheduled_for === at).length,
              ]);
    const people = [
        ...new Map(
            rows.map((r) => [r.client_id, personOf(r, clients).preferred]),
        ).values(),
    ];
    const offPeople = [
        ...new Map(
            offShift.map((r) => [r.client_id, personOf(r, clients).preferred]),
        ).values(),
    ];

    const banners: ReactNode[] = [];
    if (hasShift && !clockedIn)
        banners.push(
            <Notice
                key="clock"
                tone="warning"
                icon={LogIn}
                title="You’re not clocked in"
                actions={
                    <>
                        <Button asChild className="frontline-tap">
                            <a href="/attendance">
                                <LogIn className="size-4" aria-hidden="true" />{' '}
                                Clock in
                            </a>
                        </Button>
                        <span className="text-caption self-center">
                            Can’t clock in? Coordinator on call:{' '}
                            <Rich text={onCallWords(onCall)} />
                        </span>
                    </>
                }
            >
                You can read today’s medicines, but you can’t record anything
                until you clock in on a shift that includes these people.
            </Notice>,
        );
    if (stale)
        banners.push(
            <Notice
                key="stale"
                tone="warning"
                title={`Not updated since ${stale}`}
                actions={
                    <Button
                        className="frontline-tap"
                        variant="outline"
                        onClick={onRefresh}
                    >
                        <RefreshCw className="size-4" aria-hidden="true" />{' '}
                        Refresh now
                    </Button>
                }
            >
                We couldn’t refresh. What you see may be out of date — someone
                may have recorded a dose since. Before you record, refresh.
                Saving always checks the chart again.
            </Notice>,
        );

    const nothingToday = rows.length === 0;
    const waiting =
        rows.filter(
            (r) =>
                r.recorded === null &&
                (r.state ?? r.status) === 'pending_check',
        ).length + concealedWaiting;
    const staffRows = rows.filter(isStaffDose);
    const nothingLeft =
        !nothingToday &&
        staffRows.every((r) => r.recorded !== null) &&
        concealedOpen === 0 &&
        waiting === 0;

    return (
        <>
            {banners}
            {nothingToday ? (
                <Card className="p-2">
                    <EmptyState
                        icon={CheckCircle2}
                        title={
                            concealedTotal
                                ? 'Some scheduled doses aren’t shown'
                                : hasShift
                                  ? 'No scheduled doses on your shift today'
                                  : 'You don’t have a shift today'
                        }
                        description={
                            concealedTotal
                                ? `${concealedTotal} controlled doses aren’t shown with your current access. Ask an authorised colleague or the house lead to check them.`
                                : hasShift
                                  ? (notes[0] ??
                                    'Nobody on your shift has scheduled medicines today. As-needed medicines are in the As-needed tab.')
                                  : 'Once you’re rostered, the medicines for the people on your shift show here.'
                        }
                    />
                </Card>
            ) : groups.length === 0 ? (
                <Card className="p-2">
                    <EmptyState
                        icon={ListChecks}
                        title="No doses match"
                        description="Nothing on your shift matches this search or filter. Clear the search or choose “All states”."
                    />
                </Card>
            ) : (
                <>
                    {waiting > 0 && stateFilter === 'all' && !q ? (
                        <Notice tone="warning" title="Waiting for order checks">
                            {waiting}{' '}
                            {waiting === 1 ? 'dose needs' : 'doses need'} an
                            order check. Those doses aren’t counted as staff
                            doses to record yet.
                        </Notice>
                    ) : null}
                    {nothingLeft && stateFilter === 'all' && !q ? (
                        <Notice
                            tone={staffRows.length ? 'success' : 'info'}
                            icon={CheckCircle2}
                            title={
                                staffRows.length
                                    ? 'Nothing left to record on your shift'
                                    : 'No staff doses to record'
                            }
                        >
                            {staffRows.length
                                ? 'Every staff dose shown has an outcome. No further staff doses are due on this shift today.'
                                : 'Shown medicines are self-managed or the person is away. They aren’t staff doses to record.'}
                        </Notice>
                    ) : null}
                    {groups.map(([key, title, ds, total]) => (
                        <section
                            key={key}
                            className="flex flex-col gap-2.5"
                            aria-label={title}
                        >
                            <ListCaption
                                title={title}
                                caption={`${ds.length} of ${total} shown`}
                                right={
                                    grouping === 'time' &&
                                    ds[0].window_opens_at ? (
                                        <EntityChip icon={Clock3}>
                                            Window{' '}
                                            {nzTime(ds[0].window_opens_at)}–
                                            {nzTime(ds[0].window_ends_at)}
                                        </EntityChip>
                                    ) : null
                                }
                            />
                            <DoseTable
                                rows={ds}
                                clients={clients}
                                can={can}
                                actions={actions}
                                onContext={onContext}
                            />
                        </section>
                    ))}
                </>
            )}
            {offShift.length ? (
                <section
                    className="flex flex-col gap-2.5"
                    aria-label="Not on your shift"
                >
                    <ListCaption
                        title={`${houseLabel ? `At ${houseLabel}` : 'In your houses'}, not on your shift`}
                        caption={`${offShift.length} shown`}
                    />
                    <p className="text-caption">
                        {offPeople.length === 1
                            ? `Shown so nothing is missed. You can’t record for ${offPeople[0]} until ${offPeople[0]} is on your shift — the row says why and who can help.`
                            : 'Shown so nothing is missed. You can’t record for them until they’re on your shift — each row says why and who can help.'}
                    </p>
                    <DoseTable
                        rows={offShift}
                        clients={clients}
                        can={can}
                        actions={actions}
                        onContext={onContext}
                    />
                </section>
            ) : null}
            <p className="text-caption">
                {[
                    people.length
                        ? `Showing medicines for ${people.join(', ')}.`
                        : null,
                    `Times in ${tzLabel}.`,
                    ...notes,
                    'Each dose shows its own window — the organisation’s dose window, or the order’s when it sets one.',
                ]
                    .filter(Boolean)
                    .join(' ')}
            </p>
        </>
    );
}
