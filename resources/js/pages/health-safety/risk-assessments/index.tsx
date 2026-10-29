/**
 * Risk assessments (approved H&S drawing pg-hsn-61 — Stephan 23 Oct 2026):
 * one header with four filter chips, the register views as a tier-two strip,
 * then the risk map beside the highest risks (before → after controls), and
 * the register. The map and the highest risks are counted from the register
 * itself, so they show only when every active assessment is on this page —
 * never a page-local count dressed as a total.
 */
import { boardReportHref } from '@/lib/health-safety/board-reports';
import { hsLabel } from '@/lib/health-safety/hs-labels';
import {
    RaRegisterTable,
    raMenuItems,
    type RaCtxHandlers,
} from '@/components/health-safety/risk-assessments/ra-table';
import { RaWizardDialog } from '@/components/health-safety/risk-assessments/ra-wizard-dialog';
import type {
    RaPickers,
    RaRow,
} from '@/components/health-safety/risk-assessments/types';
import { SafetyWorkspaceRail } from '@/components/health-safety/workspace-header';
import {
    EntityContextMenu,
    useEntityContextMenu,
} from '@/components/lists/entity-menu';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import {
    TierTwoTabs,
    type GroupedProfileNavTab,
} from '@/components/page/grouped-profile-nav';
import {
    Log,
    RISK_CONSEQUENCE,
    RISK_LIKELIHOOD,
    RiskMap,
    Ring,
    SideBySide,
    type LogItem,
    type SgTone,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { SelectInput } from '@/components/wizard/primitives';
import {
    SafetyFormDraftContext,
    useSafetyWorkspaceDrafts,
} from '@/hooks/use-safety-workspace-drafts';
import AppLayout from '@/layouts/app-layout';
import {
    RISK_REGISTER,
    riskHref,
    riskReturn,
    type RiskCommand,
} from '@/lib/health-safety/risk-navigation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Archive,
    Clock,
    FileEdit,
    LayoutGrid,
    Plus,
    ShieldAlert,
    SlidersHorizontal,
    TriangleAlert,
    X,
} from 'lucide-react';
import {
    useEffect,
    useState,
    type MouseEvent as ReactMouseEvent,
    type ReactNode,
} from 'react';

interface Filters {
    tab: string;
    status: string | null;
    risk_level: string | null;
    due_for_review: string | null;
    risk_acceptable: string | null;
    site_id: number | null;
    client_id: number | null;
    hs_event_id: number | null;
    search: string | null;
}
interface Props {
    assessments: {
        data: RaRow[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    tabCounts: {
        all: number;
        active: number;
        drafts: number;
        due: number;
        high: number;
        closed: number;
    };
    hero: {
        total: number;
        active: number;
        high_extreme_active: number;
        drafts: number;
        under_review: number;
        due_for_review: number;
        residual_not_acceptable: number;
        awaiting_approval: number;
        compliance: {
            reviews_overdue: number;
            high_extreme_without_approval: number;
            residual_not_acceptable: number;
            pct_active_scheduled: number;
        };
    };
    pickers: RaPickers;
    can: { manage: boolean; viewReports?: boolean };
    filters: Filters;
}

const VIEWS: {
    key: keyof Props['tabCounts'];
    label: string;
    icon: GroupedProfileNavTab['icon'];
}[] = [
    { key: 'all', label: 'All', icon: LayoutGrid },
    { key: 'active', label: 'Active', icon: ShieldAlert },
    { key: 'drafts', label: 'Drafts', icon: FileEdit },
    { key: 'due', label: 'Due for review', icon: Clock },
    { key: 'high', label: 'High & extreme', icon: TriangleAlert },
    { key: 'closed', label: 'Historical', icon: Archive },
];

const STATUS_OPTIONS = [
    { value: 'active', label: 'Active' },
    { value: 'under_review', label: 'Under review' },
    { value: 'draft', label: 'Draft' },
    { value: 'superseded', label: 'Replaced' },
    { value: 'archived', label: 'Archived' },
];
const LEVEL_OPTIONS = ['low', 'medium', 'high', 'extreme'].map((value) => ({
    value,
    label: value[0].toUpperCase() + value.slice(1),
}));

/** The map cell for a likelihood and consequence (each 1–5), most likely first. */
function riskCell(
    likelihood: number | null | undefined,
    consequence: number | null | undefined,
): [number, number] | null {
    if (!likelihood || !consequence) return null;
    const row = RISK_LIKELIHOOD.length - likelihood;
    const col = consequence - 1;
    return row >= 0 && row < 5 && col >= 0 && col < 5 ? [row, col] : null;
}

function levelRing(level: RaRow['risk_level']): SgTone {
    return level === 'extreme'
        ? 'critical'
        : level === 'high'
          ? 'warning'
          : level === 'medium'
            ? 'info'
            : 'success';
}

function reviewWords(row: RaRow): string | null {
    switch (row.review_state.kind) {
        case 'overdue':
            return 'review overdue';
        case 'today':
            return 'review due today';
        case 'soon':
            return row.review_state.days === 1
                ? 'review due in 1 day'
                : `review due in ${row.review_state.days} days`;
        default:
            return null;
    }
}

export default function RiskAssessmentsIndex({
    assessments,
    tabCounts,
    hero,
    pickers,
    can,
    filters,
}: Props) {
    const returnTo = riskReturn(usePage().url);
    const rowMenu = useEntityContextMenu<RaRow>();
    const [creating, setCreating] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [mapView, setMapView] = useState(0);
    const drafts = useSafetyWorkspaceDrafts();
    useEffect(() => setSearch(filters.search ?? ''), [filters.search]);
    const params = (next: Partial<Filters> = {}) =>
        Object.fromEntries(
            Object.entries({ ...filters, ...next }).filter(
                ([, v]) => v != null && v !== '',
            ),
        ) as Record<string, string | number>;
    const query = (next: Partial<Filters> = {}) =>
        new URLSearchParams(
            Object.entries(params(next)).map(([key, value]) => [
                key,
                String(value),
            ]),
        );
    const go = (next: Partial<Filters>) =>
        router.get(RISK_REGISTER, params(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    const recordHref = (id: number, command?: RiskCommand) =>
        riskHref(id, { returnTo, command });
    const openDetail = (id: number, command?: RiskCommand) => {
        rowMenu.close();
        router.visit(recordHref(id, command));
    };
    const handlers: RaCtxHandlers = {
        onView: (r) => openDetail(r.id),
        onEdit: (r) => openDetail(r.id),
        onApprove: (r) => openDetail(r.id, 'approve'),
        onReview: (r) => openDetail(r.id, 'review'),
        onResidual: (r) => openDetail(r.id, 'residual'),
        onSupersede: (r) => openDetail(r.id),
        onArchive: (r) => openDetail(r.id, 'archive'),
        onCopyLink: (r) => {
            void navigator.clipboard?.writeText(
                window.location.origin + recordHref(r.id),
            );
            rowMenu.close();
        },
        onOpenCurrent: openDetail,
    };
    const actionsFor = (row: RaRow) => raMenuItems(row, can.manage, handlers);

    const views: GroupedProfileNavTab[] = VIEWS.map((view) => ({
        key: view.key,
        label: view.label,
        icon: view.icon,
        count: tabCounts[view.key],
    }));
    const moreActive = [
        filters.client_id,
        filters.hs_event_id,
        filters.risk_acceptable,
        filters.due_for_review === 'true' ? 'true' : null,
    ].filter((value) => value != null && value !== '').length;
    const selected = Object.entries(filters).some(
        ([key, value]) => key !== 'tab' && value != null && value !== '',
    );
    const select = (
        label: string,
        key: keyof Filters,
        options: { value: string; label: string }[],
        numeric = false,
    ) => (
        <PageHeaderFilterSelect
            label={label}
            value={String(filters[key] ?? '')}
            allValue=""
            options={[{ value: '', label }, ...options]}
            onChange={(v) =>
                go({ [key]: v ? (numeric ? Number(v) : v) : null })
            }
        />
    );

    /* ----- the map and the highest risks, from a complete register ----- */

    const active = assessments.data.filter((r) => r.status === 'active');
    const inForce = assessments.data.filter(
        (r) => r.status === 'active' || r.status === 'under_review',
    );
    const complete =
        active.length > 0 &&
        active.length === hero.active &&
        inForce.length === hero.active + hero.under_review;
    const after = mapView === 1;
    const scored = active.filter(
        (r) => !after || r.residual_risk_score != null,
    );
    const cells = Array.from({ length: 5 }, () => Array(5).fill(0) as number[]);
    for (const r of scored) {
        const cell = after
            ? riskCell(r.residual_likelihood, r.residual_consequence)
            : riskCell(r.likelihood, r.consequence);
        if (cell) cells[cell[0]][cell[1]] += 1;
    }
    const top = [...scored].sort(
        (a, b) =>
            (after
                ? (b.residual_risk_score ?? 0) - (a.residual_risk_score ?? 0)
                : b.risk_score - a.risk_score) || b.id - a.id,
    )[0];
    const topCell = top
        ? after
            ? riskCell(top.residual_likelihood, top.residual_consequence)
            : riskCell(top.likelihood, top.consequence)
        : null;
    const unscored = active.length - active.filter((r) => r.residual_risk_score != null).length;
    const highest: LogItem[] = [...inForce]
        .sort((a, b) => b.risk_score - a.risk_score || b.id - a.id)
        .slice(0, 5)
        .map((r) => {
            const residual = r.residual_risk_score != null && r.residual_risk_level;
            const named = r.title
                .toLowerCase()
                .includes(r.attached_to.name.toLowerCase());
            return {
                key: r.id,
                time: `${r.risk_score} → ${r.residual_risk_score ?? '?'}`,
                tone: levelRing(r.risk_level),
                title: (
                    <Link
                        href={recordHref(r.id)}
                        className="rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {r.title}
                    </Link>
                ),
                sub: [
                    r.reference_number,
                    named || r.attached_to.type === 'standalone'
                        ? null
                        : r.attached_to.name,
                    residual
                        ? `${hsLabel('risk_level', r.risk_level)} before controls → ${hsLabel('risk_level', r.residual_risk_level)} after`
                        : 'Not rated after controls yet',
                    reviewWords(r),
                ]
                    .filter(Boolean)
                    .join(' · '),
                action: can.manage
                    ? r.review_state.kind === 'overdue' && r.status === 'active'
                        ? { label: 'Review', onClick: () => openDetail(r.id, 'review') }
                        : !residual
                          ? { label: 'Score', onClick: () => openDetail(r.id, 'residual') }
                          : null
                    : null,
                menu: actionsFor(r),
                onContextMenu: (event: ReactMouseEvent<HTMLElement>) =>
                    rowMenu.open(event, r),
            };
        });

    const pager = (
        <>
            <p className="text-subtle">
                Showing {assessments.data.length} of {assessments.total}
            </p>
            {assessments.last_page > 1 ? (
                <LaravelPagination links={assessments.links} />
            ) : null}
        </>
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & safety', href: '/health-safety' },
                { title: 'Risk assessments', href: RISK_REGISTER },
            ]}
        >
            <Head title="Risk assessments" />
            <SafetyFormDraftContext.Provider value={drafts.register}>
                <PageLayout
                    hero={
                        <PageHeader
                            wrapTitle
                            title="Risk assessments"
                            icon={ShieldAlert}
                            subline="Assess risks, record the controls and keep reviews up to date"
                            actions={
                                <>
                                    <PageHeaderSearch
                                        value={search}
                                        onChange={setSearch}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter')
                                                go({
                                                    search:
                                                        search.trim() || null,
                                                });
                                        }}
                                        placeholder="Search assessments…"
                                        ariaLabel="Search risk assessments"
                                    />
                                    {can.viewReports && (
                                        <PageHeaderGlassButton asChild>
                                            <a
                                                href={
                                                    boardReportHref('risk-register')
                                                }
                                                aria-label="Open the risk assessment report"
                                            >
                                                Risk assessment report
                                            </a>
                                        </PageHeaderGlassButton>
                                    )}
                                    {can.manage && (
                                        <PageHeaderPrimaryButton
                                            icon={Plus}
                                            onClick={() => setCreating(true)}
                                        >
                                            Add risk assessment
                                        </PageHeaderPrimaryButton>
                                    )}
                                </>
                            }
                            filters={
                                <>
                                    {select(
                                        'All sites',
                                        'site_id',
                                        pickers.sites.map((p) => ({
                                            value: String(p.id),
                                            label: p.name,
                                        })),
                                        true,
                                    )}
                                    {select(
                                        'All statuses',
                                        'status',
                                        STATUS_OPTIONS,
                                    )}
                                    {select(
                                        'All risk levels',
                                        'risk_level',
                                        LEVEL_OPTIONS,
                                    )}
                                    <MoreFilters
                                        active={moreActive}
                                        filters={filters}
                                        pickers={pickers}
                                        onChange={go}
                                    />
                                    {selected && (
                                        <PageHeaderFilterButton
                                            icon={X}
                                            onClick={() =>
                                                router.get(
                                                    RISK_REGISTER,
                                                    { tab: filters.tab },
                                                    {
                                                        preserveState: true,
                                                        preserveScroll: true,
                                                    },
                                                )
                                            }
                                        >
                                            Clear filters
                                        </PageHeaderFilterButton>
                                    )}
                                </>
                            }
                            rail={
                                <SafetyWorkspaceRail
                                    hub="prevention"
                                    current="risks"
                                />
                            }
                        />
                    }
                    tabs={
                        <TierTwoTabs
                            tabs={views}
                            activeTab={filters.tab}
                            onTab={(tab) => {
                                if (tab !== filters.tab) go({ tab });
                            }}
                            testIdPrefix="risk-register"
                            ariaLabel="Risk assessment views"
                            panelId="risk-register-panel"
                            // Views carry no href, so the strip renders its own buttons.
                            renderLink={() => null}
                        />
                    }
                >
                    <div
                        id="risk-register-panel"
                        role="tabpanel"
                        aria-label={
                            VIEWS.find((view) => view.key === filters.tab)
                                ?.label ?? 'Risk assessments'
                        }
                        className="grid min-w-0 gap-5"
                    >
                        {complete ? (
                            <SideBySide cols="minmax(0, 1.15fr) minmax(0, 1fr)">
                                <RiskMap
                                    title="Risk map"
                                    sub={
                                        hero.active === 1
                                            ? '1 active risk assessment'
                                            : `${hero.active} active risk assessments`
                                    }
                                    views={['Before controls', 'After controls']}
                                    view={mapView}
                                    onView={setMapView}
                                    cells={cells}
                                    mark={topCell}
                                    note={
                                        top && topCell ? (
                                            <>
                                                <b>
                                                    {RISK_LIKELIHOOD[topCell[0]]}{' '}
                                                    × {RISK_CONSEQUENCE[topCell[1]]}
                                                </b>{' '}
                                                — {top.title}
                                                {after && unscored > 0
                                                    ? ` · ${unscored} not scored after controls yet`
                                                    : ''}
                                            </>
                                        ) : after && unscored > 0 ? (
                                            `${unscored} not scored after controls yet`
                                        ) : null
                                    }
                                />
                                <Log
                                    title="Highest risks first"
                                    sub="Before controls → after controls"
                                    action={{
                                        label: 'High & extreme',
                                        onClick: () => go({ tab: 'high' }),
                                    }}
                                    items={highest}
                                    limit={5}
                                />
                            </SideBySide>
                        ) : null}
                        <RaRegisterTable
                            rows={assessments.data}
                            sub={
                                assessments.from && assessments.to
                                    ? `Showing ${assessments.from}–${assessments.to} of ${assessments.total}`
                                    : `Showing ${assessments.data.length} of ${assessments.total}`
                            }
                            footer={pager}
                            onOpen={openDetail}
                            actionsFor={actionsFor}
                            hrefFor={recordHref}
                            empty={
                                <div className="grid justify-items-start gap-3 p-4">
                                    <p className="sg-quiet">
                                        <Ring tone="none" />
                                        <span>
                                            No risk assessments match this view
                                            and its filters.
                                        </span>
                                    </p>
                                    {can.manage ? (
                                        <Button
                                            onClick={() => setCreating(true)}
                                        >
                                            Add risk assessment
                                        </Button>
                                    ) : null}
                                </div>
                            }
                        />
                    </div>
                </PageLayout>
                {rowMenu.ctx ? (
                    <EntityContextMenu
                        x={rowMenu.ctx.x}
                        y={rowMenu.ctx.y}
                        icon={ShieldAlert}
                        title={`${rowMenu.ctx.record.reference_number} · ${rowMenu.ctx.record.title}`}
                        items={actionsFor(rowMenu.ctx.record)}
                        onClose={rowMenu.close}
                    />
                ) : null}
                {creating && (
                    <RaWizardDialog
                        kind="new"
                        pickers={pickers}
                        initialAttach={
                            filters.hs_event_id
                                ? { type: 'event', id: filters.hs_event_id }
                                : null
                        }
                        onClose={() => drafts.request(() => setCreating(false))}
                        onSuccess={(id) => {
                            setCreating(false);
                            if (id) openDetail(id);
                        }}
                    />
                )}
                {drafts.confirmation}
            </SafetyFormDraftContext.Provider>
        </AppLayout>
    );
}

/** The quieter register filters in one place. */
function MoreFilters({
    active,
    filters,
    pickers,
    onChange,
}: {
    active: number;
    filters: Filters;
    pickers: RaPickers;
    onChange: (next: Partial<Filters>) => void;
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
                <FilterField label="Client">
                    <SelectInput
                        ariaLabel="Client"
                        placeholder="All clients"
                        value={filters.client_id ? String(filters.client_id) : 'all'}
                        onChange={(value) =>
                            onChange({
                                client_id: value === 'all' ? null : Number(value),
                            })
                        }
                        options={[
                            { value: 'all', label: 'All clients' },
                            ...pickers.clients.map((p) => ({
                                value: String(p.id),
                                label: p.name,
                            })),
                        ]}
                    />
                </FilterField>
                <FilterField label="Safety review">
                    <SelectInput
                        ariaLabel="Safety review"
                        placeholder="All safety reviews"
                        value={
                            filters.hs_event_id
                                ? String(filters.hs_event_id)
                                : 'all'
                        }
                        onChange={(value) =>
                            onChange({
                                hs_event_id:
                                    value === 'all' ? null : Number(value),
                            })
                        }
                        options={[
                            { value: 'all', label: 'All safety reviews' },
                            ...pickers.events.map((p) => ({
                                value: String(p.id),
                                label: p.name,
                            })),
                        ]}
                    />
                </FilterField>
                <FilterField label="Acceptability">
                    <SelectInput
                        ariaLabel="Acceptability"
                        placeholder="All acceptability"
                        value={filters.risk_acceptable ?? 'all'}
                        onChange={(value) =>
                            onChange({
                                risk_acceptable: value === 'all' ? null : value,
                            })
                        }
                        options={[
                            { value: 'all', label: 'All acceptability' },
                            { value: '1', label: 'Acceptable' },
                            { value: '0', label: 'Not acceptable' },
                        ]}
                    />
                </FilterField>
                <FilterField label="Review">
                    <SelectInput
                        ariaLabel="Review"
                        placeholder="Any review date"
                        value={filters.due_for_review === 'true' ? 'true' : 'any'}
                        onChange={(value) =>
                            onChange({
                                due_for_review: value === 'true' ? 'true' : null,
                            })
                        }
                        options={[
                            { value: 'any', label: 'Any review date' },
                            { value: 'true', label: 'Due for review' },
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
