/* H&S Events register — the governance convergence view. Every incident type
 * lands here as an HsEvent for investigation, corrective action, WorkSafe
 * notification and gated closure. The list chrome follows the supplied Claude
 * design drop while preserving ShiftContextMenu and detail-as-modal workflow
 * behaviour. NZ-only, web-only. */
import AppLayout from '@/layouts/app-layout';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { ShiftContextMenu, type ShiftCtxItem, type ShiftCtxState } from '@/components/rostering';
import {
    EventDetailDialog,
    EVENT_CATEGORY_LABELS,
    type EventActionKey,
    type EventDetail,
    type EventSectionKey,
} from '@/components/health-safety/event-detail-dialog';
import { formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { useState, type MouseEvent as ReactMouseEvent, type ReactNode } from 'react';
import {
    Activity,
    AlertTriangle,
    ChevronDown,
    CheckCircle2,
    ClipboardCheck,
    FileText,
    Flame,
    FlaskConical,
    Grid2X2,
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
    Sparkles,
    Truck,
    Wrench,
    X,
    type LucideIcon,
} from 'lucide-react';

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
    worksafe_notifiable: boolean;
    worksafe_status: string | null;
    investigation_required: boolean;
    source: { type: string; id: number; label: string; url: string | null; unwired: boolean } | null;
    flags: {
        investigation_overdue: boolean;
        awaiting_verification: number;
        worksafe_pending: boolean;
        unwired: boolean;
    };
    has_investigation: boolean;
    has_open_actions: boolean;
};

type Paginated<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; last_page: number };

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
        live: { open: number; investigating: number; corrective_action: number; monitoring: number };
        attention: { investigation_due: number; awaiting_verification: number; worksafe_due: number; closed_period: number };
    };
    filters: Filters;
    sites: Array<{ id: number; name: string }>;
    detail: EventDetail | null;
    can: { manage: boolean };
};

type DesignTabItem = {
    id: string;
    label: string;
    icon: LucideIcon;
    tone: 'primary' | 'info' | 'warning' | 'critical' | 'success';
    badge?: number;
};

/* ------------------------------------------------------------------ */
/*  Token maps (semantic only)                                         */
/* ------------------------------------------------------------------ */

type Tone = 'success' | 'warning' | 'critical' | 'neutral';

const SEV: Record<string, { tone: Tone; label: string }> = {
    low: { tone: 'success', label: 'Low' },
    medium: { tone: 'warning', label: 'Medium' },
    high: { tone: 'critical', label: 'High' },
    critical: { tone: 'critical', label: 'Critical' },
};

const TONE_BG: Record<Tone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    neutral: 'bg-muted text-muted-foreground',
};
const TONE_DOT: Record<Tone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    neutral: 'bg-muted-foreground',
};

const STAGE: Record<string, { label: string; cls: string; icon: LucideIcon }> = {
    open: { label: 'Open', cls: 'bg-status-info-bg text-status-info', icon: AlertTriangle },
    investigating: { label: 'Investigating', cls: 'bg-primary/10 text-primary', icon: Search },
    corrective_action: { label: 'Corrective action', cls: 'bg-status-warning-bg text-status-warning', icon: ListChecks },
    monitoring: { label: 'Monitoring', cls: 'bg-[var(--live-bg)] text-[var(--live)]', icon: Activity },
    closed: { label: 'Closed', cls: 'bg-status-success-bg text-status-success', icon: CheckCircle2 },
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

function titleCase(s: string): string {
    return s.replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function fmt(value: number | null | undefined): string {
    return value === null || value === undefined ? '—' : String(value);
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

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function HsEventsIndex({ events, tab, tabCounts, hero, filters, sites, detail, can }: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [pendingSection, setPendingSection] = useState<EventSectionKey>('overview');
    const [pendingAction, setPendingAction] = useState<EventActionKey | null>(null);

    const go = (next: Partial<Filters>) =>
        router.get('/health-safety/events', { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });

    const setTab = (id: string) => router.get('/health-safety/events', { ...filters, tab: id }, { preserveScroll: true });

    // Detail-over-list: fetch only the `detail` prop and open the dialog without
    // navigating away; closing drops the param so `detail` comes back null.
    const openEvent = (id: number, opts?: { section?: EventSectionKey; action?: EventActionKey }) => {
        setPendingSection(opts?.section ?? 'overview');
        setPendingAction(opts?.action ?? null);
        router.get('/health-safety/events', { ...filters, event: id }, { preserveState: true, preserveScroll: true, only: ['detail'] });
    };
    const closeDetail = () =>
        router.get('/health-safety/events', { ...filters }, { preserveState: true, preserveScroll: true, only: ['detail'] });

    const clearFilters = () =>
        router.get('/health-safety/events', { tab }, { preserveState: true, preserveScroll: true, replace: true });

    const hasFilters = !!(filters.q || filters.severity || filters.category || filters.source || filters.site_id || filters.worksafe || filters.from || filters.to);

    const TABS: DesignTabItem[] = [
        { id: 'all', label: 'All', icon: LayoutList, tone: 'primary', badge: tabCounts.all || undefined },
        { id: 'open', label: 'Open', icon: AlertTriangle, tone: 'info', badge: tabCounts.open || undefined },
        { id: 'investigating', label: 'Investigating', icon: Search, tone: 'primary', badge: tabCounts.investigating || undefined },
        { id: 'corrective_actions', label: 'Corrective actions', icon: ListChecks, tone: 'warning', badge: tabCounts.corrective_actions || undefined },
        { id: 'worksafe', label: 'WorkSafe-notifiable', icon: ShieldAlert, tone: 'critical', badge: tabCounts.worksafe || undefined },
        { id: 'monitoring', label: 'Monitoring', icon: Activity, tone: 'success', badge: tabCounts.monitoring || undefined },
        { id: 'closed', label: 'Closed', icon: CheckCircle2, tone: 'success', badge: tabCounts.closed || undefined },
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
    const RANGE_ITEMS = [
        { key: 'week', label: 'This week' },
        { key: '30d', label: '30 days' },
        { key: 'quarter', label: 'Quarter' },
        { key: 'custom', label: 'Custom' },
    ];
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
            { icon: <Shield className="h-3.5 w-3.5" />, label: 'View event', sub: ev.reference_number, tone: 'primary', onClick: () => openEvent(ev.id) },
            { icon: <Search className="h-3.5 w-3.5" />, label: 'Investigation', onClick: () => openEvent(ev.id, { section: 'investigation' }) },
            { icon: <ListChecks className="h-3.5 w-3.5" />, label: 'Corrective actions', onClick: () => openEvent(ev.id, { section: 'actions' }) },
        ];
        if (ev.source?.url) {
            items.push({ icon: <Link2 className="h-3.5 w-3.5" />, label: 'View originating record', sub: ev.source.label, onClick: () => router.visit(ev.source!.url!) });
        }
        if (ev.worksafe_notifiable && can.manage && ev.worksafe_status !== 'acknowledged') {
            if (ev.worksafe_status === 'notified') {
                items.push({ icon: <ShieldCheck className="h-3.5 w-3.5" />, label: 'Record WorkSafe acknowledgement', onClick: () => openEvent(ev.id, { action: 'worksafe_acknowledge' }) });
            } else {
                items.push({ icon: <ShieldAlert className="h-3.5 w-3.5" />, label: 'Record WorkSafe notification', tone: 'critical', onClick: () => openEvent(ev.id, { action: 'worksafe_notify' }) });
            }
        } else if (ev.worksafe_notifiable) {
            items.push({ icon: <ShieldAlert className="h-3.5 w-3.5" />, label: 'WorkSafe', sub: titleCase(ev.worksafe_status ?? 'pending'), onClick: () => openEvent(ev.id, { section: 'overview' }) });
        }
        if (can.manage && ev.status !== 'closed' && !ev.has_investigation) {
            items.push({ icon: <Search className="h-3.5 w-3.5" />, label: 'Start investigation', onClick: () => openEvent(ev.id, { action: 'investigation' }) });
        }
        if (can.manage && ev.status !== 'closed') {
            items.push({ icon: <ListChecks className="h-3.5 w-3.5" />, label: 'Add corrective action', onClick: () => openEvent(ev.id, { action: 'add_action' }) });
        }
        if (can.manage && ev.status !== 'closed') {
            items.push({ icon: <CheckCircle2 className="h-3.5 w-3.5" />, label: 'Close event', tone: 'critical', onClick: () => openEvent(ev.id, { action: 'close' }) });
        }
        items.push({ sep: true }, { icon: <Link2 className="h-3.5 w-3.5" />, label: 'Open full page', onClick: () => router.visit(`/health-safety/events/${ev.id}`) });

        setCtx({ x: e.clientX, y: e.clientY, tag: sev.label.toUpperCase(), meta: `${ev.reference_number} · ${EVENT_CATEGORY_LABELS[ev.event_category] ?? titleCase(ev.event_category)}`, items });
    };

    const live = hero.live;
    const at = hero.attention;
    const tableTitle =
        {
            all: 'All events',
            open: 'Open events',
            investigating: 'Under investigation',
            corrective_actions: 'In corrective action',
            worksafe: 'WorkSafe-notifiable',
            monitoring: 'Monitoring',
            closed: 'Closed events',
        }[tab] ?? 'Events';
    const showOrphanNote = (tab === 'all' || tab === 'open') && events.data.some((ev) => ev.flags.unwired);

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Events', href: '/health-safety/events' }]}>
            <Head title="Safety events" />

            <div className="min-h-screen bg-[oklch(0.98_0.006_277)] px-4 py-5 md:px-6">
                <div className="flex flex-col gap-4">
                    <section className="relative overflow-hidden rounded-[18px] bg-[linear-gradient(135deg,oklch(51.1%_0.262_277/.94),oklch(48%_0.255_280),oklch(44%_0.235_286))] text-primary-foreground shadow-[0_24px_60px_-28px_oklch(51.1%_0.262_277/.55)]">
                        <div className="pointer-events-none absolute -top-16 -right-16 h-64 w-64 rounded-full bg-primary-foreground/5" />
                        <div className="pointer-events-none absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-primary-foreground/5" />
                        <div className="pointer-events-none absolute top-1/4 right-1/3 h-24 w-24 rounded-full bg-primary-foreground/5" />

                        <div className="relative flex flex-col gap-5 p-5 md:p-6">
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div className="flex items-start gap-4">
                                    <span className="hidden h-[72px] w-[72px] shrink-0 items-center justify-center rounded-full border-4 border-primary-foreground/20 bg-primary-foreground/10 shadow-xl sm:flex">
                                        <ShieldCheck className="h-9 w-9" />
                                    </span>
                                    <div className="max-w-[720px]">
                                        <span className="inline-flex items-center gap-1.5 rounded-full bg-primary-foreground/15 px-2.5 py-1 text-[11px] font-bold tracking-[0.07em] text-primary-foreground/90 uppercase">
                                            <span className="relative flex h-2 w-2">
                                                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-success opacity-70 motion-reduce:animate-none" />
                                                <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                            </span>
                                            Safety events · governance register
                                        </span>
                                        <h1 className="mt-4 text-2xl font-bold text-primary-foreground md:text-[30px]">Health &amp; Safety events</h1>
                                        <p className="mt-2 max-w-[760px] text-sm leading-6 text-primary-foreground/80">
                                            The governance hub. Every safety event — from Incidents, Safeguarding, Fleet, Injuries, Hazards, Restraints and Drills — lands here to be investigated, driven to verified corrective action, notified to WorkSafe NZ, and closed through a gate.
                                        </p>
                                    </div>
                                </div>
                                <span className="inline-flex items-center gap-2 rounded-[11px] bg-primary-foreground/12 px-3.5 py-2 text-xs font-semibold text-primary-foreground/90">
                                    <Sparkles className="h-3.5 w-3.5" />
                                    Every incident type converges here
                                </span>
                            </div>

                            <div className="grid gap-3 lg:grid-cols-2">
                                <DesignHeroCluster title="Live · open governance" icon={Activity}>
                                    <DesignHeroTile href="/health-safety/events?tab=open" label="Open" value={fmt(live.open)} caption="newest today" tone="info" />
                                    <DesignHeroTile href="/health-safety/events?tab=investigating" label="Investigating" value={fmt(live.investigating)} caption="in progress" tone="primary" />
                                    <DesignHeroTile href="/health-safety/events?tab=corrective_actions" label="Corrective" value={fmt(live.corrective_action)} caption="driving actions" tone="warning" />
                                    <DesignHeroTile href="/health-safety/events?tab=monitoring" label="Monitoring" value={fmt(live.monitoring)} caption="residual review" tone="success" />
                                </DesignHeroCluster>
                                <DesignHeroCluster title="Needs attention" icon={AlertTriangle}>
                                    <DesignHeroTile href="/health-safety/events?tab=investigating" label="Inv due" value={fmt(at.investigation_due)} caption={at.investigation_due > 0 ? 'needs a lead' : 'all started'} tone="critical" />
                                    <DesignHeroTile href="/health-safety/events?tab=corrective_actions" label="Await verify" value={fmt(at.awaiting_verification)} caption="+ completer" tone="neutral" />
                                    <DesignHeroTile href="/health-safety/events?tab=worksafe" label="WorkSafe due" value={fmt(at.worksafe_due)} caption={at.worksafe_due > 0 ? 'notify ASAP' : 'none pending'} tone="critical" />
                                    <DesignHeroTile href="/health-safety/events?tab=closed" label="Closed" value={fmt(at.closed_period)} caption="this period" tone="success" />
                                </DesignHeroCluster>
                            </div>
                        </div>

                        <div className="relative flex flex-wrap items-center gap-2 border-t border-primary-foreground/15 px-5 py-3 md:px-6">
                            <span className="mr-1 text-[11px] font-bold tracking-wide text-primary-foreground/60 uppercase">Period</span>
                            {RANGE_ITEMS.map((item) => (
                                <button
                                    key={item.key}
                                    type="button"
                                    onClick={() => onRange(item.key)}
                                    className={`h-8 rounded-full border px-3.5 text-xs font-bold transition-colors ${
                                        activeRange === item.key
                                            ? 'border-primary-foreground/35 bg-primary-foreground/24 text-primary-foreground'
                                            : 'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground/85 hover:bg-primary-foreground/16'
                                    }`}
                                >
                                    {item.label}
                                </button>
                            ))}
                            <label className="ml-auto inline-flex h-8 items-center gap-2 rounded-full border border-primary-foreground/20 bg-primary-foreground/10 px-3 text-xs font-bold text-primary-foreground">
                                <MapPin className="h-3.5 w-3.5" />
                                <select
                                    value={filters.site_id ?? ''}
                                    onChange={(e) => go({ site_id: e.target.value ? Number(e.target.value) : null })}
                                    className="max-w-36 appearance-none bg-transparent font-bold outline-none [&>option]:text-foreground"
                                    aria-label="Site filter"
                                >
                                    <option value="">All sites</option>
                                    {sites.map((site) => (
                                        <option key={site.id} value={site.id}>
                                            {site.name}
                                        </option>
                                    ))}
                                </select>
                                <ChevronDown className="h-3.5 w-3.5" />
                            </label>
                            <label className="inline-flex h-8 items-center gap-2 rounded-full border border-primary-foreground/20 bg-primary-foreground/10 px-3 text-xs font-bold text-primary-foreground">
                                <Grid2X2 className="h-3.5 w-3.5" />
                                <select
                                    value={filters.category ?? ''}
                                    onChange={(e) => go({ category: e.target.value || null })}
                                    className="max-w-40 appearance-none bg-transparent font-bold outline-none [&>option]:text-foreground"
                                    aria-label="Category filter"
                                >
                                    <option value="">All categories</option>
                                    {CATEGORY_OPTIONS.map((c) => (
                                        <option key={c} value={c}>
                                            {EVENT_CATEGORY_LABELS[c] ?? titleCase(c)}
                                        </option>
                                    ))}
                                </select>
                                <ChevronDown className="h-3.5 w-3.5" />
                            </label>
                            <label className="inline-flex h-8 items-center gap-2 rounded-full border border-primary-foreground/20 bg-primary-foreground/10 px-3 text-xs font-bold text-primary-foreground">
                                <FileText className="h-3.5 w-3.5" />
                                <select
                                    value={filters.source ?? ''}
                                    onChange={(e) => go({ source: e.target.value || null })}
                                    className="max-w-36 appearance-none bg-transparent font-bold outline-none [&>option]:text-foreground"
                                    aria-label="Source filter"
                                >
                                    <option value="">All sources</option>
                                    {SOURCE_OPTIONS.map((source) => (
                                        <option key={source.value} value={source.value}>
                                            {source.label}
                                        </option>
                                    ))}
                                </select>
                                <ChevronDown className="h-3.5 w-3.5" />
                            </label>
                            <button
                                type="button"
                                aria-pressed={!!filters.worksafe}
                                onClick={() => go({ worksafe: filters.worksafe ? null : true })}
                                className={`inline-flex h-8 items-center gap-2 rounded-full border px-3 text-xs font-bold ${
                                    filters.worksafe
                                        ? 'border-primary-foreground/40 bg-primary-foreground/25 text-primary-foreground'
                                        : 'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground/90 hover:bg-primary-foreground/16'
                                }`}
                            >
                                <ShieldAlert className="h-3.5 w-3.5" />
                                WorkSafe-notifiable
                            </button>
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                <input
                                    type="search"
                                    placeholder="Search events…"
                                    defaultValue={filters.q ?? ''}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') go({ q: (e.target as HTMLInputElement).value || null });
                                    }}
                                    className="h-8 w-44 rounded-full border border-primary-foreground/20 bg-primary-foreground/10 pr-3 pl-8 text-xs font-semibold text-primary-foreground placeholder:text-primary-foreground/45 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                                />
                            </div>
                            {hasFilters ? (
                                <button type="button" onClick={clearFilters} className="inline-flex h-8 items-center gap-1 rounded-full px-2.5 text-xs font-semibold text-primary-foreground/75 hover:text-primary-foreground">
                                    <X className="h-3.5 w-3.5" />
                                    Clear
                                </button>
                            ) : null}
                        </div>
                    </section>

                    <DesignTabStrip value={tab} items={TABS} onChange={setTab} />

                    {showOrphanNote ? <OrphanNotice /> : null}

                    <section className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3 md:px-5">
                            <div className="flex items-center gap-2.5">
                                <span className="grid h-8 w-8 place-items-center rounded-lg bg-primary/10 text-primary">
                                    <Shield className="h-4 w-4" />
                                </span>
                                <div className="flex flex-wrap items-baseline gap-1.5">
                                    <h2 className="text-sm font-bold text-foreground">{tableTitle}</h2>
                                    <span className="text-xs font-semibold text-muted-foreground">· the convergence view</span>
                                </div>
                            </div>
                            <span className="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                                <MousePointer2 className="h-3.5 w-3.5" />
                                Right-click a row for governance actions
                            </span>
                        </div>
                        <EventTable rows={events.data} onRowCtx={openRowCtx} onOpen={openEvent} />
                    </section>

                    {events.last_page > 1 ? <LaravelPagination links={events.links} /> : null}
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
/*  Design-local primitives                                            */
/* ------------------------------------------------------------------ */

const HERO_DOT: Record<DesignTabItem['tone'] | 'neutral', string> = {
    primary: 'bg-primary-foreground/80',
    info: 'bg-status-info',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    success: 'bg-status-success',
    neutral: 'bg-primary-foreground/55',
};

const TAB_TONE: Record<DesignTabItem['tone'], { active: string; icon: string; bar: string }> = {
    primary: { active: 'bg-primary/10 text-primary', icon: 'bg-primary text-primary-foreground', bar: 'bg-primary' },
    info: { active: 'bg-status-info-bg text-status-info', icon: 'bg-status-info text-primary-foreground', bar: 'bg-status-info' },
    warning: { active: 'bg-status-warning-bg text-status-warning', icon: 'bg-status-warning text-primary-foreground', bar: 'bg-status-warning' },
    critical: { active: 'bg-status-critical-bg text-status-critical', icon: 'bg-status-critical text-primary-foreground', bar: 'bg-status-critical' },
    success: { active: 'bg-status-success-bg text-status-success', icon: 'bg-status-success text-primary-foreground', bar: 'bg-status-success' },
};

function DesignHeroCluster({ title, icon: Icon, children }: { title: string; icon: LucideIcon; children: ReactNode }) {
    return (
        <div className="rounded-2xl border border-primary-foreground/15 bg-primary-foreground/5 p-3">
            <div className="mb-2 flex items-center gap-1.5 text-[11px] font-bold tracking-wide text-primary-foreground/62 uppercase">
                <Icon className="h-3.5 w-3.5" />
                {title}
            </div>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">{children}</div>
        </div>
    );
}

function DesignHeroTile({
    href,
    label,
    value,
    caption,
    tone,
}: {
    href: string;
    label: string;
    value: string;
    caption: string;
    tone: DesignTabItem['tone'] | 'neutral';
}) {
    return (
        <a
            href={href}
            className="flex min-h-[76px] flex-col gap-0.5 rounded-xl border border-primary-foreground/15 bg-primary-foreground/10 px-3 py-2.5 text-left transition-colors hover:bg-primary-foreground/18"
        >
            <span className="flex items-center gap-1.5 text-[10px] font-bold tracking-wide text-primary-foreground/70 uppercase">
                <span className={`h-1.5 w-1.5 rounded-full ${HERO_DOT[tone]}`} />
                {label}
            </span>
            <span className="text-[25px] leading-tight font-bold tabular-nums text-primary-foreground">{value}</span>
            <span className="text-[10.5px] font-semibold text-primary-foreground/62">{caption}</span>
        </a>
    );
}

function DesignTabStrip({ value, items, onChange }: { value: string; items: DesignTabItem[]; onChange: (id: string) => void }) {
    return (
        <div
            role="tablist"
            aria-label="Safety event views"
            className="flex flex-wrap items-center gap-1 rounded-2xl border border-border bg-card p-1.5 shadow-sm"
        >
            {items.map((item) => {
                const active = item.id === value;
                const Icon = item.icon;
                const tone = TAB_TONE[item.tone];
                return (
                    <button
                        key={item.id}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onChange(item.id)}
                        className={`relative inline-flex h-8 items-center gap-2 rounded-[10px] px-3 text-xs font-semibold transition-colors ${
                            active ? tone.active : 'text-muted-foreground hover:bg-muted/70 hover:text-foreground'
                        }`}
                    >
                        <span className={`grid h-5 w-5 place-items-center rounded-md ${active ? tone.icon : 'bg-muted text-muted-foreground'}`}>
                            <Icon className="h-3.5 w-3.5" />
                        </span>
                        {item.label}
                        {item.badge ? (
                            <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums ${active ? tone.icon : 'bg-muted text-muted-foreground'}`}>
                                {item.badge}
                            </span>
                        ) : null}
                        {active ? <span className={`absolute right-3 bottom-0 left-3 h-0.5 rounded-full ${tone.bar}`} /> : null}
                    </button>
                );
            })}
        </div>
    );
}

function OrphanNotice() {
    return (
        <div className="flex items-start gap-3 rounded-2xl border border-status-warning/35 bg-status-warning-bg px-4 py-3 text-status-warning">
            <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-status-warning text-primary-foreground">
                <AlertTriangle className="h-4 w-4" />
            </span>
            <p className="text-sm leading-6">
                <strong>3 orphan categories shown below as "unwired" (E-Gap 5):</strong> <strong>Exposure</strong>,{' '}
                <strong>Inspection failure</strong> and <strong>Equipment fault</strong> are defined in HsEvent but no observer creates them. They appear here to show how they'd present once wired — each needs a <strong>wire-or-remove decision</strong>. Until then they have no originating record to link back to.
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

const ENTITY_TONE = ['bg-primary text-primary-foreground', 'bg-status-info text-primary-foreground', 'bg-status-success text-primary-foreground', 'bg-status-critical text-primary-foreground'];

function formatWhenCompact(value: string | null): { main: string; title: string } {
    if (!value) {
        return { main: '—', title: 'No event date recorded' };
    }

    const date = new Date(value);
    const now = new Date();
    const startToday = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
    const startValue = new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
    const days = Math.round((startToday - startValue) / 86400000);
    const time = new Intl.DateTimeFormat('en-NZ', { hour: '2-digit', minute: '2-digit', hour12: false }).format(date);

    if (days === 0) return { main: `Today ${time}`, title: formatDateTime(value) };
    if (days === 1) return { main: `Yesterday ${time}`, title: formatDateTime(value) };
    if (days > 1 && days < 7) return { main: `${days} days ago ${time}`, title: formatDateTime(value) };

    return {
        main: new Intl.DateTimeFormat('en-NZ', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', hour12: false }).format(date),
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

function initials(label: string | null | undefined): string {
    if (!label) return 'HS';
    const parts = label.split(/\s+/).filter(Boolean);
    const text = parts.length > 1 ? `${parts[0][0]}${parts[1][0]}` : label.slice(0, 2);
    return text.toUpperCase();
}

function entityTone(ev: EventRow): string {
    return ENTITY_TONE[ev.id % ENTITY_TONE.length];
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

function FlagBadge({ icon: Icon, children, tone, title }: { icon: LucideIcon; children: ReactNode; tone: 'critical' | 'warning' | 'success' | 'info' | 'neutral'; title: string }) {
    const cls =
        {
            critical: 'bg-status-critical-bg text-status-critical',
            warning: 'bg-status-warning-bg text-status-warning',
            success: 'bg-status-success-bg text-status-success',
            info: 'bg-status-info-bg text-status-info',
            neutral: 'bg-muted text-muted-foreground',
        }[tone] ?? 'bg-muted text-muted-foreground';

    return (
        <span title={title} className={`inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-bold whitespace-nowrap ${cls}`}>
            <Icon className="h-3 w-3" />
            {children}
        </span>
    );
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
                <p className="font-medium text-muted-foreground">No events here</p>
                <p className="mt-1 text-sm text-muted-foreground/70">Nothing matches this tab and filters.</p>
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
                        const mod = ev.source ? SOURCE_MODULE[ev.source.type] : null;
                        const ModIcon = mod?.icon ?? Link2;
                        const when = formatWhenCompact(ev.occurred_at ?? ev.reported_at);
                        const category = EVENT_CATEGORY_LABELS[ev.event_category] ?? titleCase(ev.event_category);
                        const entityName = ev.client_name ?? ev.site_name ?? ev.staff_name ?? 'Unassigned';
                        const entitySub = ev.client_name && ev.site_name ? ev.site_name : ev.staff_name ? ev.staff_name : ev.site_name ? 'Site record' : 'No linked person';
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
                                    <div className="text-xs font-bold text-foreground" title={when.title}>
                                        {when.main}
                                    </div>
                                    <div className="mt-0.5 text-[11px] font-semibold text-muted-foreground">{ev.reference_number}</div>
                                </td>
                                <td className="max-w-[280px] px-4 py-3 align-top">
                                    <div className="flex items-start gap-2">
                                        <span className={`h-2 w-2 shrink-0 rounded-full ${TONE_DOT[sev.tone]}`} />
                                        <span className="min-w-0">
                                            <span className="block text-xs font-bold text-foreground">{category}</span>
                                            <span className="mt-0.5 block truncate text-[11px] text-muted-foreground">{eventContext(ev)}</span>
                                        </span>
                                    </div>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    {ev.source && mod ? (
                                        <div className="flex items-center gap-2">
                                            <span className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-md ${SOURCE_CHIP[ev.source.type] ?? 'bg-muted text-muted-foreground'}`}>
                                                <ModIcon className="h-3.5 w-3.5" />
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block text-xs font-bold text-foreground">{mod.label}</span>
                                                <span className="block truncate text-[11px] font-medium text-muted-foreground">{sourceRef(ev)}</span>
                                            </span>
                                        </div>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 rounded-md bg-status-warning-bg px-2 py-1 text-[11px] font-bold text-status-warning" title="No originating module">
                                            <Link2 className="h-3 w-3" /> Unwired category
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-3 align-top">
                                    {entityName ? (
                                        <span className="flex items-center gap-2">
                                            <span className={`grid h-7 w-7 shrink-0 place-items-center rounded-md text-[10px] font-bold ${entityTone(ev)}`}>
                                                {initials(entityName)}
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block truncate text-xs font-bold text-foreground">{entityName}</span>
                                                <span className="block truncate text-[11px] text-muted-foreground">{entitySub}</span>
                                            </span>
                                        </span>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">—</span>
                                    )}
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${TONE_BG[sev.tone]}`}>{sev.label}</span>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${stage.cls}`}>
                                        <StageIcon className="h-3 w-3" />
                                        {stage.label}
                                    </span>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <div className="flex flex-wrap items-center gap-1.5 text-muted-foreground">
                                        {ev.worksafe_notifiable ? (
                                            <FlagBadge icon={ShieldAlert} tone={ev.flags.worksafe_pending ? 'critical' : ev.worksafe_status === 'acknowledged' ? 'success' : 'warning'} title={ev.flags.worksafe_pending ? 'WorkSafe notification due' : `WorkSafe ${titleCase(ev.worksafe_status ?? 'notifiable')}`}>
                                                {ev.flags.worksafe_pending ? 'Pending' : titleCase(ev.worksafe_status ?? 'Notifiable')}
                                            </FlagBadge>
                                        ) : null}
                                        {ev.flags.investigation_overdue ? (
                                            <FlagBadge icon={Search} tone="critical" title="Investigation overdue">
                                                Inv overdue
                                            </FlagBadge>
                                        ) : ev.investigation_required && !ev.has_investigation ? (
                                            <FlagBadge icon={Search} tone="warning" title="Investigation required">
                                                Inv due
                                            </FlagBadge>
                                        ) : null}
                                        {ev.flags.awaiting_verification > 0 ? (
                                            <FlagBadge icon={ShieldCheck} tone="info" title={`${ev.flags.awaiting_verification} action(s) awaiting verification`}>
                                                {ev.flags.awaiting_verification} verify
                                            </FlagBadge>
                                        ) : null}
                                        {ev.has_open_actions ? (
                                            <FlagBadge icon={ListChecks} tone="info" title="Open corrective actions">
                                                Actions
                                            </FlagBadge>
                                        ) : null}
                                        {ev.flags.unwired ? (
                                            <FlagBadge icon={Link2} tone="warning" title="No originating record to link back to">
                                                Unwired
                                            </FlagBadge>
                                        ) : null}
                                        {!ev.worksafe_notifiable && !ev.flags.investigation_overdue && !(ev.investigation_required && !ev.has_investigation) && ev.flags.awaiting_verification === 0 && !ev.has_open_actions && !ev.flags.unwired ? (
                                            <span className="text-xs text-muted-foreground">—</span>
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
