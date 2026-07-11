import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { EntityFilter, ShiftContextMenu, TabStrip, type RosterTabItem, type ShiftCtxItem, type ShiftCtxState } from '@/components/rostering';
import { BspDetailDialog } from '@/components/health-safety/bsp-detail-dialog';
import { BspWizard } from '@/components/health-safety/bsp-wizard';
import { RestraintEventDetailDialog, type RestraintEventActionKey, type RestraintSectionKey } from '@/components/health-safety/restraint-event-detail-dialog';
import { RestraintEventWizard, type PlanPickerOption, type Prescope } from '@/components/health-safety/restraint-event-wizard';
import AppLayout from '@/layouts/app-layout';
import { HeroCluster, HeroClusterTile, HeroMedallion, HeroSegmented, HeroShell, HeroStatusPill, fmt } from '@/pages/health-safety/components/hs-hero-kit';
import { FlagBadge, RegisterTableHeader, entityTone, initials } from '@/pages/health-safety/components/register-row-kit';
import { WorkflowRibbon } from '@/pages/health-safety/components/workflow-ribbon';
import {
    CHIP,
    DOT,
    ICON_TEXT,
    PERIOD_ITEMS,
    REVIEW_STATE_META,
    RESTRAINT_TYPE_OPTIONS,
    durationLabel,
    planStatusMeta,
    severityMeta,
    titleCase,
    typeMeta,
    whenLabel,
    type ChipTone,
    type ClientOption,
    type EventDetail,
    type EventRow,
    type IncidentOption,
    type PlanDetail,
    type PlanRow,
    type RestraintFilters,
    type RestraintHero,
    type RestraintTabCounts,
    type SiteOption,
    type StaffOption,
} from '@/pages/health-safety/restraints/shared';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Archive,
    BarChart3,
    BookOpen,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    Eye,
    FileEdit,
    FileText,
    HeartPulse,
    LayoutDashboard,
    LayoutList,
    MousePointer2,
    Plus,
    ShieldAlert,
    ShieldCheck,
    TrendingDown,
    Users,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
import { useState, type MouseEvent as ReactMouseEvent, type ReactNode } from 'react';

type Paginated<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; last_page: number };

type Props = {
    lens: 'events' | 'plans';
    tab: string;
    events: Paginated<EventRow>;
    plans: Paginated<PlanRow>;
    tabCounts: RestraintTabCounts;
    hero: RestraintHero;
    filters: RestraintFilters;
    clients: ClientOption[];
    sites: SiteOption[];
    staff: StaffOption[];
    incidents: IncidentOption[];
    plansForPicker: PlanPickerOption[];
    detail: EventDetail | PlanDetail | null;
    can: { create: boolean; review: boolean; manage: boolean };
};

const BADGE_TONE: Record<ChipTone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    info: 'bg-status-info-bg text-status-info',
    neutral: 'border border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground',
};

export default function RestraintsIndex({ lens, tab, events, plans, tabCounts, hero, filters, clients, sites, staff, incidents, plansForPicker, detail, can }: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [eventWizard, setEventWizard] = useState(false);
    const [eventPrescope, setEventPrescope] = useState<Prescope | undefined>(undefined);
    const [planWizard, setPlanWizard] = useState(false);
    const [pendingSection, setPendingSection] = useState<RestraintSectionKey>('overview');
    const [pendingAction, setPendingAction] = useState<RestraintEventActionKey | null>(null);
    const [planSection, setPlanSection] = useState<'overview' | 'content' | 'lifecycle' | 'reviews'>('overview');
    const [planAction, setPlanAction] = useState<'review' | null>(null);

    const go = (next: Partial<RestraintFilters & { lens: string; tab: string }>) =>
        router.get('/health-safety/restraints', { ...filters, lens, tab, ...next }, { preserveState: true, preserveScroll: true, replace: true });

    const setLens = (next: 'events' | 'plans') => router.get('/health-safety/restraints', { ...filters, lens: next, tab: 'all' }, { preserveScroll: true });

    const setTab = (id: string) => router.get('/health-safety/restraints', { ...filters, lens, tab: id }, { preserveScroll: true });

    const openEvent = (id: number, opts?: { section?: RestraintSectionKey; action?: RestraintEventActionKey }) => {
        setPendingSection(opts?.section ?? 'overview');
        setPendingAction(opts?.action ?? null);
        router.get('/health-safety/restraints', { ...filters, lens, tab, event: id }, { preserveState: true, preserveScroll: true, only: ['detail'] });
    };
    const openPlan = (id: number, opts?: { section?: 'overview' | 'content' | 'lifecycle' | 'reviews'; action?: 'review' | null }) => {
        setPlanSection(opts?.section ?? 'overview');
        setPlanAction(opts?.action ?? null);
        router.get('/health-safety/restraints', { ...filters, lens, tab, plan: id }, { preserveState: true, preserveScroll: true, only: ['detail'] });
    };
    const closeDetail = () => router.get('/health-safety/restraints', { ...filters, lens, tab }, { preserveState: true, preserveScroll: true, only: ['detail'] });

    const openEventWizard = (prescope?: Prescope) => {
        setEventPrescope(prescope);
        setEventWizard(true);
    };

    const clearFilters = () => router.get('/health-safety/restraints', { lens, tab }, { preserveState: true, preserveScroll: true, replace: true });
    const hasFilters = !!(filters.q || filters.client_id || filters.site_id || filters.restraint_type || filters.severity || (filters.period && filters.period !== '30d') || filters.from);

    const exportHref = `/health-safety/restraints/export?${new URLSearchParams({
        lens,
        ...(filters.client_id ? { client_id: String(filters.client_id) } : {}),
        ...(filters.site_id ? { site_id: String(filters.site_id) } : {}),
        ...(filters.restraint_type ? { restraint_type: filters.restraint_type } : {}),
    }).toString()}`;

    const EVENT_TABS: RosterTabItem[] = [
        { id: 'all', label: 'All', icon: LayoutList, tone: 'primary', badge: tabCounts.events.all || undefined },
        { id: 'unreviewed', label: 'Unreviewed', icon: ClipboardCheck, tone: 'warning', badge: tabCounts.events.unreviewed || undefined },
        { id: 'out_of_plan', label: 'Out of plan', icon: AlertTriangle, tone: 'critical', badge: tabCounts.events.out_of_plan || undefined },
        { id: 'injury', label: 'Injury', icon: HeartPulse, tone: 'critical', badge: tabCounts.events.injury || undefined },
        { id: 'critical', label: 'Critical', icon: ShieldAlert, tone: 'critical', badge: tabCounts.events.critical || undefined },
        { id: '30d', label: '30 days', icon: CalendarClock, tone: 'info', badge: tabCounts.events['30d'] || undefined },
    ];
    const PLAN_TABS: RosterTabItem[] = [
        { id: 'all', label: 'All', icon: LayoutList, tone: 'primary', badge: tabCounts.plans.all || undefined },
        { id: 'active', label: 'Active', icon: CheckCircle2, tone: 'success', badge: tabCounts.plans.active || undefined },
        { id: 'draft', label: 'Draft', icon: FileEdit, tone: 'violet', badge: tabCounts.plans.draft || undefined },
        { id: 'review_due', label: 'Review due', icon: CalendarClock, tone: 'warning', badge: tabCounts.plans.review_due || undefined },
        { id: 'under_review', label: 'Under review', icon: Eye, tone: 'warning', badge: tabCounts.plans.under_review || undefined },
        { id: 'archived', label: 'Archived', icon: Archive, tone: 'info', badge: tabCounts.plans.archived || undefined },
    ];

    /* ---- context menus ---- */
    const openEventRowCtx = (e: ReactMouseEvent, row: EventRow) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [{ icon: <Eye className="h-3.5 w-3.5" />, label: 'View event', sub: row.reference, tone: 'primary', onClick: () => openEvent(row.id) }];
        if (can.review && row.flags.unreviewed) items.push({ icon: <ClipboardCheck className="h-3.5 w-3.5" />, label: 'Review event', sub: 'confirm & close out', onClick: () => openEvent(row.id, { section: 'review', action: 'review' }) });
        if (row.behaviour_support_plan_id) items.push({ icon: <BookOpen className="h-3.5 w-3.5" />, label: 'Open linked plan', onClick: () => openPlan(row.behaviour_support_plan_id!) });
        if (row.related_incident_id) items.push({ icon: <ClipboardList className="h-3.5 w-3.5" />, label: 'Open linked incident', onClick: () => router.visit(`/incidents?incident=${row.related_incident_id}`) });
        if (row.client) items.push({ icon: <Users className="h-3.5 w-3.5" />, label: 'Open client profile', onClick: () => router.visit(`/operations/clients/${row.client!.id}`) });
        setCtx({ x: e.clientX, y: e.clientY, tag: severityMeta(row.severity).label.toUpperCase(), meta: `${row.reference} · ${typeMeta(row.restraint_type).label}`, items });
    };

    const openPlanRowCtx = (e: ReactMouseEvent, row: PlanRow) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [{ icon: <Eye className="h-3.5 w-3.5" />, label: 'View plan', sub: row.reference, tone: 'primary', onClick: () => openPlan(row.id) }];
        if (can.create && row.client) items.push({ icon: <Plus className="h-3.5 w-3.5" />, label: 'Record event under this plan', onClick: () => openEventWizard({ client_id: row.client!.id, client_name: row.client!.name, behaviour_support_plan_id: row.id }) });
        if (can.manage && row.status === 'draft') items.push({ icon: <CheckCircle2 className="h-3.5 w-3.5" />, label: 'Activate plan', sub: 'draft → active', onClick: () => router.post(`/health-safety/restraints/plans/${row.id}/activate`, {}, { preserveScroll: true }) });
        if (can.manage && row.status === 'active') items.push({ icon: <ClipboardCheck className="h-3.5 w-3.5" />, label: 'Submit for review', onClick: () => router.post(`/health-safety/restraints/plans/${row.id}/submit-review`, {}, { preserveScroll: true }) });
        if (can.review) items.push({ icon: <ClipboardCheck className="h-3.5 w-3.5" />, label: 'Record review', onClick: () => openPlan(row.id, { section: 'reviews', action: 'review' }) });
        if (can.manage && row.status !== 'archived') items.push({ icon: <Archive className="h-3.5 w-3.5" />, label: 'Archive plan', tone: 'critical', onClick: () => router.post(`/health-safety/restraints/plans/${row.id}/archive`, {}, { preserveScroll: true }) });
        if (row.client) {
            items.push({ sep: true });
            items.push({ icon: <Users className="h-3.5 w-3.5" />, label: 'Open client profile', onClick: () => router.visit(`/operations/clients/${row.client!.id}`) });
        }
        setCtx({ x: e.clientX, y: e.clientY, tag: planStatusMeta(row.status).label.toUpperCase(), meta: row.reference, items });
    };

    const openHeroCtx = (e: ReactMouseEvent) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [];
        if (can.create) {
            items.push({ icon: <Plus className="h-3.5 w-3.5" />, label: 'Record restraint event', tone: 'primary', onClick: () => openEventWizard() });
            items.push({ icon: <BookOpen className="h-3.5 w-3.5" />, label: 'Create behaviour support plan', onClick: () => setPlanWizard(true) });
        }
        items.push({
            icon: <ClipboardCheck className="h-3.5 w-3.5" />,
            label: 'Jump to unreviewed',
            onClick: () => router.get('/health-safety/restraints', { ...filters, lens: 'events', tab: 'unreviewed' }, { preserveScroll: true }),
        });
        items.push({ sep: true });
        items.push({ icon: <BarChart3 className="h-3.5 w-3.5" />, label: 'Restraint analytics', onClick: () => router.visit('/health-safety/analytics') });
        setCtx({ x: e.clientX, y: e.clientY, tag: 'RESTRAINTS', meta: 'Quick actions', items });
    };

    const live = hero.live;
    const at = hero.attention;
    const b = hero.badges;
    const reductionTone: ChipTone = b.reduction_trend_pct < 0 ? 'success' : b.reduction_trend_pct > 0 ? 'warning' : 'neutral';
    const reductionLabel = b.reduction_trend_pct === 0 ? 'no change' : `${b.reduction_trend_pct < 0 ? '↓' : '↑'} ${Math.abs(b.reduction_trend_pct)}%`;

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Restraints & Behaviour Support', href: '/health-safety/restraints' }]}>
            <Head title="Restraints & Behaviour Support" />

            <div className="flex flex-col gap-6 p-6">
                {/* ---- Hero ---- */}
                <div onContextMenu={openHeroCtx}>
                    <HeroShell
                        footer={
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                                <HeroSegmented label="Period" variant="pill" ariaLabel="Date range" items={PERIOD_ITEMS} value={filters.period || '30d'} onChange={(key) => go({ period: key, from: null })} />
                                {clients?.length ? <EntityFilter label="Client" allLabel="All clients" items={clients} value={filters.client_id} onChange={(id) => go({ client_id: id })} onDark /> : null}
                                <label className="inline-flex items-center gap-1.5">
                                    <span className="text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">Type</span>
                                    <select
                                        value={filters.restraint_type ?? ''}
                                        onChange={(e) => go({ restraint_type: e.target.value || null })}
                                        aria-label="Restraint type filter"
                                        className="rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-2.5 py-1.5 text-xs font-medium text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none [&>option]:text-foreground"
                                    >
                                        <option value="">All types</option>
                                        {RESTRAINT_TYPE_OPTIONS.map((o) => (
                                            <option key={o.value} value={o.value}>
                                                {o.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <div className="relative ml-auto">
                                    <ShieldAlert className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                    <input
                                        type="search"
                                        placeholder="Search restraints…"
                                        defaultValue={filters.q ?? ''}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') go({ q: (e.target as HTMLInputElement).value || null });
                                        }}
                                        className="w-48 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pr-2.5 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                                    />
                                </div>
                                {hasFilters ? (
                                    // eslint-disable-next-line no-restricted-syntax -- onDark clear affordance on the hero footer
                                    <button type="button" onClick={clearFilters} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-primary-foreground/70 transition-colors hover:text-primary-foreground">
                                        <XCircle className="h-3 w-3" /> Clear
                                    </button>
                                ) : null}
                            </div>
                        }
                    >
                        <WorkflowRibbon current="report" />

                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex items-start gap-4">
                                <HeroMedallion icon={ShieldAlert} />
                                <div className="flex flex-col gap-1.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <HeroStatusPill>Restraint register · synced</HeroStatusPill>
                                        <span className="inline-flex items-center gap-1.5 rounded-full bg-primary-foreground/15 px-2.5 py-1 text-[11px] font-semibold tracking-[0.04em] text-primary-foreground/85 uppercase">
                                            <TrendingDown className="h-3.5 w-3.5" /> Restrictive-practice reduction
                                        </span>
                                    </div>
                                    <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">Restraints &amp; Behaviour Support</h1>
                                    <p className="max-w-xl text-sm text-primary-foreground/70">
                                        Every restrictive-practice episode and behaviour support plan in one register — recorded against Ngā Paerewa NZS 8134:2021, reviewed, and driven toward least-restrictive care.
                                    </p>
                                    <div className="mt-1.5 flex flex-wrap gap-2">
                                        <HeroBadge icon={ClipboardCheck} tone={b.unreviewed > 0 ? 'warning' : 'success'}>
                                            Unreviewed restraints · {b.unreviewed}
                                        </HeroBadge>
                                        <HeroBadge icon={TrendingDown} tone={reductionTone}>
                                            Restrictive-practice reduction · {reductionLabel}
                                        </HeroBadge>
                                        <HeroBadge icon={ShieldCheck} tone={b.nga_paerewa_certified ? 'success' : 'warning'}>
                                            Ngā Paerewa NZS 8134:2021 · {b.nga_paerewa_certified ? 'Certified' : 'At risk'}
                                        </HeroBadge>
                                        <HeroBadge icon={CalendarClock} tone={b.plans_overdue > 0 ? 'critical' : 'success'}>
                                            {b.plans_overdue > 0 ? `Plans overdue review · ${b.plans_overdue}` : 'Plan reviews current'}
                                        </HeroBadge>
                                    </div>
                                </div>
                            </div>

                            <Popover>
                                <PopoverTrigger asChild>
                                    <Button size="sm" className="border border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20">
                                        <FileText className="mr-1.5 h-4 w-4" /> Export / Board reports
                                        <span aria-hidden className="ml-1">▾</span>
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent align="end" className="w-60 p-1.5">
                                    <a href={exportHref} className="flex w-full items-center gap-2.5 rounded-md p-2.5 text-left text-sm font-medium transition-colors hover:bg-muted">
                                        <FileText className="h-4 w-4 shrink-0 text-primary" /> Export {lens === 'plans' ? 'plans' : 'events'} (CSV)
                                    </a>
                                    {[
                                        { label: 'Restraint analytics', href: '/health-safety/analytics', icon: BarChart3 },
                                        { label: 'H&S dashboard', href: '/health-safety', icon: LayoutDashboard },
                                    ].map((r) => (
                                        // eslint-disable-next-line no-restricted-syntax -- popover menu item (report link)
                                        <button key={r.href} type="button" onClick={() => router.visit(r.href)} className="flex w-full items-center gap-2.5 rounded-md p-2.5 text-left text-sm font-medium transition-colors hover:bg-muted">
                                            <r.icon className="h-4 w-4 shrink-0 text-primary" /> {r.label}
                                        </button>
                                    ))}
                                </PopoverContent>
                            </Popover>
                        </div>

                        {/* stat clusters */}
                        <div className="grid gap-3 lg:grid-cols-2">
                            <HeroCluster title="Live · this period" icon={Activity}>
                                <HeroClusterTile href="/health-safety/restraints?lens=events&tab=30d" label="Events · 30d" value={fmt(live.events_30d)} caption="recorded" tone="neutral" />
                                <HeroClusterTile href="/health-safety/restraints?lens=events&tab=out_of_plan" label="Out of plan" value={fmt(live.out_of_plan)} caption="deviations" tone="critical" />
                                <HeroClusterTile href="/health-safety/restraints?lens=events&tab=injury" label="Injuries" value={fmt(live.injuries)} caption="with harm" tone="critical" />
                                <HeroClusterTile href="/health-safety/restraints?lens=events&tab=critical" label="Critical" value={fmt(live.critical)} caption="severity" tone="critical" />
                            </HeroCluster>
                            <HeroCluster title="Needs attention" icon={AlertTriangle}>
                                <HeroClusterTile href="/health-safety/restraints?lens=events&tab=unreviewed" label="Unreviewed" value={fmt(at.unreviewed)} caption="need review" tone="warning" />
                                <HeroClusterTile href="/health-safety/restraints?lens=plans&tab=review_due" label="Review due" value={fmt(at.plans_review_due)} caption="plans" tone="warning" />
                                <HeroClusterTile href="/health-safety/restraints?lens=plans&tab=under_review" label="Under review" value={fmt(at.plans_under_review)} caption="plans" tone="neutral" />
                                <HeroClusterTile href="/health-safety/restraints?lens=plans&tab=all" label="No active BSP" value={fmt(at.clients_no_active_bsp)} caption="clients" tone="critical" />
                            </HeroCluster>
                        </div>
                    </HeroShell>
                </div>

                {/* ---- Lens toggle + tabs ---- */}
                <div className="flex flex-wrap items-center gap-3">
                    <div className="inline-flex gap-1 rounded-xl bg-muted p-1">
                        <LensButton active={lens === 'events'} icon={ShieldAlert} label="Events" onClick={() => setLens('events')} />
                        <LensButton active={lens === 'plans'} icon={BookOpen} label="Plans" onClick={() => setLens('plans')} />
                    </div>
                    <div className="min-w-0 flex-1">
                        <TabStrip value={tab} items={lens === 'events' ? EVENT_TABS : PLAN_TABS} onChange={setTab} ariaLabel={`${lens === 'events' ? 'Event' : 'Plan'} views`} />
                    </div>
                </div>

                {/* ---- Record CTA ---- */}
                {can.create ? (
                    <div>
                        {lens === 'events' ? (
                            <Button onClick={() => openEventWizard()}>
                                <Plus className="mr-1.5 h-4 w-4" /> Record restraint event
                            </Button>
                        ) : (
                            <Button onClick={() => setPlanWizard(true)}>
                                <Plus className="mr-1.5 h-4 w-4" /> Create behaviour support plan
                            </Button>
                        )}
                    </div>
                ) : null}

                {/* ---- Body ---- */}
                {lens === 'events' ? (
                    <section className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                        <RegisterTableHeader icon={ShieldAlert} title="All restraint events" subtitle="restrictive-practice episodes" hint="Right-click a row for the full workflow" hintIcon={MousePointer2} />
                        <EventTable rows={events.data} onRowCtx={openEventRowCtx} onOpen={openEvent} />
                    </section>
                ) : (
                    <PlanGrid rows={plans.data} onRowCtx={openPlanRowCtx} onOpen={openPlan} />
                )}

                {lens === 'events' && events.last_page > 1 ? <LaravelPagination links={events.links} /> : null}
                {lens === 'plans' && plans.last_page > 1 ? <LaravelPagination links={plans.links} /> : null}
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}

            {detail && detail.kind === 'event' ? (
                <RestraintEventDetailDialog key={`e-${detail.id}`} detail={detail} open onClose={closeDetail} incidents={incidents} initialSection={pendingSection} initialAction={pendingAction} onOpenPlan={(id) => openPlan(id)} />
            ) : null}
            {detail && detail.kind === 'plan' ? (
                <BspDetailDialog
                    key={`p-${detail.id}`}
                    detail={detail}
                    open
                    onClose={closeDetail}
                    initialSection={planSection}
                    initialAction={planAction}
                    onRecordEvent={
                        can.create && detail.client
                            ? () => {
                                  closeDetail();
                                  openEventWizard({ client_id: detail.client!.id, client_name: detail.client!.name, behaviour_support_plan_id: detail.id });
                              }
                            : undefined
                    }
                />
            ) : null}

            <RestraintEventWizard
                open={eventWizard}
                onClose={() => setEventWizard(false)}
                clients={clients}
                sites={sites}
                staff={staff}
                incidents={incidents}
                plans={plansForPicker}
                prescope={eventPrescope}
                onOpenEvent={(id) => {
                    setEventWizard(false);
                    openEvent(id);
                }}
            />
            <BspWizard
                open={planWizard}
                onClose={() => setPlanWizard(false)}
                clients={clients}
                staff={staff}
                onOpenPlan={(id) => {
                    setPlanWizard(false);
                    openPlan(id);
                }}
            />
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Hero badge + lens button                                          */
/* ------------------------------------------------------------------ */

function HeroBadge({ icon: Icon, tone, children }: { icon: LucideIcon; tone: ChipTone; children: ReactNode }) {
    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ${BADGE_TONE[tone]}`}>
            <Icon className="h-3.5 w-3.5" /> {children}
        </span>
    );
}

function LensButton({ active, icon: Icon, label, onClick }: { active: boolean; icon: LucideIcon; label: string; onClick: () => void }) {
    return (
        <Button unstyled
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={`inline-flex items-center gap-1.5 rounded-lg px-3.5 py-1.5 text-sm font-semibold transition-colors ${active ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'}`}
        >
            <Icon className="h-4 w-4" /> {label}
        </Button>
    );
}

/* ------------------------------------------------------------------ */
/*  Events table                                                      */
/* ------------------------------------------------------------------ */

function EventTable({ rows, onRowCtx, onOpen }: { rows: EventRow[]; onRowCtx: (e: ReactMouseEvent, row: EventRow) => void; onOpen: (id: number) => void }) {
    if (rows.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center gap-2 px-6 py-16 text-center">
                <ShieldAlert className="h-8 w-8 text-muted-foreground" />
                <div className="text-sm font-semibold">No restraint events here</div>
                <p className="text-xs text-muted-foreground">Nothing matches this tab and filters.</p>
            </div>
        );
    }
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[1040px] text-sm">
                <thead>
                    <tr className="border-b border-border bg-muted/70 text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                        <th className="px-4 py-2.5">When</th>
                        <th className="px-4 py-2.5">Client</th>
                        <th className="px-4 py-2.5">Type</th>
                        <th className="px-4 py-2.5">Duration</th>
                        <th className="px-4 py-2.5">Severity</th>
                        <th className="px-4 py-2.5">Within plan</th>
                        <th className="px-4 py-2.5">Reviewed</th>
                        <th className="px-4 py-2.5">Flags</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border">
                    {rows.map((row) => (
                        <EventRowView key={row.id} row={row} onRowCtx={onRowCtx} onOpen={onOpen} />
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function EventRowView({ row, onRowCtx, onOpen }: { row: EventRow; onRowCtx: (e: ReactMouseEvent, row: EventRow) => void; onOpen: (id: number) => void }) {
    const type = typeMeta(row.restraint_type);
    const sev = severityMeta(row.severity);
    return (
        <tr
            onClick={() => onOpen(row.id)}
            onContextMenu={(e) => onRowCtx(e, row)}
            tabIndex={0}
            aria-label={`Open restraint event ${row.reference}`}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onOpen(row.id);
                }
            }}
            className="cursor-pointer transition-colors hover:bg-muted/45 focus-visible:bg-muted/45 focus-visible:outline-none"
        >
            <td className="px-4 py-3 align-top">
                <div className="font-semibold">{whenLabel(row.started_at)}</div>
                <div className="text-xs text-muted-foreground">{row.reference}</div>
            </td>
            <td className="px-4 py-3 align-top">
                {row.client ? (
                    <div className="flex items-center gap-2">
                        <span className={`grid h-7 w-7 shrink-0 place-items-center rounded-full text-[11px] font-bold ${entityTone(row.client.id)}`}>{initials(row.client.name)}</span>
                        <div className="min-w-0">
                            <div className="truncate font-medium">{row.client.name}</div>
                            {row.site ? <div className="truncate text-xs text-muted-foreground">{row.site.name}</div> : null}
                        </div>
                    </div>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </td>
            <td className="px-4 py-3 align-top">
                <span className="inline-flex items-center gap-1.5">
                    <span className={`h-2 w-2 rounded-full ${DOT[type.tone]}`} />
                    <type.icon className={`h-4 w-4 ${ICON_TEXT[type.tone]}`} /> {type.label}
                </span>
            </td>
            <td className="px-4 py-3 align-top text-muted-foreground">{durationLabel(row.duration_minutes)}</td>
            <td className="px-4 py-3 align-top">
                <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${CHIP[sev.tone]}`}>{sev.label}</span>
            </td>
            <td className="px-4 py-3 align-top">
                {row.within_support_plan ? (
                    <CheckCircle2 className="h-4 w-4 text-status-success" />
                ) : (
                    <span className="inline-flex items-center gap-1 text-xs font-medium text-status-critical">
                        <XCircle className="h-4 w-4" /> No
                    </span>
                )}
            </td>
            <td className="px-4 py-3 align-top">{row.reviewed_at ? <CheckCircle2 className="h-4 w-4 text-status-success" /> : <XCircle className="h-4 w-4 text-muted-foreground" />}</td>
            <td className="px-4 py-3 align-top">
                <div className="flex flex-wrap gap-1.5">
                    {row.flags.unreviewed ? (
                        <FlagBadge icon={ClipboardCheck} tone="warning" title="Not yet reviewed">
                            Unreviewed
                        </FlagBadge>
                    ) : null}
                    {row.flags.out_of_plan ? (
                        <FlagBadge icon={AlertTriangle} tone="critical" title="Outside the behaviour support plan">
                            Out of plan
                        </FlagBadge>
                    ) : null}
                    {row.flags.injury ? (
                        <FlagBadge icon={HeartPulse} tone="critical" title="An injury occurred">
                            Injury
                        </FlagBadge>
                    ) : null}
                    {row.flags.linked_incident ? (
                        <FlagBadge icon={ClipboardList} tone="info" title="Linked to an incident">
                            Incident
                        </FlagBadge>
                    ) : null}
                    {!row.flags.unreviewed && !row.flags.out_of_plan && !row.flags.injury && !row.flags.linked_incident ? <span className="text-muted-foreground">—</span> : null}
                </div>
            </td>
        </tr>
    );
}

/* ------------------------------------------------------------------ */
/*  Plans grid (first-class cards)                                    */
/* ------------------------------------------------------------------ */

function PlanGrid({ rows, onRowCtx, onOpen }: { rows: PlanRow[]; onRowCtx: (e: ReactMouseEvent, row: PlanRow) => void; onOpen: (id: number) => void }) {
    if (rows.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center gap-2 rounded-2xl border border-border bg-card px-6 py-16 text-center shadow-sm">
                <BookOpen className="h-8 w-8 text-muted-foreground" />
                <div className="text-sm font-semibold">No behaviour support plans here</div>
                <p className="text-xs text-muted-foreground">Nothing matches this tab and filters.</p>
            </div>
        );
    }
    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {rows.map((row) => {
                const status = planStatusMeta(row.status);
                const reviewState = REVIEW_STATE_META[row.review_state] ?? REVIEW_STATE_META.ok;
                return (
                    <div
                        key={row.id}
                        role="button"
                        tabIndex={0}
                        onClick={() => onOpen(row.id)}
                        onContextMenu={(e) => onRowCtx(e, row)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' || e.key === ' ') {
                                e.preventDefault();
                                onOpen(row.id);
                            }
                        }}
                        aria-label={`Open plan ${row.reference}`}
                        className="flex cursor-pointer flex-col gap-3 rounded-2xl border border-border bg-card p-4 shadow-sm transition-colors hover:border-primary/40 focus-visible:border-primary/40 focus-visible:outline-none"
                    >
                        <div className="flex items-start justify-between gap-2">
                            <div className="flex items-center gap-2">
                                <span className={`grid h-9 w-9 shrink-0 place-items-center rounded-full text-xs font-bold ${row.client ? entityTone(row.client.id) : 'bg-muted text-muted-foreground'}`}>{initials(row.client?.name)}</span>
                                <div className="min-w-0">
                                    <div className="truncate text-sm font-semibold">{row.client?.name ?? 'Unknown client'}</div>
                                    <div className="text-xs text-muted-foreground">{row.reference}</div>
                                </div>
                            </div>
                            <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${CHIP[status.tone]}`}>
                                <status.icon className="h-3 w-3" /> {status.label}
                            </span>
                        </div>
                        <div className="text-sm font-medium">{row.title}</div>
                        <div className="mt-auto flex flex-wrap items-center gap-1.5">
                            {row.restrictive_practice_type ? <span className="inline-flex items-center rounded-md bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">{titleCase(row.restrictive_practice_type)}</span> : null}
                            {row.status === 'active' && row.review_state !== 'ok' ? (
                                <FlagBadge icon={CalendarClock} tone={reviewState.tone === 'critical' ? 'critical' : 'warning'} title="Review status">
                                    {reviewState.label}
                                </FlagBadge>
                            ) : null}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
