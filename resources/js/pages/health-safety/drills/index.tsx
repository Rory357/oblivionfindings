import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import {
    EntityFilter,
    ShiftContextMenu,
    TabStrip,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { DrillDetailDialog, type DrillActionKey, type DrillSectionKey } from '@/components/health-safety/drill-detail-dialog';
import { DrillScheduleDialog } from '@/components/health-safety/drill-schedule-dialog';
import { DrillCompleteDialog } from '@/components/health-safety/drill-complete-dialog';
import AppLayout from '@/layouts/app-layout';
import {
    HeroCluster,
    HeroClusterTile,
    HeroMedallion,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
    fmt,
} from '@/pages/health-safety/components/hs-hero-kit';
import { FlagBadge, RegisterTableHeader, entityTone, initials } from '@/pages/health-safety/components/register-row-kit';
import { WorkflowRibbon } from '@/pages/health-safety/components/workflow-ribbon';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlarmClock,
    AlertTriangle,
    BarChart3,
    CalendarClock,
    CheckCircle2,
    ClipboardList,
    Eye,
    ExternalLink,
    FileText,
    Flame,
    LayoutDashboard,
    LayoutList,
    Loader,
    MousePointer2,
    Pencil,
    Play,
    Plus,
    ShieldCheck,
    Siren,
    UserPlus,
    Users,
    XCircle,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type MouseEvent as ReactMouseEvent, type ReactNode } from 'react';
import {
    CHIP,
    DRILL_TYPE_OPTIONS,
    ICON_TEXT,
    statusMeta,
    typeMeta,
    whenLabel,
    type ChipTone,
    type DrillDetail,
    type DrillFilters,
    type DrillHero,
    type DrillRow,
    type StaffOption,
} from '@/pages/health-safety/drills/shared';

type Props = {
    drills: { data: DrillRow[]; links: { url: string | null; label: string; active: boolean }[]; last_page: number };
    tab: string;
    tabCounts: Record<string, number>;
    hero: DrillHero;
    filters: DrillFilters;
    sites: { id: number; name: string; region: string | null }[];
    staff: StaffOption[];
    detail: DrillDetail | null;
    can: { manage: boolean };
};

const PERIOD_ITEMS = [
    { key: '30d', label: '30 days' },
    { key: 'quarter', label: 'Quarter' },
    { key: '6mo', label: '6 months' },
    { key: 'all', label: 'All' },
];

const BADGE_TONE: Record<ChipTone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    info: 'bg-status-info-bg text-status-info',
    neutral: 'border border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground',
};

export default function DrillsIndex({ drills, tab, tabCounts, hero, filters, sites, staff, detail, can }: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [pendingSection, setPendingSection] = useState<DrillSectionKey>('overview');
    const [pendingAction, setPendingAction] = useState<DrillActionKey | null>(null);
    const [scheduleOpen, setScheduleOpen] = useState(false);
    const [completeDrill, setCompleteDrill] = useState<{ id: number; reference: string; type_label: string } | null>(null);

    // Deep-link: /health-safety/drills/create redirects here with ?schedule=1.
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('schedule')) setScheduleOpen(true);
    }, []);

    const go = (next: Partial<DrillFilters>) =>
        router.get('/health-safety/drills', { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });

    const setTab = (id: string) => router.get('/health-safety/drills', { ...filters, tab: id }, { preserveScroll: true });

    const openDrill = (id: number, opts?: { section?: DrillSectionKey; action?: DrillActionKey }) => {
        setPendingSection(opts?.section ?? 'overview');
        setPendingAction(opts?.action ?? null);
        router.get('/health-safety/drills', { ...filters, drill: id }, { preserveState: true, preserveScroll: true, only: ['detail'] });
    };
    const closeDetail = () =>
        router.get('/health-safety/drills', { ...filters }, { preserveState: true, preserveScroll: true, only: ['detail'] });

    const clearFilters = () => router.get('/health-safety/drills', { tab }, { preserveState: true, preserveScroll: true, replace: true });

    const hasFilters = !!(filters.q || filters.drill_type || filters.outcome || filters.site_id || (filters.period && filters.period !== '6mo'));

    const launchComplete = (d: { id: number; reference: string; type_label: string }, fromDetail: boolean) => {
        setCompleteDrill(d);
        if (fromDetail) closeDetail();
    };

    const startDrill = (id: number) => router.post(`/health-safety/drills/${id}/start`, {}, { preserveScroll: true });

    const TABS: RosterTabItem[] = [
        { id: 'all', label: 'All', icon: LayoutList, tone: 'primary', badge: tabCounts.all || undefined },
        { id: 'scheduled', label: 'Scheduled', icon: CalendarClock, tone: 'info', badge: tabCounts.scheduled || undefined },
        { id: 'overdue', label: 'Overdue', icon: AlarmClock, tone: 'critical', badge: tabCounts.overdue || undefined },
        { id: 'in_progress', label: 'In progress', icon: Loader, tone: 'warning', badge: tabCounts.in_progress || undefined },
        { id: 'completed', label: 'Completed', icon: CheckCircle2, tone: 'success', badge: tabCounts.completed || undefined },
        { id: 'findings', label: 'Findings open', icon: ClipboardList, tone: 'warning', badge: tabCounts.findings || undefined },
    ];

    /* ---- right-click context menus ---- */
    const openRowCtx = (e: ReactMouseEvent, row: DrillRow) => {
        e.preventDefault();
        const type = typeMeta(row.drill_type);
        const status = statusMeta(row.status);
        const items: ShiftCtxItem[] = [
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'View drill', sub: row.reference, tone: 'primary', onClick: () => openDrill(row.id) },
        ];
        if (can.manage && row.raw_status === 'scheduled') {
            items.push({ icon: <Play className="h-3.5 w-3.5" />, label: 'Start drill', sub: 'scheduled → in progress', onClick: () => startDrill(row.id) });
        }
        if (can.manage && row.raw_status === 'in_progress') {
            items.push({
                icon: <CheckCircle2 className="h-3.5 w-3.5" />,
                label: 'Complete drill',
                sub: 'record the write-up',
                onClick: () => launchComplete({ id: row.id, reference: row.reference, type_label: row.type_label }, false),
            });
        }
        if (can.manage) {
            items.push({ icon: <UserPlus className="h-3.5 w-3.5" />, label: 'Add participant', onClick: () => openDrill(row.id, { section: 'participants', action: 'add_participant' }) });
            items.push({ icon: <ClipboardList className="h-3.5 w-3.5" />, label: 'Add finding', onClick: () => openDrill(row.id, { section: 'findings', action: 'add_finding' }) });
        }
        if (row.findings_open > 0) {
            items.push({ icon: <CheckCircle2 className="h-3.5 w-3.5" />, label: 'Resolve finding', onClick: () => openDrill(row.id, { section: 'findings' }) });
        }
        if (can.manage && row.raw_status === 'scheduled') {
            items.push({ icon: <Pencil className="h-3.5 w-3.5" />, label: 'Edit / reschedule', onClick: () => openDrill(row.id, { action: 'edit' }) });
        }
        if (can.manage && row.raw_status !== 'completed' && row.raw_status !== 'cancelled') {
            items.push({ icon: <XCircle className="h-3.5 w-3.5" />, label: 'Cancel drill', tone: 'critical', onClick: () => openDrill(row.id, { action: 'cancel' }) });
        }
        items.push({ sep: true });
        items.push({ icon: <ExternalLink className="h-3.5 w-3.5" />, label: 'Open full page', sub: `/health-safety/drills/${row.id}`, onClick: () => router.visit(`/health-safety/drills/${row.id}`) });

        setCtx({ x: e.clientX, y: e.clientY, tag: status.label.toUpperCase(), meta: `${row.reference} · ${type.label}`, items });
    };

    const openHeroCtx = (e: ReactMouseEvent) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [];
        if (can.manage) {
            items.push({ icon: <Plus className="h-3.5 w-3.5" />, label: 'Schedule drill', tone: 'primary', onClick: () => setScheduleOpen(true) });
        }
        items.push({ icon: <AlarmClock className="h-3.5 w-3.5" />, label: 'Jump to overdue', onClick: () => setTab('overdue') });
        items.push({ sep: true });
        items.push({ icon: <BarChart3 className="h-3.5 w-3.5" />, label: 'Drill analytics', onClick: () => router.visit('/health-safety/analytics') });
        setCtx({ x: e.clientX, y: e.clientY, tag: 'DRILLS', meta: 'Quick actions', items });
    };

    const live = hero.live;
    const at = hero.attention;
    const b = hero.badges;
    const pctTone: ChipTone = b.sites_drilled_pct >= 80 ? 'success' : b.sites_drilled_pct >= 50 ? 'warning' : 'critical';
    const tableTitle =
        ({ all: 'All drills', scheduled: 'Scheduled drills', overdue: 'Overdue drills', in_progress: 'In progress', completed: 'Completed drills', findings: 'Drills with open findings' }[
            tab
        ] ?? 'Emergency drills');

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Emergency Drills', href: '/health-safety/drills' }]}>
            <Head title="Emergency Drills" />

            <div className="flex flex-col gap-6 p-6">
                {/* ---- Hero ---- */}
                <div onContextMenu={openHeroCtx}>
                    <HeroShell
                        footer={
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                                <HeroSegmented label="Period" variant="pill" ariaLabel="Date range" items={PERIOD_ITEMS} value={filters.period || '6mo'} onChange={(key) => go({ period: key })} />
                                {sites?.length ? (
                                    <EntityFilter label="Site" allLabel="All sites" items={sites} value={filters.site_id} onChange={(id) => go({ site_id: id })} onDark />
                                ) : null}
                                <label className="inline-flex items-center gap-1.5">
                                    <span className="text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">Type</span>
                                    <select
                                        value={filters.drill_type ?? ''}
                                        onChange={(e) => go({ drill_type: e.target.value || null })}
                                        aria-label="Drill type filter"
                                        className="rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-2.5 py-1.5 text-xs font-medium text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none [&>option]:text-foreground"
                                    >
                                        <option value="">All types</option>
                                        {DRILL_TYPE_OPTIONS.map((o) => (
                                            <option key={o.value} value={o.value}>
                                                {o.label}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <label className="inline-flex items-center gap-1.5">
                                    <span className="text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">Outcome</span>
                                    <select
                                        value={filters.outcome ?? ''}
                                        onChange={(e) => go({ outcome: e.target.value || null })}
                                        aria-label="Outcome filter"
                                        className="rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-2.5 py-1.5 text-xs font-medium text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none [&>option]:text-foreground"
                                    >
                                        <option value="">Any outcome</option>
                                        <option value="passed">Passed</option>
                                        <option value="passed_actions">Passed with actions</option>
                                        <option value="failed">Failed</option>
                                    </select>
                                </label>
                                <div className="relative ml-auto">
                                    <Siren className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                    <input
                                        type="search"
                                        placeholder="Search drills…"
                                        defaultValue={filters.q ?? ''}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') go({ q: (e.target as HTMLInputElement).value || null });
                                        }}
                                        className="w-48 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pr-2.5 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                                    />
                                </div>
                                {hasFilters ? (
                                    // eslint-disable-next-line no-restricted-syntax -- onDark clear affordance on the hero footer
                                    <button type="button" onClick={clearFilters} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-primary-foreground/70 transition-colors hover:text-primary-foreground">
                                        <XCircle className="h-3 w-3" /> Clear
                                    </button>
                                ) : null}
                            </div>
                        }
                    >
                        <WorkflowRibbon current="drill" />

                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex items-start gap-4">
                                <HeroMedallion icon={Siren} />
                                <div className="flex flex-col gap-1.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <HeroStatusPill>Drill register · readiness</HeroStatusPill>
                                        <span className="inline-flex items-center gap-1.5 rounded-full bg-primary-foreground/15 px-2.5 py-1 text-[11px] font-semibold tracking-[0.04em] text-primary-foreground/85 uppercase">
                                            <Flame className="h-3.5 w-3.5" /> FENZ evacuation scheme
                                        </span>
                                    </div>
                                    <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">Emergency Drills</h1>
                                    <p className="max-w-xl text-sm text-primary-foreground/70">
                                        Schedule, run and close out evacuation, fire and lockdown drills across every site — then drive the findings to verified action.
                                        Each drill carries its own lifecycle: schedule → run → record findings → close out.
                                    </p>
                                    <div className="mt-1.5 flex flex-wrap gap-2">
                                        <HeroBadge icon={CheckCircle2} tone={pctTone}>
                                            Sites drilled (6mo) · {b.sites_drilled_pct}%
                                        </HeroBadge>
                                        <HeroBadge icon={Flame} tone={b.drills_overdue > 0 ? 'warning' : 'success'}>
                                            {b.drills_overdue > 0 ? `Fire · ${b.drills_overdue} drill${b.drills_overdue === 1 ? '' : 's'} overdue` : 'Fire · drills current'}
                                        </HeroBadge>
                                        <HeroBadge icon={ShieldCheck} tone={b.nga_paerewa_certified ? 'success' : 'warning'}>
                                            Ngā Paerewa NZS 8134:2021 · {b.nga_paerewa_certified ? 'Certified' : 'At risk'}
                                        </HeroBadge>
                                        <HeroBadge icon={AlertTriangle} tone={b.fenz_reviews_due > 0 ? 'critical' : 'success'}>
                                            {b.fenz_reviews_due > 0 ? `FENZ scheme · ${b.fenz_reviews_due} review due` : 'FENZ scheme · current'}
                                        </HeroBadge>
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center gap-2">
                                <Popover>
                                    <PopoverTrigger asChild>
                                        <Button size="sm" className="border border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20">
                                            <FileText className="mr-1.5 h-4 w-4" /> Board reports
                                            <span aria-hidden className="ml-1">▾</span>
                                        </Button>
                                    </PopoverTrigger>
                                    <PopoverContent align="end" className="w-60 p-1.5">
                                        {[
                                            { label: 'Drill & compliance analytics', href: '/health-safety/analytics', icon: BarChart3 },
                                            { label: 'H&S dashboard', href: '/health-safety', icon: LayoutDashboard },
                                        ].map((r) => (
                                            // eslint-disable-next-line no-restricted-syntax -- popover menu item (report link)
                                            <button key={r.href} type="button" onClick={() => router.visit(r.href)} className="flex w-full items-center gap-2.5 rounded-md p-2.5 text-left text-sm font-medium transition-colors hover:bg-muted">
                                                <r.icon className="h-4 w-4 shrink-0 text-primary" />
                                                {r.label}
                                            </button>
                                        ))}
                                    </PopoverContent>
                                </Popover>
                                {can.manage ? (
                                    <Button size="sm" onClick={() => setScheduleOpen(true)} className="bg-primary-foreground text-primary hover:bg-primary-foreground/90">
                                        <Plus className="mr-1.5 h-4 w-4" /> Schedule drill
                                    </Button>
                                ) : null}
                            </div>
                        </div>

                        {/* stat clusters */}
                        <div className="grid gap-3 lg:grid-cols-2">
                            <HeroCluster title="Live · schedule" icon={Activity}>
                                <HeroClusterTile href="/health-safety/drills?tab=scheduled" label="Scheduled" value={fmt(live.scheduled)} caption="upcoming" tone="neutral" />
                                <HeroClusterTile href="/health-safety/drills?tab=overdue" label="Overdue" value={fmt(live.overdue)} caption="past due date" tone="critical" />
                                <HeroClusterTile href="/health-safety/drills?tab=in_progress" label="In progress" value={fmt(live.in_progress)} caption="running now" tone="warning" />
                                <HeroClusterTile href="/health-safety/drills?tab=completed" label="Completed" value={fmt(live.completed)} caption="this period" tone="success" />
                            </HeroCluster>
                            <HeroCluster title="Needs attention" icon={AlertTriangle}>
                                <HeroClusterTile href="/health-safety/drills?tab=overdue" label="Sites overdue" value={fmt(at.sites_overdue)} caption="drill 6mo+ ago" tone="critical" />
                                <HeroClusterTile href="/health-safety/drills?tab=findings" label="Findings open" value={fmt(at.findings_open)} caption="across drills" tone="warning" />
                                <HeroClusterTile href="/health-safety/drills?tab=findings" label="Findings overdue" value={fmt(at.findings_overdue)} caption="corrective late" tone="critical" />
                                <HeroClusterTile href="/health-safety/drills?tab=in_progress" label="Await write-up" value={fmt(at.awaiting_writeup)} caption="ran, not recorded" tone="warning" />
                            </HeroCluster>
                        </div>
                    </HeroShell>
                </div>

                {/* ---- Tabs ---- */}
                <TabStrip value={tab} items={TABS} onChange={setTab} ariaLabel="Emergency drill views" />

                {/* ---- Table ---- */}
                <section className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                    <RegisterTableHeader icon={Siren} title={tableTitle} subtitle="the readiness view" hint="Right-click a row for the full lifecycle" hintIcon={MousePointer2} />
                    <DrillTable rows={drills.data} onRowCtx={openRowCtx} onOpen={(id) => openDrill(id)} />
                </section>

                {drills.last_page > 1 ? <LaravelPagination links={drills.links} /> : null}
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}

            {detail ? (
                <DrillDetailDialog
                    key={detail.id}
                    detail={detail}
                    open
                    onClose={closeDetail}
                    initialSection={pendingSection}
                    initialAction={pendingAction}
                    onLaunchComplete={() => launchComplete({ id: detail.id, reference: detail.reference, type_label: detail.type_label }, true)}
                />
            ) : null}

            <DrillScheduleDialog open={scheduleOpen} onClose={() => setScheduleOpen(false)} sites={sites} staff={staff} defaultSiteId={filters.site_id} />

            {completeDrill ? (
                <DrillCompleteDialog open onClose={() => setCompleteDrill(null)} drill={completeDrill} />
            ) : null}
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Hero badge (onDark compliance chip)                               */
/* ------------------------------------------------------------------ */

function HeroBadge({ icon: Icon, tone, children }: { icon: LucideIcon; tone: ChipTone; children: ReactNode }) {
    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ${BADGE_TONE[tone]}`}>
            <Icon className="h-3.5 w-3.5" /> {children}
        </span>
    );
}

/* ------------------------------------------------------------------ */
/*  Table                                                              */
/* ------------------------------------------------------------------ */

function DrillTable({ rows, onRowCtx, onOpen }: { rows: DrillRow[]; onRowCtx: (e: ReactMouseEvent, row: DrillRow) => void; onOpen: (id: number) => void }) {
    if (rows.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center gap-2 px-6 py-16 text-center">
                <Siren className="h-8 w-8 text-muted-foreground" />
                <div className="text-sm font-semibold">No drills here</div>
                <p className="text-xs text-muted-foreground">Nothing matches this tab and filters.</p>
            </div>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[1040px] text-sm">
                <thead>
                    <tr className="border-b border-border bg-muted/70 text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                        <th className="px-4 py-2.5">When</th>
                        <th className="px-4 py-2.5">Drill</th>
                        <th className="px-4 py-2.5">Site</th>
                        <th className="px-4 py-2.5">Status</th>
                        <th className="px-4 py-2.5">People</th>
                        <th className="px-4 py-2.5">Findings</th>
                        <th className="px-4 py-2.5">Flags</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border">
                    {rows.map((row) => (
                        <DrillRowView key={row.id} row={row} onRowCtx={onRowCtx} onOpen={onOpen} />
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function DrillRowView({ row, onRowCtx, onOpen }: { row: DrillRow; onRowCtx: (e: ReactMouseEvent, row: DrillRow) => void; onOpen: (id: number) => void }) {
    const type = typeMeta(row.drill_type);
    const status = statusMeta(row.status);
    const TypeIcon = type.icon;
    return (
        <tr
            onClick={() => onOpen(row.id)}
            onContextMenu={(e) => onRowCtx(e, row)}
            tabIndex={0}
            aria-label={`Open drill ${row.reference}`}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onOpen(row.id);
                }
            }}
            className="cursor-pointer transition-colors hover:bg-muted/45 focus-visible:bg-muted/45 focus-visible:outline-none"
        >
            <td className="px-4 py-3 align-top">
                <div className="font-semibold">{whenLabel(row)}</div>
                <div className="text-xs text-muted-foreground">{row.reference}</div>
            </td>
            <td className="px-4 py-3 align-top">
                <div className="flex items-start gap-2">
                    <TypeIcon className={`mt-0.5 h-4 w-4 shrink-0 ${ICON_TEXT[type.tone]}`} />
                    <div className="min-w-0">
                        <div className="font-semibold">{type.label}</div>
                        <div className="truncate text-xs text-muted-foreground">{row.title}</div>
                    </div>
                </div>
            </td>
            <td className="px-4 py-3 align-top">
                {row.site ? (
                    <div className="flex items-center gap-2">
                        <span className={`grid h-7 w-7 shrink-0 place-items-center rounded-full text-[11px] font-bold ${entityTone(row.site.id)}`}>{initials(row.site.name)}</span>
                        <div className="min-w-0">
                            <div className="truncate font-medium">{row.site.name}</div>
                            {row.site.region ? <div className="truncate text-xs text-muted-foreground">{row.site.region}</div> : null}
                        </div>
                    </div>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </td>
            <td className="px-4 py-3 align-top">
                <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${CHIP[status.tone]}`}>
                    <status.icon className="h-3 w-3" /> {status.label}
                </span>
            </td>
            <td className="px-4 py-3 align-top">
                {row.people_label ? (
                    <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                        <Users className="h-3.5 w-3.5" /> {row.people_label}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </td>
            <td className="px-4 py-3 align-top">
                {row.findings_open > 0 ? (
                    <span className="inline-flex items-center gap-1.5 font-medium text-status-warning">
                        <ClipboardList className="h-3.5 w-3.5" /> {row.findings_open} open
                    </span>
                ) : (
                    <span className="text-muted-foreground">{row.findings_count > 0 ? `${row.findings_count} closed` : '0'}</span>
                )}
            </td>
            <td className="px-4 py-3 align-top">
                <div className="flex flex-wrap gap-1.5">
                    {row.flags.overdue ? (
                        <FlagBadge icon={AlarmClock} tone="critical" title="Past its scheduled date">
                            Overdue
                        </FlagBadge>
                    ) : null}
                    {row.flags.running ? (
                        <FlagBadge icon={Loader} tone="warning" title="Drill in progress">
                            Running
                        </FlagBadge>
                    ) : null}
                    {row.flags.finding_overdue ? (
                        <FlagBadge icon={AlertTriangle} tone="critical" title="A corrective action is overdue">
                            Finding overdue
                        </FlagBadge>
                    ) : null}
                    {row.raw_status === 'completed' && row.flags.open_findings > 0 ? (
                        <FlagBadge icon={ClipboardList} tone="warning" title="Open findings">
                            {row.flags.open_findings} open
                        </FlagBadge>
                    ) : null}
                    {!row.flags.overdue && !row.flags.running && !row.flags.finding_overdue && !(row.raw_status === 'completed' && row.flags.open_findings > 0) ? (
                        <span className="text-muted-foreground">—</span>
                    ) : null}
                </div>
            </td>
        </tr>
    );
}
