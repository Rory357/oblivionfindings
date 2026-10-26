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
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
} from '@/components/page';
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
    Note,
    PersonChip,
    StageDots,
    Surface,
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Field, SelectInput } from '@/components/wizard/primitives';
import AppLayout from '@/layouts/app-layout';
import {
    formatDateOnly,
    formatDateTime,
    formatTime,
    toDateInput,
} from '@/lib/datetime';
import { safetyReviewHref } from '@/lib/health-safety/navigation';
import { type Tone } from '@/pages/health-safety/components/hs-hero-kit';
import {
    FlagBadge,
    titleCase,
} from '@/pages/health-safety/components/register-row-kit';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    Clock,
    FileText,
    LayoutList,
    Link2,
    ListChecks,
    MoreVertical,
    Search,
    Shield,
    ShieldAlert,
    ShieldCheck,
    SlidersHorizontal,
    X,
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
    /** Laravel's paginator sends these with every page. */
    total?: number;
    from?: number | null;
    to?: number | null;
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

/** The five review stages, in order (Open › Investigating › Corrective action › Monitoring › Closed). */
const STAGE_ORDER = [
    'open',
    'investigating',
    'corrective_action',
    'monitoring',
    'closed',
] as const;
const STAGE: Record<string, { label: string }> = {
    open: { label: 'Open' },
    investigating: { label: 'Investigating' },
    corrective_action: { label: 'Corrective action' },
    monitoring: { label: 'Monitoring' },
    closed: { label: 'Closed' },
};

const SEVERITY_VARIANT: Record<string, StatusVariant> = {
    low: 'success',
    medium: 'warning',
    high: 'critical',
    critical: 'critical',
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

    const at = hero.attention;
    const moreFilterCount = [
        filters.category,
        filters.source,
        filters.severity,
        filters.worksafe,
    ].filter(Boolean).length;
    // Four numbers that double as lenses; Open and Monitoring stay in the Review view filter.
    const numbers: {
        key: string;
        label: string;
        value: number;
        caption: string;
        tone?: 'critical' | 'warning';
    }[] = [
        {
            key: 'investigation_due',
            label: 'Investigation needed',
            value: at.investigation_due,
            caption: 'Required, not finished',
            tone: at.investigation_due ? 'warning' : undefined,
        },
        {
            key: 'awaiting_verification',
            label: 'Waiting to be checked',
            value: at.awaiting_verification,
            caption: 'Actions done, not yet checked',
        },
        {
            key: 'worksafe_pending',
            label: 'WorkSafe to notify',
            value: at.worksafe_due,
            caption: 'Notification due',
            tone: at.worksafe_due ? 'warning' : undefined,
        },
        {
            key: 'handover',
            label: 'Handover to accept',
            value: at.handover_due,
            caption: 'Waiting for Health & safety',
        },
    ];
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
                { title: 'Health & safety', href: '/health-safety' },
                { title: 'Safety reviews', href: '/health-safety/events' },
            ]}
        >
            <Head title="Safety reviews" />

            <div className="flex min-w-0 flex-col gap-5">
                {/* ---- Hero ---- */}
                <PageHeader
                    wrapTitle
                    icon={ShieldCheck}
                    title="Safety reviews"
                    subline="Investigate, agree actions and check that people are safe"
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
                    strip={
                        <PageHeaderNumbers>
                            {numbers.map((number) => (
                                <PageHeaderNumber
                                    key={number.key}
                                    value={number.value}
                                    label={number.label}
                                    caption={number.caption}
                                    tone={number.tone}
                                    pressed={tab === number.key}
                                    onClick={() => setTab(number.key)}
                                />
                            ))}
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
                                    <Field label="Category">
                                        <SelectInput
                                            ariaLabel="Category"
                                            placeholder="All categories"
                                            value={filters.category ?? 'all'}
                                            options={[
                                                {
                                                    value: 'all',
                                                    label: 'All categories',
                                                },
                                                ...CATEGORY_OPTIONS.map(
                                                    (value) => ({
                                                        value,
                                                        label:
                                                            EVENT_CATEGORY_LABELS[
                                                                value
                                                            ] ??
                                                            titleCase(value),
                                                    }),
                                                ),
                                            ]}
                                            onChange={(value) =>
                                                go({
                                                    category:
                                                        value === 'all'
                                                            ? null
                                                            : value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field label="Source">
                                        <SelectInput
                                            ariaLabel="Source"
                                            placeholder="All sources"
                                            value={filters.source ?? 'all'}
                                            options={[
                                                {
                                                    value: 'all',
                                                    label: 'All sources',
                                                },
                                                ...SOURCE_OPTIONS,
                                            ]}
                                            onChange={(value) =>
                                                go({
                                                    source:
                                                        value === 'all'
                                                            ? null
                                                            : value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field label="Severity">
                                        <SelectInput
                                            ariaLabel="Severity"
                                            placeholder="All severities"
                                            value={filters.severity ?? 'all'}
                                            options={[
                                                {
                                                    value: 'all',
                                                    label: 'All severities',
                                                },
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
                                                        value === 'all'
                                                            ? null
                                                            : value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <label className="flex items-center gap-2 text-sm font-medium">
                                        <Checkbox
                                            checked={!!filters.worksafe}
                                            onCheckedChange={(value) =>
                                                go({
                                                    worksafe:
                                                        value === true || null,
                                                })
                                            }
                                        />
                                        WorkSafe-notifiable only
                                    </label>
                                </PopoverContent>
                            </Popover>
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

                {showOrphanNote ? (
                    <Note tone="warning" icon={AlertTriangle}>
                        Some reviews aren’t linked to the record they came
                        from. Check their history before you rely on them for
                        what happened.
                    </Note>
                ) : null}

                {/* ---- The register (pg-hsn-40) ---- */}
                <Surface
                    title={tableTitle}
                    sub={
                        events.total && events.from
                            ? `Showing ${events.from}–${events.to} of ${events.total} · click a review to open it, or use ⋮ for options`
                            : 'Click a review to open it, or use ⋮ for options'
                    }
                >
                    <div className="mt-3">
                        <EventTable
                            rows={events.data}
                            onRowCtx={openRowCtx}
                            onOpen={openEvent}
                        />
                    </div>
                    {events.data.length ? (
                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border px-[18px] py-3">
                            <p className="text-sm text-muted-foreground">
                                Showing {events.data.length}
                                {events.total ? ` of ${events.total}` : ''}
                            </p>
                            {events.last_page > 1 ? (
                                <LaravelPagination links={events.links} />
                            ) : null}
                        </div>
                    ) : null}
                </Surface>
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

/** Where a review is up to: its stage on the five-stage line and what it is waiting on, in words. */
function reviewWhere(ev: EventRow): {
    stage: number;
    label: string;
    note: string;
    tone?: 'critical' | 'warning' | 'success';
} {
    const rank = Math.max(
        0,
        STAGE_ORDER.indexOf(ev.status as (typeof STAGE_ORDER)[number]),
    );
    const worksafe = worksafeState(ev);
    const waits: string[] = [];
    if (ev.handover.status === 'awaiting_acceptance')
        waits.push('Waiting for Health & safety');
    if (ev.status !== 'closed')
        waits.push(`WorkSafe: ${worksafeLabel(worksafe)}`);
    if (ev.flags.investigation_overdue) waits.push('Investigation overdue');
    else if (ev.investigation_required && !ev.has_investigation)
        waits.push('Investigation due');
    if (ev.flags.awaiting_verification > 0)
        waits.push(
            `${ev.flags.awaiting_verification} action${ev.flags.awaiting_verification === 1 ? '' : 's'} to check`,
        );
    else if (ev.has_open_actions) waits.push('Open actions');
    if (ev.flags.unwired) waits.push('No source record');
    const tone =
        ev.status === 'closed'
            ? ('success' as const)
            : ev.flags.investigation_overdue || ev.flags.worksafe_pending
              ? ('critical' as const)
              : ev.handover.status === 'awaiting_acceptance' ||
                  worksafe.notifiable === null ||
                  worksafe.decision_signed === false
                ? ('warning' as const)
                : undefined;
    return {
        stage: rank + 1,
        label: STAGE[ev.status]?.label ?? titleCase(ev.status),
        note: waits.join(' · '),
        tone,
    };
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
                className="hidden max-w-full md:block"
            >
                <Table className="min-w-[1040px]">
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            {[
                                'When',
                                'Type',
                                'Owner',
                                'Site / client',
                                'Severity',
                                'Where it’s up to',
                            ].map((label) => (
                                <TableHead
                                    key={label}
                                    className="h-auto bg-muted/60 px-4 py-2.5 font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    <span className="text-caption">{label}</span>
                                </TableHead>
                            ))}
                            <TableHead className="h-auto w-12 bg-muted/60 px-2 py-2.5">
                                <span className="sr-only">Options</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((ev) => {
                            const when = ev.occurred_at ?? ev.reported_at;
                            const category =
                                EVENT_CATEGORY_LABELS[ev.event_category] ??
                                titleCase(ev.event_category);
                            const place = ev.client_name ?? ev.site_name;
                            const placeSub = ev.client_name
                                ? ev.site_name
                                : ev.staff_name;
                            const where = reviewWhere(ev);
                            return (
                                <TableRow
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
                                    className="cursor-pointer align-top focus-visible:bg-muted/45 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                >
                                    <TableCell className="px-4 py-3">
                                        <p
                                            className="font-semibold text-foreground"
                                            title={
                                                when
                                                    ? formatDateTime(when)
                                                    : 'No occurrence time recorded'
                                            }
                                        >
                                            {when
                                                ? formatDateOnly(
                                                      toDateInput(when),
                                                  )
                                                : 'Not recorded'}
                                        </p>
                                        <p className="mt-0.5 text-caption text-muted-foreground">
                                            {when ? `${formatTime(when)} · ` : ''}
                                            {ev.reference_number}
                                        </p>
                                    </TableCell>
                                    <TableCell className="max-w-[18rem] px-4 py-3 whitespace-normal">
                                        <p className="font-semibold text-foreground">
                                            {category}
                                        </p>
                                        <p className="mt-0.5 text-caption [overflow-wrap:anywhere] text-muted-foreground">
                                            {eventContext(ev)}
                                        </p>
                                    </TableCell>
                                    <TableCell className="px-4 py-3">
                                        {ev.handover.owner ? (
                                            <PersonChip
                                                name={ev.handover.owner.name}
                                            />
                                        ) : (
                                            <StatusBadge variant="warning">
                                                Needs an owner
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell className="px-4 py-3">
                                        <p className="font-semibold text-foreground">
                                            {place ?? 'No site recorded'}
                                        </p>
                                        {placeSub ? (
                                            <p className="mt-0.5 text-caption text-muted-foreground">
                                                {placeSub}
                                            </p>
                                        ) : null}
                                    </TableCell>
                                    <TableCell className="px-4 py-3">
                                        <StatusBadge
                                            variant={
                                                SEVERITY_VARIANT[ev.severity] ??
                                                'neutral'
                                            }
                                        >
                                            {SEV[ev.severity]?.label ??
                                                titleCase(ev.severity)}
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell className="px-4 py-3 whitespace-normal">
                                        <StageDots
                                            stage={where.stage}
                                            of={STAGE_ORDER.length}
                                            label={where.label}
                                            note={
                                                where.note ? (
                                                    <span className="whitespace-normal">
                                                        {where.note}
                                                    </span>
                                                ) : undefined
                                            }
                                            tone={where.tone}
                                        />
                                    </TableCell>
                                    <TableCell className="px-2 py-3 text-right">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="frontline-hit size-8 text-muted-foreground"
                                            aria-label={`Options for ${ev.reference_number}`}
                                            onClick={(event) => {
                                                event.stopPropagation();
                                                onRowCtx(event, ev);
                                            }}
                                        >
                                            <MoreVertical
                                                aria-hidden
                                                className="size-4"
                                            />
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
        </>
    );
}
