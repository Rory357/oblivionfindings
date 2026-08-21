import {
    SubstanceDetailDialog,
    type ActionKey,
    type SubstanceDetail,
} from '@/components/health-safety/substance-detail-dialog';
import { SubstanceWizardDialog } from '@/components/health-safety/substance-wizard-dialog';
import {
    EntityFilter,
    ShiftContextMenu,
    TabStrip,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import AppLayout from '@/layouts/app-layout';
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
    TONE_BG,
    type Tone as RowTone,
} from '@/pages/health-safety/components/register-row-kit';
import { WorkflowRibbon } from '@/pages/health-safety/components/workflow-ribbon';
import {
    GHS_BY_CODE,
    SDS_STATE_META,
    STATUS_META,
    substanceRiskTone,
    type SdsState,
    type Tone,
} from '@/pages/health-safety/substances/constants';
import { type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Activity,
    Ban,
    Bell,
    CircleSlash,
    Eye,
    FileText,
    FlaskConical,
    HeartPulse,
    LayoutList,
    MapPin,
    Plus,
    Search,
    ShieldAlert,
    ShieldCheck,
    Upload,
    X,
} from 'lucide-react';
import { useState, type MouseEvent as ReactMouseEvent } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type SubstanceRow = {
    id: number;
    name: string;
    common_name: string | null;
    hsno_classification: string | null;
    hazard_classifications: string[];
    ghs_pictograms: string[];
    physical_form: string | null;
    is_controlled_substance: boolean;
    requires_tracking: boolean;
    sds_state: SdsState;
    storage_count: number;
    status: string;
    can: { create: boolean; manage: boolean };
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    last_page: number;
};

type Filters = {
    q: string;
    tab: string;
    site_id: number | null;
    physical_form: string | null;
    status: string | null;
    controlled: string | null;
    sds_state: string | null;
    period: number;
};

type Props = {
    filters: Filters;
    tab: string;
    tabCounts: Record<string, number>;
    rows: Paginated<SubstanceRow>;
    hero: {
        live: {
            active: number;
            controlled: number;
            sds_current: number;
            storage_locations: number;
        };
        attention: {
            sds_expiring: number;
            sds_missing: number;
            awaiting_review: number;
            exposures: number;
        };
    };
    badges: {
        worksafe_awaiting: number;
        sds_to_action: number;
    };
    sites: { id: number; name: string }[];
    can: { create: boolean; manage: boolean };
    openWizard: boolean;
    initialAction: ActionKey | null;
    detail: SubstanceDetail | null;
};

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

function titleCase(s: string | null | undefined): string {
    return (s ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

const TONE_SOFT: Record<Tone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    neutral: 'bg-muted text-muted-foreground',
};
const DOT: Record<Tone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    neutral: 'bg-muted-foreground',
};

function RowSdsBadge({ state }: { state: SdsState }) {
    const meta = SDS_STATE_META[state];
    return (
        <FlagBadge icon={meta.icon} tone={meta.tone} title={meta.label}>
            {meta.label}
        </FlagBadge>
    );
}

function GhsMini({ codes }: { codes: string[] }) {
    if (!codes.length)
        return <span className="text-xs text-muted-foreground/60">—</span>;
    return (
        <div className="flex flex-wrap items-center gap-1">
            {codes.slice(0, 4).map((code) => {
                const meta = GHS_BY_CODE[code];
                if (!meta) return null;
                const Icon = meta.icon;
                return (
                    <span
                        key={code}
                        title={meta.label}
                        className={`grid h-6 w-6 place-items-center rounded-md ${TONE_SOFT[meta.tone]}`}
                    >
                        <Icon className="h-3.5 w-3.5" />
                    </span>
                );
            })}
            {codes.length > 4 ? (
                <span className="text-[11px] text-muted-foreground">
                    +{codes.length - 4}
                </span>
            ) : null}
        </div>
    );
}

const PERIOD_TO_KEY = (days: number): string =>
    days <= 30 ? '30d' : days <= 90 ? 'quarter' : 'year';
const KEY_TO_PERIOD: Record<string, number> = {
    '30d': 30,
    quarter: 90,
    year: 365,
};

/** Governance board reports surfaced from the hero popover (gated on governance.view). */
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

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function SubstancesIndex({
    filters,
    tab,
    tabCounts,
    rows,
    hero,
    badges,
    sites,
    can,
    openWizard,
    initialAction,
    detail,
}: Props) {
    const assurance = useNzsAssurance();
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [wizardOpen, setWizardOpen] = useState(openWizard);
    const [editSubstance, setEditSubstance] = useState<SubstanceDetail | null>(
        null,
    );
    // Seed from a deep-link action (e.g. dashboard launcher → Add SDS) so the detail
    // opens straight on that pane; row clicks override it via openDetail.
    const [pendingOpen, setPendingOpen] = useState<{
        action?: ActionKey;
    } | null>(initialAction ? { action: initialAction } : null);
    const canViewBoardReports =
        usePage<SharedData>().props.auth.can?.governance?.view ?? false;

    const go = (next: Partial<Filters>) =>
        router.get(
            '/health-safety/substances',
            { ...filters, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    const setTab = (id: string) =>
        router.get(
            '/health-safety/substances',
            { ...filters, tab: id },
            { preserveScroll: true },
        );

    const openDetail = (id: number, opts?: { action?: ActionKey }) => {
        setPendingOpen(opts ?? null);
        router.get(
            '/health-safety/substances',
            { ...filters, substance: id },
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );
    };
    const closeDetail = () => {
        setPendingOpen(null);
        router.get(
            '/health-safety/substances',
            { ...filters },
            { preserveState: true, preserveScroll: true, only: ['detail'] },
        );
    };

    const startAdd = () => {
        setEditSubstance(null);
        setWizardOpen(true);
    };
    const startEdit = (d: SubstanceDetail) => {
        closeDetail();
        setEditSubstance(d);
        setWizardOpen(true);
    };

    const clearFilters = () =>
        router.get(
            '/health-safety/substances',
            { tab },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    const hasFilters = !!(
        filters.q ||
        filters.site_id ||
        filters.physical_form ||
        filters.controlled ||
        filters.sds_state
    );

    /* tabs */
    const TABS: RosterTabItem[] = [
        {
            id: 'all',
            label: 'All',
            icon: LayoutList,
            tone: 'primary',
            badge: tabCounts.all || undefined,
        },
        {
            id: 'active',
            label: 'Active',
            icon: ShieldCheck,
            tone: 'success',
            badge: tabCounts.active || undefined,
        },
        {
            id: 'controlled',
            label: 'Controlled',
            icon: ShieldAlert,
            tone: 'critical',
            badge: tabCounts.controlled || undefined,
        },
        {
            id: 'sds_expiring',
            label: 'SDS expiring',
            icon: FileText,
            tone: 'warning',
            badge: tabCounts.sds_expiring || undefined,
        },
        {
            id: 'sds_missing',
            label: 'SDS missing',
            icon: Ban,
            tone: 'critical',
            badge: tabCounts.sds_missing || undefined,
        },
        {
            id: 'inactive',
            label: 'Inactive',
            icon: CircleSlash,
            tone: 'info',
            badge: tabCounts.inactive || undefined,
        },
    ];

    /* hero footer filter controls */
    const PERIOD_ITEMS = [
        { key: '30d', label: '30 days' },
        { key: 'quarter', label: 'Quarter' },
        { key: 'year', label: 'Year' },
    ];
    const FORM_ITEMS = [
        { key: 'all', label: 'All forms' },
        { key: 'liquid', label: 'Liquid' },
        { key: 'solid', label: 'Solid' },
        { key: 'gas', label: 'Gas' },
        { key: 'powder', label: 'Powder' },
        { key: 'aerosol', label: 'Aerosol' },
    ];
    const SDS_ITEMS = [
        { key: 'all', label: 'All SDS' },
        { key: 'current', label: 'Current' },
        { key: 'expiring', label: 'Expiring' },
        { key: 'missing', label: 'Missing' },
    ];
    const CONTROLLED_ITEMS = [
        { key: 'all', label: 'All' },
        { key: 'controlled', label: 'Controlled' },
        { key: 'standard', label: 'Standard' },
    ];

    const complianceBadges: HeroComplianceBadge[] = [
        {
            icon: ShieldAlert,
            tone: badges.worksafe_awaiting > 0 ? 'warning' : 'success',
            label:
                badges.worksafe_awaiting > 0
                    ? `WorkSafe notifiable · ${badges.worksafe_awaiting} awaiting`
                    : 'WorkSafe · none pending',
        },
        ngaPaerewaBadge(assurance.certification_status),
        {
            icon: FlaskConical,
            tone: badges.sds_to_action > 0 ? 'critical' : 'success',
            label:
                badges.sds_to_action > 0
                    ? `Hazardous substances · ${badges.sds_to_action} SDS to action`
                    : 'Hazardous substances · SDS current',
        },
    ];

    /* right-click row menu */
    const openRowCtx = (e: ReactMouseEvent, s: SubstanceRow) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [
            {
                icon: <Eye className="h-3.5 w-3.5" />,
                label: 'View substance',
                sub: s.hsno_classification ?? titleCase(s.physical_form),
                tone: 'primary',
                onClick: () => openDetail(s.id),
            },
        ];
        if (s.can.create && s.status !== 'removed') {
            items.push(
                { sep: true },
                {
                    icon: <Upload className="h-3.5 w-3.5" />,
                    label: 'Add SDS',
                    onClick: () => openDetail(s.id, { action: 'add_sds' }),
                },
                {
                    icon: <MapPin className="h-3.5 w-3.5" />,
                    label: 'Add storage',
                    onClick: () => openDetail(s.id, { action: 'add_storage' }),
                },
                {
                    icon: <HeartPulse className="h-3.5 w-3.5" />,
                    label: 'Record exposure',
                    onClick: () =>
                        openDetail(s.id, { action: 'record_exposure' }),
                },
            );
        }
        if (s.can.manage && s.status === 'active') {
            items.push(
                { sep: true },
                {
                    icon: <CircleSlash className="h-3.5 w-3.5" />,
                    label: 'Mark inactive',
                    tone: 'critical',
                    onClick: () => openDetail(s.id, { action: 'deactivate' }),
                },
            );
        }
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: s.is_controlled_substance ? 'CONTROLLED' : 'HAZARDOUS',
            meta: s.name,
            items,
        });
    };

    /* right-click anywhere on the hero → quick actions */
    const openHeroCtx = (e: ReactMouseEvent) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [];
        if (can.create)
            items.push({
                icon: <Plus className="h-3.5 w-3.5" />,
                label: 'Add substance',
                tone: 'primary',
                onClick: startAdd,
            });
        items.push(
            {
                icon: <FileText className="h-3.5 w-3.5" />,
                label: 'SDS expiring',
                onClick: () => setTab('sds_expiring'),
            },
            {
                icon: <Ban className="h-3.5 w-3.5" />,
                label: 'SDS missing',
                onClick: () => setTab('sds_missing'),
            },
        );
        if (canViewBoardReports) {
            items.push(
                { sep: true },
                {
                    icon: <FileText className="h-3.5 w-3.5" />,
                    label: 'Board reports →',
                    onClick: () =>
                        router.visit('/health-safety/reports/board-summary'),
                },
            );
        }
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: 'CHEMICAL REGISTER',
            meta: 'Quick actions',
            items,
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                {
                    title: 'Chemical register',
                    href: '/health-safety/substances',
                },
            ]}
        >
            <Head title="Chemical register" />

            <div className="flex flex-col gap-6 p-6">
                {/* Right-click anywhere on the hero → quick actions */}
                <div onContextMenu={openHeroCtx}>
                    <HeroShell
                        footer={
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                                <HeroSegmented
                                    label="Period"
                                    variant="pill"
                                    ariaLabel="Period"
                                    items={PERIOD_ITEMS}
                                    value={PERIOD_TO_KEY(filters.period)}
                                    onChange={(k) =>
                                        go({ period: KEY_TO_PERIOD[k] ?? 90 })
                                    }
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
                                <HeroSegmented
                                    label="Form"
                                    variant="pill"
                                    ariaLabel="Physical form"
                                    items={FORM_ITEMS}
                                    value={filters.physical_form ?? 'all'}
                                    onChange={(k) =>
                                        go({
                                            physical_form:
                                                k === 'all' ? null : k,
                                        })
                                    }
                                />
                                <HeroSegmented
                                    label="SDS"
                                    variant="pill"
                                    ariaLabel="SDS state"
                                    items={SDS_ITEMS}
                                    value={filters.sds_state ?? 'all'}
                                    onChange={(k) =>
                                        go({
                                            sds_state: k === 'all' ? null : k,
                                        })
                                    }
                                />
                                <HeroSegmented
                                    label="Type"
                                    variant="pill"
                                    ariaLabel="Controlled"
                                    items={CONTROLLED_ITEMS}
                                    value={filters.controlled ?? 'all'}
                                    onChange={(k) =>
                                        go({
                                            controlled: k === 'all' ? null : k,
                                        })
                                    }
                                />
                                <div className="relative ml-auto">
                                    <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                    <input
                                        key={filters.q}
                                        type="search"
                                        placeholder="Search substances…"
                                        defaultValue={filters.q}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter')
                                                go({
                                                    q: (
                                                        e.target as HTMLInputElement
                                                    ).value,
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
                        <WorkflowRibbon current="resolve" />

                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex items-start gap-4">
                                <HeroMedallion icon={FlaskConical} />
                                <div className="flex flex-col gap-1.5">
                                    <HeroStatusPill>
                                        Chemical register · Hazardous Substances
                                        Regs 2017
                                    </HeroStatusPill>
                                    <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">
                                        Chemical register
                                    </h1>
                                    <p className="max-w-xl text-sm text-primary-foreground/70">
                                        Every hazardous substance the
                                        organisation holds — its HSNO
                                        classification, current Safety Data
                                        Sheet, where it is stored and any worker
                                        exposure — kept compliant in one
                                        register.
                                    </p>
                                </div>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {can.create ? (
                                    <Button
                                        size="sm"
                                        className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                                        onClick={startAdd}
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" /> Add
                                        substance
                                    </Button>
                                ) : null}
                                {canViewBoardReports ? (
                                    <Popover>
                                        <PopoverTrigger asChild>
                                            <Button
                                                size="sm"
                                                className="border border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20"
                                            >
                                                <FileText className="mr-1.5 h-4 w-4" />{' '}
                                                Board reports
                                                <span
                                                    aria-hidden
                                                    className="ml-1"
                                                >
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
                                                        router.visit(
                                                            report.href,
                                                        )
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
                                title="Live · chemical register"
                                icon={Activity}
                            >
                                <HeroClusterTile
                                    href="/health-safety/substances?tab=active"
                                    label="Active"
                                    value={fmt(hero.live.active)}
                                    caption="in use"
                                    tone="success"
                                />
                                <HeroClusterTile
                                    href="/health-safety/substances?tab=controlled"
                                    label="Controlled"
                                    value={fmt(hero.live.controlled)}
                                    caption="tracked"
                                    tone={
                                        hero.live.controlled > 0
                                            ? 'warning'
                                            : 'neutral'
                                    }
                                />
                                <HeroClusterTile
                                    href="/health-safety/substances?tab=all"
                                    label="SDS current"
                                    value={fmt(hero.live.sds_current)}
                                    caption="up to date"
                                    tone="neutral"
                                />
                                <HeroClusterTile
                                    label="Storage"
                                    value={fmt(hero.live.storage_locations)}
                                    caption="locations"
                                    tone="neutral"
                                />
                            </HeroCluster>
                            <HeroCluster title="Needs attention" icon={Bell}>
                                <HeroClusterTile
                                    href="/health-safety/substances?tab=sds_expiring"
                                    label="SDS expiring"
                                    value={fmt(hero.attention.sds_expiring)}
                                    caption="≤30 days"
                                    tone={
                                        hero.attention.sds_expiring > 0
                                            ? 'critical'
                                            : 'success'
                                    }
                                />
                                <HeroClusterTile
                                    href="/health-safety/substances?tab=sds_missing"
                                    label="SDS missing"
                                    value={fmt(hero.attention.sds_missing)}
                                    caption="none on file"
                                    tone={
                                        hero.attention.sds_missing > 0
                                            ? 'critical'
                                            : 'success'
                                    }
                                />
                                <HeroClusterTile
                                    label="Awaiting review"
                                    value={fmt(hero.attention.awaiting_review)}
                                    caption="exposure follow-up"
                                    tone={
                                        hero.attention.awaiting_review > 0
                                            ? 'warning'
                                            : 'success'
                                    }
                                />
                                <HeroClusterTile
                                    label="Exposures"
                                    value={fmt(hero.attention.exposures)}
                                    caption="this period"
                                    tone={
                                        hero.attention.exposures > 0
                                            ? 'warning'
                                            : 'neutral'
                                    }
                                />
                            </HeroCluster>
                        </div>

                        <HeroComplianceBadges items={complianceBadges} />
                    </HeroShell>
                </div>

                <TabStrip
                    value={tab}
                    onChange={setTab}
                    items={TABS}
                    ariaLabel="Chemical register views"
                />

                <Card>
                    <CardContent className="p-0">
                        <SubstanceTable
                            rows={rows.data}
                            onRowCtx={openRowCtx}
                            onOpen={(id) => openDetail(id)}
                        />
                    </CardContent>
                </Card>

                {rows.last_page > 1 ? (
                    <LaravelPagination links={rows.links} />
                ) : null}
            </div>

            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}

            {detail ? (
                <SubstanceDetailDialog
                    key={detail.id}
                    detail={detail}
                    sites={sites}
                    open
                    onClose={closeDetail}
                    onEdit={startEdit}
                    initialAction={pendingOpen?.action ?? null}
                    initialSection="overview"
                />
            ) : null}

            {wizardOpen ? (
                <SubstanceWizardDialog
                    open
                    editSubstance={editSubstance}
                    onClose={() => {
                        setWizardOpen(false);
                        setEditSubstance(null);
                    }}
                    onOpenSubstance={(id, opts) => {
                        setWizardOpen(false);
                        setEditSubstance(null);
                        openDetail(id, opts);
                    }}
                />
            ) : null}
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Register table                                                     */
/* ------------------------------------------------------------------ */

function SubstanceTable({
    rows,
    onRowCtx,
    onOpen,
}: {
    rows: SubstanceRow[];
    onRowCtx: (e: ReactMouseEvent, s: SubstanceRow) => void;
    onOpen: (id: number) => void;
}) {
    if (!rows.length) {
        return (
            <div className="px-4 py-16 text-center">
                <FlaskConical className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                <p className="font-medium text-muted-foreground">
                    No substances here
                </p>
                <p className="mt-1 text-sm text-muted-foreground/70">
                    Nothing matches this tab and filters.
                </p>
            </div>
        );
    }
    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                        <th className="px-4 py-2.5">Substance</th>
                        <th className="px-4 py-2.5">HSNO / classification</th>
                        <th className="px-4 py-2.5">Form</th>
                        <th className="px-4 py-2.5">Hazard pictograms</th>
                        <th className="px-4 py-2.5">SDS</th>
                        <th className="px-4 py-2.5">Storage</th>
                        <th className="px-4 py-2.5">Status</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((s) => {
                        const risk = substanceRiskTone(
                            s.is_controlled_substance,
                            s.sds_state,
                        );
                        const status = STATUS_META[s.status] ?? {
                            label: titleCase(s.status),
                            tone: 'neutral' as Tone,
                        };
                        return (
                            <tr
                                key={s.id}
                                tabIndex={0}
                                onClick={() => onOpen(s.id)}
                                onContextMenu={(e) => onRowCtx(e, s)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        e.preventDefault();
                                        onOpen(s.id);
                                    }
                                }}
                                className="cursor-pointer transition-colors hover:bg-muted/45 focus-visible:bg-muted/45 focus-visible:outline-none"
                            >
                                <td className="px-4 py-3 align-top">
                                    <div className="flex items-start gap-2">
                                        <span
                                            className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${DOT[risk]}`}
                                        />
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-1.5">
                                                <span className="font-medium">
                                                    {s.name}
                                                </span>
                                                {s.is_controlled_substance ? (
                                                    <ShieldAlert
                                                        className="h-3.5 w-3.5 shrink-0 text-status-critical"
                                                        aria-label="Controlled substance"
                                                    />
                                                ) : null}
                                            </div>
                                            {s.common_name ? (
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {s.common_name}
                                                </p>
                                            ) : null}
                                        </div>
                                    </div>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    {s.hsno_classification ? (
                                        <span className="text-sm">
                                            {s.hsno_classification}
                                        </span>
                                    ) : (
                                        <span className="text-xs text-muted-foreground/60">
                                            —
                                        </span>
                                    )}
                                    {s.hazard_classifications.length ? (
                                        <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                                            {s.hazard_classifications.join(
                                                ', ',
                                            )}
                                        </p>
                                    ) : null}
                                </td>
                                <td className="px-4 py-3 align-top text-sm whitespace-nowrap">
                                    {titleCase(s.physical_form) || '—'}
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <GhsMini codes={s.ghs_pictograms} />
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <RowSdsBadge state={s.sds_state} />
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                        <MapPin className="h-3.5 w-3.5" />
                                        {s.storage_count}
                                    </span>
                                </td>
                                <td className="px-4 py-3 align-top">
                                    <span
                                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${TONE_BG[status.tone as RowTone]}`}
                                    >
                                        {status.label}
                                    </span>
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}
