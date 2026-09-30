/* MAR & medicines › Support & self-administration — /emar/self-admin (plan §2.2
 * hub 2; §7.3 P03). The page top is P02 v1’s approved MAR & medicines hub
 * header, unchanged (its dose meters are P02’s reference numbers here); P03
 * designs this view’s filters and body: the register of everyone at the
 * persona’s houses, grouped by what needs doing, plus recent changes. Every row
 * opens the person’s record › Support plan (the canonical record, P02). */
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
import { AlertTriangle, ClipboardList, Clock3, Eye, FileSignature, History, Home, MessageSquareText, Pill, RefreshCw, Users, type LucideIcon } from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';
import { CHANGES, HOUSES, PEOPLE, PEOPLE_ORDER, PERSONAS, SUPPORT, SUPPORT_ORDER, medsOf, type House, type PersonId, type Support } from '../data';
import { useOpen } from '../host';
import { agreementOf, assessmentOf, canAssess, canRecordConsent, capOf, cdView, consentOf, mixOf, statusOf, whoAssesses, type PlanState } from '../model';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
import { ConcealedCount, DesignNote, Notice, PlanBadge, StateLine, SupportChip } from '../ui';

type HubView = 'charts' | 'medicines' | 'asneeded' | 'selfadmin';
const RAIL: { key: HubView; label: string; icon: LucideIcon }[] = [
    { key: 'charts', label: 'MAR charts', icon: ClipboardList },
    { key: 'medicines', label: 'Medicines', icon: Pill },
    { key: 'asneeded', label: 'As-needed history', icon: History },
    { key: 'selfadmin', label: 'Support & self-administration', icon: Users },
];
/** P02 v1’s approved hub numbers for each scope (reference values — P03 doesn’t model doses). */
function hubRef(houses: House[], cd: boolean) {
    const both = houses.length > 1;
    if (!both && houses[0] === 'rimu') return { people: 1, due: 0, dueCap: 'Nothing due right now', late: 0, lateCap: 'None late', help: 0, helpCap: 'Nothing blocked', rec: [1, 1], meds: 1, medsCap: 'All checked', prn: 0, prnCap: 'No effect checks overdue' };
    if (!both) return { people: 5, due: 6, dueCap: '3 people · until 10:00 am', late: 2, lateCap: 'Oldest due 8:00 am', help: 1, helpCap: 'Blocked — see why', rec: [3, 11], meds: 18, medsCap: '1 order to check', prn: 6, prnCap: '1 effect check overdue' };
    return cd
        ? { people: 6, due: 6, dueCap: '3 people · until 10:00 am', late: 2, lateCap: 'Oldest due 8:00 am', help: 1, helpCap: 'Blocked — see why', rec: [4, 12], meds: 19, medsCap: '1 order to check', prn: 6, prnCap: '1 effect check overdue' }
        : { people: 6, due: 5, dueCap: '3 people · until 10:00 am', late: 2, lateCap: 'Oldest due 8:00 am', help: 1, helpCap: 'Blocked — see why', rec: [4, 11], meds: 16, medsCap: '+3 controlled hidden', prn: 6, prnCap: '1 effect check overdue' };
}

export function RegisterPage() {
    const s = useStore();
    const r = s.route;
    const me = PERSONAS[r.persona];
    const [search, setSearch] = useState('');
    const houseParam = r.q.get('house') as House | null;
    const houses = me.houses.filter((h) => !houseParam || h === houseParam);
    const scn = r.scenario;
    const loading = scn === 'loading';
    const failed = scn === 'unavailable';
    const empty = scn === 'empty';
    const ref = hubRef(houses, cdView(r.persona));
    const dash = loading ? '—' : failed ? 'Unavailable' : null;
    const houseText = me.houses.length > 1 && !houseParam ? `${HOUSES[me.houses[0]]} and ${HOUSES[me.houses[1]]}` : HOUSES[houses[0]];
    const other = (v: HubView) => s.toast('info', `${RAIL.find((x) => x.key === v)!.label} is P02’s approved view — outside this preview.`);

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
            title="MAR & medicines"
            titleChip={<PageHeaderStatusChip variant="neutral">{empty ? 0 : ref.people} {!empty && ref.people === 1 ? 'person' : 'people'}</PageHeaderStatusChip>}
            subline={`Charts and medicines for ${houseText} · times in NZDT`}
            actions={
                <>
                    <PageHeaderSearch value={search} onChange={setSearch} placeholder="Search people or medicines…" />
                    {me.perms.includes('administer') && !empty ? (
                        <PageHeaderPrimaryButton icon={Pill} onClick={() => s.toast('info', 'Opens P01’s approved “Record as-needed dose” dialog — outside this preview.')}>
                            Record as-needed dose
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock label="Due now" onClick={() => other('charts')} ariaLabel="View people with doses due now">
                        <PageHeaderMeterBig>{dash ?? (empty ? 0 : ref.due)}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : empty ? 'Nothing due right now' : ref.dueCap}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Late" tone={!dash && !empty && ref.late ? 'warning' : 'brand'} onClick={() => other('charts')} ariaLabel="View people with late doses">
                        <PageHeaderMeterBig>{dash ?? (empty ? 0 : ref.late)}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : empty ? 'None late' : ref.lateCap}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Needs help" tone={!dash && !empty && ref.help ? 'critical' : 'brand'} onClick={() => other('charts')} ariaLabel="View doses that can’t be recorded yet">
                        <PageHeaderMeterBig>{dash ?? (empty ? 0 : ref.help)}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : empty ? 'Nothing blocked' : ref.helpCap}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Recorded today" value={dash || empty ? undefined : `${ref.rec[0]}/${ref.rec[1]}`} onClick={() => other('charts')} ariaLabel="View today’s charts">
                        {dash || empty ? <PageHeaderMeterBig>{dash ?? 'n/a'}</PageHeaderMeterBig> : <PageHeaderMeterDonut percent={Math.round((ref.rec[0] / ref.rec[1]) * 100)} caption="of doses due so far" />}
                        {dash || empty ? <PageHeaderMeterCaption>{dash ? '—' : 'No doses due yet'}</PageHeaderMeterCaption> : null}
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Medicines" onClick={() => other('medicines')} ariaLabel="View medicines">
                        <PageHeaderMeterBig>{dash ?? (empty ? 0 : ref.meds)}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : empty ? 'None on a chart' : ref.medsCap}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="As needed · 24 h" onClick={() => other('asneeded')} ariaLabel="View as-needed history">
                        <PageHeaderMeterBig>{dash ?? (empty ? 0 : ref.prn)}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : empty ? 'No effect checks overdue' : ref.prnCap}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    {me.houses.length > 1 ? (
                        <PageHeaderFilterSelect icon={Home} label="House" value={houseParam ?? 'all'} allValue="all" onChange={(v) => s.set({ house: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All your houses' }, ...me.houses.map((h) => ({ value: h, label: HOUSES[h] }))]} />
                    ) : null}
                    <PageHeaderFilterSelect
                        label="Show"
                        value={r.q.get('show') ?? 'all'}
                        allValue="all"
                        onChange={(v) => s.set({ show: v === 'all' ? undefined : v })}
                        options={[
                            { value: 'all', label: 'Everyone' },
                            { value: 'reassess', label: 'Reassess now' },
                            { value: 'none', label: 'No assessment' },
                            { value: 'agreement', label: 'Agreement needed' },
                            { value: 'consent', label: 'Asked for a change' },
                        ]}
                    />
                    <PageHeaderFilterSelect icon={Users} label="Support" value={r.q.get('sup') ?? 'all'} allValue="all" onChange={(v) => s.set({ sup: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'Any support' }, ...SUPPORT_ORDER.map((k) => ({ value: k, label: SUPPORT[k].label }))]} />
                    {asAt}
                </>
            }
            rail={<PageHeaderRail<HubView> items={RAIL} value="selfadmin" onSelect={(k) => (k === 'selfadmin' ? undefined : other(k))} ariaLabel="MAR & medicines views" />}
        />
    );

    let body: ReactNode;
    if (loading)
        body = (
            <Card className="p-4" aria-busy="true" aria-label="Loading support plans">
                <SkeletonTable rows={6} columns={6} />
            </Card>
        );
    else if (failed)
        body = (
            <Card className="p-2">
                <ErrorState title="We couldn’t load support plans for your houses" message="Nothing is shown rather than an incomplete list. Staff keep following each person’s support as shown in Meds today. Try again." onRetry={() => s.setViewer({ scenario: 'normal' })} />
            </Card>
        );
    else if (empty)
        body = (
            <Card className="p-2">
                <EmptyState icon={Users} title="No self-administration assessments at your houses yet" description="Until someone is assessed, staff give every medicine (Administer). Start one from the person’s record › Support plan." />
            </Card>
        );
    else body = <Register houses={houses} search={search} />;

    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/emar/mar' }, { title: 'MAR & medicines', href: '/emar/mar' }, { title: 'Support & self-administration' }]}>
            {header}
            {scn === 'stale' ? (
                <Notice tone="warning" icon={AlertTriangle} title="This may be out of date" actions={<Button size="sm" variant="outline" onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}><RefreshCw className="size-4" /> Try again</Button>}>
                    We couldn’t refresh at 9:12 am. It shows what was known at 8:40 am. Check the person’s record before changing their support.
                </Notice>
            ) : null}
            {body}
        </Shell>
    );
}

/* ───────────── the register ───────────── */
interface Row {
    pid: PersonId;
    state: PlanState;
}
function useRowContext() {
    const [ctx, setCtx] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const openAt = (e: MouseEvent, title: string, items: MenuItem[]) => {
        if (e.target instanceof Element && e.target.closest('a[href]')) return;
        e.preventDefault();
        const b = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const kb = e.clientX === 0 && e.clientY === 0;
        setCtx({ x: kb ? b.left + 12 : e.clientX, y: kb ? b.top + 12 : e.clientY, title, items });
    };
    const node = ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={Users} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null;
    return { openAt, node };
}

function Register({ houses, search }: { houses: House[]; search: string }) {
    const s = useStore();
    const r = s.route;
    const p = r.persona;
    const open = useOpen();
    const ctx = useRowContext();
    const show = r.q.get('show') ?? 'all';
    const sup = r.q.get('sup') as Support | null;
    const q = search.trim().toLowerCase();
    const recordHref = (pid: PersonId, view?: string) => hrefFor('/emar/mar', { client_id: String(PEOPLE[pid].clientId), tab: 'support', view }, r);
    const goRecord = (pid: PersonId, view?: string) => (window.location.hash = recordHref(pid, view));
    const people = PEOPLE_ORDER.filter((pid) => houses.includes(PEOPLE[pid].house));
    const rows: Row[] = people
        .map((pid) => ({ pid, state: statusOf(pid, s.rt, p).state }))
        .filter(({ pid, state }) => {
            const st = statusOf(pid, s.rt, p);
            if (show === 'reassess' && !['reassess', 'overdue'].includes(state)) return false;
            if (show === 'none' && state !== 'none') return false;
            if (show === 'agreement' && !st.agreementMissing) return false;
            if (show === 'consent' && !consentOf(pid, s.rt).length) return false;
            if (sup && !medsOf(pid).some((m) => (s.rt.support[m.key] ?? m.support) === sup && (!m.cd || cdView(p)))) return false;
            if (q && !`${PEOPLE[pid].legal} ${PEOPLE[pid].pref} ${medsOf(pid).filter((m) => !m.cd || cdView(p)).map((m) => m.name).join(' ')}`.toLowerCase().includes(q)) return false;
            return true;
        });
    const menu = (row: Row): MenuItem[] => {
        const a = assessmentOf(row.pid, s.rt);
        const st = statusOf(row.pid, s.rt, p);
        return compactMenu([
            { label: 'Open the support plan', icon: Eye, onClick: () => goRecord(row.pid) },
            !!a && { label: 'View the assessment', icon: ClipboardList, onClick: () => goRecord(row.pid, 'assessment') },
            canAssess(p) && { label: a ? 'Reassess' : 'Start an assessment', icon: RefreshCw, onClick: () => open(`assess:${row.pid}`) },
            canAssess(p) && st.agreementMissing && { label: 'Record the agreement', icon: FileSignature, onClick: () => open(`agreement:${row.pid}`) },
            canRecordConsent(p) && !!a && { label: 'Record a change the person asked for', icon: MessageSquareText, onClick: () => open(`consent:${row.pid}`) },
        ]);
    };
    const action = (row: Row) => {
        const a = assessmentOf(row.pid, s.rt);
        const stop = (fn: () => void) => (e: MouseEvent) => (e.stopPropagation(), fn());
        if (canAssess(p) && (row.state === 'reassess' || row.state === 'overdue' || row.state === 'none'))
            return (
                <Button data-return={`reg-${row.pid}`} size="sm" variant={row.state === 'reassess' ? 'default' : 'outline'} onClick={stop(() => open(`assess:${row.pid}`))}>
                    {a ? 'Reassess' : 'Start assessment'}
                </Button>
            );
        return (
            <Button data-return={`reg-${row.pid}`} size="sm" variant="ghost" onClick={stop(() => goRecord(row.pid))}>
                View
            </Button>
        );
    };
    const hiddenTotal = rows.reduce((n, row) => n + mixOf(row.pid, s.rt, p).hidden, 0);
    const table = (list: Row[], key: string) => (
        <EntityTable<Row>
            rows={list}
            rowKey={(x) => x.pid}
            rowHeight="content"
            minWidth={960}
            identityLabel="Person"
            identityWidth="170px"
            identity={(x) => ({ mark: <PersonDisc name={PEOPLE[x.pid].legal} size={30} />, name: `${PEOPLE[x.pid].pref} ${PEOPLE[x.pid].surname}`, subline: HOUSES[PEOPLE[x.pid].house] })}
            columns={[
                {
                    key: 'mix',
                    label: 'Support by medicine',
                    width: '1.4fr',
                    cell: (x) => {
                        const { counts, hidden } = mixOf(x.pid, s.rt, p);
                        return (
                            <span className="flex flex-col items-start gap-1 py-0.5">
                                <span className="flex flex-wrap gap-1">
                                    {[...SUPPORT_ORDER, 'unset' as const].filter((k) => counts[k]).map((k) => (
                                        <span key={k} className="inline-flex items-center gap-1 text-[12px]">
                                            <SupportChip support={k === 'unset' ? null : k} />
                                            <span className="text-muted-foreground">×{counts[k]}</span>
                                        </span>
                                    ))}
                                </span>
                                {hidden ? <span className="text-[11.5px] text-muted-foreground">+{hidden} controlled not shown</span> : null}
                            </span>
                        );
                    },
                },
                { key: 'cap', label: 'Most independence allowed', width: '1fr', cell: (x) => (assessmentOf(x.pid, s.rt) ? <SupportChip support={capOf(x.pid, s.rt)} /> : <span className="text-[12.5px] text-muted-foreground">Not assessed</span>) },
                {
                    key: 'agr',
                    label: 'Agreement',
                    width: '1.1fr',
                    cell: (x) => {
                        const ag = agreementOf(x.pid, s.rt);
                        const st = statusOf(x.pid, s.rt, p);
                        if (ag) return <span className="text-[12.5px]">{ag.role === 'person' ? `${ag.how === 'signed' ? 'Signed' : 'Agreed verbally'} by ${PEOPLE[x.pid].pref}` : `${ag.role === 'guardian' ? 'Welfare guardian' : 'EPOA'} · ${ag.how === 'signed' ? 'signed' : 'verbal'}`} · {ag.on}</span>;
                        return st.agreementMissing ? <StateLine tone="warning">Needed — not recorded</StateLine> : <span className="text-[12.5px] text-muted-foreground">Not needed — staff give or help</span>;
                    },
                },
                { key: 'by', label: 'Reassess by', width: '0.8fr', cell: (x) => <span className="text-[12.5px] font-semibold">{assessmentOf(x.pid, s.rt)?.reassessBy ?? '—'}</span> },
                {
                    key: 'state',
                    label: 'State',
                    width: '1.6fr',
                    cell: (x) => {
                        const st = statusOf(x.pid, s.rt, p);
                        return (
                            <span className="flex flex-col items-start gap-1 py-0.5">
                                <PlanBadge state={st.state} />
                                {st.lines.map((l) => (
                                    <StateLine key={l.text} tone={l.tone}>
                                        {l.text}
                                    </StateLine>
                                ))}
                            </span>
                        );
                    },
                },
                { key: 'act', label: '', width: '150px', align: 'right', cell: (x) => action(x) },
            ]}
            actionsFor={menu}
            onOpen={(x) => goRecord(x.pid)}
            onRowContextMenu={(e, x) => ctx.openAt(e, `${PEOPLE[x.pid].pref} ${PEOPLE[x.pid].surname}`, menu(x))}
            key={key}
        />
    );
    const sections: [string, Row[], string][] = [
        ['Reassess now', rows.filter((x) => x.state === 'reassess' || x.state === 'overdue'), 'Support stays as it is until the reassessment'],
        ['No assessment', rows.filter((x) => x.state === 'none'), 'Staff give every medicine until an assessment is done'],
        ['Up to date', rows.filter((x) => x.state === 'current' || x.state === 'soon'), 'Reassess by the date shown'],
    ];
    return (
        <>
            {sections
                .filter(([, list]) => list.length || show === 'all')
                .map(([title, list, cap]) => {
                    const hidden = list.reduce((n, row) => n + mixOf(row.pid, s.rt, p).hidden, 0);
                    return (
                        <section key={title} className="flex flex-col gap-2.5" aria-label={title}>
                            <ListCaption
                                title={title}
                                caption={
                                    <>
                                        {list.length} shown{hidden ? <> · <ConcealedCount n={hidden} /></> : null} · {cap}
                                    </>
                                }
                            />
                            {list.length ? table(list, title) : <Card className="p-2"><EmptyState variant="compact" icon={Users} title={`Nobody here${show !== 'all' || q || sup ? ' matches' : ''}`} description={show !== 'all' || q || sup ? 'Clear the filters or the search in the header.' : title === 'Reassess now' ? 'No reassessments are due at your houses.' : 'Everyone at your houses has an assessment.'} /></Card>}
                        </section>
                    );
                })}
            {!rows.length && show !== 'all' ? (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={Users} title="Nobody matches" description="Clear the filters or the search in the header." />
                </Card>
            ) : null}
            <RecentChanges houses={houses} />
            {ctx.node}
            <p className="text-caption">
                Times in NZDT. Support is set per medicine, at or below the most independence the assessment allows. {canAssess(p) ? '' : `Assessments are done by ${whoAssesses}. `}
                {hiddenTotal ? 'Controlled medicines are left out for your role and counted in the captions.' : ''}
            </p>
        </>
    );
}

/* ───────────── recent changes (today’s Activity tab, as a list) ───────────── */
function RecentChanges({ houses }: { houses: House[] }) {
    const s = useStore();
    const p = s.route.persona;
    const all = CHANGES.filter((c) => houses.includes(PEOPLE[c.pid].house));
    const list = all.filter((c) => !c.cd || cdView(p)).slice(0, 8);
    const hidden = all.length - all.filter((c) => !c.cd || cdView(p)).length;
    return (
        <section className="flex flex-col gap-2.5" aria-label="Recent changes">
            <ListCaption
                title="Recent changes"
                caption={
                    <>
                        {list.length} shown{hidden ? <> · <ConcealedCount n={hidden} noun="change" /></> : null} · newest first · kept, never deleted
                    </>
                }
            />
            <EntityTable<(typeof list)[number]>
                rows={list}
                rowKey={(c) => c.id}
                rowHeight="content"
                minWidth={960}
                identityLabel="When"
                identityWidth="170px"
                identity={(c) => ({ icon: History, name: c.at.split(', ')[0], subline: c.at.split(', ')[1] ?? '' })}
                columns={[
                    { key: 'who', label: 'Person', width: '1fr', cell: (c) => <span className="text-[12.5px]">{PEOPLE[c.pid].pref} {PEOPLE[c.pid].surname}</span> },
                    { key: 'what', label: 'What changed', width: '2fr', cell: (c) => <span className="text-[12.5px] font-semibold">{c.what}</span> },
                    { key: 'ba', label: 'Before → after', width: '1.6fr', cell: (c) => <span className="text-[12.5px]">{c.before ? `${c.before} → ` : ''}{c.after}</span> },
                    { key: 'who', label: 'By', width: '0.9fr', cell: (c) => <span className="text-[12.5px]">{c.who}</span> },
                ]}
                actionsFor={(c) => compactMenu([{ label: 'Open the support plan', icon: Eye, onClick: () => (window.location.hash = hrefFor('/emar/mar', { client_id: String(PEOPLE[c.pid].clientId), tab: 'support', view: 'changes' }, s.route)) }])}
                onOpen={(c) => (window.location.hash = hrefFor('/emar/mar', { client_id: String(PEOPLE[c.pid].clientId), tab: 'support', view: 'changes' }, s.route))}
            />
            <DesignNote title="Design note — replaces today’s /emar/self-admin">
                <p>Today’s page is a PageHero (“Self-administration oversight · live”) with five tabs. Here it is the MAR &amp; medicines hub’s fourth view (P02’s header, unchanged): one register grouped by what needs doing, the per-medicine support mix, the agreement and the reassessment date. Assessments, agreements and support changes open from each row or the person’s Support plan.</p>
            </DesignNote>
        </section>
    );
}
