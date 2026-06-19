/* H&S Corrective actions — the verification register. The governance twin of the
 * Events register: every corrective/preventive action raised from a safety event,
 * tracked from open → in progress → awaiting verification → verified → closed.
 * Rows open the parent HsEvent detail modal on the corrective-actions pane, so the
 * register stays cross-linked to the governance workspace. Shares the Events
 * gold-standard chrome via governance-register-kit so the two read as one product
 * and cannot drift apart again. NZ-only, web-only. */
import AppLayout from '@/layouts/app-layout';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    ShiftContextMenu,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import {
    EventDetailDialog,
    EVENT_CATEGORY_LABELS,
    type EventActionKey,
    type EventDetail,
    type EventSectionKey,
} from '@/components/health-safety/event-detail-dialog';
import {
    DesignHeroSection,
    DesignHeroCluster,
    DesignHeroTile,
    DesignTabStrip,
    HeroFilterLabel,
    HeroRangePill,
    HeroSelect,
    HeroSearch,
    HeroClear,
    FlagBadge,
    RegisterTableHeader,
    fmt,
    titleCase,
    initials,
    entityTone,
    TONE_BG,
    TONE_DOT,
    type Tone,
    type DesignTabItem,
} from '@/pages/health-safety/components/governance-register-kit';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Bell,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    Eye,
    Flame,
    FlaskConical,
    Hand,
    HeartPulse,
    LayoutList,
    Link2,
    ListChecks,
    MapPin,
    MousePointer2,
    Search,
    Shield,
    ShieldAlert,
    ShieldCheck,
    SlidersHorizontal,
    Truck,
    UserRound,
    Wrench,
    type LucideIcon,
} from 'lucide-react';
import { useState, type MouseEvent as ReactMouseEvent } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type ActionRow = {
    id: number;
    reference_number: string;
    title: string;
    action_type: string;
    priority: string;
    status: string;
    assigned_to_name: string | null;
    due_date: string | null;
    is_overdue: boolean;
    completed_at: string | null;
    verified_at: string | null;
    event: {
        id: number;
        reference_number: string;
        event_category: string;
        severity: string;
        status: string;
        site_name: string | null;
        url: string;
        monitoring: boolean;
    } | null;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type Filters = {
    q: string | null;
    tab: string;
    priority: string | null;
    unassigned: boolean | null;
    site_id: number | null;
    from: string | null;
    to: string | null;
};

type Props = {
    actions: Paginated<ActionRow>;
    tab: string;
    tabCounts: Record<string, number>;
    hero: {
        live: { open: number; in_progress: number; awaiting_verification: number; verified: number };
        attention: { overdue: number; critical_open: number; unassigned: number; monitoring_events: number };
    };
    filters: Filters;
    sites: Array<{ id: number; name: string }>;
    detail: EventDetail | null;
    can: { manage: boolean };
};

/* ------------------------------------------------------------------ */
/*  Token maps (semantic only)                                         */
/* ------------------------------------------------------------------ */

const PRI: Record<string, { tone: Tone; label: string }> = {
    low: { tone: 'success', label: 'Low' },
    medium: { tone: 'warning', label: 'Medium' },
    high: { tone: 'critical', label: 'High' },
    critical: { tone: 'critical', label: 'Critical' },
};

const ACTION_STAGE: Record<string, { label: string; cls: string; icon: LucideIcon }> = {
    open: { label: 'Open', cls: 'bg-status-info-bg text-status-info', icon: ListChecks },
    in_progress: { label: 'In progress', cls: 'bg-primary/10 text-primary', icon: Activity },
    completed: { label: 'Awaiting verification', cls: 'bg-status-warning-bg text-status-warning', icon: ShieldCheck },
    verified: { label: 'Verified', cls: 'bg-status-success-bg text-status-success', icon: CheckCircle2 },
    closed: { label: 'Closed', cls: 'bg-muted text-muted-foreground', icon: CheckCircle2 },
};

const EVENT_STAGE: Record<string, { label: string; cls: string; icon: LucideIcon }> = {
    open: { label: 'Open', cls: 'bg-status-info-bg text-status-info', icon: AlertTriangle },
    investigating: { label: 'Investigating', cls: 'bg-primary/10 text-primary', icon: Search },
    corrective_action: { label: 'Corrective action', cls: 'bg-status-warning-bg text-status-warning', icon: ListChecks },
    monitoring: { label: 'Monitoring', cls: 'bg-[var(--live-bg)] text-[var(--live)]', icon: Activity },
    closed: { label: 'Closed', cls: 'bg-status-success-bg text-status-success', icon: CheckCircle2 },
};

/** Parent-event category → icon + chip tint for the source-style cell, mirroring
 *  the Events register's "Source & category" treatment. */
const CATEGORY_ICON: Record<string, LucideIcon> = {
    incident: ShieldAlert,
    near_miss: AlertTriangle,
    hazard: AlertTriangle,
    injury: HeartPulse,
    exposure: FlaskConical,
    restraint: Hand,
    safeguarding: ShieldCheck,
    vehicle_incident: Truck,
    drill_failure: Flame,
    inspection_failure: ClipboardCheck,
    equipment_fault: Wrench,
};

const CATEGORY_CHIP: Record<string, string> = {
    incident: 'bg-status-critical-bg text-status-critical',
    near_miss: 'bg-status-warning-bg text-status-warning',
    hazard: 'bg-status-warning-bg text-status-warning',
    injury: 'bg-status-critical-bg text-status-critical',
    exposure: 'bg-status-warning-bg text-status-warning',
    restraint: 'bg-status-critical-bg text-status-critical',
    safeguarding: 'bg-status-info-bg text-status-info',
    vehicle_incident: 'bg-status-warning-bg text-status-warning',
    drill_failure: 'bg-status-info-bg text-status-info',
    inspection_failure: 'bg-status-info-bg text-status-info',
    equipment_fault: 'bg-status-warning-bg text-status-warning',
};

const TABLE_TITLE: Record<string, string> = {
    all: 'All corrective actions',
    open: 'Open actions',
    in_progress: 'In progress',
    awaiting_verification: 'Awaiting verification',
    overdue: 'Overdue actions',
    verified: 'Verified actions',
    closed: 'Closed actions',
};

function fmtDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

/* date helpers (browser-local) */
const todayStr = () => {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};
const daysAgoStr = (n: number) => {
    const d = new Date();
    d.setDate(d.getDate() - n);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};

const RANGE_ITEMS = [
    { key: 'all', label: 'All' },
    { key: 'week', label: 'This week' },
    { key: '30d', label: '30 days' },
    { key: 'quarter', label: 'Quarter' },
];

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function CorrectiveActionsIndex({ actions, tab, tabCounts, hero, filters, sites, detail, can }: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [pendingSection, setPendingSection] = useState<EventSectionKey>('actions');
    const [pendingAction, setPendingAction] = useState<EventActionKey | null>(null);

    const go = (next: Partial<Filters>) =>
        router.get('/health-safety/corrective-actions', { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });

    const setTab = (id: string) => router.get('/health-safety/corrective-actions', { ...filters, tab: id }, { preserveScroll: true });

    // Detail-over-list: fetch only the `detail` prop and open the dialog without
    // navigating away; closing drops the param so `detail` comes back null.
    const openEvent = (id: number, opts?: { section?: EventSectionKey; action?: EventActionKey }) => {
        setPendingSection(opts?.section ?? 'actions');
        setPendingAction(opts?.action ?? null);
        router.get('/health-safety/corrective-actions', { ...filters, event: id }, { preserveState: true, preserveScroll: true, only: ['detail'] });
    };
    const closeDetail = () =>
        router.get('/health-safety/corrective-actions', { ...filters }, { preserveState: true, preserveScroll: true, only: ['detail'] });

    const clearFilters = () =>
        router.get('/health-safety/corrective-actions', { tab }, { preserveState: true, preserveScroll: true, replace: true });

    const hasFilters = !!(filters.q || filters.priority || filters.unassigned || filters.site_id || filters.from || filters.to);

    const TABS: DesignTabItem[] = [
        { id: 'all', label: 'All', icon: LayoutList, tone: 'primary', badge: tabCounts.all || undefined },
        { id: 'open', label: 'Open', icon: ListChecks, tone: 'info', badge: tabCounts.open || undefined },
        { id: 'in_progress', label: 'In progress', icon: Activity, tone: 'primary', badge: tabCounts.in_progress || undefined },
        { id: 'awaiting_verification', label: 'Awaiting verification', icon: ShieldCheck, tone: 'warning', badge: tabCounts.awaiting_verification || undefined },
        { id: 'overdue', label: 'Overdue', icon: Clock, tone: 'critical', badge: tabCounts.overdue || undefined },
        { id: 'verified', label: 'Verified', icon: CheckCircle2, tone: 'success', badge: tabCounts.verified || undefined },
        { id: 'closed', label: 'Closed', icon: CheckCircle2, tone: 'success', badge: tabCounts.closed || undefined },
    ];

    /* ---- due-date range (footer pills) ---- */
    const activeRange = !filters.from
        ? 'all'
        : filters.from === daysAgoStr(7)
          ? 'week'
          : filters.from === daysAgoStr(30)
            ? '30d'
            : filters.from === daysAgoStr(90)
              ? 'quarter'
              : 'custom';
    const onRange = (key: string) => {
        if (key === 'all') {
            go({ from: null, to: null });
            return;
        }
        const map: Record<string, number> = { week: 7, '30d': 30, quarter: 90 };
        go({ from: daysAgoStr(map[key]), to: todayStr() });
    };

    /* ---- right-click context menu (mirrors the dialog Options bar) ---- */
    const openRowCtx = (e: ReactMouseEvent, action: ActionRow) => {
        e.preventDefault();
        const priority = PRI[action.priority] ?? PRI.medium;
        const items: ShiftCtxItem[] = action.event
            ? [
                  {
                      icon: <ListChecks className="h-3.5 w-3.5" />,
                      label: 'Open corrective actions',
                      sub: action.reference_number,
                      tone: 'primary',
                      onClick: () => openEvent(action.event!.id, { section: 'actions' }),
                  },
                  {
                      icon: <Eye className="h-3.5 w-3.5" />,
                      label: 'View parent event',
                      sub: action.event.reference_number,
                      onClick: () => openEvent(action.event!.id, { section: 'overview' }),
                  },
                  ...(can.manage && action.event.status !== 'closed'
                      ? [
                            {
                                icon: <ListChecks className="h-3.5 w-3.5" />,
                                label: 'Add corrective action',
                                onClick: () => openEvent(action.event!.id, { action: 'add_action' }),
                            } satisfies ShiftCtxItem,
                        ]
                      : []),
                  { sep: true },
                  {
                      icon: <Link2 className="h-3.5 w-3.5" />,
                      label: 'Open event full page',
                      onClick: () => router.visit(`/health-safety/events/${action.event!.id}`),
                  },
              ]
            : [];

        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: priority.label.toUpperCase(),
            meta: `${action.reference_number} · ${action.title}`,
            items,
        });
    };

    const live = hero.live;
    const attention = hero.attention;
    const tableTitle = TABLE_TITLE[tab] ?? 'Corrective actions';

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Corrective actions', href: '/health-safety/corrective-actions' }]}>
            <Head title="Corrective actions" />

            <div className="min-h-screen bg-[oklch(0.98_0.006_277)] px-4 py-5 md:px-6">
                <div className="flex flex-col gap-4">
                    <DesignHeroSection
                        medallion={Wrench}
                        eyebrow="Safety actions · verification register"
                        title="Corrective actions"
                        description="Every corrective and preventive action raised from a safety event — driven from open through completion to independent verification, then closed to advance the parent event. Open a row to complete, verify and close inside the governance workspace."
                        cornerBadge={{ icon: ShieldCheck, label: 'Verifier ≠ completer' }}
                        clusters={
                            <>
                                <DesignHeroCluster title="Live · action lifecycle" icon={ListChecks}>
                                    <DesignHeroTile href="/health-safety/corrective-actions?tab=open" label="Open" value={fmt(live.open)} caption="ready to start" tone="info" />
                                    <DesignHeroTile href="/health-safety/corrective-actions?tab=in_progress" label="In progress" value={fmt(live.in_progress)} caption="being resolved" tone="primary" />
                                    <DesignHeroTile href="/health-safety/corrective-actions?tab=awaiting_verification" label="Await verify" value={fmt(live.awaiting_verification)} caption="needs verifier" tone="warning" />
                                    <DesignHeroTile href="/health-safety/corrective-actions?tab=verified" label="Verified" value={fmt(live.verified)} caption="effectiveness ✓" tone="success" />
                                </DesignHeroCluster>
                                <DesignHeroCluster title="Needs attention" icon={Bell}>
                                    <DesignHeroTile href="/health-safety/corrective-actions?tab=overdue" label="Overdue" value={fmt(attention.overdue)} caption={attention.overdue > 0 ? 'past due' : 'all on track'} tone="critical" />
                                    <DesignHeroTile href="/health-safety/corrective-actions?priority=high,critical" label="High / critical" value={fmt(attention.critical_open)} caption="priority open" tone="critical" />
                                    <DesignHeroTile href="/health-safety/corrective-actions?unassigned=true" label="Unassigned" value={fmt(attention.unassigned)} caption="needs an owner" tone="warning" />
                                    <DesignHeroTile href="/health-safety/events?tab=monitoring" label="Events monitoring" value={fmt(attention.monitoring_events)} caption="auto-advanced" tone="success" />
                                </DesignHeroCluster>
                            </>
                        }
                        footer={
                            <>
                                <HeroFilterLabel>Due</HeroFilterLabel>
                                {RANGE_ITEMS.map((item) => (
                                    <HeroRangePill key={item.key} active={activeRange === item.key} onClick={() => onRange(item.key)}>
                                        {item.label}
                                    </HeroRangePill>
                                ))}
                                <HeroSelect
                                    className="ml-auto"
                                    icon={MapPin}
                                    value={filters.site_id ?? ''}
                                    onChange={(e) => go({ site_id: e.target.value ? Number(e.target.value) : null })}
                                    ariaLabel="Site filter"
                                >
                                    <option value="">All sites</option>
                                    {sites.map((site) => (
                                        <option key={site.id} value={site.id}>
                                            {site.name}
                                        </option>
                                    ))}
                                </HeroSelect>
                                <HeroSelect
                                    icon={SlidersHorizontal}
                                    value={filters.priority ?? ''}
                                    onChange={(e) => go({ priority: e.target.value || null })}
                                    ariaLabel="Priority filter"
                                >
                                    <option value="">All priorities</option>
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                    <option value="critical">Critical</option>
                                </HeroSelect>
                                <HeroSearch placeholder="Search action or event…" defaultValue={filters.q ?? ''} onSubmit={(value) => go({ q: value })} />
                                {hasFilters ? <HeroClear onClick={clearFilters} /> : null}
                            </>
                        }
                    />

                    <DesignTabStrip value={tab} items={TABS} onChange={setTab} ariaLabel="Corrective action views" />

                    <section className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                        <RegisterTableHeader icon={Wrench} title={tableTitle} subtitle="the verification view" hint="Right-click a row for actions" hintIcon={MousePointer2} />
                        <ActionTable rows={actions.data} onOpen={openEvent} onRowCtx={openRowCtx} />
                    </section>

                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-muted-foreground">
                            {actions.total > 0 ? `Showing ${actions.from}–${actions.to} of ${actions.total}` : 'No corrective actions found'}
                        </p>
                        {actions.last_page > 1 ? <LaravelPagination links={actions.links} /> : null}
                    </div>
                </div>
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}

            {detail ? (
                <EventDetailDialog key={detail.id} detail={detail} open onClose={closeDetail} initialSection={pendingSection} initialAction={pendingAction} />
            ) : null}
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Actions table                                                      */
/* ------------------------------------------------------------------ */

function ActionTable({
    rows,
    onOpen,
    onRowCtx,
}: {
    rows: ActionRow[];
    onOpen: (id: number, opts?: { section?: EventSectionKey; action?: EventActionKey }) => void;
    onRowCtx: (e: ReactMouseEvent, action: ActionRow) => void;
}) {
    if (!rows.length) {
        return (
            <div className="px-4 py-16 text-center">
                <CheckCircle2 className="mx-auto mb-3 h-10 w-10 text-status-success/50" />
                <p className="font-medium text-muted-foreground">No corrective actions here</p>
                <p className="mt-1 text-sm text-muted-foreground/70">Nothing matches this tab and filters.</p>
            </div>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[1040px] text-sm">
                <thead className="bg-muted/70">
                    <tr className="border-b border-border text-left text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                        <th className="px-4 py-3">Due</th>
                        <th className="px-4 py-3">Action</th>
                        <th className="px-4 py-3">Priority</th>
                        <th className="px-4 py-3">Owner</th>
                        <th className="px-4 py-3">Parent event</th>
                        <th className="px-4 py-3">Stage</th>
                        <th className="px-4 py-3">Flags</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border">
                    {rows.map((action) => {
                        const priority = PRI[action.priority] ?? PRI.medium;
                        const stage = ACTION_STAGE[action.status] ?? ACTION_STAGE.open;
                        const StageIcon = stage.icon;
                        const eventStage = action.event ? (EVENT_STAGE[action.event.status] ?? EVENT_STAGE.open) : null;
                        const CatIcon = action.event ? (CATEGORY_ICON[action.event.event_category] ?? Shield) : Link2;
                        const awaiting = action.status === 'completed';
                        const unassigned = !action.assigned_to_name && action.status !== 'verified' && action.status !== 'closed';
                        const resolved = action.status === 'verified' || action.status === 'closed';

                        const open = () => {
                            if (action.event) onOpen(action.event.id, { section: 'actions' });
                        };

                        return (
                            <tr
                                key={action.id}
                                onClick={open}
                                onContextMenu={(e) => onRowCtx(e, action)}
                                tabIndex={action.event ? 0 : -1}
                                aria-label={action.event ? `Open parent event for action ${action.reference_number}` : undefined}
                                onKeyDown={(e) => {
                                    if (action.event && (e.key === 'Enter' || e.key === ' ')) {
                                        e.preventDefault();
                                        open();
                                    }
                                }}
                                className={cn(
                                    action.event ? 'cursor-pointer transition-colors hover:bg-muted/45 focus-visible:bg-muted/45 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring' : '',
                                    action.is_overdue ? 'bg-status-critical-bg/40' : '',
                                )}
                            >
                                {/* Due */}
                                <td className="px-4 py-3 align-top whitespace-nowrap">
                                    <div className={cn('flex items-center gap-1 text-xs font-bold', action.is_overdue ? 'text-status-critical' : 'text-foreground')}>
                                        <Clock className="h-3.5 w-3.5" />
                                        {fmtDate(action.due_date)}
                                    </div>
                                    <div className="mt-0.5 text-[11px] font-semibold text-muted-foreground">
                                        {action.is_overdue ? 'Overdue' : action.reference_number}
                                    </div>
                                </td>

                                {/* Action */}
                                <td className="max-w-[300px] px-4 py-3 align-top">
                                    <div className="flex items-start gap-2">
                                        <span className={cn('mt-1 h-2 w-2 shrink-0 rounded-full', TONE_DOT[priority.tone])} />
                                        <span className="min-w-0">
                                            <span className="block text-xs font-bold text-foreground">{action.reference_number}</span>
                                            <span className="block max-w-[24rem] truncate text-[11px] text-muted-foreground">{action.title}</span>
                                            <span className="mt-1 inline-flex rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                                                {titleCase(action.action_type)}
                                            </span>
                                        </span>
                                    </div>
                                </td>

                                {/* Priority */}
                                <td className="px-4 py-3 align-top">
                                    <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium', TONE_BG[priority.tone])}>{priority.label}</span>
                                </td>

                                {/* Owner */}
                                <td className="px-4 py-3 align-top">
                                    {action.assigned_to_name ? (
                                        <span className="flex items-center gap-2">
                                            <span className={cn('grid h-7 w-7 shrink-0 place-items-center rounded-md text-[10px] font-bold', entityTone(action.id))}>
                                                {initials(action.assigned_to_name)}
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block truncate text-xs font-bold text-foreground">{action.assigned_to_name}</span>
                                                <span className="block text-[11px] text-muted-foreground">Owner</span>
                                            </span>
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1.5 rounded-md bg-status-warning-bg px-2 py-1 text-[11px] font-bold text-status-warning">
                                            <UserRound className="h-3 w-3" /> Unassigned
                                        </span>
                                    )}
                                </td>

                                {/* Parent event */}
                                <td className="px-4 py-3 align-top">
                                    {action.event ? (
                                        <div className="flex items-center gap-2">
                                            <span className={cn('flex h-7 w-7 shrink-0 items-center justify-center rounded-md', CATEGORY_CHIP[action.event.event_category] ?? 'bg-muted text-muted-foreground')}>
                                                <CatIcon className="h-3.5 w-3.5" />
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block text-xs font-bold text-foreground">{action.event.reference_number}</span>
                                                <span className="block truncate text-[11px] font-medium text-muted-foreground">
                                                    {EVENT_CATEGORY_LABELS[action.event.event_category] ?? titleCase(action.event.event_category)}
                                                    {action.event.site_name ? ` · ${action.event.site_name}` : ''}
                                                </span>
                                            </span>
                                        </div>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-1 text-[11px] font-medium text-muted-foreground">
                                            <Link2 className="h-3 w-3" /> No parent event
                                        </span>
                                    )}
                                </td>

                                {/* Action stage */}
                                <td className="px-4 py-3 align-top">
                                    <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium', stage.cls)}>
                                        <StageIcon className="h-3 w-3" />
                                        {stage.label}
                                    </span>
                                </td>

                                {/* Flags */}
                                <td className="px-4 py-3 align-top">
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        {action.is_overdue ? (
                                            <FlagBadge icon={Clock} tone="critical" title="Past its due date">
                                                Overdue
                                            </FlagBadge>
                                        ) : null}
                                        {awaiting ? (
                                            <FlagBadge icon={ShieldCheck} tone="info" title="Completed — needs a different person to verify">
                                                Verify
                                            </FlagBadge>
                                        ) : null}
                                        {unassigned ? (
                                            <FlagBadge icon={UserRound} tone="warning" title="No owner assigned">
                                                No owner
                                            </FlagBadge>
                                        ) : null}
                                        {eventStage ? (
                                            <FlagBadge icon={eventStage.icon} tone={action.event?.monitoring ? 'success' : 'neutral'} title={`Parent event: ${eventStage.label}`}>
                                                {eventStage.label}
                                            </FlagBadge>
                                        ) : null}
                                        {!action.is_overdue && !awaiting && !unassigned && !eventStage ? (
                                            <span className="text-xs text-muted-foreground">{resolved ? 'Resolved' : '—'}</span>
                                        ) : null}
                                    </div>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
