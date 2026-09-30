/* Meds today (/meds/today) — P01 v1’s approved page top (PageHeader, the six
 * meters, the seven-view rail, the header actions) as P07a v1 reproduced it,
 * with this preview’s 9:12 am reference numbers. P06 designs the Stock alerts
 * view P01 / P07a left link-only (Main, Q1); the other views are link-only. */
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderGlassButton,
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
import { Activity, ArrowLeftRight, CalendarClock, CalendarDays, CheckCircle2, ClipboardList, Clock3, Flag, Package, PackageCheck, Pill, Plus, RefreshCw, Repeat, ShieldCheck, Trash2, TriangleAlert } from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';
import { HOUSES, ITEMS, PEOPLE, PERSONAS, frontline as isFrontline, type Movement, type PharmacyOrder, type StockItem } from '../data';
import { useOpen } from '../host';
import { allOrders, canManage, canReceive, cdView, concealed, daysFrom, expiryText, fefo, itemOf, openLots, openOuts, receivable, SETTINGS, statusOf, unitLabel } from '../model';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
import { ConcealedCount, DesignNote, StateLine, StockBadge, Wrap } from '../ui';
import { useCtx } from './hub';

type View = 'schedule' | 'rounds' | 'asneeded' | 'followups' | 'controlled' | 'stockalerts' | 'activity';
const stop = (fn: () => void) => (e: MouseEvent) => (e.stopPropagation(), fn());

export function MedsTodayPage() {
    const s = useStore();
    const r = s.route;
    const p = r.persona;
    const persona = PERSONAS[p];
    const view = ((r.q.get('view') as View) || 'schedule') as View;
    const [search, setSearch] = useState('');
    const scn = r.scenario;
    const loading = scn === 'loading';
    const failed = scn === 'unavailable';
    const setView = (v: View) => s.go('/meds/today', { view: v === 'schedule' ? undefined : v });
    const link = (v: View) => hrefFor('/meds/today', { view: v === 'schedule' ? undefined : v }, r);
    const alerts = useStockAlerts();
    /* P01 v1’s six meters — reference numbers for 9:12 am (P06 doesn’t model doses). */
    const meters = loading ? (
        ['Due now', 'Late', 'Needs help', 'Recorded', 'Follow-ups', 'My eligibility'].map((l) => (
            <PageHeaderMeterBlock key={l} label={l} ariaLabel={`${l}: loading`} onClick={() => undefined}>
                <PageHeaderMeterBig>—</PageHeaderMeterBig>
                <PageHeaderMeterCaption>Loading</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        ))
    ) : (
        <>
            <PageHeaderMeterBlock label="Due now" href={link('schedule')} ariaLabel="View 4 doses due now">
                <PageHeaderMeterBig>{failed ? '—' : 4}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{failed ? 'Unavailable' : '3 people · by 10:00 am'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Late" tone="brand" href={link('schedule')} ariaLabel="View 0 late doses">
                <PageHeaderMeterBig>{failed ? '—' : 0}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{failed ? 'Unavailable' : 'Nothing late'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Needs help" tone="brand" href={link('schedule')} ariaLabel="View 0 doses you can’t record">
                <PageHeaderMeterBig>{failed ? '—' : 0}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{failed ? 'Unavailable' : 'Nothing blocked'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Recorded" value="6 of 9" href={link('activity')} ariaLabel="View activity, 6 of 9 recorded">
                <PageHeaderMeterDonut percent={failed ? 0 : 66.7} caption="Due so far on your shift" />
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Follow-ups" href={link('followups')} ariaLabel="View follow-ups, 1 open">
                <PageHeaderMeterBig>{failed ? '—' : 1}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{failed ? 'Unavailable' : 'None overdue'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="My eligibility" tone="success" onClick={() => s.toast('info', 'My eligibility is P01’s dialog — outside this preview.')} ariaLabel="View my eligibility: current">
                <PageHeaderMeterBig>Current</PageHeaderMeterBig>
                <PageHeaderMeterCaption>To 14 Mar 2027</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    );
    const updated =
        scn === 'stale' ? (
            <PageHeaderFilterButton icon={TriangleAlert} active onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}>
                Not updated since 8:40 am NZDT
            </PageHeaderFilterButton>
        ) : (
            <PageHeaderFilterButton icon={RefreshCw} title="All times are NZDT (Pacific/Auckland). Refresh." onClick={() => s.toast('info', 'Refreshed at 9:12 am NZDT (mockup).')}>
                Updated 9:12 am NZDT
            </PageHeaderFilterButton>
        );
    const rail = (
        <PageHeaderRail<View>
            value={view}
            onSelect={setView}
            ariaLabel="Meds today views"
            items={[
                { key: 'schedule', label: 'Schedule', icon: Clock3, count: 4 },
                { key: 'rounds', label: 'Rounds', icon: Repeat },
                { key: 'asneeded', label: 'As-needed', icon: Pill },
                { key: 'followups', label: 'Follow-ups', icon: Flag, count: 1 },
                ...(cdView(p) ? [{ key: 'controlled' as const, label: 'Controlled checks', icon: ShieldCheck }] : []),
                { key: 'stockalerts', label: 'Stock alerts', icon: Package, ...(loading || failed || !alerts.count ? {} : { count: alerts.count }) },
                { key: 'activity', label: 'Activity', icon: Activity },
            ]}
        />
    );
    const header = (
        <PageHeader
            icon={Pill}
            title="Meds today"
            titleChip={persona.id === 'sw' || persona.id === 'lead' || persona.id === 'rimu' ? <PageHeaderStatusChip variant="success" icon={CheckCircle2}>On shift</PageHeaderStatusChip> : <PageHeaderStatusChip variant="neutral" icon={CalendarDays}>Not rostered today</PageHeaderStatusChip>}
            subline={persona.id === 'sw' ? 'Mon 28 Sep 2026 · Kōwhai House · shift 7:00 am – 3:30 pm' : `Mon 28 Sep 2026 · ${persona.houses.map((h) => HOUSES[h]).join(' and ')}`}
            actions={
                <>
                    <PageHeaderSearch value={search} onChange={setSearch} placeholder="Search people or medicines…" />
                    <PageHeaderGlassButton icon={Repeat} aria-label="Shift handover — medication" title="Shift handover — medication" onClick={() => s.toast('info', 'The medication handover lens is designed in P08a — outside this preview.')} />
                    <PageHeaderGlassButton icon={Flag} aria-label="Report a medication error" title="Report a medication error" onClick={() => s.toast('info', 'Opens the Report a medication error dialog (P08b) — outside this preview.')} />
                    <PageHeaderPrimaryButton icon={Plus} onClick={() => s.toast('info', 'Record as-needed dose opens P01’s approved dialog — outside this preview.')}>
                        Record as-needed dose
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={meters}
            filters={updated}
            rail={rail}
        />
    );
    const crumbs = isFrontline(p) ? [{ title: 'Home', href: '/dashboard' }, { title: 'Meds today' }] : [{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/meds/today' }, { title: 'Meds today' }];
    const linkOnly = (pkg: string, what: string) => (
        <Card className="gap-3 p-5">
            <p className="text-section-title">This view is designed in {pkg} — outside this preview</p>
            <p className="text-subtle">{what}</p>
            <DesignNote>P06 designs only the Stock alerts view. The other Meds today views keep their approved designs; nothing here is a stub.</DesignNote>
        </Card>
    );
    return (
        <Shell crumbs={crumbs}>
            {header}
            {view === 'stockalerts' ? (
                loading ? (
                    <Card className="p-4" aria-busy="true" aria-label="Loading stock alerts">
                        <SkeletonTable rows={5} columns={5} />
                    </Card>
                ) : failed ? (
                    <Card className="p-2">
                        <ErrorState title="We couldn’t load stock alerts" message="Keep giving doses from the packs in the cabinet — use the pack that expires first. Try again." onRetry={() => s.setViewer({ scenario: 'normal' })} />
                    </Card>
                ) : (
                    <StockAlerts search={search} />
                )
            ) : null}
            {view === 'schedule' ? linkOnly('P01 · Record a dose', 'Today’s doses. When a medicine has more than one pack, the dose dialog says “Use the pack expiring first” (P06, Main Q3).') : null}
            {view === 'rounds' ? linkOnly('P01 · Record a dose', 'Rounds and the guided round, as approved in P01 v1.') : null}
            {view === 'asneeded' ? linkOnly('P01 · Record a dose', 'As-needed medicines and today’s as-needed doses, as approved in P01 v1.') : null}
            {view === 'followups' ? linkOnly('P08a · Follow-ups & handover', 'Your follow-ups with owner and due time — including a count difference for the house lead to sign off.') : null}
            {view === 'controlled' ? linkOnly('P07a · Controlled checks', 'Shift-change counts, witness requests and controlled movements, as approved in P07a v1.') : null}
            {view === 'activity' ? linkOnly('P01 · Record a dose', 'Everything recorded in the last 24 hours, as approved in P01 v1.') : null}
        </Shell>
    );
}

/* ───────────── what needs a frontline hand today ───────────── */
export function useStockAlerts() {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const empty = s.route.scenario === 'empty';
    const inScope = empty ? [] : ITEMS.filter((i) => me.houses.includes(PEOPLE[i.pid].house) && !i.selfManaged);
    const items = inScope.filter((i) => !concealed(i, p));
    const deliveries = empty ? [] : allOrders(s.rt).filter((o) => receivable(o) && me.houses.includes(o.house) && (!o.cd || cdView(p)));
    const useFirst = items.flatMap((i) => {
        const next = fefo(i, s.rt);
        const openCount = openLots(i, s.rt).filter((l) => !l.expiryIso || daysFrom(l.expiryIso) >= 0).length;
        return next && next.expiryIso && openCount > 1 && daysFrom(next.expiryIso) <= SETTINGS.expiryWarn ? [{ i, l: next }] : [];
    });
    const low = items.filter((i) => ['low', 'out'].includes(statusOf(i, s.rt).state));
    const expired = items.flatMap((i) => openLots(i, s.rt).filter((l) => l.expiryIso && daysFrom(l.expiryIso) < 0).map((l) => ({ i, l })));
    const outs = empty ? [] : openOuts(s.rt).filter((m) => items.some((i) => i.id === m.itemId));
    return { deliveries, useFirst, low, expired, outs, hidden: inScope.length - items.length, count: deliveries.length + useFirst.length + low.length + expired.length + outs.length };
}

function StockAlerts({ search }: { search: string }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const ctx = useCtx();
    const a = useStockAlerts();
    const q = search.trim().toLowerCase();
    const match = (i: StockItem) => !q || `${i.med} ${PEOPLE[i.pid].legal}`.toLowerCase().includes(q);
    const who = (i: StockItem) => `${PEOPLE[i.pid].pref} ${PEOPLE[i.pid].surname}`;
    const itemMenu = (i: StockItem, extra: (MenuItem | false)[] = []): MenuItem[] => compactMenu([...extra, { label: 'Open the stock item', icon: Package, onClick: () => open(`item:${i.id}`) }]);
    if (s.route.scenario === 'empty' || !a.count)
        return (
            <Card className="p-2">
                <EmptyState icon={CheckCircle2} title="No stock alerts" description="Deliveries to receive, packs to use first, low stock and expired packs appear here." />
            </Card>
        );
    const section = (key: string, title: string, caption: string, body: ReactNode, n: number) => (
        <section key={key} className="flex flex-col gap-2.5" aria-label={title}>
            <ListCaption title={title} caption={`${n} shown · ${caption}`} />
            {n ? (
                body
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={CheckCircle2} title="Nothing here" />
                </Card>
            )}
        </section>
    );
    const deliveries = a.deliveries.filter((o) => match(itemOf(o.itemId)));
    const useFirst = a.useFirst.filter((x) => match(x.i));
    const low = a.low.filter(match);
    const expired = a.expired.filter((x) => match(x.i));
    return (
        <>
            {section(
                'deliveries',
                'Deliveries to receive',
                'count it in, check the label, photograph the pack',
                <EntityTable<PharmacyOrder>
                    rows={deliveries}
                    rowKey={(o) => o.id}
                    rowHeight="content"
                    minWidth={960}
                    identityLabel="Medicine"
                    identityWidth="1.2fr"
                    identity={(o) => ({ icon: o.cd ? ShieldCheck : PackageCheck, name: itemOf(o.itemId).med, subline: <Wrap>{itemOf(o.itemId).strength} · {o.id}{o.cd ? ' · controlled' : ''}</Wrap> })}
                    columns={[
                        { key: 'who', label: 'Person', width: '0.9fr', cell: (o) => <span className="text-[12.5px]">{who(itemOf(o.itemId))}</span> },
                        { key: 'what', label: 'Coming', width: '1.8fr', cell: (o) => <span className="flex flex-col gap-0.5 text-[12.5px]"><span>{unitLabel(itemOf(o.itemId), o.state === 'part' ? o.stillToCome! : o.dispensed!.qty)} from {o.pharmacy}</span><StateLine>{o.state === 'part' ? `The rest of ${o.id} — expected ${o.expected}` : `Due ${o.expected}`}</StateLine></span> },
                        {
                            key: 'act',
                            label: '',
                            width: '190px',
                            align: 'right',
                            cell: (o) =>
                                o.cd && !canManage(p) ? (
                                    <span className="text-right text-[12px] text-muted-foreground">The house lead receives it, with a witness</span>
                                ) : canReceive(p) ? (
                                    <Button size="sm" className="frontline-tap" data-return={`rcv-${o.id}`} onClick={stop(() => open(`receive:${o.id}`))}>
                                        Receive
                                    </Button>
                                ) : null,
                        },
                    ]}
                    actionsFor={(o) => itemMenu(itemOf(o.itemId), [canReceive(p) && !(o.cd && !canManage(p)) && { label: 'Receive it', icon: PackageCheck, onClick: () => open(`receive:${o.id}`) }])}
                    onOpen={(o) => open(`item:${o.itemId}`)}
                    onRowContextMenu={(e, o) => ctx.openAt(e, `${itemOf(o.itemId).med} · ${PEOPLE[itemOf(o.itemId).pid].pref}`, itemMenu(itemOf(o.itemId)))}
                />,
                deliveries.length,
            )}
            {section(
                'first',
                'Use these packs first',
                'doses come off them automatically — keep them at the front',
                <EntityTable<(typeof useFirst)[number]>
                    rows={useFirst}
                    rowKey={(x) => x.l.id}
                    rowHeight="content"
                    minWidth={960}
                    identityLabel="Medicine"
                    identityWidth="1.2fr"
                    identity={(x) => ({ icon: CalendarClock, name: x.i.med, subline: <Wrap>{x.i.strength}</Wrap> })}
                    columns={[
                        { key: 'who', label: 'Person', width: '0.9fr', cell: (x) => <span className="text-[12.5px]">{who(x.i)}</span> },
                        { key: 'pack', label: 'Pack', width: '1.8fr', cell: (x) => <span className="flex flex-col items-start gap-1 text-[12.5px]"><StatusBadge variant={daysFrom(x.l.expiryIso!) <= SETTINGS.expiryCritical ? 'warning' : 'info'} className="rounded-[8px]">In {daysFrom(x.l.expiryIso!)} days</StatusBadge><StateLine>Batch {x.l.batch ?? 'not printed'} · {expiryText(x.l).toLowerCase()} · {unitLabel(x.i, x.l.qty)} left</StateLine></span> },
                        { key: 'act', label: '', width: '190px', align: 'right', cell: () => null },
                    ]}
                    actionsFor={(x) => itemMenu(x.i)}
                    onOpen={(x) => open(`item:${x.i.id}`)}
                    onRowContextMenu={(e, x) => ctx.openAt(e, `${x.i.med} · ${PEOPLE[x.i.pid].pref}`, itemMenu(x.i))}
                />,
                useFirst.length,
            )}
            {section(
                'low',
                'Running low',
                `under ${SETTINGS.lowDays} days’ supply, or at the reorder level`,
                <EntityTable<StockItem>
                    rows={low}
                    rowKey={(i) => i.id}
                    rowHeight="content"
                    minWidth={960}
                    identityLabel="Medicine"
                    identityWidth="1.2fr"
                    identity={(i) => ({ icon: Pill, name: i.med, subline: <Wrap>{i.strength}</Wrap> })}
                    columns={[
                        { key: 'who', label: 'Person', width: '0.9fr', cell: (i) => <span className="text-[12.5px]">{who(i)}</span> },
                        { key: 'st', label: 'State', width: '1.8fr', cell: (i) => <span className="flex flex-col items-start gap-1"><StockBadge state={statusOf(i, s.rt).state} />{statusOf(i, s.rt).lines.map((l) => <StateLine key={l}>{l}</StateLine>)}</span> },
                        {
                            key: 'act',
                            label: '',
                            width: '190px',
                            align: 'right',
                            cell: (i) => {
                                const draft = allOrders(s.rt).find((o) => o.itemId === i.id && o.state === 'draft');
                                const sent = allOrders(s.rt).some((o) => o.itemId === i.id && ['sent', 'dispensed', 'part'].includes(o.state));
                                if (sent) return <span className="text-[12px] text-muted-foreground">Ordered</span>;
                                return canManage(p) ? (
                                    <Button size="sm" variant="outline" data-return={`ord-${i.id}`} onClick={stop(() => open(draft ? `po:${draft.id}` : `order:new:${i.id}`))}>
                                        {draft ? 'Send the order' : 'Order'}
                                    </Button>
                                ) : (
                                    <span className="text-right text-[12px] text-muted-foreground">{draft ? 'Drafted — the house lead sends it' : 'The house lead orders it'}</span>
                                );
                            },
                        },
                    ]}
                    actionsFor={(i) => itemMenu(i, [canManage(p) && { label: 'Order from the pharmacy', icon: ClipboardList, onClick: () => open(`order:new:${i.id}`) }])}
                    onOpen={(i) => open(`item:${i.id}`)}
                    onRowContextMenu={(e, i) => ctx.openAt(e, `${i.med} · ${PEOPLE[i.pid].pref}`, itemMenu(i))}
                />,
                low.length,
            )}
            {section(
                'expired',
                'Expired — take out of use',
                'not given, and not counted as stock',
                <EntityTable<(typeof expired)[number]>
                    rows={expired}
                    rowKey={(x) => x.l.id}
                    rowHeight="content"
                    minWidth={960}
                    identityLabel="Medicine"
                    identityWidth="1.2fr"
                    identity={(x) => ({ icon: Trash2, name: x.i.med, subline: <Wrap>{x.i.strength}</Wrap> })}
                    columns={[
                        { key: 'who', label: 'Person', width: '0.9fr', cell: (x) => <span className="text-[12.5px]">{who(x.i)}</span> },
                        { key: 'pack', label: 'Pack', width: '1.8fr', cell: (x) => <span className="flex flex-col items-start gap-1 text-[12.5px]"><StatusBadge variant="critical" className="rounded-[8px]">Expired {x.l.expiry}</StatusBadge><StateLine>Batch {x.l.batch ?? 'not printed'} · {unitLabel(x.i, x.l.qty)} — put it in the returns box</StateLine></span> },
                        { key: 'act', label: '', width: '190px', align: 'right', cell: (x) => (canManage(p) ? <Button size="sm" variant="outline" data-return={`rm-${x.l.id}`} onClick={stop(() => open(`adjust:${x.i.id}:${x.l.id}`))}>Remove</Button> : <span className="text-right text-[12px] text-muted-foreground">The house lead removes it</span>) },
                    ]}
                    actionsFor={(x) => itemMenu(x.i)}
                    onOpen={(x) => open(`item:${x.i.id}`)}
                    onRowContextMenu={(e, x) => ctx.openAt(e, `${x.i.med} · ${PEOPLE[x.i.pid].pref}`, itemMenu(x.i))}
                />,
                expired.length,
            )}
            {section(
                'outs',
                'Out with people today',
                'record them coming back',
                <EntityTable<Movement>
                    rows={a.outs}
                    rowKey={(m) => m.id}
                    rowHeight="content"
                    minWidth={960}
                    identityLabel="Medicine"
                    identityWidth="1.2fr"
                    identity={(m) => ({ icon: ArrowLeftRight, name: itemOf(m.itemId).med, subline: <Wrap>{itemOf(m.itemId).strength}</Wrap> })}
                    columns={[
                        { key: 'who', label: 'Person', width: '0.9fr', cell: (m) => <span className="text-[12.5px]">{who(itemOf(m.itemId))}</span> },
                        { key: 'what', label: 'Went out', width: '1.8fr', cell: (m) => <span className="flex flex-col gap-0.5 text-[12.5px]"><span>{unitLabel(itemOf(m.itemId), Math.abs(m.qty))} · {m.at} · by {m.by}</span><StateLine>{m.note}</StateLine></span> },
                        {
                            key: 'act',
                            label: '',
                            width: '190px',
                            align: 'right',
                            cell: (m) =>
                                canReceive(p) ? (
                                    <Button size="sm" variant="outline" className="frontline-tap" data-return={`back-${m.id}`} onClick={stop(() => open(`move:${m.itemId}:back:${m.id}`))}>
                                        Record it coming back
                                    </Button>
                                ) : null,
                        },
                    ]}
                    actionsFor={(m) => itemMenu(itemOf(m.itemId))}
                    onOpen={(m) => open(`item:${m.itemId}`)}
                />,
                a.outs.length,
            )}
            {ctx.node}
            <p className="text-caption">
                {a.hidden ? <><ConcealedCount n={a.hidden} /> · </> : null}Controlled medicines are counted in Controlled checks. Times in NZDT. Signed in as {PERSONAS[p].name}.
            </p>
        </>
    );
}
