/**
 * Hazards (approved H&S drawing pg-hsn-60 — Stephan 23 Oct 2026): one header
 * with two number boxes (due in 7 days, needs an owner) and four filter chips,
 * the hazard views as a tier-two strip, then the register table. Shared by the
 * global register, the per-Site register and the Site profile link-through;
 * domain commands stay on the hazard page (rows open it, menus deep-link a pane).
 */
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
    RecordIdentity,
    RecordTable,
    type RecordTableColumn,
} from '@/components/incidents/record-table';
import {
    EntityContextMenu,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
} from '@/components/page';
import {
    TierTwoTabs,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import {
    PageHeaderNumber,
    PageHeaderNumbers,
} from '@/components/page/page-header-strip';
import { EntityFilter } from '@/components/rostering';
import { Ring } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { SelectInput } from '@/components/wizard/primitives';
import { formatDateOnly, formatTime, toDateInput } from '@/lib/datetime';
import {
    hazardHref,
    hazardReturn,
} from '@/lib/health-safety/hazard-navigation';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CheckCircle2,
    CircleDot,
    ClipboardCheck,
    Copy,
    ExternalLink,
    FileText,
    Flame,
    Home,
    LayoutList,
    ListChecks,
    Play,
    Plus,
    ShieldAlert,
    ShieldCheck,
    SlidersHorizontal,
    UserPlus,
    X,
} from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';

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

/**
 * The register's views. The five drawn ones are the tier-two strip ("Open"
 * is the code's open work: open and in progress); the rest stay reachable
 * from More filters and show as a tab while they are the chosen view.
 */
const VIEWS: {
    key: string;
    tab: string;
    option: string;
    title: string;
    icon: GroupedProfileNavTab['icon'];
    drawn: boolean;
}[] = [
    { key: 'all', tab: 'All', option: 'All', title: 'All hazards', icon: LayoutList, drawn: true },
    { key: 'active', tab: 'Open', option: 'Open work', title: 'Open hazards', icon: AlertTriangle, drawn: true },
    { key: 'overdue', tab: 'Overdue', option: 'Overdue', title: 'Overdue hazards', icon: Flame, drawn: true },
    { key: 'critical', tab: 'Critical', option: 'Critical', title: 'Critical open', icon: ShieldAlert, drawn: true },
    { key: 'closed', tab: 'Closed & mitigated', option: 'Closed & mitigated', title: 'Closed & mitigated', icon: CheckCircle2, drawn: true },
    { key: 'open', tab: 'Not started', option: 'Open', title: 'Open, not started', icon: CircleDot, drawn: false },
    { key: 'in_progress', tab: 'In progress', option: 'In progress', title: 'In progress', icon: Play, drawn: false },
    { key: 'due_soon', tab: 'Due in 7 days', option: 'Due in 7 days', title: 'Due in the next 7 days', icon: AlertTriangle, drawn: false },
    { key: 'unassigned', tab: 'Needs an owner', option: 'Needs an owner', title: 'Hazards needing an owner', icon: UserPlus, drawn: false },
    { key: 'mitigated', tab: 'Awaiting closure', option: 'Awaiting closure', title: 'Awaiting closure', icon: ShieldCheck, drawn: false },
    { key: 'closed_only', tab: 'Closed only', option: 'Closed only', title: 'Closed hazards', icon: CheckCircle2, drawn: false },
];

function sentence(value: string): string {
    return value ? value[0].toUpperCase() + value.slice(1) : value;
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
        filters,
        sites,
        assignees,
        can,
        severityOptions,
        likelihoodOptions,
        riskRatings,
        recommendedBySiteType,
    } = data;

    const headerMenu = useEntityContextMenu<'register'>();
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

    const moreActive = [
        filters.severity,
        filters.risk_rating,
        filters.assignee_id,
        filters.due_state,
    ].filter(Boolean).length;
    const hasFilters = !!(
        filters.q ||
        filters.site_id ||
        filters.site_type ||
        filters.from ||
        filters.to ||
        moreActive
    );

    const exportUrl =
        `${baseUrl}/export?` +
        new URLSearchParams(
            Object.entries({ ...filters, tab })
                .filter(([, v]) => v != null)
                .map(([k, v]) => [k, String(v)]),
        ).toString();

    const copyLink = (id: number) => {
        const url = `${window.location.origin}${hazardHref(id)}`;
        navigator.clipboard?.writeText(url);
    };

    /* ----- one action list per row: the kebab and the right-click ----- */

    const hazardActions = (h: HazardRow): MenuItem[] =>
        compactMenu([
            {
                label: 'View hazard',
                icon: ShieldAlert,
                onClick: () => openHazard(h.id),
            },
            can.assign &&
                h.status !== 'closed' && {
                    label: h.assigned_to_id ? 'Reassign' : 'Assign',
                    icon: UserPlus,
                    onClick: () => openHazard(h.id, { action: 'assign' }),
                },
            can.manage &&
                h.status === 'open' && {
                    label: 'Start progress',
                    icon: Play,
                    onClick: () => openHazard(h.id, { action: 'start' }),
                },
            can.manage &&
                h.status === 'in_progress' && {
                    label: 'Mark mitigated',
                    icon: ShieldCheck,
                    onClick: () => openHazard(h.id, { action: 'mitigate' }),
                },
            can.manage &&
                h.status !== 'closed' && {
                    label: 'Add corrective action',
                    icon: ListChecks,
                    onClick: () =>
                        openHazard(h.id, {
                            section: 'actions',
                            action: 'add_action',
                        }),
                },
            can.manage &&
                h.status !== 'closed' && {
                    label: 'Record review',
                    icon: ClipboardCheck,
                    onClick: () => openHazard(h.id, { action: 'review' }),
                },
            can.close &&
                h.status !== 'closed' && {
                    label: 'Close hazard',
                    icon: CheckCircle2,
                    danger: true,
                    onClick: () => openHazard(h.id, { action: 'close' }),
                },
            { separator: true },
            {
                label: 'Copy link',
                icon: Copy,
                onClick: () => copyLink(h.id),
            },
            {
                label: 'Open full page',
                icon: ExternalLink,
                onClick: () => openHazard(h.id),
            },
        ]);

    const registerActions: MenuItem[] = compactMenu([
        can.create && {
            label: 'Log hazard',
            icon: Plus,
            onClick: () => setCreateOpen(true),
        },
        {
            label: 'Export CSV',
            icon: FileText,
            onClick: () => window.open(exportUrl, '_blank'),
        },
        canGovernance && {
            label: 'Board reports',
            icon: FileText,
            onClick: () => setBoardOpen(true),
        },
        !scopedSite && canBrowseSites && { separator: true },
        !scopedSite &&
            canBrowseSites && {
                label: 'Go to sites',
                icon: Building2,
                onClick: () => router.visit('/sites'),
            },
    ]);

    const reportSite = scopedSite?.id ?? filters.site_id;
    const views: GroupedProfileNavTab[] = VIEWS.filter(
        (view) => view.drawn || view.key === tab,
    ).map((view) => ({
        key: view.key,
        label: view.tab,
        icon: view.icon,
        count: tabCounts[view.key],
    }));
    const current = VIEWS.find((view) => view.key === tab);
    const range =
        hazards.from && hazards.to
            ? `Showing ${hazards.from}–${hazards.to} of ${hazards.total}`
            : `Showing ${hazards.data.length} of ${hazards.total}`;
    const pager = (
        <>
            <p className="text-subtle">
                Showing {hazards.data.length} of {hazards.total}
            </p>
            {hazards.last_page > 1 ? (
                <LaravelPagination
                    links={hazards.links}
                    lastPage={hazards.last_page}
                />
            ) : null}
        </>
    );

    return (
        <>
            <div onContextMenu={(event) => headerMenu.open(event, 'register')}>
                <PageHeader
                    wrapTitle
                    icon={ShieldAlert}
                    title={
                        scopedSite ? `${scopedSite.name} hazards` : 'Hazards'
                    }
                    subline="Find hazards, give each one an owner and keep controls up to date"
                    actions={
                        <>
                            <SafetyRegisterSearch
                                key={filters.q}
                                query={filters.q ?? ''}
                                onSearch={(q) => go({ q })}
                                placeholder="Search hazards"
                            />
                            <PageHeaderGlassButton asChild>
                                <a href={exportUrl} aria-label="Export CSV">
                                    Export
                                </a>
                            </PageHeaderGlassButton>
                            {canGovernance && (
                                <Popover
                                    open={boardOpen}
                                    onOpenChange={setBoardOpen}
                                >
                                    <PopoverTrigger asChild>
                                        <PageHeaderGlassButton>
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
                                                className="frontline-focus flex w-full items-center rounded-md p-2.5 text-left text-sm hover:bg-muted"
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
                            {can.create && (
                                <PageHeaderPrimaryButton
                                    icon={Plus}
                                    onClick={() => setCreateOpen(true)}
                                >
                                    Log hazard
                                </PageHeaderPrimaryButton>
                            )}
                        </>
                    }
                    strip={
                        <PageHeaderNumbers>
                            <PageHeaderNumber
                                label="Due in 7 days"
                                value={hero.attention.due_soon}
                                caption="Today and the next 7 days"
                                tone={
                                    hero.attention.due_soon > 0
                                        ? 'warning'
                                        : undefined
                                }
                                onClick={() => setTab('due_soon')}
                                pressed={tab === 'due_soon'}
                            />
                            <PageHeaderNumber
                                label="Needs an owner"
                                value={hero.attention.unassigned}
                                caption={
                                    hero.attention.unassigned > 0
                                        ? 'Choose someone'
                                        : 'Every open hazard has an owner'
                                }
                                tone={
                                    hero.attention.unassigned > 0
                                        ? 'warning'
                                        : undefined
                                }
                                onClick={() => setTab('unassigned')}
                                pressed={tab === 'unassigned'}
                            />
                        </PageHeaderNumbers>
                    }
                    filters={
                        <>
                            {!scopedSite && sites.length > 0 && (
                                <EntityFilter
                                    label="Site"
                                    allLabel="All sites"
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
                            <SafetyDateFilter
                                label="Logged"
                                from={filters.from ?? null}
                                to={filters.to ?? null}
                                onChange={go}
                            />
                            {!scopedSite && (
                                <PageHeaderFilterSelect
                                    label="All types"
                                    allValue=""
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
                            <MoreFilters
                                active={moreActive}
                                filters={filters}
                                tab={tab}
                                tabCounts={tabCounts}
                                severityOptions={severityOptions}
                                riskRatings={riskRatings}
                                assignees={assignees}
                                onTab={setTab}
                                onChange={go}
                            />
                            {hasFilters && (
                                <PageHeaderFilterButton
                                    icon={X}
                                    onClick={clearFilters}
                                >
                                    Clear filters
                                </PageHeaderFilterButton>
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

            <TierTwoTabs
                tabs={views}
                activeTab={tab}
                onTab={(key) => {
                    if (key !== tab) setTab(key);
                }}
                testIdPrefix="hazard-register"
                ariaLabel="Hazard views"
                panelId="hazard-register-panel"
                // Views carry no href, so the strip renders its own buttons.
                renderLink={() => null}
            />

            <div
                id="hazard-register-panel"
                role="tabpanel"
                aria-label={current?.title ?? 'Hazards'}
                className="min-w-0"
            >
                <HazardTable
                    title={current?.title ?? 'Hazards'}
                    sub={range}
                    rows={hazards.data}
                    scoped={!!scopedSite}
                    onOpen={openHazard}
                    actionsFor={hazardActions}
                    footer={pager}
                />
            </div>

            {headerMenu.ctx && registerActions.length ? (
                <EntityContextMenu
                    x={headerMenu.ctx.x}
                    y={headerMenu.ctx.y}
                    icon={ShieldAlert}
                    title={
                        scopedSite
                            ? `${scopedSite.name} hazards`
                            : 'Homes & Sites Hazards'
                    }
                    items={registerActions}
                    onClose={headerMenu.close}
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

/* ------------------------------------------------------------------ */
/*  Header parts                                                       */
/* ------------------------------------------------------------------ */

/** The quieter register filters, plus every hazard view, in one place. */
function MoreFilters({
    active,
    filters,
    tab,
    tabCounts,
    severityOptions,
    riskRatings,
    assignees,
    onTab,
    onChange,
}: {
    active: number;
    filters: HazardFilters;
    tab: string;
    tabCounts: Record<string, number>;
    severityOptions: string[];
    riskRatings: string[];
    assignees: Array<{ id: number; name: string }>;
    onTab: (tab: string) => void;
    onChange: (next: Partial<HazardFilters>) => void;
}) {
    const [open, setOpen] = useState(false);
    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <PageHeaderFilterButton
                    icon={SlidersHorizontal}
                    active={active > 0}
                >
                    {active > 0 ? `More filters · ${active}` : 'More filters'}
                </PageHeaderFilterButton>
            </PopoverTrigger>
            <PopoverContent
                align="end"
                className="grid w-80 max-w-[calc(100vw-2rem)] gap-3"
            >
                <FilterField label="Hazard view">
                    <SelectInput
                        ariaLabel="Hazard view"
                        placeholder="Hazard view"
                        value={tab}
                        onChange={(value) => {
                            setOpen(false);
                            onTab(value);
                        }}
                        options={VIEWS.map((view) => ({
                            value: view.key,
                            label: `${view.option} (${tabCounts[view.key] ?? 0})`,
                        }))}
                    />
                </FilterField>
                <FilterField label="Severity">
                    <SelectInput
                        ariaLabel="Severity"
                        placeholder="All severities"
                        value={filters.severity ?? 'all'}
                        onChange={(value) =>
                            onChange({ severity: value === 'all' ? null : value })
                        }
                        options={[
                            { value: 'all', label: 'All severities' },
                            ...severityOptions.map((value) => ({
                                value,
                                label: sentence(value),
                            })),
                        ]}
                    />
                </FilterField>
                <FilterField label="Risk">
                    <SelectInput
                        ariaLabel="Risk"
                        placeholder="All risk ratings"
                        value={filters.risk_rating ?? 'all'}
                        onChange={(value) =>
                            onChange({
                                risk_rating: value === 'all' ? null : value,
                            })
                        }
                        options={[
                            { value: 'all', label: 'All risk ratings' },
                            ...riskRatings.map((value) => ({
                                value,
                                label: sentence(value),
                            })),
                        ]}
                    />
                </FilterField>
                <FilterField label="Owner">
                    <SelectInput
                        ariaLabel="Owner"
                        placeholder="All owners"
                        value={
                            filters.assignee_id
                                ? String(filters.assignee_id)
                                : 'all'
                        }
                        onChange={(value) =>
                            onChange({
                                assignee_id:
                                    value === 'all' ? null : Number(value),
                            })
                        }
                        options={[
                            { value: 'all', label: 'All owners' },
                            ...assignees.map((user) => ({
                                value: String(user.id),
                                label: user.name,
                            })),
                        ]}
                    />
                </FilterField>
                <FilterField label="Due">
                    <SelectInput
                        ariaLabel="Due"
                        placeholder="Any due date"
                        value={filters.due_state ?? 'any'}
                        onChange={(value) =>
                            onChange({
                                due_state: value === 'any' ? null : value,
                            })
                        }
                        options={[
                            { value: 'any', label: 'Any due date' },
                            { value: 'overdue', label: 'Overdue' },
                            { value: 'due_soon', label: 'Due in 7 days' },
                        ]}
                    />
                </FilterField>
            </PopoverContent>
        </Popover>
    );
}

function FilterField({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <span className="text-caption font-semibold">{label}</span>
            {children}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Hazard table                                                       */
/* ------------------------------------------------------------------ */

export function HazardTable({
    title = 'Hazards',
    sub,
    rows,
    scoped,
    onOpen,
    actionsFor,
    footer,
}: {
    title?: string;
    sub?: ReactNode;
    rows: HazardRow[];
    scoped: boolean;
    onOpen: (id: number) => void;
    actionsFor: (row: HazardRow) => MenuItem[];
    footer?: ReactNode;
}) {
    const columns: RecordTableColumn<HazardRow>[] = [
        {
            key: 'logged',
            label: 'Logged',
            className: 'min-w-[11rem] max-w-[14rem]',
            cell: (h) => (
                <RecordIdentity
                    title={
                        h.created_at
                            ? formatDateOnly(toDateInput(h.created_at))
                            : 'Time not recorded'
                    }
                    sub={[
                        h.created_at ? formatTime(h.created_at) : null,
                        h.reference_number,
                        h.due_date
                            ? `Due ${formatDateOnly(h.due_date)}`
                            : 'No due date',
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                />
            ),
        },
        {
            key: 'hazard',
            label: 'Hazard',
            className: 'min-w-[14rem] max-w-[22rem]',
            cell: (h) => (
                <RecordIdentity
                    title={hazardLabelOf(h)}
                    sub={
                        <span className="line-clamp-2">
                            {h.description}
                            {h.description ? ' · ' : ''}
                            {h.assigned_to_name
                                ? `Owner: ${h.assigned_to_name}`
                                : 'Needs an owner'}
                        </span>
                    }
                />
            ),
        },
        ...(scoped
            ? []
            : ([
                  {
                      key: 'site',
                      label: 'Site',
                      nowrap: true,
                      cell: (h) => {
                          const SiteIcon = h.site_type === 'house' ? Home : Building2;
                          return (
                              <RecordIdentity
                                  leading={<SiteIcon className="size-3.5" />}
                                  title={h.site_name ?? 'Site not recorded'}
                                  sub={siteTypeLabel(h.site_type) || undefined}
                              />
                          );
                      },
                  },
              ] satisfies RecordTableColumn<HazardRow>[])),
        {
            key: 'severity',
            label: 'Severity',
            nowrap: true,
            cell: (h) => <SeverityChip severity={h.severity} />,
        },
        {
            key: 'risk',
            label: 'Risk',
            nowrap: true,
            cell: (h) => <RiskChip rating={h.risk_rating} suffix />,
        },
        {
            key: 'status',
            label: 'Status',
            nowrap: true,
            cell: (h) => <StatusChip status={h.status} />,
        },
        {
            key: 'flags',
            label: 'Flags',
            cell: (h) => <HazardFlags h={h} />,
        },
    ];
    return (
        <RecordTable
            title={title}
            sub={sub}
            ariaLabel="Hazards table"
            rows={rows}
            rowKey={(h) => h.id}
            onOpen={(h) => onOpen(h.id)}
            actionsFor={actionsFor}
            menuTitle={(h) => `${h.reference_number} · ${hazardLabelOf(h)}`}
            menuIcon={ShieldAlert}
            columns={columns}
            empty={
                <p className="sg-quiet">
                    <Ring tone="none" />
                    <span>No hazards match this view and its filters.</span>
                </p>
            }
            footer={rows.length ? footer : undefined}
        />
    );
}

/** The row's flags in the drawing's words; nothing to flag reads "—". */
function HazardFlags({ h }: { h: HazardRow }) {
    const flags: ReactNode[] = [];
    if (h.flags.unassigned)
        flags.push(
            <StatusBadge key="owner" variant="warning" title="No owner assigned">
                Needs an owner
            </StatusBadge>,
        );
    if (h.flags.overdue)
        flags.push(
            <StatusBadge key="overdue" variant="critical" title="Past due date">
                Overdue
            </StatusBadge>,
        );
    else if (h.flags.due_soon)
        flags.push(
            <StatusBadge key="due" variant="warning" title="Due within 7 days">
                Due within 7 days
            </StatusBadge>,
        );
    if (h.worksafe && h.status !== 'closed')
        flags.push(
            <StatusBadge
                key="critical"
                variant="critical"
                title="Extreme risk rating or critical severity; notification needs a separate decision"
            >
                Rated critical
            </StatusBadge>,
        );
    if (h.flags.awaiting_closure)
        flags.push(
            <StatusBadge
                key="close"
                variant="info"
                title="Mitigated — awaiting closure"
            >
                Ready to close
            </StatusBadge>,
        );
    if (h.open_action_count > 0)
        flags.push(
            <StatusBadge
                key="actions"
                variant="info"
                title="Open corrective actions"
            >
                {h.open_action_count === 1
                    ? '1 open action'
                    : `${h.open_action_count} open actions`}
            </StatusBadge>,
        );
    if (!flags.length) return <span className="text-muted-foreground">—</span>;
    return <div className="flex flex-wrap items-center gap-1.5">{flags}</div>;
}
