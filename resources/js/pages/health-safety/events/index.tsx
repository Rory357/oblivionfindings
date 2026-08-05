/* H&S Events register — the governance convergence view. Every incident type
 * lands here as an HsEvent for investigation, corrective action, WorkSafe
 * notification and gated closure. Shares the gold-standard `hs-hero-kit` hero
 * chrome + rostering TabStrip/EntityFilter/ShiftContextMenu with /incidents,
 * /safeguarding, /fleet-assets/incidents and its sibling Corrective-actions
 * register so the whole safety workflow reads as one product and can't drift
 * apart. Row helpers come from the neutral register-row-kit. ShiftContextMenu +
 * detail-as-modal workflow preserved. NZ-only, web-only. */
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
    EntityFilter,
    ShiftContextMenu,
    TabStrip,
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
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import {
    HeroCluster,
    HeroClusterTile,
    HeroMedallion,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
    fmt,
    type Tone,
} from '@/pages/health-safety/components/hs-hero-kit';
import {
    FlagBadge,
    RegisterTableHeader,
    TONE_BG,
    TONE_DOT,
    entityTone,
    initials,
    titleCase,
} from '@/pages/health-safety/components/register-row-kit';
import { WorkflowRibbon } from '@/pages/health-safety/components/workflow-ribbon';
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

type EventRow = {
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
    { label: 'Board summary', href: '/health-safety/reports/board-summary' },
    {
        label: 'WorkSafe register',
        href: '/health-safety/reports/worksafe-register',
    },
    {
        label: 'Investigation outcomes',
        href: '/health-safety/reports/investigation-outcomes',
    },
    {
        label: 'Corrective-action traceability',
        href: '/health-safety/reports/corrective-action-traceability',
    },
    {
        label: 'Risk-assessment register',
        href: '/health-safety/reports/risk-assessment-register',
    },
];

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
    { key: 'week', label: 'This week' },
    { key: '30d', label: '30 days' },
    { key: 'quarter', label: 'Quarter' },
    { key: 'custom', label: 'Custom' },
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
}: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [pendingSection, setPendingSection] =
        useState<EventSectionKey>('overview');
    const [pendingAction, setPendingAction] = useState<EventActionKey | null>(
        null,
    );

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

    // Detail-over-list: fetch only the `detail` prop and open the dialog without
    // navigating away; closing drops the param so `detail` comes back null.
    const openEvent = (
        id: number,
        opts?: { section?: EventSectionKey; action?: EventActionKey },
    ) => {
        setPendingSection(opts?.section ?? 'overview');
        setPendingAction(opts?.action ?? null);
        router.get(
            '/health-safety/events',
            { ...filters, event: id },
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );
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

    /* ---- date range (footer pills) ---- */
    const activeRange = !filters.from
        ? 'week'
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
        if (key === 'custom') {
            return;
        }
        const map: Record<string, number> = { week: 7, '30d': 30, quarter: 90 };
        go({ from: daysAgoStr(map[key]), to: todayStr() });
    };

    /* ---- right-click context menu (mirrors the dialog Options bar) ---- */
    const openRowCtx = (e: ReactMouseEvent, ev: EventRow) => {
        e.preventDefault();
        const sev = SEV[ev.severity] ?? SEV.low;
        const items: ShiftCtxItem[] = [
            {
                icon: <Shield className="h-3.5 w-3.5" />,
                label: 'View event',
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
        if (canDecideWorksafe) {
            items.push({
                icon:
                    ev.worksafe_notifiable === null ? (
                        <ShieldAlert className="h-3.5 w-3.5" />
                    ) : (
                        <ShieldCheck className="h-3.5 w-3.5" />
                    ),
                label:
                    ev.worksafe_notifiable === null
                        ? 'Record WorkSafe decision'
                        : 'Update WorkSafe decision',
                sub: worksafeLabel(worksafe),
                tone: ev.worksafe_notifiable === null ? 'critical' : undefined,
                onClick: () =>
                    openEvent(ev.id, { action: 'worksafe_decision' }),
            });
        }
        if (
            ev.worksafe_notifiable === true &&
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
                label: 'Close event',
                tone: 'critical',
                onClick: () => openEvent(ev.id, { action: 'close' }),
            });
        }
        items.push(
            { sep: true },
            {
                icon: <Link2 className="h-3.5 w-3.5" />,
                label: 'Open full page',
                onClick: () => router.visit(`/health-safety/events/${ev.id}`),
            },
        );

        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: sev.label.toUpperCase(),
            meta: `${ev.reference_number} · ${EVENT_CATEGORY_LABELS[ev.event_category] ?? titleCase(ev.event_category)}`,
            items,
        });
    };

    const live = hero.live;
    const at = hero.attention;
    const tableTitle =
        {
            all: 'All events',
            open: 'Open events',
            investigating: 'Under investigation',
            corrective_actions: 'In corrective action',
            handover: 'Awaiting H&S acceptance',
            worksafe: 'WorkSafe-notifiable',
            monitoring: 'Monitoring',
            closed: 'Closed events',
        }[tab] ?? 'Events';
    const showOrphanNote =
        (tab === 'all' || tab === 'open') &&
        events.data.some((ev) => ev.flags.unwired);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Events', href: '/health-safety/events' },
            ]}
        >
            <Head title="Safety events" />

            <div className="flex flex-col gap-6 p-6">
                {/* ---- Hero ---- */}
                <HeroShell
                    footer={
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <HeroSegmented
                                label="Period"
                                variant="pill"
                                ariaLabel="Date range"
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
                            <label className="inline-flex items-center gap-1.5">
                                <span className="text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">
                                    Category
                                </span>
                                <select
                                    value={filters.category ?? ''}
                                    onChange={(e) =>
                                        go({ category: e.target.value || null })
                                    }
                                    aria-label="Category filter"
                                    className="rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-2.5 py-1.5 text-xs font-medium text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none [&>option]:text-foreground"
                                >
                                    <option value="">All categories</option>
                                    {CATEGORY_OPTIONS.map((c) => (
                                        <option key={c} value={c}>
                                            {EVENT_CATEGORY_LABELS[c] ??
                                                titleCase(c)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="inline-flex items-center gap-1.5">
                                <span className="text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">
                                    Source
                                </span>
                                <select
                                    value={filters.source ?? ''}
                                    onChange={(e) =>
                                        go({ source: e.target.value || null })
                                    }
                                    aria-label="Source filter"
                                    className="rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-2.5 py-1.5 text-xs font-medium text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none [&>option]:text-foreground"
                                >
                                    <option value="">All sources</option>
                                    {SOURCE_OPTIONS.map((source) => (
                                        <option
                                            key={source.value}
                                            value={source.value}
                                        >
                                            {source.label}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            {/* eslint-disable-next-line no-restricted-syntax -- onDark WorkSafe toggle on the hero footer; not a shadcn Button. */}
                            <button
                                type="button"
                                aria-pressed={!!filters.worksafe}
                                onClick={() =>
                                    go({
                                        worksafe: filters.worksafe
                                            ? null
                                            : true,
                                    })
                                }
                                className={`inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-medium transition-colors focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none ${
                                    filters.worksafe
                                        ? 'border-primary-foreground bg-primary-foreground text-primary'
                                        : 'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground/80 hover:bg-primary-foreground/20'
                                }`}
                            >
                                <ShieldAlert className="h-3.5 w-3.5" />{' '}
                                WorkSafe-notifiable
                            </button>
                            <div className="relative ml-auto">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                <input
                                    type="search"
                                    placeholder="Search events…"
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
                                    className="w-48 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pr-2.5 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
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
                    <WorkflowRibbon current="investigate" />

                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-4">
                            <HeroMedallion icon={ShieldCheck} />
                            <div className="flex flex-col gap-1.5">
                                <div className="flex flex-wrap items-center gap-2">
                                    <HeroStatusPill>
                                        Safety events · governance register
                                    </HeroStatusPill>
                                    <span className="inline-flex items-center gap-1.5 rounded-full bg-primary-foreground/15 px-2.5 py-1 text-[11px] font-semibold tracking-[0.04em] text-primary-foreground/85 uppercase">
                                        <Activity className="h-3.5 w-3.5" />{' '}
                                        Every incident type converges here
                                    </span>
                                </div>
                                <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">
                                    Health &amp; Safety events
                                </h1>
                                <p className="max-w-xl text-sm text-primary-foreground/70">
                                    The governance hub. Every safety event —
                                    from Incidents, Safeguarding, Fleet,
                                    Injuries, Hazards, Restraints and Drills —
                                    lands here to be investigated, driven to
                                    verified corrective action, notified to
                                    WorkSafe NZ, and closed through a gate.
                                </p>
                            </div>
                        </div>

                        {canViewBoardReports ? (
                            <Popover>
                                <PopoverTrigger asChild>
                                    <Button
                                        size="sm"
                                        className="border border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20"
                                    >
                                        <FileText className="mr-1.5 h-4 w-4" />{' '}
                                        Board reports
                                        <span aria-hidden className="ml-1">
                                            ▾
                                        </span>
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent
                                    align="end"
                                    className="w-64 p-1.5"
                                >
                                    {BOARD_REPORTS.map((report) => (
                                        // eslint-disable-next-line no-restricted-syntax -- popover menu item (report link), not a form control
                                        <button
                                            key={report.href}
                                            type="button"
                                            onClick={() =>
                                                router.visit(report.href)
                                            }
                                            className="flex w-full items-center gap-2.5 rounded-md p-2.5 text-left text-sm font-medium transition-colors hover:bg-muted"
                                        >
                                            <FileText className="h-4 w-4 shrink-0 text-primary" />
                                            {report.label}
                                        </button>
                                    ))}
                                </PopoverContent>
                            </Popover>
                        ) : null}
                    </div>

                    {/* stat clusters */}
                    <div className="grid gap-3 lg:grid-cols-2">
                        <HeroCluster
                            title="Live · open governance"
                            icon={Activity}
                        >
                            <HeroClusterTile
                                href="/health-safety/events?tab=open"
                                label="Open"
                                value={fmt(live.open)}
                                caption="newest today"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/health-safety/events?tab=investigating"
                                label="Investigating"
                                value={fmt(live.investigating)}
                                caption="in progress"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/health-safety/events?tab=corrective_actions"
                                label="Corrective"
                                value={fmt(live.corrective_action)}
                                caption="driving actions"
                                tone="warning"
                            />
                            <HeroClusterTile
                                href="/health-safety/events?tab=monitoring"
                                label="Monitoring"
                                value={fmt(live.monitoring)}
                                caption="residual review"
                                tone="success"
                            />
                        </HeroCluster>
                        <HeroCluster
                            title="Needs attention"
                            icon={AlertTriangle}
                        >
                            <HeroClusterTile
                                href="/health-safety/events?tab=investigating"
                                label="Inv due"
                                value={fmt(at.investigation_due)}
                                caption={
                                    at.investigation_due > 0
                                        ? 'needs a lead'
                                        : 'all started'
                                }
                                tone="critical"
                            />
                            <HeroClusterTile
                                href="/health-safety/events?tab=corrective_actions"
                                label="Await verify"
                                value={fmt(at.awaiting_verification)}
                                caption="+ completer"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/health-safety/events?tab=worksafe"
                                label="WorkSafe due"
                                value={fmt(at.worksafe_due)}
                                caption={
                                    at.worksafe_due > 0
                                        ? 'notify ASAP'
                                        : 'none pending'
                                }
                                tone="critical"
                            />
                            <HeroClusterTile
                                href="/health-safety/events?tab=handover"
                                label="Handover"
                                value={fmt(at.handover_due)}
                                caption={
                                    at.handover_due > 0
                                        ? 'acceptance required'
                                        : 'none waiting'
                                }
                                tone="warning"
                            />
                        </HeroCluster>
                    </div>
                </HeroShell>

                {/* ---- Tabs ---- */}
                <TabStrip
                    value={tab}
                    items={TABS}
                    onChange={setTab}
                    ariaLabel="Safety event views"
                />

                {showOrphanNote ? <OrphanNotice /> : null}

                {/* ---- Table ---- */}
                <section className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                    <RegisterTableHeader
                        icon={Shield}
                        title={tableTitle}
                        subtitle="the convergence view"
                        hint="Right-click a row for governance actions"
                        hintIcon={MousePointer2}
                    />
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
                <strong>Orphan categories shown below as "unwired":</strong>{' '}
                these were defined in HsEvent before a source observer existed.
                Each appears here so the convergence view is complete; until an
                event is created from its source module it has no originating
                record to link back to.
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
    if (!value) {
        return { main: '—', title: 'No event date recorded' };
    }

    const date = new Date(value);
    const now = new Date();
    const startToday = new Date(
        now.getFullYear(),
        now.getMonth(),
        now.getDate(),
    ).getTime();
    const startValue = new Date(
        date.getFullYear(),
        date.getMonth(),
        date.getDate(),
    ).getTime();
    const days = Math.round((startToday - startValue) / 86400000);
    const time = new Intl.DateTimeFormat('en-NZ', {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    }).format(date);

    if (days === 0)
        return { main: `Today ${time}`, title: formatDateTime(value) };
    if (days === 1)
        return { main: `Yesterday ${time}`, title: formatDateTime(value) };
    if (days > 1 && days < 7)
        return {
            main: `${days} days ago ${time}`,
            title: formatDateTime(value),
        };

    return {
        main: new Intl.DateTimeFormat('en-NZ', {
            day: '2-digit',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }).format(date),
        title: formatDateTime(value),
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
    };
}

function worksafeFlagTone(
    worksafe: WorksafeState,
): 'critical' | 'warning' | 'success' | 'info' | 'neutral' {
    if (worksafe.notifiable === null) return 'warning';
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

function EventTable({
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
                    No events here
                </p>
                <p className="mt-1 text-sm text-muted-foreground/70">
                    Nothing matches this tab and filters.
                </p>
            </div>
        );
    }
    return (
        <div className="overflow-x-auto">
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
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        e.preventDefault();
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
                                    <div className="mt-0.5 text-[11px] font-semibold text-muted-foreground">
                                        {ev.reference_number}
                                    </div>
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
                                            <Link2 className="h-3 w-3" />{' '}
                                            Unwired category
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
                                            tone={worksafeFlagTone(worksafe)}
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
                                        {ev.flags.awaiting_verification > 0 ? (
                                            <FlagBadge
                                                icon={ShieldCheck}
                                                tone="info"
                                                title={`${ev.flags.awaiting_verification} action(s) awaiting verification`}
                                            >
                                                {ev.flags.awaiting_verification}{' '}
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
                                                Unwired
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
                                        ev.flags.awaiting_verification === 0 &&
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
    );
}
