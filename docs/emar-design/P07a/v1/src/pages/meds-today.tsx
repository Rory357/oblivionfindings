/* Meds today (/meds/today) — P01 v1's approved page top (PageHeader, the six
 * meters, the seven-view rail, the header actions), unchanged, with this
 * preview's synthetic 2:48 pm data. P07a designs the Controlled checks view;
 * Schedule is shown as a reference frame to prove controlled-medicine
 * concealment; the other views are P01 / P08a / P06 and are link-only here. */
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
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { Activity, AlertTriangle, CalendarDays, CheckCircle2, Clock3, Flag, ListChecks, Lock, LogIn, Package, Pill, Plus, RefreshCw, Repeat, ShieldCheck, WifiOff } from 'lucide-react';
import { useState } from 'react';
import { DOSES, HOUSES, PEOPLE, PERSONAS, can, isFrontline, seesControlledChecks, type Dose } from '../data';
import { useOpen } from '../host';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
import { DesignNote, PersonMark, StateLine } from '../ui';
import { ControlledView, useControlledAttention, useRowContext } from './controlled';

type View = 'schedule' | 'rounds' | 'asneeded' | 'followups' | 'controlled' | 'stockalerts' | 'activity';

/** Today's doses a persona may see: their houses, and no controlled rows without controlled-medicine view (EM-12). */
export function useVisibleDoses() {
    const s = useStore();
    const p = PERSONAS[s.route.persona];
    return DOSES.filter((d) => p.houses.includes(d.house) && (!d.cdMed || p.perms.includes('cd.view')));
}

export function MedsTodayPage() {
    const s = useStore();
    const r = s.route;
    const open = useOpen();
    const persona = PERSONAS[r.persona];
    const controlledAllowed = seesControlledChecks(r.persona);
    const requested = (r.q.get('view') as View) || 'schedule';
    const view: View = requested;
    const [search, setSearch] = useState('');
    const scn = r.scenario;
    const loading = scn === 'loading';
    const unavailable = scn === 'unavailable';
    const notClocked = scn === 'notClockedIn' && !s.clockedIn && r.persona === 'sw';
    const setView = (v: View) => s.go('/meds/today', { view: v === 'schedule' ? undefined : v, page: undefined, show: undefined, person: undefined, house: undefined, range: undefined, state: undefined });
    const link = (v: View, extra: Record<string, string | undefined> = {}) => hrefFor('/meds/today', { view: v === 'schedule' ? undefined : v, ...extra }, r);

    /* ── meters: P01 v1's six blocks, counted from the doses this persona may see ── */
    const doses = useVisibleDoses();
    const due = doses.filter((d) => d.state === 'due');
    const opened = doses.filter((d) => d.state !== 'notdue');
    const recorded = opened.filter((d) => d.state === 'given' || d.state === 'refused');
    const needsHelp = scn === 'noWitness' && s.override !== 'approved' ? due.filter((d) => d.cdMed) : [];
    const followUps = 1 + ((r.persona === 'lead' || r.persona === 'pm') && s.followUp === 'open' && persona.perms.includes('cd.view') ? 1 : 0);
    const elig =
        r.persona === 'tomasi'
            ? { big: 'Not assessed', cap: 'Can’t sign doses as given', tone: 'critical' as const }
            : r.persona === 'mere'
              ? { big: 'Current', cap: 'To 2 Feb 2027', tone: 'success' as const }
              : r.persona === 'lead'
                ? { big: 'Current', cap: 'To 12 Jun 2027', tone: 'success' as const }
                : r.persona === 'pm'
                  ? { big: 'Current', cap: 'To 5 May 2027', tone: 'success' as const }
                  : { big: 'Current', cap: 'To 14 Mar 2027', tone: 'success' as const };
    const eligMeter = (
        <PageHeaderMeterBlock label="My eligibility" tone={elig.tone} onClick={() => (persona.perms.includes('cd.view') ? open('elig') : s.toast('info', 'My eligibility is P01’s dialog — outside this preview.'))} ariaLabel={`View my eligibility: ${elig.big.toLowerCase()}`}>
            <PageHeaderMeterBig>{elig.big}</PageHeaderMeterBig>
            <PageHeaderMeterCaption>{elig.cap}</PageHeaderMeterCaption>
        </PageHeaderMeterBlock>
    );
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
            {eligMeter}
        </>
    ) : (
        <>
            <PageHeaderMeterBlock label="Due now" href={link('schedule')} ariaLabel={`View ${due.length} doses due now`}>
                <PageHeaderMeterBig>{due.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{due.length ? `${new Set(due.map((d) => d.pid)).size} ${new Set(due.map((d) => d.pid)).size === 1 ? 'person' : 'people'} · by ${due.some((d) => d.cdMed) ? '3:30 pm' : '3:00 pm'}` : 'Next: 5:00 pm'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Late" tone="brand" href={link('schedule')} ariaLabel="View 0 late doses">
                <PageHeaderMeterBig>0</PageHeaderMeterBig>
                <PageHeaderMeterCaption>Nothing late</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Needs help" tone={needsHelp.length ? 'warning' : 'brand'} href={link(needsHelp.length ? 'controlled' : 'schedule')} ariaLabel={`View ${needsHelp.length} doses you can’t record as given`}>
                <PageHeaderMeterBig>{needsHelp.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{needsHelp.length ? 'No witness on shift' : 'Nothing blocked'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Recorded" value={`${recorded.length} of ${opened.length}`} href={link('activity')} ariaLabel={`View activity, ${recorded.length} of ${opened.length} recorded`}>
                <PageHeaderMeterDonut percent={(recorded.length / Math.max(1, opened.length)) * 100} caption={persona.shift ? 'Due so far on your shift' : 'Due so far today'} />
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Follow-ups" href={link('followups')} ariaLabel={`View follow-ups, ${followUps} open`}>
                <PageHeaderMeterBig>{followUps}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>None overdue</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            {eligMeter}
        </>
    );

    /* ── filters: every view carries real pills ── */
    const updated =
        scn === 'stale' ? (
            <PageHeaderFilterButton icon={AlertTriangle} className="border-status-warning" onClick={() => s.toast('info', 'Refreshed at 2:48 pm NZDT (mockup).')}>
                Not updated since 2:20 pm NZDT
            </PageHeaderFilterButton>
        ) : scn === 'offline' ? (
            <PageHeaderFilterButton icon={WifiOff} onClick={() => s.toast('warning', 'Offline — the list shows what was loaded at 2:41 pm NZDT.')}>
                Offline · last updated 2:41 pm NZDT
            </PageHeaderFilterButton>
        ) : (
            <PageHeaderFilterButton icon={RefreshCw} title="All times are NZDT (Pacific/Auckland). Refresh." onClick={() => s.toast('info', 'Refreshed at 2:48 pm NZDT (mockup).')}>
                Updated 2:48 pm NZDT
            </PageHeaderFilterButton>
        );
    const filters =
        view === 'controlled' && controlledAllowed ? (
            <>
                <PageHeaderFilterSelect label="Everything" value={r.q.get('show') ?? 'all'} options={[{ value: 'all', label: 'Everything' }, { value: 'now', label: 'Needs doing now' }]} onChange={(v) => s.go('/meds/today', { show: v === 'all' ? undefined : v, page: undefined })} />
                {persona.houses.length > 1 ? (
                    <PageHeaderFilterSelect label="All my houses" value={r.q.get('house') ?? 'all'} options={[{ value: 'all', label: 'All my houses' }, ...persona.houses.map((h) => ({ value: h, label: HOUSES[h] }))]} onChange={(v) => s.go('/meds/today', { house: v === 'all' ? undefined : v, page: undefined })} />
                ) : (
                    <PageHeaderFilterSelect label="All people" value={r.q.get('person') ?? 'all'} options={[{ value: 'all', label: 'All people' }, { value: 'aroha', label: 'Aroha' }, { value: 'grace', label: 'Grace' }]} onChange={(v) => s.go('/meds/today', { person: v === 'all' ? undefined : v, page: undefined })} />
                )}
                <PageHeaderFilterSelect label="Register: last 24 hours" allValue="24h" value={r.q.get('range') ?? '24h'} options={[{ value: '24h', label: 'Register: last 24 hours' }, { value: 'today', label: 'Register: today only' }]} onChange={(v) => s.go('/meds/today', { range: v === '24h' ? undefined : v, page: undefined })} />
                {updated}
            </>
        ) : view === 'schedule' ? (
            <>
                <PageHeaderFilterSelect label="All states" value={r.q.get('state') ?? 'all'} options={[{ value: 'all', label: 'All states' }, { value: 'open', label: 'Due or late' }, { value: 'recorded', label: 'Has an outcome' }]} onChange={(v) => s.go('/meds/today', { state: v === 'all' ? undefined : v })} />
                {updated}
            </>
        ) : (
            updated
        );

    const attention = useControlledAttention();
    const rail = (
        <PageHeaderRail<View>
            value={view}
            onSelect={setView}
            items={[
                { key: 'schedule', label: 'Schedule', icon: Clock3, ...(loading || unavailable ? {} : { count: due.length }) },
                { key: 'rounds', label: 'Rounds', icon: Repeat },
                { key: 'asneeded', label: 'As-needed', icon: Pill },
                { key: 'followups', label: 'Follow-ups', icon: Flag, ...(loading || unavailable ? {} : { count: followUps }) },
                ...(controlledAllowed ? [{ key: 'controlled' as const, label: 'Controlled checks', icon: ShieldCheck, ...(loading || unavailable || !attention.count ? {} : { count: attention.count, alert: attention.alert }) }] : []),
                { key: 'stockalerts', label: 'Stock alerts', icon: Package },
                { key: 'activity', label: 'Activity', icon: Activity },
            ]}
        />
    );
    const subline = persona.shift ? `Mon 28 Sep 2026 · Kōwhai House · shift ${persona.shift}` : 'Mon 28 Sep 2026 · Kōwhai House and Rimu House';
    const header = (
        <PageHeader
            icon={Pill}
            title="Meds today"
            titleChip={notClocked ? <PageHeaderStatusChip variant="warning" icon={LogIn}>Not clocked in</PageHeaderStatusChip> : persona.shift ? <PageHeaderStatusChip variant="success" icon={CheckCircle2}>On shift</PageHeaderStatusChip> : <PageHeaderStatusChip variant="neutral" icon={CalendarDays}>Not rostered today</PageHeaderStatusChip>}
            subline={subline}
            actions={
                <>
                    <PageHeaderSearch value={search} onChange={setSearch} placeholder="Search people or medicines…" />
                    <PageHeaderGlassButton icon={Repeat} aria-label="Shift handover — medication" title="Shift handover — medication" onClick={() => s.toast('info', 'The medication handover lens is designed in P08a — it shows the shift-change count status and opens this count — outside this preview.')} />
                    <PageHeaderGlassButton icon={Flag} aria-label="Report a medication error" title="Report a medication error" onClick={() => s.toast('info', 'Opens the existing Report a medication error dialog (redesigned in P08b) — outside this preview.')} />
                    {isFrontline(r.persona) || r.persona === 'lead' ? (
                        <PageHeaderPrimaryButton icon={Plus} onClick={() => s.toast('info', 'Record as-needed dose opens P01’s approved dialog — outside this preview.')}>
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
            {view === 'controlled' ? controlledAllowed ? <ControlledView search={search} /> : <NoAccessCard /> : null}
            {view === 'schedule' ? <ScheduleReference search={search} /> : null}
            {view === 'rounds' ? <LinkOnly pkg="P01 · Record a dose" what="Rounds and the guided round, as approved in P01 v1." /> : null}
            {view === 'asneeded' ? <LinkOnly pkg="P01 · Record a dose" what="As-needed medicines and today’s as-needed doses, as approved in P01 v1. A controlled as-needed dose needs a witness there." /> : null}
            {view === 'followups' ? <LinkOnly pkg="P08a · Follow-ups & handover" what="Your follow-ups with owner and due time. The house lead’s “Check doses given without a witness” follow-up is listed there too — the same item as in Controlled checks, one record." /> : null}
            {view === 'stockalerts' ? <LinkOnly pkg="P06 · Stock & pharmacy" what="Stock and supply alerts for this house." /> : null}
            {view === 'activity' ? <LinkOnly pkg="P01 · Record a dose" what="Everything recorded in the last 24 hours, as approved in P01 v1." /> : null}
        </Shell>
    );
}

/* Page-level "no access" (P00 v5): names the view, never its content. */
function NoAccessCard() {
    const s = useStore();
    return (
        <Card className="items-center gap-3 p-10 text-center">
            <Lock className="size-8 text-muted-foreground" aria-hidden="true" />
            <h2 className="text-section-title">You don’t have access to Controlled checks</h2>
            <p className="text-subtle">Ask your manager if you need it for your work.</p>
            <Button variant="outline" onClick={() => s.go('/meds/today', { view: undefined })}>
                Go to the Schedule
            </Button>
            <DesignNote>Page-level “no access” names the view, never its content. A direct link to a controlled record shows “We can’t show this record” instead, so a hidden record and a missing one look the same.</DesignNote>
        </Card>
    );
}

function LinkOnly({ pkg, what }: { pkg: string; what: string }) {
    return (
        <Card className="gap-3 p-5">
            <p className="text-section-title">This view is designed in {pkg} — outside this preview</p>
            <p className="text-subtle">{what}</p>
            <DesignNote>P07a designs only the Controlled checks view. The other Meds today views keep their approved or planned designs; nothing here is a stub.</DesignNote>
        </Card>
    );
}

/* ───────────── Schedule — reference frame for concealment (P01 v1's list, simplified) ───────────── */
function ScheduleReference({ search }: { search: string }) {
    const s = useStore();
    const r = s.route;
    const p = PERSONAS[r.persona];
    const ctx = useRowContext();
    const q = search.trim().toLowerCase();
    const state = r.q.get('state') ?? 'all';
    const rows = useVisibleDoses()
        .filter((d) => d.house === 'kowhai')
        .filter((d) => !q || `${PEOPLE[d.pid].pref} ${PEOPLE[d.pid].surname} ${d.med}`.toLowerCase().includes(q))
        .filter((d) => (state === 'open' ? d.state === 'due' : state === 'recorded' ? d.state === 'given' || d.state === 'refused' : true));
    const concealed = !can(r.persona, 'cd.view');
    const menu = (d: Dose) => [
        { label: d.state === 'due' ? 'Record dose' : 'View dose details', icon: d.state === 'due' ? CheckCircle2 : ListChecks, onClick: () => s.toast('info', 'Recording and dose detail are P01’s approved dialog — outside this preview.') },
        { label: `Open ${PEOPLE[d.pid].pref}’s medication record`, icon: ListChecks, onClick: () => s.toast('info', 'The person’s medication record is designed in P02 — outside this preview.') },
    ];
    return (
        <>
            <DesignNote title="Reference frame">P01 v1’s Schedule, simplified, to show controlled-medicine concealment with this preview’s data. The approved Schedule is P01’s.</DesignNote>
            <section className="flex flex-col gap-2.5" aria-label="Today’s doses">
                <ListCaption title="Today at Kōwhai House" caption={`${rows.length} shown`} />
                {rows.length ? (
                    <EntityTable<Dose>
                        rows={rows}
                        rowKey={(d) => d.id}
                        rowHeight="content"
                        minWidth={900}
                        identityLabel="Person"
                        identityWidth="1fr"
                        identity={(d) => ({ mark: <PersonMark pid={d.pid} />, name: PEOPLE[d.pid].pref, subline: PEOPLE[d.pid].surname })}
                        columns={[
                            { key: 'med', label: 'Medicine', width: '1.5fr', cell: (d) => <span className="text-[13px] font-semibold">{d.med} <span className="font-normal text-muted-foreground">{d.strength}</span>{d.cdMed ? <span className="ml-1.5"><StatusBadge variant="neutral" size="sm"><ShieldCheck className="size-3" />Controlled</StatusBadge></span> : null}</span> },
                            { key: 'due', label: 'Due', width: '0.6fr', cell: (d) => <span className="text-[12.5px] font-semibold tabular-nums">{d.slot}</span> },
                            { key: 'st', label: 'State', width: '1.6fr', cell: (d) => <span className="flex flex-col items-start gap-1 py-0.5"><StatusBadge variant={d.state === 'given' ? 'success' : d.state === 'refused' ? 'warning' : d.state === 'due' ? 'info' : 'neutral'} className="rounded-[8px] font-semibold">{d.state === 'given' ? 'Given' : d.state === 'refused' ? 'Refused' : d.state === 'due' ? 'Due' : 'Not yet due'}</StatusBadge><StateLine>{d.line}</StateLine></span> },
                            {
                                key: 'act',
                                label: '',
                                width: '176px',
                                align: 'right',
                                cell: (d) =>
                                    d.state !== 'due' ? (
                                        <span className="text-caption">{d.state === 'notdue' ? 'Later' : 'Recorded'}</span>
                                    ) : r.persona === 'tomasi' ? (
                                        <Button size="sm" variant="outline" className="frontline-tap" onClick={(e) => (e.stopPropagation(), s.toast('info', 'P01’s “Why can’t I record this?”: you can’t sign doses as given until your medication competency is assessed — outside this preview.'))}>
                                            Why can’t I record?
                                        </Button>
                                    ) : (
                                        <Button size="sm" className="frontline-tap" onClick={(e) => (e.stopPropagation(), s.toast('info', 'Opens P01’s approved recording dialog — outside this preview.'))}>
                                            Record
                                        </Button>
                                    ),
                            },
                        ]}
                        actionsFor={menu}
                        onOpen={() => s.toast('info', 'Recording and dose detail are P01’s approved dialog — outside this preview.')}
                        onRowContextMenu={(e, d) => ctx.openAt(e, `${PEOPLE[d.pid].pref} · ${d.med}`, menu(d))}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState icon={ListChecks} title="No doses match" description="Nothing on your shift matches this search or filter. Clear the search or choose “All states”." />
                    </Card>
                )}
                <p className="text-caption">{concealed ? 'Showing medicines your role can see. Totals exclude medicines your role can’t see. ' : ''}Times in NZDT. Signed in as {p.name}.</p>
                {ctx.node}
            </section>
        </>
    );
}
