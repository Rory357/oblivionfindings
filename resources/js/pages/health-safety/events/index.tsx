/* H&S Events register — the governance convergence view. Every incident type
 * lands here as an HsEvent for investigation, corrective action, WorkSafe
 * notification and gated closure. Standardised to the H&S gold standard
 * (hs-hero-kit hero, TabStrip, ShiftContextMenu, detail-as-modal) — the
 * governance twin of Incidents / Safeguarding / Fleet. NZ-only, web-only. */
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    HeroShell,
    HeroStatusPill,
    HeroMedallion,
    HeroCluster,
    HeroClusterTile,
    HeroSegmented,
    fmt,
    type Tone,
} from '@/pages/health-safety/components/hs-hero-kit';
import {
    EntityFilter,
    ShiftContextMenu,
    TabStrip,
    type RosterTabItem,
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
import { formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { useState, type MouseEvent as ReactMouseEvent } from 'react';
import {
    Activity,
    AlertTriangle,
    Bell,
    Calendar,
    CheckCircle2,
    Flame,
    Hand,
    HeartPulse,
    LayoutList,
    Link2,
    ListChecks,
    Search,
    Shield,
    ShieldAlert,
    ShieldCheck,
    Truck,
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
    source: { type: string; id: number; label: string; unwired: boolean } | null;
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

/* ------------------------------------------------------------------ */
/*  Token maps (semantic only)                                         */
/* ------------------------------------------------------------------ */

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
    ClientIncident: { label: 'Incident', icon: ShieldAlert },
    SafeguardingConcern: { label: 'Safeguarding', icon: ShieldCheck },
    FleetIncident: { label: 'Fleet', icon: Truck },
    WorkplaceInjury: { label: 'Injury', icon: HeartPulse },
    SiteHazard: { label: 'Hazard', icon: AlertTriangle },
    RestraintEvent: { label: 'Restraint', icon: Hand },
    EmergencyDrill: { label: 'Drill', icon: Flame },
};

const CATEGORY_OPTIONS = [
    'incident',
    'near_miss',
    'hazard',
    'injury',
    'restraint',
    'safeguarding',
    'vehicle_incident',
    'drill_failure',
];

function titleCase(s: string): string {
    return s.replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
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

    const hasFilters = !!(filters.q || filters.severity || filters.category || filters.site_id || filters.worksafe || filters.from || filters.to);

    const TABS: RosterTabItem[] = [
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
        ? 'all'
        : filters.from === daysAgoStr(7)
          ? 'week'
          : filters.from === daysAgoStr(30)
            ? '30d'
            : filters.from === daysAgoStr(90)
              ? 'quarter'
              : 'custom';
    const RANGE_ITEMS = [
        { key: 'all', label: 'All' },
        { key: 'week', label: 'This week' },
        { key: '30d', label: '30 days' },
        { key: 'quarter', label: 'Quarter' },
    ];
    const onRange = (key: string) => {
        if (key === 'all') {
            go({ from: null, to: null });
            return;
        }
        const map: Record<string, number> = { week: 7, '30d': 30, quarter: 90 };
        go({ from: daysAgoStr(map[key]), to: todayStr() });
    };

    const SEVERITY_ITEMS = [
        { key: 'all', label: 'All' },
        { key: 'low', label: 'Low' },
        { key: 'medium', label: 'Medium' },
        { key: 'high', label: 'High' },
        { key: 'critical', label: 'Critical' },
    ];

    /* ---- right-click context menu (mirrors the dialog Options bar) ---- */
    const openRowCtx = (e: ReactMouseEvent, ev: EventRow) => {
        e.preventDefault();
        const sev = SEV[ev.severity] ?? SEV.low;
        const items: ShiftCtxItem[] = [
            { icon: <Shield className="h-3.5 w-3.5" />, label: 'View event', sub: ev.reference_number, tone: 'primary', onClick: () => openEvent(ev.id) },
            { icon: <Search className="h-3.5 w-3.5" />, label: 'Investigation', onClick: () => openEvent(ev.id, { section: 'investigation' }) },
            { icon: <ListChecks className="h-3.5 w-3.5" />, label: 'Corrective actions', onClick: () => openEvent(ev.id, { section: 'actions' }) },
        ];
        if (ev.worksafe_notifiable && can.manage && ev.worksafe_status !== 'acknowledged') {
            if (ev.worksafe_status === 'notified') {
                items.push({ icon: <ShieldCheck className="h-3.5 w-3.5" />, label: 'Record WorkSafe acknowledgement', onClick: () => openEvent(ev.id, { action: 'worksafe_acknowledge' }) });
            } else {
                items.push({ icon: <ShieldAlert className="h-3.5 w-3.5" />, label: 'Record WorkSafe notification', tone: 'critical', onClick: () => openEvent(ev.id, { action: 'worksafe_notify' }) });
            }
        } else if (ev.worksafe_notifiable) {
            items.push({ icon: <ShieldAlert className="h-3.5 w-3.5" />, label: 'WorkSafe', sub: titleCase(ev.worksafe_status ?? 'pending'), onClick: () => openEvent(ev.id, { section: 'overview' }) });
        }
        if (can.manage && ev.status !== 'closed') {
            items.push({ icon: <CheckCircle2 className="h-3.5 w-3.5" />, label: 'Close event', tone: 'critical', onClick: () => openEvent(ev.id, { action: 'close' }) });
        }
        items.push({ sep: true }, { icon: <Link2 className="h-3.5 w-3.5" />, label: 'Open full page', onClick: () => router.visit(`/health-safety/events/${ev.id}`) });

        setCtx({ x: e.clientX, y: e.clientY, tag: sev.label.toUpperCase(), meta: `${ev.reference_number} · ${EVENT_CATEGORY_LABELS[ev.event_category] ?? titleCase(ev.event_category)}`, items });
    };

    const live = hero.live;
    const at = hero.attention;

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Events', href: '/health-safety/events' }]}>
            <Head title="Safety events" />

            <div className="flex flex-col gap-6 p-6">
                {/* ---- Hero ---- */}
                <HeroShell
                    footer={
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <HeroSegmented label="Period" variant="pill" ariaLabel="Date range" items={RANGE_ITEMS} value={activeRange} onChange={onRange} />
                            {sites?.length ? (
                                <EntityFilter label="Site" allLabel="All sites" items={sites} value={filters.site_id} onChange={(id) => go({ site_id: id })} onDark />
                            ) : null}
                            <label className="inline-flex items-center gap-1.5">
                                <span className="text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">Category</span>
                                <select
                                    value={filters.category ?? ''}
                                    onChange={(e) => go({ category: e.target.value || null })}
                                    className="rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-2 py-1 text-xs text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none [&>option]:text-foreground"
                                >
                                    <option value="">All categories</option>
                                    {CATEGORY_OPTIONS.map((c) => (
                                        <option key={c} value={c}>
                                            {EVENT_CATEGORY_LABELS[c] ?? titleCase(c)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <HeroSegmented
                                label="Severity"
                                variant="pill"
                                ariaLabel="Severity"
                                items={SEVERITY_ITEMS}
                                value={filters.severity ?? 'all'}
                                onChange={(key) => go({ severity: key === 'all' ? null : key })}
                            />
                            {/* eslint-disable-next-line no-restricted-syntax -- onDark toggle pill on the hero footer */}
                            <button
                                type="button"
                                aria-pressed={!!filters.worksafe}
                                onClick={() => go({ worksafe: filters.worksafe ? null : true })}
                                className={`inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 text-xs font-medium transition-colors ${
                                    filters.worksafe
                                        ? 'border-primary-foreground/40 bg-primary-foreground/25 text-primary-foreground'
                                        : 'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground/80 hover:bg-primary-foreground/20'
                                }`}
                            >
                                <ShieldAlert className="h-3.5 w-3.5" /> WorkSafe-notifiable
                            </button>
                            <div className="relative ml-auto">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                <input
                                    type="search"
                                    placeholder="Search reference…"
                                    defaultValue={filters.q ?? ''}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') go({ q: (e.target as HTMLInputElement).value || null });
                                    }}
                                    className="w-44 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pr-2.5 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
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
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-4">
                            <HeroMedallion icon={ShieldCheck} />
                            <div className="flex flex-col gap-1.5">
                                <HeroStatusPill>Safety events · governance register</HeroStatusPill>
                                <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">Safety events</h1>
                                <p className="max-w-xl text-sm text-primary-foreground/70">
                                    Every incident, near-miss, hazard and notifiable event converges here for investigation, corrective action, WorkSafe notification and a gated close — one record, from origin to verified.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div className="grid gap-3 lg:grid-cols-2">
                        <HeroCluster title="Live · open governance" icon={Shield}>
                            <HeroClusterTile href="/health-safety/events?tab=open" label="Open" value={fmt(live.open)} caption="awaiting triage" tone={live.open > 0 ? 'warning' : 'success'} />
                            <HeroClusterTile href="/health-safety/events?tab=investigating" label="Investigating" value={fmt(live.investigating)} caption="in progress" tone="neutral" />
                            <HeroClusterTile href="/health-safety/events?tab=corrective_actions" label="Corrective action" value={fmt(live.corrective_action)} caption="remediation" tone="neutral" />
                            <HeroClusterTile href="/health-safety/events?tab=monitoring" label="Monitoring" value={fmt(live.monitoring)} caption="residual risk" tone="neutral" />
                        </HeroCluster>
                        <HeroCluster title="Needs attention" icon={Bell}>
                            <HeroClusterTile href="/health-safety/events?tab=investigating" label="Investigation due" value={fmt(at.investigation_due)} caption={at.investigation_due > 0 ? 'required' : 'all started'} tone={at.investigation_due > 0 ? 'warning' : 'success'} />
                            <HeroClusterTile href="/health-safety/events?tab=corrective_actions" label="Awaiting verification" value={fmt(at.awaiting_verification)} caption="needs a verifier" tone={at.awaiting_verification > 0 ? 'warning' : 'success'} />
                            <HeroClusterTile href="/health-safety/events?tab=worksafe" label="WorkSafe notify due" value={fmt(at.worksafe_due)} caption={at.worksafe_due > 0 ? 'notify ASAP' : 'none pending'} tone={at.worksafe_due > 0 ? 'critical' : 'success'} />
                            <HeroClusterTile href="/health-safety/events?tab=closed" label="Closed" value={fmt(at.closed_period)} caption="this period" tone="neutral" />
                        </HeroCluster>
                    </div>
                </HeroShell>

                {/* ---- Tabs ---- */}
                <TabStrip value={tab} onChange={setTab} items={TABS} ariaLabel="Safety event views" />

                {/* ---- Rows ---- */}
                <Card>
                    <CardContent className="p-0">
                        <EventTable rows={events.data} onRowCtx={openRowCtx} onOpen={openEvent} />
                    </CardContent>
                </Card>

                {events.last_page > 1 ? <LaravelPagination links={events.links} /> : null}
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}

            {detail ? (
                <EventDetailDialog key={detail.id} detail={detail} open onClose={closeDetail} initialSection={pendingSection} initialAction={pendingAction} />
            ) : null}
        </AppLayout>
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
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                        <th className="px-4 py-2.5">When</th>
                        <th className="px-4 py-2.5">Event</th>
                        <th className="px-4 py-2.5">Source &amp; category</th>
                        <th className="px-4 py-2.5">Site / Client</th>
                        <th className="px-4 py-2.5">Severity</th>
                        <th className="px-4 py-2.5">Stage</th>
                        <th className="px-4 py-2.5">Flags</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((ev) => {
                        const sev = SEV[ev.severity] ?? SEV.low;
                        const stage = STAGE[ev.status] ?? STAGE.open;
                        const StageIcon = stage.icon;
                        const mod = ev.source ? SOURCE_MODULE[ev.source.type] : null;
                        const ModIcon = mod?.icon ?? Link2;
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
                                className="cursor-pointer transition-colors hover:bg-muted/40 focus-visible:bg-muted/40 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                            >
                                <td className="px-4 py-3 align-top whitespace-nowrap">
                                    <div className="font-medium">{ev.reference_number}</div>
                                    <div className="mt-0.5 flex items-center gap-1 text-[11px] text-muted-foreground/70">
                                        <Calendar className="h-3 w-3" />
                                        {ev.occurred_at ? formatDateTime(ev.occurred_at) : '—'}
                                    </div>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <div className="flex items-center gap-2">
                                        <span className={`h-2 w-2 shrink-0 rounded-full ${TONE_DOT[sev.tone]}`} />
                                        <span className="font-medium">{EVENT_CATEGORY_LABELS[ev.event_category] ?? titleCase(ev.event_category)}</span>
                                    </div>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    {ev.source && mod ? (
                                        <div className="flex items-center gap-2">
                                            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-muted">
                                                <ModIcon className="h-3.5 w-3.5 text-muted-foreground" />
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block text-xs font-medium text-foreground">{mod.label}</span>
                                                <span className="block truncate text-[11px] text-muted-foreground">#{ev.source.id}</span>
                                            </span>
                                        </div>
                                    ) : (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground" title="No originating module">
                                            <Link2 className="h-3 w-3" /> Unwired
                                        </span>
                                    )}
                                </td>
                                <td className="px-4 py-3 align-top">
                                    {ev.site_name || ev.client_name ? (
                                        <span className="min-w-0">
                                            {ev.site_name ? <span className="block truncate">{ev.site_name}</span> : null}
                                            {ev.client_name ? <span className="block truncate text-xs text-muted-foreground">{ev.client_name}</span> : null}
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
                                    <div className="flex items-center gap-1.5 text-muted-foreground">
                                        {ev.worksafe_notifiable ? (
                                            <span
                                                className={`inline-flex items-center gap-0.5 ${ev.flags.worksafe_pending ? 'text-status-critical' : 'text-status-success'}`}
                                                aria-label={ev.flags.worksafe_pending ? 'WorkSafe notification due' : 'WorkSafe notified'}
                                                title={ev.flags.worksafe_pending ? 'WorkSafe notification due' : 'WorkSafe notified'}
                                            >
                                                <ShieldAlert className="h-3.5 w-3.5" />
                                            </span>
                                        ) : null}
                                        {ev.flags.investigation_overdue ? (
                                            <Search className="h-3.5 w-3.5 text-status-critical" aria-label="Investigation overdue" />
                                        ) : ev.investigation_required && !ev.has_investigation ? (
                                            <Search className="h-3.5 w-3.5 text-status-warning" aria-label="Investigation required" />
                                        ) : null}
                                        {ev.flags.awaiting_verification > 0 ? (
                                            <span className="inline-flex items-center gap-0.5 text-status-warning" aria-label={`${ev.flags.awaiting_verification} action(s) awaiting verification`} title={`${ev.flags.awaiting_verification} action(s) awaiting verification`}>
                                                <ShieldCheck className="h-3.5 w-3.5" />
                                                <span className="text-[10px] font-medium">{ev.flags.awaiting_verification}</span>
                                            </span>
                                        ) : null}
                                        {ev.has_open_actions ? <ListChecks className="h-3.5 w-3.5 text-status-info" aria-label="Open corrective actions" /> : null}
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
