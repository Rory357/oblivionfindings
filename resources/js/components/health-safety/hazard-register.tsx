/* Shared global and per-Site hazard worklist. Domain commands stay source-owned. */
import { HazardCreateDialog } from '@/components/health-safety/hazard-create-dialog';
import {
    RiskChip,
    SeverityChip,
    StatusChip,
    hazardLabelOf,
    siteTypeLabel,
    type HazardActionKey,
    type HazardCan,
    type HazardDetail,
    type HazardRow,
    type HazardSectionKey,
} from '@/components/health-safety/hazard-kit';
import {
    SafetyDateFilter,
    SafetyRegisterSearch,
    SafetyWorkspaceRail,
} from '@/components/health-safety/workspace-header';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page';
import {
    EntityFilter,
    ShiftContextMenu,
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
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import {
    hazardHref,
    hazardReturn,
} from '@/lib/health-safety/hazard-navigation';
import { fmt } from '@/pages/health-safety/components/hs-hero-kit';
import {
    FlagBadge,
    RegisterTableHeader,
    TONE_DOT,
} from '@/pages/health-safety/components/register-row-kit';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import {
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
    MoreVertical,
    MousePointer2,
    Play,
    Plus,
    ShieldAlert,
    ShieldCheck,
    UserPlus,
} from 'lucide-react';
import { useEffect, useState, type MouseEvent as ReactMouseEvent } from 'react';

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
        href: '/health-safety/reports?view=board',
    },
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

const TABLE_TITLES: Record<string, string> = {
    all: 'All hazards',
    open: 'Open hazards',
    in_progress: 'In progress',
    overdue: 'Overdue hazards',
    critical: 'Critical open',
    closed: 'Closed & mitigated',
    due_soon: 'Due in the next 7 days',
    unassigned: 'Hazards needing an owner',
    mitigated: 'Awaiting closure',
    closed_only: 'Closed hazards',
};

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

        filters,
        sites,
        assignees,
        can,
        severityOptions,
        likelihoodOptions,
        riskRatings,
        recommendedBySiteType,
    } = data;

    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [boardOpen, setBoardOpen] = useState(false);
    const canBrowseSites =
        !!usePage<SharedData>().props.auth.can?.sites?.viewAny;
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
        const query = new URLSearchParams(
            Object.entries({ ...filters, tab })
                .filter(([, value]) => value != null && value !== '')
                .map(([key, value]) => [key, String(value)]),
        );
        router.visit(
            hazardHref(id, {
                section: opts?.section,
                pane: opts?.action ? { kind: opts.action } : null,
                returnTo: hazardReturn(baseUrl + '?' + query),
            }),
        );
    };

    const hasFilters = !!(
        filters.q ||
        filters.site_id ||
        filters.site_type ||
        filters.severity ||
        filters.risk_rating ||
        filters.assignee_id ||
        filters.due_state ||
        filters.from ||
        filters.to
    );

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
            id: 'active',
            label: 'Open work',
            icon: AlertTriangle,
            tone: 'info',
            badge: tabCounts.active || undefined,
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
            label: 'Closed & mitigated',
            icon: CheckCircle2,
            tone: 'success',
            badge: tabCounts.closed || undefined,
        },
    ];

    /* ----- context menus ----- */

    const openRowCtx = (e: ReactMouseEvent, h: HazardRow) => {
        e.preventDefault();
        e.stopPropagation();
        const anchor = e.currentTarget.getBoundingClientRect();
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
                onClick: () => openHazard(h.id),
            },
        );
        setCtx({
            x: e.clientX || anchor.left,
            y: e.clientY || anchor.bottom,
            tag: (h.risk_rating ?? 'unknown').toUpperCase(),
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
        if (!scopedSite && canBrowseSites) {
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
        const url = `${window.location.origin}${hazardHref(id)}`;
        navigator.clipboard?.writeText(url);
    };

    const viewOptions = [
        ...TABS,
        { id: 'due_soon', label: 'Due in 7 days' },
        { id: 'unassigned', label: 'Needs an owner' },
        { id: 'mitigated', label: 'Awaiting closure' },
        { id: 'closed_only', label: 'Closed only' },
    ];
    const reportSite = scopedSite?.id ?? filters.site_id;
    const meters = [
        {
            key: 'open',
            label: 'Open',
            value: hero.live.open,
            caption: 'Awaiting progress',
        },
        {
            key: 'in_progress',
            label: 'In progress',
            value: hero.live.in_progress,
            caption: 'Controls underway',
        },
        {
            key: 'overdue',
            label: 'Overdue',
            value: hero.live.overdue,
            caption: 'Past the due date',
        },
        {
            key: 'critical',
            label: 'Critical open',
            value: hero.live.critical,
            caption: 'Critical or extreme',
        },
        {
            key: 'due_soon',
            label: 'Due in 7 days',
            value: hero.attention.due_soon,
            caption: 'Today through 7 days',
        },
        {
            key: 'unassigned',
            label: 'Needs an owner',
            value: hero.attention.unassigned,
            caption: 'Open work to assign',
        },
    ];

    return (
        <>
            <div onContextMenu={openHeroCtx}>
                <PageHeader
                    frontline
                    wrapTitle
                    icon={ShieldAlert}
                    title={
                        scopedSite ? `${scopedSite.name} hazards` : 'Hazards'
                    }
                    subline="Identify hazards, name an owner and keep controls current"
                    mobileSummary={`${hero.live.overdue} overdue · ${hero.attention.unassigned} need an owner`}
                    actions={
                        <>
                            <SafetyRegisterSearch
                                key={filters.q}
                                query={filters.q ?? ''}
                                onSearch={(q) => go({ q })}
                                placeholder="Search hazards"
                            />
                            {can.create && (
                                <PageHeaderGlassButton
                                    icon={Plus}
                                    onClick={() => setCreateOpen(true)}
                                >
                                    Log hazard
                                </PageHeaderGlassButton>
                            )}
                            <PageHeaderGlassButton icon={FileText} asChild>
                                <a href={exportUrl}>Export CSV</a>
                            </PageHeaderGlassButton>
                            {canGovernance && (
                                <Popover
                                    open={boardOpen}
                                    onOpenChange={setBoardOpen}
                                >
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
                                                    router.visit(
                                                        report.href +
                                                            (reportSite
                                                                ? `&site_id=${reportSite}`
                                                                : ''),
                                                    )
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
                    meters={
                        <div className="grid w-full grid-cols-2 gap-px lg:grid-cols-3 xl:grid-cols-6">
                            {meters.map((meter) => (
                                <PageHeaderMeterBlock
                                    key={meter.key}
                                    label={meter.label}
                                    pressed={tab === meter.key}
                                    onClick={() => setTab(meter.key)}
                                    tone={
                                        meter.value > 0 &&
                                        ['overdue', 'critical'].includes(
                                            meter.key,
                                        )
                                            ? 'critical'
                                            : 'brand'
                                    }
                                >
                                    <PageHeaderMeterBig>
                                        {fmt(meter.value)}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {meter.caption}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ))}
                        </div>
                    }
                    filters={
                        <>
                            <PageHeaderFilterSelect
                                label="Hazard view"
                                value={tab}
                                options={viewOptions.map((item) => ({
                                    value: item.id,
                                    label: `${item.label} (${tabCounts[item.id] ?? 0})`,
                                }))}
                                onChange={setTab}
                            />
                            <SafetyDateFilter
                                label="Logged"
                                from={filters.from ?? null}
                                to={filters.to ?? null}
                                onChange={go}
                            />
                            {!scopedSite && sites.length > 0 && (
                                <EntityFilter
                                    label="Site"
                                    allLabel="All approved sites"
                                    items={sites.map((site) => ({
                                        id: site.id,
                                        name: site.name,
                                        description: siteTypeLabel(site.type),
                                    }))}
                                    value={filters.site_id}
                                    onChange={(id) => go({ site_id: id })}
                                    onDark
                                />
                            )}
                            {!scopedSite && (
                                <PageHeaderFilterSelect
                                    label="Site type"
                                    value={filters.site_type ?? ''}
                                    onChange={(value) =>
                                        go({ site_type: value || null })
                                    }
                                    options={[
                                        { value: '', label: 'All types' },
                                        { value: 'house', label: 'Houses' },
                                        {
                                            value: 'facility',
                                            label: 'Facilities',
                                        },
                                        {
                                            value: 'head_office',
                                            label: 'Head office',
                                        },
                                    ]}
                                />
                            )}
                            <PageHeaderFilterSelect
                                label="Severity"
                                value={filters.severity ?? ''}
                                onChange={(value) =>
                                    go({ severity: value || null })
                                }
                                options={[
                                    { value: '', label: 'All severities' },
                                    ...severityOptions.map((value) => ({
                                        value,
                                        label:
                                            value[0].toUpperCase() +
                                            value.slice(1),
                                    })),
                                ]}
                            />
                            <PageHeaderFilterSelect
                                label="Risk"
                                value={filters.risk_rating ?? ''}
                                onChange={(value) =>
                                    go({ risk_rating: value || null })
                                }
                                options={[
                                    { value: '', label: 'All risk ratings' },
                                    ...riskRatings.map((value) => ({
                                        value,
                                        label:
                                            value[0].toUpperCase() +
                                            value.slice(1),
                                    })),
                                ]}
                            />
                            <PageHeaderFilterSelect
                                label="Owner"
                                value={
                                    filters.assignee_id
                                        ? String(filters.assignee_id)
                                        : ''
                                }
                                onChange={(value) =>
                                    go({
                                        assignee_id: value
                                            ? Number(value)
                                            : null,
                                    })
                                }
                                options={[
                                    { value: '', label: 'All owners' },
                                    ...assignees.map((user) => ({
                                        value: String(user.id),
                                        label: user.name,
                                    })),
                                ]}
                            />
                            <PageHeaderFilterSelect
                                label="Due"
                                value={filters.due_state ?? ''}
                                onChange={(value) =>
                                    go({ due_state: value || null })
                                }
                                options={[
                                    { value: '', label: 'Any due date' },
                                    { value: 'overdue', label: 'Overdue' },
                                    {
                                        value: 'due_soon',
                                        label: 'Due in 7 days',
                                    },
                                ]}
                            />
                            {hasFilters && (
                                <PageHeaderGlassButton onClick={clearFilters}>
                                    Clear filters
                                </PageHeaderGlassButton>
                            )}
                        </>
                    }
                    rail={
                        <SafetyWorkspaceRail
                            hub="prevention"
                            current="hazards"
                        />
                    }
                />
            </div>
            <section className="min-w-0 overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                <RegisterTableHeader
                    icon={ShieldAlert}
                    title={TABLE_TITLES[tab] ?? 'Hazards'}
                    subtitle={`${hazards.total} shown`}
                    hint="Open a hazard or use its options"
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

export function HazardTable({
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
        <>
            <ul
                aria-label="Hazards"
                className="divide-y divide-border md:hidden"
            >
                {rows.map((hazard) => (
                    <li
                        key={hazard.id}
                        className="min-w-0 p-4"
                        onContextMenu={(event) => onRowCtx(event, hazard)}
                    >
                        <article className="min-w-0">
                            <div className="flex min-w-0 items-start gap-2">
                                <Button
                                    unstyled
                                    className="frontline-tap frontline-focus min-w-0 flex-1 text-left [overflow-wrap:anywhere]"
                                    aria-label={`Open hazard ${hazard.reference_number}`}
                                    onClick={() => onOpen(hazard.id)}
                                >
                                    <span className="block text-xs font-semibold text-primary">
                                        {hazard.reference_number}
                                    </span>
                                    <span className="block text-sm font-semibold">
                                        {hazardLabelOf(hazard)}
                                    </span>
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="frontline-tap frontline-focus shrink-0"
                                    aria-label={`Options for ${hazard.reference_number}`}
                                    onClick={(event) => onRowCtx(event, hazard)}
                                >
                                    <MoreVertical className="h-4 w-4" />
                                </Button>
                            </div>
                            <p className="mt-2 text-sm [overflow-wrap:anywhere]">
                                {hazard.description}
                            </p>
                            <dl className="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 text-xs">
                                {!scoped && (
                                    <div className="col-span-2 min-w-0">
                                        <dt className="text-muted-foreground">
                                            Site
                                        </dt>
                                        <dd className="mt-1 [overflow-wrap:anywhere]">
                                            {hazard.site_name ??
                                                'Site not recorded'}
                                        </dd>
                                    </div>
                                )}
                                <div className="min-w-0">
                                    <dt className="text-muted-foreground">
                                        Owner
                                    </dt>
                                    <dd className="mt-1 [overflow-wrap:anywhere]">
                                        {hazard.assigned_to_name ??
                                            'Needs an owner'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Due
                                    </dt>
                                    <dd className="mt-1">
                                        {hazard.due_date
                                            ? formatDateOnly(hazard.due_date)
                                            : 'No due date'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Severity
                                    </dt>
                                    <dd className="mt-1">
                                        <SeverityChip
                                            severity={hazard.severity}
                                        />
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Risk
                                    </dt>
                                    <dd className="mt-1">
                                        <RiskChip rating={hazard.risk_rating} />
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Stage
                                    </dt>
                                    <dd className="mt-1">
                                        <StatusChip status={hazard.status} />
                                    </dd>
                                </div>
                                <div>
                                    <dt className="text-muted-foreground">
                                        Logged
                                    </dt>
                                    <dd className="mt-1">
                                        {formatDateTime(hazard.created_at)}
                                    </dd>
                                </div>
                            </dl>
                            <div className="mt-3">
                                <HazardFlags h={hazard} />
                            </div>
                        </article>
                    </li>
                ))}
            </ul>
            <div
                role="region"
                aria-label="Hazards table"
                tabIndex={0}
                className="relative hidden max-w-full overflow-x-auto overscroll-x-contain focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring md:block"
            >
                <table className="w-full min-w-[1040px] text-sm">
                    <thead className="bg-muted/70">
                        <tr className="text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                            <th className="px-4 py-3">Ref / When</th>
                            <th className="px-4 py-3">Hazard</th>
                            {!scoped ? (
                                <th className="px-4 py-3">Site</th>
                            ) : null}
                            <th className="px-4 py-3">Severity</th>
                            <th className="px-4 py-3">Risk</th>
                            <th className="px-4 py-3">Status</th>
                            <th className="px-4 py-3">Flags</th>
                            <th className="sticky right-0 bg-muted px-3 py-3">
                                <span className="sr-only">Options</span>
                            </th>
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
        </>
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
    const when = {
        main: formatDateTime(h.created_at),
        title: formatDateTime(h.created_at),
    };
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
                if (
                    e.target === e.currentTarget &&
                    (e.key === 'Enter' || e.key === ' ')
                ) {
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
                <div className="mt-1 text-xs text-muted-foreground">
                    Due: {h.due_date ? formatDateOnly(h.due_date) : 'Not set'}
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
                        <span className="mt-1 block text-xs text-muted-foreground">
                            Owner: {h.assigned_to_name ?? 'Needs an owner'}
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
                <HazardFlags h={h} />
            </td>
            <td className="sticky right-0 bg-card px-3 py-3 align-top">
                <Button
                    variant="ghost"
                    size="icon"
                    className="frontline-hit shrink-0"
                    aria-label={`Options for ${h.reference_number}`}
                    onClick={(event) => {
                        event.stopPropagation();
                        onRowCtx(event, h);
                    }}
                >
                    <MoreVertical className="h-4 w-4" />
                </Button>
            </td>
        </tr>
    );
}

function HazardFlags({ h }: { h: HazardRow }) {
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            {h.flags.overdue ? (
                <FlagBadge icon={Flame} tone="critical" title="Past due date">
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
                    title="Extreme risk rating or critical severity; notification needs a separate decision"
                >
                    Critical rating
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
    );
}
