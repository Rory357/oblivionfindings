/* The canonical person medication record — /emar/mar?client_id=… (plan §2.2, §4.1).
 * Mirrors the live Fleet vehicle profile (PKG-02B, vehicles/show.tsx on
 * origin/main): profile PageHeader with a two-line identity subline, a
 * Find-in-record search, one primary action, 4–6 linked meters, an "As at …
 * Pacific/Auckland" filter row, the record's sections on the connected-tab rail
 * (with Find) and each section's sub-views on the tier-2 strip. */
import { TabSearchPalette, type GroupedProfileNavGroup } from '@/components/page/grouped-profile-nav';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearchTrigger,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/error-state';
import {
    Activity,
    AlertTriangle,
    CalendarDays,
    CalendarRange,
    Clock3,
    ClipboardList,
    History as HistoryIcon,
    Home,
    Image,
    Lock,
    Pill,
    Printer,
    RefreshCw,
    ShieldAlert,
    Stethoscope,
    Syringe,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { DAYS, FACTS, HOUSES, INR, P02_PERSONAS, frontline, medsOf, recorderIn01, type PersonId } from '../data';
import { useAdmins, useToday } from '../model';
import { PEOPLE } from '../p01/data';
import { useOpen } from '../p01/doses';
import { DesignNote, Notice, PersonMark } from '../p01/ui';
import { Shell } from '../shell';
import { VIEWS, hrefFor, useP02, type Tab } from '../store';
import { AllergySummary, SubTabs, allergyShort, useAllergy } from '../ui';
import { AllergiesTab } from './tab-allergies';
import { ChartTab } from './tab-chart';
import { ClinicalTab } from './tab-clinical';
import { HistoryTab } from './tab-history';
import { MedicinesTab, SupportTab } from './tab-medicines';

const RAIL: { key: Tab; label: string; icon: LucideIcon }[] = [
    { key: 'chart', label: 'Chart', icon: ClipboardList },
    { key: 'medicines', label: 'Medicines', icon: Pill },
    { key: 'support', label: 'Support plan', icon: Users },
    { key: 'allergies', label: 'Allergies & alerts', icon: ShieldAlert },
    { key: 'clinical', label: 'Clinical', icon: Stethoscope },
    { key: 'history', label: 'History', icon: HistoryIcon },
];
const VIEW_ICON: Record<string, LucideIcon> = {
    scheduled: CalendarDays,
    asneeded: Pill,
    current: Pill,
    stopped: HistoryIcon,
    photos: Image,
    bymedicine: Users,
    assessment: ClipboardList,
    allergies: ShieldAlert,
    alerts: AlertTriangle,
    interactions: Activity,
    inr: Activity,
    driver: Syringe,
    observations: Stethoscope,
    doses: CalendarRange,
    corrections: RefreshCw,
    changes: HistoryIcon,
};

/* ───────────── boundaries: no access (page) vs not found (record) ───────────── */
export function NoAccess() {
    const s = useP02();
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication' }]}>
            <Card className="items-center gap-3 p-10 text-center">
                <Lock className="size-8 text-muted-foreground" aria-hidden="true" />
                <h1 className="text-section-title">You don’t have access to medication records</h1>
                <p className="text-subtle">Ask your manager if you need it for your work.</p>
                <Button variant="outline" onClick={() => s.toast('info', 'Home — outside this preview.')}>
                    Go to Home
                </Button>
            </Card>
            <DesignNote title="Design note — no access (the page)">
                <p>Shown when you hold no medication permission at all (403). It says nothing about whether this person exists or has medicines.</p>
            </DesignNote>
        </Shell>
    );
}
export function NotFound({ moved }: { moved?: boolean }) {
    const s = useP02();
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication' }, { title: 'Medication record' }]}>
            <Card className="p-8 text-center">
                <p className="text-section-title">We can’t show this record</p>
                <p className="text-subtle mt-1">It may not exist, or it may not be available to you. Check the link, or go back.</p>
                <div className="mt-4">
                    <Button variant="outline" onClick={() => s.toast('info', 'Back — outside this preview.')}>
                        Go back
                    </Button>
                </div>
            </Card>
            <DesignNote title="Design note — not found (the record)">
                <p>A record outside your approved houses and a record that doesn’t exist look the same (404, no existence leak — P00 v5).</p>
                {moved ? (
                    <p>
                        This is what Kōwhai House staff see for Ben after he moved to Rimu House at 8:30 am: access follows the person’s current house (Stephan, 30 Sep: “follow industry standard”). During the shift the move is explained on Meds today’s row instead (P01 “Your access doesn’t include Rimu House”).
                    </p>
                ) : null}
            </DesignNote>
        </Shell>
    );
}

/* ───────────── the page ───────────── */
export function RecordPage() {
    const s = useP02();
    const me = P02_PERSONAS[s.route.persona];
    const pid = s.pid;
    if (!s.can('view')) return <NoAccess />;
    if (!pid || !me.houses.includes(FACTS[pid].house)) return <NotFound moved={pid === 'ben'} />;
    return <Record pid={pid} />;
}

function Record({ pid }: { pid: PersonId }) {
    const s = useP02();
    const open01 = useOpen();
    const [findOpen, setFindOpen] = useState(false);
    const me = P02_PERSONAS[s.route.persona];
    const f = FACTS[pid];
    const p = PEOPLE[pid];
    const tab = (RAIL.some((r) => r.key === s.route.q.get('tab')) ? s.route.q.get('tab') : 'chart') as Tab;
    const views = VIEWS[tab].filter((v) => v.key !== 'changes' || s.can('audit.view'));
    const view = views.some((v) => v.key === s.route.q.get('view')) ? s.route.q.get('view')! : views[0].key;
    const state = s.route.state;
    const today = useToday(pid);
    const admins = useAdmins(pid);
    const allergy = useAllergy(pid);
    const meds = medsOf(pid).filter((m) => m.status !== 'stopped');
    const hiddenMeds = s.cdView ? 0 : meds.filter((m) => m.cd).length;
    const visibleToday = today.filter((c) => s.cdView || !c.med.cd);
    const due = visibleToday.filter((c) => c.state === 'due');
    const late = visibleToday.filter((c) => c.state === 'late');
    const soFar = visibleToday.filter((c) => !['notdue', 'selfmanaged'].includes(c.state));
    const recorded = soFar.filter((c) => c.rec);
    const onWarfarin = meds.some((m) => m.inr);
    const latestInr = s.inrFor(pid).find((r) => !r.disabled);
    const pending = admins.filter((a) => a.correction?.status === 'pending' && (s.cdView || !MED_CD(a.med))).length;
    const go = (t: Tab, v?: string, extra: Record<string, string | undefined> = {}) => s.set({ tab: t === 'chart' ? undefined : t, view: v, dlg: undefined, page: undefined, ...extra });
    const empty = state === 'empty';
    const loading = state === 'loading';
    const failed = state === 'unavailable';

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
            const t = e.target as HTMLElement | null;
            if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
            e.preventDefault();
            setFindOpen(true);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    const crumbs = frontline(s.route.persona)
        ? [{ title: 'Home', href: '/dashboard' }, { title: 'Meds today', href: '/meds/today' }, { title: p.legal }]
        : [{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/emar/mar' }, { title: 'MAR & medicines', href: '/emar/mar' }, { title: p.legal }];

    const groups: GroupedProfileNavGroup[] = RAIL.map((r) => ({
        key: r.key,
        label: r.label,
        icon: r.icon,
        tabs: VIEWS[r.key].filter((v) => v.key !== 'changes' || s.can('audit.view')).map((v) => ({ key: `${r.key}.${v.key}`, label: v.label, icon: VIEW_ICON[v.key] ?? r.icon })),
    }));

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
    const mode = s.route.q.get('mode') === 'week' ? 'week' : 'day';
    const filters: Record<Tab, React.ReactNode> = {
        chart:
            view === 'scheduled' ? (
                <>
                    <PageHeaderViewToggle
                        ariaLabel="Chart layout"
                        value={mode}
                        onChange={(v) => s.set({ mode: v === 'week' ? 'week' : undefined })}
                        options={[
                            { value: 'day', label: 'Day', icon: CalendarDays },
                            { value: 'week', label: 'Week', icon: CalendarRange },
                        ]}
                    />
                    {mode === 'day' ? (
                        <PageHeaderFilterSelect icon={CalendarDays} label="Day" value={s.route.q.get('day') ?? '2026-09-28'} onChange={(v) => s.set({ day: v === '2026-09-28' ? undefined : v })} options={[...DAYS].reverse().map((d) => ({ value: d.iso, label: `${d.short} Sep${d.iso === '2026-09-28' ? ' · today' : ''}` }))} />
                    ) : (
                        <PageHeaderFilterSelect icon={CalendarRange} label="Week" value="w39" onChange={() => s.toast('info', 'Earlier weeks page back through History — mockup shows this week.')} options={[{ value: 'w39', label: 'Tue 22 – Mon 28 Sep 2026' }]} />
                    )}
                    {asAt}
                </>
            ) : (
                <>
                    <PageHeaderFilterCheck label="Show limit reached only" checked={s.route.q.get('limit') === '1'} onChange={(c) => s.set({ limit: c ? '1' : undefined })} />
                    {asAt}
                </>
            ),
        medicines: (
            <>
                <PageHeaderFilterSelect icon={Pill} label="Type" value={s.route.q.get('type') ?? 'all'} allValue="all" onChange={(v) => s.set({ type: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All types' }, { value: 'scheduled', label: 'Scheduled' }, { value: 'prn', label: 'As needed' }]} />
                <PageHeaderFilterCheck label="Needs attention" checked={s.route.q.get('attn') === '1'} onChange={(c) => s.set({ attn: c ? '1' : undefined })} />
                {asAt}
            </>
        ),
        support: (
            <>
                <PageHeaderFilterSelect icon={Users} label="Support" value={s.route.q.get('sup') ?? 'all'} allValue="all" onChange={(v) => s.set({ sup: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All support' }, { value: 'administer', label: 'Administer' }, { value: 'assist', label: 'Assist' }, { value: 'prompt', label: 'Prompt' }, { value: 'independent', label: 'Independent' }]} />
                {asAt}
            </>
        ),
        allergies: (
            <>
                <PageHeaderFilterCheck label="Show resolved alerts" checked={s.route.q.get('resolved') === '1'} onChange={(c) => s.set({ resolved: c ? '1' : undefined })} />
                {asAt}
            </>
        ),
        clinical: (
            <>
                <PageHeaderFilterSelect icon={CalendarRange} label="Range" value={s.route.q.get('range') ?? '3m'} onChange={(v) => s.set({ range: v === '3m' ? undefined : v })} options={[{ value: '3m', label: 'Last 3 months' }, { value: '12m', label: 'Last 12 months' }]} />
                {asAt}
            </>
        ),
        history: (
            <>
                <PageHeaderFilterSelect icon={CalendarRange} label="Range" value={s.route.q.get('range') ?? '7d'} onChange={(v) => s.set({ range: v === '7d' ? undefined : v, page: undefined })} options={[{ value: '7d', label: 'Last 7 days' }, { value: '30d', label: 'Last 30 days' }]} />
                <PageHeaderFilterSelect label="Outcome" value={s.route.q.get('out') ?? 'all'} allValue="all" onChange={(v) => s.set({ out: v === 'all' ? undefined : v, page: undefined })} options={[{ value: 'all', label: 'All outcomes' }, { value: 'given', label: 'Given or taken' }, { value: 'notgiven', label: 'Not given' }, { value: 'corrected', label: 'Has a correction' }]} />
                {asAt}
            </>
        ),
    };

    const dash = loading ? '—' : failed ? 'Unavailable' : null;
    const meters = (
        <>
            <PageHeaderMeterBlock label="Due now" onClick={() => go('chart', undefined, { mode: undefined })} ariaLabel="View doses due now on the chart">
                <PageHeaderMeterBig>{dash ?? (empty ? 'n/a' : due.length)}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{loading ? 'Loading…' : failed ? 'Try again below' : due.length ? `Until ${due[0].slot === '9:00 am' ? '10:00 am' : '9:00 am'}` : 'Nothing due right now'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Late" tone={!dash && late.length ? 'warning' : 'brand'} onClick={() => go('chart')} ariaLabel="View late doses on the chart">
                <PageHeaderMeterBig>{dash ?? (empty ? 'n/a' : late.length)}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{dash ? '—' : late.length ? `Oldest due ${late[0].slot}` : 'None late'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Recorded today" value={dash || empty || !soFar.length ? undefined : `${recorded.length}/${soFar.length}`} onClick={() => go('history', 'doses')} ariaLabel="View today’s recorded doses in History">
                {dash || empty || !soFar.length ? <PageHeaderMeterBig>{dash ?? 'n/a'}</PageHeaderMeterBig> : <PageHeaderMeterDonut percent={Math.round((recorded.length / soFar.length) * 100)} caption="of doses due so far" />}
                {dash || empty || !soFar.length ? <PageHeaderMeterCaption>{dash ? '—' : 'No doses due yet'}</PageHeaderMeterCaption> : null}
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Medicines" onClick={() => go('medicines')} ariaLabel="View medicines">
                <PageHeaderMeterBig>{dash ?? (empty ? 0 : meds.length - hiddenMeds)}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{dash ? '—' : empty ? 'None on the chart' : hiddenMeds ? `+${hiddenMeds} controlled hidden` : [meds.filter((m) => m.kind === 'prn').length ? `${meds.filter((m) => m.kind === 'prn').length} as needed` : null, meds.filter((m) => m.status === 'awaiting').length ? `${meds.filter((m) => m.status === 'awaiting').length} to check` : null].filter(Boolean).join(' · ') || 'All checked'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Allergies" tone={allergy.status === 'recorded' ? 'critical' : allergy.status === 'nkda' ? 'brand' : 'warning'} onClick={() => go('allergies', 'allergies')} ariaLabel="View allergies">
                <PageHeaderMeterBig>{allergyShort(allergy.status, allergy.entries.length)}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{allergy.status === 'unavailable' ? 'Check the health profile' : allergy.reviewed ? `Reviewed ${allergy.reviewed.on.replace(' 2026', '')}` : 'Not reviewed'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            {onWarfarin ? (
                <PageHeaderMeterBlock label="INR" tone={state === 'inrStale' ? 'warning' : 'brand'} value={state === 'inrStale' ? undefined : latestInr?.tested.replace(' 2026', '')} onClick={() => go('clinical', 'inr')} ariaLabel="View INR results">
                    <PageHeaderMeterBig>{dash ?? (state === 'inrStale' ? 'Test overdue' : latestInr ? latestInr.value.toFixed(1) : 'None')}</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>{dash ? '—' : state === 'inrStale' ? `Was due ${INR[1].next!.replace(' 2026', '')}` : latestInr?.target ? `Target ${latestInr.target[0].toFixed(1)}–${latestInr.target[1].toFixed(1)} · next ${latestInr.next?.replace(' 2026', '')}` : 'No target recorded'}</PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            ) : pid === 'grace' && s.cdView ? (
                <PageHeaderMeterBlock label="Syringe driver" onClick={() => go('clinical', 'driver')} ariaLabel="View the syringe driver">
                    <PageHeaderMeterBig>{s.driverFinished ? 'Finished' : 'Running'}</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>{s.driverFinished ? 'Today' : `Last check ${s.driverChecks[0]?.at ?? '8:05 am'}`}</PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            ) : null}
        </>
    );

    const canRecord = !!recorderIn01(s.route.persona);
    const header = (
        <PageHeader
            variant="profile"
            wrapTitle
            backHref={hrefFor(frontline(s.route.persona) ? '/meds/today' : '/emar/mar', {}, s.route)}
            mark={
                <span className="eh-mark-ring overflow-hidden p-0">
                    <PersonMark pid={pid} size={44} />
                </span>
            }
            title={p.legal}
            titleChip={<PageHeaderStatusChip variant="success">{f.status}</PageHeaderStatusChip>}
            subline={
                <>
                    “{p.pref}” · {f.age} years · NHI {p.nhi} (test)
                    <br />
                    Medication record · {HOUSES[f.house]} · {f.service}
                </>
            }
            actions={
                <>
                    <PageHeaderSearchTrigger placeholder="Find in this record…" onOpen={() => setFindOpen(true)} />
                    {s.can('reports.export') ? (
                        <PageHeaderGlassButton icon={Printer} onClick={() => s.set({ dlg: 'print' })}>
                            Print MAR
                        </PageHeaderGlassButton>
                    ) : null}
                    {canRecord && !empty ? (
                        <PageHeaderPrimaryButton icon={Pill} onClick={() => open01(`dose-pick:${pid}`)}>
                            Record dose
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={meters}
            filters={filters[tab]}
            rail={
                <PageHeaderRail<Tab>
                    items={RAIL.map((r) => ({ key: r.key, label: r.label, icon: r.icon, count: r.key === 'history' && pending && s.can('correct') ? pending : undefined }))}
                    value={tab}
                    onSelect={(k) => go(k)}
                    onFind={() => setFindOpen(true)}
                    ariaLabel="Medication record sections"
                />
            }
        />
    );

    let body: React.ReactNode;
    if (failed) body = <FailedBody />;
    else if (tab === 'chart') body = <ChartTab pid={pid} view={view} />;
    else if (tab === 'medicines') body = <MedicinesTab pid={pid} view={view} />;
    else if (tab === 'support') body = <SupportTab pid={pid} view={view} />;
    else if (tab === 'allergies') body = <AllergiesTab pid={pid} view={view} />;
    else if (tab === 'clinical') body = <ClinicalTab pid={pid} view={view} />;
    else body = <HistoryTab pid={pid} view={view} />;

    return (
        <Shell crumbs={crumbs}>
            {header}
            <SubTabs
                tabs={views.map((v) => ({ key: v.key, label: v.label, icon: VIEW_ICON[v.key] ?? ClipboardList, count: v.key === 'corrections' && pending && s.can('correct') ? pending : undefined }))}
                active={view}
                onTab={(k) => go(tab, k === views[0].key ? undefined : k)}
                hrefOf={(k) => hrefFor(s.route.path, { client_id: String(f.clientId), tab: tab === 'chart' ? undefined : tab, view: k === views[0].key ? undefined : k }, s.route)}
                label={`${RAIL.find((r) => r.key === tab)!.label} views`}
            />
            <div id="p02-record-panel" role="tabpanel" className="flex min-w-0 flex-col gap-5">
                {f.moved && me.houses.includes(f.house) ? (
                    <Notice tone="info" icon={Home} title={`${p.pref} moved here from ${HOUSES[f.moved.from]} today at 8:30 am`}>
                        Doses before the move were given at {HOUSES[f.moved.from]} — History shows the house for each dose. {HOUSES[f.moved.from]} staff can no longer open this record. Medicines and supplies moved with {p.pref}; stock is checked in Stock &amp; pharmacy.
                    </Notice>
                ) : null}
                {state === 'stale' ? (
                    <Notice tone="warning" icon={AlertTriangle} title="This record may be out of date" actions={<Button size="sm" variant="outline" onClick={() => s.toast('info', 'Refreshing… (mockup: set the page state back to Normal).')}><RefreshCw className="size-4" /> Try again</Button>}>
                        We couldn’t refresh it at 9:12 am. It shows what was known at 8:40 am. Check Meds today before recording, and try again.
                    </Notice>
                ) : null}
                {body}
            </div>
            <TabSearchPalette
                open={findOpen}
                onClose={() => setFindOpen(false)}
                groups={groups}
                onTab={(k) => {
                    const [t, v] = k.split('.');
                    setFindOpen(false);
                    go(t as Tab, v === VIEWS[t as Tab][0].key ? undefined : v);
                }}
                testIdPrefix="p02-record"
                searchLabel="Find a section of this record"
            />
        </Shell>
    );
}

const CD_MEDS = new Set(['methylphenidate', 'clonazepam']);
const MED_CD = (k: string) => CD_MEDS.has(k);

function FailedBody() {
    const s = useP02();
    return (
        <Card className="p-2">
            <ErrorState
                title="We couldn’t load this medication record"
                message="Nothing is shown rather than an incomplete chart. Try again — if it keeps failing, use the printed MAR for the next round and tell the house lead."
                onRetry={() => s.setViewer({ state: 'normal' })}
            />
        </Card>
    );
}

export { AllergySummary };
