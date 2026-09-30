/* Orders & reviews › Medication reviews — /emar/reviews (Main, Q1). The page
 * top is P04 v1’s approved Orders & reviews header (title, chip, search, rail),
 * with the Reviews view’s own meters — as each Safety & oversight view has its
 * own (P08a, P07b). P05 designs this view; Orders, To check, Covert and
 * Reconciliation are P04’s and link-only here. Every row opens on click, and
 * ⋯, right-click and the menu key give the same menu. */
import { PersonDisc } from '@/components/lists/entity-cells';
import { compactMenu, EntityContextMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
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
import {
    AlertTriangle,
    ArrowUpRight,
    CalendarClock,
    CalendarPlus,
    ClipboardCheck,
    ClipboardList,
    Clock3,
    EyeOff,
    FileSignature,
    GitCompare,
    Home,
    MoveRight,
    Pill,
    RefreshCw,
    Repeat,
    Stethoscope,
    UserRound,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';
import { HOUSES, OUTCOME_LABEL, PEOPLE, PERSONAS, TRIGGER_LABEL, WHERE_LABEL, orderOf, type House, type Review } from '../data';
import { useOpen } from '../host';
import { canEnter, canManage, cdView, changeState, changesIn, daysUntil, hiddenFor, kindText, lowerFirst, medText, reviewState, reviewsIn, type ChangeRow } from '../model';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
import { DesignNote, Notice, StateLine, SubTabs, Wrap } from '../ui';

type View = 'orders' | 'check' | 'covert' | 'reconcile' | 'reviews';
const RAIL: { key: View; label: string; icon: LucideIcon }[] = [
    { key: 'orders', label: 'Orders', icon: ClipboardList },
    { key: 'check', label: 'To check', icon: ClipboardCheck },
    { key: 'covert', label: 'Covert', icon: EyeOff },
    { key: 'reconcile', label: 'Reconciliation', icon: GitCompare },
    { key: 'reviews', label: 'Medication reviews', icon: Stethoscope },
];
type Sub = 'todo' | 'changes' | 'booked' | 'recorded' | 'closed';
const SUBS: { key: Sub; label: string; icon: LucideIcon }[] = [
    { key: 'todo', label: 'To do', icon: CalendarClock },
    { key: 'changes', label: 'Changes', icon: GitCompare },
    { key: 'booked', label: 'Booked', icon: CalendarPlus },
    { key: 'recorded', label: 'Recorded', icon: FileSignature },
    { key: 'closed', label: 'Cancelled or closed', icon: XCircle },
];

/** The same menu for ⋯, right-click and the menu key (LIST_STYLE_GUIDE §1). */
export function useCtx() {
    const [ctx, setCtx] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const openAt = (e: MouseEvent, title: string, items: MenuItem[]) => {
        e.preventDefault();
        const r = (e.currentTarget as HTMLElement).getBoundingClientRect();
        setCtx({ x: e.clientX || r.left + 24, y: e.clientY || r.top + r.height / 2, title, items });
    };
    return { openAt, node: ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={Stethoscope} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null };
}
const stop = (fn: () => void) => (e: MouseEvent) => {
    e.stopPropagation();
    fn();
};

/** The review row menu, shared by every list and the person record. */
export function reviewMenu(r: Review, p: ReturnType<typeof useStore>['route']['persona'], open: (s: string) => void, go: (path: string, params?: Record<string, string | undefined>) => void): MenuItem[] {
    const booked = r.state === 'booked';
    return compactMenu([
        { label: 'Open the review', icon: Stethoscope, onClick: () => open(`review:${r.id}`) },
        booked && canManage(p) && { label: 'Record the outcome', icon: FileSignature, onClick: () => open(`record:${r.id}`) },
        booked && canManage(p) && { label: r.booked ? 'Change the appointment' : 'Book the appointment', icon: CalendarPlus, onClick: () => open(`appt:${r.id}`) },
        booked && canManage(p) && { label: 'Move the review', icon: MoveRight, onClick: () => open(`move:${r.id}`) },
        // A regular review is moved, never cancelled (Main, Q8): Cancel is left out of its menu, as approved mockups leave unavailable items out.
        booked && canManage(p) && r.kind === 'triggered' && { label: 'Cancel the review', icon: XCircle, onClick: () => open(`cancel:${r.id}`), danger: true },
        { separator: true },
        { label: `Open ${PEOPLE[r.pid].pref}’s medication record`, icon: UserRound, onClick: () => go('/emar/mar', { client_id: String(PEOPLE[r.pid].clientId), tab: 'clinical', view: 'reviews', open: undefined }) },
        canManage(p) && r.state === 'booked' && { label: 'Change how often', icon: Repeat, onClick: () => open(`interval:${r.pid}`) },
    ]);
}
/** A change row’s menu: the next step for who you are, then the review and the order. */
export function changeMenu(c: ChangeRow, p: ReturnType<typeof useStore>['route']['persona'], open: (s: string) => void): MenuItem[] {
    const st = changeState(c.item).step;
    const k = `${c.review.id}:${c.item.orderId}`;
    return compactMenu([
        { label: 'Open the change', icon: GitCompare, onClick: () => open(`change:${k}`) },
        st === 'pending' && canManage(p) && cdView(p) && { label: 'Add the outcome', icon: FileSignature, onClick: () => open(`outcome:${k}`) },
        st === 'waiting' && canManage(p) && !hiddenFor(p, c.item.orderId) && { label: 'Record the prescriber’s decision', icon: FileSignature, onClick: () => open(`decision:${k}`) },
        st === 'agreed' && !hiddenFor(p, c.item.orderId) && { label: 'Enter the change in Orders', icon: ClipboardList, onClick: () => open(`enter:${k}`) },
        ['written', 'check'].includes(st) && { label: 'Open the order in Orders', icon: ArrowUpRight, onClick: () => window.dispatchEvent(new CustomEvent('preview:toast', { detail: 'Opens the order in P04’s Orders — outside this preview.' })) },
        st === 'watching' && { label: 'Open the follow-up', icon: ArrowUpRight, onClick: () => window.dispatchEvent(new CustomEvent('preview:toast', { detail: `Opens follow-up ${c.item.watch?.followUp} (P08a) — outside this preview.` })) },
        { separator: true },
        { label: 'Open the review', icon: Stethoscope, onClick: () => open(`review:${c.review.id}`) },
    ]);
}

export function ReviewsPage() {
    const s = useStore();
    const r = s.route;
    const p = r.persona;
    const me = PERSONAS[p];
    const open = useOpen();
    const [search, setSearch] = useState('');
    const sub = (SUBS.some((x) => x.key === r.q.get('sub')) ? r.q.get('sub') : 'todo') as Sub;
    const houseParam = r.q.get('house') as House | null;
    const houses = me.houses.filter((h) => !houseParam || h === houseParam);
    const kind = r.q.get('kind') ?? 'all';
    const scn = r.scenario;
    const loading = scn === 'loading';
    const failed = scn === 'unavailable';
    const empty = scn === 'empty';
    const dash = loading ? '—' : failed ? 'Unavailable' : null;
    const q = search.trim().toLowerCase();
    const all = empty ? [] : reviewsIn(s.rt, p).filter((x) => houses.includes(PEOPLE[x.pid].house) && (kind === 'all' || x.kind === kind));
    const match = (x: Review) => !q || `${PEOPLE[x.pid].legal} ${PEOPLE[x.pid].pref} ${x.id}`.toLowerCase().includes(q);
    const booked = all.filter((x) => x.state === 'booked');
    const overdue = booked.filter((x) => daysUntil(x.dueIso) < 0);
    const soon = booked.filter((x) => daysUntil(x.dueIso) >= 0 && daysUntil(x.dueIso) <= 30);
    const later = booked.filter((x) => daysUntil(x.dueIso) > 30).sort((a, b) => a.dueIso.localeCompare(b.dueIso));
    const toRecord = booked.filter((x) => reviewState(x).label === 'Outcome to record');
    const recorded = all.filter((x) => x.state === 'recorded').sort((a, b) => b.dueIso.localeCompare(a.dueIso));
    const recent = recorded.filter((x) => daysUntil(x.dueIso) >= -90);
    const closed = all.filter((x) => x.state === 'cancelled' || x.state === 'closed');
    const changes = empty ? [] : changesIn(s.rt, p).filter((c) => houses.includes(PEOPLE[c.review.pid].house) && (kind === 'all' || c.review.kind === kind));
    const waiting = changes.filter((c) => changeState(c.item).step === 'waiting');
    const toEnter = changes.filter((c) => changeState(c.item).step === 'agreed');
    const houseText = me.houses.length > 1 && !houseParam ? `${HOUSES[me.houses[0]]} and ${HOUSES[me.houses[1]]}` : HOUSES[houses[0]];
    const goSub = (k: Sub) => s.set({ sub: k === 'todo' ? undefined : k, open: undefined });
    const goView = (v: View) => (v === 'reviews' ? goSub('todo') : s.toast('info', `${RAIL.find((x) => x.key === v)!.label} is P04’s approved view — outside this preview.`));

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
    const header = (
        <PageHeader
            variant="index"
            icon={ClipboardList}
            title="Orders & reviews"
            titleChip={<PageHeaderStatusChip variant="neutral">{me.houses.length > 1 && !houseParam ? '2 houses' : '1 house'}</PageHeaderStatusChip>}
            subline={`Medication reviews for ${houseText} · times in NZDT`}
            actions={
                <>
                    <PageHeaderSearch value={search} onChange={setSearch} placeholder="Search people or medicines…" />
                    {canManage(p) && !failed ? (
                        <PageHeaderPrimaryButton icon={CalendarPlus} onClick={() => open('book')}>
                            Book a review
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock label="Overdue" tone={!dash && overdue.length ? 'critical' : 'brand'} onClick={() => goSub('todo')} ariaLabel={`View ${overdue.length} overdue reviews`}>
                        <PageHeaderMeterBig>{dash ?? overdue.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : overdue.length ? `${PEOPLE[overdue[0].pid].pref}${PEOPLE[overdue[0].pid].away ? ' — away' : ''}` : 'None overdue'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Due in 30 days" tone={!dash && toRecord.length ? 'warning' : 'brand'} onClick={() => goSub('todo')} ariaLabel={`View ${soon.length} reviews due in the next 30 days`}>
                        <PageHeaderMeterBig>{dash ?? soon.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : toRecord.length ? `${toRecord.length} outcome to record` : soon.length ? `Next: ${soon[0].due}` : 'None due'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Booked" onClick={() => goSub('booked')} ariaLabel={`View ${later.length} reviews booked later`}>
                        <PageHeaderMeterBig>{dash ?? later.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : later.length ? `Next: ${later[0].due}` : 'None later'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Waiting for the prescriber" tone={!dash && waiting.length ? 'warning' : 'brand'} onClick={() => goSub('changes')} ariaLabel={`View ${waiting.length} changes waiting for the prescriber’s decision`}>
                        <PageHeaderMeterBig>{dash ?? waiting.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : waiting.length ? 'Asked, no decision yet' : 'None waiting'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Changes to make" tone={!dash && toEnter.length ? 'warning' : 'brand'} onClick={() => goSub('changes')} ariaLabel={`View ${toEnter.length} agreed changes to enter in Orders`}>
                        <PageHeaderMeterBig>{dash ?? toEnter.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : toEnter.length ? 'Agreed — enter in Orders' : 'None to enter'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Done" onClick={() => goSub('recorded')} ariaLabel={`View ${recent.length} reviews recorded in the last 90 days`}>
                        <PageHeaderMeterBig>{dash ?? recent.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : 'In the last 90 days'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    {me.houses.length > 1 ? <PageHeaderFilterSelect icon={Home} label="House" value={houseParam ?? 'all'} allValue="all" onChange={(v) => s.set({ house: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All your houses' }, ...me.houses.map((h) => ({ value: h, label: HOUSES[h] }))]} /> : null}
                    <PageHeaderFilterSelect label="Kind" value={kind} allValue="all" onChange={(v) => s.set({ kind: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'Regular and triggered' }, { value: 'regular', label: 'Regular' }, { value: 'triggered', label: 'Triggered' }]} />
                    {asAt}
                </>
            }
            rail={<PageHeaderRail<View> items={RAIL} value="reviews" onSelect={goView} ariaLabel="Orders & reviews views" />}
        />
    );

    let body: ReactNode;
    if (loading)
        body = (
            <Card className="p-4" aria-busy="true" aria-label="Loading medication reviews">
                <SkeletonTable rows={5} columns={5} />
            </Card>
        );
    else if (failed)
        body = (
            <Card className="p-2">
                <ErrorState title="We couldn’t load medication reviews" message="Nothing is shown rather than an incomplete list. Reviews that are due are still due — try again." onRetry={() => s.setViewer({ scenario: 'normal' })} />
            </Card>
        );
    else if (empty)
        body = (
            <Card className="p-2">
                <EmptyState
                    icon={Stethoscope}
                    title="No medication reviews yet"
                    description="Each person’s regular review is booked automatically once their first one is recorded. Book the first one to start the cycle."
                    action={canManage(p) ? <Button onClick={() => open('book')}>Book a review</Button> : undefined}
                />
            </Card>
        );
    else {
        const sections: ReactNode[] = [];
        if (sub === 'todo') {
            sections.push(<ReviewSection key="o" title="Overdue" caption="the due date has passed — being away doesn’t pause it" rows={overdue.filter(match)} empty="Nothing overdue" />);
            sections.push(<ReviewSection key="s" title="Due in the next 30 days" caption="soonest first" rows={soon.filter(match).sort((a, b) => a.dueIso.localeCompare(b.dueIso))} empty="Nothing due in the next 30 days" />);
        } else if (sub === 'changes')
            sections.push(<ChangeSection key="c" rows={changes.filter((c) => !q || `${PEOPLE[c.review.pid].legal} ${hiddenFor(p, c.item.orderId) ? '' : orderOf(c.item.orderId).med}`.toLowerCase().includes(q))} />);
        else if (sub === 'booked') sections.push(<ReviewSection key="b" title="Booked later" caption="more than 30 days away · each regular review books the next one" rows={later.filter(match)} empty="Nothing booked later" />);
        else if (sub === 'recorded') sections.push(<ReviewSection key="r" title="Recorded" caption="newest first · kept with who did them and who took part" rows={recorded.filter(match)} empty="No reviews recorded yet" recordedView />);
        else sections.push(<ReviewSection key="x" title="Cancelled or closed" caption="kept, with the reason" rows={closed.filter(match)} empty="Nothing cancelled or closed" />);
        body = <>{sections}</>;
    }

    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/emar/mar' }, { title: 'Orders & reviews', href: hrefFor('/emar/reviews', {}, r) }, { title: 'Medication reviews' }]}>
            {header}
            <SubTabs
                tabs={SUBS.map((x) => ({ key: x.key, label: x.label, icon: x.icon, count: x.key === 'changes' && !dash && !empty && changes.length ? changes.length : undefined }))}
                active={sub}
                onTab={(k) => goSub(k as Sub)}
                hrefOf={(k) => hrefFor('/emar/reviews', { sub: k === 'todo' ? undefined : k }, r)}
                label="Medication review views"
            />
            <div className="flex min-w-0 flex-col gap-5">
                {scn === 'stale' ? (
                    <Notice tone="warning" icon={AlertTriangle} title="This may be out of date" actions={<Button size="sm" variant="outline" onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}><RefreshCw className="size-4" /> Try again</Button>}>
                        We couldn’t refresh at 9:12 am. It shows what was known at 8:40 am. Check before booking or recording anything.
                    </Notice>
                ) : null}
                {body}
                <DesignNote title="Design note — medication reviews (Main, Q1–Q9)">
                    <p>
                        Today this is a separate page with a PageHero, quarter chips that ignore the year, a deprescribing board and a “GP accept %” that can only read 100 % (AUDIT 1, 5.3). Here reviews are a view of Orders & reviews. A change is a recommendation until the prescriber decides; an agreed change is entered in Orders and checked there, like any order change (P04).
                    </p>
                </DesignNote>
            </div>
        </Shell>
    );
}

/* ───────────── a list of reviews ───────────── */
function ReviewSection({ title, caption, rows, empty, recordedView }: { title: string; caption: string; rows: Review[]; empty: string; recordedView?: boolean }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const ctx = useCtx();
    const menu = (x: Review) => reviewMenu(x, p, open, s.go);
    const act = (x: Review) => {
        const st = reviewState(x);
        if (x.state !== 'booked' || !canManage(p)) return null;
        if (st.label === 'Outcome to record' || daysUntil(x.dueIso) <= 0)
            return x.booked ? (
                <Button size="sm" data-return={`rec-${x.id}`} onClick={stop(() => open(`record:${x.id}`))}>
                    Record the outcome
                </Button>
            ) : (
                <Button size="sm" data-return={`appt-${x.id}`} onClick={stop(() => open(`appt:${x.id}`))}>
                    Book the appointment
                </Button>
            );
        return x.booked ? null : (
            <Button size="sm" variant="outline" data-return={`appt-${x.id}`} onClick={stop(() => open(`appt:${x.id}`))}>
                Book the appointment
            </Button>
        );
    };
    return (
        <section className="flex flex-col gap-2.5" aria-label={title}>
            <ListCaption title={title} caption={`${rows.length} shown · ${caption}`} />
            {rows.length ? (
                <EntityTable<Review>
                    rows={rows}
                    rowKey={(x) => x.id}
                    rowHeight="content"
                    minWidth={960}
                    identityLabel="Person"
                    identityWidth="1.7fr"
                    identity={(x) => ({ mark: <PersonDisc name={PEOPLE[x.pid].legal} size={30} />, name: PEOPLE[x.pid].legal, subline: <Wrap>{x.id} · {kindText(x)}{x.trigger ? ` — ${lowerFirst(TRIGGER_LABEL[x.trigger])}` : ''} · {HOUSES[PEOPLE[x.pid].house]}</Wrap> })}
                    columns={[
                        {
                            key: 'due',
                            label: recordedView ? 'Recorded' : 'Due',
                            width: '1.3fr',
                            cell: (x) => (
                                <span className="flex flex-col items-start gap-1 py-0.5 text-[12.5px]">
                                    {recordedView ? <span>{x.recorded?.at.split(', ')[0]}</span> : <span className="font-semibold">{x.due}</span>}
                                    <StatusBadge variant={reviewState(x).variant} className="rounded-[8px]">
                                        {reviewState(x).label}
                                    </StatusBadge>
                                    {x.state === 'booked' && daysUntil(x.dueIso) < 0 && PEOPLE[x.pid].away ? <StateLine>Away — {PEOPLE[x.pid].away!.charAt(0).toLowerCase() + PEOPLE[x.pid].away!.slice(1)}</StateLine> : null}
                                    {x.moves.length ? <StateLine>Moved {x.moves.length === 1 ? 'once' : `${x.moves.length} times`} — was {x.moves[0].from}</StateLine> : null}
                                </span>
                            ),
                        },
                        {
                            key: 'with',
                            label: recordedView ? 'Done by' : 'Appointment',
                            width: '1.6fr',
                            cell: (x) =>
                                x.state === 'recorded' ? (
                                    <span className="flex flex-col gap-0.5 text-[12.5px]">
                                        <span>{x.recorded!.clinicians.map((c) => `${c.name} (${c.role})`).join(', ')}</span>
                                        <StateLine>Recorded by {x.recorded!.by}</StateLine>
                                    </span>
                                ) : x.state === 'cancelled' ? (
                                    <span className="text-[12.5px]">
                                        <Wrap>{x.cancelled!.reason}</Wrap>
                                        <StateLine>
                                            {x.cancelled!.by}, {x.cancelled!.at}
                                        </StateLine>
                                    </span>
                                ) : x.state === 'closed' ? (
                                    <span className="text-[12.5px]">
                                        <Wrap>{x.closed!.reason}</Wrap>
                                        <StateLine>{x.closed!.at}</StateLine>
                                    </span>
                                ) : x.booked ? (
                                    <span className="flex flex-col gap-0.5 text-[12.5px]">
                                        <span>
                                            {x.booked.with.name} ({x.booked.with.role})
                                        </span>
                                        <StateLine>
                                            {x.booked.at} · {WHERE_LABEL[x.booked.where].toLowerCase()}
                                        </StateLine>
                                    </span>
                                ) : (
                                    <span className="text-[12.5px] text-muted-foreground">Not booked with a clinician yet</span>
                                ),
                        },
                        {
                            key: 'what',
                            label: recordedView ? 'Outcome' : 'Owner',
                            width: '1.2fr',
                            cell: (x) =>
                                recordedView && x.recorded ? (
                                    <span className="flex flex-col gap-0.5 text-[12.5px]">
                                        <span>{outcomeSummary(x)}</span>
                                        {x.recorded.next ? <StateLine>Next: {x.recorded.next.due}</StateLine> : null}
                                    </span>
                                ) : (
                                    <span className="text-[12.5px]">{x.owner}</span>
                                ),
                        },
                        { key: 'act', label: '', width: '176px', align: 'right', cell: (x) => act(x) },
                    ]}
                    actionsFor={menu}
                    onOpen={(x) => open(`review:${x.id}`)}
                    onRowContextMenu={(e, x) => ctx.openAt(e, `${x.id} · ${PEOPLE[x.pid].legal}`, menu(x))}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={Stethoscope} title={empty} />
                </Card>
            )}
            {ctx.node}
        </section>
    );
}
export function outcomeSummary(x: Review) {
    const items = x.recorded?.items ?? [];
    const n = (o: string) => items.filter((i) => i.outcome === o).length;
    const ch = items.filter((i) => ['change', 'stop', 'start', 'swap'].includes(i.outcome)).length;
    const parts = [ch ? `${ch} ${ch === 1 ? 'change' : 'changes'}` : '', n('watch') ? `${n('watch')} to watch` : '', n('continue') ? `${n('continue')} continue` : ''].filter(Boolean);
    return parts.join(' · ') || 'No medicines reviewed';
}

/* ───────────── changes from reviews (Q6, Q7) ───────────── */
export function ChangeSection({ rows, compact }: { rows: ChangeRow[]; compact?: boolean }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const ctx = useCtx();
    const menu = (c: ChangeRow) => changeMenu(c, p, open);
    const order = ['pending', 'waiting', 'agreed', 'written', 'check', 'watching'];
    const sorted = [...rows].sort((a, b) => order.indexOf(changeState(a.item).step) - order.indexOf(changeState(b.item).step));
    const act = (c: ChangeRow) => {
        const st = changeState(c.item).step;
        const k = `${c.review.id}:${c.item.orderId}`;
        if (hiddenFor(p, c.item.orderId)) return null;
        if (st === 'pending' && canManage(p) && cdView(p))
            return (
                <Button size="sm" data-return={`out-${k}`} onClick={stop(() => open(`outcome:${k}`))}>
                    Add the outcome
                </Button>
            );
        if (st === 'waiting' && canManage(p))
            return (
                <Button size="sm" data-return={`dec-${k}`} onClick={stop(() => open(`decision:${k}`))}>
                    Record the decision
                </Button>
            );
        if (st === 'agreed' && canEnter(p))
            return (
                <Button size="sm" data-return={`ent-${k}`} onClick={stop(() => open(`enter:${k}`))}>
                    Enter the change
                </Button>
            );
        return null;
    };
    return (
        <section className="flex flex-col gap-2.5" aria-label="Changes from reviews">
            <ListCaption title="Changes from reviews" caption={`${rows.length} open · a change is a recommendation until the prescriber decides · done ones stay on each review`} />
            {rows.length ? (
                <EntityTable<ChangeRow>
                    rows={sorted}
                    rowKey={(c) => `${c.review.id}:${c.item.orderId}`}
                    rowHeight="content"
                    minWidth={compact ? 900 : 960}
                    identityLabel="Medicine"
                    identityWidth="1.3fr"
                    identity={(c) => ({ icon: Pill, name: hiddenFor(p, c.item.orderId) ? 'Controlled medicine' : orderOf(c.item.orderId).med, subline: <Wrap>{hiddenFor(p, c.item.orderId) ? '' : `${orderOf(c.item.orderId).strength} · `}{PEOPLE[c.review.pid].legal} · {c.review.id}</Wrap> })}
                    columns={[
                        {
                            key: 'what',
                            label: 'Recommended',
                            width: '1.6fr',
                            cell: (c) => (
                                <span className="flex flex-col gap-0.5 text-[12.5px]">
                                    <span className="font-semibold">{c.item.pendingCd ? 'Outcome to add' : OUTCOME_LABEL[c.item.outcome]}</span>
                                    <StateLine>{hiddenFor(p, c.item.orderId) ? 'Details need controlled-medicine access' : c.item.pendingCd ? 'The review was recorded without it' : c.item.outcome === 'watch' ? c.item.watch?.what : c.item.what}</StateLine>
                                </span>
                            ),
                        },
                        {
                            key: 'state',
                            label: 'Where it’s at',
                            width: '1.6fr',
                            cell: (c) => (
                                <span className="flex flex-col items-start gap-1 py-0.5">
                                    <StatusBadge variant={changeState(c.item).variant} className="rounded-[8px]">
                                        {changeState(c.item).label}
                                    </StatusBadge>
                                    <StateLine>{changeLine(c)}</StateLine>
                                </span>
                            ),
                        },
                        { key: 'act', label: '', width: '180px', align: 'right', cell: act },
                    ]}
                    actionsFor={menu}
                    onOpen={(c) => open(`change:${c.review.id}:${c.item.orderId}`)}
                    onRowContextMenu={(e, c) => ctx.openAt(e, `${medText(p, c.item.orderId)} · ${PEOPLE[c.review.pid].pref}`, menu(c))}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={GitCompare} title="No open changes" description="Changes a review recommends appear here until they’re done or not agreed." />
                </Card>
            )}
            {ctx.node}
        </section>
    );
}
export function changeLine(c: ChangeRow): string {
    const it = c.item;
    const st = changeState(it).step;
    if (st === 'pending') return `Recorded by ${c.review.recorded!.by} without it — a house lead adds it`;
    if (st === 'waiting') return it.decision?.asked ? `Asked ${it.decision.asked.at} — ${lowerFirst(it.decision.asked.how)}` : 'Not asked yet';
    if (st === 'agreed') return `${it.decision!.prescriber}, ${it.decision!.at} — ${it.decision!.how === 'writing' ? 'in writing' : it.decision!.how === 'phone' ? 'by phone' : 'agreed'}`;
    if (st === 'written') return `Phone order — written confirmation due ${it.entered!.phone!.writtenDue}`;
    if (st === 'check') return `Entered ${it.entered!.at} by ${it.entered!.by}`;
    if (st === 'watching') return `Follow-up ${it.watch!.followUp} · ${c.review.owner}`;
    if (st === 'watched') return it.watch!.done!.note;
    if (st === 'notAgreed') return it.decision?.note ?? '';
    if (st === 'done') return `Checked by ${it.entered!.checked!.by}`;
    return '';
}
