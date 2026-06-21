/* Lone Worker Safety — the coordinator / H&S watch-tower.
 * H&S gold standard: HeroShell + WorkflowRibbon + KPI clusters + NZ badges + footer filter
 * bar; TabStrip (Sessions / Alerts); register tables with left-click detail + right-click
 * context menu; an Add-client-style Start-session wizard; focused lifecycle action modals.
 * Composes the shared kits — no reinvented primitives, tokens only, en-NZ, web-only.
 * Worker check-in lives in My Day; Control Room owns alert triage (deep-link only). */
import { LoneWorkerActionModal } from '@/components/health-safety/lone-worker-action-form';
import { LoneWorkerDetailDialog } from '@/components/health-safety/lone-worker-detail-dialog';
import {
    type ActionTarget,
    type Alert,
    ALERT_STATUS_META,
    ALERT_TYPE_META,
    type Can,
    type Detail,
    type Filters,
    type Hero,
    LONE_WORKER_ROUTE,
    type Options,
    overdueByMinutes,
    type Paginator,
    type Session,
    SESSION_LABEL,
    SESSION_TONE,
} from '@/components/health-safety/lone-worker-types';
import { LoneWorkerWizard } from '@/components/health-safety/lone-worker-wizard';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import {
    EntityFilter,
    ShiftContextMenu,
    type ShiftCtxItem,
    type ShiftCtxState,
    TabStrip,
    type RosterTabItem,
} from '@/components/rostering';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import {
    HeroCluster,
    HeroClusterTile,
    HeroComplianceBadges,
    type HeroComplianceBadge,
    HeroMedallion,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
    fmt,
} from '@/pages/health-safety/components/hs-hero-kit';
import {
    FlagBadge,
    initials,
    RegisterTableHeader,
    TONE_BG,
    TONE_DOT,
    entityTone,
} from '@/pages/health-safety/components/register-row-kit';
import { WorkflowRibbon } from '@/pages/health-safety/components/workflow-ribbon';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    BarChart3,
    Bell,
    Check,
    CheckCircle2,
    Clock,
    Eye,
    FileText,
    HeartPulse,
    Link2,
    MapPin,
    MousePointer2,
    Plus,
    Radio,
    RadioTower,
    Search,
    ShieldCheck,
    Trash2,
    User,
    Users,
    X,
    XCircle,
} from 'lucide-react';
import { type MouseEvent as ReactMouseEvent, useState } from 'react';

type Props = {
    tab: 'sessions' | 'alerts';
    sessions: Paginator<Session>;
    alerts: Paginator<Alert>;
    detail: Detail | null;
    tabCounts: { sessions: number; alerts: number };
    hero: Hero;
    filters: Filters;
    options: Options;
    can: Can;
};

const PERIOD_ITEMS = [
    { key: 'today', label: 'Today' },
    { key: 'week', label: 'This week' },
    { key: '30d', label: '30 days' },
];

const SESSION_STATUS_ITEMS = [
    { key: '', label: 'All' },
    { key: 'active', label: 'Active' },
    { key: 'overdue', label: 'Overdue' },
    { key: 'emergency', label: 'Emergency' },
];
const ALERT_STATUS_ITEMS = [
    { key: '', label: 'All' },
    { key: 'active', label: 'Active' },
    { key: 'acknowledged', label: 'Ack' },
    { key: 'resolved', label: 'Resolved' },
];

const clean = (m: Record<string, unknown>): Record<string, string> => {
    const out: Record<string, string> = {};
    for (const k of ['tab', 'site_id', 'status', 'user_id', 'period', 'q', 'session', 'alert']) {
        const v = m[k];
        if (v === null || v === undefined || v === '') continue;
        if (k === 'period' && v === 'today') continue; // default — omit for clean URLs
        out[k] = String(v);
    }
    return out;
};

export default function LoneWorkerIndex({ tab, sessions, alerts, detail, tabCounts, hero, filters, options, can }: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [wizardOpen, setWizardOpen] = useState(false);
    const [action, setAction] = useState<ActionTarget | null>(null);
    const [search, setSearch] = useState(filters.q ?? '');

    /* ── Inertia navigation (URL-driven; mirror Incidents) ── */
    const go = (next: Record<string, unknown>) =>
        router.get(LONE_WORKER_ROUTE, clean({ ...filters, tab, ...next }), { preserveState: true, preserveScroll: true, replace: true });
    const setTab = (id: string) =>
        router.get(LONE_WORKER_ROUTE, clean({ ...filters, status: null, tab: id }), { preserveScroll: true });
    const openSession = (id: number) =>
        router.get(LONE_WORKER_ROUTE, clean({ ...filters, tab, session: id }), { preserveState: true, preserveScroll: true, only: ['detail'] });
    const openAlert = (id: string) =>
        router.get(LONE_WORKER_ROUTE, clean({ ...filters, tab, alert: id }), { preserveState: true, preserveScroll: true, only: ['detail'] });
    const closeDetail = () =>
        router.get(LONE_WORKER_ROUTE, clean({ ...filters, tab }), { preserveState: true, preserveScroll: true, only: ['detail'] });
    const clearFilters = () => router.get(LONE_WORKER_ROUTE, { tab }, { preserveState: true, preserveScroll: true, replace: true });

    const hrefFor = (extra: Record<string, unknown>) => {
        const q = new URLSearchParams(clean({ ...filters, tab, ...extra })).toString();
        return q ? `${LONE_WORKER_ROUTE}?${q}` : LONE_WORKER_ROUTE;
    };
    const copyLink = (extra: Record<string, unknown>) => {
        void navigator.clipboard?.writeText(window.location.origin + hrefFor(extra));
    };
    const exportCsv = () => {
        const isS = tab === 'sessions';
        const head = isS
            ? ['Worker', 'Site', 'Client', 'Started', 'Expected end', 'Last check-in', 'Status']
            : ['Worker', 'Site', 'Client', 'Type', 'Triggered', 'Status', 'Source'];
        const body = isS
            ? sessions.data.map((s) => [s.user?.name ?? '', s.site?.name ?? '', s.client?.name ?? '', formatDateTime(s.started_at), formatDateTime(s.expected_end_at), formatDateTime(s.last_check_in_at), s.status])
            : alerts.data.map((a) => [a.session?.user?.name ?? '', a.session?.site?.name ?? '', a.session?.client?.name ?? '', ALERT_TYPE_META[a.type]?.label ?? a.type, formatDateTime(a.triggered_at), a.status, a.source]);
        const csv = [head, ...body].map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n');
        const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = `lone-workers-${tab}.csv`;
        a.click();
        URL.revokeObjectURL(url);
    };

    /* ── Context menus (one actionsFor per entity, powers right-click + kebab) ── */
    const sessionActions = (s: Session): ShiftCtxItem[] => {
        const canAct = can.manage && (s.status === 'active' || s.status === 'overdue');
        return [
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'View session', sub: `#${s.id} · ${s.user?.name ?? 'Worker'}`, tone: 'primary', onClick: () => openSession(s.id) },
            ...(canAct
                ? ([
                      { icon: <CheckCircle2 className="h-3.5 w-3.5" />, label: 'Record check-in', sub: 'Log worker status', onClick: () => setAction({ kind: 'checkin', session: s }) },
                      { icon: <Clock className="h-3.5 w-3.5" />, label: 'Extend / edit session', sub: 'Push out expected end', onClick: () => setAction({ kind: 'extend', session: s }) },
                      { sep: true },
                      { icon: <XCircle className="h-3.5 w-3.5" />, label: 'End session', sub: 'Stop monitoring', onClick: () => setAction({ kind: 'end', session: s }) },
                      { icon: <AlertTriangle className="h-3.5 w-3.5" />, label: 'Trigger emergency', sub: 'Notify contacts now', tone: 'critical', onClick: () => setAction({ kind: 'emergency', session: s }) },
                  ] satisfies ShiftCtxItem[])
                : []),
            { sep: true },
            ...(s.user ? ([{ icon: <User className="h-3.5 w-3.5" />, label: 'Open worker profile', sub: s.user.name, onClick: () => router.visit(`/staff/${s.user!.id}`) }] satisfies ShiftCtxItem[]) : []),
            { icon: <Link2 className="h-3.5 w-3.5" />, label: 'Copy link', onClick: () => copyLink({ session: s.id }) },
            ...(can.manage && s.status === 'completed'
                ? ([
                      { sep: true },
                      { icon: <Trash2 className="h-3.5 w-3.5" />, label: 'Remove session', sub: 'Soft-delete · retained for audit', tone: 'critical', onClick: () => setAction({ kind: 'delete', session: s }) },
                  ] satisfies ShiftCtxItem[])
                : []),
        ];
    };

    const alertActions = (a: Alert): ShiftCtxItem[] => {
        const isLegacy = a.source === 'legacy';
        const crId = a.source === 'control_room' ? a.id.replace('cr_', '') : null;
        const sessionId = a.session?.id ?? null;
        return [
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'View alert', sub: ALERT_TYPE_META[a.type]?.label ?? a.type, tone: 'primary', onClick: () => openAlert(a.id) },
            ...(crId && can.view_control_room
                ? ([{ icon: <RadioTower className="h-3.5 w-3.5" />, label: 'Open in Control Room', sub: 'Triage · SLA · playbooks', tone: 'primary', onClick: () => router.visit(`/control-room/alerts/${crId}`) }] satisfies ShiftCtxItem[])
                : []),
            ...(isLegacy && can.manage && a.status === 'active'
                ? ([{ sep: true }, { icon: <Bell className="h-3.5 w-3.5" />, label: 'Acknowledge', sub: 'Convenience action', onClick: () => setAction({ kind: 'acknowledge', alert: a }) }] satisfies ShiftCtxItem[])
                : []),
            ...(isLegacy && can.manage && a.status !== 'resolved'
                ? ([{ icon: <Check className="h-3.5 w-3.5" />, label: 'Resolve', sub: 'Convenience action', tone: 'critical', onClick: () => setAction({ kind: 'resolve', alert: a }) }] satisfies ShiftCtxItem[])
                : []),
            ...(sessionId ? ([{ sep: true }, { icon: <Activity className="h-3.5 w-3.5" />, label: 'Open session', sub: a.session?.user?.name ?? `#${sessionId}`, onClick: () => openSession(sessionId) }] satisfies ShiftCtxItem[]) : []),
            { icon: <Link2 className="h-3.5 w-3.5" />, label: 'Copy link', onClick: () => copyLink({ alert: a.id }) },
        ];
    };

    const openSessionCtx = (e: ReactMouseEvent, s: Session, kebab = false) => {
        e.preventDefault();
        e.stopPropagation();
        const r = kebab ? (e.currentTarget as HTMLElement).getBoundingClientRect() : null;
        setCtx({
            x: r ? r.right - 260 : e.clientX,
            y: r ? r.bottom + 4 : e.clientY,
            tag: SESSION_LABEL[s.status] ?? s.status,
            meta: `${s.user?.name ?? 'Worker'} · ${s.site?.name ?? 'No site'}`,
            items: sessionActions(s),
        });
    };
    const openAlertCtx = (e: ReactMouseEvent, a: Alert, kebab = false) => {
        e.preventDefault();
        e.stopPropagation();
        const r = kebab ? (e.currentTarget as HTMLElement).getBoundingClientRect() : null;
        setCtx({
            x: r ? r.right - 260 : e.clientX,
            y: r ? r.bottom + 4 : e.clientY,
            tag: ALERT_STATUS_META[a.status]?.label ?? a.status,
            meta: `${a.session?.user?.name ?? 'Worker'} · ${ALERT_TYPE_META[a.type]?.label ?? a.type}`,
            items: alertActions(a),
        });
    };
    const openHeroCtx = (e: ReactMouseEvent) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [
            ...(can.manage ? ([{ icon: <Plus className="h-3.5 w-3.5" />, label: 'Start session', sub: 'New lone worker session', tone: 'primary', onClick: () => setWizardOpen(true) }] satisfies ShiftCtxItem[]) : []),
            { icon: <AlertTriangle className="h-3.5 w-3.5" />, label: 'View emergencies', sub: 'Active emergency sessions', tone: 'critical', onClick: () => go({ tab: 'sessions', status: 'emergency' }) },
            ...(can.view_control_room ? ([{ icon: <RadioTower className="h-3.5 w-3.5" />, label: 'Open Control Room', sub: 'Alert triage desk', onClick: () => router.visit('/control-room') }] satisfies ShiftCtxItem[]) : []),
            { sep: true },
            { icon: <FileText className="h-3.5 w-3.5" />, label: 'Export register', sub: 'CSV · current view', onClick: exportCsv },
        ];
        setCtx({ x: e.clientX, y: e.clientY, tag: 'QUICK', meta: 'Lone worker quick actions', items });
    };

    /* ── Hero data ── */
    const c = hero.clusters;
    const badgeItems: HeroComplianceBadge[] = [
        { icon: Users, tone: hero.badges.checked_in < hero.badges.monitored_total ? 'warning' : 'success', label: `${hero.badges.checked_in} of ${hero.badges.monitored_total} workers checked in` },
        { icon: Clock, tone: hero.badges.overdue > 0 ? 'warning' : 'success', label: hero.badges.overdue > 0 ? `${hero.badges.overdue} overdue check-in${hero.badges.overdue === 1 ? '' : 's'}` : 'No overdue check-ins' },
        { icon: AlertTriangle, tone: hero.badges.emergency_active ? 'critical' : 'success', label: hero.badges.emergency_active ? 'Emergency active' : 'No active emergency' },
        { icon: ShieldCheck, tone: 'success', label: 'HSWA 2015 · lone/remote duty met' },
        { icon: HeartPulse, tone: 'success', label: hero.badges.after_hours ? 'After-hours cover · ACC ready' : 'Business hours · ACC ready' },
    ];

    const TABS: RosterTabItem[] = [
        { id: 'sessions', label: 'Sessions', icon: Radio, tone: 'info', badge: tabCounts.sessions || undefined },
        { id: 'alerts', label: 'Alerts', icon: Bell, tone: 'critical', badge: tabCounts.alerts || undefined },
    ];

    const hasFilters = !!(filters.site_id || filters.status || filters.q || (filters.period && filters.period !== 'today'));
    const statusItems = tab === 'sessions' ? SESSION_STATUS_ITEMS : ALERT_STATUS_ITEMS;

    const footer = (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
            <HeroSegmented label="Period" variant="pill" ariaLabel="Period" items={PERIOD_ITEMS} value={filters.period} onChange={(k) => go({ period: k })} />
            <EntityFilter label="Site" allLabel={`All sites · ${options.sites.length}`} items={options.sites} value={filters.site_id} onChange={(id) => go({ site_id: id })} onDark />
            <HeroSegmented label="Status" variant="segmented" ariaLabel="Status" items={statusItems} value={filters.status ?? ''} onChange={(k) => go({ status: k || null })} />
            <div className="relative ml-auto">
                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                {/* eslint-disable-next-line no-restricted-syntax -- on-dark hero search input; not a shadcn control */}
                <input
                    type="search"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && go({ q: search || null })}
                    placeholder="Search workers…"
                    className="w-[180px] rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pr-2.5 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus:border-primary-foreground/40 focus:outline-none"
                />
            </div>
            {hasFilters ? (
                // eslint-disable-next-line no-restricted-syntax -- on-dark hero clear control
                <button type="button" onClick={() => { setSearch(''); clearFilters(); }} className="inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-medium text-primary-foreground/80 hover:text-primary-foreground">
                    <X className="h-3.5 w-3.5" /> Clear
                </button>
            ) : null}
        </div>
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Lone workers', href: LONE_WORKER_ROUTE },
            ]}
        >
            <Head title="Lone Worker Safety" />

            <div className="flex flex-col gap-6 p-6">
                <div onContextMenu={openHeroCtx}>
                    <HeroShell footer={footer}>
                        <WorkflowRibbon current="report" />

                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex items-start gap-4">
                                <HeroMedallion icon={Radio} />
                                <div className="flex flex-col gap-1.5">
                                    <HeroStatusPill>Lone worker monitoring · live</HeroStatusPill>
                                    <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">Lone Worker Safety</h1>
                                    <p className="max-w-xl text-sm text-primary-foreground/70">
                                        Live monitoring for staff working alone or remotely — start sessions, track check-ins, and escalate emergencies. Operational alerts are owned by the Control Room.
                                    </p>
                                </div>
                            </div>

                            <div className="flex items-center gap-2">
                                <Popover>
                                    <PopoverTrigger asChild>
                                        {/* eslint-disable-next-line no-restricted-syntax -- on-dark hero CTA; not a shadcn Button */}
                                        <button type="button" className="inline-flex items-center gap-1.5 rounded-lg border border-primary-foreground/25 bg-primary-foreground/10 px-3 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary-foreground/20">
                                            <FileText className="h-4 w-4" /> Reports
                                        </button>
                                    </PopoverTrigger>
                                    <PopoverContent align="end" className="w-60 p-1.5">
                                        <ReportLink href="/health-safety/analytics" icon={BarChart3} title="Safety analytics" sub="Trends, LTIFR / TRIFR" />
                                        <ReportLink href="/health-safety/reports/board-summary" icon={FileText} title="Board summary" sub="Governance report" />
                                        <button type="button" onClick={exportCsv} className="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left transition-colors hover:bg-muted">
                                            <FileText className="h-4 w-4 text-muted-foreground" />
                                            <span>
                                                <span className="block text-sm font-medium text-foreground">Export current view</span>
                                                <span className="block text-xs text-muted-foreground">CSV · this tab</span>
                                            </span>
                                        </button>
                                    </PopoverContent>
                                </Popover>

                                {can.manage ? (
                                    // eslint-disable-next-line no-restricted-syntax -- on-dark hero primary CTA; not a shadcn Button
                                    <button type="button" onClick={() => setWizardOpen(true)} className="inline-flex items-center gap-1.5 rounded-lg bg-primary-foreground px-3.5 py-2 text-sm font-semibold text-primary shadow-sm transition-colors hover:bg-primary-foreground/90">
                                        <Plus className="h-4 w-4" /> Start session
                                    </button>
                                ) : null}
                            </div>
                        </div>

                        <div className="grid gap-3 lg:grid-cols-2">
                            <HeroCluster title="Live monitoring" icon={Activity}>
                                <HeroClusterTile href={hrefFor({ tab: 'sessions', status: 'active' })} label="Active" value={fmt(c.live.active)} caption="being monitored" tone="success" />
                                <HeroClusterTile href={hrefFor({ tab: 'sessions', status: 'overdue' })} label="Overdue" value={fmt(c.live.overdue)} caption="check-in late" tone="warning" />
                                <HeroClusterTile href={hrefFor({ tab: 'sessions', status: 'emergency' })} label="Emergencies" value={fmt(c.live.emergency)} caption="unresolved" tone="critical" />
                                <HeroClusterTile href={hrefFor({ tab: 'sessions', status: '' })} label="Ending <1h" value={fmt(c.live.ending_soon)} caption="wrap-up soon" tone="warning" />
                            </HeroCluster>
                            <HeroCluster title="Alerts & response" icon={Bell}>
                                <HeroClusterTile href={hrefFor({ tab: 'alerts', status: '' })} label="Alerts today" value={fmt(c.alerts.today)} caption="all sources" tone="neutral" />
                                <HeroClusterTile href={hrefFor({ tab: 'alerts', status: 'active' })} label="Awaiting ack" value={fmt(c.alerts.awaiting_ack)} caption="need a response" tone="warning" />
                                <HeroClusterTile href={hrefFor({ tab: 'alerts', status: '' })} label="Unresolved" value={fmt(c.alerts.unresolved)} caption="Control Room" tone="critical" />
                                <HeroClusterTile href={hrefFor({ tab: 'sessions', status: 'overdue' })} label="No recent check-in" value={fmt(c.alerts.no_recent_checkin)} caption=">1 interval" tone="warning" />
                            </HeroCluster>
                        </div>

                        <HeroComplianceBadges items={badgeItems} />

                        {hero.lone_shifts_unmonitored > 0 ? (
                            <Link
                                href={hrefFor({ tab: 'sessions' })}
                                className="inline-flex items-center gap-2 self-start rounded-lg border border-status-warning/50 bg-status-warning/20 px-3 py-1.5 text-xs font-medium text-primary-foreground transition-colors hover:bg-status-warning/30"
                            >
                                <AlertTriangle className="h-3.5 w-3.5 text-status-warning" />
                                {hero.lone_shifts_unmonitored} rostered lone shift{hero.lone_shifts_unmonitored === 1 ? '' : 's'} not yet being monitored — start a session
                            </Link>
                        ) : null}
                    </HeroShell>
                </div>

                <TabStrip value={tab} onChange={setTab} items={TABS} ariaLabel="Lone worker views" />

                <Card>
                    <CardContent className="p-0">
                        {tab === 'sessions' ? (
                            <>
                                <RegisterTableHeader icon={Radio} title="Sessions" subtitle="live monitoring register" hint="Right-click a row for the full list of actions" hintIcon={MousePointer2} />
                                <SessionsTable rows={sessions.data} onOpen={openSession} onRowCtx={openSessionCtx} />
                            </>
                        ) : (
                            <>
                                <RegisterTableHeader icon={Bell} title="Alerts" subtitle="lone worker signals" hint="Right-click a row for the full list of actions" hintIcon={MousePointer2} />
                                <AlertsTable rows={alerts.data} onOpen={openAlert} onRowCtx={openAlertCtx} />
                            </>
                        )}
                    </CardContent>
                </Card>

                {tab === 'sessions' && sessions.last_page > 1 ? <LaravelPagination links={sessions.links} /> : null}
                {tab === 'alerts' && alerts.last_page > 1 ? <LaravelPagination links={alerts.links} /> : null}
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}
            {detail ? <LoneWorkerDetailDialog detail={detail} open onClose={closeDetail} can={can} onOpenSession={openSession} onOpenAlert={openAlert} /> : null}
            {action ? <LoneWorkerActionModal target={action} open onClose={() => setAction(null)} /> : null}
            <LoneWorkerWizard open={wizardOpen} onClose={() => setWizardOpen(false)} options={options} />
        </AppLayout>
    );
}

/* ───────────────────────────── Tables ───────────────────────────── */

const TH = 'px-4 py-2.5 text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase';
const TD = 'px-4 py-3 align-top';

function Avatar({ id, name }: { id: number; name: string | null | undefined }) {
    return (
        <span className={cn('flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-full text-[11px] font-semibold text-white', entityTone(id))}>
            {initials(name)}
        </span>
    );
}

function StatusPill({ status }: { status: string }) {
    const tone = SESSION_TONE[status] ?? 'neutral';
    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium', TONE_BG[tone])}>
            <span className={cn('h-1.5 w-1.5 rounded-full', TONE_DOT[tone])} />
            {SESSION_LABEL[status] ?? status}
        </span>
    );
}

function Kebab({ onClick }: { onClick: (e: ReactMouseEvent) => void }) {
    return (
        <button type="button" onClick={onClick} className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground" aria-label="Row actions">
            <span className="text-lg leading-none">⋮</span>
        </button>
    );
}

function SessionsTable({
    rows,
    onOpen,
    onRowCtx,
}: {
    rows: Session[];
    onOpen: (id: number) => void;
    onRowCtx: (e: ReactMouseEvent, s: Session, kebab?: boolean) => void;
}) {
    if (rows.length === 0) {
        return <EmptyState icon={Radio} title="No lone worker sessions" sub="Start a session to begin monitoring a worker who is working alone or remotely." />;
    }
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-border">
                        <th className={TH}>Worker</th>
                        <th className={TH}>Site / Client</th>
                        <th className={TH}>Started</th>
                        <th className={TH}>Expected end</th>
                        <th className={TH}>Last check-in</th>
                        <th className={TH}>Status</th>
                        <th className={cn(TH, 'w-10')}></th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border">
                    {rows.map((s) => {
                        const overdue = overdueByMinutes(s);
                        return (
                            <tr
                                key={s.id}
                                tabIndex={0}
                                onClick={() => onOpen(s.id)}
                                onContextMenu={(e) => onRowCtx(e, s)}
                                onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), onOpen(s.id))}
                                className="cursor-pointer outline-none transition-colors hover:bg-muted/55 focus-visible:bg-muted/55 focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-inset"
                            >
                                <td className={TD}>
                                    <div className="flex items-center gap-2.5">
                                        <Avatar id={s.user?.id ?? s.id} name={s.user?.name} />
                                        <div className="min-w-0">
                                            <div className="font-medium text-foreground">{s.user?.name ?? '—'}</div>
                                            {s.location ? (
                                                <div className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                                                    <MapPin className="h-3 w-3" /> {s.location}
                                                </div>
                                            ) : null}
                                        </div>
                                    </div>
                                </td>
                                <td className={TD}>
                                    <div className="text-foreground">{s.site?.name ?? '—'}</div>
                                    {s.client ? <div className="text-xs text-muted-foreground">{s.client.name}</div> : null}
                                </td>
                                <td className={cn(TD, 'whitespace-nowrap text-muted-foreground')}>{formatDateTime(s.started_at)}</td>
                                <td className={cn(TD, 'whitespace-nowrap text-muted-foreground')}>{formatDateTime(s.expected_end_at)}</td>
                                <td className={cn(TD, 'whitespace-nowrap')}>
                                    <div className="text-foreground">{formatDateTime(s.last_check_in_at)}</div>
                                    {overdue > 0 ? <div className="font-semibold text-status-warning">overdue by {overdue}m</div> : null}
                                </td>
                                <td className={TD}>
                                    <StatusPill status={s.status} />
                                </td>
                                <td className={cn(TD, 'text-right')}>
                                    <Kebab onClick={(e) => onRowCtx(e, s, true)} />
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function AlertsTable({
    rows,
    onOpen,
    onRowCtx,
}: {
    rows: Alert[];
    onOpen: (id: string) => void;
    onRowCtx: (e: ReactMouseEvent, a: Alert, kebab?: boolean) => void;
}) {
    if (rows.length === 0) {
        return <EmptyState icon={Bell} title="No lone worker alerts" sub="Overdue check-ins and emergencies will appear here and in the Control Room." />;
    }
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-border">
                        <th className={TH}>Worker</th>
                        <th className={TH}>Site / Client</th>
                        <th className={TH}>Type</th>
                        <th className={TH}>Triggered</th>
                        <th className={TH}>Status</th>
                        <th className={TH}>Source</th>
                        <th className={cn(TH, 'w-10')}></th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border">
                    {rows.map((a) => {
                        const typeMeta = ALERT_TYPE_META[a.type] ?? { tone: 'neutral' as const, label: a.type };
                        const statusMeta = ALERT_STATUS_META[a.status] ?? { tone: 'neutral' as const, label: a.status };
                        const isLegacy = a.source === 'legacy';
                        return (
                            <tr
                                key={a.id}
                                tabIndex={0}
                                onClick={() => onOpen(a.id)}
                                onContextMenu={(e) => onRowCtx(e, a)}
                                onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), onOpen(a.id))}
                                className="cursor-pointer outline-none transition-colors hover:bg-muted/55 focus-visible:bg-muted/55 focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:ring-inset"
                            >
                                <td className={TD}>
                                    <div className="flex items-center gap-2.5">
                                        <Avatar id={a.session?.user?.id ?? 0} name={a.session?.user?.name} />
                                        <div className="font-medium text-foreground">{a.session?.user?.name ?? '—'}</div>
                                    </div>
                                </td>
                                <td className={TD}>
                                    <div className="text-foreground">{a.session?.site?.name ?? '—'}</div>
                                    {a.session?.client ? <div className="text-xs text-muted-foreground">{a.session.client.name}</div> : null}
                                </td>
                                <td className={TD}>
                                    <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium', TONE_BG[typeMeta.tone])}>
                                        <span className={cn('h-1.5 w-1.5 rounded-full', TONE_DOT[typeMeta.tone])} />
                                        {typeMeta.label}
                                    </span>
                                </td>
                                <td className={cn(TD, 'whitespace-nowrap text-muted-foreground')}>{formatDateTime(a.triggered_at)}</td>
                                <td className={TD}>
                                    <span className={cn('inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium', TONE_BG[statusMeta.tone])}>
                                        <span className={cn('h-1.5 w-1.5 rounded-full', TONE_DOT[statusMeta.tone])} />
                                        {statusMeta.label}
                                    </span>
                                </td>
                                <td className={TD}>
                                    <FlagBadge icon={isLegacy ? FileText : RadioTower} tone={isLegacy ? 'neutral' : 'info'} title={isLegacy ? 'Pre-PR4 compatibility record' : 'Canonical · owned by Control Room'}>
                                        {isLegacy ? 'Legacy' : 'Control Room'}
                                    </FlagBadge>
                                </td>
                                <td className={cn(TD, 'text-right')}>
                                    <Kebab onClick={(e) => onRowCtx(e, a, true)} />
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function EmptyState({ icon: Icon, title, sub }: { icon: typeof Radio; title: string; sub: string }) {
    return (
        <div className="flex flex-col items-center gap-2 px-6 py-14 text-center">
            <span className="flex h-11 w-11 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <Icon className="h-5 w-5" />
            </span>
            <div className="text-sm font-semibold text-foreground">{title}</div>
            <p className="max-w-sm text-sm text-muted-foreground">{sub}</p>
        </div>
    );
}

function ReportLink({ href, icon: Icon, title, sub }: { href: string; icon: typeof FileText; title: string; sub: string }) {
    return (
        <Link href={href} className="flex items-center gap-2.5 rounded-md px-2.5 py-2 transition-colors hover:bg-muted">
            <Icon className="h-4 w-4 text-muted-foreground" />
            <span>
                <span className="block text-sm font-medium text-foreground">{title}</span>
                <span className="block text-xs text-muted-foreground">{sub}</span>
            </span>
        </Link>
    );
}
