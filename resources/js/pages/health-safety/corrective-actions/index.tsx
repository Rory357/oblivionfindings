import {
    EventDetailDialog,
    type EventActionKey,
    type EventDetail,
    type EventSectionKey,
} from '@/components/health-safety/event-detail-dialog';
import {
    SafetyDateFilter,
    SafetyRegisterSearch,
    SafetyWorkspaceRail,
} from '@/components/health-safety/workspace-header';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
} from '@/components/page';
import { TierTwoTabs } from '@/components/page/grouped-profile-nav';
import {
    PageHeaderNumber,
    PageHeaderNumbers,
} from '@/components/page/page-header-strip';
import {
    EntityFilter,
    ShiftContextMenu,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import {
    PersonChip,
    Ring,
    Surface,
    type SgTone,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { HS_LABELS, hsLabel } from '@/lib/health-safety/hs-labels';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateOnly, toDateInput } from '@/lib/datetime';
import {
    correctiveActionHref,
    safetyReviewHref,
} from '@/lib/health-safety/navigation';
import { cn } from '@/lib/utils';
import { type Tone } from '@/pages/health-safety/components/hs-hero-kit';
import {
    entityTone,
    FlagBadge,
    initials,
    TONE_BG,
    TONE_DOT,
} from '@/pages/health-safety/components/register-row-kit';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    Columns3,
    Eye,
    FileText,
    Flame,
    FlaskConical,
    Hand,
    HeartPulse,
    LayoutList,
    Link2,
    List,
    ListChecks,
    Lock,
    MoreVertical,
    Play,
    Plus,
    RotateCcw,
    Search,
    Shield,
    ShieldAlert,
    ShieldCheck,
    SlidersHorizontal,
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

export type ActionRow = {
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
    evidence: {
        load_state: 'loaded' | 'unavailable';
        attachments: Array<{ id: number }>;
    };
    rework: { latest_reason: string | null };
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
    monitoringReviewUrl: string;
};

/* ------------------------------------------------------------------ */
/*  Token maps (semantic only)                                         */
/* ------------------------------------------------------------------ */

const PRI: Record<string, { tone: Tone; label: string }> = {
    low: { tone: 'neutral', label: hsLabel('action_priority', 'low') },
    medium: { tone: 'warning', label: hsLabel('action_priority', 'medium') },
    high: { tone: 'warning', label: hsLabel('action_priority', 'high') },
    critical: { tone: 'critical', label: hsLabel('action_priority', 'critical') },
};

const ACTION_STAGE: Record<
    string,
    { label: string; variant: StatusVariant; icon: LucideIcon }
> = {
    open: {
        label: hsLabel('action_status', 'open'),
        variant: 'info',
        icon: ListChecks,
    },
    in_progress: {
        label: hsLabel('action_status', 'in_progress'),
        variant: 'info',
        icon: Activity,
    },
    completed: {
        label: hsLabel('action_status', 'completed'),
        variant: 'warning',
        icon: ShieldCheck,
    },
    verified: {
        label: hsLabel('action_status', 'verified'),
        variant: 'success',
        icon: CheckCircle2,
    },
    closed: {
        label: hsLabel('action_status', 'closed'),
        variant: 'neutral',
        icon: CheckCircle2,
    },
};

const EVENT_STAGE: Record<string, { label: string; icon: LucideIcon }> = {
    open: { label: hsLabel('review_status', 'open'), icon: AlertTriangle },
    investigating: { label: hsLabel('review_status', 'investigating'), icon: Search },
    corrective_action: { label: hsLabel('review_status', 'corrective_action'), icon: ListChecks },
    monitoring: { label: hsLabel('review_status', 'monitoring'), icon: Activity },
    closed: { label: hsLabel('review_status', 'closed'), icon: CheckCircle2 },
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
    all: 'All actions',
    open: HS_LABELS.action_status.open,
    in_progress: HS_LABELS.action_status.in_progress,
    awaiting_verification: HS_LABELS.action_status.completed,
    overdue: 'Overdue actions',
    verified: HS_LABELS.action_status.verified,
    closed: HS_LABELS.action_status.closed,
    critical_open: 'Urgent and high, not done',
    unassigned: HS_LABELS.owner_state.unassigned,
};

const TRACEABILITY_REPORT = '/health-safety/reports?view=action-traceability';

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
    monitoringReviewUrl,
}: Props) {
    const pageUrl = usePage().url;
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
    // Closing from the list menu asks first, the same as on the action's own page.
    const [closing, setClosing] = useState<{ href: string; title: string } | null>(null);
    const [closeBusy, setCloseBusy] = useState(false);

    // Board (default, pg-hsn-42) or the register List; the choice rides in the URL with the filters.
    const view: 'board' | 'list' =
        new URLSearchParams(pageUrl.split('?')[1]?.split('#')[0] ?? '').get(
            'view',
        ) === 'list'
            ? 'list'
            : 'board';
    const keepView = view === 'list' ? { view: 'list' } : {};

    const go = (next: Partial<Filters>) =>
        router.get(
            '/health-safety/corrective-actions',
            { ...filters, ...next, ...keepView },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const setTab = (id: string, nextView: 'board' | 'list' = view) =>
        router.get(
            '/health-safety/corrective-actions',
            {
                ...filters,
                tab: id,
                ...(nextView === 'list' ? { view: 'list' } : {}),
            },
            { preserveScroll: true },
        );

    const setView = (nextView: 'board' | 'list') =>
        router.get(
            '/health-safety/corrective-actions',
            { ...filters, ...(nextView === 'list' ? { view: 'list' } : {}) },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    // Normal entry opens a permanent page; the filtered register remains the return.
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
        router.visit(
            safetyReviewHref(id, {
                section: opts?.section ?? 'actions',
                action: opts?.action,
                pane: opts?.actionTarget
                    ? {
                          kind: (
                              {
                                  complete: 'ca_complete',
                                  verify: 'ca_verify',
                                  return: 'ca_return',
                              } as const
                          )[opts.actionTarget.pane],
                          actionId: opts.actionTarget.actionId,
                      }
                    : null,
                returnTo: pageUrl,
            }),
        );
    };
    // Deep-link a row straight onto a lifecycle pane (Complete / Verify / Return)
    // of its parent event's Corrective actions section.
    const openActionPane = (action: ActionRow, pane: ActionPane) => {
        if (!action.event) return;
        router.visit(
            correctiveActionHref(action.id, {
                action: pane,
                returnTo: pageUrl,
            }),
        );
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
            { tab, ...keepView },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    // An empty view or filter is not an empty register (found in the 30 Oct user test).
    const showAll = () =>
        router.get(
            '/health-safety/corrective-actions',
            { ...keepView },
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
            label: HS_LABELS.action_status.open,
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
            label: HS_LABELS.action_status.completed,
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

    /* ---- status-aware lifecycle menu (right-click + kebab share one payload) ---- */
    const menuItems = (action: ActionRow): ShiftCtxItem[] => {
        if (!action.event) return [];
        const base = `/health-safety/events/${action.event.id}/corrective-actions/${action.id}`;
        const canWrite = can.manage;

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
                        setClosing({ href: `${base}/close`, title: action.title }),
                });
            }
        }

        const tail: ShiftCtxItem[] = [
            {
                icon: <ListChecks className="h-3.5 w-3.5" />,
                label: 'Open corrective action',
                sub: action.reference_number,
                tone: 'primary',
                onClick: () =>
                    router.visit(
                        correctiveActionHref(action.id, { returnTo: pageUrl }),
                    ),
            },
            {
                icon: <Eye className="h-3.5 w-3.5" />,
                label: 'Open safety review',
                sub: action.event.reference_number,
                onClick: () =>
                    openEvent(action.event!.id, { section: 'overview' }),
            },
            ...(canWrite && action.event.status !== 'closed'
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

    const attention = hero.attention;
    const tableTitle = TABLE_TITLE[tab] ?? 'Corrective actions';
    const moreFilterCount = [filters.priority, filters.unassigned].filter(
        Boolean,
    ).length;
    const openAction = (id: number) =>
        router.visit(correctiveActionHref(id, { returnTo: pageUrl }));

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health and safety', href: '/health-safety' },
                {
                    title: 'Corrective actions',
                    href: '/health-safety/corrective-actions',
                },
            ]}
        >
            <Head title="Corrective actions" />
            <ConfirmDialog
                open={closing !== null}
                onClose={() => { if (!closeBusy) setClosing(null); }}
                frontline
                processing={closeBusy}
                variant="default"
                title="Close this checked action?"
                description={`This closes “${closing?.title ?? 'this action'}”. Closing the incident and its safety review are separate decisions.`}
                confirmText="Close action"
                cancelText="Keep it open"
                onConfirm={() => {
                    if (!closing) return;
                    setCloseBusy(true);
                    router.post(closing.href, {}, {
                        preserveScroll: true,
                        onFinish: () => { setCloseBusy(false); setClosing(null); },
                    });
                }}
            />

            <div className="flex w-full max-w-full min-w-0 flex-col gap-5">
                {/* ---- Header (pg-hsn-42) ---- */}
                <PageHeader
                    wrapTitle
                    icon={ListChecks}
                    title="Corrective actions"
                    subline="Agree the work, name an owner and check it’s done"
                    actions={
                        <>
                            <SafetyRegisterSearch
                                key={filters.q}
                                query={filters.q ?? ''}
                                onSearch={(q) => go({ q })}
                                placeholder="Search corrective actions"
                            />
                            {can.viewReports && (
                                <PageHeaderGlassButton
                                    icon={FileText}
                                    onClick={() =>
                                        router.visit(TRACEABILITY_REPORT)
                                    }
                                >
                                    Traceability report
                                </PageHeaderGlassButton>
                            )}
                        </>
                    }
                    strip={
                        <PageHeaderNumbers>
                            <PageHeaderNumber
                                value={attention.overdue}
                                label="Overdue"
                                caption="Past the due date"
                                tone={attention.overdue ? 'critical' : undefined}
                                pressed={tab === 'overdue'}
                                onClick={() => setTab('overdue')}
                            />
                            <PageHeaderNumber
                                value={attention.critical_open}
                                label="High or critical"
                                caption="Not yet checked or closed"
                                tone={
                                    attention.critical_open
                                        ? 'critical'
                                        : undefined
                                }
                                pressed={tab === 'critical_open'}
                                onClick={() => setTab('critical_open')}
                            />
                            <PageHeaderNumber
                                value={attention.monitoring_events}
                                label="Reviews in monitoring"
                                caption="With matching actions"
                                href={monitoringReviewUrl}
                            />
                        </PageHeaderNumbers>
                    }
                    filters={
                        <>
                            {sites.length > 0 && (
                                <EntityFilter
                                    label="Site"
                                    allLabel="All sites"
                                    items={sites}
                                    value={filters.site_id}
                                    onChange={(site_id) => go({ site_id })}
                                    onDark
                                />
                            )}
                            <PageHeaderFilterSelect
                                label="Action view"
                                value={tab}
                                options={[
                                    ...TABS,
                                    {
                                        id: 'critical_open',
                                        label: 'High / critical open',
                                    },
                                    {
                                        id: 'unassigned',
                                        label: 'Needs an owner',
                                    },
                                ].map((item) => ({
                                    value: item.id,
                                    label: `${item.label} (${tabCounts[item.id] ?? 0})`,
                                }))}
                                onChange={(id) => setTab(id)}
                            />
                            <SafetyDateFilter
                                label="Due"
                                from={filters.from}
                                to={filters.to}
                                onChange={go}
                            />
                            <Popover>
                                <PopoverTrigger asChild>
                                    <PageHeaderFilterButton
                                        icon={SlidersHorizontal}
                                        active={moreFilterCount > 0}
                                    >
                                        {moreFilterCount
                                            ? `More filters · ${moreFilterCount}`
                                            : 'More filters'}
                                    </PageHeaderFilterButton>
                                </PopoverTrigger>
                                <PopoverContent
                                    align="start"
                                    className="w-80 max-w-[calc(100vw-2rem)] space-y-4"
                                >
                                    <Field label="Priority">
                                        <SelectInput
                                            ariaLabel="Priority"
                                            placeholder="All priorities"
                                            value={filters.priority ?? 'all'}
                                            options={[
                                                {
                                                    value: 'all',
                                                    label: 'All priorities',
                                                },
                                                {
                                                    value: 'high,critical',
                                                    label: 'High / critical',
                                                },
                                                ...Object.entries(PRI).map(
                                                    ([value, { label }]) => ({
                                                        value,
                                                        label,
                                                    }),
                                                ),
                                            ]}
                                            onChange={(value) =>
                                                go({
                                                    priority:
                                                        value === 'all'
                                                            ? null
                                                            : value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <label className="flex items-center gap-2 text-sm font-medium">
                                        <Checkbox
                                            checked={!!filters.unassigned}
                                            onCheckedChange={(value) =>
                                                go({
                                                    unassigned:
                                                        value === true || null,
                                                })
                                            }
                                        />
                                        Without an owner
                                    </label>
                                </PopoverContent>
                            </Popover>
                            {hasFilters && (
                                <PageHeaderGlassButton
                                    icon={X}
                                    onClick={clearFilters}
                                >
                                    Clear filters
                                </PageHeaderGlassButton>
                            )}
                        </>
                    }
                    rail={
                        <SafetyWorkspaceRail hub="concerns" current="actions" />
                    }
                />

                <TierTwoTabs
                    tabs={[
                        { key: 'board', label: 'Board', icon: Columns3 },
                        { key: 'list', label: 'List', icon: List },
                    ]}
                    activeTab={view}
                    onTab={(key) => setView(key === 'list' ? 'list' : 'board')}
                    testIdPrefix="corrective-actions-view"
                    ariaLabel="Corrective action views"
                    renderLink={() => null}
                />

                {view === 'board' ? (
                    actions.data.length ? (
                        <ActionBoard
                            rows={actions.data}
                            tab={tab}
                            tabCounts={tabCounts}
                            unassigned={attention.unassigned}
                            total={actions.total}
                            morePages={actions.last_page > 1}
                            onOpen={openAction}
                            onMenu={openMenu}
                            onColumn={(id) => setTab(id, 'list')}
                        />
                    ) : (
                        <Surface>
                            <ActionTable
                                rows={[]}
                                canViewReports={!!can.viewReports}
                                onOpen={openAction}
                                onMenu={openMenu}
                                narrowed={tab !== 'all' || hasFilters}
                                onShowAll={showAll}
                            />
                        </Surface>
                    )
                ) : (
                    <>
                        <Surface
                            title={tableTitle}
                            sub="The verification view · right-click or ⋮ for the full lifecycle"
                        >
                            <div className="mt-3">
                                <ActionTable
                                    rows={actions.data}
                                    canViewReports={!!can.viewReports}
                                    onOpen={openAction}
                                    onMenu={openMenu}
                                    narrowed={tab !== 'all' || hasFilters}
                                    onShowAll={showAll}
                                />
                            </div>
                        </Surface>

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
                    </>
                )}
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
/*  Board (pg-hsn-42)                                                  */
/* ------------------------------------------------------------------ */

const PRIORITY_VARIANT: Record<string, StatusVariant> = {
    low: 'success',
    medium: 'warning',
    high: 'critical',
    critical: 'critical',
};

type BoardColumn = {
    key: string;
    title: string;
    /** The List view lens behind "N more". */
    tab: string;
    tone?: SgTone;
    sub?: string;
    count: number;
    match: (action: ActionRow) => boolean;
};

const DAY_MS = 86_400_000;

function needsOwner(action: ActionRow): boolean {
    return (
        !action.assigned_to_name &&
        action.status !== 'verified' &&
        action.status !== 'closed'
    );
}

/**
 * The actions by stage — Needs an owner, To do, In progress, Waiting to be checked, Checked and closed — so the
 * independent check before an action closes is a column you can see. Column counts are the register's real totals
 * (same filters); the cards are the ones on this page of the register, and "N more" opens the List on that lens.
 */
export function ActionBoard({
    rows,
    tab,
    tabCounts,
    unassigned,
    total,
    morePages,
    onOpen,
    onMenu,
    onColumn,
}: {
    rows: ActionRow[];
    tab: string;
    tabCounts: Record<string, number>;
    unassigned: number;
    total: number;
    morePages: boolean;
    onOpen: (id: number) => void;
    onMenu: (action: ActionRow, x: number, y: number) => void;
    onColumn: (tab: string) => void;
}) {
    const today = toDateInput(new Date());
    // Real totals only on the unfiltered lens; a lens (Overdue, a stage …) counts what it shows.
    const totals = tab === 'all';
    const columns: BoardColumn[] = [
        {
            key: 'unassigned',
            title: 'Needs an owner',
            tab: 'unassigned',
            tone: 'warning',
            count: tabCounts.unassigned ?? unassigned,
            match: needsOwner,
        },
        {
            key: 'open',
            title: 'To do',
            tab: 'open',
            count: tabCounts.open ?? 0,
            match: (action) => action.status === 'open' && !needsOwner(action),
        },
        {
            key: 'in_progress',
            title: 'In progress',
            tab: 'in_progress',
            count: tabCounts.in_progress ?? 0,
            match: (action) =>
                action.status === 'in_progress' && !needsOwner(action),
        },
        {
            key: 'completed',
            title: HS_LABELS.action_status.completed,
            tab: 'awaiting_verification',
            tone: 'info',
            sub: 'Someone other than the owner checks it',
            count: tabCounts.awaiting_verification ?? 0,
            match: (action) =>
                action.status === 'completed' && !needsOwner(action),
        },
        {
            key: 'checked',
            title: 'Checked and closed',
            tab:
                (tabCounts.verified ?? 0) >
                rows.filter((action) => action.status === 'verified').length
                    ? 'verified'
                    : 'closed',
            tone: 'success',
            count: (tabCounts.verified ?? 0) + (tabCounts.closed ?? 0),
            match: (action) =>
                action.status === 'verified' || action.status === 'closed',
        },
    ];
    const placed = new Set<number>();

    return (
        <section aria-label="Corrective actions board" className="grid gap-5">
            {morePages ? (
                <p className="text-sm text-muted-foreground">
                    The board shows the first {rows.length} of {total} matching
                    actions. Open the List for every one.
                </p>
            ) : null}
            <div className="grid items-start gap-5 md:grid-cols-3 xl:grid-cols-5">
                {columns.map((column) => {
                    const cards = rows.filter((action) => {
                        if (placed.has(action.id) || !column.match(action))
                            return false;
                        placed.add(action.id);
                        return true;
                    });
                    const count = totals ? column.count : cards.length;
                    const shown = cards.slice(0, 3);
                    const more = count - shown.length;
                    return (
                        <section
                            key={column.key}
                            aria-label={column.title}
                            className="grid gap-3 rounded-2xl border border-border bg-muted/40 p-3"
                        >
                            <div>
                                <div className="flex items-center gap-2">
                                    {column.tone ? (
                                        <Ring tone={column.tone} />
                                    ) : null}
                                    <h3 className="min-w-0 flex-1 text-sm font-semibold">
                                        {column.title}
                                    </h3>
                                    <span className="rounded-md bg-card px-1.5 text-caption font-semibold text-muted-foreground tabular-nums">
                                        {count}
                                    </span>
                                </div>
                                {column.sub ? (
                                    <p className="mt-1 text-caption text-muted-foreground">
                                        {column.sub}
                                    </p>
                                ) : null}
                            </div>
                            {shown.length ? (
                                <ul className="grid gap-2.5">
                                    {shown.map((action) => (
                                        <BoardCard
                                            key={action.id}
                                            action={action}
                                            today={today}
                                            onOpen={onOpen}
                                            onMenu={onMenu}
                                        />
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-caption text-muted-foreground">
                                    {count ? 'Not on this page' : 'Nothing here'}
                                </p>
                            )}
                            {more > 0 ? (
                                <Button
                                    variant="link"
                                    size="sm"
                                    className="h-auto justify-start px-0"
                                    onClick={() => onColumn(column.tab)}
                                >
                                    {more} more
                                </Button>
                            ) : null}
                        </section>
                    );
                })}
            </div>
        </section>
    );
}

function BoardCard({
    action,
    today,
    onOpen,
    onMenu,
}: {
    action: ActionRow;
    today: string;
    onOpen: (id: number) => void;
    onMenu: (action: ActionRow, x: number, y: number) => void;
}) {
    const overdue =
        action.is_overdue &&
        action.status !== 'verified' &&
        action.status !== 'closed';
    const daysOverdue =
        overdue && action.due_date
            ? Math.max(
                  1,
                  Math.round(
                      (Date.parse(today) - Date.parse(action.due_date)) /
                          DAY_MS,
                  ),
              )
            : 0;
    const context =
        overdue && daysOverdue
            ? `${daysOverdue} day${daysOverdue === 1 ? '' : 's'} overdue`
            : action.status === 'completed' && action.completed_at
              ? `done ${formatDate(action.completed_at)}`
              : (action.status === 'verified' || action.status === 'closed') &&
                  action.verified_at
                ? `checked ${formatDate(action.verified_at)}`
                : (action.event?.site_name ?? null);
    const due = action.due_date
        ? action.due_date === today
            ? 'Today'
            : formatDate(`${action.due_date}T00:00:00`)
        : null;
    const priority = PRI[action.priority] ?? PRI.medium;
    return (
        <li
            className="relative rounded-xl border border-border bg-card shadow-sm transition-colors focus-within:ring-2 focus-within:ring-ring hover:border-primary/40"
            onContextMenu={(event: ReactMouseEvent) => {
                event.preventDefault();
                onMenu(action, event.clientX, event.clientY);
            }}
        >
            {/* eslint-disable-next-line no-restricted-syntax -- the board card body is a custom selector-card layout that opens the action */}
            <button
                type="button"
                onClick={() => onOpen(action.id)}
                className="block w-full rounded-xl p-3 pr-9 text-left outline-none"
            >
                <span className="block text-sm font-semibold [overflow-wrap:anywhere] text-foreground">
                    {action.title}
                </span>
                <span className="mt-1 block text-caption text-muted-foreground">
                    {action.reference_number}
                    {context ? ` · ${context}` : ''}
                </span>
                <span className="mt-2.5 flex flex-wrap items-center gap-2">
                    {action.assigned_to_name ? (
                        <PersonChip name={action.assigned_to_name} />
                    ) : null}
                    <StatusBadge
                        size="sm"
                        variant={PRIORITY_VARIANT[action.priority] ?? 'neutral'}
                    >
                        {priority.label}
                    </StatusBadge>
                    {due ? (
                        <span
                            // A plain join: tailwind-merge reads text-caption as a colour and would drop it.
                            className={`ml-auto text-caption ${
                                overdue
                                    ? 'font-semibold text-status-critical'
                                    : due === 'Today'
                                      ? 'font-semibold text-foreground'
                                      : 'text-muted-foreground'
                            }`}
                        >
                            {due}
                        </span>
                    ) : null}
                </span>
            </button>
            {action.event ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={`Lifecycle actions for ${action.reference_number}`}
                    onClick={(event) => {
                        const rect =
                            event.currentTarget.getBoundingClientRect();
                        onMenu(action, rect.left, rect.bottom);
                    }}
                    className="frontline-hit absolute top-2 right-1.5 size-7 text-muted-foreground"
                >
                    <MoreVertical className="size-4" />
                </Button>
            ) : null}
        </li>
    );
}

/* ------------------------------------------------------------------ */
/*  Actions table                                                      */
/* ------------------------------------------------------------------ */

function ActionCard({
    action,
    onOpen,
    onMenu,
}: {
    action: ActionRow;
    onOpen: (
        id: number,
        opts?: { section?: EventSectionKey; action?: EventActionKey },
    ) => void;
    onMenu: (action: ActionRow, x: number, y: number) => void;
}) {
    const priority = PRI[action.priority] ?? PRI.medium;
    const stage = ACTION_STAGE[action.status] ?? ACTION_STAGE.open;
    const StageIcon = stage.icon;
    const eventStage = action.event
        ? (EVENT_STAGE[action.event.status] ?? EVENT_STAGE.open)
        : null;
    const awaiting = action.status === 'completed';
    const unassigned =
        !action.assigned_to_name &&
        action.status !== 'verified' &&
        action.status !== 'closed';
    const resolved = action.status === 'verified' || action.status === 'closed';

    const title = (
        <span className="min-w-0">
            <span className="block text-xs font-bold text-foreground">
                {action.reference_number}
            </span>
            <span className="block text-sm font-semibold [overflow-wrap:anywhere] whitespace-normal text-foreground">
                {action.title}
            </span>
        </span>
    );

    return (
        <li
            className={cn(
                'min-w-0 px-4 py-3',
                action.is_overdue ? 'bg-status-critical-bg/40' : '',
            )}
            onContextMenu={(event: ReactMouseEvent) => {
                event.preventDefault();
                onMenu(action, event.clientX, event.clientY);
            }}
        >
            <article className="min-w-0">
                <div className="flex min-w-0 items-start gap-2">
                    {action.event ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => onOpen(action.id)}
                            aria-label={`Open corrective action ${action.reference_number}`}
                            className="frontline-focus h-auto min-h-11 min-w-0 flex-1 justify-start px-0 py-1 text-left whitespace-normal hover:bg-transparent"
                        >
                            {title}
                        </Button>
                    ) : (
                        <div className="min-w-0 flex-1 py-1">{title}</div>
                    )}

                    {action.event ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label={`Lifecycle actions for ${action.reference_number}`}
                            onClick={(event) => {
                                const rect =
                                    event.currentTarget.getBoundingClientRect();
                                onMenu(action, rect.left, rect.bottom);
                            }}
                            className="frontline-focus frontline-tap shrink-0 text-muted-foreground"
                        >
                            <MoreVertical className="h-4 w-4" />
                        </Button>
                    ) : null}
                </div>

                <dl className="mt-3 grid min-w-0 grid-cols-2 gap-x-4 gap-y-3 text-xs">
                    <div className="min-w-0">
                        <dt className="font-medium text-muted-foreground">
                            Due
                        </dt>
                        <dd
                            className={cn(
                                'mt-1 flex items-center gap-1 font-bold',
                                action.is_overdue
                                    ? 'text-status-critical'
                                    : 'text-foreground',
                            )}
                        >
                            <Clock className="h-3.5 w-3.5 shrink-0" />
                            {formatDateOnly(action.due_date)}
                            {action.is_overdue ? ' · Overdue' : ''}
                        </dd>
                    </div>

                    <div className="min-w-0">
                        <dt className="font-medium text-muted-foreground">
                            Stage
                        </dt>
                        <dd className="mt-1">
                            <StatusBadge variant={stage.variant}>
                                <StageIcon className="h-3 w-3" />
                                {stage.label}
                            </StatusBadge>
                        </dd>
                    </div>

                    <div className="min-w-0">
                        <dt className="font-medium text-muted-foreground">
                            Priority
                        </dt>
                        <dd className="mt-1">
                            <span
                                className={cn(
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium',
                                    TONE_BG[priority.tone],
                                )}
                            >
                                {priority.label}
                            </span>
                        </dd>
                    </div>

                    <div className="min-w-0">
                        <dt className="font-medium text-muted-foreground">
                            Owner
                        </dt>
                        <dd className="mt-1 font-semibold [overflow-wrap:anywhere] text-foreground">
                            {action.assigned_to_name ?? 'Unassigned'}
                        </dd>
                    </div>

                    <div className="col-span-2 min-w-0">
                        <dt className="font-medium text-muted-foreground">
                            Parent event
                        </dt>
                        <dd className="mt-1 [overflow-wrap:anywhere] text-foreground">
                            {action.event ? (
                                <>
                                    <span className="font-semibold">
                                        {action.event.reference_number}
                                    </span>{' '}
                                    ·{' '}
                                    {hsLabel('review_category', action.event.event_category)}
                                    {action.event.site_name
                                        ? ` · ${action.event.site_name}`
                                        : ''}
                                </>
                            ) : (
                                'No parent event'
                            )}
                        </dd>
                    </div>
                </dl>

                <div className="mt-3 min-w-0">
                    <p className="text-xs font-medium text-muted-foreground">
                        Flags
                    </p>
                    <div className="mt-1 flex min-w-0 flex-wrap items-center gap-1.5">
                        <span className="inline-flex rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                            {hsLabel('action_type', action.action_type)}
                        </span>
                        {action.is_overdue ? (
                            <FlagBadge
                                icon={Clock}
                                tone="critical"
                                title="Past its due date"
                            >
                                Overdue
                            </FlagBadge>
                        ) : null}
                        {awaiting && action.evidence.load_state === 'loaded' ? (
                            <FlagBadge
                                icon={ShieldCheck}
                                tone="info"
                                title="Completed — needs a different person to verify"
                            >
                                Verify
                            </FlagBadge>
                        ) : awaiting ? (
                            <FlagBadge
                                icon={ShieldAlert}
                                tone="warning"
                                title="Completion evidence could not be loaded; verification is unavailable"
                            >
                                Evidence unavailable
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
                </div>

                {action.recommendation ? (
                    <p className="mt-3 text-xs [overflow-wrap:anywhere] text-muted-foreground">
                        Recommendation: {action.recommendation}
                    </p>
                ) : null}
                {action.source.type === 'control_room_task' ? (
                    <p className="mt-1 text-xs [overflow-wrap:anywhere] text-muted-foreground">
                        Transferred from Control Room task:{' '}
                        {action.source.title}
                    </p>
                ) : action.source.type === 'new_responsibility' ? (
                    <p className="mt-1 text-xs [overflow-wrap:anywhere] text-muted-foreground">
                        New responsibility:{' '}
                        {action.source.reason ?? 'Reason not recorded'}
                    </p>
                ) : null}
                {action.rework.latest_reason ? (
                    <p className="mt-1 text-xs [overflow-wrap:anywhere] text-status-warning">
                        Returned for rework: {action.rework.latest_reason}
                    </p>
                ) : null}
            </article>
        </li>
    );
}

export function ActionTable({
    rows,
    canViewReports,
    onOpen,
    onMenu,
    narrowed = false,
    onShowAll,
}: {
    rows: ActionRow[];
    canViewReports: boolean;
    /** True when a view (tab) or filter is narrowing the list. */
    narrowed?: boolean;
    onShowAll?: () => void;
    onOpen: (
        id: number,
        opts?: { section?: EventSectionKey; action?: EventActionKey },
    ) => void;
    onMenu: (action: ActionRow, x: number, y: number) => void;
}) {
    if (!rows.length && narrowed) {
        return (
            <div className="px-4 py-16 text-center">
                <Wrench className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                <p className="font-semibold text-foreground">
                    Nothing in this view
                </p>
                <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                    No actions match this view or your filters right now.
                </p>
                {onShowAll ? (
                    <div className="mt-4 flex justify-center">
                        <Button size="sm" variant="outline" onClick={onShowAll}>
                            Show all actions
                        </Button>
                    </div>
                ) : null}
            </div>
        );
    }
    if (!rows.length) {
        return (
            <div className="px-4 py-16 text-center">
                <Wrench className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                <p className="font-semibold text-foreground">
                    No corrective actions yet
                </p>
                <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                    Corrective and preventive actions are raised from a safety
                    review — open a review and add an action, or promote an
                    investigation recommendation. They'll appear here to drive,
                    verify and close.
                </p>
                <div className="mt-4 flex flex-wrap items-center justify-center gap-2">
                    <Button
                        size="sm"
                        onClick={() => router.visit('/health-safety/events')}
                    >
                        <ListChecks className="mr-1.5 h-4 w-4" /> Go to safety
                        reviews
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
        <>
            <ul
                aria-label="Corrective actions"
                className="min-w-0 divide-y divide-border md:hidden"
                data-test="corrective-action-cards"
            >
                {rows.map((action) => (
                    <ActionCard
                        key={action.id}
                        action={action}
                        onOpen={onOpen}
                        onMenu={onMenu}
                    />
                ))}
            </ul>

            <div
                role="region"
                aria-label="Corrective actions table"
                tabIndex={0}
                className="relative hidden max-w-full overflow-x-auto overscroll-x-contain focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring md:block"
                data-test="corrective-action-table-scroll"
            >
                <table className="w-full min-w-[1080px] text-sm">
                    <thead className="bg-muted/70">
                        <tr className="border-b border-border text-left text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                            <th className="px-4 py-3">Due</th>
                            <th className="px-4 py-3">Action</th>
                            <th className="px-4 py-3">Stage</th>
                            <th className="px-4 py-3">Priority</th>
                            <th className="px-4 py-3">Owner</th>
                            <th className="px-4 py-3">Parent event</th>
                            <th className="px-4 py-3">Flags</th>
                            <th className="sticky right-0 bg-muted px-4 py-3">
                                <span className="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                        {rows.map((action) => {
                            const priority = PRI[action.priority] ?? PRI.medium;
                            const stage =
                                ACTION_STAGE[action.status] ??
                                ACTION_STAGE.open;
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
                                if (action.event) onOpen(action.id);
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
                                            ? `Open corrective action ${action.reference_number}`
                                            : undefined
                                    }
                                    onKeyDown={(e) => {
                                        if (
                                            action.event &&
                                            e.target === e.currentTarget &&
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
                                            {formatDateOnly(action.due_date)}
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
                                                {action.rework.latest_reason ? (
                                                    <span className="mt-1 block max-w-[24rem] text-[11px] text-status-warning">
                                                        Returned for rework:{' '}
                                                        {
                                                            action.rework
                                                                .latest_reason
                                                        }
                                                    </span>
                                                ) : null}
                                                <span className="mt-1 inline-flex rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                                                    {hsLabel('action_type', action.action_type)}
                                                </span>
                                            </span>
                                        </div>
                                    </td>

                                    {/* Priority */}
                                    <td className="px-4 py-3 align-top">
                                        <StatusBadge variant={stage.variant}>
                                            <StageIcon className="h-3 w-3" />
                                            {stage.label}
                                        </StatusBadge>
                                    </td>

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
                                                        {
                                                            action.assigned_to_name
                                                        }
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
                                                        {hsLabel('review_category', action.event.event_category)}
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
                                            {awaiting &&
                                            action.evidence.load_state ===
                                                'loaded' ? (
                                                <FlagBadge
                                                    icon={ShieldCheck}
                                                    tone="info"
                                                    title="Completed — needs a different person to verify"
                                                >
                                                    Verify
                                                </FlagBadge>
                                            ) : awaiting ? (
                                                <FlagBadge
                                                    icon={ShieldAlert}
                                                    tone="warning"
                                                    title="Completion evidence could not be loaded; verification is unavailable"
                                                >
                                                    Evidence unavailable
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
                                                    {resolved
                                                        ? 'Resolved'
                                                        : '—'}
                                                </span>
                                            ) : null}
                                        </div>
                                    </td>

                                    {/* Kebab — same payload as right-click (a11y / discoverability) */}
                                    <td className="sticky right-0 bg-card px-2 py-3 text-right align-top">
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
                                                className="frontline-hit inline-grid h-7 w-7 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
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
        </>
    );
}
