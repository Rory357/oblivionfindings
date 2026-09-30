/* The person medication record — /emar/mar?client_id=… (P02 v1) — as a frame
 * around Clinical › Medication reviews (Main, Q1: “the person record gets a
 * Reviews section”). The page top is P02 v1’s approved record header, as P03 v1
 * reproduced it: profile PageHeader, the identity subline, Find, Record dose,
 * the meters (P02’s reference numbers), the “As at” chip, the six-section rail
 * and the tier-2 strip. P05 adds one sub-view to Clinical; INR, Syringe driver
 * and Observations are P02’s and link-only here. */
import { EntityTable } from '@/components/lists/entity-table';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearchTrigger,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { Activity, AlertTriangle, CalendarClock, ClipboardList, Clock3, Droplets, History as HistoryIcon, Lock, Pill, RefreshCw, Repeat, ShieldAlert, Stethoscope, Syringe, Users, type LucideIcon } from 'lucide-react';
import type { MouseEvent } from 'react';
import { HOUSES, PEOPLE, PERSONAS, RECORD_REF, TRIGGER_LABEL, WHERE_LABEL, frontline, ordersOf, personByClientId, type PersonId, type Review } from '../data';
import { useOpen } from '../host';
import { canManage, cdView, changeState, changesIn, daysUntil, intervalOf, kindText, nextFor, reviewState, reviewsIn } from '../model';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
import { DesignNote, KV, Notice, PersonMark, SectionCard, StateLine, SubTabs, Wrap } from '../ui';
import { ChangeSection, outcomeSummary, reviewMenu, useCtx } from './reviews';

type Tab = 'chart' | 'medicines' | 'support' | 'allergies' | 'clinical' | 'history';
const RAIL: { key: Tab; label: string; icon: LucideIcon }[] = [
    { key: 'chart', label: 'Chart', icon: ClipboardList },
    { key: 'medicines', label: 'Medicines', icon: Pill },
    { key: 'support', label: 'Support plan', icon: Users },
    { key: 'allergies', label: 'Allergies & alerts', icon: ShieldAlert },
    { key: 'clinical', label: 'Clinical', icon: Stethoscope },
    { key: 'history', label: 'History', icon: HistoryIcon },
];
/** P02 approved INR, Syringe driver and Observations; P05 adds Medication reviews. */
const CLINICAL_VIEWS: { key: string; label: string; icon: LucideIcon }[] = [
    { key: 'inr', label: 'INR', icon: Droplets },
    { key: 'driver', label: 'Syringe driver', icon: Syringe },
    { key: 'observations', label: 'Observations', icon: Activity },
    { key: 'reviews', label: 'Medication reviews', icon: Stethoscope },
];

function NotFound({ moved }: { moved?: boolean }) {
    const s = useStore();
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication' }, { title: 'Medication record' }]}>
            <Card className="p-8 text-center">
                <p className="text-section-title">We can’t show this record</p>
                <p className="text-subtle mt-1">It may not exist, or it may not be available to you. Check the link, or go back.</p>
                <div className="mt-4">
                    <Button variant="outline" onClick={() => s.toast('info', 'Back — outside this preview.')}>
                        Go back
                    </Button>
                </div>
            </Card>
            <DesignNote title="Design note — not found (the record)">
                <p>A person outside your houses and a record that doesn’t exist look the same (404, no existence leak — P00 v5, P02 v1).{moved ? ' This is what Kōwhai House staff see for Ben, who lives at Rimu House.' : ''}</p>
            </DesignNote>
        </Shell>
    );
}

export function RecordPage() {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const pid = personByClientId(Number(s.route.q.get('client_id')));
    if (!pid || !me.houses.includes(PEOPLE[pid].house)) return <NotFound moved={pid === 'ben'} />;
    return <Record pid={pid} />;
}

function Record({ pid }: { pid: PersonId }) {
    const s = useStore();
    const r = s.route;
    const p = PEOPLE[pid];
    const ref = RECORD_REF[pid] ?? { due: 0, dueCap: 'Nothing due', late: 0, lateCap: 'None late', rec: null, allergy: '—', allergyCap: 'Not reviewed', allergyTone: 'warning' as const };
    const view = CLINICAL_VIEWS.some((v) => v.key === r.q.get('view')) ? r.q.get('view')! : 'reviews';
    const scn = r.scenario;
    const loading = scn === 'loading';
    const failed = scn === 'unavailable';
    const dash = loading ? '—' : failed ? 'Unavailable' : null;
    const meds = ordersOf(pid);
    const hidden = cdView(r.persona) ? 0 : meds.filter((m) => m.cd).length;
    const prn = meds.filter((m) => m.prn && (!m.cd || cdView(r.persona))).length;
    const go = (t: Tab) => (t === 'clinical' ? s.set({ view: undefined, open: undefined }) : s.toast('info', `${RAIL.find((x) => x.key === t)!.label} is P02’s approved section — outside this preview.`));
    const crumbs = frontline(r.persona)
        ? [{ title: 'Home', href: '/dashboard' }, { title: 'Meds today', href: '/meds/today' }, { title: p.legal }]
        : [{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/emar/mar' }, { title: 'MAR & medicines', href: '/emar/mar' }, { title: p.legal }];
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
            variant="profile"
            wrapTitle
            backHref={hrefFor(frontline(r.persona) ? '/meds/today' : '/emar/mar', {}, r)}
            mark={
                <span className="eh-mark-ring overflow-hidden p-0">
                    <PersonMark pid={pid} size={44} />
                </span>
            }
            title={p.legal}
            titleChip={<PageHeaderStatusChip variant={p.left ? 'neutral' : 'success'}>{p.left ? 'Left the service' : 'Active'}</PageHeaderStatusChip>}
            subline={
                <>
                    “{p.pref}” · {p.age} years · NHI {p.nhi} (test)
                    <br />
                    Medication record · {HOUSES[p.house]} · Supported living
                </>
            }
            actions={
                <>
                    <PageHeaderSearchTrigger placeholder="Find in this record…" onOpen={() => s.toast('info', 'Find in this record is P02’s — outside this preview.')} />
                    {PERSONAS[r.persona].perms.includes('administer') && !p.left ? (
                        <PageHeaderPrimaryButton icon={Pill} onClick={() => s.toast('info', 'Opens P01’s approved “Record a dose” dialog — outside this preview.')}>
                            Record dose
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock label="Due now" onClick={() => go('chart')} ariaLabel="View doses due now on the chart">
                        <PageHeaderMeterBig>{dash ?? ref.due}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{loading ? 'Loading…' : failed ? 'Try again below' : ref.dueCap}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Late" tone={!dash && ref.late ? 'warning' : 'brand'} onClick={() => go('chart')} ariaLabel="View late doses on the chart">
                        <PageHeaderMeterBig>{dash ?? ref.late}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : ref.lateCap}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Recorded today" value={dash || !ref.rec ? undefined : `${ref.rec[0]}/${ref.rec[1]}`} onClick={() => go('history')} ariaLabel="View today’s recorded doses in History">
                        {dash || !ref.rec ? <PageHeaderMeterBig>{dash ?? 'n/a'}</PageHeaderMeterBig> : <PageHeaderMeterDonut percent={Math.round((ref.rec[0] / ref.rec[1]) * 100)} caption="of doses due so far" />}
                        {dash || !ref.rec ? <PageHeaderMeterCaption>{dash ? '—' : 'No doses due yet'}</PageHeaderMeterCaption> : null}
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Medicines" onClick={() => go('medicines')} ariaLabel="View medicines">
                        <PageHeaderMeterBig>{dash ?? meds.length - hidden}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : hidden ? `+${hidden} controlled hidden` : prn ? `${prn} as needed` : 'All checked'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Allergies" tone={ref.allergyTone} onClick={() => go('allergies')} ariaLabel="View allergies">
                        <PageHeaderMeterBig>{ref.allergy}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{ref.allergyCap}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {'inr' in ref && ref.inr ? (
                        <PageHeaderMeterBlock label="INR" value={ref.inr.on} onClick={() => s.set({ view: 'inr' })} ariaLabel="View INR results">
                            <PageHeaderMeterBig>{dash ?? ref.inr.value}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>{dash ? '—' : ref.inr.cap}</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}
                </>
            }
            filters={asAt}
            rail={<PageHeaderRail<Tab> items={RAIL} value="clinical" onSelect={(k) => go(k)} onFind={() => s.toast('info', 'Find is P02’s — outside this preview.')} ariaLabel="Medication record sections" />}
        />
    );

    let body: React.ReactNode;
    if (failed)
        body = (
            <Card className="p-2">
                <ErrorState title="We couldn’t load this medication record" message="Nothing is shown rather than an incomplete record. Reviews that are due are still due. Try again." onRetry={() => s.setViewer({ scenario: 'normal' })} />
            </Card>
        );
    else if (loading)
        body = (
            <Card className="p-4" aria-busy="true" aria-label="Loading reviews">
                <SkeletonTable rows={4} columns={4} />
            </Card>
        );
    else if (view !== 'reviews')
        body = (
            <Card className="p-2">
                <EmptyState icon={Lock} title={`${CLINICAL_VIEWS.find((x) => x.key === view)!.label} is P02’s approved view`} description="This preview covers Clinical › Medication reviews only." />
            </Card>
        );
    else body = <PersonReviews pid={pid} />;

    return (
        <Shell crumbs={crumbs}>
            {header}
            <SubTabs
                tabs={CLINICAL_VIEWS.map((v) => ({ key: v.key, label: v.label, icon: v.icon }))}
                active={view}
                onTab={(k) => s.set({ view: k, open: undefined })}
                hrefOf={(k) => hrefFor(r.path, { client_id: String(p.clientId), tab: 'clinical', view: k }, r)}
                label="Clinical views"
            />
            <div role="tabpanel" className="flex min-w-0 flex-col gap-5">
                {scn === 'stale' ? (
                    <Notice tone="warning" icon={AlertTriangle} title="This record may be out of date" actions={<Button size="sm" variant="outline" onClick={() => s.toast('info', 'Refreshing… (mockup: set the scenario back to normal).')}><RefreshCw className="size-4" /> Try again</Button>}>
                        We couldn’t refresh it at 9:12 am. It shows what was known at 8:40 am.
                    </Notice>
                ) : null}
                {body}
            </div>
        </Shell>
    );
}

/* ───────────── Clinical › Medication reviews ───────────── */
function PersonReviews({ pid }: { pid: PersonId }) {
    const s = useStore();
    const p = s.route.persona;
    const person = PEOPLE[pid];
    const open = useOpen();
    const ctx = useCtx();
    const empty = s.route.scenario === 'empty';
    const next = empty ? null : nextFor(s.rt, pid);
    const every = intervalOf(s.rt, pid);
    const mine = empty ? [] : reviewsIn(s.rt, p).filter((x) => x.pid === pid).sort((a, b) => b.dueIso.localeCompare(a.dueIso));
    const changes = empty ? [] : changesIn(s.rt, p).filter((c) => c.review.pid === pid);
    const watch = changes.filter((c) => changeState(c.item).step === 'watching');
    const menu = (x: Review) => reviewMenu(x, p, open, s.go);
    const stop = (fn: () => void) => (e: MouseEvent) => {
        e.stopPropagation();
        fn();
    };
    return (
        <>
            <SectionCard
                icon={CalendarClock}
                eyebrow="Next review"
                title={person.left ? 'No review booked' : next ? `${next.due} · ${kindText(next)}` : 'No review booked yet'}
                right={
                    canManage(p) && !person.left ? (
                        <>
                            {next ? (
                                next.booked && daysUntil(next.booked.iso) <= 0 ? (
                                    <Button size="sm" data-return={`rec-${next.id}`} onClick={() => open(`record:${next.id}`)}>
                                        Record the outcome
                                    </Button>
                                ) : (
                                    <Button size="sm" variant="outline" data-return={`appt-${next.id}`} onClick={() => open(`appt:${next.id}`)}>
                                        {next.booked ? 'Change the appointment' : 'Book the appointment'}
                                    </Button>
                                )
                            ) : (
                                <Button size="sm" onClick={() => open(`book:${pid}`)}>
                                    Book a review
                                </Button>
                            )}
                            <Button size="sm" variant="outline" data-return="interval" onClick={() => open(`interval:${pid}`)}>
                                <Repeat className="size-4" /> Change how often
                            </Button>
                        </>
                    ) : null
                }
            >
                {person.left ? (
                    <p className="text-subtle">{person.left}. Open reviews were closed automatically, with that reason.</p>
                ) : next ? (
                    <KV
                        rows={[
                            ['State', <StatusBadge key="st" variant={reviewState(next).variant} className="rounded-[8px]">{reviewState(next).label}</StatusBadge>],
                            ['Appointment', next.booked ? `${next.booked.with.name} (${next.booked.with.role}, ${next.booked.with.practice}) · ${next.booked.at} · ${WHERE_LABEL[next.booked.where].toLowerCase()}` : 'Not booked with a clinician yet'],
                            ['Owner', next.owner],
                            ['How often', every.own ? `Every ${every.months} months — set for ${person.pref}${every.set ? ` (${every.set})` : ''}` : `Every ${every.months} months — the organisation default`],
                            ...(person.away && daysUntil(next.dueIso) < 0 ? ([['Away', `${person.away} — the due date isn’t paused`]] as [string, string][]) : []),
                        ]}
                    />
                ) : (
                    <p className="text-subtle">Book the first review to start {person.pref}’s cycle — every {every.months} months after that.</p>
                )}
            </SectionCard>

            <ChangeSection rows={changes} compact />
            {watch.length ? (
                <p className="text-caption -mt-2">
                    What to watch stays on {person.pref}’s record while its follow-up is open. The house lead owns the follow-up.
                </p>
            ) : null}

            <section className="flex flex-col gap-2.5" aria-label="Reviews">
                <ListCaption title="Reviews" caption={`${mine.length} shown · newest first · kept, including cancelled and closed ones`} />
                {mine.length ? (
                    <EntityTable<Review>
                        rows={mine}
                        rowKey={(x) => x.id}
                        rowHeight="content"
                        minWidth={900}
                        identityLabel="Review"
                        identityWidth="1.4fr"
                        identity={(x) => ({ icon: Stethoscope, name: `${kindText(x)} · ${x.id}`, subline: <Wrap>{x.trigger ? `${TRIGGER_LABEL[x.trigger]} · ` : ''}due {x.due}</Wrap> })}
                        columns={[
                            {
                                key: 'state',
                                label: 'State',
                                width: '1.2fr',
                                cell: (x) => (
                                    <span className="flex flex-col items-start gap-1 py-0.5">
                                        <StatusBadge variant={reviewState(x).variant} className="rounded-[8px]">
                                            {reviewState(x).label}
                                        </StatusBadge>
                                        {x.state === 'recorded' ? <StateLine>{x.recorded!.at}</StateLine> : x.state === 'cancelled' ? <StateLine>{x.cancelled!.at}</StateLine> : x.state === 'closed' ? <StateLine>{x.closed!.at}</StateLine> : null}
                                    </span>
                                ),
                            },
                            {
                                key: 'who',
                                label: 'Who',
                                width: '1.5fr',
                                cell: (x) => (
                                    <span className="text-[12.5px]">
                                        <Wrap>{x.recorded ? x.recorded.clinicians.map((c) => `${c.name} (${c.role})`).join(', ') : x.booked ? `${x.booked.with.name} (${x.booked.with.role})` : x.state === 'booked' ? 'Not booked with a clinician yet' : x.cancelled?.reason ?? x.closed?.reason}</Wrap>
                                    </span>
                                ),
                            },
                            { key: 'out', label: 'Outcome', width: '1.3fr', cell: (x) => <span className="text-[12.5px]">{x.recorded ? outcomeSummary(x) : '—'}</span> },
                            {
                                key: 'act',
                                label: '',
                                width: '150px',
                                align: 'right',
                                cell: (x) => (
                                    <Button size="sm" variant="outline" data-return={`open-${x.id}`} onClick={stop(() => open(`review:${x.id}`))}>
                                        Open
                                    </Button>
                                ),
                            },
                        ]}
                        actionsFor={menu}
                        onOpen={(x) => open(`review:${x.id}`)}
                        onRowContextMenu={(e, x) => ctx.openAt(e, `${x.id} · ${person.legal}`, menu(x))}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={Stethoscope} title={`No reviews for ${person.pref} yet`} />
                    </Card>
                )}
                {ctx.node}
            </section>
            <DesignNote title="Design note — the person’s reviews (Main, Q1, Q9)">
                <p>
                    One sub-view of Clinical: the next review and how often, the changes still open, and every review kept. Support workers see the dates, each medicine’s outcome and what to watch; the clinician’s own summary shows only to people who manage reviews. A controlled medicine reads “Controlled medicine” without controlled-medicine access (P02’s redaction).
                </p>
            </DesignNote>
        </>
    );
}
