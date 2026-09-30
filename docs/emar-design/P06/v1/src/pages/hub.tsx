/* Stock & controlled drugs — /emar/stock (plan §2.2 hub 4; Main Q1). The P02
 * hub pattern: PageHeader (not today’s PageHero), a meter row, the filter row
 * and the rail — Stock · Deliveries & orders · Counts · Expiring · Removals,
 * plus Controlled (link-only, P07) for people with controlled-medicine view.
 * Every stock row opens the stock item (lots, movements, pack photos, orders). */
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
import { AlertTriangle, ArrowLeftRight, CalendarClock, ClipboardCheck, ClipboardList, Clock3, Home, Package, PackageCheck, PackageMinus, Pill, RefreshCw, ShieldCheck, Trash2, Truck, type LucideIcon } from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';
import { HOUSES, ITEMS, MOVE_LABEL, PEOPLE, PERSONAS, type House, type Lot, type Movement, type PharmacyOrder, type StockItem } from '../data';
import { useOpen } from '../host';
import {
    allCounts,
    allMovements,
    allOrders,
    awaitingSignOff,
    canManage,
    canReceive,
    cdView,
    concealed,
    daysFrom,
    daysOfSupply,
    differences,
    itemOf,
    lotsOf,
    needsAttention,
    onHand,
    openLots,
    receivable,
    REMOVAL_KINDS,
    SETTINGS,
    statusOf,
    unitLabel,
} from '../model';
import { Shell } from '../shell';
import { useStore } from '../store';
import { ConcealedCount, DefaultChip, DesignNote, Notice, OrderStateBadge, StateLine, StockBadge, Wrap } from '../ui';

type View = 'stock' | 'orders' | 'counts' | 'expiring' | 'removals' | 'controlled';

export function useCtx() {
    const [ctx, setCtx] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const openAt = (e: MouseEvent, title: string, items: MenuItem[]) => {
        if (e.target instanceof Element && e.target.closest('a[href]')) return;
        e.preventDefault();
        const b = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const kb = e.clientX === 0 && e.clientY === 0;
        setCtx({ x: kb ? b.left + 12 : e.clientX, y: kb ? b.top + 12 : e.clientY, title, items });
    };
    return { openAt, node: ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={Package} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null };
}
const who = (i: StockItem) => `${PEOPLE[i.pid].pref} ${PEOPLE[i.pid].surname}`;
const stop = (fn: () => void) => (e: MouseEvent) => (e.stopPropagation(), fn());

export function HubPage() {
    const s = useStore();
    const r = s.route;
    const p = r.persona;
    const me = PERSONAS[p];
    const open = useOpen();
    const [search, setSearch] = useState('');
    const cd = cdView(p);
    const RAIL: { key: View; label: string; icon: LucideIcon }[] = [
        { key: 'stock', label: 'Stock', icon: Package },
        { key: 'orders', label: 'Deliveries & orders', icon: Truck },
        { key: 'counts', label: 'Counts', icon: ClipboardCheck },
        { key: 'expiring', label: 'Expiring', icon: CalendarClock },
        { key: 'removals', label: 'Removals', icon: PackageMinus },
        ...(cd ? [{ key: 'controlled' as const, label: 'Controlled', icon: ShieldCheck }] : []),
    ];
    const view = (RAIL.some((x) => x.key === r.q.get('view')) ? r.q.get('view') : 'stock') as View;
    const houseParam = r.q.get('house') as House | null;
    const houses = me.houses.filter((h) => !houseParam || h === houseParam);
    const scn = r.scenario;
    const loading = scn === 'loading';
    const failed = scn === 'unavailable';
    const empty = scn === 'empty';
    const dash = loading ? '—' : failed ? 'Unavailable' : null;
    const inScope = empty ? [] : ITEMS.filter((i) => houses.includes(PEOPLE[i.pid].house));
    const visible = inScope.filter((i) => !concealed(i, p));
    const hiddenCd = inScope.length - visible.length;
    const tracked = visible.filter((i) => !i.selfManaged);
    const low = tracked.filter((i) => ['low', 'out'].includes(statusOf(i, s.rt).state));
    const soonLots = tracked.flatMap((i) => openLots(i, s.rt).filter((l) => l.expiryIso && daysFrom(l.expiryIso) <= SETTINGS.expiryWarn).map((l) => ({ i, l })));
    const orders = empty ? [] : allOrders(s.rt).filter((o) => houses.includes(o.house) && (!o.cd || cd));
    const toReceive = orders.filter(receivable);
    const openOrders = orders.filter((o) => o.state === 'sent' || o.state === 'draft');
    const toSign = empty ? [] : allCounts(s.rt).filter((c) => houses.includes(c.house) && awaitingSignOff(c));
    const houseText = me.houses.length > 1 && !houseParam ? `${HOUSES[me.houses[0]]} and ${HOUSES[me.houses[1]]}` : HOUSES[houses[0]];
    const go = (v: View, extra: Record<string, string | undefined> = {}) => s.set({ view: v === 'stock' ? undefined : v, open: undefined, ...extra });
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
        stock: (
            <>
                {houseFilter}
                <PageHeaderFilterSelect label="Show" value={r.q.get('show') ?? 'all'} allValue="all" onChange={(v) => s.set({ show: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All medicines' }, { value: 'attention', label: 'Needs attention' }, { value: 'low', label: 'Running low or out' }, { value: 'self', label: 'Self-managed (not counted)' }]} />
                {asAt}
            </>
        ),
        orders: (
            <>
                {houseFilter}
                {asAt}
            </>
        ),
        counts: (
            <>
                {houseFilter}
                {asAt}
            </>
        ),
        expiring: (
            <>
                {houseFilter}
                {asAt}
            </>
        ),
        removals: (
            <>
                {houseFilter}
                {asAt}
            </>
        ),
        controlled: asAt,
    };
    const header = (
        <PageHeader
            variant="index"
            icon={Package}
            title="Stock & controlled drugs"
            titleChip={<PageHeaderStatusChip variant="neutral">{me.houses.length > 1 && !houseParam ? '2 houses' : '1 house'}</PageHeaderStatusChip>}
            subline={`Medicines held for people at ${houseText} · times in NZDT`}
            actions={
                <>
                    <PageHeaderSearch value={search} onChange={setSearch} placeholder="Search people, medicines or batches…" />
                    {canReceive(p) && !failed ? (
                        <PageHeaderPrimaryButton icon={PackageCheck} onClick={() => open('receive:new')}>
                            Receive a delivery
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock label="Running low" tone={!dash && low.length ? 'warning' : 'brand'} onClick={() => go('stock', { show: 'low' })} ariaLabel={`View ${low.length} medicines running low`}>
                        <PageHeaderMeterBig>{dash ?? low.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : low.length ? `First: ${low[0].med}` : 'Nothing low'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Expiring" tone={!dash && soonLots.some((x) => daysFrom(x.l.expiryIso!) < 0) ? 'critical' : !dash && soonLots.length ? 'warning' : 'brand'} onClick={() => go('expiring')} ariaLabel={`View ${soonLots.length} packs expiring within ${SETTINGS.expiryWarn} days`}>
                        <PageHeaderMeterBig>{dash ?? soonLots.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : soonLots.some((x) => daysFrom(x.l.expiryIso!) < 0) ? `${soonLots.filter((x) => daysFrom(x.l.expiryIso!) < 0).length} expired · remove` : soonLots.length ? `Within ${SETTINGS.expiryWarn} days` : 'None within 30 days'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="To receive" tone={!dash && toReceive.length ? 'warning' : 'brand'} onClick={() => go('orders')} ariaLabel={`View ${toReceive.length} deliveries to receive`}>
                        <PageHeaderMeterBig>{dash ?? toReceive.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : toReceive.some((o) => o.state === 'dispensed') ? `${toReceive.filter((o) => o.state === 'dispensed').length} due today by 12:00 pm` : toReceive.length ? 'The rest of a part delivery' : 'Nothing due'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Counts to sign off" tone={!dash && toSign.length ? 'warning' : 'brand'} onClick={() => go('counts')} ariaLabel={`View ${toSign.length} counts to sign off`}>
                        <PageHeaderMeterBig>{dash ?? toSign.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : toSign.length ? 'Due by the end of today' : 'None waiting'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Pharmacy orders" onClick={() => go('orders')} ariaLabel={`View ${openOrders.length} open pharmacy orders`}>
                        <PageHeaderMeterBig>{dash ?? openOrders.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : openOrders.some((o) => o.state === 'draft') ? `${openOrders.filter((o) => o.state === 'draft').length} draft not sent` : 'Sent, waiting'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Medicines in stock" onClick={() => go('stock', { show: undefined })} ariaLabel="View all medicines in stock">
                        <PageHeaderMeterBig>{dash ?? tracked.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : hiddenCd ? `+${hiddenCd} controlled hidden` : `${new Set(tracked.map((i) => i.pid)).size} people`}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={filters[view]}
            rail={<PageHeaderRail<View> items={RAIL.map((x) => ({ ...x, count: x.key === 'orders' && !dash && toReceive.length ? toReceive.length : x.key === 'counts' && !dash && toSign.length ? toSign.length : undefined }))} value={view} onSelect={(k) => go(k)} ariaLabel="Stock views" />}
        />
    );
    let body: ReactNode;
    if (loading)
        body = (
            <Card className="p-4" aria-busy="true" aria-label="Loading stock">
                <SkeletonTable rows={7} columns={6} />
            </Card>
        );
    else if (failed)
        body = (
            <Card className="p-2">
                <ErrorState title="We couldn’t load stock for your houses" message="Nothing is shown rather than an incomplete list. Keep giving doses from the packs in the cabinet, and receive deliveries when this is back." onRetry={() => s.setViewer({ scenario: 'normal' })} />
            </Card>
        );
    else if (view === 'controlled')
        body = (
            <Card className="gap-3 p-5">
                <p className="text-section-title">Controlled medicines are counted and recorded in their register</p>
                <p className="text-subtle">Counts and movements: Meds today › Controlled checks. The register, adjustments and destruction: the controlled register. Controlled deliveries are received here, as a register entry with a witness.</p>
                <DesignNote title="Design note — link-only (Main, Q9)">
                    <p>Controlled checks are P07a’s approved view; the register, adjustments and destruction are P07b. P06 owns only the controlled receipt.</p>
                </DesignNote>
            </Card>
        );
    else if (view === 'orders') body = <OrdersView orders={orders} empty={empty} search={search} />;
    else if (view === 'counts') body = <CountsView houses={houses} empty={empty} />;
    else if (view === 'expiring') body = <ExpiringView items={tracked} empty={empty} search={search} />;
    else if (view === 'removals') body = <RemovalsView items={visible} empty={empty} />;
    else body = <StockView items={visible} hiddenCd={hiddenCd} empty={empty} search={search} />;
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/emar/stock' }, { title: 'Stock & controlled drugs' }]}>
            {header}
            {scn === 'stale' ? (
                <Notice tone="warning" icon={AlertTriangle} title="This may be out of date" actions={<Button size="sm" variant="outline" onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}><RefreshCw className="size-4" /> Try again</Button>}>
                    We couldn’t refresh at 9:12 am. It shows what was known at 8:40 am. Don’t receive, count or remove stock until it’s up to date.
                </Notice>
            ) : null}
            {body}
        </Shell>
    );
}

/* ───────────── Stock ───────────── */
export function itemMenu(i: StockItem, s: ReturnType<typeof useStore>, open: (x: string) => void): MenuItem[] {
    const p = s.route.persona;
    const st = statusOf(i, s.rt).state;
    if (i.cd)
        return compactMenu([
            { label: 'Open the stock item', icon: Package, onClick: () => open(`item:${i.id}`) },
            { label: 'Count in Controlled checks', icon: ClipboardCheck, onClick: () => s.toast('info', 'Controlled counts are P07a’s approved Controlled checks view — outside this preview.') },
            { label: 'Open the controlled register', icon: ShieldCheck, onClick: () => s.toast('info', 'The controlled register, adjustments and destruction are designed in P07b — outside this preview.') },
        ]);
    return compactMenu([
        { label: 'Open the stock item', icon: Package, onClick: () => open(`item:${i.id}`) },
        !i.selfManaged && canReceive(p) && { label: 'Receive a delivery', icon: PackageCheck, onClick: () => open(`receive:item:${i.id}`) },
        !i.selfManaged && canReceive(p) && lotsOf(i, s.rt).length > 0 && { label: 'Count it', icon: ClipboardCheck, onClick: () => open(`count:${i.id}`) },
        !i.selfManaged && canReceive(p) && onHand(i, s.rt) > 0 && { label: 'Going out or coming back', icon: ArrowLeftRight, onClick: () => open(`move:${i.id}`) },
        !i.selfManaged && canManage(p) && { separator: true },
        !i.selfManaged && canManage(p) && { label: 'Order from the pharmacy', icon: ClipboardList, onClick: () => open(`order:new:${i.id}`) },
        !i.selfManaged && canManage(p) && lotsOf(i, s.rt).some((l) => l.qty > 0) && { label: st === 'expired' ? 'Remove the expired pack' : 'Adjust or remove', icon: Trash2, onClick: () => open(`adjust:${i.id}`) },
    ]);
}
function actionFor(i: StockItem, s: ReturnType<typeof useStore>, open: (x: string) => void): ReactNode {
    const p = s.route.persona;
    const st = statusOf(i, s.rt).state;
    if (i.cd) return <span className="text-right text-[12px] text-muted-foreground">In the controlled register</span>;
    if (st === 'arriving' && canReceive(p))
        return (
            <Button size="sm" data-return={`rcv-${i.id}`} onClick={stop(() => open(`receive:item:${i.id}`))}>
                Receive
            </Button>
        );
    if (st === 'expired' && canManage(p))
        return (
            <Button size="sm" variant="outline" data-return={`rm-${i.id}`} onClick={stop(() => open(`adjust:${i.id}`))}>
                Remove expired
            </Button>
        );
    if (st === 'countDiff' && canManage(p)) {
        const c = allCounts(s.rt).find((x) => awaitingSignOff(x) && x.lines.some((l) => l.itemId === i.id));
        return c ? (
            <Button size="sm" data-return={`so-${c.id}`} onClick={stop(() => open(`signoff:${c.id}`))}>
                Sign off count
            </Button>
        ) : null;
    }
    if ((st === 'none' || st === 'out' || st === 'low') && canManage(p) && !allOrders(s.rt).some((o) => o.itemId === i.id && ['sent', 'dispensed', 'part'].includes(o.state))) {
        const draft = allOrders(s.rt).find((o) => o.itemId === i.id && o.state === 'draft');
        return (
            <Button size="sm" variant="outline" data-return={`ord-${i.id}`} onClick={stop(() => open(draft ? `po:${draft.id}` : `order:new:${i.id}`))}>
                {draft ? 'Send the order' : 'Order'}
            </Button>
        );
    }
    return null;
}
function StockView({ items, hiddenCd, empty, search }: { items: StockItem[]; hiddenCd: number; empty: boolean; search: string }) {
    const s = useStore();
    const open = useOpen();
    const ctx = useCtx();
    const show = s.route.q.get('show') ?? 'all';
    const q = search.trim().toLowerCase();
    const list = items
        .filter((i) => {
            const st = statusOf(i, s.rt).state;
            if (show === 'attention') return needsAttention(st);
            if (show === 'low') return st === 'low' || st === 'out';
            if (show === 'self') return st === 'selfManaged';
            return true;
        })
        .filter((i) => !q || `${i.med} ${PEOPLE[i.pid].legal} ${PEOPLE[i.pid].pref} ${lotsOf(i, s.rt).map((l) => l.batch).join(' ')}`.toLowerCase().includes(q));
    if (empty)
        return (
            <Card className="p-2">
                <EmptyState icon={Package} title="No stock recorded at your houses yet" description="Stock appears here when a delivery is received — from a pharmacy order, or brought in by the person or whānau." />
            </Card>
        );
    return (
        <>
            <section className="flex flex-col gap-2.5" aria-label="Stock">
                <ListCaption
                    title={show === 'low' ? 'Running low or out' : show === 'attention' ? 'Needs attention' : show === 'self' ? 'Self-managed — not counted' : 'Medicines held'}
                    caption={
                        <>
                            {list.length} shown · {new Set(list.map((i) => i.pid)).size} {new Set(list.map((i) => i.pid)).size === 1 ? 'person' : 'people'}
                            {hiddenCd ? <> · <ConcealedCount n={hiddenCd} /></> : null}
                        </>
                    }
                />
                {list.length ? (
                    <EntityTable<StockItem>
                        rows={list}
                        rowKey={(i) => i.id}
                        rowHeight="content"
                        minWidth={1000}
                        identityLabel="Medicine"
                        identityWidth="1.25fr"
                        identity={(i) => ({ icon: Pill, name: i.med, subline: <Wrap>{i.strength}{i.cd ? ' · controlled' : ''}{i.coldChain ? ' · fridge' : ''}{i.covert ? ' · covert plan' : ''}</Wrap> })}
                        columns={[
                            { key: 'who', label: 'Person', width: '0.9fr', cell: (i) => <span className="flex items-center gap-2 text-[12.5px]"><PersonDisc name={PEOPLE[i.pid].legal} size={22} />{who(i)}</span> },
                            {
                                key: 'have',
                                label: 'On hand',
                                width: '0.9fr',
                                cell: (i) => {
                                    if (i.selfManaged) return <span className="text-[12.5px] text-muted-foreground">Not counted</span>;
                                    if (!lotsOf(i, s.rt).length) return <span className="text-[12.5px] text-muted-foreground">None yet</span>;
                                    const d = daysOfSupply(i, s.rt);
                                    return (
                                        <span className="flex flex-col text-[12.5px]">
                                            <span className="font-semibold">{unitLabel(i, onHand(i, s.rt))}</span>
                                            <span className="text-muted-foreground">{d != null ? `${d} days’ supply` : i.reorderLevel != null ? `As needed · reorder at ${i.reorderLevel}` : 'As needed'}</span>
                                        </span>
                                    );
                                },
                            },
                            {
                                key: 'lots',
                                label: 'Packs',
                                width: '0.9fr',
                                cell: (i) => {
                                    const ls = openLots(i, s.rt);
                                    if (!ls.length) return <span className="text-[12.5px] text-muted-foreground">{i.selfManaged ? '—' : 'None yet'}</span>;
                                    const first = [...ls].sort((a, b) => (a.expiryIso ?? '9').localeCompare(b.expiryIso ?? '9'))[0];
                                    return (
                                        <span className="flex flex-col text-[12.5px]">
                                            <span>{ls.length} {ls.length === 1 ? 'pack' : 'packs'}</span>
                                            <span className="text-muted-foreground">First: {first.expiry ?? 'no expiry printed'}</span>
                                        </span>
                                    );
                                },
                            },
                            {
                                key: 'state',
                                label: 'State',
                                width: '1.7fr',
                                cell: (i) => {
                                    const st = statusOf(i, s.rt);
                                    return (
                                        <span className="flex flex-col items-start gap-1 py-0.5">
                                            <StockBadge state={st.state} />
                                            {st.lines.map((l) => (
                                                <StateLine key={l}>{l}</StateLine>
                                            ))}
                                        </span>
                                    );
                                },
                            },
                            { key: 'act', label: '', width: '150px', align: 'right', cell: (i) => actionFor(i, s, open) },
                        ]}
                        actionsFor={(i) => itemMenu(i, s, open)}
                        onOpen={(i) => open(`item:${i.id}`)}
                        onRowContextMenu={(e, i) => ctx.openAt(e, `${i.med} · ${PEOPLE[i.pid].pref}`, itemMenu(i, s, open))}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={Package} title="Nothing matches" description="Change the Show filter or clear the search." />
                    </Card>
                )}
                <p className="text-caption">
                    On hand is the sum of the open packs; doses come off the pack that expires first. Low stock: under <DefaultChip value={`${SETTINGS.lowDays} days’ supply`} /> for regular doses, or at the reorder level for as-needed ones.
                </p>
            </section>
            <RecentMovements items={items} />
            {ctx.node}
            <DesignNote title="Design note — replaces today’s /emar/stock">
                <p>Today’s page is a PageHero (“Medication stock for {'{site}'}”) over one stock row per medicine with a single batch and expiry, which every pharmacy delivery overwrites with blanks (AUDIT 2.3). Here stock is held in packs (lots), each with its own batch and expiry (Main: EM-10, Q2).</p>
            </DesignNote>
        </>
    );
}

function RecentMovements({ items }: { items: StockItem[] }) {
    const s = useStore();
    const ids = new Set(items.map((i) => i.id));
    const list = allMovements(s.rt).filter((m) => ids.has(m.itemId)).slice(0, 8);
    return (
        <section className="flex flex-col gap-2.5" aria-label="Recent movements">
            <ListCaption title="Recent movements" caption={`${list.length} shown · newest first · kept, never deleted`} />
            <EntityTable<Movement>
                rows={list}
                rowKey={(m) => m.id}
                rowHeight="content"
                minWidth={1000}
                identityLabel="When"
                identityWidth="150px"
                identity={(m) => ({ icon: ArrowLeftRight, name: m.at.split(', ')[0], subline: m.at.split(', ')[1] ?? '' })}
                columns={[
                    { key: 'med', label: 'Medicine', width: '1.1fr', cell: (m) => <span className="text-[12.5px]"><span className="font-semibold">{itemOf(m.itemId).med}</span> · {PEOPLE[itemOf(m.itemId).pid].pref}</span> },
                    { key: 'what', label: 'What happened', width: '2fr', cell: (m) => <span className="flex flex-col text-[12.5px]"><span className="font-semibold">{MOVE_LABEL[m.kind]}{m.qty ? ` · ${m.qty > 0 ? '+' : ''}${unitLabel(itemOf(m.itemId), m.qty)}` : ''}</span>{m.note ? <span className="text-muted-foreground">{m.note}</span> : null}</span> },
                    { key: 'by', label: 'By', width: '0.8fr', cell: (m) => <span className="text-[12.5px]">{m.by}</span> },
                ]}
                actionsFor={(m) => [{ label: 'Open the stock item', icon: Package, onClick: () => s.set({ open: `item:${m.itemId}` }) }]}
                onOpen={(m) => s.set({ open: `item:${m.itemId}` })}
            />
        </section>
    );
}

/* ───────────── Deliveries & orders (Q4) ───────────── */
function orderLines(o: PharmacyOrder): string[] {
    const i = itemOf(o.itemId);
    if (o.state === 'draft') return [`Drafted ${o.createdAt} by ${o.createdBy} — not sent to the pharmacy yet`];
    if (o.state === 'sent') return [`Sent ${o.sentAt}${o.neededBy ? ` · needed by ${o.neededBy}` : ''} — waiting for the pharmacy`];
    if (o.state === 'dispensed') return [`${o.pharmacy} dispensed ${unitLabel(i, o.dispensed!.qty)} ${o.dispensed!.at} · batch ${o.dispensed!.batch ?? 'not printed'}, expires ${o.dispensed!.expiry ?? 'not printed'}`, `Delivery due ${o.expected}`];
    if (o.state === 'part') return [`${unitLabel(i, o.receipts!.reduce((n, r) => n + r.qty, 0))} received ${o.receipts![0].at} by ${o.receipts![0].by}`, `${o.stillToCome} still to come — expected ${o.expected}`];
    if (o.state === 'received') return [`Received ${o.receipts![o.receipts!.length - 1].at} by ${o.receipts![o.receipts!.length - 1].by} — read only`];
    return [`${o.state === 'cancelled' ? 'Cancelled' : 'Closed short'} ${o.ended!.at} by ${o.ended!.by}: “${o.ended!.reason}”`];
}
function orderMenu(o: PharmacyOrder, s: ReturnType<typeof useStore>, open: (x: string) => void): MenuItem[] {
    const p = s.route.persona;
    return compactMenu([
        { label: 'Open the order', icon: ClipboardList, onClick: () => open(`po:${o.id}`) },
        receivable(o) && canReceive(p) && { label: o.cd ? 'Receive into the register' : 'Receive it', icon: PackageCheck, onClick: () => open(`receive:${o.id}`) },
        o.state === 'sent' && canManage(p) && { label: 'Record what the pharmacy dispensed', icon: Package, onClick: () => open(`dispensed:${o.id}`) },
        o.state === 'draft' && canManage(p) && { label: 'Send to the pharmacy', icon: Truck, onClick: () => open(`po:${o.id}`) },
        o.state === 'part' && canManage(p) && { label: 'Close it short', icon: PackageMinus, onClick: () => open(`short:${o.id}`) },
        (o.state === 'draft' || o.state === 'sent') && canManage(p) && { separator: true },
        (o.state === 'draft' || o.state === 'sent') && canManage(p) && { label: 'Cancel the order', icon: Trash2, onClick: () => open(`cancel:${o.id}`), danger: true },
    ]);
}
const ORDER_SECTIONS: { key: string; title: string; caption: string; states: PharmacyOrder['state'][] }[] = [
    { key: 'receive', title: 'To receive', caption: 'Dispensed by the pharmacy, or part received', states: ['dispensed', 'part'] },
    { key: 'sent', title: 'Waiting on the pharmacy', caption: 'Sent — record what they dispense when the label arrives', states: ['sent'] },
    { key: 'draft', title: 'Drafts', caption: 'Not sent yet', states: ['draft'] },
    { key: 'closed', title: 'Closed', caption: 'Received, closed short or cancelled — read only, kept', states: ['received', 'closedShort', 'cancelled'] },
];
function OrdersView({ orders, empty, search }: { orders: PharmacyOrder[]; empty: boolean; search: string }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const ctx = useCtx();
    const q = search.trim().toLowerCase();
    const list = orders.filter((o) => !q || `${o.id} ${itemOf(o.itemId).med} ${PEOPLE[itemOf(o.itemId).pid].legal}`.toLowerCase().includes(q));
    if (empty)
        return (
            <Card className="p-2">
                <EmptyState icon={Truck} title="No pharmacy orders yet" description="Order from the pharmacy here. Each order is one supply record, from draft to received." />
            </Card>
        );
    const act = (o: PharmacyOrder) => {
        if (receivable(o))
            return canReceive(p) ? (
                o.cd && !canManage(p) ? (
                    <span className="text-right text-[12px] text-muted-foreground">The house lead receives it, with a witness</span>
                ) : (
                    <Button size="sm" data-return={`rcv-${o.id}`} onClick={stop(() => open(`receive:${o.id}`))}>
                        Receive
                    </Button>
                )
            ) : null;
        if (o.state === 'sent' && canManage(p))
            return (
                <Button size="sm" variant="outline" data-return={`dsp-${o.id}`} onClick={stop(() => open(`dispensed:${o.id}`))}>
                    Record dispensing
                </Button>
            );
        if (o.state === 'draft' && canManage(p))
            return (
                <Button size="sm" variant="outline" data-return={`po-${o.id}`} onClick={stop(() => open(`po:${o.id}`))}>
                    Review and send
                </Button>
            );
        return null;
    };
    return (
        <>
            {ORDER_SECTIONS.map((sec, n) => {
                const rows = list.filter((o) => sec.states.includes(o.state));
                return (
                    <section key={sec.key} className="flex flex-col gap-2.5" aria-label={sec.title}>
                        <ListCaption
                            title={sec.title}
                            caption={`${rows.length} shown · ${sec.caption}`}
                            right={
                                n === 0 && canManage(p) ? (
                                    <Button size="sm" data-return="order-new" onClick={() => open('order:new')}>
                                        Order from the pharmacy
                                    </Button>
                                ) : undefined
                            }
                        />
                        {rows.length ? (
                            <EntityTable<PharmacyOrder>
                                rows={rows}
                                rowKey={(o) => o.id}
                                rowHeight="content"
                                minWidth={1000}
                                identityLabel="Order"
                                identityWidth="1.1fr"
                                identity={(o) => ({ icon: o.cd ? ShieldCheck : ClipboardList, name: itemOf(o.itemId).med, subline: <Wrap>{o.id} · {itemOf(o.itemId).strength}{o.cd ? ' · controlled' : ''}</Wrap> })}
                                columns={[
                                    { key: 'who', label: 'Person', width: '0.8fr', cell: (o) => <span className="text-[12.5px]">{PEOPLE[itemOf(o.itemId).pid].pref} {PEOPLE[itemOf(o.itemId).pid].surname}</span> },
                                    { key: 'qty', label: 'Ordered', width: '0.6fr', cell: (o) => <span className="text-[12.5px]">{unitLabel(itemOf(o.itemId), o.qty)}</span> },
                                    {
                                        key: 'state',
                                        label: 'State',
                                        width: '2fr',
                                        cell: (o) => (
                                            <span className="flex flex-col items-start gap-1 py-0.5">
                                                <OrderStateBadge state={o.state} />
                                                {orderLines(o).map((l) => (
                                                    <StateLine key={l}>{l}</StateLine>
                                                ))}
                                            </span>
                                        ),
                                    },
                                    { key: 'act', label: '', width: '170px', align: 'right', cell: act },
                                ]}
                                actionsFor={(o) => orderMenu(o, s, open)}
                                onOpen={(o) => open(`po:${o.id}`)}
                                onRowContextMenu={(e, o) => ctx.openAt(e, `${o.id} · ${itemOf(o.itemId).med}`, orderMenu(o, s, open))}
                            />
                        ) : (
                            <Card className="p-2">
                                <EmptyState variant="compact" icon={Truck} title={`Nothing ${sec.key === 'receive' ? 'to receive' : sec.key === 'sent' ? 'waiting on the pharmacy' : sec.key === 'draft' ? 'drafted' : 'closed yet'}`} />
                            </Card>
                        )}
                    </section>
                );
            })}
            {ctx.node}
            <DesignNote title="Design note — one supply record per order (Main, Q4)">
                <p>Today an order moves draft → submitted → confirmed → dispensed → delivered with no partial, short or cancelled state, and prescriber-order dispensing is a separate record (AUDIT 3.4). Here the pharmacy’s dispensing is a step of the order, and receiving creates the packs.</p>
            </DesignNote>
        </>
    );
}

/* ───────────── Counts (Q7) ───────────── */
function CountsView({ houses, empty }: { houses: House[]; empty: boolean }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const counts = empty ? [] : allCounts(s.rt).filter((c) => houses.includes(c.house));
    const waiting = counts.filter(awaitingSignOff);
    const done = counts.filter((c) => !awaitingSignOff(c));
    return (
        <>
            <section className="flex flex-col gap-2.5" aria-label="To sign off">
                <ListCaption
                    title="Differences to sign off"
                    caption={`${waiting.length} shown · the house lead signs off every difference (a follow-up, due by the end of the day)`}
                    right={
                        canReceive(p) ? (
                            <Button size="sm" data-return="count-house" onClick={() => open('count:house')}>
                                Count stock
                            </Button>
                        ) : undefined
                    }
                />
                {waiting.length ? (
                    <EntityTable<(typeof waiting)[number]>
                        rows={waiting}
                        rowKey={(c) => c.id}
                        rowHeight="content"
                        minWidth={1000}
                        identityLabel="Counted"
                        identityWidth="150px"
                        identity={(c) => ({ icon: ClipboardCheck, name: c.at.split(', ')[0], subline: c.at.split(', ')[1] ?? '' })}
                        columns={[
                            { key: 'what', label: 'Difference', width: '1.8fr', cell: (c) => <span className="flex flex-col gap-0.5 text-[12.5px]">{differences(c).map((l) => <span key={l.itemId}><span className="font-semibold">{itemOf(l.itemId).med} · {PEOPLE[itemOf(l.itemId).pid].pref}</span> — counted {l.counted}, expected {l.expected}</span>)}</span> },
                            { key: 'why', label: 'Reason given', width: '1.8fr', cell: (c) => <span className="flex flex-col gap-0.5 text-[12.5px]">{differences(c).map((l) => <span key={l.itemId}>{l.reason}{l.note ? ` — ${l.note}` : ''}</span>)}</span> },
                            { key: 'by', label: 'Counted by', width: '0.8fr', cell: (c) => <span className="text-[12.5px]">{c.by}</span> },
                            { key: 'act', label: '', width: '150px', align: 'right', cell: (c) => (canManage(p) ? <Button size="sm" data-return={`so-${c.id}`} onClick={stop(() => open(`signoff:${c.id}`))}>Sign off</Button> : <span className="text-[12px] text-muted-foreground">The house lead signs it off</span>) },
                        ]}
                        actionsFor={(c) => [{ label: canManage(p) ? 'Sign off the difference' : 'View the count', icon: ClipboardCheck, onClick: () => open(`signoff:${c.id}`) }]}
                        onOpen={(c) => open(`signoff:${c.id}`)}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={ClipboardCheck} title="No differences waiting" description="A count that matches is done straight away." />
                    </Card>
                )}
            </section>
            <section className="flex flex-col gap-2.5" aria-label="Recent counts">
                <ListCaption title="Recent counts" caption={`${done.length} shown · kept`} />
                {done.length ? (
                    <EntityTable<(typeof done)[number]>
                        rows={done}
                        rowKey={(c) => c.id}
                        rowHeight="content"
                        minWidth={1000}
                        identityLabel="Counted"
                        identityWidth="150px"
                        identity={(c) => ({ icon: ClipboardCheck, name: c.at.split(', ')[0], subline: c.at.split(', ')[1] ?? '' })}
                        columns={[
                            { key: 'what', label: 'What', width: '1.6fr', cell: (c) => <span className="text-[12.5px]">{c.scope === 'house' ? `Whole house · ${c.lines.length} medicines · ${HOUSES[c.house]}` : `${itemOf(c.lines[0].itemId).med} · ${PEOPLE[itemOf(c.lines[0].itemId).pid].pref}`}</span> },
                            { key: 'res', label: 'Result', width: '1.6fr', cell: (c) => <span className="flex flex-col items-start gap-1"><StatusBadge variant="success" className="rounded-[8px]">{differences(c).length ? 'Signed off' : 'All matched'}</StatusBadge>{differences(c).length && c.signOff ? <StateLine>{c.signOff.by}, {c.signOff.at} — {c.signOff.outcome === 'accepted' ? 'stock set to the count' : 'recount asked for'}</StateLine> : null}</span> },
                            { key: 'by', label: 'Counted by', width: '0.8fr', cell: (c) => <span className="text-[12.5px]">{c.by}</span> },
                        ]}
                        actionsFor={() => []}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={ClipboardCheck} title="No counts yet" />
                    </Card>
                )}
            </section>
            <Card className="flex-row flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
                <span>
                    <span className="font-semibold">Scheduled counts</span> <DefaultChip value="Off" />
                </span>
                <span className="text-caption">An organisation can turn on a weekly house count in Settings › Stock.</span>
            </Card>
            <DesignNote title="Design note — blind counts (Main, Q7)">
                <p>Today a stock count is an absolute adjustment with the reason “Physical stock count”, and every scheduled count shows as a discrepancy because “0.00” is truthy (AUDIT 3.2, 8.8). Here counts are blind: you enter what you count, then see what was expected.</p>
            </DesignNote>
        </>
    );
}

/* ───────────── Expiring (Q2, Q8) ───────────── */
function ExpiringView({ items, empty, search }: { items: StockItem[]; empty: boolean; search: string }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const q = search.trim().toLowerCase();
    const rows = items.flatMap((i) => openLots(i, s.rt).filter((l) => l.expiryIso && daysFrom(l.expiryIso) <= SETTINGS.expiryWarn).map((l) => ({ i, l, d: daysFrom(l.expiryIso!) }))).filter((x) => !q || `${x.i.med} ${PEOPLE[x.i.pid].legal} ${x.l.batch}`.toLowerCase().includes(q)).sort((a, b) => a.d - b.d);
    const bands: { key: string; title: string; caption: string; rows: typeof rows }[] = [
        { key: 'expired', title: 'Expired — take out of use', caption: 'Not given, and not counted as stock', rows: rows.filter((x) => x.d < 0) },
        { key: 'seven', title: `Within ${SETTINGS.expiryCritical} days`, caption: 'Use these packs first', rows: rows.filter((x) => x.d >= 0 && x.d <= SETTINGS.expiryCritical) },
        { key: 'thirty', title: `Within ${SETTINGS.expiryWarn} days`, caption: 'Doses come off these packs first', rows: rows.filter((x) => x.d > SETTINGS.expiryCritical) },
    ];
    if (empty)
        return (
            <Card className="p-2">
                <EmptyState icon={CalendarClock} title="Nothing expiring" description="Packs expiring within 30 days appear here." />
            </Card>
        );
    return (
        <>
            {bands.map((b) => (
                <section key={b.key} className="flex flex-col gap-2.5" aria-label={b.title}>
                    <ListCaption title={b.title} caption={`${b.rows.length} shown · ${b.caption}`} />
                    {b.rows.length ? (
                        <EntityTable<(typeof rows)[number]>
                            rows={b.rows}
                            rowKey={(x) => x.l.id}
                            rowHeight="content"
                            minWidth={1000}
                            identityLabel="Pack"
                            identityWidth="1fr"
                            identity={(x) => ({ icon: CalendarClock, name: x.l.batch ?? 'No batch printed', subline: x.l.expiry ? `Expires ${x.l.expiry}` : 'No expiry printed' })}
                            columns={[
                                { key: 'med', label: 'Medicine', width: '1.2fr', cell: (x) => <span className="text-[12.5px]"><span className="font-semibold">{x.i.med}</span> {x.i.strength}</span> },
                                { key: 'who', label: 'Person', width: '0.8fr', cell: (x) => <span className="text-[12.5px]">{who(x.i)}</span> },
                                { key: 'qty', label: 'In the pack', width: '0.6fr', cell: (x) => <span className="text-[12.5px]">{unitLabel(x.i, x.l.qty)}</span> },
                                { key: 'when', label: 'When', width: '1.1fr', cell: (x) => <span className="flex flex-col items-start gap-1"><StatusBadge variant={x.d < 0 ? 'critical' : x.d <= SETTINGS.expiryCritical ? 'warning' : 'info'} className="rounded-[8px]">{x.d < 0 ? `Expired ${-x.d} days ago` : x.d === 0 ? 'Expires today' : `In ${x.d} days`}</StatusBadge></span> },
                                { key: 'act', label: '', width: '160px', align: 'right', cell: (x) => (x.d < 0 && canManage(p) ? <Button size="sm" variant="outline" data-return={`rm-${x.l.id}`} onClick={stop(() => open(`adjust:${x.i.id}:${x.l.id}`))}>Remove</Button> : x.d < 0 ? <span className="text-[12px] text-muted-foreground">Set aside for the house lead</span> : null) },
                            ]}
                            actionsFor={(x) => compactMenu([{ label: 'Open the stock item', icon: Package, onClick: () => open(`item:${x.i.id}`) }, x.d < 0 && canManage(p) && { label: 'Remove the expired pack', icon: Trash2, onClick: () => open(`adjust:${x.i.id}:${x.l.id}`) }])}
                            onOpen={(x) => open(`item:${x.i.id}`)}
                        />
                    ) : (
                        <Card className="p-2">
                            <EmptyState variant="compact" icon={CalendarClock} title="None" />
                        </Card>
                    )}
                </section>
            ))}
            <p className="text-caption">
                Warnings at <DefaultChip value={`${SETTINGS.expiryWarn} and ${SETTINGS.expiryCritical} days`} />. They go to the house lead (P11 Delivery) and to All Tasks › Stock. Discontinued medicines never warn.
            </p>
        </>
    );
}

/* ───────────── Removals (Q7) ───────────── */
function RemovalsView({ items, empty }: { items: StockItem[]; empty: boolean }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const ids = new Set(items.map((i) => i.id));
    const toRemove = items.flatMap((i) => openLots(i, s.rt).filter((l: Lot) => l.expiryIso && daysFrom(l.expiryIso) < 0).map((l) => ({ i, l })));
    const done = empty ? [] : allMovements(s.rt).filter((m) => ids.has(m.itemId) && (REMOVAL_KINDS as readonly string[]).includes(m.kind));
    return (
        <>
            <section className="flex flex-col gap-2.5" aria-label="To remove">
                <ListCaption title="To remove" caption={`${toRemove.length} shown · expired packs still in the cabinet`} />
                {toRemove.length && !empty ? (
                    <Card className="gap-0 p-0">
                        {toRemove.map(({ i, l }) => (
                            <div key={l.id} className="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3 text-sm last:border-b-0">
                                <span>
                                    <span className="font-semibold">{i.med} {i.strength}</span> · {who(i)} — batch {l.batch ?? 'not printed'}, expired {l.expiry}, {unitLabel(i, l.qty)}
                                </span>
                                {canManage(p) ? (
                                    <Button size="sm" variant="outline" data-return={`rmv-${l.id}`} onClick={() => open(`adjust:${i.id}:${l.id}`)}>
                                        Remove
                                    </Button>
                                ) : (
                                    <span className="text-[12px] text-muted-foreground">The house lead removes it</span>
                                )}
                            </div>
                        ))}
                    </Card>
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={PackageMinus} title="Nothing to remove" />
                    </Card>
                )}
            </section>
            <section className="flex flex-col gap-2.5" aria-label="Removed and returned">
                <ListCaption title="Removed and returned" caption={`${done.length} shown · with the reason, kept`} />
                {done.length ? (
                    <EntityTable<Movement>
                        rows={done}
                        rowKey={(m) => m.id}
                        rowHeight="content"
                        minWidth={1000}
                        identityLabel="When"
                        identityWidth="150px"
                        identity={(m) => ({ icon: PackageMinus, name: m.at.split(', ')[0], subline: m.at.split(', ')[1] ?? '' })}
                        columns={[
                            { key: 'med', label: 'Medicine', width: '1.1fr', cell: (m) => <span className="text-[12.5px]"><span className="font-semibold">{itemOf(m.itemId).med}</span> · {PEOPLE[itemOf(m.itemId).pid].pref}</span> },
                            { key: 'why', label: 'Reason', width: '2fr', cell: (m) => <span className="flex flex-col text-[12.5px]"><span className="font-semibold">{MOVE_LABEL[m.kind]} · {unitLabel(itemOf(m.itemId), Math.abs(m.qty))}</span>{m.note ? <span className="text-muted-foreground">{m.note}</span> : null}</span> },
                            { key: 'by', label: 'By', width: '0.8fr', cell: (m) => <span className="text-[12.5px]">{m.by}</span> },
                        ]}
                        actionsFor={(m) => [{ label: 'Open the stock item', icon: Package, onClick: () => open(`item:${m.itemId}`) }]}
                        onOpen={(m) => open(`item:${m.itemId}`)}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={PackageMinus} title="Nothing removed yet" />
                    </Card>
                )}
            </section>
            <DesignNote title="Design note — ordinary removals (Main, Q7)">
                <p>Today the only way to take an ordinary medicine out of stock is the controlled destruction flow, which needs controlled-medicine permissions, with a free-text reason (AUDIT 3.3, 8.16). Here an ordinary removal is a reasoned movement by a house lead; controlled destruction stays in the register (P07b).</p>
            </DesignNote>
        </>
    );
}
