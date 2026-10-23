import {
    EVENT_CATEGORY_LABELS,
    EventDetailDialog,
    worksafeLabel,
    type EventActionKey,
    type EventDetail,
    type EventSectionKey,
    type WorksafeState,
} from '@/components/health-safety/event-detail-dialog';
import {
    SafetyDateFilter,
    SafetyRegisterSearch,
    SafetyWorkspaceRail,
} from '@/components/health-safety/workspace-header';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterCheck,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page';
import {
    EntityFilter,
    ShiftContextMenu,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { safetyReviewHref } from '@/lib/health-safety/navigation';
import { fmt, type Tone } from '@/pages/health-safety/components/hs-hero-kit';
import {
    FlagBadge,
    RegisterTableHeader,
    TONE_BG,
    TONE_DOT,
    entityTone,
    initials,
    titleCase,
} from '@/pages/health-safety/components/register-row-kit';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    FileText,
    Flame,
    FlaskConical,
    Hand,
    HeartPulse,
    LayoutList,
    Link2,
    ListChecks,
    MoreVertical,
    MousePointer2,
    Search,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Truck,
    Wrench,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useState, type MouseEvent as ReactMouseEvent } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

export type EventRow = {
    id: number;
    reference_number: string;
    event_category: string;
    severity: string;
    status: string;
    occurred_at: string | null;
    reported_at: string | null;
    site_name: string | null;
    client_name: string | null;
    staff_name: string | null;
    worksafe_notifiable: boolean | null;
    worksafe_status: string | null;
    worksafe_decision_signed: boolean;
    handover: {
        status: string;
        owner: { id: number; name: string } | null;
        accepted_by: { id: number; name: string } | null;
        accepted_at: string | null;
    };
    investigation_required: boolean;
    source: {
        type: string;
        id: number;
        label: string;
        url: string | null;
        unwired: boolean;
    } | null;
    flags: {
        investigation_overdue: boolean;
        awaiting_verification: number;
        worksafe_pending: boolean;
        unwired: boolean;
    };
    has_investigation: boolean;
    has_open_actions: boolean;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
};

type Filters = {
    q: string | null;
    tab: string;
    severity: string | null;
    category: string | null;
    source: string | null;
    site_id: number | null;
    worksafe: boolean | null;
    from: string | null;
    to: string | null;
    action_context?: {
        q?: string;
        priority?: string;
        unassigned?: boolean | number;
        site_id?: number;
        from?: string;
        to?: string;
        tab?: string;
    } | null;
};

type Props = {
    events: Paginated<EventRow>;
    tab: string;
    tabCounts: Record<string, number>;
    hero: {
        live: {
            open: number;
            investigating: number;
            corrective_action: number;
            monitoring: number;
        };
        attention: {
            investigation_due: number;
            awaiting_verification: number;
            worksafe_due: number;
            handover_due: number;
            closed_period: number;
        };
    };
    filters: Filters;
    sites: Array<{ id: number; name: string }>;
    detail: EventDetail | null;
    can: { manage: boolean };
    actionReturnUrl?: string | null;
};

/* ------------------------------------------------------------------ */
/*  Token maps (events-specific; shared chrome lives in the kits)      */
/* ------------------------------------------------------------------ */

const SEV: Record<string, { tone: Tone; label: string }> = {
    low: { tone: 'success', label: 'Low' },
    medium: { tone: 'warning', label: 'Medium' },
    high: { tone: 'critical', label: 'High' },
    critical: { tone: 'critical', label: 'Critical' },
};

const STAGE: Record<string, { label: string; cls: string; icon: LucideIcon }> =
    {
        open: {
            label: 'Open',
            cls: 'bg-status-info-bg text-status-info',
            icon: AlertTriangle,
        },
        investigating: {
            label: 'Investigating',
            cls: 'bg-primary/10 text-primary',
            icon: Search,
        },
        corrective_action: {
            label: 'Corrective action',
            cls: 'bg-status-warning-bg text-status-warning',
            icon: ListChecks,
        },
        monitoring: {
            label: 'Monitoring',
            cls: 'bg-status-success-bg text-status-success',
            icon: Activity,
        },
        closed: {
            label: 'Closed',
            cls: 'bg-status-success-bg text-status-success',
            icon: CheckCircle2,
        },
    };

/** Originating module (HsEvent.source_type class basename) → label + icon for the
 *  convergence column. */
const SOURCE_MODULE: Record<string, { label: string; icon: LucideIcon }> = {
    ClientIncident: { label: 'Incidents', icon: ShieldAlert },
    SafeguardingConcern: { label: 'Safeguarding', icon: ShieldCheck },
    FleetIncident: { label: 'Fleet & Assets', icon: Truck },
    WorkplaceInjury: { label: 'Injuries', icon: HeartPulse },
    SubstanceExposureRecord: { label: 'Exposure', icon: FlaskConical },
    SiteHazard: { label: 'Site hazards', icon: AlertTriangle },
    SiteInspectionRecord: { label: 'Inspection', icon: ClipboardCheck },
    FleetWorkOrder: { label: 'Equipment', icon: Wrench },
    RestraintEvent: { label: 'Restraints', icon: Hand },
    EmergencyDrill: { label: 'Drills', icon: Flame },
};

const SOURCE_OPTIONS = [
    { value: 'incidents', label: 'Incidents' },
    { value: 'safeguarding', label: 'Safeguarding' },
    { value: 'fleet', label: 'Fleet & Assets' },
    { value: 'injuries', label: 'Injuries' },
    { value: 'exposure', label: 'Exposure' },
    { value: 'site_hazards', label: 'Site hazards' },
    { value: 'inspection', label: 'Inspection' },
    { value: 'equipment', label: 'Equipment' },
    { value: 'restraints', label: 'Restraints' },
    { value: 'drills', label: 'Drills' },
];

const CATEGORY_OPTIONS = [
    'incident',
    'near_miss',
    'hazard',
    'injury',
    'exposure',
    'restraint',
    'safeguarding',
    'vehicle_incident',
    'drill_failure',
    'inspection_failure',
    'equipment_fault',
];

/** The five governance board reports surfaced from the hero CTA popover. */
const BOARD_REPORTS = [
    { label: 'Board summary', href: '/health-safety/reports?view=board' },
    {
        label: 'WorkSafe register',
        href: '/health-safety/reports?view=notifications',
    },
    {
        label: 'Investigation outcomes',
        href: '/health-safety/reports?view=investigation-outcomes',
    },
    {
        label: 'Corrective-action traceability',
        href: '/health-safety/reports?view=action-traceability',
    },
    {
        label: 'Risk-assessment register',
        href: '/health-safety/reports?view=risk-register',
    },
];

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function HsEventsIndex({
    events,
    tab,
    tabCounts,
    hero,
    filters,
    sites,
    detail,
    can,
    actionReturnUrl,
}: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [pendingSection] = useState<EventSectionKey>('overview');
    const [pendingAction] = useState<EventActionKey | null>(null);

    // Board-report routes are gated on governance.view; hide the launcher for
    // register-only roles (Team Lead, H&S Officer, …) so they don't hit a 403.
    const canViewBoardReports =
        usePage<SharedData>().props.auth.can?.governance?.view ?? false;

    const go = (next: Partial<Filters>) =>
        router.get(
            '/health-safety/events',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const setTab = (id: string) =>
        router.get(
            '/health-safety/events',
            { ...filters, tab: id },
            { preserveScroll: true },
        );

    const pageUrl = usePage().url;
    // New work opens the permanent record; retained ?event= links remain compatible.
    const openEvent = (
        id: number,
        opts?: { section?: EventSectionKey; action?: EventActionKey },
    ) => {
        router.visit(safetyReviewHref(id, { ...opts, returnTo: pageUrl }));
    };
    const closeDetail = () =>
        router.get(
            '/health-safety/events',
            { ...filters },
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );

    const clearFilters = () =>
        router.get(
            '/health-safety/events',
            { tab },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const hasFilters = !!(
        filters.q ||
        filters.severity ||
        filters.category ||
        filters.source ||
        filters.site_id ||
        filters.worksafe ||
        filters.from ||
        filters.to ||
        filters.action_context
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
            icon: AlertTriangle,
            tone: 'info',
            badge: tabCounts.open || undefined,
        },
        {
            id: 'investigating',
            label: 'Investigating',
            icon: Search,
            tone: 'primary',
            badge: tabCounts.investigating || undefined,
        },
        {
            id: 'corrective_actions',
            label: 'Corrective actions',
            icon: ListChecks,
            tone: 'warning',
            badge: tabCounts.corrective_actions || undefined,
        },
        {
            id: 'handover',
            label: 'Awaiting handover',
            icon: ShieldCheck,
            tone: 'warning',
            badge: tabCounts.handover || undefined,
        },
        {
            id: 'worksafe',
            label: 'WorkSafe-notifiable',
            icon: ShieldAlert,
            tone: 'critical',
            badge: tabCounts.worksafe || undefined,
        },
        {
            id: 'monitoring',
            label: 'Monitoring',
            icon: Activity,
            tone: 'success',
            badge: tabCounts.monitoring || undefined,
        },
        {
            id: 'closed',
            label: 'Closed',
            icon: CheckCircle2,
            tone: 'success',
            badge: tabCounts.closed || undefined,
        },
    ];

    /* ---- right-click context menu (mirrors the dialog Options bar) ---- */
    const openRowCtx = (e: ReactMouseEvent, ev: EventRow) => {
        e.preventDefault();
        e.stopPropagation();
        const anchor = e.currentTarget.getBoundingClientRect();
        const sev = SEV[ev.severity] ?? SEV.low;
        const items: ShiftCtxItem[] = [
            {
                icon: <Shield className="h-3.5 w-3.5" />,
                label: 'Open safety review',
                sub: ev.reference_number,
                tone: 'primary',
                onClick: () => openEvent(ev.id),
            },
            {
                icon: <Search className="h-3.5 w-3.5" />,
                label: 'Investigation',
                onClick: () => openEvent(ev.id, { section: 'investigation' }),
            },
            {
                icon: <ListChecks className="h-3.5 w-3.5" />,
                label: 'Corrective actions',
                onClick: () => openEvent(ev.id, { section: 'actions' }),
            },
        ];
        if (ev.source?.url) {
            items.push({
                icon: <Link2 className="h-3.5 w-3.5" />,
                label: 'View originating record',
                sub: ev.source.label,
                onClick: () => router.visit(ev.source!.url!),
            });
        }
        if (ev.handover.status === 'awaiting_acceptance') {
            items.push({
                icon: <ShieldCheck className="h-3.5 w-3.5" />,
                label: 'Review H&S handover',
                sub: 'Acceptance required',
                tone: 'critical',
                onClick: () => openEvent(ev.id, { section: 'handover' }),
            });
        }
        const worksafe = worksafeState(ev);
        const canDecideWorksafe =
            can.manage &&
            ev.status !== 'closed' &&
            ['accepted', 'not_required'].includes(ev.handover.status);
        const needsSignedWorksafeDecision = !ev.worksafe_decision_signed;
        if (canDecideWorksafe) {
            items.push({
                icon: needsSignedWorksafeDecision ? (
                    <ShieldAlert className="h-3.5 w-3.5" />
                ) : (
                    <ShieldCheck className="h-3.5 w-3.5" />
                ),
                label: needsSignedWorksafeDecision
                    ? 'Record WorkSafe decision'
                    : 'Update WorkSafe decision',
                sub: worksafeLabel(worksafe),
                tone: needsSignedWorksafeDecision ? 'critical' : undefined,
                onClick: () =>
                    openEvent(ev.id, { action: 'worksafe_decision' }),
            });
        }
        if (
            ev.worksafe_notifiable === true &&
            ev.worksafe_decision_signed &&
            can.manage &&
            ev.worksafe_status !== 'acknowledged'
        ) {
            if (ev.worksafe_status === 'notified') {
                items.push({
                    icon: <ShieldCheck className="h-3.5 w-3.5" />,
                    label: 'Record WorkSafe acknowledgement',
                    onClick: () =>
                        openEvent(ev.id, { action: 'worksafe_acknowledge' }),
                });
            } else {
                items.push({
                    icon: <ShieldAlert className="h-3.5 w-3.5" />,
                    label: 'Record WorkSafe notification',
                    tone: 'critical',
                    onClick: () =>
                        openEvent(ev.id, { action: 'worksafe_notify' }),
                });
            }
        } else if (!canDecideWorksafe) {
            items.push({
                icon:
                    ev.worksafe_notifiable === false ||
                    ev.worksafe_status === 'acknowledged' ? (
                        <ShieldCheck className="h-3.5 w-3.5" />
                    ) : (
                        <ShieldAlert className="h-3.5 w-3.5" />
                    ),
                label: 'WorkSafe status',
                sub: worksafeLabel(worksafe),
                onClick: () => openEvent(ev.id, { section: 'overview' }),
            });
        }
        if (can.manage && ev.status !== 'closed' && !ev.has_investigation) {
            items.push({
                icon: <Search className="h-3.5 w-3.5" />,
                label: 'Start investigation',
                onClick: () => openEvent(ev.id, { action: 'investigation' }),
            });
        }
        if (can.manage && ev.status !== 'closed') {
            items.push({
                icon: <ListChecks className="h-3.5 w-3.5" />,
                label: 'Add corrective action',
                onClick: () => openEvent(ev.id, { action: 'add_action' }),
            });
        }
        if (can.manage && ev.status !== 'closed') {
            items.push({
                icon: <CheckCircle2 className="h-3.5 w-3.5" />,
                label: 'Close safety review',
                tone: 'critical',
                onClick: () => openEvent(ev.id, { action: 'close' }),
            });
        }
        setCtx({
            x: e.clientX || anchor.left,
            y: e.clientY || anchor.bottom,
            tag: sev.label.toUpperCase(),
            meta: `${ev.reference_number} · ${EVENT_CATEGORY_LABELS[ev.event_category] ?? titleCase(ev.event_category)}`,
            items,
        });
    };

    const live = hero.live;
    const at = hero.attention;
    const tableTitle =
        {
            all: 'All safety reviews',
            open: 'Open safety reviews',
            investigating: 'Under investigation',
            corrective_actions: 'In corrective action',
            handover: 'Awaiting H&S acceptance',
            worksafe: 'WorkSafe-notifiable',
            monitoring: 'Monitoring',
            closed: 'Closed safety reviews',
            investigation_due: 'Investigation needed',
            awaiting_verification: 'Actions awaiting verification',
            worksafe_pending: 'WorkSafe pending',
        }[tab] ?? 'Safety reviews';
    const showOrphanNote =
        (tab === 'all' || tab === 'open') &&
        events.data.some((ev) => ev.flags.unwired);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Safety reviews', href: '/health-safety/events' },
            ]}
        >
            <Head title="Safety reviews" />

            <div className="flex min-w-0 flex-col gap-5">
                {/* ---- Hero ---- */}
                <PageHeader
                    frontline
                    wrapTitle
                    icon={ShieldCheck}
                    title="Safety reviews"
                    subline="Investigate, agree actions and check that people are safe"
                    mobileSummary={`${at.handover_due} awaiting acceptance · ${at.investigation_due} investigations needed`}
                    actions={
                        <>
                            <SafetyRegisterSearch
                                key={filters.q}
                                query={filters.q ?? ''}
                                onSearch={(q) => go({ q })}
                                placeholder="Search safety reviews"
                            />
                            {actionReturnUrl && (
                                <PageHeaderGlassButton
                                    onClick={() =>
                                        router.visit(actionReturnUrl)
                                    }
                                >
                                    Back to actions
                                </PageHeaderGlassButton>
                            )}
                            {canViewBoardReports && (
                                <Popover>
                                    <PopoverTrigger asChild>
                                        <PageHeaderGlassButton icon={FileText}>
                                            Reports
                                        </PageHeaderGlassButton>
                                    </PopoverTrigger>
                                    <PopoverContent
                                        align="end"
                                        className="w-72 max-w-[calc(100vw-2rem)] p-1.5"
                                    >
                                        {BOARD_REPORTS.map((report) => (
                                            <Button
                                                key={report.href}
                                                unstyled
                                                className="frontline-tap frontline-focus flex w-full items-center rounded-md p-2.5 text-left text-sm hover:bg-muted"
                                                onClick={() =>
                                                    router.visit(report.href)
                                                }
                                            >
                                                {report.label}
                                            </Button>
                                        ))}
                                    </PopoverContent>
                                </Popover>
                            )}
                        </>
                    }
                    meters={
                        <div className="grid w-full grid-cols-2 gap-px lg:grid-cols-3 xl:grid-cols-6">
                            {[
                                {
                                    key: 'open',
                                    label: 'Open',
                                    value: live.open,
                                    caption: 'Awaiting progress',
                                },
                                {
                                    key: 'investigation_due',
                                    label: 'Investigation needed',
                                    value: at.investigation_due,
                                    caption: 'Not yet completed',
                                },
                                {
                                    key: 'awaiting_verification',
                                    label: 'Awaiting verification',
                                    value: at.awaiting_verification,
                                    caption: 'Reviews with completed actions',
                                },
                                {
                                    key: 'worksafe_pending',
                                    label: 'WorkSafe pending',
                                    value: at.worksafe_due,
                                    caption: 'Awaiting notification',
                                },
                                {
                                    key: 'handover',
                                    label: 'Awaiting acceptance',
                                    value: at.handover_due,
                                    caption: 'H&S handover',
                                },
                                {
                                    key: 'monitoring',
                                    label: 'Monitoring',
                                    value: live.monitoring,
                                    caption: 'Check the outcome',
                                },
                            ].map((meter) => (
                                <PageHeaderMeterBlock
                                    key={meter.key}
                                    label={meter.label}
                                    pressed={tab === meter.key}
                                    onClick={() => setTab(meter.key)}
                                    tone={
                                        meter.key === 'worksafe_pending' &&
                                        meter.value > 0
                                            ? 'critical'
                                            : meter.key === 'handover' &&
                                                meter.value > 0
                                              ? 'warning'
                                              : 'brand'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {fmt(meter.value)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {meter.caption}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ))}
                        </div>
                    }
                    filters={
                        <>
                            <PageHeaderFilterSelect
                                label="Review view"
                                value={tab}
                                options={[
                                    ...TABS,
                                    {
                                        id: 'investigation_due',
                                        label: 'Investigation needed',
                                    },
                                    {
                                        id: 'awaiting_verification',
                                        label: 'Actions awaiting verification',
                                    },
                                    {
                                        id: 'worksafe_pending',
                                        label: 'WorkSafe pending',
                                    },
                                ].map((item) => ({
                                    value: item.id,
                                    label: `${item.label} (${tabCounts[item.id] ?? 0})`,
                                }))}
                                onChange={setTab}
                            />
                            <SafetyDateFilter
                                label="Occurred"
                                from={filters.from}
                                to={filters.to}
                                onChange={go}
                            />
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
                                label="Category"
                                value={filters.category ?? 'all'}
                                options={[
                                    { value: 'all', label: 'All categories' },
                                    ...CATEGORY_OPTIONS.map((value) => ({
                                        value,
                                        label:
                                            EVENT_CATEGORY_LABELS[value] ??
                                            titleCase(value),
                                    })),
                                ]}
                                onChange={(value) =>
                                    go({
                                        category:
                                            value === 'all' ? null : value,
                                    })
                                }
                            />
                            <PageHeaderFilterSelect
                                label="Source"
                                value={filters.source ?? 'all'}
                                options={[
                                    { value: 'all', label: 'All sources' },
                                    ...SOURCE_OPTIONS,
                                ]}
                                onChange={(value) =>
                                    go({
                                        source: value === 'all' ? null : value,
                                    })
                                }
                            />
                            <PageHeaderFilterSelect
                                label="Severity"
                                value={filters.severity ?? 'all'}
                                options={[
                                    { value: 'all', label: 'All severities' },
                                    ...Object.entries(SEV).map(
                                        ([value, { label }]) => ({
                                            value,
                                            label,
                                        }),
                                    ),
                                ]}
                                onChange={(value) =>
                                    go({
                                        severity:
                                            value === 'all' ? null : value,
                                    })
                                }
                            />
                            <PageHeaderFilterCheck
                                label="WorkSafe-notifiable"
                                checked={!!filters.worksafe}
                                onChange={(value) =>
                                    go({ worksafe: value || null })
                                }
                            />
                            {filters.action_context && (
                                <Popover>
                                    <PopoverTrigger asChild>
                                        <PageHeaderFilterButton active>
                                            Matching actions
                                        </PageHeaderFilterButton>
                                    </PopoverTrigger>
                                    <PopoverContent className="w-80 max-w-[calc(100vw-2rem)] space-y-3 text-sm">
                                        <p>
                                            Reviews with actions matching these
                                            filters:
                                        </p>
                                        <dl className="space-y-2">
                                            <div>
                                                <dt className="text-muted-foreground">
                                                    Search
                                                </dt>
                                                <dd>
                                                    {filters.action_context.q ||
                                                        'All actions'}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-muted-foreground">
                                                    Priority
                                                </dt>
                                                <dd>
                                                    {filters.action_context
                                                        .priority
                                                        ? titleCase(
                                                              filters.action_context.priority.replaceAll(
                                                                  ',',
                                                                  ' / ',
                                                              ),
                                                          )
                                                        : 'All priorities'}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-muted-foreground">
                                                    Site
                                                </dt>
                                                <dd>
                                                    {sites.find(
                                                        (site) =>
                                                            site.id ===
                                                            Number(
                                                                filters
                                                                    .action_context
                                                                    ?.site_id,
                                                            ),
                                                    )?.name ??
                                                        (filters.action_context
                                                            .site_id
                                                            ? 'Selected site'
                                                            : 'All permitted sites')}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="text-muted-foreground">
                                                    Action due date
                                                </dt>
                                                <dd>
                                                    {filters.action_context.from
                                                        ? formatDateOnly(
                                                              filters
                                                                  .action_context
                                                                  .from,
                                                          )
                                                        : 'Any start'}{' '}
                                                    –{' '}
                                                    {filters.action_context.to
                                                        ? formatDateOnly(
                                                              filters
                                                                  .action_context
                                                                  .to,
                                                          )
                                                        : 'Any end'}
                                                </dd>
                                            </div>
                                            {filters.action_context
                                                .unassigned ? (
                                                <p>
                                                    Only work without an owner
                                                </p>
                                            ) : null}
                                        </dl>
                                        <Button
                                            className="frontline-tap"
                                            variant="outline"
                                            onClick={() =>
                                                go({ action_context: null })
                                            }
                                        >
                                            Clear matching-action filter
                                        </Button>
                                    </PopoverContent>
                                </Popover>
                            )}
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
                        <SafetyWorkspaceRail hub="actions" current="reviews" />
                    }
                />

                {/* ---- Table ---- */}
                <section className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                    <RegisterTableHeader
                        icon={Shield}
                        title={tableTitle}
                        subtitle="Investigation, follow-through and closure"
                        hint="Open a review or use its options"
                        hintIcon={MousePointer2}
                    />
                    {showOrphanNote ? <OrphanNotice /> : null}
                    <EventTable
                        rows={events.data}
                        onRowCtx={openRowCtx}
                        onOpen={openEvent}
                    />
                </section>

                {events.last_page > 1 ? (
                    <LaravelPagination links={events.links} />
                ) : null}
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
                />
            ) : null}
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Events-specific row helpers                                        */
/* ------------------------------------------------------------------ */

function OrphanNotice() {
    return (
        <div className="flex items-start gap-3 rounded-2xl border border-status-warning/35 bg-status-warning-bg px-4 py-3 text-status-warning">
            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-status-warning text-primary-foreground">
                <AlertTriangle className="h-4 w-4" />
            </span>
            <p className="text-sm leading-6">
                Some reviews have no linked source record. Check their history
                before using them to confirm what happened.
            </p>
        </div>
    );
}

const SOURCE_CHIP: Record<string, string> = {
    ClientIncident: 'bg-status-critical-bg text-status-critical',
    SafeguardingConcern: 'bg-status-info-bg text-status-info',
    FleetIncident: 'bg-status-warning-bg text-status-warning',
    WorkplaceInjury: 'bg-status-critical-bg text-status-critical',
    SubstanceExposureRecord: 'bg-status-warning-bg text-status-warning',
    SiteHazard: 'bg-status-warning-bg text-status-warning',
    SiteInspectionRecord: 'bg-status-info-bg text-status-info',
    FleetWorkOrder: 'bg-status-warning-bg text-status-warning',
    RestraintEvent: 'bg-status-critical-bg text-status-critical',
    EmergencyDrill: 'bg-status-info-bg text-status-info',
};

function formatWhenCompact(value: string | null): {
    main: string;
    title: string;
} {
    return {
        main: formatDateTime(value),
        title: value ? formatDateTime(value) : 'No occurrence time recorded',
    };
}
function eventContext(ev: EventRow): string {
    if (ev.source?.label) return ev.source.label;
    if (ev.staff_name) return `Reported by ${ev.staff_name}`;
    if (ev.client_name) return ev.client_name;
    if (ev.site_name) return ev.site_name;
    if (ev.worksafe_notifiable) return 'WorkSafe-notifiable event';
    return 'Governance event';
}

function worksafeState(ev: EventRow): WorksafeState {
    return {
        notifiable: ev.worksafe_notifiable,
        status: ev.worksafe_status,
        decision_signed: ev.worksafe_decision_signed,
    };
}

function worksafeFlagTone(
    worksafe: WorksafeState,
): 'critical' | 'warning' | 'success' | 'info' | 'neutral' {
    if (worksafe.notifiable === null || worksafe.decision_signed === false)
        return 'warning';
    if (worksafe.notifiable === false || worksafe.status === 'acknowledged')
        return 'success';
    if (!worksafe.status || worksafe.status === 'pending') return 'critical';
    return 'warning';
}

function sourceRef(ev: EventRow): string {
    if (!ev.source) return 'No source';
    const prefix =
        {
            ClientIncident: 'INC',
            SafeguardingConcern: 'SG',
            FleetIncident: 'FI',
            WorkplaceInjury: 'WI',
            SubstanceExposureRecord: 'EX',
            SiteInspectionRecord: 'SI',
            FleetWorkOrder: 'WO',
            EmergencyDrill: 'DR',
            SiteHazard: 'HZ',
            RestraintEvent: 'RE',
        }[ev.source.type] ?? 'SRC';
    return `${prefix}-${ev.source.id}`;
}

/* ------------------------------------------------------------------ */
/*  Events table                                                       */
/* ------------------------------------------------------------------ */

export function EventTable({
    rows,
    onRowCtx,
    onOpen,
}: {
    rows: EventRow[];
    onRowCtx: (e: ReactMouseEvent, ev: EventRow) => void;
    onOpen: (id: number) => void;
}) {
    if (!rows.length) {
        return (
            <div className="px-4 py-16 text-center">
                <Shield className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                <p className="font-medium text-muted-foreground">
                    No safety reviews match
                </p>
                <p className="mt-1 text-sm text-muted-foreground/70">
                    Nothing matches this tab and filters.
                </p>
            </div>
        );
    }
    return (
        <>
            <ul
                aria-label="Safety reviews"
                className="min-w-0 divide-y divide-border md:hidden"
            >
                {rows.map((ev) => (
                    <li key={ev.id} className="min-w-0 space-y-4 p-4">
                        <div className="flex items-start gap-3">
                            <Button
                                unstyled
                                className="frontline-tap frontline-focus min-w-0 flex-1 text-left text-sm font-semibold text-primary"
                                onClick={() => onOpen(ev.id)}
                                aria-label={`Open safety review ${ev.reference_number}`}
                            >
                                {ev.reference_number} ·{' '}
                                {EVENT_CATEGORY_LABELS[ev.event_category] ??
                                    titleCase(ev.event_category)}
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="frontline-tap size-11 shrink-0"
                                aria-label={`Options for ${ev.reference_number}`}
                                onClick={(event) => onRowCtx(event, ev)}
                            >
                                <MoreVertical aria-hidden className="size-4" />
                            </Button>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {eventContext(ev)}
                        </p>
                        <dl className="grid grid-cols-2 gap-3 text-sm">
                            <div className="col-span-2">
                                <dt className="text-xs text-muted-foreground">
                                    {ev.occurred_at ? 'Occurred' : 'Reported'}
                                </dt>
                                <dd>
                                    {formatDateTime(
                                        ev.occurred_at ?? ev.reported_at,
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    Site / person
                                </dt>
                                <dd>
                                    {ev.client_name ??
                                        ev.staff_name ??
                                        'No linked person'}
                                    <span className="block text-xs text-muted-foreground">
                                        {ev.site_name ?? 'No site recorded'}
                                    </span>
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    Review owner
                                </dt>
                                <dd>
                                    {ev.handover.owner?.name ??
                                        'Needs an owner'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    Severity
                                </dt>
                                <dd>
                                    <StatusBadge status={ev.severity} />
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-muted-foreground">
                                    Stage
                                </dt>
                                <dd>
                                    <StatusBadge
                                        status={ev.status}
                                        label={
                                            STAGE[ev.status]?.label ??
                                            titleCase(ev.status)
                                        }
                                    />
                                </dd>
                            </div>
                            <div className="col-span-2">
                                <dt className="text-xs text-muted-foreground">
                                    WorkSafe
                                </dt>
                                <dd>{worksafeLabel(worksafeState(ev))}</dd>
                            </div>
                            <div className="col-span-2">
                                <dt className="text-xs text-muted-foreground">
                                    Source
                                </dt>
                                <dd>
                                    {ev.source?.label ??
                                        'No linked source record'}
                                </dd>
                            </div>
                        </dl>
                        <div
                            className="flex flex-wrap gap-2"
                            aria-label="Review flags"
                        >
                            {ev.handover.status === 'awaiting_acceptance' && (
                                <FlagBadge
                                    icon={ShieldCheck}
                                    tone="warning"
                                    title="H&S acceptance required"
                                >
                                    Awaiting acceptance
                                </FlagBadge>
                            )}
                            {ev.handover.status === 'accepted' && (
                                <FlagBadge
                                    icon={ShieldCheck}
                                    tone="info"
                                    title={
                                        ev.handover.accepted_by
                                            ? `Accepted by ${ev.handover.accepted_by.name}`
                                            : 'H&S handover accepted'
                                    }
                                >
                                    Accepted
                                    {ev.handover.accepted_by
                                        ? ` by ${ev.handover.accepted_by.name}`
                                        : ''}
                                </FlagBadge>
                            )}
                            {ev.flags.investigation_overdue && (
                                <FlagBadge
                                    icon={Clock}
                                    tone="critical"
                                    title="Investigation overdue"
                                >
                                    Investigation overdue
                                </FlagBadge>
                            )}
                            {ev.investigation_required &&
                                !ev.has_investigation && (
                                    <FlagBadge
                                        icon={Search}
                                        tone="warning"
                                        title="Investigation has not started"
                                    >
                                        Investigation needed
                                    </FlagBadge>
                                )}
                            {ev.flags.awaiting_verification > 0 && (
                                <FlagBadge
                                    icon={ShieldCheck}
                                    tone="warning"
                                    title="Actions awaiting verification"
                                >
                                    {ev.flags.awaiting_verification} awaiting
                                    verification
                                </FlagBadge>
                            )}
                            {ev.has_open_actions && (
                                <FlagBadge
                                    icon={ListChecks}
                                    tone="info"
                                    title="Open corrective actions"
                                >
                                    Open actions
                                </FlagBadge>
                            )}
                            {ev.flags.unwired && (
                                <FlagBadge
                                    icon={Link2}
                                    tone="warning"
                                    title="No originating record to link back to"
                                >
                                    No linked source
                                </FlagBadge>
                            )}
                        </div>
                    </li>
                ))}
            </ul>
            <div
                role="region"
                aria-label="Safety reviews table"
                tabIndex={0}
                className="hidden max-w-full overflow-x-auto overscroll-x-contain md:block"
            >
                <table className="w-full min-w-[1040px] text-sm">
                    <thead className="bg-muted/70">
                        <tr className="border-b border-border text-left text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                            <th className="px-4 py-3">When</th>
                            <th className="px-4 py-3">Event</th>
                            <th className="px-4 py-3">Source &amp; category</th>
                            <th className="px-4 py-3">Site / Client</th>
                            <th className="px-4 py-3">Severity</th>
                            <th className="px-4 py-3">Stage</th>
                            <th className="px-4 py-3">Governance flags</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                        {rows.map((ev) => {
                            const sev = SEV[ev.severity] ?? SEV.low;
                            const stage = STAGE[ev.status] ?? STAGE.open;
                            const StageIcon = stage.icon;
                            const mod = ev.source
                                ? SOURCE_MODULE[ev.source.type]
                                : null;
                            const ModIcon = mod?.icon ?? Link2;
                            const when = formatWhenCompact(
                                ev.occurred_at ?? ev.reported_at,
                            );
                            const category =
                                EVENT_CATEGORY_LABELS[ev.event_category] ??
                                titleCase(ev.event_category);
                            const entityName =
                                ev.client_name ??
                                ev.site_name ??
                                ev.staff_name ??
                                'Unassigned';
                            const entitySub =
                                ev.client_name && ev.site_name
                                    ? ev.site_name
                                    : ev.staff_name
                                      ? ev.staff_name
                                      : ev.site_name
                                        ? 'Site record'
                                        : 'No linked person';
                            const worksafe = worksafeState(ev);
                            const WorksafeIcon =
                                worksafe.notifiable === false ||
                                worksafe.status === 'acknowledged'
                                    ? ShieldCheck
                                    : worksafe.notifiable === null
                                      ? Clock
                                      : ShieldAlert;
                            return (
                                <tr
                                    key={ev.id}
                                    onClick={() => onOpen(ev.id)}
                                    onContextMenu={(e) => onRowCtx(e, ev)}
                                    tabIndex={0}
                                    aria-label={`Open event ${ev.reference_number}`}
                                    onKeyDown={(e) => {
                                        if (
                                            e.target === e.currentTarget &&
                                            (e.key === 'Enter' || e.key === ' ')
                                        ) {
                                            e.preventDefault();
                                            e.stopPropagation();
                                            onOpen(ev.id);
                                        }
                                    }}
                                    className="cursor-pointer transition-colors hover:bg-muted/45 focus-visible:bg-muted/45 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                >
                                    <td className="px-4 py-3 align-top whitespace-nowrap">
                                        <div
                                            className="text-xs font-bold text-foreground"
                                            title={when.title}
                                        >
                                            {when.main}
                                        </div>
                                        <Button
                                            unstyled
                                            className="frontline-tap frontline-focus mt-0.5 text-left text-xs font-semibold text-primary"
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                onOpen(ev.id);
                                            }}
                                        >
                                            {ev.reference_number}
                                        </Button>
                                    </td>
                                    <td className="max-w-[280px] px-4 py-3 align-top">
                                        <div className="flex items-start gap-2">
                                            <span
                                                className={`h-2 w-2 shrink-0 rounded-full ${TONE_DOT[sev.tone]}`}
                                            />
                                            <span className="min-w-0">
                                                <span className="block text-xs font-bold text-foreground">
                                                    {category}
                                                </span>
                                                <span className="mt-0.5 block truncate text-[11px] text-muted-foreground">
                                                    {eventContext(ev)}
                                                </span>
                                                <span className="mt-1 block text-xs text-muted-foreground">
                                                    Review owner:{' '}
                                                    {ev.handover.owner?.name ??
                                                        'Needs an owner'}
                                                </span>
                                            </span>
                                        </div>
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        {ev.source && mod ? (
                                            <div className="flex items-center gap-2">
                                                <span
                                                    className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-md ${SOURCE_CHIP[ev.source.type] ?? 'bg-muted text-muted-foreground'}`}
                                                >
                                                    <ModIcon className="h-3.5 w-3.5" />
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="block text-xs font-bold text-foreground">
                                                        {mod.label}
                                                    </span>
                                                    <span className="block truncate text-[11px] font-medium text-muted-foreground">
                                                        {sourceRef(ev)}
                                                    </span>
                                                </span>
                                            </div>
                                        ) : (
                                            <span
                                                className="inline-flex items-center gap-1 rounded-md bg-status-warning-bg px-2 py-1 text-[11px] font-bold text-status-warning"
                                                title="No originating module"
                                            >
                                                <Link2 className="h-3 w-3" /> No
                                                linked source
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        {entityName ? (
                                            <span className="flex items-center gap-2">
                                                <span
                                                    className={`grid h-7 w-7 shrink-0 place-items-center rounded-md text-[10px] font-bold ${entityTone(ev.id)}`}
                                                >
                                                    {initials(entityName)}
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="block truncate text-xs font-bold text-foreground">
                                                        {entityName}
                                                    </span>
                                                    <span className="block truncate text-[11px] text-muted-foreground">
                                                        {entitySub}
                                                    </span>
                                                </span>
                                            </span>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        <span
                                            className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${TONE_BG[sev.tone]}`}
                                        >
                                            {sev.label}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        <span
                                            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${stage.cls}`}
                                        >
                                            <StageIcon className="h-3 w-3" />
                                            {stage.label}
                                        </span>
                                    </td>
                                    <td className="px-4 py-3 align-top">
                                        <div className="flex flex-wrap items-center gap-1.5 text-muted-foreground">
                                            {ev.handover.status ===
                                            'awaiting_acceptance' ? (
                                                <FlagBadge
                                                    icon={ShieldCheck}
                                                    tone="warning"
                                                    title="H&S handover awaiting acceptance"
                                                >
                                                    Awaiting H&S
                                                </FlagBadge>
                                            ) : ev.handover.status ===
                                              'accepted' ? (
                                                <FlagBadge
                                                    icon={ShieldCheck}
                                                    tone="success"
                                                    title={
                                                        ev.handover.accepted_by
                                                            ? `Accepted by ${ev.handover.accepted_by.name}`
                                                            : 'H&S handover accepted'
                                                    }
                                                >
                                                    H&S accepted
                                                </FlagBadge>
                                            ) : null}
                                            <FlagBadge
                                                icon={WorksafeIcon}
                                                tone={worksafeFlagTone(
                                                    worksafe,
                                                )}
                                                title={`WorkSafe: ${worksafeLabel(worksafe)}`}
                                            >
                                                {worksafeLabel(worksafe)}
                                            </FlagBadge>
                                            {ev.flags.investigation_overdue ? (
                                                <FlagBadge
                                                    icon={Search}
                                                    tone="critical"
                                                    title="Investigation overdue"
                                                >
                                                    Inv overdue
                                                </FlagBadge>
                                            ) : ev.investigation_required &&
                                              !ev.has_investigation ? (
                                                <FlagBadge
                                                    icon={Search}
                                                    tone="warning"
                                                    title="Investigation required"
                                                >
                                                    Inv due
                                                </FlagBadge>
                                            ) : null}
                                            {ev.flags.awaiting_verification >
                                            0 ? (
                                                <FlagBadge
                                                    icon={ShieldCheck}
                                                    tone="info"
                                                    title={`${ev.flags.awaiting_verification} action(s) awaiting verification`}
                                                >
                                                    {
                                                        ev.flags
                                                            .awaiting_verification
                                                    }{' '}
                                                    verify
                                                </FlagBadge>
                                            ) : null}
                                            {ev.has_open_actions ? (
                                                <FlagBadge
                                                    icon={ListChecks}
                                                    tone="info"
                                                    title="Open corrective actions"
                                                >
                                                    Actions
                                                </FlagBadge>
                                            ) : null}
                                            {ev.flags.unwired ? (
                                                <FlagBadge
                                                    icon={Link2}
                                                    tone="warning"
                                                    title="No originating record to link back to"
                                                >
                                                    No linked source
                                                </FlagBadge>
                                            ) : null}
                                            {ev.handover.status !==
                                                'awaiting_acceptance' &&
                                            ev.handover.status !== 'accepted' &&
                                            !ev.flags.investigation_overdue &&
                                            !(
                                                ev.investigation_required &&
                                                !ev.has_investigation
                                            ) &&
                                            ev.flags.awaiting_verification ===
                                                0 &&
                                            !ev.has_open_actions &&
                                            !ev.flags.unwired ? (
                                                <span className="text-xs text-muted-foreground">
                                                    —
                                                </span>
                                            ) : null}
                                        </div>
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
