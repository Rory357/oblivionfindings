import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
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
import { IncidentDetailDialog, type IncidentDetail } from '@/components/incidents/incident-detail-dialog';
import { IncidentReportDialog } from '@/components/incidents/incident-report-dialog';
import { formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { useState, type MouseEvent as ReactMouseEvent } from 'react';
import {
    Activity,
    AlertTriangle,
    Bell,
    Bot,
    Calendar,
    CheckCircle2,
    CircleDot,
    Clock,
    Cpu,
    Eye,
    FileEdit,
    LayoutList,
    ListTodo,
    Paperclip,
    Plus,
    RadioTower,
    Search,
    Send,
    ShieldAlert,
    Stethoscope,
    User,
    UserCog,
    Wrench,
    X,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Types                                                               */
/* ------------------------------------------------------------------ */

type IncidentRow = {
    id: number;
    occurred_at: string | null;
    type: string;
    description: string | null;
    severity: string;
    status: string;
    source: string;
    interactive: boolean;
    is_notifiable: boolean;
    worksafe_notification_status: string | null;
    potential_severity: string | null;
    investigation_status: string | null;
    control_room_alert_id: number | null;
    requires_followup: boolean;
    attachments_count: number;
    open_followups_count: number;
    client: { id: number; first_name: string; last_name: string; site: string | null } | null;
    reporter: { name: string } | null;
};

type FollowupRow = {
    id: number;
    incident_id: number;
    incident_type: string | null;
    client_name: string | null;
    assigned_to: string | null;
    due_at: string | null;
    overdue: boolean;
    notes: string | null;
};

type Paginated<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; last_page: number };

type Filters = {
    q: string;
    tab: string;
    severity: string | null;
    client_id: number | null;
    site_id: number | null;
    source: string | null;
    from: string | null;
    to: string | null;
};

type Props = {
    filters: Filters;
    tab: string;
    tabCounts: Record<string, number>;
    rows: Paginated<IncidentRow | FollowupRow>;
    rowsKind: 'incidents' | 'followups';
    hero: {
        period: {
            reported: { value: number; delta: number };
            open: { value: number };
            investigation: { value: number };
            closed: { value: number; delta: number };
        };
        attention: {
            followups: { value: number; overdue: number };
            review: { value: number };
            worksafe: { value: number };
            alerts: { value: number };
        };
    };
    nearMissInsights: {
        trend_pct: number | null;
        ratio: number | null;
        by_potential: Record<string, number>;
    };
    sites?: Array<{ id: number; name: string }> | null;
    clients?: Array<{ id: number; first_name: string; last_name: string }> | null;
    reportClients?: Array<{ id: number; first_name: string; last_name: string }> | null;
    reportStaff?: Array<{ id: number; name: string }> | null;
    can: { create: boolean; templatesManage: boolean };
    detail: IncidentDetail | null;
};

/* ------------------------------------------------------------------ */
/*  Token maps                                                          */
/* ------------------------------------------------------------------ */

const SEV: Record<string, { tone: Tone; label: string }> = {
    low: { tone: 'success', label: 'Low' },
    medium: { tone: 'warning', label: 'Medium' },
    high: { tone: 'critical', label: 'High' },
    critical: { tone: 'critical', label: 'Critical' },
};

const TONE_TEXT: Record<Tone, string> = {
    success: 'text-status-success',
    warning: 'text-status-warning',
    critical: 'text-status-critical',
    neutral: 'text-muted-foreground',
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

const STATUS: Record<string, { label: string; cls: string; icon: typeof Clock }> = {
    draft: { label: 'Draft', cls: 'bg-muted text-foreground', icon: FileEdit },
    submitted: { label: 'Submitted', cls: 'bg-status-info-bg text-status-info', icon: Clock },
    reviewed: { label: 'Reviewed', cls: 'bg-primary/10 text-primary', icon: CheckCircle2 },
    closed: { label: 'Closed', cls: 'bg-status-success-bg text-status-success', icon: CheckCircle2 },
};

const SOURCE: Record<string, { label: string; icon: typeof User }> = {
    manual: { label: 'Staff-reported', icon: User },
    control_room: { label: 'Control Room', icon: RadioTower },
    sensor: { label: 'Sensor', icon: Cpu },
    automated: { label: 'Automated', icon: Bot },
};

const SEV_ORDER = ['critical', 'high', 'medium', 'low'];

function deltaStr(d: number): string | undefined {
    if (d > 0) return `▲ ${d}`;
    if (d < 0) return `▼ ${Math.abs(d)}`;
    return undefined;
}

function titleCase(s: string): string {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
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

export default function IncidentsIndex({
    filters,
    tab,
    tabCounts,
    rows,
    rowsKind,
    hero,
    nearMissInsights,
    sites,
    clients,
    reportClients,
    reportStaff,
    can,
    detail,
}: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [reportMode, setReportMode] = useState<'incident' | 'near_miss' | null>(null);
    const [launcherOpen, setLauncherOpen] = useState(false);
    const openReport = (m: 'incident' | 'near_miss') => {
        setLauncherOpen(false);
        setReportMode(m);
    };

    const go = (next: Partial<Filters>) =>
        router.get('/incidents', { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });

    const setTab = (id: string) => router.get('/incidents', { ...filters, tab: id }, { preserveScroll: true });

    // Detail-over-list: fetch only the `detail` prop and open the dialog without
    // navigating away; closing drops the param so `detail` comes back null.
    const openDetail = (id: number) =>
        router.get('/incidents', { ...filters, incident: id }, { preserveState: true, preserveScroll: true, only: ['detail'] });
    const closeDetail = () =>
        router.get('/incidents', { ...filters }, { preserveState: true, preserveScroll: true, only: ['detail'] });

    const clearFilters = () =>
        router.get('/incidents', { tab }, { preserveState: true, preserveScroll: true, replace: true });

    const hasFilters = !!(
        filters.q ||
        filters.severity ||
        filters.client_id ||
        filters.site_id ||
        filters.source ||
        filters.from ||
        filters.to
    );

    /* ---- hero stat clusters ---- */
    const p = hero.period;
    const a = hero.attention;

    /* ---- tabs ---- */
    const TABS: RosterTabItem[] = [
        { id: 'all', label: 'All', icon: LayoutList, tone: 'primary', badge: tabCounts.all || undefined },
        { id: 'open', label: 'Open', icon: CircleDot, tone: 'info', badge: tabCounts.open || undefined },
        { id: 'investigation', label: 'Under investigation', icon: Search, tone: 'primary', badge: tabCounts.investigation || undefined },
        { id: 'followups', label: 'Follow-ups due', icon: ListTodo, tone: 'warning', badge: tabCounts.followups || undefined },
        { id: 'worksafe', label: 'WorkSafe-notifiable', icon: ShieldAlert, tone: 'critical', badge: tabCounts.worksafe || undefined },
        { id: 'near_misses', label: 'Near misses', icon: Eye, tone: 'success', badge: tabCounts.near_misses || undefined },
        { id: 'review', label: 'Awaiting review', icon: Clock, tone: 'info', badge: tabCounts.review || undefined },
        { id: 'closed', label: 'Closed', icon: CheckCircle2, tone: 'success', badge: tabCounts.closed || undefined },
    ];

    /* ---- source filter (segmented pill on dark) ---- */
    const SOURCE_ITEMS = [
        { key: 'all', label: 'All sources' },
        { key: 'manual', label: 'Staff' },
        { key: 'control_room', label: 'Control Room' },
        { key: 'sensor', label: 'Sensor' },
    ];

    /* ---- date range (footer pills) ---- */
    const activeRange = !filters.from
        ? ''
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
        {
            key: 'custom',
            label: 'Custom',
            popover: (
                <div className="space-y-2">
                    <label className="block text-xs font-medium text-foreground">
                        From
                        <input
                            type="date"
                            defaultValue={filters.from ?? ''}
                            onChange={(e) => go({ from: e.target.value || null })}
                            className="mt-1 block w-full rounded-md border border-border bg-background px-2 py-1 text-sm"
                        />
                    </label>
                    <label className="block text-xs font-medium text-foreground">
                        To
                        <input
                            type="date"
                            defaultValue={filters.to ?? ''}
                            onChange={(e) => go({ to: e.target.value || null })}
                            className="mt-1 block w-full rounded-md border border-border bg-background px-2 py-1 text-sm"
                        />
                    </label>
                </div>
            ),
        },
    ];
    const onRange = (key: string) => {
        if (key === 'custom') return;
        const map: Record<string, number> = { week: 7, '30d': 30, quarter: 90 };
        go({ from: daysAgoStr(map[key]), to: todayStr() });
    };

    /* ---- right-click context menu (incident rows) ---- */
    const openRowCtx = (e: ReactMouseEvent, i: IncidentRow) => {
        e.preventDefault();
        const sev = SEV[i.severity] ?? SEV.low;
        const clientName = i.client ? `${i.client.first_name} ${i.client.last_name}`.trim() : 'No client';
        const items: ShiftCtxItem[] = [
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'View incident', sub: `${titleCase(i.type)}${i.occurred_at ? ` · ${formatDateTime(i.occurred_at)}` : ''}`, tone: 'primary', onClick: () => openDetail(i.id) },
            ...(i.status === 'draft' ? [{ icon: <FileEdit className="h-3.5 w-3.5" />, label: 'Continue draft', onClick: () => router.visit(`/incidents/create?incident=${i.id}`) } satisfies ShiftCtxItem] : []),
            { sep: true },
            ...(i.client ? [{ icon: <User className="h-3.5 w-3.5" />, label: 'View client', sub: clientName, onClick: () => router.visit(`/operations/clients/${i.client!.id}/care`) } satisfies ShiftCtxItem] : []),
            ...(i.control_room_alert_id ? [{ icon: <RadioTower className="h-3.5 w-3.5" />, label: 'View Control Room alert', onClick: () => router.visit(`/control-room/alerts/${i.control_room_alert_id}`) } satisfies ShiftCtxItem] : []),
            ...(i.status === 'draft' ? [{ sep: true } satisfies ShiftCtxItem, { icon: <Send className="h-3.5 w-3.5" />, label: 'Submit for review', onClick: () => router.post(`/incidents/${i.id}/submit`) } satisfies ShiftCtxItem] : []),
        ];
        setCtx({ x: e.clientX, y: e.clientY, tag: (sev.label ?? i.severity).toUpperCase(), meta: `${clientName} · ${titleCase(i.type)}`, items });
    };

    const incidentRows = rowsKind === 'incidents' ? (rows.data as IncidentRow[]) : [];
    const followupRows = rowsKind === 'followups' ? (rows.data as FollowupRow[]) : [];

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Incidents', href: '/incidents' }]}>
            <Head title="Incidents" />

            <div className="flex flex-col gap-6 p-6">
                {/* ---- Hero ---- */}
                <HeroShell
                    footer={
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <HeroSegmented label="Period" variant="pill" ariaLabel="Date range" items={RANGE_ITEMS} value={activeRange} onChange={onRange} />
                            {sites?.length ? (
                                <EntityFilter label="Site" allLabel="All sites" items={sites} value={filters.site_id} onChange={(id) => go({ site_id: id })} onDark />
                            ) : null}
                            {clients?.length ? (
                                <EntityFilter
                                    label="Client"
                                    allLabel="All clients"
                                    items={clients.map((c) => ({ id: c.id, name: `${c.first_name} ${c.last_name}`.trim() }))}
                                    value={filters.client_id}
                                    onChange={(id) => go({ client_id: id })}
                                    onDark
                                />
                            ) : null}
                            <HeroSegmented
                                label="Source"
                                variant="pill"
                                ariaLabel="Source"
                                items={SOURCE_ITEMS}
                                value={filters.source ?? 'all'}
                                onChange={(key) => go({ source: key === 'all' ? null : key })}
                            />
                            <div className="relative ml-auto">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                <input
                                    type="search"
                                    placeholder="Search incidents…"
                                    defaultValue={filters.q}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') go({ q: (e.target as HTMLInputElement).value });
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
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-4">
                            <HeroMedallion icon={AlertTriangle} />
                            <div className="flex flex-col gap-1.5">
                                <HeroStatusPill>Incident register · synced just now</HeroStatusPill>
                                <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">Incidents</h1>
                                <p className="max-w-xl text-sm text-primary-foreground/70">
                                    Report, triage and close the loop on incidents and near misses — from the Control Room desk through to Health &amp; Safety investigation.
                                </p>
                            </div>
                        </div>

                        {can.create ? (
                            <Popover open={launcherOpen} onOpenChange={setLauncherOpen}>
                                <PopoverTrigger asChild>
                                    <Button size="sm" className="bg-primary-foreground text-primary hover:bg-primary-foreground/90">
                                        <Plus className="mr-1.5 h-4 w-4" /> Report
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent align="end" className="w-64 p-1.5">
                                    <button type="button" onClick={() => openReport('incident')} className="flex w-full items-start gap-2.5 rounded-md p-2.5 text-left transition-colors hover:bg-muted">
                                        <ShieldAlert className="mt-0.5 h-4 w-4 shrink-0 text-status-critical" />
                                        <span>
                                            <span className="block text-sm font-medium">An incident</span>
                                            <span className="block text-xs text-muted-foreground">Something happened to a client or worker.</span>
                                        </span>
                                    </button>
                                    <button type="button" onClick={() => openReport('near_miss')} className="flex w-full items-start gap-2.5 rounded-md p-2.5 text-left transition-colors hover:bg-muted">
                                        <Eye className="mt-0.5 h-4 w-4 shrink-0 text-status-success" />
                                        <span>
                                            <span className="block text-sm font-medium">A near miss</span>
                                            <span className="block text-xs text-muted-foreground">No harm done — takes under a minute.</span>
                                        </span>
                                    </button>
                                </PopoverContent>
                            </Popover>
                        ) : null}
                    </div>

                    {/* stat clusters */}
                    <div className="grid gap-3 lg:grid-cols-2">
                        <HeroCluster title="This period · last 30 days" icon={Activity}>
                            <HeroClusterTile href="/incidents?tab=all" label="Reported" value={fmt(p.reported.value)} caption="last 30 days" tone="neutral" delta={deltaStr(p.reported.delta)} deltaTone="neutral" />
                            <HeroClusterTile href="/incidents?tab=open" label="Open" value={fmt(p.open.value)} caption="not yet closed" tone="warning" />
                            <HeroClusterTile href="/incidents?tab=investigation" label="Investigating" value={fmt(p.investigation.value)} caption="under investigation" tone="critical" />
                            <HeroClusterTile href="/incidents?tab=closed" label="Closed" value={fmt(p.closed.value)} caption="last 30 days" tone="success" delta={deltaStr(p.closed.delta)} deltaTone={p.closed.delta >= 0 ? 'success' : 'critical'} />
                        </HeroCluster>
                        <HeroCluster title="Needs attention" icon={Bell}>
                            <HeroClusterTile href="/incidents?tab=followups" label="Follow-ups due" value={fmt(a.followups.value)} caption={a.followups.overdue > 0 ? `${a.followups.overdue} overdue` : 'all on track'} tone={a.followups.overdue > 0 ? 'critical' : 'warning'} />
                            <HeroClusterTile href="/incidents?tab=review" label="Awaiting review" value={fmt(a.review.value)} caption="submitted" tone="warning" />
                            <HeroClusterTile href="/incidents?tab=worksafe" label="WorkSafe" value={fmt(a.worksafe.value)} caption="awaiting notify" tone={a.worksafe.value > 0 ? 'critical' : 'success'} />
                            <HeroClusterTile label="Active CR alerts" value={fmt(a.alerts.value)} caption="Control Room" tone={a.alerts.value > 0 ? 'critical' : 'success'} />
                        </HeroCluster>
                    </div>
                </HeroShell>

                {/* ---- Tabs ---- */}
                <TabStrip value={tab} onChange={setTab} items={TABS} ariaLabel="Incident views" />

                {/* ---- Near-miss insights strip ---- */}
                {tab === 'near_misses' ? <NearMissInsights insights={nearMissInsights} /> : null}

                {/* ---- Rows ---- */}
                <Card>
                    <CardContent className="p-0">
                        {rowsKind === 'incidents' ? (
                            <IncidentTable rows={incidentRows} onRowCtx={openRowCtx} onOpen={openDetail} />
                        ) : (
                            <FollowupTable rows={followupRows} onOpen={openDetail} />
                        )}
                    </CardContent>
                </Card>

                {rows.last_page > 1 ? <LaravelPagination links={rows.links} /> : null}
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}

            {detail ? <IncidentDetailDialog detail={detail} open onClose={closeDetail} /> : null}

            {reportMode && reportClients ? (
                <IncidentReportDialog
                    open
                    mode={reportMode}
                    clients={reportClients}
                    staff={reportStaff ?? []}
                    onClose={() => setReportMode(null)}
                    onOpenIncident={(id) => {
                        setReportMode(null);
                        openDetail(id);
                    }}
                />
            ) : null}
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Near-miss insights                                                 */
/* ------------------------------------------------------------------ */

function NearMissInsights({ insights }: { insights: Props['nearMissInsights'] }) {
    const total = SEV_ORDER.reduce((sum, k) => sum + (insights.by_potential[k] ?? 0), 0);
    return (
        <div className="flex flex-wrap items-center gap-x-8 gap-y-3 rounded-xl border border-status-success/30 bg-status-success-bg/40 px-4 py-3">
            <div className="flex items-center gap-2">
                <Eye className="h-4 w-4 text-status-success" />
                <span className="text-sm font-semibold text-foreground">Near-miss reporting</span>
                <span className="text-xs text-muted-foreground">leading safety indicator</span>
            </div>
            <Metric label="Reporting trend (30d)" value={insights.trend_pct === null ? '—' : `${insights.trend_pct > 0 ? '+' : ''}${insights.trend_pct}%`} tone={insights.trend_pct !== null && insights.trend_pct >= 0 ? 'success' : 'warning'} />
            <Metric label="Near-miss : incident (90d)" value={insights.ratio === null ? '—' : `${insights.ratio} : 1`} tone="success" />
            <div className="min-w-[180px] flex-1">
                <p className="mb-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">What could have happened</p>
                {total === 0 ? (
                    <p className="text-xs text-muted-foreground">No near misses with a recorded potential severity yet.</p>
                ) : (
                    <div className="flex flex-col gap-1">
                        {SEV_ORDER.filter((k) => (insights.by_potential[k] ?? 0) > 0).map((k) => {
                            const v = insights.by_potential[k] ?? 0;
                            const tone = SEV[k]?.tone ?? 'neutral';
                            return (
                                <div key={k} className="flex items-center gap-2">
                                    <span className="w-16 text-xs capitalize text-muted-foreground">{k}</span>
                                    <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                        <div className={`h-full rounded-full ${TONE_DOT[tone]}`} style={{ width: `${Math.round((v / total) * 100)}%` }} />
                                    </div>
                                    <span className="w-6 text-right text-xs tabular-nums text-foreground">{v}</span>
                                </div>
                            );
                        })}
                    </div>
                )}
            </div>
        </div>
    );
}

function Metric({ label, value, tone }: { label: string; value: string; tone: Tone }) {
    return (
        <div>
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">{label}</p>
            <p className={`text-lg font-bold tabular-nums ${TONE_TEXT[tone]}`}>{value}</p>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Incident table                                                     */
/* ------------------------------------------------------------------ */

function IncidentTable({ rows, onRowCtx, onOpen }: { rows: IncidentRow[]; onRowCtx: (e: ReactMouseEvent, i: IncidentRow) => void; onOpen: (id: number) => void }) {
    if (!rows.length) {
        return (
            <div className="px-4 py-16 text-center">
                <ShieldAlert className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                <p className="font-medium text-muted-foreground">No incidents here</p>
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
                        <th className="px-4 py-2.5">Incident</th>
                        <th className="px-4 py-2.5">Client</th>
                        <th className="px-4 py-2.5">Severity</th>
                        <th className="px-4 py-2.5">Status</th>
                        <th className="px-4 py-2.5">Source</th>
                        <th className="px-4 py-2.5">Flags</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((i) => {
                        const sev = SEV[i.severity] ?? SEV.low;
                        const stat = STATUS[i.status] ?? STATUS.draft;
                        const StatusIcon = stat.icon;
                        const src = SOURCE[i.source] ?? SOURCE.manual;
                        const SrcIcon = src.icon;
                        const clientName = i.client ? `${i.client.first_name} ${i.client.last_name}`.trim() : null;
                        const isNearMiss = i.type === 'near_miss';
                        return (
                            <tr
                                key={i.id}
                                onClick={() => onOpen(i.id)}
                                onContextMenu={(e) => onRowCtx(e, i)}
                                className="cursor-pointer transition-colors hover:bg-muted/40"
                            >
                                <td className="px-4 py-3 align-top whitespace-nowrap">
                                    <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <Calendar className="h-3 w-3" />
                                        {i.occurred_at ? formatDateTime(i.occurred_at) : '—'}
                                    </div>
                                    <div className="mt-0.5 text-[11px] text-muted-foreground/60">INC-{i.id}</div>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <div className="flex items-center gap-2">
                                        <span className={`h-2 w-2 shrink-0 rounded-full ${TONE_DOT[sev.tone]}`} />
                                        <span className="font-medium capitalize">{titleCase(i.type)}</span>
                                    </div>
                                    {i.description ? <p className="mt-0.5 line-clamp-1 max-w-md text-xs text-muted-foreground">{i.description}</p> : null}
                                </td>
                                <td className="px-4 py-3 align-top">
                                    {clientName ? (
                                        <div className="flex items-center gap-2">
                                            <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[11px] font-semibold text-primary">
                                                {clientName.split(' ').map((n) => n[0]).slice(0, 2).join('')}
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block truncate font-medium">{clientName}</span>
                                                {i.client?.site ? <span className="block truncate text-xs text-muted-foreground">{i.client.site}</span> : null}
                                            </span>
                                        </div>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">—</span>
                                    )}
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${TONE_BG[sev.tone]}`}>
                                        {isNearMiss && i.potential_severity ? `Potential: ${SEV[i.potential_severity]?.label ?? i.potential_severity}` : sev.label}
                                    </span>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${stat.cls}`}>
                                        <StatusIcon className="h-3 w-3" />
                                        {stat.label}
                                    </span>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground" title={src.label}>
                                        <SrcIcon className="h-3.5 w-3.5" />
                                        {src.label}
                                    </span>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <div className="flex items-center gap-1.5 text-muted-foreground">
                                        {i.control_room_alert_id ? <RadioTower className="h-3.5 w-3.5 text-status-info" aria-label="Linked to Control Room alert" /> : null}
                                        {i.investigation_status && i.investigation_status !== 'not_required' ? <Search className="h-3.5 w-3.5 text-primary" aria-label="Investigation" /> : null}
                                        {i.is_notifiable ? <ShieldAlert className="h-3.5 w-3.5 text-status-critical" aria-label="WorkSafe-notifiable" /> : null}
                                        {i.open_followups_count > 0 ? (
                                            <span className="inline-flex items-center gap-0.5 text-xs text-status-warning" aria-label="Open follow-ups">
                                                <ListTodo className="h-3.5 w-3.5" />
                                                {i.open_followups_count}
                                            </span>
                                        ) : null}
                                        {i.attachments_count > 0 ? (
                                            <span className="inline-flex items-center gap-0.5 text-xs" aria-label="Attachments">
                                                <Paperclip className="h-3.5 w-3.5" />
                                                {i.attachments_count}
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

/* ------------------------------------------------------------------ */
/*  Follow-ups worklist                                                */
/* ------------------------------------------------------------------ */

function FollowupTable({ rows, onOpen }: { rows: FollowupRow[]; onOpen: (id: number) => void }) {
    if (!rows.length) {
        return (
            <div className="px-4 py-16 text-center">
                <CheckCircle2 className="mx-auto mb-3 h-10 w-10 text-status-success/50" />
                <p className="font-medium text-muted-foreground">No open follow-ups</p>
                <p className="mt-1 text-sm text-muted-foreground/70">Every follow-up across these incidents is complete.</p>
            </div>
        );
    }
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                        <th className="px-4 py-2.5">Follow-up</th>
                        <th className="px-4 py-2.5">Incident</th>
                        <th className="px-4 py-2.5">Client</th>
                        <th className="px-4 py-2.5">Owner</th>
                        <th className="px-4 py-2.5">Due</th>
                        <th className="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((f) => (
                        <tr
                            key={f.id}
                            onClick={() => onOpen(f.incident_id)}
                            className="cursor-pointer transition-colors hover:bg-muted/40"
                        >
                            <td className="px-4 py-3 align-top">
                                <div className="flex items-start gap-2">
                                    <Wrench className="mt-0.5 h-3.5 w-3.5 shrink-0 text-status-warning" />
                                    <span className="line-clamp-2 max-w-md">{f.notes ?? 'Follow-up task'}</span>
                                </div>
                            </td>
                            <td className="px-4 py-3 align-top whitespace-nowrap text-muted-foreground">
                                <span className="font-medium text-foreground">INC-{f.incident_id}</span>
                                {f.incident_type ? <span className="block text-xs capitalize">{titleCase(f.incident_type)}</span> : null}
                            </td>
                            <td className="px-4 py-3 align-top">{f.client_name ?? '—'}</td>
                            <td className="px-4 py-3 align-top">
                                <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                    {f.assigned_to ? <UserCog className="h-3.5 w-3.5" /> : null}
                                    {f.assigned_to ?? 'Unassigned'}
                                </span>
                            </td>
                            <td className="px-4 py-3 align-top whitespace-nowrap">
                                {f.due_at ? (
                                    <span className={`inline-flex items-center gap-1 text-xs ${f.overdue ? 'font-semibold text-status-critical' : 'text-muted-foreground'}`}>
                                        <Clock className="h-3.5 w-3.5" />
                                        {formatDateTime(f.due_at)}
                                        {f.overdue ? ' · overdue' : ''}
                                    </span>
                                ) : (
                                    <span className="text-xs text-muted-foreground">No due date</span>
                                )}
                            </td>
                            <td className="px-4 py-3 align-top text-right">
                                <span className="inline-flex items-center gap-1 text-xs text-primary">
                                    <Stethoscope className="h-3.5 w-3.5" /> Open
                                </span>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
