/* The controlled register — /emar/controlled (Main, Q1), reached from Stock &
 * controlled drugs › Controlled. The P02 hub pattern: PageHeader (not today’s
 * PageHero), a meter row, the filter row and the rail — Register ·
 * Discrepancies · Losses · Destructions. /emar/destructions redirects to the
 * Destructions view (controlled only). */
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
import { AlertTriangle, BookOpen, Clock3, FileWarning, Home, Lock, PackageX, RefreshCw, Scale, ShieldCheck, Trash2, Undo2, type LucideIcon } from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';
import { DESTROY_REASON, ENTRY_LABEL, HOUSES, ORG_SETTINGS, PEOPLE, PERSONAS, type CdMedicine, type Destruction, type Discrepancy, type Entry, type House, type Loss } from '../data';
import { useOpen } from '../host';
import {
    allDestructions,
    allDiscrepancies,
    allEntries,
    allLosses,
    balanceOf,
    canCloseLoss,
    canManage,
    canRecord,
    cdView,
    classOf,
    destructionState,
    LOSS_STATE,
    medOf,
    medsIn,
    OUTCOME_LABEL,
    signed,
    unitLabel,
    whyCantResolve,
} from '../model';
import { Shell } from '../shell';
import { useStore } from '../store';
import { ClassChip, DefaultChip, DesignNote, Notice, StateLine, Wrap } from '../ui';

type View = 'register' | 'discrepancies' | 'losses' | 'destructions';
const RAIL: { key: View; label: string; icon: LucideIcon }[] = [
    { key: 'register', label: 'Register', icon: BookOpen },
    { key: 'discrepancies', label: 'Discrepancies', icon: Scale },
    { key: 'losses', label: 'Losses', icon: FileWarning },
    { key: 'destructions', label: 'Destructions', icon: PackageX },
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
    return { openAt, node: ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={ShieldCheck} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null };
}
const who = (m: CdMedicine) => `${PEOPLE[m.pid].pref} ${PEOPLE[m.pid].surname}`;
const stop = (fn: () => void) => (e: MouseEvent) => (e.stopPropagation(), fn());

export function RegisterPage({ forceView }: { forceView?: View }) {
    const s = useStore();
    const r = s.route;
    const p = r.persona;
    const me = PERSONAS[p];
    const open = useOpen();
    const [search, setSearch] = useState('');
    const view = forceView ?? ((RAIL.some((x) => x.key === r.q.get('view')) ? r.q.get('view') : 'register') as View);
    const houseParam = r.q.get('house') as House | null;
    const houses = me.houses.filter((h) => !houseParam || h === houseParam);
    const scn = r.scenario;
    const loading = scn === 'loading';
    const failed = scn === 'unavailable';
    const empty = scn === 'empty';
    const dash = loading ? '—' : failed ? 'Unavailable' : null;
    const meds = empty ? [] : medsIn(houses);
    const inHouses = (medId: string) => houses.includes(PEOPLE[medOf(medId).pid].house);
    const discs = empty ? [] : allDiscrepancies(s.rt).filter((d) => inHouses(d.medId));
    const openDiscs = discs.filter((d) => d.status !== 'closed');
    const losses = empty ? [] : allLosses(s.rt).filter((l) => inHouses(l.medId));
    const openLosses = losses.filter((l) => l.status !== 'closed');
    const dests = empty ? [] : allDestructions(s.rt).filter((d) => inHouses(d.medId));
    const awaiting = dests.filter((d) => destructionState(d).label.startsWith('Waiting'));
    const toReview = meds.filter((m) => !classOf(m, s.rt));
    const houseText = me.houses.length > 1 && !houseParam ? `${HOUSES[me.houses[0]]} and ${HOUSES[me.houses[1]]}` : HOUSES[houses[0]];
    const go = (v: View, extra: Record<string, string | undefined> = {}) => s.go('/emar/controlled', { view: v === 'register' ? undefined : v, open: undefined, house: houseParam ?? undefined, ...extra });
    const crumbs = [{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/emar/controlled' }, { title: 'Stock & controlled drugs', href: '/emar/stock' }, { title: 'Controlled register' }];
    if (!cdView(p))
        return (
            <Shell crumbs={crumbs}>
                <Card className="items-center gap-3 p-10 text-center">
                    <Lock className="size-8 text-muted-foreground" aria-hidden="true" />
                    <h1 className="text-section-title">You don’t have access to the controlled register</h1>
                    <p className="text-subtle">It needs controlled-medicine access. Ask your manager if you need it for your work.</p>
                    {PERSONAS[p].perms.includes('cd.manage') ? (
                        <DesignNote title="Design note — deviation 5">
                            <p>Main granted medications.controlled.manage to clinical leads (Q10), but they have no controlled-medicine view today, so they can’t reach the register to resolve or void. The build either grants controlled view with it, or leaves it unused for them.</p>
                        </DesignNote>
                    ) : null}
                </Card>
            </Shell>
        );
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
    const header = (
        <PageHeader
            variant="index"
            icon={ShieldCheck}
            title="Controlled register"
            titleChip={<PageHeaderStatusChip variant="neutral">{me.houses.length > 1 && !houseParam ? '2 houses' : '1 house'}</PageHeaderStatusChip>}
            subline={`Controlled medicines at ${houseText} · times in NZDT`}
            actions={
                <>
                    <PageHeaderSearch value={search} onChange={setSearch} placeholder="Search medicines or people" />
                    {canRecord(p) && !failed ? (
                        <PageHeaderPrimaryButton icon={PackageX} onClick={() => open('destroy:new')}>
                            Return for destruction
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock label="Discrepancies" tone={!dash && openDiscs.length ? 'warning' : 'brand'} onClick={() => go('discrepancies')} ariaLabel={`View ${openDiscs.length} open discrepancies`}>
                        <PageHeaderMeterBig>{dash ?? openDiscs.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : openDiscs.length ? `Owner: ${openDiscs[0].owner.split(' ')[0]}` : 'None open'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Losses" tone={!dash && openLosses.length ? 'warning' : 'brand'} onClick={() => go('losses')} ariaLabel={`View ${openLosses.length} open losses`}>
                        <PageHeaderMeterBig>{dash ?? openLosses.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : openLosses.some((l) => l.status === 'awaitingClose') ? 'Waiting for a manager' : openLosses.length ? 'Investigating' : 'None open'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Pharmacy receipt" tone={!dash && awaiting.length ? 'warning' : 'brand'} onClick={() => go('destructions')} ariaLabel={`View ${awaiting.length} returns waiting for the pharmacist’s receipt`}>
                        <PageHeaderMeterBig>{dash ?? awaiting.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : awaiting.length ? 'Not yet received' : 'Nothing waiting'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Class to review" tone={!dash && toReview.length ? 'warning' : 'brand'} onClick={() => go('register', { show: 'class' })} ariaLabel={`View ${toReview.length} medicines whose class needs review`}>
                        <PageHeaderMeterBig>{dash ?? toReview.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : toReview.length ? 'Was “Schedule”' : 'All set'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Counts overdue" onClick={() => s.toast('info', 'Shift-change counts are P07a’s Controlled checks — outside this preview.')} ariaLabel="Counts overdue: none — open Controlled checks">
                        <PageHeaderMeterBig>{dash ?? 0}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : ORG_SETTINGS.cadence}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="In the register" onClick={() => go('register', { show: undefined })} ariaLabel="View every medicine in the register">
                        <PageHeaderMeterBig>{dash ?? meds.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : `${new Set(meds.map((m) => m.pid)).size} people`}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    {houseFilter}
                    {view === 'register' ? <PageHeaderFilterSelect label="Show" value={r.q.get('show') ?? 'all'} allValue="all" onChange={(v) => s.set({ show: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All medicines' }, { value: 'class', label: 'Class to review' }]} /> : null}
                    {asAt}
                </>
            }
            rail={<PageHeaderRail<View> items={RAIL.map((x) => ({ ...x, count: x.key === 'discrepancies' && !dash && openDiscs.length ? openDiscs.length : x.key === 'losses' && !dash && openLosses.length ? openLosses.length : undefined }))} value={view} onSelect={(k) => go(k)} ariaLabel="Controlled register views" />}
        />
    );
    let body: ReactNode;
    if (loading)
        body = (
            <Card className="p-4" aria-busy="true" aria-label="Loading the register">
                <SkeletonTable rows={6} columns={6} />
            </Card>
        );
    else if (failed)
        body = (
            <Card className="p-2">
                <ErrorState title="We couldn’t load the controlled register" message="Nothing is shown rather than an incomplete register. Keep the paper back-up at the cupboard until it loads. Try again." onRetry={() => s.setViewer({ scenario: 'normal' })} />
            </Card>
        );
    else if (view === 'discrepancies') body = <DiscrepanciesView discs={discs} search={search} />;
    else if (view === 'losses') body = <LossesView losses={losses} search={search} empty={empty} />;
    else if (view === 'destructions') body = <DestructionsView dests={dests} search={search} empty={empty} redirected={!!forceView} />;
    else body = <RegisterView meds={meds} search={search} empty={empty} />;
    return (
        <Shell crumbs={crumbs}>
            {header}
            {scn === 'stale' ? (
                <Notice tone="warning" icon={AlertTriangle} title="This may be out of date" actions={<Button size="sm" variant="outline" onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}><RefreshCw className="size-4" /> Try again</Button>}>
                    We couldn’t refresh at 9:12 am. It shows the register as at 8:40 am. Don’t record, void or resolve until it’s up to date.
                </Notice>
            ) : null}
            {body}
        </Shell>
    );
}

/* ───────────── Register ───────────── */
export function medMenu(m: CdMedicine, s: ReturnType<typeof useStore>, open: (x: string) => void): MenuItem[] {
    const p = s.route.persona;
    return compactMenu([
        { label: 'Open the register for this medicine', icon: BookOpen, onClick: () => open(`med:${m.id}`) },
        canRecord(p) && { label: 'Record a breakage or spillage', icon: AlertTriangle, onClick: () => open(`breakage:${m.id}`) },
        canRecord(p) && { label: 'Report a loss', icon: FileWarning, onClick: () => open(`loss:new:${m.id}`) },
        canRecord(p) && { label: 'Return for destruction', icon: PackageX, onClick: () => open(`destroy:new:${m.id}`) },
        canManage(p) && { separator: true },
        canManage(p) && { label: classOf(m, s.rt) ? 'Change the class' : 'Set the class', icon: ShieldCheck, onClick: () => open(`class:${m.id}`) },
    ]);
}
function RegisterView({ meds, search, empty }: { meds: CdMedicine[]; search: string; empty: boolean }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const ctx = useCtx();
    const show = s.route.q.get('show') ?? 'all';
    const q = search.trim().toLowerCase();
    const list = meds.filter((m) => (show === 'class' ? !classOf(m, s.rt) : true)).filter((m) => !q || `${m.med} ${PEOPLE[m.pid].legal}`.toLowerCase().includes(q));
    const ids = new Set(meds.map((m) => m.id));
    const recent = allEntries(s.rt).filter((e) => ids.has(e.medId)).slice(0, 10);
    if (empty)
        return (
            <Card className="p-2">
                <EmptyState icon={BookOpen} title="Nothing in the controlled register yet" description="A controlled medicine appears here when its first delivery is received into the register (Stock & controlled drugs › Receive)." />
            </Card>
        );
    return (
        <>
            <section className="flex flex-col gap-2.5" aria-label="Controlled medicines">
                <ListCaption title={show === 'class' ? 'Class to review' : 'Controlled medicines'} caption={`${list.length} shown · balance after the latest entry that isn’t voided`} />
                {list.length ? (
                    <EntityTable<CdMedicine>
                        rows={list}
                        rowKey={(m) => m.id}
                        rowHeight="content"
                        minWidth={960}
                        identityLabel="Medicine"
                        identityWidth="1.2fr"
                        identity={(m) => ({ icon: ShieldCheck, name: m.med, subline: <Wrap>{m.strength}</Wrap> })}
                        columns={[
                            { key: 'who', label: 'Person', width: '0.9fr', cell: (m) => <span className="flex items-center gap-2 text-[12.5px]"><PersonDisc name={PEOPLE[m.pid].legal} size={22} />{who(m)}</span> },
                            { key: 'cls', label: 'Class', width: '1.35fr', cell: (m) => <ClassChip cls={classOf(m, s.rt)} legacy={m.legacySchedule} /> },
                            { key: 'bal', label: 'Balance', width: '0.6fr', cell: (m) => <span className="text-[12.5px] font-semibold">{unitLabel(m, balanceOf(m, s.rt))}</span> },
                            { key: 'count', label: 'Last count', width: '1fr', cell: (m) => <span className="text-[12.5px]">{m.lastCount}</span> },
                            {
                                key: 'act',
                                label: '',
                                width: '140px',
                                align: 'right',
                                cell: (m) =>
                                    !classOf(m, s.rt) && canManage(p) ? (
                                        <Button size="sm" variant="outline" data-return={`cls-${m.id}`} onClick={stop(() => open(`class:${m.id}`))}>
                                            Set the class
                                        </Button>
                                    ) : null,
                            },
                        ]}
                        actionsFor={(m) => medMenu(m, s, open)}
                        onOpen={(m) => open(`med:${m.id}`)}
                        onRowContextMenu={(e, m) => ctx.openAt(e, `${m.med} · ${PEOPLE[m.pid].pref}`, medMenu(m, s, open))}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={BookOpen} title="Nothing matches" />
                    </Card>
                )}
            </section>
            <section className="flex flex-col gap-2.5" aria-label="Recent entries">
                <ListCaption title="Recent entries" caption={`${recent.length} shown · newest first · never edited — a wrong entry is voided and corrected`} />
                <EntryTable entries={recent} />
            </section>
            {ctx.node}
            <DesignNote title="Design note — replaces today’s /emar/controlled">
                <p>Today’s page is a PageHero with seven tabs; its “Audit Trail” tab repeats Recent Entries, the page says “append-only” while entries can be updated, and wrong entries can’t be voided (AUDIT 1.1, 2.1, 8.14). Here the register is append-only: a wrong entry is voided with a reason and a witness PIN, stays visible struck through, and a correcting entry follows (Main, Q2).</p>
            </DesignNote>
        </>
    );
}
export function EntryTable({ entries, compact = false }: { entries: Entry[]; compact?: boolean }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const menu = (e: Entry): MenuItem[] => compactMenu([{ label: 'Open the register for this medicine', icon: BookOpen, onClick: () => open(`med:${e.medId}`) }, !e.voided && canManage(p) && e.kind !== 'count' && { label: 'Void this entry', icon: Undo2, onClick: () => open(`void:${e.id}`), danger: true }]);
    return (
        <EntityTable<Entry>
            rows={entries}
            rowKey={(e) => e.id}
            rowHeight="content"
            minWidth={960}
            identityLabel="When"
            identityWidth="140px"
            identity={(e) => ({ icon: e.voided ? Undo2 : BookOpen, name: e.at.split(', ')[0], subline: e.at.split(', ')[1] ?? '' })}
            columns={[
                ...(compact ? [] : [{ key: 'med', label: 'Medicine', width: '1fr', cell: (e: Entry) => <span className="text-[12.5px]"><span className="font-semibold">{medOf(e.medId).med}</span> · {PEOPLE[medOf(e.medId).pid].pref}</span> }]),
                {
                    key: 'what',
                    label: 'Entry',
                    width: '1.9fr',
                    cell: (e: Entry) => (
                        <span className="flex flex-col items-start gap-1 py-0.5 text-[12.5px]">
                            <span className={e.voided ? 'line-through decoration-1' : 'font-semibold'}>
                                {ENTRY_LABEL[e.kind]}
                                {e.change ? ` · ${signed(e.change)}` : ''} · {e.before} → {e.after}
                            </span>
                            {e.voided ? (
                                <>
                                    <StatusBadge variant="neutral" className="rounded-[8px]">
                                        Voided
                                    </StatusBadge>
                                    <StateLine>
                                        {e.voided.at} by {e.voided.by}, witnessed by {e.voided.witness}: “{e.voided.reason}”
                                    </StateLine>
                                </>
                            ) : e.note ? (
                                <StateLine>{e.note}</StateLine>
                            ) : null}
                            {e.corrects ? <StateLine>Corrects the voided entry of {allEntries(s.rt).find((x) => x.id === e.corrects)?.at}</StateLine> : null}
                        </span>
                    ),
                },
                { key: 'by', label: 'Recorded by', width: '0.9fr', cell: (e: Entry) => <span className="text-[12.5px]">{e.by}</span> },
                { key: 'wit', label: 'Witnessed by', width: '1fr', cell: (e: Entry) => <span className="text-[12.5px]">{e.witnesses.length ? e.witnesses.join(' and ') : e.link?.startsWith('OV') ? `No witness — override ${e.link}` : '—'}</span> },
            ]}
            actionsFor={menu}
            onOpen={(e) => open(`med:${e.medId}`)}
        />
    );
}

/* ───────────── Discrepancies (Q4) ───────────── */
function DiscrepanciesView({ discs, search }: { discs: Discrepancy[]; search: string }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const q = search.trim().toLowerCase();
    const list = discs.filter((d) => !q || `${medOf(d.medId).med} ${PEOPLE[medOf(d.medId).pid].legal} ${d.id}`.toLowerCase().includes(q));
    const sections: { key: Discrepancy['status']; title: string; caption: string }[] = [
        { key: 'open', title: 'Open', caption: 'the house lead owns each one; never resolved by someone who did the count' },
        { key: 'under_review', title: 'With a manager', caption: 'escalated for a manager to resolve' },
        { key: 'closed', title: 'Closed', caption: 'with the outcome — kept' },
    ];
    const act = (d: Discrepancy) => {
        if (d.status === 'closed') return null;
        const why = whyCantResolve(d, p);
        if (d.status === 'under_review' && !PERSONAS[p].perms.includes('manager')) return <span className="text-right text-[12px] text-muted-foreground">A manager resolves it</span>;
        return why ? (
            <span className="text-right text-[12px] text-muted-foreground">{why}</span>
        ) : (
            <Button size="sm" data-return={`res-${d.id}`} onClick={stop(() => open(`resolve:${d.id}`))}>
                Resolve
            </Button>
        );
    };
    return (
        <>
            {sections.map((sec) => {
                const rows = list.filter((d) => d.status === sec.key);
                return (
                    <section key={sec.key} className="flex flex-col gap-2.5" aria-label={sec.title}>
                        <ListCaption title={sec.title} caption={`${rows.length} shown · ${sec.caption}`} />
                        {rows.length ? (
                            <EntityTable<Discrepancy>
                                rows={rows}
                                rowKey={(d) => d.id}
                                rowHeight="content"
                                minWidth={960}
                                identityLabel="Discrepancy"
                                identityWidth="1.1fr"
                                identity={(d) => ({ icon: Scale, name: medOf(d.medId).med, subline: <Wrap>{d.id} · {who(medOf(d.medId))}</Wrap> })}
                                columns={[
                                    { key: 'diff', label: 'Difference', width: '1fr', cell: (d) => <span className="text-[12.5px]"><span className="font-semibold">Counted {d.counted}, expected {d.expected}</span> ({signed(d.counted - d.expected)})</span> },
                                    { key: 'started', label: 'Started', width: '1.3fr', cell: (d) => <span className="flex flex-col gap-0.5 text-[12.5px]"><span>{d.started}</span><StateLine>Counted by {d.countedBy}, witnessed by {d.witness}</StateLine></span> },
                                    {
                                        key: 'state',
                                        label: 'State',
                                        width: '1.6fr',
                                        cell: (d) => (
                                            <span className="flex flex-col items-start gap-1 py-0.5">
                                                <StatusBadge variant={d.status === 'closed' ? 'success' : d.status === 'under_review' ? 'info' : 'warning'} className="rounded-[8px]">
                                                    {d.status === 'closed' ? OUTCOME_LABEL[d.resolved!.outcome] : d.status === 'under_review' ? 'With a manager' : 'Open'}
                                                </StatusBadge>
                                                <StateLine>{d.status === 'closed' ? `${d.resolved!.at} by ${d.resolved!.by}${d.resolved!.link ? ` · ${d.resolved!.link}` : ''}` : d.status === 'under_review' ? `Escalated ${d.escalated?.at} by ${d.escalated?.by}` : `Owner: ${d.owner} · incident ${d.incident} · doses aren’t blocked`}</StateLine>
                                            </span>
                                        ),
                                    },
                                    { key: 'act', label: '', width: '190px', align: 'right', cell: act },
                                ]}
                                actionsFor={(d) => compactMenu([{ label: 'Open the discrepancy', icon: Scale, onClick: () => open(`disc:${d.id}`) }, d.status !== 'closed' && !whyCantResolve(d, p) && { label: 'Resolve it', icon: Scale, onClick: () => open(`resolve:${d.id}`) }, { label: 'Open the register for this medicine', icon: BookOpen, onClick: () => open(`med:${d.medId}`) }])}
                                onOpen={(d) => open(`disc:${d.id}`)}
                            />
                        ) : (
                            <Card className="p-2">
                                <EmptyState variant="compact" icon={Scale} title={`No ${sec.title.toLowerCase()} discrepancies`} />
                            </Card>
                        )}
                    </section>
                );
            })}
            <p className="text-caption">A discrepancy starts from a count that still differs after a recount (Controlled checks). Doses are never blocked while it’s open. Resolving it adds a note to the linked incident; the incident itself is closed in Safety & oversight › Medication errors.</p>
            <DesignNote title="Design note — replaces ResolveDiscrepancyDialog (Main, Q4)">
                <p>Today’s dialog says “Resolution is logged against the linked incident”, but only alerts and signals are cleared; “Loss report raised” is only a label; and the person who counted can resolve their own discrepancy (AUDIT 3.3, 8.1–8.5).</p>
            </DesignNote>
        </>
    );
}

/* ───────────── Losses (Q5) ───────────── */
function LossesView({ losses, search, empty }: { losses: Loss[]; search: string; empty: boolean }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const q = search.trim().toLowerCase();
    const list = losses.filter((l) => !q || `${medOf(l.medId).med} ${PEOPLE[medOf(l.medId).pid].legal} ${l.id}`.toLowerCase().includes(q));
    const act = (l: Loss) =>
        l.status === 'closed' ? null : l.status === 'awaitingClose' && canCloseLoss(p) ? (
            <Button size="sm" data-return={`close-${l.id}`} onClick={stop(() => open(`lossclose:${l.id}`))}>
                Close it
            </Button>
        ) : canRecord(p) ? (
            <Button size="sm" variant="outline" data-return={`note-${l.id}`} onClick={stop(() => open(`lossnote:${l.id}`))}>
                Add to the investigation
            </Button>
        ) : null;
    return (
        <>
            {(['open', 'closed'] as const).map((k, n) => {
                const rows = list.filter((l) => (k === 'open' ? l.status !== 'closed' : l.status === 'closed'));
                return (
                    <section key={k} className="flex flex-col gap-2.5" aria-label={k === 'open' ? 'Open losses' : 'Closed losses'}>
                        <ListCaption
                            title={k === 'open' ? 'Open' : 'Closed'}
                            caption={`${rows.length} shown · ${k === 'open' ? 'a manager closes each one' : 'kept, never deleted'}`}
                            right={
                                n === 0 && canRecord(p) && !empty ? (
                                    <Button size="sm" data-return="loss-new" onClick={() => open('loss:new')}>
                                        Report a loss
                                    </Button>
                                ) : undefined
                            }
                        />
                        {rows.length ? (
                            <EntityTable<Loss>
                                rows={rows}
                                rowKey={(l) => l.id}
                                rowHeight="content"
                                minWidth={960}
                                identityLabel="Loss"
                                identityWidth="1.1fr"
                                identity={(l) => ({ icon: FileWarning, name: medOf(l.medId).med, subline: <Wrap>{l.id} · {who(medOf(l.medId))}</Wrap> })}
                                columns={[
                                    { key: 'qty', label: 'Lost', width: '0.6fr', cell: (l) => <span className="text-[12.5px] font-semibold">{unitLabel(medOf(l.medId), l.qty)}</span> },
                                    { key: 'when', label: 'Discovered', width: '1.4fr', cell: (l) => <span className="flex flex-col gap-0.5 text-[12.5px]"><span>{l.discovered}</span><StateLine>Reported by {l.reportedBy}, witnessed by {l.witness}{l.fromDiscrepancy ? ` · from ${l.fromDiscrepancy}` : ''}</StateLine></span> },
                                    { key: 'state', label: 'State', width: '1.6fr', cell: (l) => <span className="flex flex-col items-start gap-1 py-0.5"><StatusBadge variant={LOSS_STATE[l.status].variant} className="rounded-[8px]">{LOSS_STATE[l.status].label}</StatusBadge><StateLine>{l.status === 'closed' ? `${l.closed!.at} by ${l.closed!.by}: “${l.closed!.finding}”` : `${l.timeline.length} investigation ${l.timeline.length === 1 ? 'note' : 'notes'} · latest ${l.timeline[l.timeline.length - 1].at} · incident ${l.incident}`}</StateLine></span> },
                                    { key: 'act', label: '', width: '210px', align: 'right', cell: act },
                                ]}
                                actionsFor={(l) => compactMenu([{ label: 'Open the loss', icon: FileWarning, onClick: () => open(`loss:${l.id}`) }, l.status !== 'closed' && canRecord(p) && { label: 'Add to the investigation', icon: FileWarning, onClick: () => open(`lossnote:${l.id}`) }, l.status === 'awaitingClose' && canCloseLoss(p) && { label: 'Close it', icon: ShieldCheck, onClick: () => open(`lossclose:${l.id}`) }])}
                                onOpen={(l) => open(`loss:${l.id}`)}
                            />
                        ) : (
                            <Card className="p-2">
                                <EmptyState variant="compact" icon={FileWarning} title={k === 'open' ? 'No open losses' : 'No closed losses yet'} />
                            </Card>
                        )}
                    </section>
                );
            })}
            <DesignNote title="Design note — losses (Main, Q5)">
                <p>Today a loss report is JSON behind the register page: it doesn’t change the balance, investigation notes overwrite each other, and nothing is audited (AUDIT 3.4, 8.12–8.13). Here a loss is a witnessed register entry, with its incident and an append-only investigation, and a manager closes it.</p>
            </DesignNote>
        </>
    );
}

/* ───────────── Destructions (Q6) ───────────── */
function DestructionsView({ dests, search, empty, redirected }: { dests: Destruction[]; search: string; empty: boolean; redirected: boolean }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const q = search.trim().toLowerCase();
    const list = dests.filter((d) => !q || `${medOf(d.medId).med} ${PEOPLE[medOf(d.medId).pid].legal} ${d.id}`.toLowerCase().includes(q));
    const bands: { key: string; title: string; caption: string; pick: (d: Destruction) => boolean }[] = [
        { key: 'waiting', title: 'Waiting for the pharmacist’s receipt', caption: 'returned to the pharmacy; record the pharmacist’s name and registration when they sign for it', pick: (d) => !d.voided && d.method === 'return' && !d.received },
        { key: 'done', title: 'Destroyed', caption: 'received by the pharmacy, or destroyed on site', pick: (d) => !d.voided && (d.method === 'onsite' || !!d.received) },
        { key: 'voided', title: 'Voided', caption: 'kept, with the reason; the register entry was reversed', pick: (d) => !!d.voided },
    ];
    return (
        <>
            {redirected ? (
                <Notice tone="info" title="/emar/destructions now opens here">
                    Destructions are controlled-only (Main, Q1). Ordinary removals are in Stock & controlled drugs › Removals.
                </Notice>
            ) : null}
            {empty ? (
                <Card className="p-2">
                    <EmptyState icon={PackageX} title="No destructions yet" description="Controlled medicines go back to the pharmacy for destruction — that’s standard NZ community practice." />
                </Card>
            ) : (
                bands.map((b, n) => {
                    const rows = list.filter(b.pick);
                    return (
                        <section key={b.key} className="flex flex-col gap-2.5" aria-label={b.title}>
                            <ListCaption
                                title={b.title}
                                caption={`${rows.length} shown · ${b.caption}`}
                                right={
                                    n === 0 && canRecord(p) ? (
                                        <Button size="sm" data-return="destroy-new" onClick={() => open('destroy:new')}>
                                            Return for destruction
                                        </Button>
                                    ) : undefined
                                }
                            />
                            {rows.length ? (
                                <EntityTable<Destruction>
                                    rows={rows}
                                    rowKey={(d) => d.id}
                                    rowHeight="content"
                                    minWidth={960}
                                    identityLabel="Destruction"
                                    identityWidth="1.1fr"
                                    identity={(d) => ({ icon: PackageX, name: medOf(d.medId).med, subline: <Wrap>{d.id} · {who(medOf(d.medId))}</Wrap> })}
                                    columns={[
                                        { key: 'qty', label: 'What', width: '1fr', cell: (d) => <span className="flex flex-col gap-0.5 text-[12.5px]"><span className={d.voided ? 'line-through decoration-1' : 'font-semibold'}>{unitLabel(medOf(d.medId), d.qty)} · {DESTROY_REASON[d.reason]}</span><StateLine>{d.method === 'return' ? `Returned to ${d.pharmacy}` : 'Destroyed on site'}</StateLine></span> },
                                        { key: 'by', label: 'Recorded', width: '1.3fr', cell: (d) => <span className="flex flex-col gap-0.5 text-[12.5px]"><span>{d.at}</span><StateLine>By {d.by}, witnessed by {d.witnesses.join(' and ')}</StateLine></span> },
                                        { key: 'state', label: 'State', width: '1.5fr', cell: (d) => <span className="flex flex-col items-start gap-1 py-0.5"><StatusBadge variant={destructionState(d).variant} className="rounded-[8px]">{destructionState(d).label}</StatusBadge>{d.received ? <StateLine>{d.received.pharmacist} · reg. {d.received.registration} · {d.received.at}</StateLine> : d.voided ? <StateLine>{d.voided.at} by {d.voided.by}, witnessed by {d.voided.witness}: “{d.voided.reason}”</StateLine> : null}</span> },
                                        {
                                            key: 'act',
                                            label: '',
                                            width: '190px',
                                            align: 'right',
                                            cell: (d) =>
                                                b.key === 'waiting' && canManage(p) ? (
                                                    <Button size="sm" data-return={`rcpt-${d.id}`} onClick={stop(() => open(`receipt:${d.id}`))}>
                                                        Record the receipt
                                                    </Button>
                                                ) : null,
                                        },
                                    ]}
                                    actionsFor={(d) => compactMenu([{ label: 'Open the destruction', icon: PackageX, onClick: () => open(`dest:${d.id}`) }, b.key === 'waiting' && canManage(p) && { label: 'Record the pharmacist’s receipt', icon: ShieldCheck, onClick: () => open(`receipt:${d.id}`) }, !d.voided && canManage(p) && { separator: true }, !d.voided && canManage(p) && { label: 'Void this destruction', icon: Trash2, onClick: () => open(`voiddest:${d.id}`), danger: true }])}
                                    onOpen={(d) => open(`dest:${d.id}`)}
                                />
                            ) : (
                                <Card className="p-2">
                                    <EmptyState variant="compact" icon={PackageX} title="None" />
                                </Card>
                            )}
                        </section>
                    );
                })
            )}
            <p className="text-caption">
                Destroying on site: <DefaultChip value={ORG_SETTINGS.onSiteDestruction ? 'Allowed, with two witnesses' : 'Not allowed'} />. Controlled medicines go back to the pharmacy for destruction by default.
            </p>
            <DesignNote title="Design note — one destruction path (Main, Q6)">
                <p>Today there are two: a register “disposal” with one witness and no destruction record, and a destruction with two witnesses and a free-text authoriser, whose register entry keeps only one witness; voiding a destruction reverses nothing (AUDIT 3.5, 8.7–8.10).</p>
            </DesignNote>
        </>
    );
}
