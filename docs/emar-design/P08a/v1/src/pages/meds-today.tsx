/* Meds today › Follow-ups — the frontline view P08a designs (P01 kept it
 * link-only). P01 v2’s approved page top (PageHeader, six meters, the rail,
 * the header actions) is unchanged; the Schedule numbers come from P01’s
 * Schedule, which isn’t modelled here. Follow-ups are visible to everyone
 * rostered at the house until done (Stephan, D12); the list splits into
 * yours, others at the house, and done today. */
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
import { ErrorState } from '@/components/ui/error-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { Activity, AlertTriangle, CheckCircle2, Clock3, Flag, Inbox, Package, Pill, Plus, RefreshCw, Repeat, ShieldCheck, WifiOff } from 'lucide-react';
import { useState } from 'react';
import { HOUSES, PEOPLE, PERSONAS, TYPE_LABEL, hoById, isFrontline, type FuType } from '../data';
import { useOpen } from '../host';
import { SCHEDULE, isOpen } from '../model';
import { FollowUpTable } from '../rows';
import { Shell } from '../shell';
import { hrefFor, useStore, type Row } from '../store';
import { DesignNote, Notice } from '../ui';

type View = 'schedule' | 'rounds' | 'asneeded' | 'followups' | 'controlled' | 'stockalerts' | 'activity';
export const TYPE_FILTER: { value: string; label: string; types: FuType[] }[] = [
    { value: 'all', label: 'All types', types: [] },
    { value: 'effect', label: 'As-needed effect checks', types: ['effect'] },
    { value: 'reoffer', label: 'Refusals', types: ['reoffer'] },
    { value: 'second', label: 'Second person', types: ['confirm', 'unconfirmed', 'partial', 'disputed', 'override'] },
    { value: 'lead', label: 'For the house lead', types: ['unconfirmed', 'partial', 'disputed', 'override', 'countersign', 'handover'] },
];
export const matchesType = (r: Row, v: string) => v === 'all' || (TYPE_FILTER.find((t) => t.value === v)?.types ?? []).includes(r.f.type);
export const matchesSearch = (r: Row, q: string) => !q || `${r.f.pid ? `${PEOPLE[r.f.pid].pref} ${PEOPLE[r.f.pid].surname}` : ''} ${r.f.title} ${r.f.source} ${r.owner ?? ''} ${TYPE_LABEL[r.f.type]}`.toLowerCase().includes(q);

export function MedsTodayPage() {
    const s = useStore();
    const r = s.route;
    const open = useOpen();
    const persona = PERSONAS[r.persona];
    const view = (r.q.get('view') as View) || 'followups';
    const [search, setSearch] = useState('');
    const scn = r.scenario;
    const loading = scn === 'loading';
    const unavailable = scn === 'unavailable';
    const rows = s.rows().filter((x) => x.f.house === 'kowhai');
    const openRows = rows.filter((x) => isOpen(x.state));
    const overdue = openRows.filter((x) => x.state === 'overdue');
    const mine = openRows.filter((x) => x.owner === persona.name);
    const setView = (v: View) => s.go('/meds/today', { view: v === 'followups' ? undefined : v, who: undefined, type: undefined });
    const link = (v: View) => hrefFor('/meds/today', { view: v }, r);

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
        ['Due now', 'Late', 'Needs help', 'Recorded', 'Follow-ups', 'My eligibility'].map((l) => (
            <PageHeaderMeterBlock key={l} label={l} href={link('schedule')} ariaLabel={`${l}: unavailable`}>
                <PageHeaderMeterBig>—</PageHeaderMeterBig>
                <PageHeaderMeterCaption>Unavailable</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        ))
    ) : (
        <>
            <PageHeaderMeterBlock label="Due now" href={link('schedule')} ariaLabel={`View ${SCHEDULE.dueNow} doses due now`}>
                <PageHeaderMeterBig>{SCHEDULE.dueNow}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{SCHEDULE.dueCaption}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Late" tone="warning" href={link('schedule')} ariaLabel={`View ${SCHEDULE.late} late doses`}>
                <PageHeaderMeterBig>{SCHEDULE.late}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{SCHEDULE.lateCaption}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Needs help" href={link('schedule')} ariaLabel="View 0 doses you can’t record as given">
                <PageHeaderMeterBig>0</PageHeaderMeterBig>
                <PageHeaderMeterCaption>Nothing blocked</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Recorded" value={`${SCHEDULE.recorded} of ${SCHEDULE.of}`} href={link('activity')} ariaLabel={`View activity, ${SCHEDULE.recorded} of ${SCHEDULE.of} recorded`}>
                <PageHeaderMeterDonut percent={(SCHEDULE.recorded / SCHEDULE.of) * 100} caption="Due so far on your shift" />
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="Follow-ups" tone={overdue.length ? 'critical' : 'brand'} href={link('followups')} ariaLabel={`View follow-ups, ${overdue.length} overdue`}>
                <PageHeaderMeterBig>{scn === 'empty' ? '0' : overdue.length ? `${overdue.length} overdue` : String(openRows.length)}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{scn === 'empty' ? 'None open' : overdue.length ? 'Oldest 11:30 pm Sunday' : 'None overdue'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock label="My eligibility" tone="success" onClick={() => s.toast('info', 'My eligibility is P01’s dialog — outside this preview.')} ariaLabel="View my eligibility: current">
                <PageHeaderMeterBig>Current</PageHeaderMeterBig>
                <PageHeaderMeterCaption>{r.persona === 'lead' ? 'To 12 Jun 2027' : r.persona === 'daniel' ? 'To 6 Oct 2026' : 'To 14 Mar 2027'}</PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    );
    const updated =
        scn === 'stale' ? (
            <PageHeaderFilterButton icon={AlertTriangle} className="border-status-warning" onClick={() => s.toast('info', 'Refreshed at 9:12 am NZDT (mockup).')}>
                Not updated since 8:40 am NZDT
            </PageHeaderFilterButton>
        ) : scn === 'offline' ? (
            <PageHeaderFilterButton icon={WifiOff} onClick={() => s.toast('warning', 'Offline — the list shows what was loaded at 9:05 am NZDT.')}>
                Offline · last updated 9:05 am NZDT
            </PageHeaderFilterButton>
        ) : (
            <PageHeaderFilterButton icon={RefreshCw} title="All times are NZDT (Pacific/Auckland). Refresh." onClick={() => s.toast('info', 'Refreshed at 9:12 am NZDT (mockup).')}>
                Updated 9:12 am NZDT
            </PageHeaderFilterButton>
        );
    const filters =
        view === 'followups' ? (
            <>
                <PageHeaderFilterSelect label="Everyone at the house" value={r.q.get('who') ?? 'all'} options={[{ value: 'all', label: 'Everyone at the house' }, { value: 'mine', label: 'Only mine' }]} onChange={(v) => s.go('/meds/today', { who: v === 'all' ? undefined : v })} />
                <PageHeaderFilterSelect label="All types" value={r.q.get('type') ?? 'all'} options={TYPE_FILTER.map((t) => ({ value: t.value, label: t.label }))} onChange={(v) => s.go('/meds/today', { type: v === 'all' ? undefined : v })} />
                {updated}
            </>
        ) : (
            updated
        );
    const rail = (
        <PageHeaderRail<View>
            value={view}
            onSelect={setView}
            items={[
                { key: 'schedule', label: 'Schedule', icon: Clock3, ...(loading || unavailable ? {} : { count: SCHEDULE.dueNow + SCHEDULE.late, alert: SCHEDULE.late > 0 }) },
                { key: 'rounds', label: 'Rounds', icon: Repeat },
                { key: 'asneeded', label: 'As-needed', icon: Pill },
                { key: 'followups', label: 'Follow-ups', icon: Flag, ...(loading || unavailable || scn === 'empty' || !openRows.length ? {} : { count: openRows.length, alert: overdue.length > 0 }) },
                ...(persona.perms.includes('cd.record') ? [{ key: 'controlled' as const, label: 'Controlled checks', icon: ShieldCheck }] : []),
                { key: 'stockalerts', label: 'Stock alerts', icon: Package },
                { key: 'activity', label: 'Activity', icon: Activity },
            ]}
        />
    );
    const header = (
        <PageHeader
            icon={Pill}
            title="Meds today"
            titleChip={<PageHeaderStatusChip variant="success" icon={CheckCircle2}>On shift</PageHeaderStatusChip>}
            subline={`Mon 28 Sep 2026 · Kōwhai House · shift ${persona.shift ?? ''}`}
            actions={
                <>
                    <PageHeaderSearch value={search} onChange={setSearch} placeholder="Search people or medicines…" />
                    <PageHeaderGlassButton icon={Repeat} aria-label="Shift handover — medication" title="Shift handover — medication" onClick={() => open('handover-draft')} />
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
    if (!persona.shift)
        return (
            <Shell crumbs={crumbs}>
                <Card className="gap-3 p-8 text-center">
                    <p className="text-section-title">You’re not rostered at a house today</p>
                    <p className="text-subtle">Follow-ups for your houses are in Safety &amp; oversight › Follow-ups.</p>
                    <div>
                        <Button variant="outline" onClick={() => s.go('/emar/safety', { view: 'followups' })}>
                            Open Safety &amp; oversight
                        </Button>
                    </div>
                </Card>
            </Shell>
        );
    return (
        <Shell crumbs={crumbs}>
            {header}
            {view === 'followups' ? <FollowUpsView search={search} /> : <LinkOnly view={view} />}
        </Shell>
    );
}

function LinkOnly({ view }: { view: View }) {
    const pkg: Record<string, string> = { schedule: 'P01 · Record a dose', rounds: 'P01 · Record a dose', asneeded: 'P01 · Record a dose', controlled: 'P07a · Controlled checks (approved)', stockalerts: 'P06 · Stock & pharmacy', activity: 'P01 · Record a dose' };
    return (
        <Card className="gap-3 p-5">
            <p className="text-section-title">This view is designed in {pkg[view]} — outside this preview</p>
            <DesignNote>P08a designs only the Follow-ups view of Meds today. The other views keep their approved or planned designs; nothing here is a stub.</DesignNote>
        </Card>
    );
}

function FollowUpsView({ search }: { search: string }) {
    const s = useStore();
    const r = s.route;
    const open = useOpen();
    const scn = r.scenario;
    const me = PERSONAS[r.persona].name;
    const q = search.trim().toLowerCase();
    const who = r.q.get('who') ?? 'all';
    const type = r.q.get('type') ?? 'all';
    const all = s.rows().filter((x) => x.f.house === 'kowhai' && matchesType(x, type) && matchesSearch(x, q));
    const sort = (a: Row, b: Row) => (a.state === 'overdue' ? 0 : 1) - (b.state === 'overdue' ? 0 : 1) || a.f.dueMin - b.f.dueMin;
    const openRows = all.filter((x) => isOpen(x.state)).sort(sort);
    // An unowned carried-over follow-up counts as the incoming worker’s (Q1) — Priya takes the night handover.
    const mine = openRows.filter((x) => x.owner === me || (x.owner === null && !!x.f.carried && me === hoById('h-kow-am')!.to));
    const others = openRows.filter((x) => !mine.includes(x));
    const done = all.filter((x) => !isOpen(x.state) || x.state === 'queued');
    const ackPending = scn === 'ackPending' && !s.acked['h-kow-am'];
    if (scn === 'loading')
        return (
            <Card className="p-5" aria-busy="true" aria-label="Loading follow-ups">
                <SkeletonTable rows={6} columns={5} />
            </Card>
        );
    if (scn === 'unavailable')
        return (
            <Card className="p-2">
                <ErrorState title="Couldn’t load follow-ups" message="Some may be overdue. Don’t rely on memory — try again, or ask the house lead what’s open." onRetry={() => s.toast('warning', 'Still unavailable (mockup).')} />
            </Card>
        );
    return (
        <>
            {scn === 'stale' ? (
                <Notice tone="warning" title="Not updated since 8:40 am NZDT (32 min ago)" actions={<Button size="sm" variant="outline" onClick={() => s.toast('info', 'Refreshed at 9:12 am NZDT (mockup).')}><RefreshCw className="size-4" /> Refresh now</Button>}>
                    Someone may have done a follow-up since. Refresh before you act — saving always checks again.
                </Notice>
            ) : null}
            {ackPending && r.persona === 'sw' ? (
                <Notice tone="info" icon={Repeat} title="You haven’t acknowledged the night handover yet" actions={<Button size="sm" onClick={() => open('handover:h-kow-am')}>Open the handover</Button>}>
                    1 follow-up was carried over from the night shift. Everyone on shift can see it now; it becomes yours when you acknowledge. Acknowledging never stops you recording.
                </Notice>
            ) : (
                <Notice tone="neutral" icon={Repeat} title={`Night handover · acknowledged ${s.acked['h-kow-am']?.at ?? '7:05 am'} by ${s.acked['h-kow-am']?.by ?? 'Priya Shah'}`} actions={<Button size="sm" variant="outline" onClick={() => open('handover:h-kow-am')}>View the handover</Button>}>
                    1 follow-up was carried over from the night shift (Wiremu Hēnare).
                </Notice>
            )}
            {scn === 'empty' ? (
                <Card className="p-2">
                    <EmptyState icon={CheckCircle2} title="Nothing to follow up" description="No effect checks, refusals or second-person questions are open at Kōwhai House. Updated 9:12 am NZDT." />
                </Card>
            ) : (
                <>
                    <section className="flex flex-col gap-2.5" aria-label="Yours">
                        <ListCaption title="Yours" caption={`${mine.length} open${mine.some((x) => x.state === 'overdue') ? ` · ${mine.filter((x) => x.state === 'overdue').length} overdue` : ''}`} />
                        {mine.length ? <FollowUpTable rows={mine} keyPrefix="mine" /> : <Card className="p-2"><EmptyState icon={Inbox} title="Nothing of yours is open" description={who === 'mine' ? 'Choose “Everyone at the house” to see what others have open.' : 'Anything you record that needs a follow-up — an as-needed dose, a refusal — shows here.'} /></Card>}
                    </section>
                    {who === 'all' ? (
                        <section className="flex flex-col gap-2.5" aria-label="Others at the house">
                            <ListCaption title={`Others at ${HOUSES.kowhai}`} caption={`${others.length} open · everyone on shift sees these until they’re done`} />
                            {others.length ? <FollowUpTable rows={others} keyPrefix="others" /> : <Card className="p-2"><EmptyState icon={Inbox} title="Nothing else open" description="No one else at the house has a follow-up open." /></Card>}
                        </section>
                    ) : null}
                </>
            )}
            <section className="flex flex-col gap-2.5" aria-label="Done today">
                <ListCaption title="Done today" caption={`${done.length} shown`} />
                {done.length ? <FollowUpTable rows={done} keyPrefix="done" /> : <Card className="p-2"><EmptyState icon={Inbox} title="Nothing done yet today" /></Card>}
            </section>
            <p className="text-caption">Times in NZDT. Worker follow-ups (effect checks, refusals) can be closed by the owner or anyone rostered at the house; follow-ups for the house lead show here so everyone knows they’re being dealt with. Anything still open at the end of a shift carries over to the next one.</p>
        </>
    );
}
