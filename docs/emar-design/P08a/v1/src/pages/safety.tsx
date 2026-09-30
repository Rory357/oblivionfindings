/* Safety & oversight (hub 5) — P11 v5’s approved header and rail (title,
 * houses chip, one-line subline, the seven views), with the two views P11
 * assigned to P08a designed: Follow-ups (the oversight queue over the same
 * records as Meds today) and Handovers (the register, replacing today’s
 * /emar/handovers PageHero page). Other views are link-only. */
import { PersonDisc } from '@/components/lists/entity-cells';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
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
import { AlertOctagon, AlertTriangle, Check, CheckCircle2, Eye, Flag, Gauge, Home, Inbox, Lock, LockKeyhole, RefreshCw, Repeat, Send, Shield, UserCheck } from 'lucide-react';
import { useState } from 'react';
import { HANDOVERS, HOUSES, PERSONAS, isFrontline, type Handover, type HouseKey } from '../data';
import { useOpen } from '../host';
import { isLead, isOpen } from '../model';
import { FollowUpTable, useRowContext } from '../rows';
import { Shell } from '../shell';
import { useStore, type Row } from '../store';
import { DesignNote, NotConfigured, StateLine } from '../ui';
import { TYPE_FILTER, matchesSearch, matchesType } from './meds-today';

const SAFETY_VIEWS: { key: string; label: string; icon: typeof Gauge; pkg: string }[] = [
    { key: 'overview', label: 'Overview', icon: Gauge, pkg: 'P09' },
    { key: 'followups', label: 'Follow-ups', icon: Flag, pkg: 'P08a' },
    { key: 'overrides', label: 'Witness overrides', icon: Shield, pkg: 'P07b' },
    { key: 'errors', label: 'Medication errors', icon: AlertOctagon, pkg: 'P08b' },
    { key: 'handovers', label: 'Handovers', icon: Repeat, pkg: 'P08a' },
    { key: 'eligibility', label: 'Staff eligibility', icon: UserCheck, pkg: 'P11 (approved)' },
    { key: 'emergency', label: 'Emergency access', icon: LockKeyhole, pkg: 'P10' },
];

export function SafetyPage() {
    const s = useStore();
    const r = s.route;
    const open = useOpen();
    const p = PERSONAS[r.persona];
    const view = r.q.get('view') ?? 'followups';
    const [q, setQ] = useState('');
    const scn = r.scenario;
    const houseQ = (r.q.get('house') as HouseKey | null) ?? null;
    const houses = houseQ && p.houses.includes(houseQ) ? [houseQ] : p.houses;
    const crumbs = [{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/emar/safety' }, { title: 'Safety & oversight' }];
    if (isFrontline(r.persona))
        return (
            <Shell crumbs={crumbs}>
                <Card className="items-center gap-3 p-10 text-center">
                    <Lock className="size-8 text-muted-foreground" aria-hidden="true" />
                    <h1 className="text-section-title">You don’t have access to Safety &amp; oversight</h1>
                    <p className="text-subtle">Ask your manager if you need it for your work. Your follow-ups are in Meds today.</p>
                    <Button variant="outline" onClick={() => s.go('/meds/today', { view: 'followups' })}>
                        Go to Meds today
                    </Button>
                </Card>
            </Shell>
        );

    const rows = s.rows().filter((x) => houses.includes(x.f.house));
    const openRows = rows.filter((x) => isOpen(x.state));
    const overdue = openRows.filter((x) => x.state === 'overdue');
    const leadOpen = openRows.filter((x) => isLead(x.f));
    const carried = openRows.filter((x) => x.f.carried);
    const hRows = HANDOVERS.filter((h) => houses.includes(h.house));
    const ackOf = (h: Handover) => s.acked[h.id] ?? (scn === 'ackPending' && h.id === 'h-kow-am' ? null : h.ack);
    const needAck = hRows.filter((h) => !ackOf(h));
    const loading = scn === 'loading';
    const unavailable = scn === 'unavailable';
    const blank = (l: string) => (
        <PageHeaderMeterBlock key={l} label={l} ariaLabel={`${l}: ${loading ? 'loading' : 'unavailable'}`} onClick={() => undefined}>
            <PageHeaderMeterBig>—</PageHeaderMeterBig>
            <PageHeaderMeterCaption>{loading ? 'Loading…' : 'Unavailable'}</PageHeaderMeterCaption>
        </PageHeaderMeterBlock>
    );
    const go = (params: Record<string, string | undefined>) => s.go('/emar/safety', { view, ...params });
    const meters =
        view === 'followups' ? (
            loading || unavailable ? (
                ['Overdue', 'Due today', 'For a lead', 'Carried over', 'Escalations', 'Done on time'].map(blank)
            ) : (
                <>
                    <PageHeaderMeterBlock label="Overdue" tone={overdue.length ? 'critical' : 'brand'} ariaLabel={`View ${overdue.length} overdue follow-ups`} onClick={() => go({ st: 'overdue' })}>
                        <PageHeaderMeterBig>{overdue.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{overdue.length ? 'Oldest 11:30 pm Sunday' : 'Nothing overdue'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Due today" ariaLabel={`View ${openRows.length - overdue.length} follow-ups due`} onClick={() => go({ st: 'open' })}>
                        <PageHeaderMeterBig>{openRows.length - overdue.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>Next {openRows.filter((x) => x.state !== 'overdue').sort((a, b) => a.f.dueMin - b.f.dueMin)[0]?.f.due ?? '—'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="For a lead" tone={leadOpen.length ? 'warning' : 'brand'} ariaLabel={`View ${leadOpen.length} follow-ups for a lead`} onClick={() => go({ type: 'lead' })}>
                        <PageHeaderMeterBig>{leadOpen.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>Sign-offs and checks</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Carried over" ariaLabel={`View ${carried.length} carried-over follow-ups`} onClick={() => go({ st: 'carried' })}>
                        <PageHeaderMeterBig>{carried.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>From an earlier shift</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Escalations" tone={scn === 'delivery' ? 'critical' : 'brand'} ariaLabel="View escalations not acknowledged" onClick={() => (scn === 'delivery' ? go({ st: 'overdue' }) : s.toast('info', 'Reminders and escalation are set in Settings › Alerts › Delivery (P11, approved) — outside this preview.'))}>
                        {scn === 'delivery' ? (
                            <>
                                <PageHeaderMeterBig>2</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>Not acknowledged</PageHeaderMeterCaption>
                            </>
                        ) : (
                            <>
                                <PageHeaderMeterBig>Off</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>Not configured</PageHeaderMeterCaption>
                            </>
                        )}
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="On time" value="31 of 36" ariaLabel="View done follow-ups, 31 of 36 on time in the last 7 days" onClick={() => go({ st: 'done' })}>
                        <PageHeaderMeterDonut percent={(31 / 36) * 100} caption="Last 7 days" />
                    </PageHeaderMeterBlock>
                </>
            )
        ) : view === 'handovers' ? (
            loading || unavailable ? (
                ['Not acknowledged', 'Acknowledged', 'Carried over', 'Doses with no outcome', 'Controlled counts'].map(blank)
            ) : (
                <>
                    <PageHeaderMeterBlock label="Not acknowledged" tone={needAck.length ? 'warning' : 'brand'} ariaLabel={`View ${needAck.length} handovers not acknowledged`} onClick={() => go({ hst: 'need' })}>
                        <PageHeaderMeterBig>{needAck.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{needAck.length ? `${HOUSES[needAck[0].house].replace(' House', '')} · since ${needAck[0].change.split(' ')[0]} ${needAck[0].change.split(' ')[1]}` : 'All read'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Acknowledged" ariaLabel={`View ${hRows.length - needAck.length} acknowledged handovers`} onClick={() => go({ hst: 'acked' })}>
                        <PageHeaderMeterBig>{hRows.length - needAck.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>Last 24 hours</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Carried over" ariaLabel={`View ${carried.length} follow-ups carried over`} onClick={() => s.go('/emar/safety', { view: 'followups', st: 'carried' })}>
                        <PageHeaderMeterBig>{carried.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>Follow-ups still open</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Doses with no outcome" ariaLabel="View doses with no outcome at a handover: none" onClick={() => go({ hst: 'all' })}>
                        <PageHeaderMeterBig>0</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>At the last shift changes</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {p.perms.includes('cd.view') ? (
                        <PageHeaderMeterBlock label="Controlled counts" value={`${hRows.length} of ${hRows.length}`} ariaLabel="Controlled counts at the shift change: all counted" onClick={() => s.toast('info', 'Opens Meds today › Controlled checks — P07a’s approved view, outside this preview.')}>
                            <PageHeaderMeterDonut percent={100} caption="Counted, matched" />
                        </PageHeaderMeterBlock>
                    ) : null}
                </>
            )
        ) : undefined;
    const updated = (
        <PageHeaderFilterButton icon={scn === 'stale' ? AlertTriangle : RefreshCw} onClick={() => s.toast('info', 'Refreshed at 9:12 am NZDT (mockup).')}>
            {scn === 'stale' ? 'Not updated since 8:40 am' : 'Updated 9:12 am'}
        </PageHeaderFilterButton>
    );
    const houseSel = <PageHeaderFilterSelect icon={Home} label="All houses" value={houseQ ?? 'all'} onChange={(v) => go({ house: v === 'all' ? undefined : v })} options={[{ value: 'all', label: 'All houses' }, ...p.houses.map((h) => ({ value: h, label: HOUSES[h] }))]} />;
    const filters =
        view === 'followups' ? (
            <>
                {houseSel}
                <PageHeaderFilterSelect label="Open" value={r.q.get('st') ?? 'open'} allValue="open" options={[{ value: 'open', label: 'Open' }, { value: 'overdue', label: 'Overdue' }, { value: 'carried', label: 'Carried over' }, { value: 'done', label: 'Done' }]} onChange={(v) => go({ st: v === 'open' ? undefined : v })} />
                <PageHeaderFilterSelect label="All types" value={r.q.get('type') ?? 'all'} options={TYPE_FILTER.map((t) => ({ value: t.value, label: t.label }))} onChange={(v) => go({ type: v === 'all' ? undefined : v })} />
                {updated}
            </>
        ) : view === 'handovers' ? (
            <>
                {houseSel}
                <PageHeaderFilterSelect label="All handovers" value={r.q.get('hst') ?? 'all'} options={[{ value: 'all', label: 'All handovers' }, { value: 'need', label: 'Not acknowledged' }, { value: 'acked', label: 'Acknowledged' }]} onChange={(v) => go({ hst: v === 'all' ? undefined : v })} />
                {updated}
            </>
        ) : (
            updated
        );
    const railItems = SAFETY_VIEWS.map((v) => ({ key: v.key, label: v.label, icon: v.icon, ...(v.key === 'followups' && overdue.length && !loading && !unavailable ? { count: overdue.length, alert: true } : v.key === 'handovers' && needAck.length ? { count: needAck.length, alert: true } : {}) }));
    const header = (
        <PageHeader
            className="overflow-clip!"
            icon={Shield}
            title="Safety & oversight"
            titleChip={<PageHeaderStatusChip variant="neutral">{p.houses.length} {p.houses.length === 1 ? 'house' : 'houses'}</PageHeaderStatusChip>}
            subline={`${p.houses.map((h) => HOUSES[h]).join(' and ')} · your approved houses · times in NZDT (Pacific/Auckland)`}
            actions={view === 'followups' || view === 'handovers' ? <PageHeaderSearch value={q} onChange={setQ} placeholder={view === 'followups' ? 'Search people or follow-ups' : 'Search handovers or staff'} /> : undefined}
            meters={meters}
            filters={filters}
            rail={<PageHeaderRail items={railItems} value={view} onSelect={(k) => s.go('/emar/safety', { view: k, st: undefined, type: undefined, hst: undefined })} ariaLabel="Safety & oversight views" />}
        />
    );
    const v = SAFETY_VIEWS.find((x) => x.key === view) ?? SAFETY_VIEWS[1];
    return (
        <Shell crumbs={crumbs}>
            {header}
            {view === 'followups' ? (
                <OversightFollowUps rows={rows} search={q} />
            ) : view === 'handovers' ? (
                <HandoverRegister rows={hRows} search={q} ackOf={ackOf} onOpen={(id) => open(`handover:${id}`)} />
            ) : (
                <Card className="p-2">
                    <EmptyState icon={v.icon} title={`${v.label} is designed in ${v.pkg}`} description="It isn’t part of this preview, which covers Follow-ups and Handovers (P08a)." action={<Button variant="outline" size="sm" onClick={() => s.go('/emar/safety', { view: 'followups' })}>Back to Follow-ups</Button>} />
                </Card>
            )}
        </Shell>
    );
}

function OversightFollowUps({ rows, search }: { rows: Row[]; search: string }) {
    const s = useStore();
    const r = s.route;
    const q = search.trim().toLowerCase();
    const st = r.q.get('st') ?? 'open';
    const type = r.q.get('type') ?? 'all';
    const scn = r.scenario;
    const p = PERSONAS[r.persona];
    if (scn === 'loading')
        return (
            <Card className="p-5" aria-busy="true" aria-label="Loading follow-ups">
                <SkeletonTable rows={7} columns={6} />
            </Card>
        );
    if (scn === 'unavailable')
        return (
            <Card className="p-2">
                <ErrorState title="Couldn’t load follow-ups" message="Some may be overdue at your houses. Try again, or ask the house leads on shift what’s open." onRetry={() => s.toast('warning', 'Still unavailable (mockup).')} />
            </Card>
        );
    const list = rows.filter((x) => matchesType(x, type) && matchesSearch(x, q));
    const sort = (a: Row, b: Row) => a.f.dueMin - b.f.dueMin;
    const open = list.filter((x) => isOpen(x.state));
    const overdue = open.filter((x) => x.state === 'overdue').sort(sort);
    const due = open.filter((x) => x.state !== 'overdue').sort(sort);
    const done = list.filter((x) => !isOpen(x.state) || x.state === 'queued');
    const carried = open.filter((x) => x.f.carried);
    const hidden = !p.perms.includes('cd.view');
    const sections: [string, Row[], string][] =
        st === 'done' ? [['Done in the last 24 hours', done, 'Done late shows how late']] : st === 'carried' ? [['Carried over from an earlier shift', carried, 'Owner set when the handover is acknowledged, or by a lead']] : st === 'overdue' ? [['Overdue', overdue, 'Everyone rostered and the house lead see these until they’re done']] : [['Overdue', overdue, 'Everyone rostered and the house lead see these until they’re done'], ['Due', due, 'Worker follow-ups first, then lead sign-offs']];
    return (
        <>
            {scn === 'empty' ? (
                <Card className="p-2">
                    <EmptyState icon={CheckCircle2} title="Nothing to follow up" description="No follow-ups are open at your houses. Updated 9:12 am NZDT." />
                </Card>
            ) : (
                sections.map(([title, items, cap]) => (
                    <section key={title} className="flex flex-col gap-2.5" aria-label={title}>
                        <ListCaption title={title} caption={`${items.length} shown · ${cap}`} />
                        {items.length ? <FollowUpTable rows={items} showHouse keyPrefix={title} /> : <Card className="p-2"><EmptyState icon={Inbox} title={`Nothing ${title.toLowerCase()}`} description="Change the filters to see more." /></Card>}
                    </section>
                ))
            )}
            <p className="text-caption">
                Times in NZDT. Reminders and escalation follow Settings › Alerts › Delivery {scn === 'delivery' ? '(on in this scenario: every 30 minutes, up to 3 times, then to the house lead after 60 minutes)' : <>— <NotConfigured /> until a manager switches them on</>}.{hidden ? ' Showing follow-ups your role can see. Totals exclude medicines your role can’t see.' : ''}
            </p>
        </>
    );
}

function HandoverRegister({ rows, search, ackOf, onOpen }: { rows: Handover[]; search: string; ackOf: (h: Handover) => { by: string; at: string } | null; onOpen: (id: string) => void }) {
    const s = useStore();
    const r = s.route;
    const ctx = useRowContext();
    const q = search.trim().toLowerCase();
    const hst = r.q.get('hst') ?? 'all';
    const p = PERSONAS[r.persona];
    if (r.scenario === 'loading')
        return (
            <Card className="p-5" aria-busy="true" aria-label="Loading handovers">
                <SkeletonTable rows={5} columns={6} />
            </Card>
        );
    if (r.scenario === 'unavailable')
        return (
            <Card className="p-2">
                <ErrorState title="Couldn’t load handovers" message="Try again. The handovers themselves are safe; this list just couldn’t be shown." onRetry={() => s.toast('warning', 'Still unavailable (mockup).')} />
            </Card>
        );
    const list = rows.filter((h) => (hst === 'need' ? !ackOf(h) : hst === 'acked' ? !!ackOf(h) : true) && (!q || `${h.from} ${h.to} ${HOUSES[h.house]} ${h.label}`.toLowerCase().includes(q)));
    const menu = (h: Handover): MenuItem[] =>
        compactMenu([
            { label: 'Open the handover', icon: Eye, onClick: () => onOpen(h.id) },
            !ackOf(h) && { label: `Remind ${h.to}`, icon: Send, onClick: () => s.toast('success', `${h.to} has been reminded in-app to read the handover.`) },
            { label: 'Open in Operations › Handovers', icon: Repeat, onClick: () => s.toast('info', 'The full handover (notes, mood, tasks) is the Operations handover — unchanged by P08a, outside this preview.') },
        ]);
    return (
        <section className="flex flex-col gap-2.5" aria-label="Handovers">
            <ListCaption title="Shift handovers, last 24 hours" caption={`${list.length} of ${rows.length} shown · the medication part of each handover`} />
            {list.length ? (
                <EntityTable<Handover>
                    rows={list}
                    rowKey={(h) => h.id}
                    rowHeight="content"
                    minWidth={960}
                    identityLabel="Handover"
                    identityWidth="170px"
                    identity={(h) => ({ icon: Repeat, name: h.label, subline: h.change })}
                    columns={[
                        ...(p.houses.length > 1 ? [{ key: 'house', label: 'House', width: '0.7fr', cell: (h: Handover) => <span className="text-[12.5px]">{HOUSES[h.house]}</span> }] : []),
                        { key: 'who', label: 'From → to', width: '1.1fr', cell: (h) => <span className="flex flex-col gap-1 text-[12.5px]"><span className="flex items-center gap-1.5"><PersonDisc name={h.from} size={20} />{h.from}</span><span className="flex items-center gap-1.5"><PersonDisc name={h.to} size={20} />{h.to}</span></span> },
                        {
                            key: 'ack',
                            label: 'Acknowledged',
                            width: '1.2fr',
                            cell: (h) => {
                                const a = ackOf(h);
                                return a ? (
                                    <span className="flex flex-col items-start gap-1"><StatusBadge variant="success"><Check className="size-3" />Acknowledged</StatusBadge><StateLine>{a.at} · {a.by}</StateLine></span>
                                ) : (
                                    <span className="flex flex-col items-start gap-1"><StatusBadge variant="warning"><AlertTriangle className="size-3" />Not acknowledged</StatusBadge><StateLine tone="warning">Submitted {h.submitted} · the house lead had a heads-up at 8:00 am</StateLine></span>
                                );
                            },
                        },
                        {
                            key: 'meds',
                            label: 'Medication at the shift change',
                            width: '2fr',
                            cell: (h) => (
                                <span className="flex flex-col gap-1 py-0.5 text-[12.5px]">
                                    <span>{h.carried.length ? `${h.carried.length} follow-up carried over` : 'No follow-ups left open'} · {h.noOutcome.length ? `${h.noOutcome.length} doses with no outcome` : 'every dose has an outcome'}</span>
                                    {p.perms.includes('cd.view') ? <StateLine>Controlled-drug count: {h.cdCount}</StateLine> : null}
                                    {h.supply ? <StateLine tone="warning">{h.supply}</StateLine> : null}
                                </span>
                            ),
                        },
                        { key: 'act', label: '', width: '150px', align: 'right', cell: (h) => <Button size="sm" variant="ghost" className="frontline-tap" onClick={(e) => (e.stopPropagation(), onOpen(h.id))}>Open</Button> },
                    ]}
                    actionsFor={menu}
                    onOpen={(h) => onOpen(h.id)}
                    onRowContextMenu={(e, h) => ctx.openAt(e, `${h.label} · ${HOUSES[h.house]}`, menu(h))}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState icon={Inbox} title="No handovers match" description="Change the filters to see more." />
                </Card>
            )}
            <p className="text-caption">Times in NZDT. Only the incoming worker can acknowledge. Acknowledging closes no medication work and never blocks recording.{p.perms.includes('cd.view') ? '' : ' Controlled-drug counts aren’t shown to your role.'}</p>
            {ctx.node}
            <DesignNote>Replaces the medication handovers page at /emar/handovers (today a PageHero with “Kia ora …”). The URL is kept; the handover itself (notes, mood, tasks) stays in Operations › Handovers.</DesignNote>
        </section>
    );
}
