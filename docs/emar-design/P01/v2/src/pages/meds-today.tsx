/* Meds today (/meds/today) — the support worker's single sidebar entry.
 * Event Horizon PageHeader (not PageHero), honest meters incl. My eligibility,
 * the connected-tab rail, and EntityTable lists. Follow-ups, Controlled checks
 * and Stock alerts keep their rail places but are designed in P08a/P07a/P06. */
import { EntityChip, PersonDisc } from '@/components/lists/entity-cells';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    Clock3,
    Flag,
    ListChecks,
    LogIn,
    Package,
    Pill,
    Play,
    Plus,
    Printer,
    RefreshCw,
    Repeat,
    ShieldCheck,
    UserRound,
    WifiOff,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { labelOf } from '../clock';
import { requirementsFor } from '../contract';
import { ACTIVITY, PEOPLE, PRN, isFrontline, type Dose } from '../data';
import { DoseActionCell, DoseStateCell, MedicineCell, useDoseMenu, useOpen, useRowContext, useRowOpen } from '../doses';
import { Shell } from '../shell';
import { hrefFor, useCounts, useStore } from '../store';
import { DesignNote, Notice, NotConfigured, PersonMark } from '../ui';

type View = 'schedule' | 'rounds' | 'asneeded' | 'followups' | 'controlled' | 'stockalerts' | 'activity';

export function MedsTodayPage() {
    const s = useStore();
    const r = s.route;
    const view = (r.q.get('view') as View) || 'schedule';
    const c = useCounts();
    const [search, setSearch] = useState('');
    const scn = r.scenario;
    const loading = scn === 'loading';
    const unavailable = scn === 'unavailable';
    const empty = scn === 'empty';
    const setView = (v: View) => s.go('/meds/today', { view: v === 'schedule' ? undefined : v, state: undefined, page: undefined });
    const link = (v: View, extra: Record<string, string | undefined> = {}) => hrefFor('/meds/today', { view: v === 'schedule' ? undefined : v, ...extra }, r);
    const openFu = s.followUps.filter((f) => f.state !== 'done');
    const overdueFu = openFu.filter((f) => f.state === 'overdue').length;
    const notClocked = scn === 'notClockedIn' && !s.clockedIn;

    /* ── meters: one row, every block a link, honest n/a and Unavailable ── */
    const eligibility = (() => {
        if (scn === 'competencyExpired') return { big: 'Expired', cap: 'Ended 14 Sep 2026', tone: 'critical' as const };
        if (scn === 'restrictedBlock') return { big: 'Restricted', cap: 'Can’t sign doses as given', tone: 'critical' as const };
        return { big: 'Current', cap: 'To 14 Mar 2027', tone: 'success' as const };
    })();
    const blockedCaption = [...new Set(c.needsHelp.map(({ d }) => {
        const q = requirementsFor(d, s.ctx);
        const k = q.blockAll ?? q.blockGiven;
        return ({ notClockedIn: 'not clocked in', notOnShift: 'not on your shift', siteNotApproved: 'house not in your access', awaitingVerification: 'order to check', covertMissing: 'no covert plan', noWitness: 'no witness on shift', allergyNotConfirmed: 'allergy not confirmed' } as Record<string, string>)[k ?? ''] ?? 'blocked';
    }))];
    const blockedKinds = blockedCaption;
    const meters = loading ? (
        ['Due now', 'Late', 'Needs help', 'Recorded', 'Follow-ups', 'My eligibility'].map((l) => (
            <PageHeaderMeterBlock key={l} label={l} ariaLabel={`${l}: loading`} onClick={() => undefined}>
                <PageHeaderMeterBig>
                    <span className="inline-block h-5 w-10 animate-pulse rounded bg-primary-foreground/20 motion-reduce:animate-none" />
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>Loading</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        ))
    ) : unavailable ? (
        <>
            {['Due now', 'Late', 'Needs help', 'Recorded', 'Follow-ups'].map((l) => (
                <PageHeaderMeterBlock key={l} label={l} href={link('schedule')} ariaLabel={`${l}: unavailable`}>
                    <PageHeaderMeterBig>—</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>Unavailable</PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            ))}
            <EligibilityMeter e={eligibility} />
        </>
    ) : (
        <>
            <PageHeaderMeterBlock label="Due now" href={link('schedule', { state: 'open' })} ariaLabel={`View ${c.due.length} doses due now`}>
                <PageHeaderMeterBig>{c.due.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{c.due.length ? `${new Set(c.due.map(({ d }) => d.pid)).size} people · by 10:00 am` : empty ? 'Next: none today' : 'Next: 12:00 pm'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Late" tone={c.late.length ? 'warning' : 'brand'} href={link('schedule', { state: 'open' })} ariaLabel={`View ${c.late.length} late doses`}>
                <PageHeaderMeterBig>{c.late.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{c.late.length ? 'Oldest due 8:00 am' : 'Nothing late'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Needs help" tone={c.needsHelp.length ? (c.needsHelp.some(({ d }) => requirementsFor(d, s.ctx).blockGiven === 'allergyNotConfirmed' || requirementsFor(d, s.ctx).blockGiven === 'covertMissing') ? 'critical' : 'warning') : 'brand'} href={link('schedule', { state: 'help' })} ariaLabel={`View ${c.needsHelp.length} doses you can’t record as given`}>
                <PageHeaderMeterBig>{c.needsHelp.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{c.needsHelp.length ? (blockedKinds.length > 1 ? `${blockedKinds.length} reasons — see the list` : blockedKinds[0].charAt(0).toUpperCase() + blockedKinds[0].slice(1)) : 'Nothing blocked'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Recorded" value={c.denom ? `${c.recordedN} of ${c.denom}` : undefined} href={link('activity')} ariaLabel={c.denom ? `View activity, ${c.recordedN} of ${c.denom} recorded` : 'Recorded: not applicable, no doses were due'}>
                {c.denom ? <PageHeaderMeterDonut percent={(c.recordedN / c.denom) * 100} caption="Due so far on your shift" /> : (
                    <>
                        <PageHeaderMeterBig>n/a</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>None due this shift</PageHeaderMeterCaption>
                    </>
                )}
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Follow-ups" value={empty ? undefined : String(openFu.length)} tone={overdueFu && !empty ? 'critical' : 'brand'} href={link('followups')} ariaLabel={`View follow-ups, ${overdueFu} overdue`}>
                <PageHeaderMeterBig>{empty ? '0' : overdueFu ? `${overdueFu} overdue` : String(openFu.length)}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{empty ? 'None open' : overdueFu ? 'Oldest 11:30 pm Sunday' : 'None overdue'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <EligibilityMeter e={eligibility} />
        </>
    );

    /* ── filters: every view carries real pills ── */
    const stateFilter = r.q.get('state') ?? 'all';
    const group = (r.q.get('group') as 'time' | 'person') ?? 'time';
    const updated = scn === 'stale' ? (
        <PageHeaderFilterButton icon={AlertTriangle} className="border-status-warning" onClick={() => s.toast('info', 'Refreshed at 9:12 am NZDT (mockup).')}>
            Not updated since 8:40 am NZDT
        </PageHeaderFilterButton>
    ) : scn === 'offline' ? (
        <PageHeaderFilterButton icon={WifiOff} onClick={() => s.toast('warning', 'Offline — the list shows what was loaded at 9:05 am NZDT.')}>
            Offline · last updated 9:05 am NZDT
        </PageHeaderFilterButton>
    ) : (
        <PageHeaderFilterButton icon={RefreshCw} title="All times are NZDT (Pacific/Auckland). Refresh." onClick={() => s.toast('info', 'Refreshed at 9:12 am NZDT (mockup).')}>
            Updated 9:12 am NZDT
        </PageHeaderFilterButton>
    );
    const filters =
        view === 'schedule' ? (
            <>
                <PageHeaderFilterSelect label="All states" value={stateFilter} options={[{ value: 'all', label: 'All states' }, { value: 'open', label: 'Due or late' }, { value: 'help', label: 'Needs help' }, { value: 'recorded', label: 'Has an outcome' }]} onChange={(v) => s.go('/meds/today', { state: v === 'all' ? undefined : v })} />
                <PageHeaderViewToggle ariaLabel="Group by" value={group} onChange={(v) => s.go('/meds/today', { group: v === 'time' ? undefined : v })} options={[{ value: 'time', label: 'By time', icon: Clock3 }, { value: 'person', label: 'By person', icon: UserRound }]} />
                {updated}
            </>
        ) : view === 'rounds' ? (
            <>
                <PageHeaderFilterSelect label="All rounds" value={r.q.get('rstate') ?? 'all'} options={[{ value: 'all', label: 'All rounds' }, { value: 'open', label: 'Still to finish' }, { value: 'done', label: 'Finished' }]} onChange={(v) => s.go('/meds/today', { rstate: v === 'all' ? undefined : v })} />
                {updated}
            </>
        ) : view === 'asneeded' ? (
            <>
                <PageHeaderFilterSelect label="All people" value={r.q.get('pp') ?? 'all'} options={[{ value: 'all', label: 'All people' }, ...['aroha', 'tama', 'mele', 'grace'].map((p) => ({ value: p, label: PEOPLE[p].pref }))]} onChange={(v) => s.go('/meds/today', { pp: v === 'all' ? undefined : v })} />
                {updated}
            </>
        ) : view === 'activity' ? (
            <>
                <PageHeaderFilterSelect label="Last 24 hours" allValue="24h" value={r.q.get('range') ?? '24h'} options={[{ value: '24h', label: 'Last 24 hours' }, { value: 'today', label: 'Today only' }]} onChange={(v) => s.go('/meds/today', { range: v === '24h' ? undefined : v, page: undefined })} />
                <PageHeaderFilterSelect label="All outcomes" value={r.q.get('outcome') ?? 'all'} options={[{ value: 'all', label: 'All outcomes' }, { value: 'given', label: 'Given or taken' }, { value: 'notgiven', label: 'Not given' }]} onChange={(v) => s.go('/meds/today', { outcome: v === 'all' ? undefined : v, page: undefined })} />
                {updated}
            </>
        ) : (
            updated
        );

    const rail = (
        <PageHeaderRail<View>
            value={view}
            onSelect={setView}
            items={[
                { key: 'schedule', label: 'Schedule', icon: Clock3, ...(loading || unavailable ? {} : { count: c.due.length + c.late.length, alert: c.late.length > 0 }) },
                { key: 'rounds', label: 'Rounds', icon: Repeat },
                { key: 'asneeded', label: 'As-needed', icon: Pill },
                { key: 'followups', label: 'Follow-ups', icon: Flag, ...(loading || unavailable || empty ? {} : { count: openFu.length, alert: overdueFu > 0 }) },
                { key: 'controlled', label: 'Controlled checks', icon: ShieldCheck },
                { key: 'stockalerts', label: 'Stock alerts', icon: Package },
                { key: 'activity', label: 'Activity', icon: Activity },
            ]}
        />
    );

    const header = (
        <PageHeader
            icon={Pill}
            title="Meds today"
            titleChip={notClocked ? <PageHeaderStatusChip variant="warning" icon={LogIn}>Not clocked in</PageHeaderStatusChip> : <PageHeaderStatusChip variant="success" icon={CheckCircle2}>On shift</PageHeaderStatusChip>}
            subline="Mon 28 Sep 2026 · Kōwhai House · shift 7:00 am–3:00 pm"
            actions={
                <>
                    <PageHeaderSearch value={search} onChange={setSearch} placeholder="Search people or medicines…" />
                    <PageHeaderGlassButton icon={Repeat} aria-label="Shift handover — medication" title="Shift handover — medication" onClick={() => s.toast('info', 'The medication handover lens is designed in P08a — outside this preview.')} />
                    <PageHeaderGlassButton icon={Flag} aria-label="Report a medication error" title="Report a medication error" onClick={() => s.toast('info', 'Opens the existing Report a medication error dialog (redesigned in P08b) — outside this preview.')} />
                    {isFrontline(r.persona) || r.persona === 'lead' ? (
                        <PageHeaderPrimaryButton icon={Plus} onClick={() => s.go('/meds/today', { open: 'prn-pick' })}>
                            Record as-needed dose
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={meters}
            filters={filters}
            rail={rail}
        />
    );

    const crumbs = isFrontline(r.persona) ? [{ title: 'Home', href: '/dashboard' }, { title: 'Meds today' }] : [{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/meds/today' }, { title: 'Meds today' }];
    return (
        <Shell crumbs={crumbs}>
            {header}
            {view === 'schedule' ? <ScheduleView search={search} stateFilter={stateFilter} group={group} /> : null}
            {view === 'rounds' ? <RoundsView /> : null}
            {view === 'asneeded' ? <AsNeededView search={search} /> : null}
            {view === 'activity' ? <ActivityView search={search} /> : null}
            {view === 'followups' ? <LinkOnly pkg="P08a · Follow-ups & handover" what="Your follow-ups (as-needed effect checks, refusal follow-ups and handover items) with owner and due time. P01 creates them — a refusal or an as-needed dose adds one here — and My Day shows your own." /> : null}
            {view === 'controlled' ? <LinkOnly pkg="P07a · Controlled checks" what="Controlled-drug counts and witness requests due at this house. P01 designs witnessing a controlled dose and asking a manager for a witness override." /> : null}
            {view === 'stockalerts' ? <LinkOnly pkg="P06 · Stock & pharmacy" what="Stock and supply alerts for this house, including medicine photos at stock receipt." /> : null}
        </Shell>
    );
}

function EligibilityMeter({ e }: { e: { big: string; cap: string; tone: 'critical' | 'success' | 'warning' } }) {
    const open = useOpen();
    return (
        <PageHeaderMeterBlock label="My eligibility" tone={e.tone} onClick={() => open('eligibility')} ariaLabel={`View my eligibility: ${e.big.toLowerCase()}`}>
            <PageHeaderMeterBig>{e.big}</PageHeaderMeterBig>
            <PageHeaderMeterCaption>{e.cap}</PageHeaderMeterCaption>
        </PageHeaderMeterBlock>
    );
}

function LinkOnly({ pkg, what }: { pkg: string; what: string }) {
    return (
        <Card className="gap-3 p-5">
            <p className="text-section-title">This tab keeps its place — its view is designed in {pkg}</p>
            <p className="text-subtle">{what}</p>
            <DesignNote>Shown as a link only in P01 (brief). Until its package ships, the app keeps today’s screen for this tab; nothing here is a stub.</DesignNote>
        </Card>
    );
}

/* ───────────── Schedule ───────────── */
function useDoseTable(from: 'meds-today' | 'round' = 'meds-today') {
    const s = useStore();
    const menu = useDoseMenu();
    const rowOpen = useRowOpen();
    const ctx = useRowContext();
    const table = (rows: Dose[], key: string) => (
        <EntityTable<Dose>
            key={key}
            rows={rows}
            rowKey={(d) => d.id}
            rowHeight="content"
            minWidth={980}
            identityLabel="Person"
            identityWidth="1.05fr"
            identity={(d) => ({ mark: <PersonMark pid={d.pid} size={30} />, name: PEOPLE[d.pid].pref, subline: `${PEOPLE[d.pid].surname}${PEOPLE[d.pid].house !== 'Kōwhai House' ? ` · ${PEOPLE[d.pid].house}` : ''}` })}
            columns={[
                { key: 'med', label: 'Medicine', width: '1.7fr', cell: (d) => <MedicineCell d={d} /> },
                { key: 'due', label: 'Due', width: '0.7fr', cell: (d) => <span className="text-[12.5px] font-semibold tabular-nums">{d.slot}</span> },
                { key: 'state', label: 'State', width: '1.9fr', cell: (d) => <DoseStateCell d={d} /> },
                { key: 'act', label: '', width: '176px', align: 'right', cell: (d) => <DoseActionCell d={d} from={from} /> },
            ]}
            actionsFor={(d) => menu(d, from)}
            onOpen={(d) => rowOpen(d, from)}
            onRowContextMenu={(e, d) => ctx.openAt(e, `${PEOPLE[d.pid].pref} · ${d.med}`, menu(d, from))}
            mutedFor={(d) => s.stateOf(d) === 'selfmanaged'}
        />
    );
    return { table, ctxNode: ctx.node };
}

function ScheduleView({ search, stateFilter, group }: { search: string; stateFilter: string; group: 'time' | 'person' }) {
    const s = useStore();
    const scn = s.route.scenario;
    const { table, ctxNode } = useDoseTable();
    const q = search.trim().toLowerCase();
    const all = s.visibleDoses();
    const onShift = all.filter((d) => d.pid !== 'hine' && d.pid !== 'ben');
    const offShift = all.filter((d) => d.pid === 'hine' || d.pid === 'ben');
    const match = (d: Dose) => {
        if (q && !`${PEOPLE[d.pid].pref} ${PEOPLE[d.pid].surname} ${d.med}`.toLowerCase().includes(q)) return false;
        const st = s.stateOf(d);
        const req = requirementsFor(d, s.ctx);
        if (stateFilter === 'open') return st === 'due' || st === 'late';
        if (stateFilter === 'help') return (st === 'due' || st === 'late') && !!(req.blockAll || req.blockGiven);
        if (stateFilter === 'recorded') return !['due', 'late', 'notdue', 'selfmanaged'].includes(st);
        return true;
    };
    const rows = onShift.filter(match);
    const banners = (
        <>
            {scn === 'notClockedIn' && !s.clockedIn ? (
                <Notice
                    tone="warning"
                    icon={LogIn}
                    title="You’re not clocked in"
                    actions={
                        <>
                            <Button className="frontline-tap" onClick={() => (s.clockIn(), s.toast('success', 'Clocked in at 9:12 am · Kōwhai House. You can record now.'))}>
                                <LogIn className="size-4" /> Clock in
                            </Button>
                            <span className="self-center text-caption">
                                Can’t clock in? Coordinator on call: <NotConfigured />
                            </span>
                        </>
                    }
                >
                    You can read today’s medicines, but you can’t record anything until you clock in on a shift that includes these people.
                </Notice>
            ) : null}
            {scn === 'offline' ? (
                <Notice tone="warning" icon={WifiOff} title="You’re offline" actions={<Button className="frontline-tap" variant="outline" onClick={() => s.toast('info', 'The dated print pack lives in Reports & audit › Print & exports (P09/P10) — outside this preview.')}><Printer className="size-4" /> Open print pack</Button>}>
                    Records you make now are saved on this device only and sent when you reconnect. Other staff can’t see them yet.
                </Notice>
            ) : null}
            {scn === 'stale' ? (
                <Notice tone="warning" title="Not updated since 8:40 am NZDT (32 min ago)" actions={<><Button className="frontline-tap" variant="outline" onClick={() => s.toast('info', 'Refreshed at 9:12 am NZDT (mockup).')}><RefreshCw className="size-4" /> Refresh now</Button><Button className="frontline-tap" variant="ghost" onClick={() => s.toast('info', 'The dated print pack lives in Reports & audit › Print & exports — outside this preview.')}><Printer className="size-4" /> Open print pack</Button></>}>
                    We couldn’t refresh. What you see may be out of date — someone may have recorded a dose since. Before you record, refresh. Saving always checks the chart again.
                </Notice>
            ) : null}
        </>
    );
    if (scn === 'loading')
        return (
            <Card className="p-5" aria-busy="true" aria-label="Loading today’s doses">
                <SkeletonTable rows={6} columns={5} />
                <span className="sr-only">Loading today’s doses…</span>
            </Card>
        );
    if (scn === 'unavailable')
        return (
            <Card className="p-2">
                <ErrorState title="Couldn’t load today’s doses" message="Some doses may already be recorded. Don’t give anything from memory — try again, or use the printed MAR." onRetry={() => s.toast('warning', 'Still unavailable (mockup).')} />
            </Card>
        );
    if (scn === 'empty')
        return (
            <Card className="p-2">
                <EmptyState icon={CheckCircle2} title="Nothing left to record on your shift" description="Everything due so far is recorded, and no one on your shift has more medicines due today. Updated 9:12 am NZDT." />
            </Card>
        );
    const groups: [string, Dose[]][] =
        group === 'person'
            ? [...new Set(rows.map((d) => d.pid))].map((pid) => [`${PEOPLE[pid].pref} ${PEOPLE[pid].surname}`, rows.filter((d) => d.pid === pid)])
            : [...new Set(rows.map((d) => d.slot))].map((slot) => [slot, rows.filter((d) => d.slot === slot)]);
    return (
        <>
            {banners}
            {groups.length === 0 ? (
                <Card className="p-2">
                    <EmptyState icon={ListChecks} title="No doses match" description="Nothing on your shift matches this search or filter. Clear the search or choose “All states”." />
                </Card>
            ) : (
                groups.map(([title, ds]) => (
                    <section key={title} className="flex flex-col gap-2.5" aria-label={title}>
                        <ListCaption title={group === 'time' ? `${title} doses` : title} caption={`${ds.length} of ${ds.length} shown`} right={group === 'time' ? <EntityChip icon={Clock3}>Window {labelOf(ds[0].slotMin - 30)}–{labelOf(ds[0].slotMin + 60)}</EntityChip> : null} />
                        {table(ds, title)}
                    </section>
                ))
            )}
            {offShift.length ? (
                <section className="flex flex-col gap-2.5" aria-label="Not on your shift">
                    <ListCaption title={offShift[0].pid === 'ben' ? 'Moved to a house outside your access' : 'At Kōwhai House, not on your shift'} caption={`${offShift.length} shown`} />
                    <p className="text-caption">Shown so nothing is missed. You can’t record for {offShift[0].pid === 'ben' ? 'Ben' : 'Hine'} until {offShift[0].pid === 'ben' ? 'your access includes Rimu House' : 'Hine is on your shift'} — the row says why and who can help.</p>
                    {table(offShift, 'off')}
                </section>
            ) : null}
            <p className="text-caption">Showing the people on your covering shift (Aroha, Tama, Mele, Grace, Sam). Times in NZDT. Self-managed medicines are listed for information and never count as late or missed. The window is today’s organisation window: 30 minutes before to 60 minutes after the dose time.</p>
            {ctxNode}
        </>
    );
}

/* ───────────── Rounds (incl. the guided round) ───────────── */
interface Round {
    id: string;
    name: string;
    slotMin: number;
    slot: string;
}
const ROUNDS: Round[] = [
    { id: 'am8', name: 'Morning round', slotMin: 480, slot: '8:00 am' },
    { id: 'am9', name: '9:00 am round', slotMin: 540, slot: '9:00 am' },
    { id: 'md12', name: 'Midday round', slotMin: 720, slot: '12:00 pm' },
];
function RoundsView() {
    const s = useStore();
    const open = useOpen();
    const active = s.route.q.get('round');
    const ctx = useRowContext();
    const rstate = s.route.q.get('rstate') ?? 'all';
    const doses = s.visibleDoses();
    const info = (rd: Round) => {
        const ds = doses.filter((d) => d.slotMin === rd.slotMin && d.support !== 'independent');
        const openN = ds.filter((d) => ['due', 'late', 'notdue', 'rejected', 'uncertain', 'queued'].includes(s.stateOf(d))).length;
        const late = ds.filter((d) => s.stateOf(d) === 'late').length;
        const notdue = ds.every((d) => s.stateOf(d) === 'notdue');
        return { ds, done: ds.length - openN, total: ds.length, late, state: openN === 0 ? 'done' : notdue ? 'notdue' : late ? 'late' : 'due' };
    };
    const rows = ROUNDS.filter((rd) => (rstate === 'all' ? true : rstate === 'done' ? info(rd).state === 'done' : info(rd).state !== 'done'));
    const menu = (rd: Round) => [
        { label: info(rd).state === 'notdue' ? 'View round' : 'Start or resume guided round', icon: Play, onClick: () => s.go('/meds/today', { view: 'rounds', round: rd.id }) },
        { label: 'Print round sheet', icon: Printer, onClick: () => s.toast('info', 'Prints the existing round sheet PDF — outside this preview.') },
        ...(s.route.persona === 'lead' || s.route.persona === 'pm' ? [{ label: 'Assign to one person', icon: UserRound, onClick: () => s.toast('info', 'Narrows the round to one person: the others on shift stop seeing its task; overdue alerts still reach the house lead.') }] : []),
    ];
    const guided = active ? ROUNDS.find((x) => x.id === active) : null;
    const { table, ctxNode } = useDoseTable('round');
    return (
        <>
            {guided ? (
                (() => {
                    const inf = info(guided);
                    const nextDose = inf.ds.find((d) => ['due', 'late', 'rejected'].includes(s.stateOf(d)) && !requirementsFor(d, s.ctx).blockAll);
                    return (
                        <Card className="gap-4 p-5" aria-label={`Guided round: ${guided.name}`}>
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p className="text-section-title">Guided round · {guided.name} ({guided.slot}) · Kōwhai House</p>
                                    <p className="text-subtle">
                                        {inf.done} of {inf.total} recorded · every dose opens the same recording steps as the schedule · the round URL /emar/rounds?guided= keeps working
                                    </p>
                                </div>
                                <div className="flex gap-2">
                                    <Button className="frontline-tap" variant="outline" onClick={() => s.go('/meds/today', { view: 'rounds', round: undefined })}>
                                        Leave the round
                                    </Button>
                                    {nextDose ? (
                                        <Button className="frontline-tap" data-return="round-next" onClick={() => open(`record:${nextDose.id}`, { from: 'round' })}>
                                            <Play className="size-4" /> Record next: {PEOPLE[nextDose.pid].pref} · {nextDose.med}
                                        </Button>
                                    ) : (
                                        <Button className="frontline-tap" onClick={() => (s.toast('success', `${guided.name} finished at 9:12 am — ${inf.done} of ${inf.total} have an outcome.`), s.go('/meds/today', { view: 'rounds', round: undefined }))}>
                                            <CheckCircle2 className="size-4" /> Finish round
                                        </Button>
                                    )}
                                </div>
                            </div>
                            <div className="h-1.5 overflow-hidden rounded-full bg-muted" role="progressbar" aria-valuenow={inf.done} aria-valuemin={0} aria-valuemax={inf.total} aria-label="Round progress">
                                <div className="h-full rounded-full bg-primary" style={{ width: `${(inf.done / Math.max(1, inf.total)) * 100}%` }} />
                            </div>
                            {s.route.scenario === 'orderChanged' && guided.id === 'am8' ? (
                                <Notice tone="warning" title="An order in this round changed after it started">
                                    Tama’s levetiracetam changed at 9:05 am (now 750 mg). The round shows the new order; you’ll be asked to check it before recording.
                                </Notice>
                            ) : null}
                            {table(inf.ds, `g-${guided.id}`)}
                        </Card>
                    );
                })()
            ) : null}
            <section className="flex flex-col gap-2.5" aria-label="Rounds today">
                <ListCaption title="Rounds on your shift" caption={`${rows.length} of ${ROUNDS.length} shown`} right={<EntityChip icon={UserRound}>Everyone rostered sees each round</EntityChip>} />
                <EntityTable<Round>
                    rows={rows}
                    rowKey={(x) => x.id}
                    minWidth={900}
                    identityLabel="Round"
                    identity={(x) => ({ icon: Repeat, name: x.name, subline: `${x.slot} · Kōwhai House` })}
                    columns={[
                        { key: 'win', label: 'Window', width: '1fr', cell: (x) => <span className="text-[12.5px]">{labelOf(x.slotMin - 30)}–{labelOf(x.slotMin + 60)}</span> },
                        { key: 'rec', label: 'Recorded', width: '1fr', cell: (x) => <span className="text-[12.5px] font-semibold tabular-nums">{info(x).done} of {info(x).total}</span> },
                        { key: 'who', label: 'Who', width: '1.4fr', cell: () => <span className="flex items-center gap-2 text-[12.5px]"><PersonDisc name="Priya Shah" size={22} /> Everyone rostered at Kōwhai House</span> },
                        { key: 'st', label: 'State', width: '1fr', cell: (x) => { const i = info(x); return i.state === 'done' ? <StatusBadge variant="success">Done — all recorded</StatusBadge> : i.state === 'late' ? <StatusBadge variant="warning">{i.late} late</StatusBadge> : i.state === 'due' ? <StatusBadge variant="info">Due now</StatusBadge> : <StatusBadge variant="neutral">Due {x.slot}</StatusBadge>; } },
                        { key: 'act', label: '', width: '190px', align: 'right', cell: (x) => info(x).state === 'notdue' || info(x).state === 'done' ? <span className="text-caption">{info(x).state === 'done' ? 'Finished' : `Opens ${labelOf(x.slotMin - 30)}`}</span> : <Button className="frontline-tap" onClick={(e) => (e.stopPropagation(), s.go('/meds/today', { view: 'rounds', round: x.id }))}><Play className="size-4" /> {info(x).done ? 'Resume guided round' : 'Start guided round'}</Button> },
                    ]}
                    actionsFor={menu}
                    onOpen={(x) => s.go('/meds/today', { view: 'rounds', round: x.id })}
                    onRowContextMenu={(e, x) => ctx.openAt(e, x.name, menu(x))}
                />
                <p className="text-caption">Rounds after 3:00 pm belong to the next shift. Round templates are managed in Settings › Round templates.</p>
            </section>
            {ctx.node}
            {ctxNode}
        </>
    );
}

/* ───────────── As-needed ───────────── */
function AsNeededView({ search }: { search: string }) {
    const s = useStore();
    const open = useOpen();
    const ctx = useRowContext();
    const pp = s.route.q.get('pp') ?? 'all';
    const q = search.trim().toLowerCase();
    const rows = PRN.filter((o) => (pp === 'all' || o.pid === pp) && (!q || `${PEOPLE[o.pid].pref} ${o.med}`.toLowerCase().includes(q)));
    const menu = (o: (typeof PRN)[number]) => [
        { label: 'Record as-needed dose', icon: Pill, onClick: () => open(`prn:${o.id}`) },
        { label: `Open ${PEOPLE[o.pid].pref}’s medication record`, icon: ListChecks, onClick: () => s.go('/emar/mar', { client: o.pid }) },
        { label: 'Report a medication error', icon: Flag, onClick: () => s.toast('info', 'Opens the existing Report a medication error dialog — outside this preview.') },
    ];
    const recorded = [
        ...s.prnRecords.map((p) => ({ ...p, pid: PRN.find((o) => o.id === p.orderId)!.pid, med: PRN.find((o) => o.id === p.orderId)!.med })),
        ...(s.route.scenario === 'offlineRefused' ? [{ id: 'ref', orderId: 'p4', pid: 'mele', med: 'Paracetamol', at: '8:50 am', amount: '2 tablets', reason: 'Pain', by: 'Priya Shah', state: 'rejected' as const, checkBy: '' }] : []),
    ];
    return (
        <>
            <section className="flex flex-col gap-2.5" aria-label="As-needed medicines">
                <ListCaption title="As-needed medicines for people on your shift" caption={`${rows.length} of ${PRN.length} shown`} />
                <EntityTable
                    rows={rows}
                    rowKey={(o) => o.id}
                    rowHeight="content"
                    minWidth={960}
                    identityLabel="Person"
                    identityWidth="1fr"
                    identity={(o) => ({ mark: <PersonMark pid={o.pid} size={30} />, name: PEOPLE[o.pid].pref, subline: PEOPLE[o.pid].surname })}
                    columns={[
                        { key: 'med', label: 'Medicine', width: '1.6fr', cell: (o) => <span className="flex flex-col gap-1"><span className="text-[13px] font-semibold">{o.med} <span className="font-normal text-muted-foreground">{o.strength}</span>{o.cd ? ' · controlled' : ''}</span><span className="text-[12px] text-muted-foreground">{o.instructions.split(' (')[0]}</span></span> },
                        { key: 'last', label: 'Last 24 hours', width: '1.2fr', cell: (o) => <span className="text-[12.5px]"><strong>{o.last24h.length} of {o.maxPer24h}</strong>{o.last24h[0] ? ` · last ${o.last24h[0].at}` : ' · none'}</span> },
                        { key: 'st', label: 'State', width: '1fr', cell: (o) => (o.last24h.length >= o.maxPer24h ? <StatusBadge variant="critical">Limit reached</StatusBadge> : <StatusBadge variant="neutral">Available</StatusBadge>) },
                        { key: 'act', label: '', width: '150px', align: 'right', cell: (o) => <Button variant={o.last24h.length >= o.maxPer24h ? 'outline' : 'default'} className="frontline-tap" onClick={(e) => (e.stopPropagation(), open(`prn:${o.id}`))}>{o.last24h.length >= o.maxPer24h ? 'Why not?' : 'Record'}</Button> },
                    ]}
                    actionsFor={menu}
                    onOpen={(o) => open(`prn:${o.id}`)}
                    onRowContextMenu={(e, o) => ctx.openAt(e, `${PEOPLE[o.pid].pref} · ${o.med}`, menu(o))}
                />
                <p className="text-caption">Counts use the real last 24 hours. A dose at its limit still opens so you can see why and what to do.</p>
            </section>
            <section className="flex flex-col gap-2.5" aria-label="As-needed doses recorded today">
                <ListCaption title="As-needed doses recorded today" caption={`${recorded.length + 1} shown`} />
                <Card className="gap-0 divide-y p-0">
                    {[...recorded, { id: 'seed', orderId: 'p4', pid: 'mele', med: 'Paracetamol', at: '8:05 am', amount: '2 tablets', reason: 'Pain', by: 'Mere Kahu', state: 'recorded' as const, checkBy: '8:35 am' }].map((x) => (
                        <div key={x.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <span className="flex items-center gap-3">
                                <PersonMark pid={x.pid} size={30} />
                                <span>
                                    <span className="block text-[13px] font-semibold">{PEOPLE[x.pid].pref} · {x.med} · {x.amount}</span>
                                    <span className="block text-[12px] text-muted-foreground">{x.state === 'rejected' ? 'Saved offline 8:50 am · refused when sent at 9:02 am (limit on the prescription reached) · not on the chart' : `${x.at} · for ${x.reason.toLowerCase()} · ${x.by}${x.checkBy ? ` · effect check by ${x.checkBy}` : ''}`}</span>
                                </span>
                            </span>
                            {x.state === 'rejected' ? (
                                <Button className="frontline-tap" variant="outline" onClick={() => open('rejected')}>
                                    Review
                                </Button>
                            ) : x.state === 'queued' ? (
                                <StatusBadge variant="warning">Saved on this device</StatusBadge>
                            ) : (
                                <StatusBadge variant="success">Given</StatusBadge>
                            )}
                        </div>
                    ))}
                </Card>
            </section>
            {ctx.node}
        </>
    );
}

/* ───────────── Activity (server-style pagination) ───────────── */
function ActivityView({ search }: { search: string }) {
    const s = useStore();
    const ctx = useRowContext();
    const range = s.route.q.get('range') ?? '24h';
    const outcome = s.route.q.get('outcome') ?? 'all';
    const page = Number(s.route.q.get('page') ?? '1');
    const q = search.trim().toLowerCase();
    const rows = useMemo(
        () =>
            ACTIVITY.filter((a) => (range === 'today' ? a.day === 'Today' : true))
                .filter((a) => (outcome === 'all' ? true : outcome === 'given' ? ['Given', 'Given (as needed)', 'Taken with assistance', 'Taken with prompting'].includes(a.outcome) : ['Refused', 'Withheld'].includes(a.outcome)))
                .filter((a) => !q || `${PEOPLE[a.pid].pref} ${a.med} ${a.by}`.toLowerCase().includes(q)),
        [range, outcome, q],
    );
    const per = 10;
    const last = Math.max(1, Math.ceil(rows.length / per));
    const cur = Math.min(page, last);
    const shown = rows.slice((cur - 1) * per, cur * per);
    const url = (p: number) => hrefFor('/meds/today', { view: 'activity', page: String(p) }, s.route);
    const links = [{ url: cur > 1 ? url(cur - 1) : null, label: '&laquo; Previous', active: false }, ...Array.from({ length: last }, (_, i) => ({ url: url(i + 1), label: String(i + 1), active: i + 1 === cur })), { url: cur < last ? url(cur + 1) : null, label: 'Next &raquo;', active: false }];
    const menu = (a: (typeof ACTIVITY)[number]) => [
        { label: `Open ${PEOPLE[a.pid].pref}’s medication record`, icon: ListChecks, onClick: () => s.go('/emar/mar', { client: a.pid }) },
        { label: 'Report a medication error', icon: Flag, onClick: () => s.toast('info', 'Opens the existing Report a medication error dialog — outside this preview.') },
    ];
    return (
        <section className="flex flex-col gap-2.5" aria-label="Activity">
            <ListCaption title={range === 'today' ? 'Recorded today at Kōwhai House' : 'Recorded in the last 24 hours at Kōwhai House'} caption={`${shown.length} of ${rows.length} shown`} />
            <EntityTable
                rows={shown}
                rowKey={(a) => a.id}
                minWidth={900}
                identityLabel="Person"
                identity={(a) => ({ mark: <PersonMark pid={a.pid} size={30} />, name: PEOPLE[a.pid].pref, subline: PEOPLE[a.pid].surname })}
                columns={[
                    { key: 'when', label: 'When', width: '0.9fr', cell: (a) => <span className="text-[12.5px] tabular-nums">{a.at}{a.day === 'Sunday' ? ' Sunday' : ''}</span> },
                    { key: 'med', label: 'Medicine', width: '1.3fr', cell: (a) => <span className="text-[12.5px] font-semibold">{a.med}</span> },
                    { key: 'out', label: 'Outcome', width: '1.1fr', cell: (a) => <StatusBadge variant={['Refused', 'Withheld'].includes(a.outcome) ? 'warning' : 'success'}>{a.outcome}</StatusBadge> },
                    { key: 'by', label: 'By', width: '1fr', cell: (a) => <span className="flex items-center gap-2 text-[12.5px]"><PersonDisc name={a.by} size={22} />{a.by}</span> },
                    { key: 'd', label: 'Detail', width: '1.6fr', cell: (a) => <span className="text-[12px] text-muted-foreground">{a.detail}</span> },
                ]}
                actionsFor={menu}
                onOpen={(a) => s.go('/emar/mar', { client: a.pid })}
                onRowContextMenu={(e, a) => ctx.openAt(e, `${PEOPLE[a.pid].pref} · ${a.med}`, menu(a))}
            />
            <LaravelPagination links={links} lastPage={last} />
            <p className="text-caption">Times in NZDT. 10 per page. Rows open the person’s medication record.</p>
            {ctx.node}
        </section>
    );
}
