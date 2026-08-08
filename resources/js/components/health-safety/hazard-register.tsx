/* HazardRegister — the shared, gold-standard Hazards register chrome used by
 * BOTH the global compliance register (/compliance/hazards) and the per-site
 * register (/sites/{id}/hazards). One implementation, two thin pages, so the
 * surfaces can't drift. HeroShell + WorkflowRibbon + clusters + NZ compliance
 * badges + hero-footer filter bar; TabStrip; right-click + click rows;
 * detail-as-modal; create wizard. Semantic tokens only. NZ-only, web-only. */
import { HazardCreateDialog } from '@/components/health-safety/hazard-create-dialog';
import { HazardDetailDialog } from '@/components/health-safety/hazard-detail-dialog';
import {
    RiskChip,
    SeverityChip,
    StatusChip,
    fmtWhen,
    hazardLabelOf,
    siteTypeLabel,
    type HazardActionKey,
    type HazardCan,
    type HazardDetail,
    type HazardRow,
    type HazardSectionKey,
} from '@/components/health-safety/hazard-kit';
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
import {
    HeroCluster,
    HeroClusterTile,
    HeroComplianceBadges,
    HeroMedallion,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
    fmt,
} from '@/pages/health-safety/components/hs-hero-kit';
import {
    FlagBadge,
    RegisterTableHeader,
    TONE_DOT,
} from '@/pages/health-safety/components/register-row-kit';
import { WorkflowRibbon } from '@/pages/health-safety/components/workflow-ribbon';
import { router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Building2,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    Copy,
    ExternalLink,
    FileText,
    Flame,
    Home,
    ListChecks,
    MapPin,
    MousePointer2,
    Play,
    Plus,
    Search,
    ShieldAlert,
    ShieldCheck,
    UserPlus,
} from 'lucide-react';
import {
    useEffect,
    useState,
    type MouseEvent as ReactMouseEvent,
    type ReactNode,
} from 'react';

type Chip = { key: string; label: string; hint: string };

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type HazardFilters = {
    q: string | null;
    site_id: number | null;
    site_type: string | null;
    severity: string | null;
    risk_rating: string | null;
    assignee_id: number | null;
    due_state: string | null;
    tab: string | null;
    from: string | null;
    to: string | null;
};

export type HazardRegisterData = {
    hazards: Paginated<HazardRow>;
    tab: string;
    tabCounts: Record<string, number>;
    hero: {
        live: {
            open: number;
            in_progress: number;
            overdue: number;
            critical: number;
        };
        attention: {
            due_soon: number;
            unassigned: number;
            mitigated: number;
            closed_period: number;
        };
    };
    nzBadges: {
        worksafe_awaiting: number;
        sds_expiring: number;
        drills_due: number;
        drills_overdue: number;
        nga_paerewa_certified: boolean;
        first_aid_ok: boolean;
    };
    filters: HazardFilters;
    sites: Array<{ id: number; name: string; type: string }>;
    assignees: Array<{ id: number; name: string }>;
    detail: HazardDetail | null;
    can: HazardCan;
    severityOptions: string[];
    likelihoodOptions: string[];
    riskRatings: string[];
    recommendedBySiteType: Record<string, Chip[]>;
};

const BOARD_REPORTS = [
    {
        label: 'Board safety summary',
        href: '/health-safety/reports/board-summary',
    },
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

const RANGE_ITEMS = [
    { key: 'week', label: 'This week' },
    { key: '30d', label: '30 days' },
    { key: 'quarter', label: 'Quarter' },
    { key: 'all', label: 'All' },
];

const TABLE_TITLES: Record<string, string> = {
    all: 'All hazards',
    open: 'Open hazards',
    in_progress: 'In progress',
    overdue: 'Overdue hazards',
    critical: 'Critical open',
    closed: 'Closed & mitigated',
};

function todayStr() {
    const d = new Date();
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 10);
}
function daysAgoStr(n: number) {
    const d = new Date();
    d.setDate(d.getDate() - n);
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 10);
}

export function HazardRegister({
    baseUrl,
    scopedSite = null,
    data,
}: {
    baseUrl: string;
    scopedSite?: {
        id: number;
        name: string;
        type: string;
        suburb?: string | null;
    } | null;
    data: HazardRegisterData;
}) {
    const {
        hazards,
        tab,
        tabCounts,
        hero,
        nzBadges,
        filters,
        sites,
        assignees,
        detail,
        can,
        severityOptions,
        likelihoodOptions,
        riskRatings,
        recommendedBySiteType,
    } = data;

    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [pendingSection, setPendingSection] =
        useState<HazardSectionKey>('overview');
    const [pendingAction, setPendingAction] = useState<HazardActionKey | null>(
        null,
    );
    const [intent, setIntent] = useState(0);
    const [createOpen, setCreateOpen] = useState(false);
    const [boardOpen, setBoardOpen] = useState(false);
    const canGovernance = Boolean(
        (
            usePage().props as {
                auth?: { can?: { governance?: { view?: boolean } } };
            }
        ).auth?.can?.governance?.view,
    );

    // Auto-open the create wizard when arriving via a "Log hazard" deep-link
    // (e.g. the site-profile embed's ?action=add).
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (
            (params.get('action') === 'add' || params.get('create') === '1') &&
            can.create
        ) {
            setCreateOpen(true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps -- read the launch intent once on mount
    }, []);

    const go = (next: Partial<HazardFilters>) =>
        router.get(
            baseUrl,
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    const setTab = (id: string) =>
        router.get(baseUrl, { ...filters, tab: id }, { preserveScroll: true });
    const clearFilters = () =>
        router.get(
            baseUrl,
            { tab },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const openHazard = (
        id: number,
        opts?: { section?: HazardSectionKey; action?: HazardActionKey },
    ) => {
        setPendingSection(opts?.section ?? 'overview');
        setPendingAction(opts?.action ?? null);
        setIntent((n) => n + 1);
        router.get(
            baseUrl,
            { ...filters, hazard: id },
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );
    };
    const closeDetail = () =>
        router.get(
            baseUrl,
            { ...filters },
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );

    const hasFilters = !!(
        filters.q ||
        filters.site_id ||
        filters.site_type ||
        filters.severity ||
        filters.risk_rating ||
        filters.assignee_id ||
        filters.due_state ||
        filters.from
    );

    const activeRange = !filters.from
        ? 'all'
        : filters.from === daysAgoStr(7)
          ? 'week'
          : filters.from === daysAgoStr(30)
            ? '30d'
            : filters.from === daysAgoStr(90)
              ? 'quarter'
              : 'all';
    const onRange = (key: string) => {
        if (key === 'all') return go({ from: null, to: null });
        const map: Record<string, number> = { week: 7, '30d': 30, quarter: 90 };
        go({ from: daysAgoStr(map[key]), to: todayStr() });
    };

    const exportUrl =
        `${baseUrl}/export?` +
        new URLSearchParams(
            Object.entries({ ...filters, tab })
                .filter(([, v]) => v != null)
                .map(([k, v]) => [k, String(v)]),
        ).toString();

    const TABS: RosterTabItem[] = [
        {
            id: 'all',
            label: 'All',
            icon: ListChecks,
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
            id: 'in_progress',
            label: 'In progress',
            icon: Clock,
            tone: 'info',
            badge: tabCounts.in_progress || undefined,
        },
        {
            id: 'overdue',
            label: 'Overdue',
            icon: Flame,
            tone: 'critical',
            badge: tabCounts.overdue || undefined,
        },
        {
            id: 'critical',
            label: 'Critical',
            icon: ShieldAlert,
            tone: 'critical',
            badge: tabCounts.critical || undefined,
        },
        {
            id: 'closed',
            label: 'Closed',
            icon: CheckCircle2,
            tone: 'success',
            badge: tabCounts.closed || undefined,
        },
    ];

    /* ----- context menus ----- */

    const openRowCtx = (e: ReactMouseEvent, h: HazardRow) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [
            {
                icon: <ShieldAlert className="h-3.5 w-3.5" />,
                label: 'View hazard',
                sub: h.reference_number,
                tone: 'primary',
                onClick: () => openHazard(h.id),
            },
        ];
        if (can.assign && h.status !== 'closed') {
            items.push({
                icon: <UserPlus className="h-3.5 w-3.5" />,
                label: h.assigned_to_id ? 'Reassign' : 'Assign',
                onClick: () => openHazard(h.id, { action: 'assign' }),
            });
        }
        if (can.manage && h.status === 'open') {
            items.push({
                icon: <Play className="h-3.5 w-3.5" />,
                label: 'Start progress',
                sub: 'open → in progress',
                onClick: () => openHazard(h.id, { action: 'start' }),
            });
        }
        if (can.manage && h.status === 'in_progress') {
            items.push({
                icon: <ShieldCheck className="h-3.5 w-3.5" />,
                label: 'Mark mitigated',
                sub: 'in progress → mitigated',
                onClick: () => openHazard(h.id, { action: 'mitigate' }),
            });
        }
        if (can.manage && h.status !== 'closed') {
            items.push({
                icon: <ListChecks className="h-3.5 w-3.5" />,
                label: 'Add corrective action',
                onClick: () =>
                    openHazard(h.id, {
                        section: 'actions',
                        action: 'add_action',
                    }),
            });
            items.push({
                icon: <ClipboardCheck className="h-3.5 w-3.5" />,
                label: 'Record review',
                onClick: () => openHazard(h.id, { action: 'review' }),
            });
        }
        if (can.close && h.status !== 'closed') {
            items.push({
                icon: <CheckCircle2 className="h-3.5 w-3.5" />,
                label: 'Close hazard',
                tone: 'critical',
                onClick: () => openHazard(h.id, { action: 'close' }),
            });
        }
        items.push(
            { sep: true },
            {
                icon: <Copy className="h-3.5 w-3.5" />,
                label: 'Copy link',
                onClick: () => copyLink(h.id),
            },
            {
                icon: <ExternalLink className="h-3.5 w-3.5" />,
                label: 'Open full page',
                sub: `/hazards/${h.id}`,
                onClick: () => router.visit(`/hazards/${h.id}`),
            },
        );
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: (h.risk_rating ?? 'low').toUpperCase(),
            meta: `${h.reference_number} · ${hazardLabelOf(h)}`,
            items,
        });
    };

    const openHeroCtx = (e: ReactMouseEvent) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [];
        if (can.create)
            items.push({
                icon: <Plus className="h-3.5 w-3.5" />,
                label: 'Log hazard',
                tone: 'primary',
                onClick: () => setCreateOpen(true),
            });
        items.push({
            icon: <FileText className="h-3.5 w-3.5" />,
            label: 'Export CSV',
            onClick: () => window.open(exportUrl, '_blank'),
        });
        if (canGovernance)
            items.push({
                icon: <FileText className="h-3.5 w-3.5" />,
                label: 'Board reports',
                sub: 'governance pack',
                onClick: () => setBoardOpen(true),
            });
        if (!scopedSite) {
            items.push(
                { sep: true },
                {
                    icon: <Building2 className="h-3.5 w-3.5" />,
                    label: 'Go to sites',
                    onClick: () => router.visit('/sites'),
                },
            );
        }
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: 'REGISTER',
            tagBg: 'var(--primary)',
            tagColor: 'var(--primary-foreground)',
            meta: scopedSite
                ? `${scopedSite.name} hazards`
                : 'Homes & Sites Hazards',
            items,
        });
    };

    const copyLink = (id: number) => {
        const url = `${window.location.origin}${baseUrl}?hazard=${id}`;
        navigator.clipboard?.writeText(url);
    };

    /* ----- hero footer filter bar ----- */
    const footer = (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
            <HeroSegmented
                label="Period"
                variant="pill"
                ariaLabel="Date range"
                items={RANGE_ITEMS}
                value={activeRange}
                onChange={onRange}
            />
            {!scopedSite && sites.length ? (
                <EntityFilter
                    label="Site"
                    allLabel="All sites"
                    items={sites.map((s) => ({
                        id: s.id,
                        name: s.name,
                        description: siteTypeLabel(s.type),
                    }))}
                    value={filters.site_id}
                    onChange={(id) => go({ site_id: id })}
                    onDark
                />
            ) : null}
            {!scopedSite ? (
                <HeroSelect
                    label="Type"
                    value={filters.site_type}
                    onChange={(v) => go({ site_type: v })}
                >
                    <option value="">All types</option>
                    <option value="house">Houses</option>
                    <option value="facility">Facilities</option>
                    <option value="head_office">Head office</option>
                </HeroSelect>
            ) : null}
            <HeroSelect
                label="Severity"
                value={filters.severity}
                onChange={(v) => go({ severity: v })}
            >
                <option value="">All</option>
                {severityOptions.map((s) => (
                    <option key={s} value={s}>
                        {s[0].toUpperCase() + s.slice(1)}
                    </option>
                ))}
            </HeroSelect>
            <HeroSelect
                label="Risk"
                value={filters.risk_rating}
                onChange={(v) => go({ risk_rating: v })}
            >
                <option value="">All</option>
                {riskRatings.map((r) => (
                    <option key={r} value={r}>
                        {r[0].toUpperCase() + r.slice(1)}
                    </option>
                ))}
            </HeroSelect>
            <HeroSelect
                label="Assignee"
                value={filters.assignee_id}
                onChange={(v) => go({ assignee_id: v ? Number(v) : null })}
            >
                <option value="">All</option>
                {assignees.map((a) => (
                    <option key={a.id} value={a.id}>
                        {a.name}
                    </option>
                ))}
            </HeroSelect>
            <HeroSelect
                label="Due"
                value={filters.due_state}
                onChange={(v) => go({ due_state: v })}
            >
                <option value="">All</option>
                <option value="overdue">Overdue</option>
                <option value="due_soon">Due ≤ 7d</option>
            </HeroSelect>
            <div className="relative ml-auto">
                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                <input
                    type="search"
                    placeholder="Search hazards…"
                    defaultValue={filters.q ?? ''}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter')
                            go({
                                q: (e.target as HTMLInputElement).value || null,
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
                    <span aria-hidden>✕</span> Clear
                </button>
            ) : null}
        </div>
    );

    return (
        <>
            <div onContextMenu={openHeroCtx}>
                <HeroShell footer={footer}>
                    <WorkflowRibbon current="resolve" />
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-4">
                            <HeroMedallion icon={ShieldAlert} />
                            <div className="flex flex-col gap-1.5">
                                <div className="flex flex-wrap items-center gap-2">
                                    <HeroStatusPill>
                                        {scopedSite
                                            ? `${scopedSite.name} · hazard register`
                                            : 'Hazard register · live'}
                                    </HeroStatusPill>
                                    <span className="inline-flex items-center gap-1.5 rounded-full bg-primary-foreground/15 px-2.5 py-1 text-[11px] font-semibold tracking-[0.04em] text-primary-foreground/85 uppercase">
                                        <MapPin className="h-3.5 w-3.5" />{' '}
                                        {scopedSite
                                            ? siteTypeLabel(scopedSite.type)
                                            : 'NZ · Ngā Paerewa NZS 8134:2021'}
                                    </span>
                                </div>
                                <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">
                                    {scopedSite
                                        ? `${scopedSite.name} hazards`
                                        : 'Homes & Sites Hazards'}
                                </h1>
                                <p className="max-w-xl text-sm text-primary-foreground/70">
                                    {scopedSite
                                        ? `Every physical and environmental hazard at ${scopedSite.name}${scopedSite.suburb ? ` · ${scopedSite.suburb}` : ''} — risk-rated, driven through controls and closed through review.`
                                        : 'Every physical and environmental hazard across our homes and facilities — logged, risk-rated against the WorkSafe matrix, driven through controls and closed through review.'}
                                </p>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            {can.create ? (
                                <Button
                                    size="sm"
                                    className="border border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20"
                                    onClick={() => setCreateOpen(true)}
                                >
                                    <Plus className="mr-1.5 h-4 w-4" /> Log
                                    hazard
                                </Button>
                            ) : null}
                            {canGovernance ? (
                                <Popover
                                    open={boardOpen}
                                    onOpenChange={setBoardOpen}
                                >
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
                    </div>

                    <div className="grid gap-3 lg:grid-cols-2">
                        <HeroCluster
                            title="Live · open register"
                            icon={Activity}
                        >
                            <HeroClusterTile
                                href={`${baseUrl}?tab=open`}
                                label="Open"
                                value={fmt(hero.live.open)}
                                caption="awaiting triage"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href={`${baseUrl}?tab=in_progress`}
                                label="In progress"
                                value={fmt(hero.live.in_progress)}
                                caption="controls underway"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href={`${baseUrl}?tab=overdue`}
                                label="Overdue"
                                value={fmt(hero.live.overdue)}
                                caption={
                                    hero.live.overdue > 0
                                        ? 'past due date'
                                        : 'all on time'
                                }
                                tone="critical"
                            />
                            <HeroClusterTile
                                href={`${baseUrl}?tab=critical`}
                                label="Critical open"
                                value={fmt(hero.live.critical)}
                                caption="high / extreme risk"
                                tone="critical"
                            />
                        </HeroCluster>
                        <HeroCluster
                            title="Needs attention"
                            icon={AlertTriangle}
                        >
                            <HeroClusterTile
                                href={`${baseUrl}?tab=overdue`}
                                label="Due ≤ 7d"
                                value={fmt(hero.attention.due_soon)}
                                caption="closing window"
                                tone="warning"
                            />
                            <HeroClusterTile
                                href={`${baseUrl}?tab=open`}
                                label="Unassigned"
                                value={fmt(hero.attention.unassigned)}
                                caption={
                                    hero.attention.unassigned > 0
                                        ? 'needs an owner'
                                        : 'all owned'
                                }
                                tone="warning"
                            />
                            <HeroClusterTile
                                href={`${baseUrl}?tab=closed`}
                                label="Mitigated"
                                value={fmt(hero.attention.mitigated)}
                                caption="awaiting closure"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href={`${baseUrl}?tab=closed`}
                                label="Closed"
                                value={fmt(hero.attention.closed_period)}
                                caption="this period"
                                tone="success"
                            />
                        </HeroCluster>
                    </div>

                    <HeroComplianceBadges
                        worksafeAwaiting={nzBadges.worksafe_awaiting}
                        sdsExpiring={nzBadges.sds_expiring}
                        drillsDue={nzBadges.drills_due}
                        drillsOverdue={nzBadges.drills_overdue}
                        ngaPaerewaCertified={nzBadges.nga_paerewa_certified}
                        firstAidOk={nzBadges.first_aid_ok}
                    />
                </HeroShell>
            </div>

            <div className="mt-4">
                <TabStrip
                    value={tab}
                    items={TABS}
                    onChange={setTab}
                    ariaLabel="Hazard register views"
                />
            </div>

            <section className="mt-4 overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                <RegisterTableHeader
                    icon={ShieldAlert}
                    title={TABLE_TITLES[tab] ?? 'Hazards'}
                    subtitle={`${hazards.total} shown`}
                    hint="Right-click a row for the full lifecycle"
                    hintIcon={MousePointer2}
                />
                <HazardTable
                    rows={hazards.data}
                    scoped={!!scopedSite}
                    onOpen={openHazard}
                    onRowCtx={openRowCtx}
                />
                <div className="border-t border-border p-3">
                    <LaravelPagination
                        links={hazards.links}
                        lastPage={hazards.last_page}
                    />
                </div>
            </section>

            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}

            {detail ? (
                <HazardDetailDialog
                    key={detail.id}
                    detail={detail}
                    open
                    onClose={closeDetail}
                    initialSection={pendingSection}
                    initialAction={pendingAction}
                    intentKey={intent}
                    registerHref={baseUrl}
                />
            ) : null}

            {createOpen ? (
                <HazardCreateDialog
                    open
                    onClose={() => setCreateOpen(false)}
                    sites={sites}
                    fixedSite={
                        scopedSite
                            ? {
                                  id: scopedSite.id,
                                  name: scopedSite.name,
                                  type: scopedSite.type,
                              }
                            : null
                    }
                    recommendedBySiteType={recommendedBySiteType}
                    staff={assignees}
                    severityOptions={severityOptions}
                    likelihoodOptions={likelihoodOptions}
                    onSuccess={() => setCreateOpen(false)}
                />
            ) : null}
        </>
    );
}

function HeroSelect({
    label,
    value,
    onChange,
    children,
}: {
    label: string;
    value: string | number | null;
    onChange: (v: string | null) => void;
    children: ReactNode;
}) {
    return (
        <label className="flex items-center gap-1.5 text-[11px] font-medium text-primary-foreground/70">
            {label}
            <select
                value={value != null ? String(value) : ''}
                onChange={(e) => onChange(e.target.value || null)}
                className="rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-2 py-1.5 text-xs text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none [&>option]:text-foreground"
            >
                {children}
            </select>
        </label>
    );
}

function HazardTable({
    rows,
    scoped,
    onOpen,
    onRowCtx,
}: {
    rows: HazardRow[];
    scoped: boolean;
    onOpen: (id: number) => void;
    onRowCtx: (e: ReactMouseEvent, h: HazardRow) => void;
}) {
    if (!rows.length) {
        return (
            <div className="px-4 py-16 text-center">
                <ShieldAlert className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                <p className="font-medium text-muted-foreground">
                    No hazards here
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
                    <tr className="text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                        <th className="px-4 py-3">Ref / When</th>
                        <th className="px-4 py-3">Hazard</th>
                        {!scoped ? <th className="px-4 py-3">Site</th> : null}
                        <th className="px-4 py-3">Severity</th>
                        <th className="px-4 py-3">Risk</th>
                        <th className="px-4 py-3">Status</th>
                        <th className="px-4 py-3">Flags</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border">
                    {rows.map((h) => (
                        <HazardRowView
                            key={h.id}
                            h={h}
                            scoped={scoped}
                            onOpen={onOpen}
                            onRowCtx={onRowCtx}
                        />
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function HazardRowView({
    h,
    scoped,
    onOpen,
    onRowCtx,
}: {
    h: HazardRow;
    scoped: boolean;
    onOpen: (id: number) => void;
    onRowCtx: (e: ReactMouseEvent, h: HazardRow) => void;
}) {
    const when = fmtWhen(h.created_at);
    const riskTone = (
        h.risk_rating === 'extreme' || h.risk_rating === 'high'
            ? 'critical'
            : h.risk_rating === 'medium'
              ? 'warning'
              : 'success'
    ) as 'critical' | 'warning' | 'success';
    const SiteIcon = h.site_type === 'house' ? Home : Building2;
    return (
        <tr
            onClick={() => onOpen(h.id)}
            onContextMenu={(e) => onRowCtx(e, h)}
            tabIndex={0}
            aria-label={`Open hazard ${h.reference_number}`}
            onKeyDown={(e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    onOpen(h.id);
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
                    {h.reference_number}
                </div>
            </td>
            <td className="max-w-[300px] px-4 py-3 align-top">
                <div className="flex items-start gap-2">
                    <span
                        className={`mt-1 h-2 w-2 shrink-0 rounded-full ${TONE_DOT[riskTone]}`}
                    />
                    <span className="min-w-0">
                        <span className="block text-xs font-bold text-foreground">
                            {hazardLabelOf(h)}
                        </span>
                        <span className="mt-0.5 block truncate text-[11px] text-muted-foreground">
                            {h.description}
                        </span>
                    </span>
                </div>
            </td>
            {!scoped ? (
                <td className="px-4 py-3 align-top">
                    <div className="flex items-center gap-2">
                        <span className="grid h-7 w-7 shrink-0 place-items-center rounded-md bg-muted text-muted-foreground">
                            <SiteIcon className="h-3.5 w-3.5" />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-xs font-bold text-foreground">
                                {h.site_name}
                            </span>
                            <span className="block text-[11px] text-muted-foreground">
                                {siteTypeLabel(h.site_type)}
                            </span>
                        </span>
                    </div>
                </td>
            ) : null}
            <td className="px-4 py-3 align-top">
                <SeverityChip severity={h.severity} />
            </td>
            <td className="px-4 py-3 align-top">
                <RiskChip rating={h.risk_rating} suffix />
            </td>
            <td className="px-4 py-3 align-top">
                <StatusChip status={h.status} />
            </td>
            <td className="px-4 py-3 align-top">
                <div className="flex flex-wrap items-center gap-1.5">
                    {h.flags.overdue ? (
                        <FlagBadge
                            icon={Flame}
                            tone="critical"
                            title="Past due date"
                        >
                            Overdue
                        </FlagBadge>
                    ) : h.flags.due_soon ? (
                        <FlagBadge
                            icon={Clock}
                            tone="warning"
                            title="Due within 7 days"
                        >
                            Due ≤7d
                        </FlagBadge>
                    ) : null}
                    {h.flags.unassigned ? (
                        <FlagBadge
                            icon={UserPlus}
                            tone="warning"
                            title="No owner assigned"
                        >
                            Unassigned
                        </FlagBadge>
                    ) : null}
                    {h.worksafe && h.status !== 'closed' ? (
                        <FlagBadge
                            icon={ShieldAlert}
                            tone="critical"
                            title="WorkSafe-notifiable"
                        >
                            WorkSafe
                        </FlagBadge>
                    ) : null}
                    {h.flags.awaiting_closure ? (
                        <FlagBadge
                            icon={ShieldCheck}
                            tone="info"
                            title="Mitigated — awaiting closure"
                        >
                            Awaiting closure
                        </FlagBadge>
                    ) : null}
                    {h.open_action_count > 0 ? (
                        <FlagBadge
                            icon={ListChecks}
                            tone="info"
                            title="Open corrective actions"
                        >
                            {h.open_action_count} action
                        </FlagBadge>
                    ) : null}
                    {!h.flags.overdue &&
                    !h.flags.due_soon &&
                    !h.flags.unassigned &&
                    !(h.worksafe && h.status !== 'closed') &&
                    !h.flags.awaiting_closure &&
                    h.open_action_count === 0 ? (
                        <span className="text-xs text-muted-foreground">—</span>
                    ) : null}
                </div>
            </td>
        </tr>
    );
}
