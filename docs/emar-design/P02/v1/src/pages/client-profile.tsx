/* The client profile stays the person's front door (plan §4.1). Reference frame:
 * the header, two meter rows, group rail and tier-2 strip are copied from
 * origin/main pages/operations/clients/show.tsx (PageHeader profile variant,
 * "Health & safety" group with the MAR tab). P02 designs only the MAR tab
 * contract — a summary and launch point that reads the same payload as the
 * medication record — and the allergy card that is now the one place allergies
 * are edited (Stephan, 30 Sep). */
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
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
import { SkeletonCard } from '@/components/ui/skeleton-card';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Activity,
    AlertTriangle,
    BellRing,
    CalendarDays,
    ChevronDown,
    ClipboardList,
    Cpu,
    FileHeart,
    HeartPulse,
    Home,
    LifeBuoy,
    MessageCircle,
    MoreHorizontal,
    Pencil,
    Pill,
    ShieldAlert,
    ShieldCheck,
    Stethoscope,
    Target,
    Users, ArrowUpRight } from 'lucide-react';
import { FACTS, HOUSES, P02_PERSONAS, medsOf, recorderIn01, type PersonId } from '../data';
import { useAdmins, useToday } from '../model';
import { PEOPLE } from '../p01/data';
import { useOpen } from '../p01/doses';
import { DesignNote, DoseBadge, PersonMark, SupportChip } from '../p01/ui';
import { Shell } from '../shell';
import { hrefFor, useDlg, useP02 } from '../store';
import { AllergySummary, CONCEALED, ConcealedCaption, allergyShort, useAllergy } from '../ui';
import { NoAccess, NotFound } from './record';

const GROUPS = [
    { key: 'snapshot', label: 'Snapshot', icon: Home },
    { key: 'daily', label: 'Daily care', icon: CalendarDays },
    { key: 'plans', label: 'Plans & goals', icon: Target },
    { key: 'health', label: 'Health & safety', icon: ShieldCheck },
    { key: 'day', label: 'Day-to-day', icon: ClipboardList },
    { key: 'rel', label: 'Relationships & governance', icon: Users },
];
const HEALTH_TABS = [
    { key: 'health_monitoring', label: 'Health Monitoring', icon: Activity },
    { key: 'healthcare_devices', label: 'Healthcare Devices', icon: Cpu },
    { key: 'medical', label: 'Medical', icon: Stethoscope },
    { key: 'mar', label: 'MAR', icon: Pill },
    { key: 'incidents_accidents', label: 'Incidents & Accidents', icon: AlertTriangle },
    { key: 'first_aid', label: 'First Aid', icon: LifeBuoy },
    { key: 'risk_management', label: 'Risk Management', icon: ShieldAlert },
];

export function ClientProfilePage() {
    const s = useP02();
    const pid = s.pid;
    const me = P02_PERSONAS[s.route.persona];
    if (s.route.persona === 'hr') return <NoAccess />;
    if (!pid || !me.houses.includes(FACTS[pid].house)) return <NotFound moved={pid === 'ben'} />;
    return <Profile pid={pid} />;
}

function Profile({ pid }: { pid: PersonId }) {
    const s = useP02();
    const p = PEOPLE[pid];
    const f = FACTS[pid];
    const allergy = useAllergy(pid);
    const tab = s.route.q.get('tab') ?? 'mar';
    const outside = (what: string) => () => s.toast('info', `The client profile’s ${what} — outside this preview.`);
    const meds = medsOf(pid).filter((m) => m.status !== 'stopped');
    const shownMeds = meds.filter((m) => s.cdView || !m.cd).length;
    // The live meters count pending medication dashboard alerts (INR due, stock…), not chart alerts: none for these fixtures.
    const alertsPending = 0;
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Clients', href: '/operations/clients' }, { title: p.legal }]}>
            <PageHeader
                variant="profile"
                backHref={hrefFor('/operations/clients', {}, s.route)}
                mark={
                    <span className="eh-mark-ring overflow-hidden p-0">
                        <PersonMark pid={pid} size={44} />
                    </span>
                }
                title={p.legal}
                titleChip={<PageHeaderStatusChip variant="success">{f.status}</PageHeaderStatusChip>}
                subline={
                    <>
                        <span className="block">“{p.pref}” · {f.age} years · NHI {p.nhi} (test)</span>
                        <span className="block">
                            {HOUSES[f.house]} · {f.service} · key worker {f.keyWorker}
                        </span>
                    </>
                }
                actions={
                    <>
                        <PageHeaderSearchTrigger placeholder="Search this profile…" onOpen={outside('search palette')} />
                        <PageHeaderGlassButton icon={MessageCircle} aria-label="Family chat" title="Chat with whānau" onClick={outside('family chat')} />
                        <PageHeaderGlassButton icon={Pencil} aria-label="Edit profile" title="Edit profile" onClick={outside('edit wizard')} />
                        <PageHeaderGlassButton icon={MoreHorizontal} aria-label="More actions" title="More actions" onClick={outside('more actions menu')} />
                        <PageHeaderPrimaryButton onClick={outside('Add / log menu')}>
                            Add / log <ChevronDown className="size-4" />
                        </PageHeaderPrimaryButton>
                    </>
                }
                meters={
                    <>
                        <div className="flex min-h-[80px] w-full flex-wrap items-stretch gap-2 empty:hidden">
                            <PageHeaderMeterBlock label="Next shift" onClick={outside('calendar tab')} ariaLabel="View the next shift">
                                <PageHeaderMeterBig>3:00 pm</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>Jordan Tipene</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock label="Needs attention" onClick={outside('attention list')} ariaLabel="View what needs attention">
                                <PageHeaderMeterBig>1</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>1 action</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock label="Safety" tone={allergy.status === 'recorded' ? 'critical' : allergy.status === 'nkda' ? 'brand' : 'warning'} onClick={() => s.set({ tab: 'medical', dlg: undefined })} ariaLabel="View allergies on the Medical tab">
                                <PageHeaderMeterBig>{allergyShort(allergy.status, allergy.entries.length)}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>{allergy.status === 'recorded' ? `${allergy.entries[0].allergen}${allergy.entries[0].severity ? ` (${allergy.entries[0].severity.toLowerCase()})` : ''}` : allergy.status === 'unavailable' ? 'Allergies couldn’t load' : allergy.reviewed ? 'Allergies reviewed' : 'Allergies not reviewed'}</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock label="Care plan goals" onClick={outside('care plan tab')} ariaLabel="View care plan goals">
                                <PageHeaderMeterBig>3</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>active goals</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock label="Daily notes" onClick={outside('daily notes tab')} ariaLabel="View daily notes">
                                <PageHeaderMeterBig>4</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>written this past week</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </div>
                        <div className="flex min-h-[80px] w-full flex-wrap items-stretch gap-2 empty:hidden">
                            <PageHeaderMeterBlock label="Meals today" onClick={outside('meals tab')} ariaLabel="View meals today">
                                <PageHeaderMeterBig>1/3</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>breakfast logged</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock label="Mood" onClick={outside('mood tab')} ariaLabel="View mood">
                                <PageHeaderMeterBig>Settled</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>3 ratings this week</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock label="Sleep" onClick={outside('sleep tab')} ariaLabel="View sleep">
                                <PageHeaderMeterBig>7 h</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>last night</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock label="Medications" tone={alertsPending ? 'warning' : 'brand'} onClick={() => s.set({ tab: 'mar', dlg: undefined })} ariaLabel="View the MAR">
                                <PageHeaderMeterBig>{s.route.state === 'empty' ? 0 : shownMeds}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>{alertsPending ? `active meds · ${alertsPending} alert${alertsPending > 1 ? 's' : ''} pending` : 'active meds · no pending alerts'}</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </div>
                    </>
                }
                rail={<PageHeaderRail items={GROUPS.map((g) => ({ key: g.key, label: g.label, icon: g.icon }))} value="health" onSelect={(k) => k !== 'health' && outside(`${GROUPS.find((g) => g.key === k)!.label} group`)()} onFind={outside('search palette')} ariaLabel="Client Profile groups" />}
            />
            <TierTwoTabs
                tabs={HEALTH_TABS}
                activeTab={tab}
                onTab={(k) => (['mar', 'medical', 'health_monitoring'].includes(k) ? s.set({ tab: k, dlg: undefined }) : outside(`${HEALTH_TABS.find((t) => t.key === k)!.label} tab`)())}
                testIdPrefix="p02-client"
                ariaLabel="Health & safety tabs"
                renderLink={(t, className, inner, a11y) => (
                    <a
                        key={t.key}
                        href={hrefFor(s.route.path, { tab: t.key }, s.route)}
                        className={className}
                        {...a11y}
                        onClick={(e) => {
                            if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
                            e.preventDefault();
                            if (['mar', 'medical', 'health_monitoring'].includes(t.key)) s.set({ tab: t.key, dlg: undefined });
                            else outside(`${t.label} tab`)();
                        }}
                    >
                        {inner}
                    </a>
                )}
            />
            {tab === 'medical' ? <MedicalAllergyCard pid={pid} /> : tab === 'health_monitoring' ? <Boundary what="Health monitoring" /> : <MarTab pid={pid} />}
        </Shell>
    );
}

function Boundary({ what }: { what: string }) {
    return (
        <Card className="gap-2 p-6">
            <h2 className="text-section-title">{what} is the client profile’s and unchanged</h2>
            <p className="text-subtle">Outside P02. The app keeps today’s tab.</p>
        </Card>
    );
}

/* ───────────── the MAR tab — summary and launch point ───────────── */
function MarTab({ pid }: { pid: PersonId }) {
    const s = useP02();
    const dlg = useDlg();
    const open01 = useOpen();
    const p = PEOPLE[pid];
    const f = FACTS[pid];
    const today = useToday(pid).filter((c) => s.cdView || !c.med.cd);
    const admins = useAdmins(pid).filter((a) => s.cdView || !MED_CD.has(a.med));
    const meds = medsOf(pid).filter((m) => m.status !== 'stopped');
    const hidden = s.cdView ? 0 : meds.filter((m) => m.cd).length;
    const shown = meds.filter((m) => s.cdView || !m.cd);
    const open = today.filter((c) => c.state === 'due' || c.state === 'late');
    const last = admins.find((a) => a.outcome !== 'selfmanaged');
    const alerts = s.alerts.filter((a) => a.pid === pid && !a.resolved && (s.cdView || !a.cd));
    const record = `/emar/mar`;
    const recordParams = (extra: Record<string, string | undefined> = {}) => ({ client_id: String(f.clientId), tab: undefined, ...extra });
    const recorder = !!recorderIn01(s.route.persona);
    const state = s.route.state;
    if (state === 'loading')
        return (
            <div className="grid gap-5 lg:grid-cols-2">
                <SkeletonCard />
                <SkeletonCard />
            </div>
        );
    if (state === 'unavailable')
        return (
            <Card className="p-2">
                <ErrorState title="We couldn’t load medication" message="The rest of the profile is fine. Try again, or open the medication record." onRetry={() => s.setViewer({ state: 'normal' })} />
            </Card>
        );
    return (
        <>
            <Card className="gap-4 p-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="min-w-0">
                        <h2 className="text-section-title">Medication</h2>
                        <p className="text-subtle">
                            {HOUSES[f.house]} · the full chart, history, INR and alerts are in the medication record
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" onClick={() => s.go(record, recordParams())}>
                            <ClipboardList className="size-4" /> Open medication record
                        </Button>
                        {recorder && state !== 'empty' ? (
                            <Button onClick={() => open01(`dose-pick:${pid}`)}>
                                <Pill className="size-4" /> Record dose
                            </Button>
                        ) : null}
                    </div>
                </div>
                <AllergySummary pid={pid} />
                {alerts.length ? (
                    <div className="flex flex-wrap items-center gap-2 rounded-lg border border-status-warning/30 bg-status-warning-bg px-3 py-2 text-sm">
                        <BellRing className="size-4 text-status-warning" aria-hidden="true" />
                        <span className="font-semibold text-status-warning">Chart alerts:</span>
                        <span className="min-w-0 flex-1">{alerts.map((a) => a.title).join(' · ')}</span>
                        <Button size="sm" variant="link" className="h-auto p-0" onClick={() => s.go(record, recordParams({ tab: 'allergies', view: 'alerts' }))}>
                            View alerts <ArrowUpRight className="size-4" aria-hidden="true" />
                        </Button>
                    </div>
                ) : null}
                <p className="text-sm">
                    <span className="text-muted-foreground">Last dose · </span>
                    {last ? (
                        <>
                            {MEDS_NAME(last.med)} · {last.dayLabel === 'Mon 28' ? 'today' : last.dayLabel} · <DoseBadge state={last.outcome} size="sm" /> {last.at} · {last.by}
                        </>
                    ) : (
                        'None recorded in the last 7 days'
                    )}
                </p>
            </Card>
            {state === 'empty' ? (
                <Card className="p-2">
                    <EmptyState icon={Pill} title={`No medicines on ${p.pref}’s chart`} description="New orders are added and checked in Orders & reviews." />
                </Card>
            ) : (
                <div className="grid gap-5 xl:grid-cols-2">
                    <section aria-label="Due or late now" className="flex flex-col gap-2.5">
                        <ListCaption title="Due or late now" caption={`${open.length} shown · times in NZDT`} />
                        <Card className="gap-0 p-0">
                            {open.length ? (
                                <ul className="divide-y">
                                    {open.map((c) => (
                                        <li key={c.key} className="flex flex-wrap items-center justify-between gap-3 px-4 py-2.5">
                                            <span className="min-w-0 text-sm">
                                                <strong>{c.slot}</strong> · {c.med.name} {c.med.strength}
                                                <span className="block text-caption">{c.line}</span>
                                            </span>
                                            <span className="flex items-center gap-2">
                                                <DoseBadge state={c.state} size="sm" />
                                                {recorder && c.dose ? (
                                                    <Button size="sm" className="frontline-tap" onClick={() => open01(`record:${c.dose!.id}`, { from: 'client-profile' })}>
                                                        Record
                                                    </Button>
                                                ) : null}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="px-4 py-3 text-subtle">Nothing due or late right now.</p>
                            )}
                        </Card>
                    </section>
                    <section aria-label="Active medicines" className="flex flex-col gap-2.5">
                        <ListCaption
                            title="Active medicines"
                            caption={
                                <>
                                    {shown.length} of {shown.length} shown{hidden ? ' · ' : ''}
                                    <ConcealedCaption n={hidden} listed={false} />
                                </>
                            }
                        />
                        <Card className="gap-0 p-0">
                            <ul className="divide-y">
                                {shown.map((m) => (
                                    <li key={m.key}>
                                        <Button variant="ghost" className="h-auto w-full justify-between gap-3 rounded-none px-4 py-2.5 text-left" onClick={() => s.go(record, recordParams({ tab: 'medicines', dlg: `med:${m.key}` }))}>
                                            <span className="min-w-0">
                                                <span className="block text-sm font-medium">
                                                    {m.name} {m.strength}
                                                </span>
                                                <span className="block text-caption">{m.kind === 'prn' ? 'As needed' : m.when}</span>
                                            </span>
                                            <SupportChip support={m.support} />
                                        </Button>
                                    </li>
                                ))}
                                {hidden ? (
                                    <li className="px-4 py-2.5 text-caption">
                                        {CONCEALED.subline}. {CONCEALED.ask}
                                    </li>
                                ) : null}
                            </ul>
                        </Card>
                    </section>
                </div>
            )}
            <DesignNote title="Design note — the MAR tab contract">
                <p>
                    A summary and launch point only. It reads the same payload as the medication record (allergy status from both of today’s lists, today’s doses, alerts, last dose), so the two can’t disagree; every medicine opens the record. It keeps: allergies, chart alerts, last dose, due now, active medicines, Record dose (P01’s dialog) and “Open medication record”. It drops today’s stock card (Stock &amp; pharmacy), the four number tiles (they repeated the header’s Medications meter), the per-medicine Sign and Give PRN buttons (Record dose covers them) and the row of four link buttons. Nothing is edited here.
                </p>
            </DesignNote>
        </>
    );
}
const MED_CD = new Set(['methylphenidate', 'clonazepam', 'lorazepam']);
const MEDS_NAME = (k: string) => medsOf('aroha').concat(medsOf('tama'), medsOf('mele'), medsOf('grace'), medsOf('sam'), medsOf('ben')).find((m) => m.key === k)?.name ?? k;

/* ───────────── Medical tab — the health profile's allergy card (the one place) ───────────── */
function MedicalAllergyCard({ pid }: { pid: PersonId }) {
    const s = useP02();
    const dlg = useDlg();
    const a = useAllergy(pid);
    const lead = s.can('clients.update') && s.can('orders.manage');
    return (
        <>
            <Card className="gap-4 p-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <span className="grid size-9 place-items-center rounded-[10px] bg-primary/10 text-primary">
                            <FileHeart className="size-4" aria-hidden="true" />
                        </span>
                        <div>
                            <p className="text-[10px] font-semibold tracking-[0.08em] text-muted-foreground uppercase">Health profile</p>
                            <h2 className="text-section-title">Allergies</h2>
                        </div>
                    </div>
                    {lead ? (
                        <Button onClick={() => dlg.open('allergy-review')}>
                            <ShieldCheck className="size-4" /> Review allergies
                        </Button>
                    ) : (
                        <span className="text-caption">Only house leads and clinical leads review allergies — tell Jordan Tipene if something is wrong.</span>
                    )}
                </div>
                <AllergySummary pid={pid} />
                {a.entries.length ? (
                    <ul className="divide-y rounded-lg border text-sm">
                        {a.entries.map((e) => (
                            <li key={e.allergen} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                <span>
                                    <strong>{e.allergen}</strong> · {e.reaction ?? 'reaction not recorded'}
                                </span>
                                {e.severity ? <StatusBadge variant={e.severity === 'Severe' ? 'critical' : e.severity === 'Moderate' ? 'warning' : 'neutral'} size="sm" className="rounded-[8px]">{e.severity}</StatusBadge> : <StatusBadge variant="warning" size="sm" className="rounded-[8px]">Severity not recorded</StatusBadge>}
                            </li>
                        ))}
                    </ul>
                ) : null}
            </Card>
            <Card className="gap-2 p-5">
                <p className="flex items-center gap-2 text-sm font-semibold">
                    <HeartPulse className="size-4" aria-hidden="true" /> Conditions, GP, disabilities and the rest of the health profile
                </p>
                <p className="text-subtle">The client profile’s Medical tab is unchanged apart from this allergy card — outside P02.</p>
            </Card>
            <DesignNote title="Design note — the one place allergies are edited (Stephan, 30 Sep)">
                <p>This card replaces today’s free-text allergy box. It adds severity and reaction (absorbing the medication allergy list that only the mobile API writes), an explicit “No known allergies”, and a lead’s review with how it was checked. Every medication screen reads it; none edits it. The review interval is left for the clinical lead (Not configured).</p>
            </DesignNote>
        </>
    );
}
