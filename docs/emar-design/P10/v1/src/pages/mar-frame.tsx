/* A FRAME of P02’s person record › MAR & medicines › Today, showing only what
 * P10 changes there (Main, Q3, Q5): the live strip for the person using
 * emergency access, the blocked state for someone who isn’t on a shift for
 * the person (with “Start emergency access” only for people who hold it —
 * nobody else is told about it), and P01’s record dialog when the access
 * ends mid-record. Everything else on the page is P02’s and link-only. */
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { PageHeader, PageHeaderFilterButton, PageHeaderRail, PageHeaderStatusChip } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { CalendarDays, ClipboardList, Clock3, History, KeyRound, LockKeyhole, Phone, Pill, UserRound, type LucideIcon } from 'lucide-react';
import { HOUSES, ONCALL, ORDERS, PEOPLE, PERSONAS, has, secondPersonFor, type Order, type PersonId, type PersonaId } from '../data';
import { LiveStrip } from '../ea-ui';
import { useOpen } from '../host';
import { allGrants, canRequest, canAudit, eventLog, housesOf, isLive, time12, TODAY_ISO, NOW_HM, type Runtime, type Scenario } from '../model';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
import { DesignNote, Notice, StateLine, Wrap } from '../ui';
import { useCtx } from './hub';

/** Who this morning’s shifts cover (synthetic roster): Aroha’s own worker went home unwell at 8:20. */
export const COVERS: Partial<Record<PersonaId, PersonId[]>> = { sw: ['tama', 'mele', 'grace', 'sam'], lead: ['tama', 'mele', 'grace', 'sam'], rimu: ['ben', 'hemi'] };
export const covers = (p: PersonaId, pid: PersonId) => !!COVERS[p]?.includes(pid);
/** Who can open the person’s chart in this frame: their house’s staff, and people who oversee (audit.view). */
export const canViewChart = (p: PersonaId, pid: PersonId) => housesOf(p).includes(PEOPLE[pid].house) && (canAudit(p) || !!COVERS[p] || canRequest(p));
export function myLiveGrant(rt: Runtime, scn: Scenario, p: PersonaId, pid: PersonId) {
    return allGrants(rt, scn).find((g) => g.pid === pid && g.by === PERSONAS[p].name && isLive(g));
}
/** The grant that ran out while the dialog was open (the “expired” scenario). */
export function myEndedGrant(rt: Runtime, scn: Scenario, p: PersonaId, pid: PersonId) {
    return scn === 'expired' ? allGrants(rt, scn).find((g) => g.pid === pid && g.by === PERSONAS[p].name && g.id === 'EA-13' && !isLive(g) && !g.ended) : undefined;
}

export interface DoseRow {
    order: Order;
    time: string | null;
    state: 'given' | 'due' | 'later' | 'notRecorded' | 'prn';
    by?: string;
    at?: string;
    note?: string;
}
const addMin = (hm: string, n: number) => {
    const t = Number(hm.slice(0, 2)) * 60 + Number(hm.slice(3, 5)) + n;
    return `${String(Math.floor(t / 60)).padStart(2, '0')}:${String(t % 60).padStart(2, '0')}`;
};
export function todayRows(rt: Runtime, pid: PersonId): DoseRow[] {
    const log = eventLog();
    const rows: DoseRow[] = [];
    for (const o of ORDERS.filter((x) => x.pid === pid && !x.to)) {
        if (o.prn) {
            rows.push({ order: o, time: null, state: 'prn' });
            continue;
        }
        for (const t of o.times) {
            const rec = rt.recorded[`${o.id}|${t}`];
            const ev = log.find((e) => e.id === `E-${o.id}-${TODAY_ISO}-${t}`);
            if (rec) rows.push({ order: o, time: t, state: 'given', by: rec.by, at: rec.hm, note: rec.grant ? `under emergency access ${rec.grant}` : rec.outcome === 'queued' ? 'saved on this device — sent when you’re back' : undefined });
            else if (ev) rows.push({ order: o, time: t, state: 'given', by: ev.by, at: ev.hm, note: ev.detail.includes('emergency access') ? 'under emergency access EA-13' : ev.detail.includes('given at') ? ev.detail.split(' — ')[0].replace('9:00 am dose, ', '') : undefined });
            else if (addMin(t, 60) <= NOW_HM) rows.push({ order: o, time: t, state: 'notRecorded' });
            else if (addMin(t, -30) <= NOW_HM) rows.push({ order: o, time: t, state: 'due' });
            else rows.push({ order: o, time: t, state: 'later' });
        }
    }
    return rows.sort((a, b) => (a.time ?? '99').localeCompare(b.time ?? '99'));
}

export function MarFrame() {
    const s = useStore();
    const r = s.route;
    const p = r.persona;
    const scn = r.scenario;
    const open = useOpen();
    const ctx = useCtx(Pill);
    const pid = (r.q.get('person') ?? 'aroha') as PersonId;
    const person = PEOPLE[pid];
    if (!person || !canViewChart(p, pid))
        return (
            <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication' }, { title: 'MAR & medicines' }]}>
                <Card className="items-center gap-3 p-10 text-center">
                    <LockKeyhole className="size-8 text-muted-foreground" aria-hidden="true" />
                    <h1 className="text-section-title">We can’t show this record</h1>
                    <p className="text-subtle">It may not exist, or it may not be available to you. Check the link, or go back.</p>
                </Card>
            </Shell>
        );
    const grant = myLiveGrant(s.rt, scn, p, pid);
    const ranOut = myEndedGrant(s.rt, scn, p, pid);
    const canRecord = covers(p, pid) || !!grant || !!ranOut;
    const rows = scn === 'empty' ? [] : todayRows(s.rt, pid);
    const oncall = ONCALL[person.house];
    const RAIL: { key: string; label: string; icon: LucideIcon }[] = [
        { key: 'today', label: 'Today', icon: Clock3 },
        { key: 'medicines', label: 'Medicines', icon: Pill },
        { key: 'chart', label: 'MAR chart', icon: CalendarDays },
        { key: 'history', label: 'History', icon: History },
    ];
    const recordable = (d: DoseRow) => d.state === 'due' && canRecord && (!d.order.cd || has(p, 'cd.view'));
    const menu = (d: DoseRow): MenuItem[] =>
        compactMenu([
            recordable(d) && { label: 'Record this dose', icon: Pill, onClick: () => open(`record:${pid}:${d.order.id}`) },
            { label: 'Open the dose history', icon: History, onClick: () => s.toast('info', `Opens ${person.pref}’s ${d.order.med} history (P02) — outside this preview.`) },
        ]);
    const badge = (d: DoseRow) =>
        d.state === 'given' ? (
            <StatusBadge variant="neutral" className="rounded-[8px]">Given</StatusBadge>
        ) : d.state === 'due' ? (
            <StatusBadge variant="warning" className="rounded-[8px]">Due now</StatusBadge>
        ) : d.state === 'notRecorded' ? (
            <StatusBadge variant="critical" className="rounded-[8px]">Not recorded</StatusBadge>
        ) : d.state === 'prn' ? (
            <StatusBadge variant="info" className="rounded-[8px]">As needed</StatusBadge>
        ) : (
            <StatusBadge variant="neutral" className="rounded-[8px]">Later today</StatusBadge>
        );
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Clients', href: hrefFor('/outside/clients', {}, r) }, { title: person.legal }, { title: 'MAR & medicines' }, { title: 'Today' }]}>
            <PageHeader
                icon={UserRound}
                title={person.legal}
                titleChip={<PageHeaderStatusChip variant="neutral">{HOUSES[person.house]}</PageHeaderStatusChip>}
                subline="MAR & medicines · Today · Monday 28 September · NZ time"
                filters={
                    <PageHeaderFilterButton icon={Clock3} onClick={() => s.toast('success', 'Up to date · as at 9:12 am · Pacific/Auckland')}>
                        As at 9:12 am · NZDT
                    </PageHeaderFilterButton>
                }
                rail={<PageHeaderRail items={RAIL} value="today" onSelect={(k) => k !== 'today' && s.toast('info', 'That view is P02’s — outside this preview.')} ariaLabel="MAR & medicines views" />}
            />
            {grant ? <LiveStrip g={grant} onExtend={() => open(`extend:${grant.id}`)} onDone={() => open(`done:${grant.id}`)} /> : null}
            {ranOut ? (
                <Notice tone="critical" icon={KeyRound} title={`Your emergency access for ${person.pref} ended at 9:10 am`}>
                    Anything you hadn’t saved wasn’t recorded. {canRequest(p) ? 'Start it again if you still need to record for ' + person.pref + ', or ask someone on shift.' : ''}
                </Notice>
            ) : null}
            {!canRecord ? (
                canRequest(p) ? (
                    <Notice
                        tone="neutral"
                        icon={KeyRound}
                        title={`You’re not on a shift for ${person.pref}`}
                        actions={
                            <Button size="sm" onClick={() => open(`request:${pid}`)} data-return={`request-${pid}`}>
                                <KeyRound className="size-4" /> Start emergency access
                            </Button>
                        }
                    >
                        To record for {person.pref} now, start emergency access. It covers {person.pref} only, and {HOUSES[person.house]}’s reviewers are told.
                    </Notice>
                ) : (
                    <Notice tone="neutral" icon={Phone} title={`You’re not on a shift for ${person.pref}`}>
                        {oncall ? `Clock in, or call the on-call contact for ${HOUSES[person.house]}: ${oncall.name} — ${oncall.phone}.` : `Clock in, or call your manager — ${HOUSES[person.house]} has no on-call contact set.`}
                    </Notice>
                )
            ) : null}
            <section className="flex flex-col gap-2.5" aria-label="Today’s doses">
                <ListCaption title="Today’s doses" caption={rows.length ? `${rows.length} · NZ time` : 'None'} />
                {rows.length ? (
                    <EntityTable<DoseRow>
                        rows={rows}
                        rowKey={(d) => `${d.order.id}${d.time}`}
                        rowHeight="content"
                        minWidth={900}
                        identityLabel="Medicine"
                        identityWidth="1.6fr"
                        identity={(d) => ({ icon: d.order.cd ? LockKeyhole : Pill, name: d.order.cd && !has(p, 'cd.view') ? 'Controlled medicine' : `${d.order.med} ${d.order.strength}`, subline: <Wrap>{d.order.cd ? 'Controlled — needs a witness' : d.order.prn ? 'As needed' : secondPersonFor(d.order.id) === 'rule' ? 'Scheduled · a second person and a reading — medication rules' : 'Scheduled'}</Wrap> })}
                        columns={[
                            { key: 'due', label: 'Due', width: '0.7fr', cell: (d) => <span className="text-[12.5px]">{d.time ? time12(d.time) : '—'}</span> },
                            {
                                key: 'st',
                                label: 'Today',
                                width: '1.6fr',
                                cell: (d) => (
                                    <span className="flex flex-col items-start gap-1 py-1">
                                        {badge(d)}
                                        {d.state === 'given' ? <StateLine>{`${time12(d.at!)} · ${d.by}${d.note ? ` · ${d.note}` : ''}`}</StateLine> : d.state === 'due' ? <StateLine>{`Window ends ${time12(addMin(d.time!, 60))}`}</StateLine> : null}
                                    </span>
                                ),
                            },
                            {
                                key: 'act',
                                label: '',
                                width: '120px',
                                align: 'right',
                                cell: (d) =>
                                    recordable(d) ? (
                                        <Button size="sm" variant="outline" className="frontline-tap" data-return={`rec-${d.order.id}`} onClick={(e) => (e.stopPropagation(), open(`record:${pid}:${d.order.id}`))}>
                                            Record
                                        </Button>
                                    ) : null,
                            },
                        ]}
                        actionsFor={menu}
                        onOpen={(d) => (recordable(d) ? open(`record:${pid}:${d.order.id}`) : s.toast('info', `Opens ${person.pref}’s ${d.order.med} history (P02) — outside this preview.`))}
                        onRowContextMenu={(e, d) => ctx.openAt(e, `${d.order.med} · ${d.time ? time12(d.time) : 'as needed'}`, menu(d))}
                    />
                ) : (
                    <Card className="p-4 text-center text-sm text-muted-foreground">No doses today.</Card>
                )}
                {ctx.node}
            </section>
            <Card className="flex-row flex-wrap items-center justify-between gap-3 p-4">
                <span className="flex items-start gap-3">
                    <ClipboardList className="mt-0.5 size-4 text-muted-foreground" aria-hidden="true" />
                    <span>
                        <span className="block text-sm font-semibold">The rest of {person.pref}’s record</span>
                        <span className="text-caption block">Medicines, the MAR chart and the history are P02’s approved views.</span>
                    </span>
                </span>
            </Card>
            <DesignNote title="Design note — a frame of P02’s Today view (Main, Q3, Q5)">
                <p>Today the person’s MAR shows no sign of emergency access, a save after it ends fails with a generic message, and the only contextual entry is the redirect from /clients/{'{id}'}/mar. Here the person using it sees the strip on the chart and in the record dialog; someone who holds emergency access but isn’t on a shift gets “Start emergency access” in the blocked state; everyone else is told the real route, and never about emergency access.</p>
            </DesignNote>
        </Shell>
    );
}
