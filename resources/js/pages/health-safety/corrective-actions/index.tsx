/* H&S Corrective actions — the verification register. The governance twin of the
 * Events register: every corrective/preventive action raised from a safety event,
 * tracked from open → in progress → awaiting verification → verified → closed.
 * Rows open the parent HsEvent detail modal on the corrective-actions pane (and
 * deep-link straight onto the Complete / Verify / Return panes), so the register
 * stays cross-linked to the governance workspace. Shares the gold-standard
 * `hs-hero-kit` hero chrome + rostering TabStrip/ShiftContextMenu with /incidents,
 * /safeguarding and /fleet-assets/incidents so the whole safety workflow reads as
 * one product. Row helpers come from the neutral register-row-kit. NZ-only, web-only. */
import {
    EVENT_CATEGORY_LABELS,
    EventDetailDialog,
    type EventActionKey,
    type EventDetail,
    type EventSectionKey,
} from '@/components/health-safety/event-detail-dialog';
import {
    EntityFilter,
    ShiftContextMenu,
    TabStrip,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import {
    fmt,
    HeroCluster,
    HeroClusterTile,
    HeroMedallion,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
    type Tone,
} from '@/pages/health-safety/components/hs-hero-kit';
import {
    entityTone,
    FlagBadge,
    initials,
    RegisterTableHeader,
    titleCase,
    TONE_BG,
    TONE_DOT,
} from '@/pages/health-safety/components/register-row-kit';
import { WorkflowRibbon } from '@/pages/health-safety/components/workflow-ribbon';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Bell,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    Eye,
    FileText,
    Flame,
    FlaskConical,
    Hand,
    HeartPulse,
    LayoutList,
    Link2,
    ListChecks,
    Lock,
    MoreVertical,
    MousePointer2,
    Play,
    Plus,
    RotateCcw,
    Search,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Truck,
    UserRound,
    Wrench,
    X,
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
    /** Separation-of-duties: who completed it, and may the current viewer verify it. */
    completed_by_user_id: number | null;
    completed_by_name: string | null;
    can_verify: boolean;
    recommendation: string | null;
    source:
        | {
              type: 'control_room_task';
              id: number;
              reference: string;
              title: string;
          }
        | { type: 'new_responsibility'; reason: string | null }
        | { type: 'standalone' };
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

type ActionPane = 'complete' | 'verify' | 'return';

type Props = {
    actions: Paginated<ActionRow>;
    tab: string;
    tabCounts: Record<string, number>;
    hero: {
        live: {
            open: number;
            in_progress: number;
            awaiting_verification: number;
            verified: number;
        };
        attention: {
            overdue: number;
            critical_open: number;
            unassigned: number;
            monitoring_events: number;
        };
    };
    filters: Filters;
    sites: Array<{ id: number; name: string }>;
    detail: EventDetail | null;
    can: { manage: boolean; viewReports?: boolean };
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

const ACTION_STAGE: Record<
    string,
    { label: string; cls: string; icon: LucideIcon }
> = {
    open: {
        label: 'Open',
        cls: 'bg-status-info-bg text-status-info',
        icon: ListChecks,
    },
    in_progress: {
        label: 'In progress',
        cls: 'bg-primary/10 text-primary',
        icon: Activity,
    },
    completed: {
        label: 'Awaiting verification',
        cls: 'bg-status-warning-bg text-status-warning',
        icon: ShieldCheck,
    },
    verified: {
        label: 'Verified',
        cls: 'bg-status-success-bg text-status-success',
        icon: CheckCircle2,
    },
    closed: {
        label: 'Closed',
        cls: 'bg-muted text-muted-foreground',
        icon: CheckCircle2,
    },
};

const EVENT_STAGE: Record<string, { label: string; icon: LucideIcon }> = {
    open: { label: 'Open', icon: AlertTriangle },
    investigating: { label: 'Investigating', icon: Search },
    corrective_action: { label: 'Corrective action', icon: ListChecks },
    monitoring: { label: 'Monitoring', icon: Activity },
    closed: { label: 'Closed', icon: CheckCircle2 },
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

const TRACEABILITY_REPORT =
    '/health-safety/reports/corrective-action-traceability';

function fmtDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/* date helpers (browser-local) */
const todayStr = () => {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 10);
};
const daysAgoStr = (n: number) => {
    const d = new Date();
    d.setDate(d.getDate() - n);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 10);
};

const RANGE_ITEMS = [
    { key: 'all', label: 'All' },
    { key: 'week', label: 'This week' },
    { key: '30d', label: '30 days' },
    { key: 'quarter', label: 'Quarter' },
];

const PRIORITY_ITEMS = [
    { key: 'all', label: 'All' },
    { key: 'low', label: 'Low' },
    { key: 'medium', label: 'Med' },
    { key: 'high', label: 'High' },
    { key: 'critical', label: 'Crit' },
];

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function CorrectiveActionsIndex({
    actions,
    tab,
    tabCounts,
    hero,
    filters,
    sites,
    detail,
    can,
}: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [pendingSection, setPendingSection] =
        useState<EventSectionKey>('actions');
    const [pendingAction, setPendingAction] = useState<EventActionKey | null>(
        null,
    );
    const [pendingActionTarget, setPendingActionTarget] = useState<{
        actionId: number;
        pane: ActionPane;
    } | null>(null);

    const go = (next: Partial<Filters>) =>
        router.get(
            '/health-safety/corrective-actions',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const setTab = (id: string) =>
        router.get(
            '/health-safety/corrective-actions',
            { ...filters, tab: id },
            { preserveScroll: true },
        );

    // Detail-over-list: fetch only the `detail` prop and open the dialog without
    // navigating away; closing drops the param so `detail` comes back null.
    const openEvent = (
        id: number,
        opts?: {
            section?: EventSectionKey;
            action?: EventActionKey;
            actionTarget?: { actionId: number; pane: ActionPane };
        },
    ) => {
        setPendingSection(opts?.section ?? 'actions');
        setPendingAction(opts?.action ?? null);
        setPendingActionTarget(opts?.actionTarget ?? null);
        router.get(
            '/health-safety/corrective-actions',
            { ...filters, event: id },
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );
    };
    // Deep-link a row straight onto a lifecycle pane (Complete / Verify / Return)
    // of its parent event's Corrective actions section.
    const openActionPane = (action: ActionRow, pane: ActionPane) => {
        if (!action.event) return;
        openEvent(action.event.id, {
            section: 'actions',
            actionTarget: { actionId: action.id, pane },
        });
    };
    const closeDetail = () => {
        setPendingActionTarget(null);
        router.get(
            '/health-safety/corrective-actions',
            { ...filters },
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );
    };

    const clearFilters = () =>
        router.get(
            '/health-safety/corrective-actions',
            { tab },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const hasFilters = !!(
        filters.q ||
        filters.priority ||
        filters.unassigned ||
        filters.site_id ||
        filters.from ||
        filters.to
    );

    const TABS: RosterTabItem[] = [
        {
            id: 'all',
            label: 'All',
            icon: LayoutList,
            tone: 'primary',
            badge: tabCounts.all || undefined,
        },
        {
            id: 'open',
            label: 'Open',
            icon: ListChecks,
            tone: 'info',
            badge: tabCounts.open || undefined,
        },
        {
            id: 'in_progress',
            label: 'In progress',
            icon: Activity,
            tone: 'primary',
            badge: tabCounts.in_progress || undefined,
        },
        {
            id: 'awaiting_verification',
            label: 'Awaiting verification',
            icon: ShieldCheck,
            tone: 'warning',
            badge: tabCounts.awaiting_verification || undefined,
        },
        {
            id: 'overdue',
            label: 'Overdue',
            icon: Clock,
            tone: 'critical',
            badge: tabCounts.overdue || undefined,
        },
        {
            id: 'verified',
            label: 'Verified',
            icon: CheckCircle2,
            tone: 'success',
            badge: tabCounts.verified || undefined,
        },
        {
            id: 'closed',
            label: 'Closed',
            icon: CheckCircle2,
            tone: 'success',
            badge: tabCounts.closed || undefined,
        },
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

    /* ---- status-aware lifecycle menu (right-click + kebab share one payload) ---- */
    const menuItems = (action: ActionRow): ShiftCtxItem[] => {
        if (!action.event) return [];
        const base = `/health-safety/events/${action.event.id}/corrective-actions/${action.id}`;
        const canWrite = can.manage && action.event.status !== 'closed';

        const lifecycle: ShiftCtxItem[] = [];
        if (canWrite) {
            if (action.status === 'open') {
                lifecycle.push({
                    icon: <Play className="h-3.5 w-3.5" />,
                    label: 'Start action',
                    tone: 'primary',
                    onClick: () =>
                        router.post(
                            `${base}/start`,
                            {},
                            { preserveScroll: true },
                        ),
                });
            } else if (action.status === 'in_progress') {
                lifecycle.push({
                    icon: <CheckCircle2 className="h-3.5 w-3.5" />,
                    label: 'Mark complete…',
                    tone: 'primary',
                    onClick: () => openActionPane(action, 'complete'),
                });
            } else if (action.status === 'completed') {
                // Verify is hidden for the person who completed it (server also gates).
                if (action.can_verify) {
                    lifecycle.push({
                        icon: <ShieldCheck className="h-3.5 w-3.5" />,
                        label: 'Verify…',
                        tone: 'primary',
                        onClick: () => openActionPane(action, 'verify'),
                    });
                }
                lifecycle.push({
                    icon: <RotateCcw className="h-3.5 w-3.5" />,
                    label: 'Return for rework…',
                    tone: 'critical',
                    onClick: () => openActionPane(action, 'return'),
                });
            } else if (action.status === 'verified') {
                lifecycle.push({
                    icon: <Lock className="h-3.5 w-3.5" />,
                    label: 'Close action',
                    onClick: () =>
                        router.post(
                            `${base}/close`,
                            {},
                            { preserveScroll: true },
                        ),
                });
            }
        }

        const tail: ShiftCtxItem[] = [
            {
                icon: <ListChecks className="h-3.5 w-3.5" />,
                label: 'Open corrective actions',
                sub: action.reference_number,
                tone: 'primary',
                onClick: () =>
                    openEvent(action.event!.id, { section: 'actions' }),
            },
            {
                icon: <Eye className="h-3.5 w-3.5" />,
                label: 'View parent event',
                sub: action.event.reference_number,
                onClick: () =>
                    openEvent(action.event!.id, { section: 'overview' }),
            },
            ...(canWrite
                ? [
                      {
                          icon: <Plus className="h-3.5 w-3.5" />,
                          label: 'Add corrective action',
                          onClick: () =>
                              openEvent(action.event!.id, {
                                  action: 'add_action',
                              }),
                      } satisfies ShiftCtxItem,
                  ]
                : []),
            {
                icon: <Link2 className="h-3.5 w-3.5" />,
                label: 'Open event full page',
                onClick: () =>
                    router.visit(`/health-safety/events/${action.event!.id}`),
            },
        ];

        return lifecycle.length ? [...lifecycle, { sep: true }, ...tail] : tail;
    };

    const openMenu = (action: ActionRow, x: number, y: number) => {
        const priority = PRI[action.priority] ?? PRI.medium;
        setCtx({
            x,
            y,
            tag: priority.label.toUpperCase(),
            meta: `${action.reference_number} · ${action.title}`,
            items: menuItems(action),
        });
    };

    const live = hero.live;
    const attention = hero.attention;
    const tableTitle = TABLE_TITLE[tab] ?? 'Corrective actions';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                {
                    title: 'Corrective actions',
                    href: '/health-safety/corrective-actions',
                },
            ]}
        >
            <Head title="Corrective actions" />

            <div className="flex flex-col gap-6 p-6">
                {/* ---- Hero ---- */}
                <HeroShell
                    footer={
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <HeroSegmented
                                label="Due"
                                variant="pill"
                                ariaLabel="Due date range"
                                items={RANGE_ITEMS}
                                value={activeRange}
                                onChange={onRange}
                            />
                            {sites?.length ? (
                                <EntityFilter
                                    label="Site"
                                    allLabel="All sites"
                                    items={sites}
                                    value={filters.site_id}
                                    onChange={(id) => go({ site_id: id })}
                                    onDark
                                />
                            ) : null}
                            <HeroSegmented
                                label="Priority"
                                variant="pill"
                                ariaLabel="Priority"
                                items={PRIORITY_ITEMS}
                                value={filters.priority ?? 'all'}
                                onChange={(key) =>
                                    go({ priority: key === 'all' ? null : key })
                                }
                            />
                            <div className="relative ml-auto">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                <input
                                    type="search"
                                    placeholder="Search action or event…"
                                    defaultValue={filters.q ?? ''}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter')
                                            go({
                                                q:
                                                    (
                                                        e.target as HTMLInputElement
                                                    ).value || null,
                                            });
                                    }}
                                    className="w-52 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pr-2.5 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                                />
                            </div>
                            {hasFilters ? (
                                // eslint-disable-next-line no-restricted-syntax -- onDark clear affordance on the hero footer
                                <button
                                    type="button"
                                    onClick={clearFilters}
                                    className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-primary-foreground/70 transition-colors hover:text-primary-foreground"
                                >
                                    <X className="h-3 w-3" /> Clear
                                </button>
                            ) : null}
                        </div>
                    }
                >
                    <WorkflowRibbon current="resolve" />

                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-4">
                            <HeroMedallion icon={Wrench} />
                            <div className="flex flex-col gap-1.5">
                                <div className="flex flex-wrap items-center gap-2">
                                    <HeroStatusPill>
                                        Safety actions · verification register
                                    </HeroStatusPill>
                                    <span className="inline-flex items-center gap-1.5 rounded-full bg-primary-foreground/15 px-2.5 py-1 text-[11px] font-semibold tracking-[0.04em] text-primary-foreground/85 uppercase">
                                        <ShieldCheck className="h-3.5 w-3.5" />{' '}
                                        Verifier ≠ completer
                                    </span>
                                </div>
                                <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">
                                    Corrective actions
                                </h1>
                                <p className="max-w-xl text-sm text-primary-foreground/70">
                                    Every corrective and preventive action
                                    raised from a safety event — driven from
                                    open through completion to independent
                                    verification, then closed to advance the
                                    parent event. Open a row to complete, verify
                                    and close inside the governance workspace.
                                </p>
                            </div>
                        </div>

                        {can.viewReports ? (
                            <Button
                                size="sm"
                                className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                                onClick={() =>
                                    router.visit(TRACEABILITY_REPORT)
                                }
                            >
                                <FileText className="mr-1.5 h-4 w-4" />{' '}
                                Traceability report
                            </Button>
                        ) : null}
                    </div>

                    {/* stat clusters */}
                    <div className="grid gap-3 lg:grid-cols-2">
                        <HeroCluster
                            title="Live · action lifecycle"
                            icon={ListChecks}
                        >
                            <HeroClusterTile
                                href="/health-safety/corrective-actions?tab=open"
                                label="Open"
                                value={fmt(live.open)}
                                caption="ready to start"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/health-safety/corrective-actions?tab=in_progress"
                                label="In progress"
                                value={fmt(live.in_progress)}
                                caption="being resolved"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/health-safety/corrective-actions?tab=awaiting_verification"
                                label="Await verify"
                                value={fmt(live.awaiting_verification)}
                                caption="needs verifier"
                                tone="warning"
                            />
                            <HeroClusterTile
                                href="/health-safety/corrective-actions?tab=verified"
                                label="Verified"
                                value={fmt(live.verified)}
                                caption="effectiveness ✓"
                                tone="success"
                            />
                        </HeroCluster>
                        <HeroCluster title="Needs attention" icon={Bell}>
                            <HeroClusterTile
                                href="/health-safety/corrective-actions?tab=overdue"
                                label="Overdue"
                                value={fmt(attention.overdue)}
                                caption={
                                    attention.overdue > 0
                                        ? 'past due'
                                        : 'all on track'
                                }
                                tone={
                                    attention.overdue > 0
                                        ? 'critical'
                                        : 'success'
                                }
                            />
                            <HeroClusterTile
                                href="/health-safety/corrective-actions?priority=high,critical"
                                label="High / critical"
                                value={fmt(attention.critical_open)}
                                caption="priority open"
                                tone={
                                    attention.critical_open > 0
                                        ? 'critical'
                                        : 'success'
                                }
                            />
                            <HeroClusterTile
                                href="/health-safety/corrective-actions?unassigned=true"
                                label="Unassigned"
                                value={fmt(attention.unassigned)}
                                caption="needs an owner"
                                tone={
                                    attention.unassigned > 0
                                        ? 'warning'
                                        : 'success'
                                }
                            />
                            <HeroClusterTile
                                href="/health-safety/events?tab=monitoring"
                                label="Events monitoring"
                                value={fmt(attention.monitoring_events)}
                                caption="auto-advanced"
                                tone="success"
                            />
                        </HeroCluster>
                    </div>
                </HeroShell>

                {/* ---- Tabs ---- */}
                <TabStrip
                    value={tab}
                    items={TABS}
                    onChange={setTab}
                    ariaLabel="Corrective action views"
                />

                {/* ---- Table ---- */}
                <section className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                    <RegisterTableHeader
                        icon={Wrench}
                        title={tableTitle}
                        subtitle="the verification view"
                        hint="Right-click or ⋮ for the full lifecycle"
                        hintIcon={MousePointer2}
                    />
                    <ActionTable
                        rows={actions.data}
                        canViewReports={!!can.viewReports}
                        onOpen={openEvent}
                        onMenu={openMenu}
                    />
                </section>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm text-muted-foreground">
                        {actions.total > 0
                            ? `Showing ${actions.from}–${actions.to} of ${actions.total}`
                            : 'No corrective actions found'}
                    </p>
                    {actions.last_page > 1 ? (
                        <LaravelPagination links={actions.links} />
                    ) : null}
                </div>
            </div>

            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}

            {detail ? (
                <EventDetailDialog
                    key={detail.id}
                    detail={detail}
                    open
                    onClose={closeDetail}
                    initialSection={pendingSection}
                    initialAction={pendingAction}
                    initialActionTarget={pendingActionTarget}
                />
            ) : null}
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Actions table                                                      */
/* ------------------------------------------------------------------ */

function ActionTable({
    rows,
    canViewReports,
    onOpen,
    onMenu,
}: {
    rows: ActionRow[];
    canViewReports: boolean;
    onOpen: (
        id: number,
        opts?: { section?: EventSectionKey; action?: EventActionKey },
    ) => void;
    onMenu: (action: ActionRow, x: number, y: number) => void;
}) {
    if (!rows.length) {
        return (
            <div className="px-4 py-16 text-center">
                <Wrench className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                <p className="font-semibold text-foreground">
                    No corrective actions yet
                </p>
                <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                    Corrective and preventive actions are raised from a safety
                    event — open an event in the register and add an action, or
                    promote an investigation recommendation. They'll appear here
                    to drive, verify and close.
                </p>
                <div className="mt-4 flex flex-wrap items-center justify-center gap-2">
                    <Button
                        size="sm"
                        onClick={() => router.visit('/health-safety/events')}
                    >
                        <ListChecks className="mr-1.5 h-4 w-4" /> Go to Events
                        register
                    </Button>
                    {canViewReports ? (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => router.visit(TRACEABILITY_REPORT)}
                        >
                            <FileText className="mr-1.5 h-4 w-4" /> Traceability
                            report
                        </Button>
                    ) : null}
                </div>
            </div>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[1080px] text-sm">
                <thead className="bg-muted/70">
                    <tr className="border-b border-border text-left text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                        <th className="px-4 py-3">Due</th>
                        <th className="px-4 py-3">Action</th>
                        <th className="px-4 py-3">Priority</th>
                        <th className="px-4 py-3">Owner</th>
                        <th className="px-4 py-3">Parent event</th>
                        <th className="px-4 py-3">Stage</th>
                        <th className="px-4 py-3">Flags</th>
                        <th className="px-4 py-3">
                            <span className="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border">
                    {rows.map((action) => {
                        const priority = PRI[action.priority] ?? PRI.medium;
                        const stage =
                            ACTION_STAGE[action.status] ?? ACTION_STAGE.open;
                        const StageIcon = stage.icon;
                        const eventStage = action.event
                            ? (EVENT_STAGE[action.event.status] ??
                              EVENT_STAGE.open)
                            : null;
                        const CatIcon = action.event
                            ? (CATEGORY_ICON[action.event.event_category] ??
                              Shield)
                            : Link2;
                        const awaiting = action.status === 'completed';
                        const unassigned =
                            !action.assigned_to_name &&
                            action.status !== 'verified' &&
                            action.status !== 'closed';
                        const resolved =
                            action.status === 'verified' ||
                            action.status === 'closed';

                        const open = () => {
                            if (action.event)
                                onOpen(action.event.id, { section: 'actions' });
                        };

                        return (
                            <tr
                                key={action.id}
                                onClick={open}
                                onContextMenu={(e: ReactMouseEvent) => {
                                    e.preventDefault();
                                    onMenu(action, e.clientX, e.clientY);
                                }}
                                tabIndex={action.event ? 0 : -1}
                                aria-label={
                                    action.event
                                        ? `Open parent event for action ${action.reference_number}`
                                        : undefined
                                }
                                onKeyDown={(e) => {
                                    if (
                                        action.event &&
                                        (e.key === 'Enter' || e.key === ' ')
                                    ) {
                                        e.preventDefault();
                                        open();
                                    }
                                }}
                                className={cn(
                                    action.event
                                        ? 'cursor-pointer transition-colors hover:bg-muted/45 focus-visible:bg-muted/45 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring'
                                        : '',
                                    action.is_overdue
                                        ? 'bg-status-critical-bg/40'
                                        : '',
                                )}
                            >
                                {/* Due */}
                                <td className="px-4 py-3 align-top whitespace-nowrap">
                                    <div
                                        className={cn(
                                            'flex items-center gap-1 text-xs font-bold',
                                            action.is_overdue
                                                ? 'text-status-critical'
                                                : 'text-foreground',
                                        )}
                                    >
                                        <Clock className="h-3.5 w-3.5" />
                                        {fmtDate(action.due_date)}
                                    </div>
                                    <div className="mt-0.5 text-[11px] font-semibold text-muted-foreground">
                                        {action.is_overdue
                                            ? 'Overdue'
                                            : action.reference_number}
                                    </div>
                                </td>

                                {/* Action */}
                                <td className="max-w-[300px] px-4 py-3 align-top">
                                    <div className="flex items-start gap-2">
                                        <span
                                            className={cn(
                                                'mt-1 h-2 w-2 shrink-0 rounded-full',
                                                TONE_DOT[priority.tone],
                                            )}
                                        />
                                        <span className="min-w-0">
                                            <span className="block text-xs font-bold text-foreground">
                                                {action.reference_number}
                                            </span>
                                            <span className="block max-w-[24rem] truncate text-[11px] text-muted-foreground">
                                                {action.title}
                                            </span>
                                            {action.recommendation ? (
                                                <span className="mt-1 block max-w-[24rem] text-[11px] text-muted-foreground">
                                                    Recommendation:{' '}
                                                    {action.recommendation}
                                                </span>
                                            ) : null}
                                            {action.source.type ===
                                            'control_room_task' ? (
                                                <span className="mt-1 block max-w-[24rem] text-[11px] text-muted-foreground">
                                                    Transferred from Control
                                                    Room task:{' '}
                                                    {action.source.title}
                                                </span>
                                            ) : action.source.type ===
                                              'new_responsibility' ? (
                                                <span className="mt-1 block max-w-[24rem] text-[11px] text-muted-foreground">
                                                    New responsibility:{' '}
                                                    {action.source.reason ??
                                                        'Reason not recorded'}
                                                </span>
                                            ) : null}
                                            <span className="mt-1 inline-flex rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                                                {titleCase(action.action_type)}
                                            </span>
                                        </span>
                                    </div>
                                </td>

                                {/* Priority */}
                                <td className="px-4 py-3 align-top">
                                    <span
                                        className={cn(
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium',
                                            TONE_BG[priority.tone],
                                        )}
                                    >
                                        {priority.label}
                                    </span>
                                </td>

                                {/* Owner */}
                                <td className="px-4 py-3 align-top">
                                    {action.assigned_to_name ? (
                                        <span className="flex items-center gap-2">
                                            <span
                                                className={cn(
                                                    'grid h-7 w-7 shrink-0 place-items-center rounded-md text-[10px] font-bold',
                                                    entityTone(action.id),
                                                )}
                                            >
                                                {initials(
                                                    action.assigned_to_name,
                                                )}
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block truncate text-xs font-bold text-foreground">
                                                    {action.assigned_to_name}
                                                </span>
                                                <span className="block text-[11px] text-muted-foreground">
                                                    Owner
                                                </span>
                                            </span>
                                        </span>
                                    ) : (
                                        <span className="inline-flex items-center gap-1.5 rounded-md bg-status-warning-bg px-2 py-1 text-[11px] font-bold text-status-warning">
                                            <UserRound className="h-3 w-3" />{' '}
                                            Unassigned
                                        </span>
                                    )}
                                </td>

                                {/* Parent event */}
                                <td className="px-4 py-3 align-top">
                                    {action.event ? (
                                        <div className="flex items-center gap-2">
                                            <span
                                                className={cn(
                                                    'flex h-7 w-7 shrink-0 items-center justify-center rounded-md',
                                                    CATEGORY_CHIP[
                                                        action.event
                                                            .event_category
                                                    ] ??
                                                        'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                <CatIcon className="h-3.5 w-3.5" />
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block text-xs font-bold text-foreground">
                                                    {
                                                        action.event
                                                            .reference_number
                                                    }
                                                </span>
                                                <span className="block truncate text-[11px] font-medium text-muted-foreground">
                                                    {EVENT_CATEGORY_LABELS[
                                                        action.event
                                                            .event_category
                                                    ] ??
                                                        titleCase(
                                                            action.event
                                                                .event_category,
                                                        )}
                                                    {action.event.site_name
                                                        ? ` · ${action.event.site_name}`
                                                        : ''}
                                                </span>
                                            </span>
                                        </div>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 rounded-md bg-muted px-2 py-1 text-[11px] font-medium text-muted-foreground">
                                            <Link2 className="h-3 w-3" /> No
                                            parent event
                                        </span>
                                    )}
                                </td>

                                {/* Action stage */}
                                <td className="px-4 py-3 align-top">
                                    <span
                                        className={cn(
                                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium',
                                            stage.cls,
                                        )}
                                    >
                                        <StageIcon className="h-3 w-3" />
                                        {stage.label}
                                    </span>
                                </td>

                                {/* Flags */}
                                <td className="px-4 py-3 align-top">
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        {action.is_overdue ? (
                                            <FlagBadge
                                                icon={Clock}
                                                tone="critical"
                                                title="Past its due date"
                                            >
                                                Overdue
                                            </FlagBadge>
                                        ) : null}
                                        {awaiting ? (
                                            <FlagBadge
                                                icon={ShieldCheck}
                                                tone="info"
                                                title="Completed — needs a different person to verify"
                                            >
                                                Verify
                                            </FlagBadge>
                                        ) : null}
                                        {unassigned ? (
                                            <FlagBadge
                                                icon={UserRound}
                                                tone="warning"
                                                title="No owner assigned"
                                            >
                                                No owner
                                            </FlagBadge>
                                        ) : null}
                                        {eventStage ? (
                                            <FlagBadge
                                                icon={eventStage.icon}
                                                tone={
                                                    action.event?.monitoring
                                                        ? 'success'
                                                        : 'neutral'
                                                }
                                                title={`Parent event: ${eventStage.label}`}
                                            >
                                                {eventStage.label}
                                            </FlagBadge>
                                        ) : null}
                                        {!action.is_overdue &&
                                        !awaiting &&
                                        !unassigned &&
                                        !eventStage ? (
                                            <span className="text-xs text-muted-foreground">
                                                {resolved ? 'Resolved' : '—'}
                                            </span>
                                        ) : null}
                                    </div>
                                </td>

                                {/* Kebab — same payload as right-click (a11y / discoverability) */}
                                <td className="px-2 py-3 text-right align-top">
                                    {action.event ? (
                                        // eslint-disable-next-line no-restricted-syntax -- icon-only row affordance; opens the shared ShiftContextMenu
                                        <button
                                            type="button"
                                            aria-label={`Lifecycle actions for ${action.reference_number}`}
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                const r =
                                                    e.currentTarget.getBoundingClientRect();
                                                onMenu(
                                                    action,
                                                    r.left,
                                                    r.bottom,
                                                );
                                            }}
                                            className="inline-grid h-7 w-7 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                        >
                                            <MoreVertical className="h-4 w-4" />
                                        </button>
                                    ) : null}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
