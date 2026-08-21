/* Injuries & Return-to-Work register — H&S gold standard. Shares the hs-hero-kit
 * hero chrome + rostering TabStrip/EntityFilter/ShiftContextMenu + register-row-kit
 * with /health-safety/events, /incidents, /safeguarding and /fleet-assets/incidents
 * so the whole safety workflow reads as one product. Modal-first: left-click a row
 * opens the detail dialog (partial reload of `detail` via ?injury=), right-click
 * opens the full lifecycle menu. NZ-only, web-only, semantic tokens only. */
import {
    SEVERITY_OPTIONS,
    SEVERITY_TONE,
    STATUS_META,
    TREATMENT_OPTIONS,
    injuryTypeLabel,
    severityLabel,
} from '@/components/health-safety/injury-constants';
import { InjuryDetailDialog } from '@/components/health-safety/injury-detail-dialog';
import type {
    IncidentOption,
    InjuryDetail,
    InjuryRow,
    InjurySectionKey,
    SiteOption,
    StaffOption,
} from '@/components/health-safety/injury-types';
import { InjuryWizardDialog } from '@/components/health-safety/injury-wizard-dialog';
import {
    EntityFilter,
    ShiftContextMenu,
    TabStrip,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
import { formatRelative } from '@/lib/datetime';
import {
    HeroCluster,
    HeroClusterTile,
    HeroComplianceBadges,
    HeroMedallion,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
    fmt,
    ngaPaerewaBadge,
    type HeroComplianceBadge,
    useNzsAssurance,
} from '@/pages/health-safety/components/hs-hero-kit';
import {
    FlagBadge,
    RegisterTableHeader,
    TONE_BG,
    TONE_DOT,
    entityTone,
    initials,
} from '@/pages/health-safety/components/register-row-kit';
import { WorkflowRibbon } from '@/pages/health-safety/components/workflow-ribbon';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRightLeft,
    BarChart3,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    Copy,
    Download,
    ExternalLink,
    FileText,
    HeartPulse,
    LayoutList,
    LifeBuoy,
    Link2,
    MousePointer2,
    MoveRight,
    Pencil,
    Plus,
    Search,
    Shield,
    ShieldAlert,
    Stethoscope,
    X,
} from 'lucide-react';
import { useState, type MouseEvent as ReactMouseEvent } from 'react';

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
};

type Filters = {
    q: string;
    site_id: number | null;
    severity: string | null;
    status: string | null;
    treatment: string | null;
    acc_open: boolean | null;
    worksafe: boolean | null;
    period: string;
    from: string | null;
    to: string | null;
    type: string | null;
    body_part: string | null;
};

type Hero = {
    live: {
        reported: number;
        under_treatment: number;
        return_to_work: number;
        recovered: number;
    };
    attention: {
        worksafe_awaiting: number;
        acc_unlodged: number;
        rtw_review_due: number;
        lost_time: number;
    };
    badges: {
        worksafe_awaiting: number;
        acc_open: number;
        ltifr: number | null;
        trifr: number | null;
        lost_time_days: number;
    };
};

type Props = {
    injuries: Paginated<InjuryRow>;
    tab: string;
    tabCounts: Record<string, number>;
    hero: Hero;
    filters: Filters;
    sites: SiteOption[];
    staff: StaffOption[];
    incidents: IncidentOption[];
    detail: InjuryDetail | null;
    can: { manage: boolean };
};

const PERIODS = [
    { key: 'all', label: 'All time' },
    { key: 'week', label: 'This week' },
    { key: '30d', label: '30 days' },
    { key: 'quarter', label: 'Quarter' },
    { key: 'year', label: 'Year' },
];

const TABS = (c: Record<string, number>): RosterTabItem[] => [
    {
        id: 'all',
        label: 'All',
        icon: LayoutList,
        tone: 'primary',
        badge: c.all || undefined,
    },
    {
        id: 'reported',
        label: 'Reported',
        icon: AlertTriangle,
        tone: 'warning',
        badge: c.reported || undefined,
    },
    {
        id: 'under_treatment',
        label: 'Under treatment',
        icon: HeartPulse,
        tone: 'info',
        badge: c.under_treatment || undefined,
    },
    {
        id: 'return_to_work',
        label: 'Return to work',
        icon: MoveRight,
        tone: 'success',
        badge: c.return_to_work || undefined,
    },
    {
        id: 'recovered',
        label: 'Recovered',
        icon: ClipboardCheck,
        tone: 'success',
        badge: c.recovered || undefined,
    },
    {
        id: 'closed',
        label: 'Closed',
        icon: X,
        tone: 'primary',
        badge: c.closed || undefined,
    },
    {
        id: 'worksafe',
        label: 'WorkSafe-notifiable',
        icon: ShieldAlert,
        tone: 'critical',
        badge: c.worksafe || undefined,
    },
    {
        id: 'acc',
        label: 'ACC open',
        icon: LifeBuoy,
        tone: 'info',
        badge: c.acc || undefined,
    },
];

export default function InjuriesIndex({
    injuries,
    tab,
    tabCounts,
    hero,
    filters,
    sites,
    staff,
    incidents,
    detail,
    can,
}: Props) {
    const assurance = useNzsAssurance();
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [wizard, setWizard] = useState<{
        open: boolean;
        mode: 'create' | 'edit';
        injury: InjuryDetail | null;
    }>({ open: false, mode: 'create', injury: null });
    const [pendingSection, setPendingSection] =
        useState<InjurySectionKey>('overview');
    const [pendingAction, setPendingAction] = useState<string | null>(null);

    const ANY = '__any__';

    const go = (next: Partial<Filters>) =>
        router.get(
            '/health-safety/injuries',
            cleanParams({ ...filters, ...next, tab }),
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const setTab = (id: string) =>
        router.get(
            '/health-safety/injuries',
            cleanParams({ ...filters, tab: id }),
            { preserveScroll: true },
        );

    const openInjury = (
        id: number,
        opts?: { section?: InjurySectionKey; action?: string },
    ) => {
        setPendingSection(opts?.section ?? 'overview');
        setPendingAction(opts?.action ?? null);
        router.get(
            '/health-safety/injuries',
            cleanParams({ ...filters, tab, injury: id }),
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );
    };
    const closeDetail = () =>
        router.get(
            '/health-safety/injuries',
            cleanParams({ ...filters, tab }),
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );

    const clearFilters = () =>
        router.get(
            '/health-safety/injuries',
            { tab },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const transition = (id: number, status: string) =>
        router.post(
            `/health-safety/injuries/${id}/status`,
            { status },
            { preserveScroll: true, preserveState: true },
        );
    const flagWorksafe = (id: number) =>
        router.put(
            `/health-safety/injuries/${id}`,
            { worksafe_notifiable: true },
            { preserveScroll: true, preserveState: true },
        );

    const hasFilters = Boolean(
        filters.q ||
        filters.site_id ||
        filters.severity ||
        filters.treatment ||
        filters.acc_open ||
        filters.worksafe ||
        (filters.period && filters.period !== 'all'),
    );

    const exportUrl = `/health-safety/injuries/export?${new URLSearchParams(Object.entries(cleanParams({ ...filters, tab })).map(([k, v]) => [k, String(v)])).toString()}`;

    // ── Row right-click menu ──
    const openRowCtx = (e: ReactMouseEvent, row: InjuryRow) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [];
        items.push({
            icon: <FileText className="h-4 w-4" />,
            label: 'View injury',
            sub: row.reference,
            tone: 'primary',
            onClick: () => openInjury(row.id),
        });
        items.push({
            icon: <Pencil className="h-4 w-4" />,
            label: 'Edit details',
            onClick: () => openEdit(row.id),
        });
        if (can.manage) {
            items.push({ sep: true });
            if (row.status === 'reported')
                items.push({
                    icon: <HeartPulse className="h-4 w-4" />,
                    label: 'Start treatment',
                    onClick: () => transition(row.id, 'under_treatment'),
                });
            if (row.status === 'under_treatment')
                items.push({
                    icon: <ArrowRightLeft className="h-4 w-4" />,
                    label: 'Begin return to work',
                    onClick: () => transition(row.id, 'return_to_work'),
                });
            if (
                row.status === 'return_to_work' ||
                row.status === 'under_treatment'
            )
                items.push({
                    icon: <CheckCircle2 className="h-4 w-4" />,
                    label: 'Mark recovered',
                    onClick: () => transition(row.id, 'recovered'),
                });
            items.push({
                icon: <ArrowRightLeft className="h-4 w-4" />,
                label: 'Add RTW plan',
                onClick: () =>
                    openInjury(row.id, { section: 'rtw', action: 'add_rtw' }),
            });
            items.push({
                icon: <Stethoscope className="h-4 w-4" />,
                label: 'Record capacity assessment',
                onClick: () =>
                    openInjury(row.id, {
                        section: 'capacity',
                        action: 'add_capacity',
                    }),
            });
            items.push({
                icon: <LifeBuoy className="h-4 w-4" />,
                label: row.acc_claim_lodged
                    ? 'Update ACC claim'
                    : 'Lodge ACC claim',
                onClick: () =>
                    openInjury(row.id, { section: 'overview', action: 'acc' }),
            });
            if (!row.worksafe_notifiable)
                items.push({
                    icon: <ShieldAlert className="h-4 w-4" />,
                    label: 'Flag WorkSafe-notifiable',
                    onClick: () => flagWorksafe(row.id),
                });
            if (row.status !== 'closed')
                items.push({
                    icon: <X className="h-4 w-4" />,
                    label: 'Close',
                    tone: 'critical',
                    onClick: () => transition(row.id, 'closed'),
                });
        }
        items.push({ sep: true });
        if (row.related_incident_id)
            items.push({
                icon: <Link2 className="h-4 w-4" />,
                label: 'Open linked incident',
                sub:
                    row.related_incident_ref ??
                    `INC-${String(row.related_incident_id).padStart(4, '0')}`,
                onClick: () =>
                    router.visit(
                        `/incidents?incident=${row.related_incident_id}`,
                    ),
            });
        items.push({
            icon: <Copy className="h-4 w-4" />,
            label: 'Copy link',
            onClick: () =>
                navigator.clipboard?.writeText(
                    `${window.location.origin}/health-safety/injuries/${row.id}`,
                ),
        });
        items.push({
            icon: <ExternalLink className="h-4 w-4" />,
            label: 'Open full page',
            sub: `/health-safety/injuries/${row.id}`,
            onClick: () => router.visit(`/health-safety/injuries/${row.id}`),
        });

        const tone = SEVERITY_TONE[row.severity] ?? 'neutral';
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: severityLabel(row.severity).toUpperCase(),
            tagBg: `var(--status-${tone === 'neutral' ? 'info' : tone}-bg)`,
            tagColor: `var(--status-${tone === 'neutral' ? 'info' : tone})`,
            meta: `${row.worker?.name ?? 'Unknown'} · ${injuryTypeLabel(row.injury_type)}`,
            items,
        });
    };

    const openHeroCtx = (e: ReactMouseEvent) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [];
        if (can.manage)
            items.push({
                icon: <Plus className="h-4 w-4" />,
                label: 'Record injury',
                tone: 'primary',
                onClick: openCreate,
            });
        items.push({
            icon: <Download className="h-4 w-4" />,
            label: 'Export register (CSV)',
            onClick: () => {
                window.location.href = exportUrl;
            },
        });
        items.push({
            icon: <BarChart3 className="h-4 w-4" />,
            label: 'Open analytics',
            sub: '/health-safety/analytics',
            onClick: () => router.visit('/health-safety/analytics'),
        });
        items.push({
            icon: <ShieldAlert className="h-4 w-4" />,
            label: 'WorkSafe-notifiable register',
            onClick: () => setTab('worksafe'),
        });
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: 'REGISTER',
            meta: 'Injuries & Return to Work',
            items,
        });
    };

    const openCreate = () =>
        setWizard({ open: true, mode: 'create', injury: null });
    const openEdit = (id: number) => {
        // The wizard needs the full record — open the detail (which loads it) then edit from there.
        openInjury(id, { section: 'overview' });
    };

    const badges: HeroComplianceBadge[] = [
        {
            icon: ShieldAlert,
            tone: hero.badges.worksafe_awaiting > 0 ? 'critical' : 'success',
            label: `WorkSafe-notifiable · ${hero.badges.worksafe_awaiting} awaiting`,
        },
        {
            icon: LifeBuoy,
            tone: hero.badges.acc_open > 0 ? 'warning' : 'success',
            label: `ACC claims · ${hero.badges.acc_open} open`,
        },
        {
            icon: BarChart3,
            tone: 'success',
            label: `LTIFR ${hero.badges.ltifr ?? '—'} · TRIFR ${hero.badges.trifr ?? '—'} (rolling 12m)`,
        },
        {
            icon: Clock,
            tone: hero.badges.lost_time_days > 30 ? 'warning' : 'success',
            label: `Lost-time · ${hero.badges.lost_time_days} days`,
        },
        ngaPaerewaBadge(assurance.certification_status),
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                {
                    title: 'Injuries & Return to Work',
                    href: '/health-safety/injuries',
                },
            ]}
        >
            <Head title="Injuries & Return to Work" />

            <div className="flex flex-col gap-5 p-5">
                <div onContextMenu={openHeroCtx}>
                    <HeroShell
                        footer={
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                                <HeroSegmented
                                    label="Period"
                                    variant="pill"
                                    ariaLabel="Date range"
                                    items={PERIODS}
                                    value={filters.period || 'all'}
                                    onChange={(period) => go({ period })}
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
                                <FooterSelect
                                    label="Severity"
                                    value={filters.severity}
                                    onChange={(v) => go({ severity: v })}
                                    options={SEVERITY_OPTIONS}
                                    any={ANY}
                                />
                                <FooterSelect
                                    label="Treatment"
                                    value={filters.treatment}
                                    onChange={(v) => go({ treatment: v })}
                                    options={TREATMENT_OPTIONS}
                                    any={ANY}
                                />
                                <HeroToggle
                                    label="ACC open"
                                    active={!!filters.acc_open}
                                    onClick={() =>
                                        go({
                                            acc_open: filters.acc_open
                                                ? null
                                                : true,
                                        })
                                    }
                                />
                                <HeroToggle
                                    label="WorkSafe"
                                    active={!!filters.worksafe}
                                    onClick={() =>
                                        go({
                                            worksafe: filters.worksafe
                                                ? null
                                                : true,
                                        })
                                    }
                                />
                                <div className="relative ml-auto">
                                    <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                    {/* eslint-disable-next-line no-restricted-syntax -- on-dark hero search input uses primary-foreground tokens */}
                                    <input
                                        value={filters.q ?? ''}
                                        onChange={(e) =>
                                            go({ q: e.target.value })
                                        }
                                        placeholder="Search workers…"
                                        className="h-8 w-44 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 pl-8 text-[13px] text-primary-foreground placeholder:text-primary-foreground/50 focus:ring-2 focus:ring-primary-foreground/30 focus:outline-none"
                                    />
                                </div>
                                {hasFilters ? (
                                    // eslint-disable-next-line no-restricted-syntax -- on-dark hero Clear uses primary-foreground tokens
                                    <button
                                        type="button"
                                        onClick={clearFilters}
                                        className="text-[13px] font-medium text-primary-foreground/70 hover:text-primary-foreground"
                                    >
                                        Clear
                                    </button>
                                ) : null}
                            </div>
                        }
                    >
                        <WorkflowRibbon current="report" />

                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex items-start gap-4">
                                <HeroMedallion icon={HeartPulse} />
                                <div className="flex flex-col gap-1.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <HeroStatusPill>
                                            Injury &amp; RTW register · synced
                                            just now
                                        </HeroStatusPill>
                                        {/* eslint-disable-next-line no-restricted-syntax -- on-dark eyebrow chip uses primary-foreground tokens */}
                                        <span className="rounded-full border border-primary-foreground/20 px-2 py-0.5 text-[11px] font-medium text-primary-foreground/70">
                                            Staff · ACC · WorkSafe NZ
                                        </span>
                                    </div>
                                    <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">
                                        Injuries &amp; Return to Work
                                    </h1>
                                    <p className="max-w-xl text-sm text-primary-foreground/70">
                                        Every workplace injury to a staff member
                                        — tracked from report through treatment,
                                        ACC claim and staged return-to-work to
                                        recovery. Right-click any row for the
                                        full lifecycle.
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <Popover>
                                    <PopoverTrigger asChild>
                                        {/* eslint-disable-next-line no-restricted-syntax -- on-dark hero button uses primary-foreground tokens */}
                                        <button
                                            type="button"
                                            className="inline-flex items-center gap-1.5 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-[13px] font-semibold text-primary-foreground hover:bg-primary-foreground/20"
                                        >
                                            <Download className="h-4 w-4" />{' '}
                                            Export &amp; reports
                                        </button>
                                    </PopoverTrigger>
                                    <PopoverContent
                                        align="end"
                                        className="w-64 p-1.5"
                                    >
                                        <ReportLink
                                            icon={Download}
                                            label="Export injury register (CSV)"
                                            onClick={() => {
                                                window.location.href =
                                                    exportUrl;
                                            }}
                                        />
                                        <ReportLink
                                            icon={BarChart3}
                                            label="Injury analytics & LTIFR/TRIFR"
                                            onClick={() =>
                                                router.visit(
                                                    '/health-safety/analytics',
                                                )
                                            }
                                        />
                                        <ReportLink
                                            icon={ShieldAlert}
                                            label="WorkSafe-notifiable register"
                                            onClick={() => setTab('worksafe')}
                                        />
                                        <ReportLink
                                            icon={LifeBuoy}
                                            label="ACC claims (open)"
                                            onClick={() => setTab('acc')}
                                        />
                                    </PopoverContent>
                                </Popover>
                                {can.manage ? (
                                    // eslint-disable-next-line no-restricted-syntax -- on-dark primary hero CTA uses primary-foreground tokens
                                    <button
                                        type="button"
                                        onClick={openCreate}
                                        className="inline-flex items-center gap-1.5 rounded-md bg-primary-foreground px-3.5 py-2 text-[13px] font-bold text-primary hover:bg-primary-foreground/90"
                                    >
                                        <Plus className="h-4 w-4" /> Record
                                        injury
                                    </button>
                                ) : null}
                            </div>
                        </div>

                        <div className="grid gap-3 lg:grid-cols-2">
                            <HeroCluster
                                title="Live · open caseload"
                                icon={Activity}
                            >
                                <HeroClusterTile
                                    href="/health-safety/injuries?tab=reported"
                                    label="Reported"
                                    value={fmt(hero.live.reported)}
                                    caption="awaiting triage"
                                    tone="warning"
                                />
                                <HeroClusterTile
                                    href="/health-safety/injuries?tab=under_treatment"
                                    label="Treatment"
                                    value={fmt(hero.live.under_treatment)}
                                    caption="in care"
                                    tone="neutral"
                                />
                                <HeroClusterTile
                                    href="/health-safety/injuries?tab=return_to_work"
                                    label="RTW"
                                    value={fmt(hero.live.return_to_work)}
                                    caption="staged return"
                                    tone="success"
                                />
                                <HeroClusterTile
                                    href="/health-safety/injuries?tab=recovered"
                                    label="Recovered"
                                    value={fmt(hero.live.recovered)}
                                    caption="this period"
                                    tone="success"
                                />
                            </HeroCluster>
                            <HeroCluster
                                title="Needs attention"
                                icon={AlertTriangle}
                            >
                                <HeroClusterTile
                                    href="/health-safety/injuries?tab=worksafe"
                                    label="WorkSafe"
                                    value={fmt(
                                        hero.attention.worksafe_awaiting,
                                    )}
                                    caption={
                                        hero.attention.worksafe_awaiting > 0
                                            ? 'notify ASAP'
                                            : 'none pending'
                                    }
                                    tone="critical"
                                />
                                <HeroClusterTile
                                    href="/health-safety/injuries?tab=acc"
                                    label="ACC unlodged"
                                    value={fmt(hero.attention.acc_unlodged)}
                                    caption="open claims"
                                    tone="warning"
                                />
                                <HeroClusterTile
                                    href="/health-safety/injuries?tab=return_to_work"
                                    label="RTW review"
                                    value={fmt(hero.attention.rtw_review_due)}
                                    caption="plans due"
                                    tone="warning"
                                />
                                <HeroClusterTile
                                    href="/health-safety/injuries?tab=all"
                                    label="Lost-time"
                                    value={fmt(hero.attention.lost_time)}
                                    caption="days accruing"
                                    tone="critical"
                                />
                            </HeroCluster>
                        </div>

                        <HeroComplianceBadges items={badges} />
                    </HeroShell>
                </div>

                <TabStrip
                    value={tab}
                    items={TABS(tabCounts)}
                    onChange={setTab}
                    ariaLabel="Injury register views"
                />

                <section className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                    <RegisterTableHeader
                        icon={HeartPulse}
                        title={`${tabLabel(tab)} injuries`}
                        subtitle={`${injuries.data.length} record${injuries.data.length === 1 ? '' : 's'} shown`}
                        hint="Right-click a row for the full lifecycle"
                        hintIcon={MousePointer2}
                    />
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-border text-left text-[11px] tracking-wide text-muted-foreground uppercase">
                                    <th className="px-4 py-2.5 font-semibold">
                                        Worker
                                    </th>
                                    <th className="px-4 py-2.5 font-semibold">
                                        When
                                    </th>
                                    <th className="px-4 py-2.5 font-semibold">
                                        Injury
                                    </th>
                                    <th className="px-4 py-2.5 font-semibold">
                                        Severity
                                    </th>
                                    <th className="px-4 py-2.5 font-semibold">
                                        Status
                                    </th>
                                    <th className="px-4 py-2.5 text-right font-semibold">
                                        Lost days
                                    </th>
                                    <th className="px-4 py-2.5 font-semibold">
                                        ACC
                                    </th>
                                    <th className="px-4 py-2.5 font-semibold">
                                        WorkSafe
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {injuries.data.map((row) => {
                                    const sm =
                                        STATUS_META[row.status] ??
                                        STATUS_META.reported;
                                    const sevTone =
                                        SEVERITY_TONE[row.severity] ??
                                        'neutral';
                                    return (
                                        <tr
                                            key={row.id}
                                            onClick={() => openInjury(row.id)}
                                            onContextMenu={(e) =>
                                                openRowCtx(e, row)
                                            }
                                            tabIndex={0}
                                            aria-label={`Open injury ${row.reference}`}
                                            onKeyDown={(e) => {
                                                if (
                                                    e.key === 'Enter' ||
                                                    e.key === ' '
                                                ) {
                                                    e.preventDefault();
                                                    openInjury(row.id);
                                                }
                                            }}
                                            className="cursor-pointer border-b border-border transition-colors last:border-0 hover:bg-muted/45 focus-visible:bg-muted/45 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                        >
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2.5">
                                                    <span
                                                        className={`grid h-8 w-8 shrink-0 place-items-center rounded-full text-[11px] font-bold ${entityTone(row.worker?.id ?? row.id)}`}
                                                    >
                                                        {initials(
                                                            row.worker?.name,
                                                        )}
                                                    </span>
                                                    <div className="min-w-0">
                                                        <div className="font-semibold">
                                                            {row.worker?.name ??
                                                                'Unknown'}
                                                        </div>
                                                        <div className="truncate text-xs text-muted-foreground">
                                                            {row.site?.name ??
                                                                'No site'}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="text-[13px]">
                                                    {row.injury_date
                                                        ? formatRelative(
                                                              row.injury_date,
                                                          )
                                                        : '—'}
                                                </div>
                                                <div className="text-[11px] text-muted-foreground">
                                                    {row.reference}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2">
                                                    <span
                                                        className={`h-2 w-2 shrink-0 rounded-full ${TONE_DOT[sevTone]}`}
                                                    />
                                                    <div className="min-w-0">
                                                        <div className="font-medium">
                                                            {
                                                                row.injury_type_label
                                                            }
                                                        </div>
                                                        <div className="max-w-[240px] truncate text-xs text-muted-foreground">
                                                            {row.body_part_affected ??
                                                                '—'}
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span
                                                    className={`inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold ${TONE_BG[sevTone]}`}
                                                >
                                                    {severityLabel(
                                                        row.severity,
                                                    )}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span
                                                    className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold ${sm.chip}`}
                                                >
                                                    <span
                                                        className={`h-1.5 w-1.5 rounded-full ${sm.dot}`}
                                                    />{' '}
                                                    {sm.label}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <span
                                                    className={`font-semibold tabular-nums ${row.lost_time_days > 0 ? 'text-status-critical' : 'text-muted-foreground'}`}
                                                >
                                                    {row.lost_time_days}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                {row.acc_claim_lodged ? (
                                                    <span className="inline-flex rounded-full bg-status-info-bg px-2 py-0.5 text-[11px] font-semibold text-status-info">
                                                        {row.acc_claim_number ??
                                                            'Lodged'}
                                                    </span>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">
                                                        Not lodged
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                {row.worksafe_notifiable ? (
                                                    <FlagBadge
                                                        icon={ShieldAlert}
                                                        tone="critical"
                                                        title="WorkSafe-notifiable"
                                                    >
                                                        {row.status ===
                                                        'reported'
                                                            ? 'Notify due'
                                                            : 'Notified'}
                                                    </FlagBadge>
                                                ) : (
                                                    <span className="text-xs text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                        {injuries.data.length === 0 ? (
                            <div className="flex flex-col items-center gap-1 py-12 text-center">
                                <Shield className="h-8 w-8 text-muted-foreground/50" />
                                <div className="text-sm font-semibold">
                                    No injuries in this view
                                </div>
                                <p className="text-[13px] text-muted-foreground">
                                    Nothing matches this tab and filters.
                                </p>
                            </div>
                        ) : null}
                    </div>
                </section>

                {injuries.last_page > 1 ? (
                    <LaravelPagination
                        links={injuries.links}
                        lastPage={injuries.last_page}
                    />
                ) : null}
            </div>

            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}

            <InjuryWizardDialog
                open={wizard.open}
                onClose={() => setWizard((w) => ({ ...w, open: false }))}
                mode={wizard.mode}
                injury={wizard.injury}
                staff={staff}
                sites={sites}
                incidents={incidents}
                onSaved={(id, section) =>
                    openInjury(
                        id,
                        section === 'rtw'
                            ? { section: 'rtw', action: 'add_rtw' }
                            : { section: 'overview' },
                    )
                }
            />

            {detail ? (
                <InjuryDetailDialog
                    key={detail.id}
                    detail={detail}
                    open
                    onClose={closeDetail}
                    staff={staff}
                    initialSection={pendingSection}
                    initialAction={pendingAction}
                    onEdit={() => {
                        setWizard({ open: true, mode: 'edit', injury: detail });
                        closeDetail();
                    }}
                />
            ) : null}
        </AppLayout>
    );
}

/* ── small on-dark hero helpers (tokens only) ── */

function FooterSelect({
    label,
    value,
    onChange,
    options,
    any,
}: {
    label: string;
    value: string | null;
    onChange: (v: string | null) => void;
    options: { value: string; label: string }[];
    any: string;
}) {
    return (
        <label className="flex items-center gap-1.5 text-[13px] text-primary-foreground/70">
            {label}
            {/* eslint-disable-next-line no-restricted-syntax -- on-dark hero select uses primary-foreground tokens */}
            <select
                value={value ?? any}
                onChange={(e) =>
                    onChange(e.target.value === any ? null : e.target.value)
                }
                className="h-8 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-2 text-[13px] text-primary-foreground focus:ring-2 focus:ring-primary-foreground/30 focus:outline-none"
            >
                <option value={any} className="text-foreground">
                    All
                </option>
                {options.map((o) => (
                    <option
                        key={o.value}
                        value={o.value}
                        className="text-foreground"
                    >
                        {o.label}
                    </option>
                ))}
            </select>
        </label>
    );
}

function HeroToggle({
    label,
    active,
    onClick,
}: {
    label: string;
    active: boolean;
    onClick: () => void;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- on-dark hero toggle uses primary-foreground tokens
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={`inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-[13px] font-medium transition-colors ${active ? 'border-primary-foreground/40 bg-primary-foreground/25 text-primary-foreground' : 'border-primary-foreground/20 bg-primary-foreground/5 text-primary-foreground/70 hover:bg-primary-foreground/15'}`}
        >
            {label}
        </button>
    );
}

function ReportLink({
    icon: Icon,
    label,
    onClick,
}: {
    icon: typeof BarChart3;
    label: string;
    onClick: () => void;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- full-width left-aligned popover menu item, intentionally a custom control
        <button
            type="button"
            onClick={onClick}
            className="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left text-[13px] font-medium hover:bg-muted"
        >
            <Icon className="h-4 w-4 text-muted-foreground" /> {label}
        </button>
    );
}

function tabLabel(tab: string): string {
    const map: Record<string, string> = {
        all: 'All',
        reported: 'Reported',
        under_treatment: 'Under-treatment',
        return_to_work: 'Return-to-work',
        recovered: 'Recovered',
        closed: 'Closed',
        worksafe: 'WorkSafe-notifiable',
        acc: 'ACC-open',
    };
    return map[tab] ?? 'All';
}

function cleanParams(
    params: Record<string, unknown>,
): Record<string, string | number | boolean> {
    const out: Record<string, string | number | boolean> = {};
    for (const [k, v] of Object.entries(params)) {
        if (v === null || v === undefined || v === '' || v === false) continue;
        out[k] = v as string | number | boolean;
    }
    return out;
}
