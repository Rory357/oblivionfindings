/* Safety & oversight › Medication errors — /emar/errors (Main, Q1). Inside
 * P08a v1’s approved Safety & oversight frame (PageHeader, the seven-view
 * rail); P08b designs only this view, with its own meters and tier-2 views:
 * To triage · Investigating · Actions · Incidents to close · Closed · Trends.
 * Support workers see their own reports. Every row opens on click, and ⋯,
 * right-click and the menu key give the same menu. */
import { PersonDisc } from '@/components/lists/entity-cells';
import { compactMenu, EntityContextMenu, type MenuItem } from '@/components/lists/entity-menu';
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
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    AlertOctagon,
    AlertTriangle,
    Archive,
    CheckCheck,
    ClipboardCheck,
    Clock3,
    Download,
    Flag,
    Gauge,
    Home,
    Inbox,
    ListChecks,
    LockKeyhole,
    MessageCircleMore,
    MessageSquarePlus,
    NotebookPen,
    Plus,
    RefreshCw,
    Repeat,
    RotateCcw,
    Search,
    Shield,
    ShieldCheck,
    Siren,
    TrendingUp,
    UserCheck,
    UserRound,
    type LucideIcon,
} from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';
import { HOUSES, PEOPLE, PERSONAS, TRIAGE_LABEL, TYPE_LABEL, type Action, type ErrType, type House, type MedError, type MedIncident, type PersonaId } from '../data';
import { useOpen } from '../host';
import {
    actionState,
    actionTone,
    band,
    canActOn,
    canCloseError,
    canCloseIncident,
    canReport,
    canSeeAll,
    cdView,
    closeBlockers,
    daysUntil,
    errorsIn,
    harmTone,
    hasHarm,
    inLast90,
    INC_STATUS,
    incidentsIn,
    incidentState,
    incidentTitle,
    isReady,
    labelIso,
    medsText,
    openActions,
    redacted,
    stageState,
    lowerFirst,
    weekOf,
    WEEKS,
    WHO_CLOSES_INCIDENTS,
} from '../model';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
import { DesignNote, Notice, StateLine, SubTabs, Wrap } from '../ui';

type View = 'overview' | 'followups' | 'overrides' | 'errors' | 'handovers' | 'eligibility' | 'emergency';
const VIEWS: { key: View; label: string; icon: LucideIcon; pkg: string }[] = [
    { key: 'overview', label: 'Overview', icon: Gauge, pkg: 'P09' },
    { key: 'followups', label: 'Follow-ups', icon: Flag, pkg: 'P08a (approved)' },
    { key: 'overrides', label: 'Witness overrides', icon: Shield, pkg: 'P07b (approved)' },
    { key: 'errors', label: 'Medication errors', icon: AlertOctagon, pkg: 'P08b' },
    { key: 'handovers', label: 'Handovers', icon: Repeat, pkg: 'P08a (approved)' },
    { key: 'eligibility', label: 'Staff eligibility', icon: UserCheck, pkg: 'P11 (approved)' },
    { key: 'emergency', label: 'Emergency access', icon: LockKeyhole, pkg: 'P10' },
];
type Sub = 'triage' | 'investigating' | 'actions' | 'incidents' | 'closed' | 'trends' | 'mine';
const SUBS: { key: Sub; label: string; icon: LucideIcon }[] = [
    { key: 'triage', label: 'To triage', icon: Inbox },
    { key: 'investigating', label: 'Investigating', icon: Search },
    { key: 'actions', label: 'Actions', icon: ListChecks },
    { key: 'incidents', label: 'Incidents to close', icon: Siren },
    { key: 'closed', label: 'Closed', icon: Archive },
    { key: 'trends', label: 'Trends', icon: TrendingUp },
];

/** The same menu for ⋯, right-click and the menu key (LIST_STYLE_GUIDE §1). */
export function useCtx(icon: LucideIcon = AlertOctagon) {
    const [ctx, setCtx] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const openAt = (e: MouseEvent, title: string, items: MenuItem[]) => {
        e.preventDefault();
        const r = (e.currentTarget as HTMLElement).getBoundingClientRect();
        setCtx({ x: e.clientX || r.left + 24, y: e.clientY || r.top + r.height / 2, title, items });
    };
    return { openAt, node: ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={icon} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null };
}
const stop = (fn: () => void) => (e: MouseEvent) => {
    e.stopPropagation();
    fn();
};
const outside = (msg: string) => () => window.dispatchEvent(new CustomEvent('preview:toast', { detail: msg }));
const isOpen = (e: MedError) => e.stage === 'investigating' || e.stage === 'actions';

/** The error row menu, shared by every list: the next steps for who you are, then the incident and the person’s record. */
export function errorMenu(e: MedError, p: PersonaId, open: (s: string) => void, go: (path: string, params?: Record<string, string | undefined>) => void): MenuItem[] {
    const act = canActOn(p, e);
    return compactMenu([
        { label: 'Open the report', icon: AlertOctagon, onClick: () => open(`error:${e.id}`) },
        act && e.stage === 'triage' && { label: 'Triage it', icon: ClipboardCheck, onClick: () => open(`triage:${e.id}`) },
        act && isOpen(e) && { label: 'Add a note', icon: NotebookPen, onClick: () => open(`note:${e.id}`) },
        act && isOpen(e) && { label: 'Add an action', icon: ListChecks, onClick: () => open(`action:${e.id}`) },
        act && e.stage !== 'closed' && e.reach !== 'no' && { label: e.disclosure?.state === 'told' ? 'Update telling the person' : 'Record telling the person', icon: MessageCircleMore, onClick: () => open(`disclose:${e.id}`) },
        canCloseError(p, e) && isOpen(e) && !closeBlockers(e).length && { label: 'Close the error', icon: CheckCheck, onClick: () => open(`close:${e.id}`) },
        act && e.stage === 'closed' && { label: 'Reopen', icon: RotateCcw, onClick: () => open(`reopen:${e.id}`) },
        canReport(p) && !redacted(p, e) && e.stage !== 'closed' && { label: 'Add your account', icon: MessageSquarePlus, onClick: () => open(`account:${e.id}`) },
        { separator: true },
        !!e.incident && canSeeAll(p) && { label: `Open incident ${e.incident}`, icon: Siren, onClick: () => go('/incidents', { tab: 'awaiting', open: undefined, sub: undefined }) },
        { label: `Open ${PEOPLE[e.pid].pref}’s medication record`, icon: UserRound, onClick: outside(`Opens ${PEOPLE[e.pid].pref}’s medication record (P02) — outside this preview. Its Safety tab lists this report.`) },
    ]);
}

export function ErrorsPage() {
    const s = useStore();
    const r = s.route;
    const p = r.persona;
    const me = PERSONAS[p];
    const open = useOpen();
    const [search, setSearch] = useState('');
    const all = canSeeAll(p);
    const subs = all ? SUBS : [{ key: 'mine' as Sub, label: 'Your reports', icon: MessageSquarePlus }];
    const sub = (subs.some((x) => x.key === r.q.get('sub')) ? r.q.get('sub') : subs[0].key) as Sub;
    const houseQ = r.q.get('house') as House | null;
    const houses = me.houses.filter((h) => !houseQ || h === houseQ);
    const scn = r.scenario;
    const loading = scn === 'loading';
    const failed = scn === 'unavailable';
    const empty = scn === 'empty';
    const dash = loading ? '—' : failed ? 'Unavailable' : null;
    const q = search.trim().toLowerCase();
    const errs = empty ? [] : errorsIn(s.rt, p).filter((e) => houses.includes(PEOPLE[e.pid].house));
    const match = (e: MedError) => !q || `${PEOPLE[e.pid].legal} ${PEOPLE[e.pid].pref} ${e.id} ${TYPE_LABEL[e.type]} ${redacted(p, e) ? '' : medsText(p, e)}`.toLowerCase().includes(q);
    const triage = errs.filter((e) => e.stage === 'triage').sort((a, b) => a.triageDueIso.localeCompare(b.triageDueIso));
    const triageLate = triage.filter((e) => daysUntil(e.triageDueIso) < 0);
    const investigating = errs.filter((e) => e.stage === 'investigating').sort((a, b) => (a.investigateDueIso ?? '').localeCompare(b.investigateDueIso ?? ''));
    const inActions = errs.filter((e) => e.stage === 'actions').sort((a, b) => Number(!!closeBlockers(a).length) - Number(!!closeBlockers(b).length));
    const actionRows = errs.filter(isOpen).flatMap((e) => openActions(e).map((a) => ({ e, a }))).sort((x, y) => x.a.dueIso.localeCompare(y.a.dueIso));
    const actionsDue = actionRows.filter((x) => daysUntil(x.a.dueIso) <= 7);
    const actionsLate = actionRows.filter((x) => daysUntil(x.a.dueIso) < 0);
    const harmOpen = errs.filter((e) => e.stage !== 'closed' && hasHarm(e));
    const harmBad = harmOpen.filter((e) => ['moderate', 'severe', 'death'].includes(e.harm));
    const closed = errs.filter((e) => e.stage === 'closed' && inLast90(e)).sort((a, b) => b.occurredIso.localeCompare(a.occurredIso));
    const incs = empty ? [] : incidentsIn(s.rt, p).filter((i) => houses.includes(PEOPLE[i.pid].house));
    const ready = incs.filter((i) => isReady(s.rt, i));
    const mine = [...errs].sort((a, b) => Number(a.stage === 'closed') - Number(b.stage === 'closed') || b.occurredIso.localeCompare(a.occurredIso));
    const houseText = houses.map((h) => HOUSES[h]).join(' and ');
    const goSub = (k: Sub) => s.set({ sub: k === subs[0].key ? undefined : k, open: undefined });

    const asAt =
        scn === 'stale' ? (
            <PageHeaderFilterButton icon={AlertTriangle} active onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}>
                As at 8:40 am · couldn’t refresh — try again
            </PageHeaderFilterButton>
        ) : (
            <PageHeaderFilterButton icon={Clock3} onClick={() => s.toast('success', 'Up to date · as at 9:12 am · Pacific/Auckland')}>
                As at 9:12 am · Pacific/Auckland
            </PageHeaderFilterButton>
        );
    const meters = all ? (
        <>
            <PageHeaderMeterBlock label="To triage" tone={!dash && triageLate.length ? 'critical' : !dash && triage.length ? 'warning' : 'brand'} onClick={() => goSub('triage')} ariaLabel={`View ${triage.length} errors to triage`}>
                <PageHeaderMeterBig>{dash ?? triage.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{dash ? '—' : triageLate.length ? `${triageLate.length} overdue` : triage.length ? `Due ${labelIso(triage[0].triageDueIso)}` : 'None waiting'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Open with harm" tone={!dash && harmBad.length ? 'warning' : 'brand'} onClick={() => goSub('investigating')} ariaLabel={`${harmOpen.length} open errors that reached the person with harm`}>
                <PageHeaderMeterBig>{dash ?? harmOpen.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{dash ? '—' : harmOpen.length ? `${harmBad.length} moderate or worse` : 'None open'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Investigating" onClick={() => goSub('investigating')} ariaLabel={`View ${investigating.length} errors being investigated`}>
                <PageHeaderMeterBig>{dash ?? investigating.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{dash ? '—' : investigating.length ? `Next due ${investigating[0].investigateDue}` : 'None'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Actions due" tone={!dash && actionsLate.length ? 'critical' : 'brand'} onClick={() => goSub('actions')} ariaLabel={`View ${actionsDue.length} actions due in the next 7 days or overdue`}>
                <PageHeaderMeterBig>{dash ?? actionsDue.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{dash ? '—' : actionsLate.length ? `${actionsLate.length} overdue` : actionsDue.length ? `Next ${actionsDue[0].a.due}` : 'None in 7 days'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Incidents to close" tone={!dash && ready.length && canCloseIncident(p) ? 'warning' : 'brand'} onClick={() => goSub('incidents')} ariaLabel={`View ${ready.length} incidents ready to close`}>
                <PageHeaderMeterBig>{dash ?? ready.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{dash ? '—' : !ready.length ? 'None ready' : canCloseIncident(p) ? 'Ready for you to close' : 'For a manager to close'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Closed" onClick={() => goSub('closed')} ariaLabel={`View ${closed.length} errors closed, from the last 90 days`}>
                <PageHeaderMeterBig>{dash ?? closed.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{dash ? '—' : 'Last 90 days'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    ) : (
        <>
            <PageHeaderMeterBlock label="Your open reports" ariaLabel={`${mine.filter((e) => e.stage !== 'closed').length} of your reports are open`} onClick={() => goSub('mine')}>
                <PageHeaderMeterBig>{dash ?? mine.filter((e) => e.stage !== 'closed').length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{dash ? '—' : 'Being looked into'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Closed" ariaLabel={`${mine.filter((e) => e.stage === 'closed').length} of your reports are closed`} onClick={() => goSub('mine')}>
                <PageHeaderMeterBig>{dash ?? mine.filter((e) => e.stage === 'closed').length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{dash ? '—' : mine.some((e) => e.stage === 'closed') ? 'You were told the outcome' : 'None yet'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    );
    const header = (
        <PageHeader
            icon={ShieldCheck}
            title="Safety & oversight"
            titleChip={<PageHeaderStatusChip variant="neutral">{me.houses.length > 1 && !houseQ ? '2 houses' : '1 house'}</PageHeaderStatusChip>}
            subline={`Medication errors at ${houses.length > 1 ? `your ${houses.length} houses` : houseText} · times in NZDT`}
            actions={
                <>
                    <PageHeaderSearch value={search} onChange={setSearch} placeholder={all ? 'Search people or MED numbers…' : 'Search your reports…'} />
                    {all && !failed ? (
                        <PageHeaderGlassButton icon={Download} onClick={() => open('export')}>
                            Export
                        </PageHeaderGlassButton>
                    ) : null}
                    {canReport(p) && !failed ? (
                        <PageHeaderPrimaryButton icon={Plus} onClick={() => open('report')}>
                            Report an error
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={meters}
            filters={
                <>
                    {me.houses.length > 1 ? <PageHeaderFilterSelect icon={Home} label="House" value={houseQ ?? 'all'} allValue="all" onChange={(v) => s.set({ house: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All your houses' }, ...me.houses.map((h) => ({ value: h, label: HOUSES[h] }))]} /> : null}
                    {asAt}
                </>
            }
            rail={
                <PageHeaderRail<View>
                    items={VIEWS.map((v) => ({ key: v.key, label: v.label, icon: v.icon, ...(v.key === 'errors' && triageLate.length && !dash ? { count: triageLate.length, alert: true } : {}) }))}
                    value="errors"
                    onSelect={(k) => (k === 'errors' ? goSub(subs[0].key) : s.toast('info', `${VIEWS.find((v) => v.key === k)!.label} is designed in ${VIEWS.find((v) => v.key === k)!.pkg} — outside this preview.`))}
                    ariaLabel="Safety & oversight views"
                />
            }
        />
    );

    let body: ReactNode;
    if (loading)
        body = (
            <Card className="p-4" aria-busy="true" aria-label="Loading medication errors">
                <SkeletonTable rows={5} columns={5} />
            </Card>
        );
    else if (failed)
        body = (
            <Card className="p-2">
                <ErrorState title="We couldn’t load medication errors" message="Nothing is shown rather than an incomplete list. Errors waiting for triage are still waiting — try again, or ask the house lead on shift." onRetry={() => s.setViewer({ scenario: 'normal' })} />
            </Card>
        );
    else if (empty)
        body = (
            <Card className="p-2">
                <EmptyState
                    icon={AlertOctagon}
                    title={all ? 'No medication errors reported' : 'You haven’t reported any medication errors'}
                    description={all ? 'Reports come from the dose, from a check that wasn’t right, from the person’s record and from here. Each one is triaged, looked into and closed, with the person told.' : 'If something goes wrong with a medicine, report it from the dose or from here. The house lead looks into it and tells you the outcome.'}
                    action={canReport(p) ? <Button onClick={() => open('report')}>Report an error</Button> : undefined}
                />
            </Card>
        );
    else if (sub === 'mine')
        body = <ErrorSection title="Your reports" caption="open first, then closed · you see the reports you made or added to" rows={mine.filter(match)} emptyTitle="No reports match this search" emptyText="Clear the search to see your reports." />;
    else if (sub === 'triage')
        body = <ErrorSection title="To triage" caption={`oldest first · a report is triaged ${lowerFirst(TRIAGE_LABEL[s.rt.org.value])} (Settings)`} rows={triage.filter(match)} emptyTitle="Nothing to triage" emptyText="New reports appear here until someone who manages errors triages them." />;
    else if (sub === 'investigating')
        body = <ErrorSection title="Investigating" caption="due soonest first · notes are added, never edited" rows={investigating.filter(match)} emptyTitle="Nothing being investigated" emptyText="An error moves here when it’s triaged, with an owner and a due date." />;
    else if (sub === 'actions')
        body = (
            <>
                <ActionSection rows={actionRows.filter((x) => match(x.e))} />
                <ErrorSection title="Errors at the actions stage" caption="ready to close first · closing needs every action done and telling the person recorded" rows={inActions.filter(match)} emptyTitle="No errors at the actions stage" emptyText="An error moves here when its first action is added." />
            </>
        );
    else if (sub === 'incidents') body = <IncidentSection rows={incs.filter((i) => !q || `${i.id} ${i.ref} ${PEOPLE[i.pid].legal}`.toLowerCase().includes(q))} />;
    else if (sub === 'closed') body = <ErrorSection title="Closed" caption={`${closed.length ? `1–${closed.length} of ${closed.length}` : 'none'} · newest first · pages of 50 from the server · reopen with a reason`} rows={closed.filter(match)} emptyTitle="Nothing closed in the last 90 days" emptyText="Closed errors stay here, with the close note and who closed them." closedView />;
    else body = <Trends errs={errs} />;

    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/emar/errors' }, { title: 'Safety & oversight', href: hrefFor('/emar/errors', {}, r) }, { title: 'Medication errors' }]}>
            {header}
            <SubTabs
                tabs={subs.map((x) => ({ key: x.key, label: x.label, icon: x.icon, ...(dash || empty ? {} : x.key === 'triage' && triage.length ? { warningCount: triage.length } : x.key === 'incidents' && ready.length ? { count: ready.length } : {}) }))}
                active={sub}
                onTab={(k) => goSub(k as Sub)}
                hrefOf={(k) => hrefFor('/emar/errors', { sub: k === subs[0].key ? undefined : k }, r)}
                label="Medication error views"
            />
            <div id="p08b-errors-panel" role="tabpanel" aria-label={subs.find((x) => x.key === sub)!.label} className="flex min-w-0 flex-col gap-5">
                {scn === 'stale' ? (
                    <Notice tone="warning" icon={AlertTriangle} title="This may be out of date" actions={<Button size="sm" variant="outline" onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}><RefreshCw className="size-4" /> Try again</Button>}>
                        We couldn’t refresh at 9:12 am. It shows what was known at 8:40 am. A report made since then may not be here yet.
                    </Notice>
                ) : null}
                {body}
                <DesignNote title="Design note — medication errors (Main, Q1–Q10)">
                    <p>
                        Today this is a separate page with a PageHero, “A quiet register is a good sign”, severity from NCC-MERP letters, and a report form with no medicine picker — so errors are never concealed and the free text is copied into the incident, Control Room, Tasks and the CSV (AUDIT 1, 4). Here it is a view of Safety & oversight. Reports start from the dose (P01), a check that wasn’t right (P08a), the person’s record, Meds today or this page. Outside the error, only the neutral summary is shown.
                    </p>
                </DesignNote>
            </div>
        </Shell>
    );
}

/* ───────────── a list of errors ───────────── */
function ErrorSection({ title, caption, rows, emptyTitle, emptyText, closedView }: { title: string; caption: string; rows: MedError[]; emptyTitle: string; emptyText: string; closedView?: boolean }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const ctx = useCtx();
    const menu = (e: MedError) => errorMenu(e, p, open, s.go);
    const act = (e: MedError) => {
        if (!canActOn(p, e)) return null;
        if (e.stage === 'triage')
            return (
                <Button size="sm" data-return={`tri-${e.id}`} onClick={stop(() => open(`triage:${e.id}`))}>
                    Triage it
                </Button>
            );
        if (isOpen(e) && !closeBlockers(e).length && canCloseError(p, e))
            return (
                <Button size="sm" variant="outline" data-return={`cls-${e.id}`} onClick={stop(() => open(`close:${e.id}`))}>
                    Close it
                </Button>
            );
        return null;
    };
    return (
        <section className="flex flex-col gap-2.5" aria-label={title}>
            <ListCaption title={title} caption={`${rows.length} shown · ${caption}`} />
            {rows.length ? (
                <EntityTable<MedError>
                    rows={rows}
                    rowKey={(e) => e.id}
                    rowHeight="content"
                    minWidth={980}
                    identityLabel="Person"
                    identityWidth="1.5fr"
                    identity={(e) => ({ mark: <PersonDisc name={PEOPLE[e.pid].legal} size={30} />, name: PEOPLE[e.pid].legal, subline: <Wrap>{e.id} · {HOUSES[PEOPLE[e.pid].house]}</Wrap> })}
                    columns={[
                        {
                            key: 'what',
                            label: 'What happened',
                            width: '1.8fr',
                            cell: (e) => (
                                <span className="flex flex-col gap-0.5 py-0.5 text-[12.5px]">
                                    <span className="font-semibold">{TYPE_LABEL[e.type]}</span>
                                    <StateLine icon={redacted(p, e) ? LockKeyhole : undefined}>{medsText(p, e)}</StateLine>
                                    <StateLine>Happened {e.occurred}</StateLine>
                                </span>
                            ),
                        },
                        {
                            key: 'harm',
                            label: 'Reach & harm',
                            width: '1.2fr',
                            cell: (e) => (
                                <span className="flex flex-col items-start gap-1 py-0.5">
                                    <StatusBadge variant={harmTone(e).variant} className="rounded-[8px]">
                                        {harmTone(e).label}
                                    </StatusBadge>
                                    {e.incident ? <StateLine icon={Siren}>Incident {e.incident}</StateLine> : null}
                                </span>
                            ),
                        },
                        {
                            key: 'stage',
                            label: closedView ? 'Closed' : 'Where it’s at',
                            width: '1.5fr',
                            cell: (e) =>
                                closedView && e.closed ? (
                                    <span className="flex flex-col gap-0.5 text-[12.5px]">
                                        <span>
                                            {e.closed.by}, {e.closed.at}
                                        </span>
                                        <StateLine>{redacted(p, e) ? 'Close note needs controlled-medicine access' : `“${e.closed.note}”`}</StateLine>
                                    </span>
                                ) : (
                                    <span className="flex flex-col items-start gap-1 py-0.5">
                                        <StatusBadge variant={stageState(e).variant} className="rounded-[8px]">
                                            {stageState(e).label}
                                        </StatusBadge>
                                        {stageState(e).line ? <StateLine>{stageState(e).line}</StateLine> : null}
                                    </span>
                                ),
                        },
                        { key: 'act', label: '', width: '120px', align: 'right', cell: act },
                    ]}
                    actionsFor={menu}
                    onOpen={(e) => open(`error:${e.id}`)}
                    onRowContextMenu={(ev, e) => ctx.openAt(ev, `${e.id} · ${PEOPLE[e.pid].pref}`, menu(e))}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={AlertOctagon} title={emptyTitle} description={emptyText} />
                </Card>
            )}
            {ctx.node}
        </section>
    );
}

/* ───────────── open actions (Q5) ───────────── */
type ActionRow = { e: MedError; a: Action };
function ActionSection({ rows }: { rows: ActionRow[] }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const ctx = useCtx(ListChecks);
    const menu = (x: ActionRow) => compactMenu([{ label: 'Open the error’s actions', icon: AlertOctagon, onClick: () => open(`error:${x.e.id}:actions`) }, canActOn(p, x.e) && { label: 'Mark it done', icon: CheckCheck, onClick: () => open(`done:${x.e.id}:${x.a.id}`) }]);
    const hide = (x: ActionRow) => redacted(p, x.e);
    return (
        <section className="flex flex-col gap-2.5" aria-label="Open actions">
            <ListCaption title="Open actions" caption={`${rows.length} shown · due soonest first · each has an owner and a due date, and shows in their All Tasks`} />
            {rows.length ? (
                <EntityTable<ActionRow>
                    rows={rows}
                    rowKey={(x) => `${x.e.id}:${x.a.id}`}
                    rowHeight="content"
                    minWidth={940}
                    identityLabel="Action"
                    identityWidth="2fr"
                    identity={(x) => ({ icon: ListChecks, name: `${x.a.id} · ${PEOPLE[x.e.pid].pref}`, subline: <Wrap><span className="text-foreground">{hide(x) ? 'An action on a controlled-medicine error' : x.a.what}</span> · {x.e.id}</Wrap> })}
                    columns={[
                        { key: 'owner', label: 'Owner', width: '1fr', cell: (x) => <span className="text-[12.5px]">{x.a.owner}</span> },
                        {
                            key: 'due',
                            label: 'Due',
                            width: '1fr',
                            cell: (x) => (
                                <span className="flex flex-col items-start gap-1 py-0.5 text-[12.5px]">
                                    <span className="font-semibold">{x.a.due}</span>
                                    <StatusBadge variant={actionTone(x.a.dueIso)} className="rounded-[8px]">
                                        {actionState(x.a.dueIso)}
                                    </StatusBadge>
                                </span>
                            ),
                        },
                        {
                            key: 'act',
                            label: '',
                            width: '130px',
                            align: 'right',
                            cell: (x) =>
                                canActOn(p, x.e) ? (
                                    <Button size="sm" variant="outline" data-return={`done-${x.a.id}`} onClick={stop(() => open(`done:${x.e.id}:${x.a.id}`))}>
                                        Mark it done
                                    </Button>
                                ) : null,
                        },
                    ]}
                    actionsFor={menu}
                    onOpen={(x) => open(`error:${x.e.id}:actions`)}
                    onRowContextMenu={(ev, x) => ctx.openAt(ev, `${x.a.id} · ${x.e.id}`, menu(x))}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={ListChecks} title="No open actions" description="Actions added during an investigation appear here until they’re done." />
                </Card>
            )}
            {ctx.node}
        </section>
    );
}

/* ───────────── incidents the medication side closes (Q6; P07b Q4) ───────────── */
function IncidentSection({ rows }: { rows: MedIncident[] }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const ctx = useCtx(Siren);
    const closer = canCloseIncident(p);
    const readyRows = rows.filter((i) => isReady(s.rt, i));
    const waiting = rows.filter((i) => i.status !== 'closed' && !isReady(s.rt, i));
    const done = rows.filter((i) => i.status === 'closed');
    const srcLabel = (i: MedIncident) => (i.source === 'error' ? `From ${i.ref} · ${PEOPLE[i.pid].pref}` : i.source === 'loss' ? `From loss ${i.ref} · controlled register` : `From discrepancy ${i.ref} · controlled register`);
    const menu = (i: MedIncident) =>
        compactMenu([
            i.source === 'error' && { label: `Open ${i.ref}`, icon: AlertOctagon, onClick: () => open(`error:${i.ref}:incident`) },
            i.source !== 'error' && cdView(p) && { label: `Open ${i.ref} in the controlled register`, icon: LockKeyhole, onClick: outside(`Opens ${i.ref} in Stock & controlled drugs (P07b) — outside this preview.`) },
            closer && isReady(s.rt, i) && { label: 'Review and close', icon: CheckCheck, onClick: () => open(`incclose:${i.id}`) },
            { separator: true },
            { label: 'Open in Incidents', icon: Siren, onClick: () => s.go('/incidents', { tab: 'awaiting', open: undefined, sub: undefined }) },
        ]);
    const table = (list: MedIncident[], label: string) => (
        <EntityTable<MedIncident>
            rows={list}
            rowKey={(i) => i.id}
            rowHeight="content"
            minWidth={960}
            identityLabel={label}
            identityWidth="1.9fr"
            identity={(i) => ({ icon: Siren, name: i.id, subline: <Wrap><span className="text-foreground">{incidentTitle(s.rt, i)}</span> · {srcLabel(i)}</Wrap> })}
            columns={[
                { key: 'inc', label: 'In Incidents', width: '1fr', cell: (i) => <span className="text-[12.5px]">{INC_STATUS[i.status]}</span> },
                {
                    key: 'state',
                    label: 'Where it’s at',
                    width: '1.7fr',
                    cell: (i) => (
                        <span className="flex flex-col items-start gap-1 py-0.5">
                            <StatusBadge variant={incidentState(s.rt, i).variant} className="rounded-[8px]">
                                {incidentState(s.rt, i).label}
                            </StatusBadge>
                            {incidentState(s.rt, i).line ? <StateLine>{incidentState(s.rt, i).line}</StateLine> : null}
                        </span>
                    ),
                },
                {
                    key: 'act',
                    label: '',
                    width: '150px',
                    align: 'right',
                    cell: (i) =>
                        closer && isReady(s.rt, i) ? (
                            <Button size="sm" data-return={`inc-${i.id}`} onClick={stop(() => open(`incclose:${i.id}`))}>
                                Review and close
                            </Button>
                        ) : null,
                },
            ]}
            actionsFor={menu}
            onOpen={(i) => (i.source === 'error' ? open(`error:${i.ref}:incident`) : closer && isReady(s.rt, i) ? open(`incclose:${i.id}`) : s.toast('info', `${i.id} opens in Incidents — outside this preview.`))}
            onRowContextMenu={(ev, i) => ctx.openAt(ev, i.id, menu(i))}
        />
    );
    return (
        <>
            <section className="flex flex-col gap-2.5" aria-label="Ready to close">
                <ListCaption title="Ready to close" caption={`${readyRows.length} shown · the medication side is closed · ${closer ? 'you review and close them here or in Incidents, with the same checks' : `closed by ${WHO_CLOSES_INCIDENTS}`}`} />
                {readyRows.length ? (
                    table(readyRows, 'Incident')
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={Siren} title="No incidents ready to close" description="An incident is ready when the medication error, discrepancy or loss it came from is closed." />
                    </Card>
                )}
            </section>
            <section className="flex flex-col gap-2.5" aria-label="Waiting on the medication side">
                <ListCaption title="Waiting on the medication side" caption={`${waiting.length} shown · each closes when its error, discrepancy or loss closes`} />
                {waiting.length ? (
                    table(waiting, 'Incident')
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={Siren} title="No incidents waiting" description="Incidents linked to an open error, discrepancy or loss appear here." />
                    </Card>
                )}
            </section>
            {done.length ? (
                <section className="flex flex-col gap-2.5" aria-label="Closed today">
                    <ListCaption title="Closed today" caption={`${done.length} shown · closed through Incidents`} />
                    {table(done, 'Incident')}
                </section>
            ) : null}
            {ctx.node}
            {!closer ? (
                <Notice tone="neutral" title={`Incidents are closed by ${WHO_CLOSES_INCIDENTS}`}>
                    When you close a medication error, its incident gets your close note and waits here and in Incidents as “Ready to close — medication error closed”.
                </Notice>
            ) : null}
        </>
    );
}

/* ───────────── trends (Q10) — one number each ───────────── */
function Trends({ errs }: { errs: MedError[] }) {
    const last90 = errs.filter(inLast90);
    const count = (list: MedError[], b: ReturnType<typeof band>) => list.filter((e) => band(e) === b).length;
    const weeks = [...WEEKS].reverse().map((w) => {
        const inWeek = errs.filter((e) => weekOf(e.occurredIso) === w);
        return { w, harm: count(inWeek, 'harm'), noHarm: count(inWeek, 'noHarm'), near: count(inWeek, 'nearMiss'), all: inWeek.length };
    });
    const sum = (k: 'harm' | 'noHarm' | 'near' | 'all') => weeks.reduce((n, x) => n + x[k], 0);
    const reached = last90.filter((e) => e.reach !== 'no');
    const types = (Object.keys(TYPE_LABEL) as ErrType[]).map((t) => ({ t, n: last90.filter((e) => e.type === t).length })).filter((x) => x.n);
    return (
        <>
            <section className="flex flex-col gap-2.5" aria-label="Governance count">
                <ListCaption title="Governance count — the last 90 days" caption="by when it happened, in NZ time · the same numbers as the lists and the governance report" />
                <div className="grid gap-3 md:grid-cols-3">
                    <Card className="gap-1 p-4">
                        <p className="text-caption">Reached the person</p>
                        <p className="text-2xl font-semibold tabular-nums">{reached.length}</p>
                        <p className="text-caption">{reached.filter(hasHarm).length} with harm · {reached.length - reached.filter(hasHarm).length} with no harm or not known yet</p>
                    </Card>
                    <Card className="gap-1 p-4">
                        <p className="text-caption">Near misses — didn’t reach the person</p>
                        <p className="text-2xl font-semibold tabular-nums">{last90.length - reached.length}</p>
                        <p className="text-caption">Counted separately, and reported with the rest</p>
                    </Card>
                    <Card className="gap-1 p-4">
                        <p className="text-caption">Closed</p>
                        <p className="text-2xl font-semibold tabular-nums">{last90.filter((e) => e.stage === 'closed').length}</p>
                        <p className="text-caption">{last90.filter((e) => e.stage !== 'closed').length} still open</p>
                    </Card>
                </div>
            </section>
            <section className="flex flex-col gap-2.5" aria-label="Each week">
                <ListCaption title="Each week" caption="Monday to Sunday, NZ time · by when it happened, not when it was reported · the last 8 weeks" />
                <Card className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Week</TableHead>
                                <TableHead className="text-right">Reached — harm</TableHead>
                                <TableHead className="text-right">Reached — no harm or not known</TableHead>
                                <TableHead className="text-right">Near misses</TableHead>
                                <TableHead className="text-right">All</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {weeks.map((x) => (
                                <TableRow key={x.w}>
                                    <TableCell>Week of {labelIso(x.w)}{x.w === WEEKS[WEEKS.length - 1] ? ' (so far)' : ''}</TableCell>
                                    <TableCell className="text-right tabular-nums">{x.harm}</TableCell>
                                    <TableCell className="text-right tabular-nums">{x.noHarm}</TableCell>
                                    <TableCell className="text-right tabular-nums">{x.near}</TableCell>
                                    <TableCell className="text-right tabular-nums font-semibold">{x.all}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell>8 weeks</TableCell>
                                <TableCell className="text-right tabular-nums">{sum('harm')}</TableCell>
                                <TableCell className="text-right tabular-nums">{sum('noHarm')}</TableCell>
                                <TableCell className="text-right tabular-nums">{sum('near')}</TableCell>
                                <TableCell className="text-right tabular-nums">{sum('all')}</TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </Card>
            </section>
            <section className="flex flex-col gap-2.5" aria-label="What went wrong">
                <ListCaption title="What went wrong — the last 90 days" caption="one reason per report · controlled-medicine errors are counted, not named" />
                <Card className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>What went wrong</TableHead>
                                <TableHead className="text-right">Reports</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {types.map((x) => (
                                <TableRow key={x.t}>
                                    <TableCell>{TYPE_LABEL[x.t]}</TableCell>
                                    <TableCell className="text-right tabular-nums">{x.n}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            </section>
            <DesignNote title="Design note — honest numbers (Main, Q10)">
                <p>Today the hero says “Resolved · 30d” but counts from the 1st of the month, months are worked out in UTC, the list stops at 300 while the numbers don’t, reports count only “resolved” while the register counts resolved and closed, and near misses count against a target of 0 (AUDIT 1, 8). Here each number is worked out once, by NZ date, and the governance count shows near misses next to errors that reached the person. P09 builds the reports from the same numbers.</p>
            </DesignNote>
        </>
    );
}
