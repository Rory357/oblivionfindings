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
import { FleetIncidentDialog, type FleetIncidentDetail } from '@/components/fleet/fleet-incident-dialog';
import { FleetIncidentReportDialog, type ReportMode } from '@/components/fleet/fleet-incident-report-dialog';
import { FleetTelematicsStoryboard } from '@/components/fleet/fleet-telematics-storyboard';
import { formatDateTime } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState, type MouseEvent as ReactMouseEvent, type ReactNode } from 'react';
import {
    Activity,
    AlertTriangle,
    Bell,
    Box,
    Calendar,
    CheckCircle2,
    CircleDot,
    CircleSlash,
    Clock,
    CreditCard,
    Download,
    Edit2,
    Eye,
    FileText,
    LayoutList,
    ListTodo,
    Paperclip,
    Plus,
    RadioTower,
    Search,
    ShieldAlert,
    Truck,
    User,
    X,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Types                                                               */
/* ------------------------------------------------------------------ */

type IncidentRow = {
    id: number;
    reference: string;
    asset: { id: number; name: string; registration_number: string | null; category: string | null } | null;
    reported_by: { id: number; name: string } | null;
    driver: { id: number; name: string } | null;
    incident_type: string;
    severity: string;
    occurred_at: string | null;
    location: string | null;
    status: string;
    flags: {
        police_report_due: boolean;
        police_report_due_at: string | null;
        police_hours_remaining: number | null;
        injury: boolean;
        off_road: boolean;
        claim_open: boolean;
        alert_linked: boolean;
        attachments: number;
        followups: number;
    };
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    meta: { current_page: number; last_page: number; total: number };
};

type Filters = {
    tab: string;
    search: string | null;
    severity: string | null;
    incident_type: string | null;
    site_id: number | null;
    vehicle_id: number | null;
    driver_id: number | null;
    date_from: string | null;
    date_to: string | null;
};

type Props = {
    incidents: Paginated<IncidentRow>;
    tab: string;
    tabCounts: Record<string, number>;
    stats: {
        reported: number;
        investigating: number;
        resolved: number;
        closed: number;
        police_due: number;
        worksafe_notifiable: number;
        off_road: number;
        open_claims: number;
        injury_acc: number;
    };
    filters: Filters;
    formOptions: {
        assets: Array<{ id: number; name: string; registration_number: string | null; category: string | null }>;
        users: Array<{ id: number; name: string }>;
        sites: Array<{ id: number; name: string }>;
        types: string[];
        severities: string[];
        injury_severities: string[];
        damage_classifications: string[];
    };
    can: { manage: boolean };
    detail: FleetIncidentDetail | null;
    report: string | null;
};

/* ------------------------------------------------------------------ */
/*  Token maps                                                          */
/* ------------------------------------------------------------------ */

const SEV: Record<string, { tone: Tone; label: string }> = {
    minor: { tone: 'success', label: 'Minor' },
    moderate: { tone: 'warning', label: 'Moderate' },
    major: { tone: 'critical', label: 'Major' },
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

const STATUS: Record<string, { label: string; cls: string; icon: typeof Clock }> = {
    reported: { label: 'Reported', cls: 'bg-status-info-bg text-status-info', icon: Clock },
    investigating: { label: 'Investigating', cls: 'bg-status-warning-bg text-status-warning', icon: Search },
    resolved: { label: 'Resolved', cls: 'bg-primary/10 text-primary', icon: CheckCircle2 },
    closed: { label: 'Closed', cls: 'bg-status-success-bg text-status-success', icon: CheckCircle2 },
};

const REPORT_MODES: ReportMode[] = ['vehicle', 'asset', 'near_miss'];

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

export default function FleetIncidentsIndex({
    incidents,
    tab,
    tabCounts,
    stats,
    filters,
    formOptions,
    can,
    detail,
    report,
}: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [launcherOpen, setLauncherOpen] = useState(false);
    const [telematicsOpen, setTelematicsOpen] = useState(false);
    const [reportMode, setReportMode] = useState<ReportMode | null>(
        report && REPORT_MODES.includes(report as ReportMode) ? (report as ReportMode) : null,
    );
    const openReport = (m: ReportMode) => {
        setLauncherOpen(false);
        setReportMode(m);
    };

    const go = (next: Partial<Filters>) =>
        router.get('/fleet-assets/incidents', { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });

    const setTab = (id: string) => router.get('/fleet-assets/incidents', { ...filters, tab: id }, { preserveScroll: true });

    // Detail-over-list: fetch only the `detail` prop and open the dialog without
    // navigating away; closing drops the param so `detail` comes back null.
    const openDetail = (id: number) =>
        router.get('/fleet-assets/incidents', { ...filters, incident: id }, { preserveState: true, preserveScroll: true, only: ['detail'] });
    const closeDetail = () =>
        router.get('/fleet-assets/incidents', { ...filters }, { preserveState: true, preserveScroll: true, only: ['detail'] });

    const clearFilters = () => router.get('/fleet-assets/incidents', { tab }, { preserveState: true, preserveScroll: true, replace: true });

    const hasFilters = !!(
        filters.search ||
        filters.severity ||
        filters.incident_type ||
        filters.site_id ||
        filters.vehicle_id ||
        filters.driver_id ||
        filters.date_from ||
        filters.date_to
    );

    /* ---- tabs ---- */
    const TABS: RosterTabItem[] = [
        { id: 'all', label: 'All', icon: LayoutList, tone: 'primary', badge: tabCounts.all || undefined },
        { id: 'open', label: 'Open', icon: CircleDot, tone: 'info', badge: tabCounts.open || undefined },
        { id: 'under_investigation', label: 'Under investigation', icon: Search, tone: 'primary', badge: tabCounts.under_investigation || undefined },
        { id: 'police_report_due', label: 'Police report due', icon: ShieldAlert, tone: 'critical', badge: tabCounts.police_report_due || undefined },
        { id: 'injury_acc', label: 'Injury / ACC', icon: AlertTriangle, tone: 'critical', badge: tabCounts.injury_acc || undefined },
        { id: 'insurance_claims', label: 'Insurance & claims', icon: CreditCard, tone: 'warning', badge: tabCounts.insurance_claims || undefined },
        { id: 'off_road', label: 'Off-road (VOR)', icon: CircleSlash, tone: 'warning', badge: tabCounts.off_road || undefined },
        { id: 'near_misses', label: 'Near misses', icon: Eye, tone: 'success', badge: tabCounts.near_misses || undefined },
        { id: 'closed', label: 'Closed', icon: CheckCircle2, tone: 'success', badge: tabCounts.closed || undefined },
    ];

    /* ---- date range (footer pills) ---- */
    const activeRange = !filters.date_from
        ? ''
        : filters.date_from === daysAgoStr(7)
          ? 'week'
          : filters.date_from === daysAgoStr(30)
            ? '30d'
            : filters.date_from === daysAgoStr(90)
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
                            defaultValue={filters.date_from ?? ''}
                            onChange={(e) => go({ date_from: e.target.value || null })}
                            className="mt-1 block w-full rounded-md border border-border bg-background px-2 py-1 text-sm"
                        />
                    </label>
                    <label className="block text-xs font-medium text-foreground">
                        To
                        <input
                            type="date"
                            defaultValue={filters.date_to ?? ''}
                            onChange={(e) => go({ date_to: e.target.value || null })}
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
        go({ date_from: daysAgoStr(map[key]), date_to: todayStr() });
    };

    /* ---- right-click context menu ---- */
    const openRowCtx = (e: ReactMouseEvent, i: IncidentRow) => {
        e.preventDefault();
        const sev = SEV[i.severity] ?? SEV.minor;
        const isClosed = i.status === 'closed';
        const items: ShiftCtxItem[] = [
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'View incident', sub: `${i.reference} · ${titleCase(i.incident_type)}`, tone: 'primary', onClick: () => openDetail(i.id) },
            ...(can.manage
                ? [
                      { icon: <Edit2 className="h-3.5 w-3.5" />, label: i.status === 'reported' ? 'Continue / edit' : 'Edit', onClick: () => openDetail(i.id) } satisfies ShiftCtxItem,
                      { icon: <CircleDot className="h-3.5 w-3.5" />, label: 'Update status', onClick: () => openDetail(i.id) } satisfies ShiftCtxItem,
                      { icon: <ListTodo className="h-3.5 w-3.5" />, label: 'Add follow-up', onClick: () => openDetail(i.id) } satisfies ShiftCtxItem,
                      { icon: <FileText className="h-3.5 w-3.5" />, label: 'Upload photo / doc', onClick: () => openDetail(i.id) } satisfies ShiftCtxItem,
                  ]
                : []),
            ...(can.manage && i.flags.police_report_due
                ? [{ icon: <ShieldAlert className="h-3.5 w-3.5" />, label: 'Log Police report (TCR)', tone: 'critical', onClick: () => openDetail(i.id) } satisfies ShiftCtxItem]
                : []),
            ...(can.manage
                ? [
                      { icon: <CreditCard className="h-3.5 w-3.5" />, label: 'Log insurance claim', onClick: () => openDetail(i.id) } satisfies ShiftCtxItem,
                      { icon: <CircleSlash className="h-3.5 w-3.5" />, label: i.flags.off_road ? 'Mark back in service' : 'Mark off-road (VOR)', onClick: () => openDetail(i.id) } satisfies ShiftCtxItem,
                  ]
                : []),
            { sep: true },
            ...(i.asset ? [{ icon: <Truck className="h-3.5 w-3.5" />, label: 'View vehicle / asset', sub: i.asset.name, onClick: () => router.visit(`/fleet-assets/assets/${i.asset!.id}`) } satisfies ShiftCtxItem] : []),
            ...(i.driver ? [{ icon: <User className="h-3.5 w-3.5" />, label: 'View driver', sub: i.driver.name, onClick: () => router.visit(`/fleet-assets/drivers/${i.driver!.id}`) } satisfies ShiftCtxItem] : []),
            ...(i.flags.alert_linked ? [{ icon: <RadioTower className="h-3.5 w-3.5" />, label: 'View Control Room alert', onClick: () => openDetail(i.id) } satisfies ShiftCtxItem] : []),
            ...(can.manage && !isClosed
                ? [{ sep: true } satisfies ShiftCtxItem, { icon: <CheckCircle2 className="h-3.5 w-3.5" />, label: 'Close incident', tone: 'critical', onClick: () => openDetail(i.id) } satisfies ShiftCtxItem]
                : []),
        ];
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: i.reference,
            meta: [i.asset?.name, i.asset?.registration_number].filter(Boolean).join(' · ') || titleCase(i.incident_type),
            items,
        });
    };

    const csvHref = () => {
        const params = new URLSearchParams();
        Object.entries({ ...filters, export: 'csv' }).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') params.set(k, String(v));
        });
        return `/fleet-assets/incidents?${params.toString()}`;
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Fleet Incidents', href: '/fleet-assets/incidents' }]}>
            <Head title="Fleet & Asset Incidents" />

            <div className="flex flex-col gap-6 p-6">
                {/* ---- Hero ---- */}
                <HeroShell
                    footer={
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <HeroSegmented label="Period" variant="pill" ariaLabel="Date range" items={RANGE_ITEMS} value={activeRange} onChange={onRange} />
                            {formOptions.sites?.length ? (
                                <EntityFilter label="Site" allLabel="All sites" items={formOptions.sites} value={filters.site_id} onChange={(id) => go({ site_id: id })} onDark />
                            ) : null}
                            {formOptions.assets?.length ? (
                                <EntityFilter
                                    label="Vehicle / asset"
                                    allLabel="All vehicles & assets"
                                    items={formOptions.assets.map((a) => ({ id: a.id, name: a.name, description: a.registration_number }))}
                                    value={filters.vehicle_id}
                                    onChange={(id) => go({ vehicle_id: id })}
                                    onDark
                                />
                            ) : null}
                            {formOptions.users?.length ? (
                                <EntityFilter label="Driver" allLabel="All drivers" items={formOptions.users} value={filters.driver_id} onChange={(id) => go({ driver_id: id })} onDark />
                            ) : null}
                            <HeroSegmented
                                label="Severity"
                                variant="pill"
                                ariaLabel="Severity"
                                items={[{ key: 'all', label: 'All' }, ...formOptions.severities.map((s) => ({ key: s, label: SEV[s]?.label ?? titleCase(s) }))]}
                                value={filters.severity ?? 'all'}
                                onChange={(key) => go({ severity: key === 'all' ? null : key })}
                            />
                            <div className="relative ml-auto">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                <input
                                    type="search"
                                    placeholder="Search incidents…"
                                    defaultValue={filters.search ?? ''}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') go({ search: (e.target as HTMLInputElement).value || null });
                                    }}
                                    className="w-48 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pr-2.5 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                                />
                            </div>
                            {/* eslint-disable-next-line no-restricted-syntax -- onDark CSV affordance on the hero footer */}
                            <a
                                href={csvHref()}
                                className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-primary-foreground/70 transition-colors hover:text-primary-foreground"
                            >
                                <Download className="h-3 w-3" /> CSV
                            </a>
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
                            <HeroMedallion icon={Truck} />
                            <div className="flex flex-col gap-1.5">
                                <HeroStatusPill>Fleet incident register · synced just now</HeroStatusPill>
                                <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">Fleet &amp; Asset Incidents</h1>
                                <p className="max-w-xl text-sm text-primary-foreground/70">
                                    The system of record for everything that happens to a vehicle or asset — captured with photos in seconds, triaged in the Control Room, and
                                    investigated in Health &amp; Safety. The 24-hour Police-report duty is impossible to miss.
                                </p>
                            </div>
                        </div>

                        <div className="flex flex-col items-end gap-2">
                        {can.manage ? (
                            <Popover open={launcherOpen} onOpenChange={setLauncherOpen}>
                                <PopoverTrigger asChild>
                                    <Button size="sm" className="bg-primary-foreground text-primary hover:bg-primary-foreground/90">
                                        <Plus className="mr-1.5 h-4 w-4" /> Report incident
                                    </Button>
                                </PopoverTrigger>
                                <PopoverContent align="end" className="w-72 p-1.5">
                                    <button type="button" onClick={() => openReport('vehicle')} className="flex w-full items-start gap-2.5 rounded-md p-2.5 text-left transition-colors hover:bg-muted">
                                        <Truck className="mt-0.5 h-4 w-4 shrink-0 text-status-critical" />
                                        <span>
                                            <span className="block text-sm font-medium">Vehicle incident</span>
                                            <span className="block text-xs text-muted-foreground">Collision, damage, theft or breakdown.</span>
                                        </span>
                                    </button>
                                    <button type="button" onClick={() => openReport('asset')} className="flex w-full items-start gap-2.5 rounded-md p-2.5 text-left transition-colors hover:bg-muted">
                                        <Box className="mt-0.5 h-4 w-4 shrink-0 text-status-warning" />
                                        <span>
                                            <span className="block text-sm font-medium">Asset / equipment incident</span>
                                            <span className="block text-xs text-muted-foreground">Damage, theft or fault — no vehicle questions.</span>
                                        </span>
                                    </button>
                                    <button type="button" onClick={() => openReport('near_miss')} className="flex w-full items-start gap-2.5 rounded-md p-2.5 text-left transition-colors hover:bg-muted">
                                        <Eye className="mt-0.5 h-4 w-4 shrink-0 text-status-success" />
                                        <span>
                                            <span className="block text-sm font-medium">A near miss</span>
                                            <span className="block text-xs text-muted-foreground">No harm done — blame-free, under a minute.</span>
                                        </span>
                                    </button>
                                </PopoverContent>
                            </Popover>
                        ) : null}
                            {/* eslint-disable-next-line no-restricted-syntax -- onDark dashed PREP-LATER affordance */}
                            <button
                                type="button"
                                onClick={() => setTelematicsOpen(true)}
                                className="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-primary-foreground/30 px-3 py-1.5 text-xs font-medium text-primary-foreground/70 transition-colors hover:bg-primary-foreground/10"
                            >
                                <RadioTower className="h-3.5 w-3.5" /> Telematics preview
                                <span className="rounded-full bg-primary-foreground/15 px-1.5 py-0.5 text-[9px] font-semibold tracking-wide uppercase">Prep</span>
                            </button>
                        </div>
                    </div>

                    {/* stat clusters */}
                    <div className="grid gap-3 lg:grid-cols-2">
                        <HeroCluster title="This period · last 30 days" icon={Activity}>
                            <HeroClusterTile href="/fleet-assets/incidents?tab=all" label="Reported" value={fmt(stats.reported)} caption="reported" tone="neutral" />
                            <HeroClusterTile href="/fleet-assets/incidents?tab=under_investigation" label="Investigating" value={fmt(stats.investigating)} caption="under investigation" tone="warning" />
                            <HeroClusterTile href="/fleet-assets/incidents?tab=all" label="Resolved" value={fmt(stats.resolved)} caption="back in service" tone="success" />
                            <HeroClusterTile href="/fleet-assets/incidents?tab=closed" label="Closed" value={fmt(stats.closed)} caption="finalised" tone="neutral" />
                        </HeroCluster>
                        <HeroCluster title="Needs attention" icon={Bell}>
                            <HeroClusterTile href="/fleet-assets/incidents?tab=police_report_due" label="Police report due" value={fmt(stats.police_due)} caption="24h s22 duty" tone={stats.police_due > 0 ? 'critical' : 'success'} />
                            <HeroClusterTile href="/fleet-assets/incidents?tab=off_road" label="Off-road (VOR)" value={fmt(stats.off_road)} caption="out of service" tone={stats.off_road > 0 ? 'warning' : 'success'} />
                            <HeroClusterTile href="/fleet-assets/incidents?tab=insurance_claims" label="Open claims" value={fmt(stats.open_claims)} caption="insurance" tone={stats.open_claims > 0 ? 'warning' : 'neutral'} />
                            <HeroClusterTile href="/fleet-assets/incidents?tab=injury_acc" label="Injury / ACC" value={fmt(stats.injury_acc)} caption={`${stats.worksafe_notifiable} WorkSafe`} tone={stats.injury_acc > 0 ? 'critical' : 'success'} />
                        </HeroCluster>
                    </div>
                </HeroShell>

                {/* ---- Tabs ---- */}
                <TabStrip value={tab} onChange={setTab} items={TABS} ariaLabel="Fleet incident views" />

                {/* ---- Rows ---- */}
                <Card>
                    <CardContent className="p-0">
                        <IncidentTable rows={incidents.data} onRowCtx={openRowCtx} onOpen={openDetail} />
                    </CardContent>
                </Card>

                {incidents.meta.last_page > 1 ? <LaravelPagination links={incidents.links} /> : null}
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}

            {detail ? <FleetIncidentDialog detail={detail} open onClose={closeDetail} users={formOptions.users} /> : null}

            {reportMode ? (
                <FleetIncidentReportDialog
                    open
                    mode={reportMode}
                    formOptions={formOptions}
                    onClose={() => setReportMode(null)}
                    onOpenIncident={(id) => {
                        setReportMode(null);
                        openDetail(id);
                    }}
                />
            ) : null}

            <FleetTelematicsStoryboard open={telematicsOpen} onClose={() => setTelematicsOpen(false)} />
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Police-report countdown (live)                                     */
/* ------------------------------------------------------------------ */

function PoliceCountdownPill({ dueAt }: { dueAt: string | null }) {
    const [, force] = useState(0);
    useEffect(() => {
        const id = setInterval(() => force((n) => n + 1), 60_000);
        return () => clearInterval(id);
    }, []);

    if (!dueAt) {
        return (
            <span
                title="Police report due (Land Transport Act s22)"
                className="inline-flex items-center gap-0.5 rounded-md bg-status-critical-bg px-1.5 py-0.5 text-[11px] font-medium text-status-critical"
                style={{ animation: 'fiPulse 2s ease infinite' }}
            >
                <ShieldAlert className="h-3 w-3" /> TCR due
                <style>{'@keyframes fiPulse{0%,100%{opacity:1}50%{opacity:.5}}'}</style>
            </span>
        );
    }

    const mins = Math.round((new Date(dueAt).getTime() - Date.now()) / 60000);
    const overdue = mins < 0;
    const abs = Math.abs(mins);
    const label = abs >= 60 ? `${Math.floor(abs / 60)}h ${abs % 60}m` : `${abs}m`;

    return (
        <span
            title="Police report due within 24h (Land Transport Act s22)"
            className="inline-flex items-center gap-0.5 rounded-md bg-status-critical-bg px-1.5 py-0.5 text-[11px] font-medium text-status-critical"
            style={{ animation: 'fiPulse 2s ease infinite' }}
        >
            <Clock className="h-3 w-3" /> {overdue ? `Overdue ${label}` : `${label} left`}
            <style>{'@keyframes fiPulse{0%,100%{opacity:1}50%{opacity:.5}}'}</style>
        </span>
    );
}

function FlagChip({ tone, icon, title }: { tone: 'critical' | 'warning' | 'info'; icon: ReactNode; title: string }) {
    const cls =
        tone === 'critical'
            ? 'bg-status-critical-bg text-status-critical'
            : tone === 'warning'
              ? 'bg-status-warning-bg text-status-warning'
              : 'bg-status-info-bg text-status-info';
    return (
        <span title={title} aria-label={title} className={`inline-flex h-[22px] w-[22px] items-center justify-center rounded-md ${cls}`}>
            {icon}
        </span>
    );
}

/* ------------------------------------------------------------------ */
/*  Incident table                                                     */
/* ------------------------------------------------------------------ */

function IncidentTable({ rows, onRowCtx, onOpen }: { rows: IncidentRow[]; onRowCtx: (e: ReactMouseEvent, i: IncidentRow) => void; onOpen: (id: number) => void }) {
    if (!rows.length) {
        return (
            <div className="px-4 py-16 text-center">
                <Truck className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
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
                        <th className="px-4 py-2.5">Vehicle / asset</th>
                        <th className="px-4 py-2.5">Driver</th>
                        <th className="px-4 py-2.5">Severity</th>
                        <th className="px-4 py-2.5">Status</th>
                        <th className="px-4 py-2.5">Flags</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((i) => {
                        const sev = SEV[i.severity] ?? SEV.minor;
                        const stat = STATUS[i.status] ?? STATUS.reported;
                        const StatusIcon = stat.icon;
                        const isEquipment = i.asset?.category && i.asset.category !== 'vehicle';
                        return (
                            <tr
                                key={i.id}
                                onClick={() => onOpen(i.id)}
                                onContextMenu={(e) => onRowCtx(e, i)}
                                tabIndex={0}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') onOpen(i.id);
                                }}
                                className="cursor-pointer transition-colors hover:bg-muted/40 focus:bg-muted/50 focus:outline-none"
                            >
                                <td className="px-4 py-3 align-top whitespace-nowrap">
                                    <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                        <Calendar className="h-3 w-3" />
                                        {i.occurred_at ? formatDateTime(i.occurred_at) : '—'}
                                    </div>
                                    <div className="mt-0.5 font-mono text-[11px] text-muted-foreground/60">{i.reference}</div>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <div className="flex items-center gap-2">
                                        <span className={`h-2 w-2 shrink-0 rounded-full ${TONE_DOT[sev.tone]}`} />
                                        <span className="font-medium capitalize">{titleCase(i.incident_type)}</span>
                                    </div>
                                    {i.location ? <p className="mt-0.5 line-clamp-1 max-w-md text-xs text-muted-foreground">{i.location}</p> : null}
                                </td>
                                <td className="px-4 py-3 align-top">
                                    {i.asset ? (
                                        <div className="flex items-center gap-2">
                                            <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                                                {isEquipment ? <Box className="h-3.5 w-3.5" /> : <Truck className="h-3.5 w-3.5" />}
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block truncate font-medium">{i.asset.name}</span>
                                                {i.asset.registration_number ? <span className="block truncate font-mono text-xs text-muted-foreground">{i.asset.registration_number}</span> : null}
                                            </span>
                                        </div>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">—</span>
                                    )}
                                </td>
                                <td className="px-4 py-3 align-top whitespace-nowrap">
                                    {i.driver ? <span className="font-medium">{i.driver.name}</span> : <span className="text-xs text-muted-foreground">—</span>}
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${TONE_BG[sev.tone]}`}>{sev.label}</span>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium ${stat.cls}`}>
                                        <StatusIcon className="h-3 w-3" />
                                        {stat.label}
                                    </span>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <div className="flex items-center gap-1.5">
                                        {i.flags.police_report_due ? <PoliceCountdownPill dueAt={i.flags.police_report_due_at} /> : null}
                                        {i.flags.injury ? <FlagChip tone="critical" icon={<AlertTriangle className="h-3 w-3" />} title="Injury / ACC" /> : null}
                                        {i.flags.off_road ? <FlagChip tone="warning" icon={<CircleSlash className="h-3 w-3" />} title="Off-road (VOR)" /> : null}
                                        {i.flags.claim_open ? <FlagChip tone="info" icon={<CreditCard className="h-3 w-3" />} title="Open insurance claim" /> : null}
                                        {i.flags.alert_linked ? <FlagChip tone="warning" icon={<RadioTower className="h-3 w-3" />} title="Linked Control Room alert" /> : null}
                                        {i.flags.attachments > 0 ? (
                                            <span className="inline-flex items-center gap-0.5 text-xs text-muted-foreground" aria-label="Attachments">
                                                <Paperclip className="h-3.5 w-3.5" />
                                                {i.flags.attachments}
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
