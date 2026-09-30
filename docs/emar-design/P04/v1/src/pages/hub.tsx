/* Orders & reviews — /emar/prescriptions (plan §2.2 hub 3; Main Q7). The P02
 * hub pattern: PageHeader (not today’s PageHero), a meter row, the filter row
 * and the rail — Orders · To check · Covert · Reconciliation · Medication
 * reviews (link-only, P05). Every order row opens the order (versions,
 * checks, confirmations, supply read-only). */
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
import { AlertTriangle, ClipboardCheck, ClipboardList, Clock3, EyeOff, FilePlus2, FileSignature, GitCompare, History, Home, Pill, RefreshCw, ShieldCheck, Stethoscope, type LucideIcon } from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';
import { COVERT, HOUSES, PEOPLE, PERSONAS, PHONE_INSTRUCTION, REC_REASON, SOURCE_LABEL, type House, type Order } from '../data';
import { useOpen } from '../host';
import { allOrders, allRecs, canCheck, canEnter, cdView, concealed, covertOf, covertState, currentOf, eventsOf, pendingOf, queueOf, recOf, statusOf, whyCantCheck, type CheckItem } from '../model';
import { Shell } from '../shell';
import { useStore } from '../store';
import { ConcealedCount, DesignNote, Notice, OrderBadge, StateLine, Wrap } from '../ui';

type View = 'orders' | 'check' | 'covert' | 'reconcile' | 'reviews';
/** “1 December 2026” → “1 Dec 2026”, so meter captions fit on one line. */
const shortDate = (d: string) => d.replace(/^(\d+) (\w{3})\w* (\d{4})$/, '$1 $2 $3');
const RAIL: { key: View; label: string; icon: LucideIcon }[] = [
    { key: 'orders', label: 'Orders', icon: ClipboardList },
    { key: 'check', label: 'To check', icon: ClipboardCheck },
    { key: 'covert', label: 'Covert', icon: EyeOff },
    { key: 'reconcile', label: 'Reconciliation', icon: GitCompare },
    { key: 'reviews', label: 'Medication reviews', icon: Stethoscope },
];

export function useCtx() {
    const [ctx, setCtx] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const openAt = (e: MouseEvent, title: string, items: MenuItem[]) => {
        if (e.target instanceof Element && e.target.closest('a[href]')) return;
        e.preventDefault();
        const b = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const kb = e.clientX === 0 && e.clientY === 0;
        setCtx({ x: kb ? b.left + 12 : e.clientX, y: kb ? b.top + 12 : e.clientY, title, items });
    };
    return { openAt, node: ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={Pill} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null };
}

export function HubPage() {
    const s = useStore();
    const r = s.route;
    const me = PERSONAS[r.persona];
    const open = useOpen();
    const [search, setSearch] = useState('');
    const view = (RAIL.some((x) => x.key === r.q.get('view')) ? r.q.get('view') : 'orders') as View;
    const houseParam = r.q.get('house') as House | null;
    const houses = me.houses.filter((h) => !houseParam || h === houseParam);
    const scn = r.scenario;
    const loading = scn === 'loading';
    const failed = scn === 'unavailable';
    const empty = scn === 'empty';
    const dash = loading ? '—' : failed ? 'Unavailable' : null;
    const inScope = allOrders(s.rt).filter((o) => houses.includes(PEOPLE[o.pid].house));
    const visible = empty ? [] : inScope.filter((o) => !concealed(o, r.persona));
    const hiddenCd = empty ? 0 : inScope.length - visible.length;
    const active = visible.filter((o) => statusOf(o, s.rt).state !== 'stopped');
    const queue = queueOf(visible, s.rt);
    const toCheck = queue.filter((x) => x.kind !== 'sentBack');
    const written = queue.filter((x) => x.kind === 'written');
    const ending = active.filter((o) => statusOf(o, s.rt).state === 'ending');
    const covers = empty ? [] : visible.filter((o) => o.covert).map((o) => covertOf(o.id, s.rt, scn)).filter(Boolean);
    const nextCovert = covers.sort((a, b) => a!.reviewIso.localeCompare(b!.reviewIso))[0];
    const recsOpen = empty ? [] : allRecs(s.rt).filter((x) => houses.includes(PEOPLE[x.pid].house) && recOf(x, s.rt).status === 'open');
    const houseText = me.houses.length > 1 && !houseParam ? `${HOUSES[me.houses[0]]} and ${HOUSES[me.houses[1]]}` : HOUSES[houses[0]];
    const go = (v: View, extra: Record<string, string | undefined> = {}) => s.set({ view: v === 'orders' ? undefined : v, open: undefined, ...extra });

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
    const houseFilter = me.houses.length > 1 ? <PageHeaderFilterSelect icon={Home} label="House" value={houseParam ?? 'all'} allValue="all" onChange={(v) => s.set({ house: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All your houses' }, ...me.houses.map((h) => ({ value: h, label: HOUSES[h] }))]} /> : null;
    const filters: Record<View, ReactNode> = {
        orders: (
            <>
                {houseFilter}
                <PageHeaderFilterSelect label="Show" value={r.q.get('show') ?? 'current'} allValue="current" onChange={(v) => s.set({ show: v === 'current' ? undefined : v })} options={[{ value: 'current', label: 'Current orders' }, { value: 'attention', label: 'Needs attention' }, { value: 'ending', label: 'Ending in 14 days' }, { value: 'stopped', label: 'Stopped' }]} />
                {asAt}
            </>
        ),
        check: (
            <>
                {houseFilter}
                {asAt}
            </>
        ),
        covert: (
            <>
                {houseFilter}
                {asAt}
            </>
        ),
        reconcile: (
            <>
                {houseFilter}
                <PageHeaderFilterSelect label="Show" value={r.q.get('rs') ?? 'all'} allValue="all" onChange={(v) => s.set({ rs: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All' }, { value: 'open', label: 'Open' }, { value: 'done', label: 'Signed off' }]} />
                {asAt}
            </>
        ),
        reviews: asAt,
    };
    const header = (
        <PageHeader
            variant="index"
            icon={ClipboardList}
            title="Orders & reviews"
            titleChip={<PageHeaderStatusChip variant="neutral">{me.houses.length > 1 && !houseParam ? '2 houses' : '1 house'}</PageHeaderStatusChip>}
            subline={`Prescribers’ orders for ${houseText} · times in NZDT`}
            actions={
                <>
                    <PageHeaderSearch value={search} onChange={setSearch} placeholder="Search people or medicines…" />
                    {canEnter(r.persona) && !failed ? (
                        <PageHeaderPrimaryButton icon={FilePlus2} onClick={() => open('new')}>
                            Enter an order
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock label="To check" tone={!dash && toCheck.length ? 'warning' : 'brand'} onClick={() => go('check')} ariaLabel={`View ${toCheck.length} things to check`}>
                        <PageHeaderMeterBig>{dash ?? toCheck.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : toCheck.some((x) => x.kind === 'allergy') ? '1 needs the prescriber' : toCheck.length ? 'Orders and confirmations' : 'Nothing to check'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Written confirmation" tone={!dash && written.length ? 'warning' : 'brand'} onClick={() => go('check')} ariaLabel={`View ${written.length} written confirmations due`}>
                        <PageHeaderMeterBig>{dash ?? written.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : written.length ? 'Due by the end of today' : 'None due'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Ending in 14 days" onClick={() => s.set({ view: undefined, show: 'ending' })} ariaLabel={`View ${ending.length} orders ending in 14 days`}>
                        <PageHeaderMeterBig>{dash ?? ending.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : ending.length ? `First: ${ending.map((o) => o.end!).sort()[0]}` : 'None ending'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Covert" tone={!dash && nextCovert && covertState(nextCovert).state !== 'current' ? (covertState(nextCovert).state === 'overdue' ? 'critical' : 'warning') : 'brand'} onClick={() => go('covert')} ariaLabel="View covert authorisations">
                        <PageHeaderMeterBig>{dash ?? covers.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : nextCovert ? (covertState(nextCovert).state === 'overdue' ? `Overdue · ${shortDate(nextCovert.review)}` : `Next review ${shortDate(nextCovert.review)}`) : 'None active'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Reconciliation" tone={!dash && recsOpen.length ? 'warning' : 'brand'} onClick={() => go('reconcile')} ariaLabel={`View ${recsOpen.length} open reconciliations`}>
                        <PageHeaderMeterBig>{dash ?? recsOpen.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : recsOpen.length ? `Due ${recsOpen[0].due.replace(/ \(.*\)$/, '').replace(/^before /, 'by ')}` : 'None open'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Current orders" onClick={() => go('orders')} ariaLabel="View current orders">
                        <PageHeaderMeterBig>{dash ?? active.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : hiddenCd ? `+${hiddenCd} controlled hidden` : `${new Set(active.map((o) => o.pid)).size} people`}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={filters[view]}
            rail={<PageHeaderRail<View> items={RAIL.map((x) => ({ ...x, count: x.key === 'check' && !dash && toCheck.length ? toCheck.length : undefined }))} value={view} onSelect={(k) => go(k)} ariaLabel="Orders & reviews views" />}
        />
    );
    let body: ReactNode;
    if (loading)
        body = (
            <Card className="p-4" aria-busy="true" aria-label="Loading orders">
                <SkeletonTable rows={7} columns={6} />
            </Card>
        );
    else if (failed)
        body = (
            <Card className="p-2">
                <ErrorState title="We couldn’t load orders for your houses" message="Nothing is shown rather than an incomplete list. Staff keep giving from the last checked version on Meds today. Try again." onRetry={() => s.setViewer({ scenario: 'normal' })} />
            </Card>
        );
    else if (view === 'reviews')
        body = (
            <Card className="p-2">
                <EmptyState icon={Stethoscope} title="Medication reviews" description="Reviews keep their own page (/emar/reviews). A review’s outcome becomes an order change here, checked like any other version." />
                <DesignNote title="Design note — link-only (Main, Q7)">
                    <p>Medication reviews are designed in P05. In the build, this rail item opens /emar/reviews.</p>
                </DesignNote>
            </Card>
        );
    else if (view === 'check') body = <CheckQueue items={queue} search={search} empty={empty} />;
    else if (view === 'covert') body = <CovertView orders={visible} empty={empty} />;
    else if (view === 'reconcile') body = <ReconcileView houses={houses} empty={empty} />;
    else body = <OrdersView orders={visible} hiddenCd={hiddenCd} search={search} empty={empty} />;
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/emar/mar' }, { title: 'Orders & reviews' }]}>
            {header}
            {scn === 'stale' ? (
                <Notice tone="warning" icon={AlertTriangle} title="This may be out of date" actions={<Button size="sm" variant="outline" onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}><RefreshCw className="size-4" /> Try again</Button>}>
                    We couldn’t refresh at 9:12 am. It shows what was known at 8:40 am. Don’t check or change an order until it’s up to date.
                </Notice>
            ) : null}
            {body}
        </Shell>
    );
}

/* ───────────── Orders ───────────── */
function orderMenu(o: Order, s: ReturnType<typeof useStore>, open: (x: string) => void): MenuItem[] {
    const p = s.route.persona;
    const st = statusOf(o, s.rt);
    const pend = pendingOf(o, s.rt);
    const stopped = st.state === 'stopped';
    return compactMenu([
        { label: 'Open the order', icon: ClipboardList, onClick: () => open(`order:${o.id}`) },
        !stopped && pend?.state === 'waiting' && canCheck(p) && !whyCantCheck(pend, p) && !(pend.allergy && !pend.allergy.confirmed) && { label: `Check version ${pend.v}`, icon: ClipboardCheck, onClick: () => open(`check:${o.id}`) },
        !stopped && pend?.state === 'waiting' && canCheck(p) && whyCantCheck(pend, p)?.startsWith('You entered') && !(pend.allergy && !pend.allergy.confirmed) && { label: 'Check it alone — nobody else can', icon: ClipboardCheck, onClick: () => open(`check:${o.id}`) },
        !stopped && !pend && currentOf(o, s.rt)?.state === 'lone' && canCheck(p) && !whyCantCheck(currentOf(o, s.rt)!, p) && { label: 'Second check', icon: ClipboardCheck, onClick: () => open(`check:${o.id}`) },
        !stopped && pend?.allergy && !pend.allergy.confirmed && canEnter(p) && { label: 'Record the prescriber’s confirmation', icon: ShieldCheck, onClick: () => open(`allergy:${o.id}`) },
        !stopped && currentOf(o, s.rt)?.written && !currentOf(o, s.rt)!.written!.done && canEnter(p) && { label: 'Record the written confirmation', icon: FileSignature, onClick: () => open(`written:${o.id}`) },
        !stopped && canEnter(p) && { label: 'Enter a change', icon: GitCompare, onClick: () => open(`change:${o.id}`) },
        !stopped && o.covert && canEnter(p) && covertOf(o.id, s.rt, s.route.scenario) && { label: 'Review covert giving', icon: EyeOff, onClick: () => open(`covert:${o.id}`) },
        !stopped && canEnter(p) && { separator: true },
        !stopped && canEnter(p) && { label: 'Stop this order', icon: AlertTriangle, onClick: () => open(`stop:${o.id}`), danger: true },
        { separator: true },
        { label: `Open ${PEOPLE[o.pid].pref}’s medication record`, icon: Pill, onClick: () => s.toast('info', 'The person’s medication record is P02’s approved design — outside this preview.') },
    ]);
}
function OrdersView({ orders, hiddenCd, search, empty }: { orders: Order[]; hiddenCd: number; search: string; empty: boolean }) {
    const s = useStore();
    const open = useOpen();
    const ctx = useCtx();
    const show = s.route.q.get('show') ?? 'current';
    const q = search.trim().toLowerCase();
    const list = orders
        .filter((o) => {
            const st = statusOf(o, s.rt).state;
            if (show === 'stopped') return st === 'stopped';
            if (st === 'stopped') return false;
            if (show === 'attention') return st !== 'active' && st !== 'ending';
            if (show === 'ending') return st === 'ending';
            return true;
        })
        .filter((o) => !q || `${o.med} ${PEOPLE[o.pid].legal} ${PEOPLE[o.pid].pref}`.toLowerCase().includes(q));
    if (empty)
        return (
            <Card className="p-2">
                <EmptyState icon={ClipboardList} title="No orders at your houses yet" description="Orders appear here when a prescriber’s order is entered and checked." />
            </Card>
        );
    const byPerson = [...new Set(list.map((o) => o.pid))];
    return (
        <section className="flex flex-col gap-2.5" aria-label="Orders">
            <ListCaption
                title={show === 'stopped' ? 'Stopped orders' : show === 'ending' ? 'Ending in the next 14 days' : show === 'attention' ? 'Orders needing attention' : 'Current orders'}
                caption={
                    <>
                        {list.length} shown · {byPerson.length} {byPerson.length === 1 ? 'person' : 'people'}
                        {hiddenCd ? <> · <ConcealedCount n={hiddenCd} noun="order" /></> : null}
                    </>
                }
            />
            {list.length ? (
                <EntityTable<Order>
                    rows={list}
                    rowKey={(o) => o.id}
                    rowHeight="content"
                    minWidth={980}
                    identityLabel="Medicine"
                    identityWidth="1.3fr"
                    identity={(o) => ({ icon: Pill, name: o.med, subline: <Wrap>{o.strength}{o.covert ? ' · covert plan' : ''}{o.cd ? ' · controlled' : ''}</Wrap> })}
                    columns={[
                        { key: 'who', label: 'Person', width: '1fr', cell: (o) => <span className="flex items-center gap-2 text-[12.5px]"><PersonDisc name={PEOPLE[o.pid].legal} size={22} />{PEOPLE[o.pid].pref} {PEOPLE[o.pid].surname}</span> },
                        { key: 'dose', label: 'Dose and when', width: '1.3fr', cell: (o) => { const v = currentOf(o, s.rt) ?? pendingOf(o, s.rt)!; return <span className="text-[12.5px]">{v.dose} · {o.prn ? `when needed — ${o.prn}` : v.when}</span>; } },
                        { key: 'src', label: 'Current version', width: '1.1fr', cell: (o) => { const v = currentOf(o, s.rt) ?? pendingOf(o, s.rt)!; return <span className="text-[12.5px]">Version {v.v} · {SOURCE_LABEL[v.source.type].toLowerCase()} · {v.source.prescriber.replace(' (after-hours GP)', '')}</span>; } },
                        {
                            key: 'state',
                            label: 'State',
                            width: '1.6fr',
                            cell: (o) => {
                                const st = statusOf(o, s.rt);
                                return (
                                    <span className="flex flex-col items-start gap-1 py-0.5">
                                        <OrderBadge state={st.state} />
                                        {st.lines.map((l) => (
                                            <StateLine key={l}>{l}</StateLine>
                                        ))}
                                    </span>
                                );
                            },
                        },
                    ]}
                    actionsFor={(o) => orderMenu(o, s, open)}
                    onOpen={(o) => open(`order:${o.id}`)}
                    onRowContextMenu={(e, o) => ctx.openAt(e, `${o.med} · ${PEOPLE[o.pid].pref}`, orderMenu(o, s, open))}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={ClipboardList} title="No orders match" description="Change the Show filter or clear the search." />
                </Card>
            )}
            {ctx.node}
            <p className="text-caption">Each medicine has one order. A change from the prescriber makes a new version; the old ones are kept. Staff give from the latest checked version.</p>
            <RecentChanges />
            <DesignNote title="Design note — replaces today’s /emar/prescriptions">
                <p>Today’s page is a PageHero (“Prescriptions &amp; orders · live”) listing prescriber orders that are a paper trail only — the chart entry that allows a dose is a separate record, and confirming an order doesn’t change it (AUDIT 2.1). Here there is one order per medicine, with versions (Main, Q1).</p>
            </DesignNote>
        </section>
    );
}

function RecentChanges() {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const all = eventsOf(s.rt).filter((e) => me.houses.includes(PEOPLE[e.pid].house));
    const list = all.filter((e) => !e.cd || cdView(p)).slice(0, 8);
    const hidden = all.length - all.filter((e) => !e.cd || cdView(p)).length;
    return (
        <section className="flex flex-col gap-2.5" aria-label="Recent changes">
            <ListCaption title="Recent changes" caption={<>{list.length} shown{hidden ? <> · <ConcealedCount n={hidden} noun="change" /></> : null} · newest first · kept, never deleted</>} />
            <EntityTable<(typeof list)[number]>
                rows={list}
                rowKey={(e) => e.id}
                rowHeight="content"
                minWidth={980}
                identityLabel="When"
                identityWidth="150px"
                identity={(e) => ({ icon: History, name: e.at.split(', ')[0], subline: e.at.split(', ')[1] ?? '' })}
                columns={[
                    { key: 'who', label: 'Person', width: '0.9fr', cell: (e) => <span className="text-[12.5px]">{PEOPLE[e.pid].pref} {PEOPLE[e.pid].surname}</span> },
                    { key: 'what', label: 'What happened', width: '2.4fr', cell: (e) => <span className="text-[12.5px] font-semibold">{e.what}</span> },
                    { key: 'by', label: 'By', width: '0.9fr', cell: (e) => <span className="text-[12.5px]">{e.who}</span> },
                ]}
                actionsFor={() => [{ label: 'See the orders', icon: ClipboardList, onClick: () => s.set({ view: undefined }) }]}
            />
        </section>
    );
}

/* ───────────── To check ───────────── */
const KIND: Record<CheckItem['kind'], { title: string; caption: string }> = {
    allergy: { title: 'The prescriber must confirm', caption: 'An allergy match — record the prescriber’s confirmation before anyone checks it' },
    check: { title: 'Check new and changed orders', caption: 'Someone other than the person who entered it checks each version' },
    second: { title: 'Second checks', caption: 'Checked alone because nobody else could — due by the end of the next day' },
    written: { title: 'Prescriber’s written confirmation', caption: 'Phone and verbal orders — attach it by the end of the next day' },
    sentBack: { title: 'Sent back', caption: 'Re-enter it with the right details, or cancel the change' },
};
function CheckQueue({ items, search, empty }: { items: CheckItem[]; search: string; empty: boolean }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const ctx = useCtx();
    const q = search.trim().toLowerCase();
    const list = items.filter((x) => !q || `${x.order.med} ${PEOPLE[x.order.pid].legal}`.toLowerCase().includes(q));
    const me = PERSONAS[p];
    const phone = me.houses.includes('kowhai') && !empty && !s.rt.phoneDone;
    if (empty)
        return (
            <Card className="p-2">
                <EmptyState icon={ClipboardCheck} title="Nothing to check" description="New and changed orders, written confirmations and second checks appear here." />
            </Card>
        );
    const actionFor = (x: CheckItem) => {
        const stop = (fn: () => void) => (e: MouseEvent) => (e.stopPropagation(), fn());
        if (x.kind === 'check' || x.kind === 'second') {
            const why = whyCantCheck(x.version, p);
            return why ? (
                <span className="text-right text-[12px] text-muted-foreground">{why}</span>
            ) : (
                <Button data-return={`chk-${x.order.id}`} size="sm" onClick={stop(() => open(`check:${x.order.id}`))}>
                    {x.kind === 'second' ? 'Second check' : `Check version ${x.version.v}`}
                </Button>
            );
        }
        if (x.kind === 'allergy') return canEnter(p) ? <Button data-return={`alg-${x.order.id}`} size="sm" onClick={stop(() => open(`allergy:${x.order.id}`))}>Record confirmation</Button> : <span className="text-[12px] text-muted-foreground">A lead records it</span>;
        if (x.kind === 'written') return canEnter(p) ? <Button data-return={`wr-${x.order.id}`} size="sm" variant="outline" onClick={stop(() => open(`written:${x.order.id}`))}>Attach it</Button> : <span className="text-[12px] text-muted-foreground">A lead attaches it</span>;
        return canEnter(p) ? <Button data-return={`sb-${x.order.id}`} size="sm" variant="outline" onClick={stop(() => open(`change:${x.order.id}`))}>Re-enter</Button> : <span className="text-[12px] text-muted-foreground">The person who entered it re-enters it</span>;
    };
    const sections: CheckItem['kind'][] = ['allergy', 'check', 'second', 'written', 'sentBack'];
    return (
        <>
            {sections.map((k) => {
                const rows = list.filter((x) => x.kind === k);
                if (!rows.length && k !== 'check') return null;
                return (
                    <section key={k} className="flex flex-col gap-2.5" aria-label={KIND[k].title}>
                        <ListCaption title={KIND[k].title} caption={`${rows.length} shown · ${KIND[k].caption}`} />
                        {rows.length ? (
                            <EntityTable<CheckItem>
                                rows={rows}
                                rowKey={(x) => `${k}-${x.order.id}`}
                                rowHeight="content"
                                minWidth={980}
                                identityLabel="Medicine"
                                identityWidth="1.2fr"
                                identity={(x) => ({ icon: Pill, name: x.order.med, subline: <Wrap>{x.order.strength} · version {x.version.v}</Wrap> })}
                                columns={[
                                    { key: 'who', label: 'Person', width: '0.9fr', cell: (x) => <span className="text-[12.5px]">{PEOPLE[x.order.pid].pref} {PEOPLE[x.order.pid].surname}</span> },
                                    { key: 'what', label: 'What', width: '1.6fr', cell: (x) => <span className="text-[12.5px]">{x.version.changed ?? `${x.version.dose} · ${x.version.when}`}</span> },
                                    {
                                        key: 'src',
                                        label: 'From',
                                        width: '1.5fr',
                                        cell: (x) => (
                                            <span className="flex flex-col gap-0.5 text-[12.5px]">
                                                <span>{SOURCE_LABEL[x.version.source.type]} · {x.version.source.prescriber} · {x.version.source.at}</span>
                                                <StateLine>Entered {x.version.enteredAt} by {x.version.enteredBy}</StateLine>
                                                {k === 'allergy' ? <StateLine>{x.version.allergy!.match}</StateLine> : null}
                                                {k === 'written' ? <StateLine>Due by {x.version.written!.due}</StateLine> : null}
                                                {k === 'second' ? <StateLine>Checked alone by {x.version.checked!.by}: “{x.version.checked!.lone!.reason}”</StateLine> : null}
                                                {k === 'sentBack' ? <StateLine>Sent back by {x.version.sentBack!.by}: “{x.version.sentBack!.reason}”</StateLine> : null}
                                            </span>
                                        ),
                                    },
                                    { key: 'act', label: '', width: '170px', align: 'right', cell: (x) => actionFor(x) },
                                ]}
                                actionsFor={(x) => orderMenu(x.order, s, open)}
                                onOpen={(x) => open(`order:${x.order.id}`)}
                                onRowContextMenu={(e, x) => ctx.openAt(e, `${x.order.med} · ${PEOPLE[x.order.pid].pref}`, orderMenu(x.order, s, open))}
                            />
                        ) : (
                            <Card className="p-2">
                                <EmptyState variant="compact" icon={ClipboardCheck} title="No orders waiting to be checked" description="New and changed orders appear here when they’re entered." />
                            </Card>
                        )}
                    </section>
                );
            })}
            {phone ? (
                <section className="flex flex-col gap-2.5" aria-label="Phone instruction for one dose">
                    <ListCaption title="Phone instruction for one dose" caption="1 shown · recorded while giving a dose — a house or clinical lead countersigns it by the end of the next day" />
                    <Card className="flex-row flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
                        <span>
                            <span className="font-semibold">{PEOPLE[PHONE_INSTRUCTION.pid].pref} · {PHONE_INSTRUCTION.med}</span> — {PHONE_INSTRUCTION.what} · {PHONE_INSTRUCTION.prescriber} by phone {PHONE_INSTRUCTION.at} · recorded by {PHONE_INSTRUCTION.recordedBy} · countersign by {PHONE_INSTRUCTION.due}
                        </span>
                        {canCheck(p) ? (
                            <Button data-return="cs-phone" size="sm" variant="outline" onClick={() => open('countersign:phone')}>
                                Countersign
                            </Button>
                        ) : (
                            <span className="text-[12px] text-muted-foreground">A house or clinical lead countersigns it</span>
                        )}
                    </Card>
                    <DesignNote title="Design note — approved change to P08a, at build (Main, 30 September)">
                        <p>This opens P08a’s approved dialog, unchanged. At build its Countersign gains “Attach the prescriber’s written confirmation (script, email or e-prescription)” by the end of the next day; until then the item shows “Waiting for the prescriber’s written confirmation”.</p>
                    </DesignNote>
                </section>
            ) : null}
            {ctx.node}
        </>
    );
}

/* ───────────── Covert ───────────── */
function CovertView({ orders, empty }: { orders: Order[]; empty: boolean }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const covertOrders = orders.filter((o) => o.covert && statusOf(o, s.rt).state !== 'stopped');
    const history = empty ? [] : [...s.rt.newCovert, ...COVERT].filter((c) => orders.some((o) => o.id === c.orderId) && (s.rt.covert[c.id]?.status ?? c.status) !== 'active');
    if (empty)
        return (
            <Card className="p-2">
                <EmptyState icon={EyeOff} title="No covert authorisations" description="Covert giving is authorised per medicine, by the GP, with a capacity assessment, the welfare guardian or EPOA consulted, and the pharmacist’s advice." />
            </Card>
        );
    return (
        <>
            <section className="flex flex-col gap-2.5" aria-label="Covert authorisations">
                <ListCaption title="Active authorisations" caption={`${covertOrders.length} shown · reviewed every 3 months by default · a follow-up comes 14 days before`} />
                {covertOrders.map((o) => {
                    const c = covertOf(o.id, s.rt, s.route.scenario);
                    if (!c) return null;
                    const st = covertState(c);
                    return (
                        <Card key={o.id} className="gap-3 p-5">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <p className="text-[10px] font-semibold tracking-[0.08em] text-muted-foreground uppercase">{PEOPLE[o.pid].legal} · {HOUSES[PEOPLE[o.pid].house]}</p>
                                    <h2 className="text-section-title">{o.med} {o.strength}</h2>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <StatusBadge variant={st.state === 'overdue' ? 'critical' : st.state === 'due' ? 'warning' : 'success'} className="rounded-[8px]">
                                        {st.state === 'overdue' ? 'Review overdue — blocked' : st.state === 'due' ? 'Review due soon' : 'Authorised'}
                                    </StatusBadge>
                                    {canEnter(p) ? (
                                        <Button size="sm" data-return={`cv-${o.id}`} variant={st.state === 'current' ? 'outline' : 'default'} onClick={() => open(`covert:${o.id}`)}>
                                            Review
                                        </Button>
                                    ) : null}
                                    <Button size="sm" variant="ghost" onClick={() => open(`order:${o.id}`)}>
                                        Open the order
                                    </Button>
                                </div>
                            </div>
                            <p className={st.state === 'current' ? 'text-sm text-muted-foreground' : 'text-sm font-semibold'}>{st.line}</p>
                            <div className="grid gap-3 text-[13px] md:grid-cols-2">
                                <p><span className="text-muted-foreground">Capacity · </span>{c.capacity.outcome} ({c.capacity.by}, {c.capacity.on})</p>
                                <p><span className="text-muted-foreground">Consulted · </span>{c.consulted.map((x) => `${x.name} (${x.role.toLowerCase()}), ${x.on}: ${x.view}`).join(' · ')}</p>
                                <p><span className="text-muted-foreground">Pharmacist · </span>{c.pharmacist.name}, {c.pharmacist.on}: {c.pharmacist.advice}</p>
                                <p><span className="text-muted-foreground">GP · </span>{c.gp.name} authorised it on {c.gp.on}{c.gp.file ? ' · signed form attached' : ''}</p>
                                <p className="md:col-span-2"><span className="text-muted-foreground">How it’s given · </span>{c.method}</p>
                            </div>
                        </Card>
                    );
                })}
            </section>
            <section className="flex flex-col gap-2.5" aria-label="Earlier authorisations">
                <ListCaption title="Earlier authorisations" caption={`${history.length} shown · kept, never deleted`} />
                {history.length ? (
                    <EntityTable<(typeof history)[number]>
                        rows={history}
                        rowKey={(c) => c.id}
                        identityLabel="Authorised"
                        identityWidth="1fr"
                        identity={(c) => ({ icon: EyeOff, name: c.authorised, subline: `${PEOPLE[c.pid].pref} ${PEOPLE[c.pid].surname}` })}
                        columns={[
                            { key: 'm', label: 'How', width: '1.6fr', cell: (c) => <span className="text-[12.5px]">{c.method}</span> },
                            { key: 'e', label: 'Ended', width: '1.4fr', cell: (c) => <span className="text-[12.5px]">{(s.rt.covert[c.id]?.status ?? c.status) === 'revoked' ? `Revoked · “${(s.rt.covert[c.id]?.revoked ?? c.revoked)?.reason}”` : `Replaced at the review of ${c.review}`}</span> },
                        ]}
                        actionsFor={(c) => [{ label: 'Open the order', icon: ClipboardList, onClick: () => open(`order:${c.orderId}`) }]}
                        onOpen={(c) => open(`order:${c.orderId}`)}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={History} title="No earlier authorisations" />
                    </Card>
                )}
            </section>
            <p className="text-caption">An overdue review blocks covert giving until it’s reviewed. Staff see the method and the pharmacist’s advice when they give the medicine.</p>
        </>
    );
}

/* ───────────── Reconciliation ───────────── */
function ReconcileView({ houses, empty }: { houses: House[]; empty: boolean }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const rs = s.route.q.get('rs') ?? 'all';
    const list = empty ? [] : allRecs(s.rt).filter((x) => houses.includes(PEOPLE[x.pid].house)).map((x) => recOf(x, s.rt)).filter((x) => rs === 'all' || (rs === 'open' ? x.status === 'open' : x.status === 'signedOff'));
    return (
        <section className="flex flex-col gap-2.5" aria-label="Reconciliation">
            <ListCaption
                title="Medicines reconciliation"
                caption={`${list.length} shown · moving in, back from hospital, respite and house moves`}
                right={canEnter(p) ? <Button size="sm" data-return="rec-start" onClick={() => open('reconcile:new')}>Start a reconciliation</Button> : undefined}
            />
            {list.length ? (
                <EntityTable<(typeof list)[number]>
                    rows={list}
                    rowKey={(x) => x.id}
                    rowHeight="content"
                    minWidth={980}
                    identityLabel="Person"
                    identityWidth="1fr"
                    identity={(x) => ({ mark: <PersonDisc name={PEOPLE[x.pid].legal} size={30} />, name: `${PEOPLE[x.pid].pref} ${PEOPLE[x.pid].surname}`, subline: HOUSES[PEOPLE[x.pid].house] })}
                    columns={[
                        { key: 'why', label: 'Why', width: '0.9fr', cell: (x) => <span className="text-[12.5px] font-semibold">{REC_REASON[x.reason]}</span> },
                        { key: 'src', label: 'Checked against', width: '1.4fr', cell: (x) => <span className="text-[12.5px]">{x.sources.join(' · ')}</span> },
                        { key: 'items', label: 'Medicines', width: '1fr', cell: (x) => { const undecided = x.items.filter((i) => !x.decisions[i.key]).length; return <span className="text-[12.5px]">{x.items.length} · {undecided ? `${undecided} still to decide` : 'all decided'}</span>; } },
                        {
                            key: 'state',
                            label: 'State',
                            width: '1.3fr',
                            cell: (x) =>
                                x.status === 'open' ? (
                                    <span className="flex flex-col items-start gap-1">
                                        <StatusBadge variant="warning" className="rounded-[8px]">Open</StatusBadge>
                                        <StateLine>Due {x.due} · started {x.started} by {x.by}</StateLine>
                                    </span>
                                ) : (
                                    <span className="flex flex-col items-start gap-1">
                                        <StatusBadge variant="success" className="rounded-[8px]">Signed off</StatusBadge>
                                        <StateLine>{x.signedOff!.at} by {x.signedOff!.by}</StateLine>
                                    </span>
                                ),
                        },
                        { key: 'act', label: '', width: '140px', align: 'right', cell: (x) => (x.status === 'open' && canEnter(p) ? <Button data-return={`rec-${x.id}`} size="sm" onClick={(e) => (e.stopPropagation(), open(`reconcile:${x.id}`))}>Continue</Button> : <Button size="sm" variant="ghost" onClick={(e) => (e.stopPropagation(), open(`reconcile:${x.id}`))}>View</Button>) },
                    ]}
                    actionsFor={(x) => [{ label: x.status === 'open' && canEnter(p) ? 'Continue the reconciliation' : 'View the reconciliation', icon: GitCompare, onClick: () => open(`reconcile:${x.id}`) }]}
                    onOpen={(x) => open(`reconcile:${x.id}`)}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState icon={GitCompare} title={empty ? 'No reconciliations yet' : 'None match'} description="Reconcile medicines when someone moves in, comes back from hospital, arrives or leaves respite, or moves house." />
                </Card>
            )}
            <p className="text-caption">A reconciliation is due before the next dose of any medicine it affects. Its changes become order versions that someone else checks; a lead signs it off. It also asks for the person’s support to be reassessed.</p>
            <DesignNote title="Design note — replaces Respite’s counts-only modal">
                <p>Today reconciliation exists only in Respite, as counts and free text, always saved as “completed” and not linked to the chart (AUDIT 6). Respite check-in keeps its rule: it waits for the arrival reconciliation or an override reason.</p>
            </DesignNote>
        </section>
    );
}
