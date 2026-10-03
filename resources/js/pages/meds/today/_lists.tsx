/* Meds today › As-needed, Follow-ups and Activity (P01 C3, approved P01 v2
 * `meds-today.tsx`). EntityTables with one menu for the kebab and
 * right-click; Activity is server-paginated, 10 a page. Follow-ups keeps
 * today's content (P08a designs the tab): your refusal follow-ups and the
 * as-needed effect checks, at the time the recorder chose. */
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { PersonDisc } from '@/components/lists/entity-cells';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { Activity, ClipboardList, Flag, HeartPulse, Pill, Repeat } from 'lucide-react';
import type { MouseEvent } from 'react';
import type { ActivityPage, ActivityRow, ClientInfo, PrnFollowUp, PrnMedication, PrnRecorded, RefusalFollowUp } from './types';

type Ctx = (e: MouseEvent, title: string, items: MenuItem[]) => void;

const nameOf = (clients: Map<number, ClientInfo>, id: number, fallback: string) => {
    const c = clients.get(id);
    return { preferred: c?.preferred ?? fallback.split(' ')[0], surname: (c?.name ?? fallback).split(' ').slice(1).join(' '), legal: c?.name ?? fallback };
};

/* ───────────── As-needed ───────────── */
export function AsNeededView({
    medications,
    recorded,
    clients,
    search,
    person,
    canRecord,
    canReportError,
    onRecord,
    chartFor,
    onReportError,
    onContext,
}: {
    medications: PrnMedication[];
    recorded: PrnRecorded[];
    clients: Map<number, ClientInfo>;
    search: string;
    person: number | null;
    canRecord: (m: PrnMedication) => boolean;
    canReportError: boolean;
    onRecord: (m: PrnMedication) => void;
    /** Opens the person's medication record, or null when this worker can't. */
    chartFor: (clientId: number) => (() => void) | null;
    onReportError: (clientId: number) => void;
    onContext: Ctx;
}) {
    const q = search.trim().toLowerCase();
    const rows = medications.filter((m) => (person === null || m.client_id === person) && (!q || `${m.client_name} ${m.name}`.toLowerCase().includes(q)));
    const blocked = (m: PrnMedication) => m.over_limit || m.interval_blocked;
    const menu = (m: PrnMedication): MenuItem[] => {
        const p = nameOf(clients, m.client_id, m.client_name).preferred;
        return compactMenu([
            canRecord(m) && { label: blocked(m) ? 'Why can’t I record this?' : 'Record as-needed dose', icon: Pill, onClick: () => onRecord(m) },
            { separator: true },
            chartFor(m.client_id) && { label: `Open ${p}’s medication record`, icon: ClipboardList, onClick: chartFor(m.client_id)! },
            canReportError && { label: 'Report a medication error', icon: Flag, onClick: () => onReportError(m.client_id) },
        ]);
    };
    const shownRecorded = recorded.filter((x) => person === null || x.client_id === person);
    return (
        <>
            <section className="flex flex-col gap-2.5" aria-label="As-needed medicines">
                <ListCaption title="As-needed medicines for people on your shift" caption={`${rows.length} of ${medications.length} shown`} />
                {rows.length === 0 ? (
                    <Card className="p-2">
                        <EmptyState icon={Pill} title={medications.length ? 'No as-needed medicines match' : 'No as-needed medicines'} description={medications.length ? 'Clear the search or choose “All people”.' : 'Nobody on your shift has an as-needed medicine ordered.'} />
                    </Card>
                ) : (
                    <EntityTable<PrnMedication>
                        rows={rows}
                        rowKey={(m) => m.id}
                        rowHeight="content"
                        minWidth={960}
                        identityLabel="Person"
                        identityWidth="1fr"
                        identity={(m) => {
                            const p = nameOf(clients, m.client_id, m.client_name);
                            return { mark: <PersonDisc name={p.legal} size={30} />, name: p.preferred, subline: p.surname };
                        }}
                        columns={[
                            {
                                key: 'med',
                                label: 'Medicine',
                                width: '1.6fr',
                                cell: (m) => (
                                    <span className="flex flex-col gap-1">
                                        <span className="text-[13px] font-semibold">
                                            {m.name} <span className="font-normal text-muted-foreground">{m.dose}</span>
                                            {m.is_controlled ? ' · controlled' : ''}
                                        </span>
                                        <span className="text-[12px] text-muted-foreground">{m.instructions ?? m.prn_reason ?? ''}</span>
                                    </span>
                                ),
                            },
                            {
                                key: 'last',
                                label: 'Last 24 hours',
                                width: '1.2fr',
                                cell: (m) => (
                                    <span className="text-[12.5px]">
                                        <strong>
                                            {m.given_last_24h} of {m.max_per_day ?? 'no limit'}
                                        </strong>
                                        {m.last_given_label ? ` · last ${m.last_given_label.replace(/^Today\s+/i, '').replace(/^Yesterday\s+(.*)$/i, '$1 yesterday')}` : ' · none'}
                                    </span>
                                ),
                            },
                            {
                                key: 'st',
                                label: 'State',
                                width: '1fr',
                                cell: (m) =>
                                    m.over_limit ? (
                                        <StatusBadge variant="critical">Limit reached</StatusBadge>
                                    ) : m.interval_blocked ? (
                                        <StatusBadge variant="warning">Too soon{m.next_allowed_label ? ` · from ${m.next_allowed_label}` : ''}</StatusBadge>
                                    ) : (
                                        <StatusBadge variant="neutral">Available</StatusBadge>
                                    ),
                            },
                            {
                                key: 'act',
                                label: '',
                                width: '150px',
                                align: 'right',
                                cell: (m) =>
                                    canRecord(m) ? (
                                        <Button
                                            data-return={`prn-${m.id}`}
                                            variant={blocked(m) ? 'outline' : 'default'}
                                            className="frontline-tap"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                onRecord(m);
                                            }}
                                        >
                                            {blocked(m) ? 'Why not?' : 'Record'}
                                        </Button>
                                    ) : null,
                            },
                        ]}
                        actionsFor={menu}
                        onOpen={(m) => (canRecord(m) ? onRecord(m) : undefined)}
                        onRowContextMenu={(e, m) => onContext(e, `${nameOf(clients, m.client_id, m.client_name).preferred} · ${m.name}`, menu(m))}
                    />
                )}
                <p className="text-caption">Counts use the real last 24 hours. A dose at its limit still opens so you can see why and what to do.</p>
            </section>
            <section className="flex flex-col gap-2.5" aria-label="As-needed doses recorded today">
                <ListCaption title="As-needed doses recorded today" caption={`${shownRecorded.length} shown`} />
                {shownRecorded.length === 0 ? (
                    <p className="text-caption">None recorded today.</p>
                ) : (
                    <Card className="gap-0 divide-y p-0">
                        {shownRecorded.map((x) => {
                            const p = nameOf(clients, x.client_id, '');
                            return (
                                <div key={x.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                    <span className="flex items-center gap-3">
                                        <PersonDisc name={p.legal} size={30} />
                                        <span>
                                            <span className="block text-[13px] font-semibold">
                                                {p.preferred} · {x.medication_name}
                                                {x.dose_given ? ` · ${x.dose_given}` : ''}
                                            </span>
                                            <span className="block text-[12px] text-muted-foreground">
                                                {[x.time, x.reason ? `for ${x.reason.toLowerCase()}` : null, x.by, x.check_at && !x.effect_recorded ? `effect check by ${x.check_at}` : null, x.effect_recorded ? 'effect recorded' : null]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </span>
                                        </span>
                                    </span>
                                    <StatusBadge variant={x.status === 'given' ? 'success' : 'warning'}>{x.status === 'given' ? 'Given' : x.status}</StatusBadge>
                                </div>
                            );
                        })}
                    </Card>
                )}
            </section>
        </>
    );
}

/* ───────────── Follow-ups ───────────── */
type FollowUpRow = { kind: 'refusal'; f: RefusalFollowUp } | { kind: 'effect'; f: PrnFollowUp };

export function FollowUpsView({
    refusals,
    effects,
    clients,
    nowIso,
    canRecordRefusal,
    canRecordEffect,
    onReoffer,
    onEffect,
    chartFor,
    onContext,
}: {
    refusals: RefusalFollowUp[];
    effects: PrnFollowUp[];
    clients: Map<number, ClientInfo>;
    nowIso: string;
    canRecordRefusal: (f: RefusalFollowUp) => boolean;
    canRecordEffect: (f: PrnFollowUp) => boolean;
    onReoffer: (f: RefusalFollowUp) => void;
    onEffect: (f: PrnFollowUp) => void;
    /** Opens the person's medication record, or null when this worker can't. */
    chartFor: (clientId: number) => (() => void) | null;
    onContext: Ctx;
}) {
    const now = new Date(nowIso).getTime();
    const rows: FollowUpRow[] = [
        ...refusals.map((f) => ({ kind: 'refusal' as const, f })),
        ...effects.map((f) => ({ kind: 'effect' as const, f })),
    ].sort((a, b) => dueOf(a).localeCompare(dueOf(b)));
    const overdue = (r: FollowUpRow) => (r.kind === 'refusal' ? r.f.overdue : !!r.f.check_due_at && new Date(r.f.check_due_at).getTime() < now);
    const person = (r: FollowUpRow) => (r.kind === 'refusal' ? r.f.preferred : (clients.get(r.f.client_id)?.preferred ?? clients.get(r.f.client_id)?.name ?? 'Client'));
    const legal = (r: FollowUpRow) => clients.get(r.f.client_id)?.name ?? person(r);
    const act = (r: FollowUpRow) =>
        r.kind === 'refusal'
            ? canRecordRefusal(r.f)
                ? () => onReoffer(r.f)
                : null
            : canRecordEffect(r.f)
              ? () => onEffect(r.f)
              : null;
    const menu = (r: FollowUpRow): MenuItem[] =>
        compactMenu([
            act(r) && { label: r.kind === 'refusal' ? 'Record re-offer' : 'Record whether it helped', icon: r.kind === 'refusal' ? Repeat : HeartPulse, onClick: act(r)! },
            { separator: true },
            chartFor(r.f.client_id) && { label: `Open ${person(r)}’s medication record`, icon: ClipboardList, onClick: chartFor(r.f.client_id)! },
        ]);
    if (rows.length === 0)
        return (
            <Card className="p-2">
                <EmptyState icon={HeartPulse} title="No follow-ups open" description="A refused dose or an as-needed dose adds one here, owned by whoever recorded it, until its result is recorded." />
            </Card>
        );
    return (
        <section className="flex flex-col gap-2.5" aria-label="Follow-ups">
            <ListCaption title="Follow-ups for people on your shift" caption={`${rows.length} open · ${rows.filter(overdue).length} overdue`} />
            <EntityTable<FollowUpRow>
                rows={rows}
                rowKey={(r) => `${r.kind}-${r.kind === 'refusal' ? r.f.id : r.f.administration_id}`}
                rowHeight="content"
                minWidth={900}
                identityLabel="Person"
                identityWidth="1fr"
                identity={(r) => ({ mark: <PersonDisc name={legal(r)} size={30} />, name: person(r) })}
                columns={[
                    {
                        key: 'what',
                        label: 'Follow-up',
                        width: '1.6fr',
                        cell: (r) => (
                            <span className="flex flex-col gap-1">
                                <span className="text-[13px] font-semibold">{r.kind === 'refusal' ? `Refused: ${r.f.medication_name ?? 'medicine'}` : `Did it help? ${r.f.medication_name ?? 'As-needed dose'}`}</span>
                                <span className="text-[12px] text-muted-foreground">
                                    {r.kind === 'refusal'
                                        ? `Refused ${r.f.refused_time ?? ''} · offer again, or record why not`
                                        : `Given ${r.f.given_time ?? ''}${r.f.dose_given ? ` · ${r.f.dose_given}` : ''}${r.f.by ? ` · ${r.f.by}` : ''}`}
                                </span>
                            </span>
                        ),
                    },
                    { key: 'due', label: 'Due', width: '0.8fr', cell: (r) => <span className="text-[12.5px] font-semibold tabular-nums">{r.kind === 'refusal' ? (r.f.due_time ?? '—') : (r.f.check_at ?? '—')}</span> },
                    { key: 'owner', label: 'Owner', width: '1fr', cell: (r) => <span className="text-[12.5px]">{r.kind === 'refusal' ? (r.f.owner ?? '—') : (r.f.by ?? '—')}</span> },
                    {
                        key: 'st',
                        label: 'State',
                        width: '0.9fr',
                        cell: (r) => (overdue(r) ? <StatusBadge variant="critical">Overdue</StatusBadge> : <StatusBadge variant="info">Open</StatusBadge>),
                    },
                    {
                        key: 'act',
                        label: '',
                        width: '190px',
                        align: 'right',
                        cell: (r) => {
                            const fn = act(r);
                            return fn ? (
                                <Button
                                    variant="outline"
                                    className="frontline-tap"
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        fn();
                                    }}
                                >
                                    {r.kind === 'refusal' ? (
                                        <>
                                            <Repeat className="size-4" aria-hidden="true" /> Record re-offer
                                        </>
                                    ) : (
                                        'Record whether it helped'
                                    )}
                                </Button>
                            ) : null;
                        },
                    },
                ]}
                actionsFor={menu}
                onOpen={(r) => act(r)?.()}
                onRowContextMenu={(e, r) => onContext(e, `${person(r)} · follow-up`, menu(r))}
            />
            <p className="text-caption">Times in NZ time. An as-needed check uses the time chosen when the dose was recorded.</p>
        </section>
    );
}

function dueOf(r: FollowUpRow): string {
    return (r.kind === 'refusal' ? r.f.due_at : r.f.check_due_at) ?? '9999';
}

/* ───────────── Activity (server-paginated) ───────────── */
export function ActivityView({
    page,
    range,
    houseLabel,
    canReportError,
    chartFor,
    onReportError,
    onContext,
}: {
    page: ActivityPage | undefined;
    range: string;
    houseLabel: string | null;
    canReportError: boolean;
    /** Opens the person's medication record, or null when this worker can't. */
    chartFor: (clientId: number) => (() => void) | null;
    onReportError: (clientId: number) => void;
    onContext: Ctx;
}) {
    const rows = page?.data ?? [];
    const menu = (a: ActivityRow): MenuItem[] =>
        compactMenu([
            chartFor(a.client_id) && { label: `Open ${a.preferred}’s medication record`, icon: ClipboardList, onClick: chartFor(a.client_id)! },
            canReportError && { label: 'Report a medication error', icon: Flag, onClick: () => onReportError(a.client_id) },
        ]);
    return (
        <section className="flex flex-col gap-2.5" aria-label="Activity">
            <ListCaption
                title={`${range === 'today' ? 'Recorded today' : 'Recorded in the last 24 hours'} ${houseLabel ? `at ${houseLabel}` : 'for people on your shift'}`}
                caption={`${rows.length} of ${page?.total ?? 0} shown`}
            />
            {rows.length === 0 ? (
                <Card className="p-2">
                    <EmptyState icon={Activity} title="Nothing recorded" description="Nothing matches this range, outcome or search." />
                </Card>
            ) : (
                <EntityTable<ActivityRow>
                    rows={rows}
                    rowKey={(a) => a.id}
                    minWidth={900}
                    rowHeight="content"
                    identityLabel="Person"
                    identity={(a) => ({ mark: <PersonDisc name={`${a.preferred} ${a.surname ?? ''}`.trim()} size={30} />, name: a.preferred, subline: a.surname ?? undefined })}
                    columns={[
                        { key: 'when', label: 'When', width: '0.9fr', cell: (a) => <span className="text-[12.5px] tabular-nums">{a.time}{a.day ? ` ${a.day}` : ''}</span> },
                        { key: 'med', label: 'Medicine', width: '1.3fr', cell: (a) => <span className="text-[12.5px] font-semibold">{a.medication_name}</span> },
                        {
                            key: 'out',
                            label: 'Outcome',
                            width: '1.1fr',
                            cell: (a) => <StatusBadge variant={a.status === 'given' ? 'success' : a.status === 'missed' ? 'critical' : 'warning'}>{a.outcome}</StatusBadge>,
                        },
                        {
                            key: 'by',
                            label: 'By',
                            width: '1fr',
                            cell: (a) => (
                                <span className="flex items-center gap-2 text-[12.5px]">
                                    {a.by ? <PersonDisc name={a.by} size={22} /> : null}
                                    {a.by ?? '—'}
                                </span>
                            ),
                        },
                        { key: 'd', label: 'Detail', width: '1.6fr', cell: (a) => <span className="text-[12px] text-muted-foreground">{a.detail ?? ''}</span> },
                    ]}
                    actionsFor={menu}
                    onOpen={(a) => chartFor(a.client_id)?.()}
                    onRowContextMenu={(e, a) => onContext(e, `${a.preferred} · ${a.medication_name}`, menu(a))}
                />
            )}
            {page ? <LaravelPagination links={page.links} lastPage={page.last_page} preserveScroll /> : null}
            <p className="text-caption">Times in NZ time. 10 per page. A row opens the person’s medication record when you can view it.</p>
        </section>
    );
}
