/* Client profile › Relationships & governance › Actions & Reviews (NF-05,
 * the P11 build plan note): the person’s medication reviews, their open
 * changes and what to watch appear here, from the same records as All Tasks.
 * The page top is P02 v1’s client profile frame (copied from origin/main
 * operations/clients/show.tsx); the tab body is the REAL ActionsReviewsTab
 * (pages/operations/clients/tabs/actions-reviews.tsx), unchanged, fed with
 * synthetic items — P05 adds medication item types to its aggregator. */
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
import { ActionsReviewsTab, type ClientActionReview } from '@/pages/operations/clients/tabs/actions-reviews';
import { Card } from '@/components/ui/card';
import { ErrorState } from '@/components/ui/error-state';
import { CalendarDays, ChevronDown, ClipboardList, Home, ListTodo, MessageCircle, MoreHorizontal, Pencil, Send, Shield, ShieldCheck, Target, Users } from 'lucide-react';
import { HOUSES, PEOPLE, PERSONAS, RECORD_REF, orderOf, personByClientId, type PersonId } from '../data';
import { changeState, changesIn, daysUntil, hiddenFor, lowerFirst, nextFor, reviewState } from '../model';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
import { DesignNote, PersonMark } from '../ui';

const GROUPS = [
    { key: 'snapshot', label: 'Snapshot', icon: Home },
    { key: 'daily', label: 'Daily care', icon: CalendarDays },
    { key: 'plans', label: 'Plans & goals', icon: Target },
    { key: 'health', label: 'Health & safety', icon: ShieldCheck },
    { key: 'day', label: 'Day-to-day', icon: ClipboardList },
    { key: 'rel', label: 'Relationships & governance', icon: Users },
];
/** origin/main CLIENT_TAB_GROUPS › governance, with show.tsx’s labels. */
const GOVERNANCE_TABS = [
    { key: 'family_tree', label: 'Family Tree', icon: Users },
    { key: 'consents', label: 'Consents', icon: Shield },
    { key: 'consent-requests', label: 'Consent Requests', icon: Send },
    { key: 'portal', label: 'Family Portal', icon: Users },
    { key: 'actions_reviews', label: 'Actions & Reviews', icon: ListTodo },
    { key: 'audit_history', label: 'Audit History', icon: Shield },
    { key: 'privacy', label: 'Privacy', icon: Shield },
];

export function ActionsPage() {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const pid = personByClientId(Number(s.route.q.get('client_id')));
    if (!pid || !me.houses.includes(PEOPLE[pid].house))
        return (
            <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Clients', href: '/operations/clients' }, { title: 'Client' }]}>
                <Card className="p-8 text-center">
                    <p className="text-section-title">We can’t show this record</p>
                    <p className="text-subtle mt-1">It may not exist, or it may not be available to you.</p>
                </Card>
            </Shell>
        );
    return <Profile pid={pid} />;
}

/** The person’s medication items for the aggregator — the same records All Tasks reads (NF-05). */
export function medicationItems(rt: ReturnType<typeof useStore>['rt'], persona: ReturnType<typeof useStore>['route']['persona'], pid: PersonId): ClientActionReview[] {
    const out: ClientActionReview[] = [];
    const client = PEOPLE[pid].clientId;
    const rec = (open: string) => hrefFor('/emar/mar', { client_id: String(client), tab: 'clinical', view: 'reviews', open });
    const next = nextFor(rt, pid);
    if (next) {
        const overdue = daysUntil(next.dueIso) < 0;
        out.push({
            type: overdue ? 'overdue_medication_review' : 'medication_review',
            severity: overdue ? 'critical' : reviewState(next).variant === 'warning' ? 'warning' : 'info',
            due_at: next.dueIso,
            summary: `${next.kind === 'regular' ? 'Regular' : 'Triggered'} medication review ${next.id} — ${reviewState(next).label === 'Booked' ? `due ${next.due}` : lowerFirst(reviewState(next).label)}${next.booked ? ` · with ${next.booked.with.name}` : ' · no appointment yet'} · owner ${next.owner}`,
            deep_link: rec(`review:${next.id}`),
        });
    }
    changesIn(rt, persona)
        .filter((c) => c.review.pid === pid)
        .forEach((c) => {
            const st = changeState(c.item);
            const name = hiddenFor(persona, c.item.orderId) ? 'A controlled medicine' : orderOf(c.item.orderId).med;
            const watch = st.step === 'watching';
            out.push({
                type: watch ? 'open_follow_up' : 'medication_change',
                severity: st.variant === 'warning' ? 'warning' : 'info',
                due_at: watch ? null : '2026-09-28',
                summary: watch ? `Watch after ${c.review.id}: ${hiddenFor(persona, c.item.orderId) ? 'details need controlled-medicine access' : c.item.watch!.what} — until ${c.item.watch!.until}` : `${name}: ${lowerFirst(st.label)} (${c.review.id})`,
                deep_link: rec(`change:${c.review.id}:${c.item.orderId}`),
            });
        });
    return out;
}

function Profile({ pid }: { pid: PersonId }) {
    const s = useStore();
    const p = PEOPLE[pid];
    const ref = RECORD_REF[pid];
    const outside = (what: string) => () => s.toast('info', `The client profile’s ${what} — outside this preview.`);
    const failed = s.route.scenario === 'unavailable';
    const loading = s.route.scenario === 'loading';
    const empty = s.route.scenario === 'empty';
    // Other aggregator sources (P02’s frame; not P05’s): a care plan review and a consent request.
    const others: ClientActionReview[] = [
        { type: 'care_plan_review_due', severity: 'warning', due_at: '2026-10-12', summary: 'Care & support plan review is due', deep_link: '#/outside/care-plan' },
        { type: 'pending_consent_request', severity: 'info', due_at: null, summary: 'Photo consent request — waiting for whānau', deep_link: '#/outside/consents' },
    ];
    const items = empty ? [] : [...medicationItems(s.rt, s.route.persona, pid), ...others];
    const crit = items.filter((i) => i.severity === 'critical').length;
    const warn = items.filter((i) => i.severity === 'warning').length;
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
                titleChip={<PageHeaderStatusChip variant="success">Active</PageHeaderStatusChip>}
                subline={
                    <>
                        <span className="block">
                            “{p.pref}” · {p.age} years · NHI {p.nhi} (test)
                        </span>
                        <span className="block">
                            {HOUSES[p.house]} · Supported living · key worker {p.keyWorker}
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
                            <PageHeaderMeterBlock label="Needs attention" tone={crit ? 'critical' : warn ? 'warning' : 'brand'} onClick={() => s.set({ tab: 'actions_reviews' })} ariaLabel="View what needs attention">
                                <PageHeaderMeterBig>{loading || failed ? '—' : crit + warn}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>{loading || failed ? '—' : `${crit + warn} ${crit + warn === 1 ? 'action' : 'actions'}`}</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock label="Safety" tone={ref?.allergyTone ?? 'warning'} onClick={outside('Medical tab')} ariaLabel="View allergies on the Medical tab">
                                <PageHeaderMeterBig>{ref?.allergy ?? '—'}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>{ref?.allergyCap ?? 'Not reviewed'}</PageHeaderMeterCaption>
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
                    </>
                }
                rail={<PageHeaderRail items={GROUPS.map((g) => ({ key: g.key, label: g.label, icon: g.icon }))} value="rel" onSelect={(k) => k !== 'rel' && outside(`${GROUPS.find((g) => g.key === k)!.label} group`)()} onFind={outside('search palette')} ariaLabel="Client Profile groups" />}
            />
            <TierTwoTabs
                tabs={GOVERNANCE_TABS}
                activeTab="actions_reviews"
                onTab={(k) => k !== 'actions_reviews' && outside(`${GOVERNANCE_TABS.find((t) => t.key === k)!.label} tab`)()}
                testIdPrefix="p05-client"
                ariaLabel="Relationships & governance tabs"
                renderLink={(t, className, inner, a11y) => (
                    <a
                        key={t.key}
                        href={hrefFor(s.route.path, { client_id: String(p.clientId), tab: t.key }, s.route)}
                        className={className}
                        {...a11y}
                        onClick={(e) => {
                            if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
                            e.preventDefault();
                            if (t.key !== 'actions_reviews') outside(`${t.label} tab`)();
                        }}
                    >
                        {inner}
                    </a>
                )}
            />
            {failed ? (
                <Card className="p-2">
                    <ErrorState title="We couldn’t load this person’s actions" message="Nothing is shown rather than an incomplete list. Try again." onRetry={() => s.setViewer({ scenario: 'normal' })} />
                </Card>
            ) : (
                <ActionsReviewsTab items={items} summary={{ open: items.length, loaded: items.length, has_more: false, critical: crit, warning: warn }} isLoading={loading} />
            )}
            <DesignNote title="Design note — medication work in Actions & Reviews (NF-05, P11 build plan)">
                <p>
                    The real tab, unchanged, with P05’s items: the next review, each open change, and what to watch — the same records All Tasks shows the review’s owner, and each opens the exact review or change. A controlled medicine reads “A controlled medicine” without controlled-medicine access. At build the aggregator gains these item types, with labels “Medication review” and “Medication change”.
                </p>
            </DesignNote>
        </Shell>
    );
}
