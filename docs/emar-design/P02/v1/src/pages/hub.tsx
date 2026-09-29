/* The MAR & medicines hub (plan §2.2 hub 2, §4.1) — cross-person only; every row
 * opens the canonical person record. Views keep today's URLs: MAR charts
 * (/emar/mar with no person), Medicines (/emar/medications), As-needed history
 * (/emar/prn); Support & self-administration (/emar/self-admin) is P03's and a
 * link-only boundary here. There is no second people directory: the board lists
 * people only because they have medication work, and each row leads into the
 * person's record. House scope and controlled-medicine concealment as the record. */
import { CounterPill, PersonCell, ProgressValue } from '@/components/lists/entity-cells';
import { EntityContextMenu, compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { AlertTriangle, ArrowUpRight, ClipboardList, Clock3, FileText, History, Home, Info, Pill, ScrollText, Users, type LucideIcon } from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';
import { FACTS, HISTORY, HOUSES, MEDICINES, P02_PERSONAS, RECORD_PEOPLE, medByKey, pageState, recorderIn01, type House, type Medicine, type PersonId } from '../data';
import { todayCells, toMin, type TodayCell } from '../model';
import { BLOCKS, fill, requirementsFor } from '../p01/contract';
import { FOLLOW_UPS, PEOPLE, PRN } from '../p01/data';
import { useOpen } from '../p01/doses';
import { useStore as useStore01 } from '../p01/store';
import { DesignNote, Notice, SupportChip } from '../p01/ui';
import { Shell } from '../shell';
import { hrefFor, useP02 } from '../store';
import { ConcealedCaption, Wrap, allergyShort } from '../ui';
import { NoAccess } from './record';

export type HubView = 'charts' | 'medicines' | 'asneeded' | 'selfadmin';
export const HUB_PATH: Record<HubView, string> = { charts: '/emar/mar', medicines: '/emar/medications', asneeded: '/emar/prn', selfadmin: '/emar/self-admin' };
const RAIL: { key: HubView; label: string; icon: LucideIcon }[] = [
    { key: 'charts', label: 'MAR charts', icon: ClipboardList },
    { key: 'medicines', label: 'Medicines', icon: Pill },
    { key: 'asneeded', label: 'As-needed history', icon: History },
    { key: 'selfadmin', label: 'Support & self-administration', icon: Users },
];

/* ───────────── per-person day (pure; the record's own payload) ───────────── */
interface Row {
    pid: PersonId;
    house: House;
    cells: TodayCell[];
    due: number;
    late: number;
    help: { n: number; reason: string | null };
    recorded: number;
    soFar: number;
    next: TodayCell | null;
    hidden: number;
}
function rowFor(pid: PersonId, s01: ReturnType<typeof useStore01>, cdView: boolean): Row {
    const all = todayCells(pid, s01);
    const cells = all.filter((c) => cdView || !c.med.cd);
    const open = cells.filter((c) => c.state === 'due' || c.state === 'late');
    const blocked = open.filter((c) => c.dose && (requirementsFor(c.dose, s01.ctx).blockAll || requirementsFor(c.dose, s01.ctx).blockGiven));
    const firstBlock = blocked[0]?.dose ? requirementsFor(blocked[0].dose, s01.ctx) : null;
    const key = firstBlock ? (firstBlock.blockAll ?? firstBlock.blockGiven)! : null;
    const soFar = cells.filter((c) => !['notdue', 'selfmanaged'].includes(c.state));
    const next = cells.filter((c) => c.state === 'notdue').sort((a, b) => toMin(a.slot) - toMin(b.slot))[0] ?? null;
    return {
        pid,
        house: FACTS[pid].house,
        cells,
        due: cells.filter((c) => c.state === 'due').length,
        late: cells.filter((c) => c.state === 'late').length,
        help: { n: blocked.length, reason: key ? fill(BLOCKS[key].title, PEOPLE[pid].pref, blocked[0].med.name) : null },
        recorded: soFar.filter((c) => c.rec).length,
        soFar: soFar.length,
        next,
        hidden: all.length - cells.length,
    };
}

function useCtx() {
    const [ctx, setCtx] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const openAt = (e: MouseEvent, title: string, items: MenuItem[]) => {
        e.preventDefault();
        const b = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const kb = e.clientX === 0 && e.clientY === 0;
        setCtx({ x: kb ? b.left + 12 : e.clientX, y: kb ? b.top + 12 : e.clientY, title, items });
    };
    return { openAt, node: ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={Pill} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null };
}

/* ───────────── as-needed history rows (P02 history + P01 orders' last 24 hours) ───────────── */
interface PrnRow {
    id: string;
    pid: PersonId;
    med: string;
    day: string;
    at: string;
    amount: string;
    reason: string;
    by: string;
    effect: { tone: 'success' | 'warning' | 'critical' | 'neutral'; text: string };
    adminId?: string;
    cd?: boolean;
    within24: boolean;
}
function prnRows(s01: ReturnType<typeof useStore01>): PrnRow[] {
    const rows: PrnRow[] = HISTORY.filter((a) => a.prn).map((a) => {
        const m = medByKey(a.med);
        const [, why = 'For pain', effect = ''] = /^(For [^·]+)·\s*effect check:\s*(.*)$/.exec(a.note ?? '') ?? [];
        return { id: a.id, pid: a.pid, med: `${m.name} ${m.strength.replace(' tablet', '')}`, day: a.dayLabel, at: a.at, amount: a.amount ?? '—', reason: (/^(For [^·]+)/.exec(a.note ?? '')?.[1] ?? why).trim(), by: a.by, effect: { tone: 'success', text: `Recorded: ${effect.replace(/[“”]/g, '')}` }, adminId: a.id, within24: a.day === '2026-09-27' };
    });
    for (const o of PRN.filter((x) => x.pid !== 'aroha')) {
        o.last24h.forEach((g, i) => {
            const overdue = FOLLOW_UPS.find((f) => f.state === 'overdue' && f.title.includes(PEOPLE[o.pid].pref) && f.title.toLowerCase().includes(o.med.toLowerCase()));
            rows.push({
                id: `${o.id}-${i}`,
                pid: o.pid as PersonId,
                med: `${o.med} ${o.strength.replace(' tablet', '')}`,
                day: /Sunday/.test(g.at) ? 'Sun 27' : 'Today',
                at: g.at.replace(' Sunday', ''),
                amount: g.amount,
                reason: o.reasons[0],
                by: g.by,
                effect: overdue ? { tone: 'critical', text: `Effect check overdue — was due ${overdue.due.replace('Was due ', '')}` } : i === 0 ? { tone: 'warning', text: `Effect check due by 10:05 am (set by ${g.by})` } : { tone: 'success', text: 'Recorded: pain eased' },
                cd: o.cd,
                within24: true,
            });
        });
    }
    for (const r of s01.prnRecords) {
        const o = PRN.find((x) => x.id === r.orderId)!;
        rows.unshift({ id: r.id, pid: o.pid as PersonId, med: `${o.med} ${o.strength.replace(' tablet', '')}`, day: 'Today', at: r.at, amount: r.amount, reason: r.reason, by: r.by, effect: { tone: 'warning', text: `Effect check due by ${r.checkBy}` }, cd: o.cd, within24: true });
    }
    const dayOrder = (d: string) => (d === 'Today' ? 9 : Number(d.split(' ')[1]));
    return rows.sort((a, b) => dayOrder(b.day) - dayOrder(a.day) || toMin(b.at) - toMin(a.at));
}

/* ───────────── the page ───────────── */
export function HubPage({ view }: { view: HubView }) {
    const s = useP02();
    if (!s.can('view')) return <NoAccess />;
    return <Hub view={view} />;
}

function Hub({ view }: { view: HubView }) {
    const s = useP02();
    const s01 = useStore01();
    const open01 = useOpen();
    const me = P02_PERSONAS[s.route.persona];
    const [search, setSearch] = useState('');
    const state = s.route.state;
    const houseParam = s.route.q.get('house') as House | null;
    const houses = me.houses.filter((h) => !houseParam || h === houseParam);
    const people = RECORD_PEOPLE.filter((p) => houses.includes(FACTS[p].house));
    const rows = people.map((p) => rowFor(p, s01, s.cdView));
    const empty = state === 'empty';
    const loading = state === 'loading';
    const failed = state === 'unavailable';
    const dash = loading ? '—' : failed ? 'Unavailable' : null;
    const sum = (k: (r: Row) => number) => rows.reduce((a, r) => a + k(r), 0);
    const due = empty ? 0 : sum((r) => r.due);
    const late = empty ? 0 : sum((r) => r.late);
    const help = empty ? 0 : sum((r) => r.help.n);
    const recorded = sum((r) => r.recorded);
    const soFar = sum((r) => r.soFar);
    const meds = MEDICINES.filter((m) => people.includes(m.pid) && m.status !== 'stopped' && (!m.scenario || m.scenario === pageState()));
    const medsShown = meds.filter((m) => s.cdView || !m.cd);
    const prn = prnRows(s01).filter((r) => people.includes(r.pid) && (s.cdView || !r.cd));
    const prn24 = prn.filter((r) => r.within24);
    const go = (v: HubView, params: Record<string, string | undefined> = {}) => s.go(HUB_PATH[v], { house: houseParam ?? undefined, ...params });
    const houseText = me.houses.length > 1 && !houseParam ? `${HOUSES[me.houses[0]]} and ${HOUSES[me.houses[1]]}` : HOUSES[houses[0]];
    const oldest = rows.flatMap((r) => r.cells.filter((c) => c.state === 'late')).sort((a, b) => toMin(a.slot) - toMin(b.slot))[0];

    const asAt =
        state === 'stale' ? (
            <PageHeaderFilterButton icon={AlertTriangle} active onClick={() => s.toast('info', 'Refreshing… (mockup: set the page state back to Normal).')}>
                As at 8:40 am · couldn’t refresh — try again
            </PageHeaderFilterButton>
        ) : (
            <PageHeaderFilterButton icon={Clock3} onClick={() => s.toast('success', 'Up to date · as at 9:12 am · Pacific/Auckland')}>
                As at 9:12 am · Pacific/Auckland
            </PageHeaderFilterButton>
        );
    const houseFilter =
        me.houses.length > 1 ? (
            <PageHeaderFilterSelect icon={Home} label="House" value={houseParam ?? 'all'} allValue="all" onChange={(v) => s.set({ house: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All your houses' }, ...me.houses.map((h) => ({ value: h, label: HOUSES[h] }))]} />
        ) : null;
    const filters: Record<HubView, ReactNode> = {
        charts: (
            <>
                {houseFilter}
                <PageHeaderFilterSelect label="Show" value={s.route.q.get('show') ?? 'all'} allValue="all" onChange={(v) => s.set({ show: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'Everyone with doses' }, { value: 'due', label: 'Due now' }, { value: 'late', label: 'Late' }, { value: 'help', label: 'Needs help' }]} />
                {asAt}
            </>
        ),
        medicines: (
            <>
                {houseFilter}
                <PageHeaderFilterSelect icon={Pill} label="Type" value={s.route.q.get('type') ?? 'all'} allValue="all" onChange={(v) => s.set({ type: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All types' }, { value: 'scheduled', label: 'Scheduled' }, { value: 'prn', label: 'As needed' }]} />
                <PageHeaderFilterSelect label="Order" value={s.route.q.get('status') ?? 'current'} onChange={(v) => s.set({ status: v === 'current' ? undefined : v })} options={[{ value: 'current', label: 'Current' }, { value: 'awaiting', label: 'Waiting to be checked' }, { value: 'stopped', label: 'Stopped' }]} />
                {asAt}
            </>
        ),
        asneeded: (
            <>
                {houseFilter}
                <PageHeaderFilterSelect label="Range" value={s.route.q.get('range') ?? '24h'} onChange={(v) => s.set({ range: v === '24h' ? undefined : v })} options={[{ value: '24h', label: 'Last 24 hours' }, { value: '7d', label: 'Last 7 days' }]} />
                {asAt}
            </>
        ),
        selfadmin: (
            <>
                {houseFilter}
                {asAt}
            </>
        ),
    };

    const header = (
        <PageHeader
            variant="index"
            icon={ClipboardList}
            title="MAR & medicines"
            titleChip={<PageHeaderStatusChip variant="neutral">{empty ? 0 : people.length} {!empty && people.length === 1 ? 'person' : 'people'}</PageHeaderStatusChip>}
            subline={`Charts and medicines for ${houseText} · times in NZDT`}
            actions={
                <>
                    <PageHeaderSearch value={search} onChange={setSearch} placeholder="Search people or medicines…" />
                    {recorderIn01(s.route.persona) && !empty ? (
                        <PageHeaderPrimaryButton icon={Pill} onClick={() => open01('prn-pick')}>
                            Record as-needed dose
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock label="Due now" onClick={() => go('charts', { show: 'due' })} ariaLabel="View people with doses due now">
                        <PageHeaderMeterBig>{dash ?? due}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : due ? (() => { const n = rows.filter((r) => r.due).length; return `${n} ${n === 1 ? 'person' : 'people'} · until 10:00 am`; })() : 'Nothing due right now'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Late" tone={!dash && late ? 'warning' : 'brand'} onClick={() => go('charts', { show: 'late' })} ariaLabel="View people with late doses">
                        <PageHeaderMeterBig>{dash ?? late}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : late && oldest ? `Oldest due ${oldest.slot}` : 'None late'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Needs help" tone={!dash && help ? 'critical' : 'brand'} onClick={() => go('charts', { show: 'help' })} ariaLabel="View doses that can’t be recorded yet">
                        <PageHeaderMeterBig>{dash ?? help}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : help ? 'Blocked — see why' : 'Nothing blocked'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Recorded today" value={dash || empty || !soFar ? undefined : `${recorded}/${soFar}`} onClick={() => go('charts')} ariaLabel="View today’s charts">
                        {dash || empty || !soFar ? <PageHeaderMeterBig>{dash ?? 'n/a'}</PageHeaderMeterBig> : <PageHeaderMeterDonut percent={Math.round((recorded / soFar) * 100)} caption="of doses due so far" />}
                        {dash || empty || !soFar ? <PageHeaderMeterCaption>{dash ? '—' : 'No doses due yet'}</PageHeaderMeterCaption> : null}
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Medicines" onClick={() => go('medicines')} ariaLabel="View medicines">
                        <PageHeaderMeterBig>{dash ?? (empty ? 0 : medsShown.length)}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : meds.length - medsShown.length ? `+${meds.length - medsShown.length} controlled hidden` : (() => { const n = meds.filter((m) => m.status === 'awaiting').length; return n ? `${n} ${n === 1 ? 'order' : 'orders'} to check` : 'All checked'; })()}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="As needed · 24 h" onClick={() => go('asneeded')} ariaLabel="View as-needed history">
                        <PageHeaderMeterBig>{dash ?? (empty ? 0 : prn24.length)}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : (() => { const n = prn24.filter((r) => r.effect.tone === 'critical').length; return n ? `${n} effect ${n === 1 ? 'check' : 'checks'} overdue` : 'No effect checks overdue'; })()}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={filters[view]}
            rail={<PageHeaderRail<HubView> items={RAIL} value={view} onSelect={(k) => go(k)} ariaLabel="MAR & medicines views" />}
        />
    );

    let body: ReactNode;
    if (loading)
        body = (
            <Card className="p-4">
                <SkeletonTable rows={6} columns={6} />
            </Card>
        );
    else if (failed)
        body = (
            <Card className="p-2">
                <ErrorState title="We couldn’t load medication for your houses" message="Nothing is shown rather than incomplete charts. Try again — if it keeps failing, use the printed MAR for the next round and tell the house lead." onRetry={() => s.setViewer({ state: 'normal' })} />
            </Card>
        );
    else if (view === 'charts') body = <Board rows={rows} search={search} empty={empty} />;
    else if (view === 'medicines') body = <Medicines meds={MEDICINES.filter((m) => people.includes(m.pid) && (!m.scenario || m.scenario === pageState()))} search={search} empty={empty} />;
    else if (view === 'asneeded') body = <AsNeededHistory rows={prn} search={search} empty={empty} />;
    else body = <SelfAdminBoundary />;

    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/emar/mar' }, { title: 'MAR & medicines' }]}>
            {header}
            {state === 'stale' ? (
                <Notice tone="warning" icon={AlertTriangle} title="This may be out of date">
                    We couldn’t refresh at 9:12 am; it shows what was known at 8:40 am. Check Meds today before recording, and try again.
                </Notice>
            ) : null}
            {body}
        </Shell>
    );
}

const personMatch = (pid: string, q: string) => !q || `${PEOPLE[pid].pref} ${PEOPLE[pid].legal}`.toLowerCase().includes(q.toLowerCase());

/* ───────────── MAR charts — the board by house ───────────── */
function Board({ rows, search, empty }: { rows: Row[]; search: string; empty: boolean }) {
    const s = useP02();
    const ctx = useCtx();
    const open01 = useOpen();
    const show = s.route.q.get('show');
    const record = (pid: string, params: Record<string, string | undefined> = {}) => s.go('/emar/mar', { client_id: String(FACTS[pid as PersonId].clientId), house: undefined, show: undefined, ...params });
    const menu = (r: Row): MenuItem[] =>
        compactMenu([
            { label: `Open ${PEOPLE[r.pid].pref}’s medication record`, icon: ClipboardList, onClick: () => record(r.pid) },
            (r.due > 0 || r.late > 0) && !!recorderIn01(s.route.persona) && { label: 'Record a dose', icon: Pill, onClick: () => open01(`dose-pick:${r.pid}`) },
            r.help.n > 0 && { label: 'Why can’t these be recorded?', icon: Info, onClick: () => s.toast('info', `${r.help.reason}. Open the record to see who can unblock it.`) },
            { separator: true },
            { label: 'Open the client profile', icon: Users, onClick: () => s.go(`/operations/clients/${FACTS[r.pid].clientId}`, { tab: 'mar', house: undefined, show: undefined }) },
            s.can('reports.export') && { label: 'Print MAR', icon: FileText, onClick: () => record(r.pid, { dlg: 'print' }) },
        ]);
    if (empty)
        return (
            <Card className="p-2">
                <EmptyState icon={ClipboardList} title="No medicines on anyone’s chart at your houses" description="Charts appear here once orders are added and checked in Orders & reviews." />
            </Card>
        );
    const houses = [...new Set(rows.map((r) => r.house))];
    return (
        <>
            {houses.map((h) => {
                const inHouse = rows.filter((r) => r.house === h && r.cells.length);
                const hiddenHere = rows.filter((r) => r.house === h).reduce((a, r) => a + r.hidden, 0);
                const shown = inHouse.filter((r) => personMatch(r.pid, search) && (!show || (show === 'due' ? r.due : show === 'late' ? r.late : r.help.n) > 0));
                return (
                    <section key={h} aria-label={HOUSES[h]} className="flex flex-col gap-2.5">
                        <ListCaption
                            title={HOUSES[h]}
                            caption={
                                <>
                                    {shown.length} of {inHouse.length} {inHouse.length === 1 ? 'person' : 'people'} shown · today, Monday 28 September{hiddenHere ? ' · ' : ''}
                                    <ConcealedCaption n={hiddenHere} listed={false} />
                                </>
                            }
                        />
                        {shown.length ? (
                            <EntityTable<Row>
                                rows={shown}
                                rowKey={(r) => r.pid}
                                identityLabel="Person"
                                identityWidth="1.5fr"
                                rowHeight="content"
                                minWidth={1100}
                                identity={(r) => ({ mark: <PersonCellMark pid={r.pid} />, name: `${PEOPLE[r.pid].pref} ${PEOPLE[r.pid].surname}`, subline: FACTS[r.pid].moved ? <Wrap>Moved here from {HOUSES[FACTS[r.pid].moved!.from]} today</Wrap> : `${r.cells.length} dose ${r.cells.length === 1 ? 'time' : 'times'} today` })}
                                columns={[
                                    { key: 'due', label: 'Due now', width: '0.7fr', cell: (r) => (r.due ? <CounterPill tone="neutral">{r.due}</CounterPill> : <span className="text-muted-foreground">—</span>) },
                                    { key: 'late', label: 'Late', width: '0.6fr', cell: (r) => (r.late ? <CounterPill tone="warning">{r.late}</CounterPill> : <span className="text-muted-foreground">—</span>) },
                                    { key: 'help', label: 'Needs help', width: '1.7fr', cell: (r) => (r.help.n ? <span className="flex items-center gap-2 text-[12.5px]"><CounterPill tone="critical">{r.help.n}</CounterPill><span className="whitespace-normal">{r.help.reason}</span></span> : <span className="text-muted-foreground">—</span>) },
                                    { key: 'rec', label: 'Recorded today', width: '1.1fr', cell: (r) => (r.soFar ? <ProgressValue percent={Math.round((r.recorded / r.soFar) * 100)} tone={r.recorded === r.soFar ? 'success' : 'brand'}>{r.recorded} of {r.soFar}</ProgressValue> : <span className="text-[12.5px] text-muted-foreground">n/a — none due yet</span>) },
                                    { key: 'next', label: 'Next due', width: '1.1fr', cell: (r) => <span className="text-[12.5px]">{r.next ? `${r.next.slot} · ${r.next.med.name}` : '—'}</span> },
                                    { key: 'al', label: 'Allergies', width: '1fr', cell: (r) => <AllergyChip pid={r.pid} /> },
                                ]}
                                actionsFor={menu}
                                onOpen={(r) => record(r.pid)}
                                onRowContextMenu={(e, r) => ctx.openAt(e, `${PEOPLE[r.pid].pref} ${PEOPLE[r.pid].surname}`, menu(r))}
                            />
                        ) : (
                            <Card className="p-2">
                                <EmptyState variant="compact" icon={ClipboardList} title={show ? 'Nobody matches this filter' : 'Nobody matches the search'} description="Clear the Show filter or the search in the header." />
                            </Card>
                        )}
                    </section>
                );
            })}
            {ctx.node}
            <DesignNote title="Design note — the MAR charts board">
                <p>Cross-person only: one row per person with medication work today, counted from the same schedule as Meds today and the record, and every row opens the person’s record (plan §4.1 — there is no second people directory). House scope follows your approved houses; controlled doses are left out of the counts for people without controlled-medicine access, and the caption says so.</p>
            </DesignNote>
        </>
    );
}
function PersonCellMark({ pid }: { pid: string }) {
    return <span className="grid size-[30px] place-items-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">{PEOPLE[pid].initials}</span>;
}
function AllergyChip({ pid }: { pid: PersonId }) {
    const s = useP02();
    const f = FACTS[pid];
    const live = s.reviewed[pid];
    const status = f.allergy === 'nkda' || live?.nkda ? 'nkda' : f.allergy;
    const reviewed = live ?? f.reviewed;
    const variant = status === 'recorded' ? 'critical' : status === 'nkda' ? 'neutral' : 'warning';
    return (
        <span className="flex flex-col items-start gap-0.5">
            <StatusBadge variant={variant} className="rounded-[8px]">
                {allergyShort(status, f.entries.length)}
            </StatusBadge>
            {status !== 'unavailable' && !reviewed ? <span className="text-[11.5px] font-semibold text-status-warning">Not reviewed</span> : null}
        </span>
    );
}

/* ───────────── Medicines — the cross-person list ───────────── */
function Medicines({ meds, search, empty }: { meds: Medicine[]; search: string; empty: boolean }) {
    const s = useP02();
    const ctx = useCtx();
    const type = s.route.q.get('type');
    const status = s.route.q.get('status') ?? 'current';
    const base = empty ? [] : meds.filter((m) => (status === 'current' ? m.status !== 'stopped' : m.status === status) && (!type || m.kind === type));
    const hidden = s.cdView ? 0 : base.filter((m) => m.cd).length;
    const rows = base.filter((m) => (s.cdView || !m.cd) && (personMatch(m.pid, search) || `${m.name}`.toLowerCase().includes(search.toLowerCase())));
    const open = (m: Medicine, dlg?: string) => s.go('/emar/mar', { client_id: String(FACTS[m.pid].clientId), tab: 'medicines', view: m.status === 'stopped' ? 'stopped' : undefined, dlg, house: undefined, type: undefined, status: undefined });
    const menu = (m: Medicine): MenuItem[] =>
        compactMenu([
            { label: 'View medicine details', icon: Info, onClick: () => open(m, `med:${m.key}`) },
            { label: `Open ${PEOPLE[m.pid].pref}’s medication record`, icon: ClipboardList, onClick: () => open(m) },
            { separator: true },
            s.can('orders.manage') && { label: 'Change or stop in Orders & reviews', icon: ScrollText, onClick: () => s.go('/emar/prescriptions', { client_id: String(FACTS[m.pid].clientId) }) },
        ]);
    return (
        <section aria-label="Medicines" className="flex flex-col gap-2.5">
            <ListCaption
                title={status === 'stopped' ? 'Stopped medicines' : status === 'awaiting' ? 'Orders waiting to be checked' : 'Current medicines'}
                caption={
                    <>
                        {rows.length} of {base.length - hidden} shown{hidden ? ' · ' : ''}
                        <ConcealedCaption n={hidden} listed={false} />
                    </>
                }
                right={
                    s.can('orders.manage') ? (
                        <Button size="sm" variant="link" onClick={() => s.go('/emar/prescriptions')}>
                            Add or change an order <ArrowUpRight className="size-4" aria-hidden="true" />
                        </Button>
                    ) : null
                }
            />
            {rows.length ? (
                <EntityTable<Medicine>
                    rows={rows}
                    rowKey={(m) => m.key}
                    identityLabel="Medicine"
                    identityWidth="2.1fr"
                    rowHeight="content"
                    minWidth={1100}
                    identity={(m) => ({ icon: Pill, name: `${m.name} ${m.strength}`, subline: `${m.amount.length > 40 ? 'Dose by INR' : m.amount} · ${m.route}` })}
                    columns={[
                        { key: 'who', label: 'Person', width: '1.2fr', cell: (m) => <PersonCell name={`${PEOPLE[m.pid].pref} ${PEOPLE[m.pid].surname}`} /> },
                        { key: 'house', label: 'House', width: '0.9fr', cell: (m) => <span className="text-[12.5px]">{HOUSES[FACTS[m.pid].house]}</span> },
                        { key: 'when', label: 'When', width: '1fr', cell: (m) => <span className="text-[12.5px]">{m.kind === 'prn' ? 'As needed' : m.when}</span> },
                        { key: 'sup', label: 'Support', width: '0.9fr', cell: (m) => <SupportChip support={m.support} /> },
                        { key: 'ord', label: 'Order', width: '1.3fr', cell: (m) => (m.status === 'awaiting' ? <StatusBadge variant="warning" className="rounded-[8px]">Waiting to be checked</StatusBadge> : m.status === 'stopped' ? <span className="text-[12px]">Stopped {m.stopped!.on}</span> : <span className="text-[12px]">{m.order}</span>) },
                    ]}
                    actionsFor={menu}
                    onOpen={(m) => open(m, `med:${m.key}`)}
                    onRowContextMenu={(e, m) => ctx.openAt(e, `${m.name} · ${PEOPLE[m.pid].pref}`, menu(m))}
                    mutedFor={(m) => m.status === 'stopped'}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={Pill} title={empty ? 'No medicines at your houses' : 'No medicines match'} description={empty ? 'Orders are added and checked in Orders & reviews.' : 'Clear the filters or the search in the header.'} />
                </Card>
            )}
            {ctx.node}
            <p className="text-caption">Each medicine opens in the person’s record. Controlled medicines are listed only for people with controlled-medicine access.</p>
        </section>
    );
}

/* ───────────── As-needed history ───────────── */
function AsNeededHistory({ rows, search, empty }: { rows: PrnRow[]; search: string; empty: boolean }) {
    const s = useP02();
    const ctx = useCtx();
    const range = s.route.q.get('range') ?? '24h';
    const inRange = empty ? [] : rows.filter((r) => range === '7d' || r.within24);
    const shown = inRange.filter((r) => personMatch(r.pid, search) || r.med.toLowerCase().includes(search.toLowerCase()));
    const open = (r: PrnRow) => s.go('/emar/mar', { client_id: String(FACTS[r.pid].clientId), tab: 'history', dlg: r.adminId ? `dose:${r.adminId}` : undefined, house: undefined, range: undefined });
    const menu = (r: PrnRow): MenuItem[] =>
        compactMenu([
            { label: r.adminId ? 'Open the dose record' : `Open ${PEOPLE[r.pid].pref}’s history`, icon: ClipboardList, onClick: () => open(r) },
            r.effect.tone !== 'success' && { label: 'Record the effect check', icon: Info, onClick: () => s.toast('info', 'Effect checks are follow-ups — designed in P08a (Follow-ups & handover), outside this preview.') },
        ]);
    return (
        <section aria-label="As-needed history" className="flex flex-col gap-2.5">
            <ListCaption title="As-needed doses" caption={`${shown.length} of ${inRange.length} shown · ${range === '24h' ? 'last 24 hours' : 'last 7 days'} · newest first · times in NZDT`} />
            {shown.length ? (
                <EntityTable<PrnRow>
                    rows={shown}
                    rowKey={(r) => r.id}
                    identityLabel="Medicine"
                    identityWidth="1.4fr"
                    rowHeight="content"
                    minWidth={1100}
                    identity={(r) => ({ icon: Pill, name: r.med, subline: `${PEOPLE[r.pid].pref} ${PEOPLE[r.pid].surname} · ${HOUSES[FACTS[r.pid].house]}` })}
                    columns={[
                        { key: 'when', label: 'When', width: '0.9fr', cell: (r) => <span className="text-[12.5px]">{r.day} · {r.at}</span> },
                        { key: 'amt', label: 'Given', width: '1fr', cell: (r) => <span className="text-[12.5px]">{r.amount}</span> },
                        { key: 'why', label: 'For', width: '1fr', cell: (r) => <span className="text-[12.5px]">{r.reason}</span> },
                        { key: 'by', label: 'By', width: '0.9fr', cell: (r) => <span className="text-[12.5px]">{r.by}</span> },
                        { key: 'eff', label: 'Effect check', width: '1.6fr', cell: (r) => <StatusBadge variant={r.effect.tone} className="rounded-[8px] whitespace-normal">{r.effect.text}</StatusBadge> },
                    ]}
                    actionsFor={menu}
                    onOpen={open}
                    onRowContextMenu={(e, r) => ctx.openAt(e, `${r.med} · ${PEOPLE[r.pid].pref}`, menu(r))}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={History} title={empty ? 'No as-needed doses at your houses' : 'No as-needed doses match'} description={empty ? 'They appear here as they’re recorded.' : 'Widen the range or clear the search.'} />
                </Card>
            )}
            {ctx.node}
            <p className="text-caption">Controlled as-needed doses are listed only for people with controlled-medicine access. Effect checks are owned by Follow-ups (P08a).</p>
        </section>
    );
}

function SelfAdminBoundary() {
    const s = useP02();
    return (
        <Card className="gap-3 p-6">
            <h2 className="text-section-title">Support &amp; self-administration is designed in P03</h2>
            <p className="text-subtle max-w-[70ch]">The register of self-administration assessments, agreements and reassessments keeps its URL (/emar/self-admin) and its own design package. Each person’s support is already shown on their record’s Support plan tab.</p>
            <div>
                <Button variant="link" onClick={() => s.go('/emar/mar', { client_id: '201', tab: 'support', house: undefined })}>
                    See a Support plan tab <ArrowUpRight className="size-4" aria-hidden="true" />
                </Button>
            </div>
        </Card>
    );
}

