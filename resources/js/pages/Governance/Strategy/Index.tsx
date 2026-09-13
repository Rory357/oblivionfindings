import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import {
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    ProgressValue,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import { Head, router } from '@inertiajs/react';
import { Compass, ExternalLink, History, Pencil, Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    StrategicPlanWizardDialog,
    humaniseHorizon,
    planStatusLabel,
    planStatusVariant,
    type StrategicPlanFormOptions,
} from './_dialogs';

interface StrategicPlan {
    id: number;
    title: string;
    planning_horizon: string;
    period_start: string;
    period_end: string;
    status: string;
    progress_pct: number | null;
    goals_count: number;
    version_number: number;
}

interface Filters {
    status: string | null;
    horizon: string | null;
    search: string | null;
}

interface Props {
    plans: { data: StrategicPlan[] };
    summary?: {
        total: number;
        draft: number;
        approved: number;
        superseded: number;
        archived: number;
    };
    inEffect?: { id: number; title: string; progress_pct: number } | null;
    filters?: Filters;
    canCreate?: boolean;
    /** New-plan wizard options — only sent to viewers who may create. */
    formOptions?: StrategicPlanFormOptions | null;
}

const ALL = '__all';

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'draft', label: 'Draft or in review' },
    { value: 'approved', label: 'Approved' },
    { value: 'superseded', label: 'Superseded' },
    { value: 'archived', label: 'Archived' },
];

const EMPTY_FILTERS: Filters = { status: null, horizon: null, search: null };

const dateOnly = (value: string | null | undefined) =>
    (value ?? '').slice(0, 10);

export default function StrategyIndex({
    plans,
    summary,
    inEffect = null,
    filters = EMPTY_FILTERS,
    canCreate: canCreateProp = false,
    formOptions = null,
}: Props) {
    const rows = plans.data;
    const [search, setSearch] = useState(filters.search ?? '');
    const canCreate = Boolean(canCreateProp && formOptions);
    const [createOpen, setCreateOpen] = useDialogDeepLink('create', canCreate);
    const ctxMenu = useEntityContextMenu<StrategicPlan>();

    const counts = summary ?? {
        total: rows.length,
        draft: rows.filter((p) => ['draft', 'review'].includes(p.status))
            .length,
        approved: rows.filter((p) => ['approved', 'active'].includes(p.status))
            .length,
        superseded: rows.filter((p) => p.status === 'superseded').length,
        archived: rows.filter((p) =>
            ['archived', 'completed'].includes(p.status),
        ).length,
    };

    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);

    const go = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(
            Object.entries(merged).filter(([, value]) => value),
        );
        router.get('/governance/strategy', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    useEffect(() => {
        const handle = setTimeout(() => {
            if ((filters.search ?? '') !== search) {
                go({ search: search.trim() || null });
            }
        }, 350);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const hasFilters = Boolean(
        filters.status || filters.horizon || filters.search,
    );
    const horizonOptions = [
        { value: ALL, label: 'Any horizon' },
        ...Object.entries(
            formOptions?.horizons ?? {
                '3_year': '3-year plan',
                '5_year': '5-year plan',
            },
        ).map(([value, label]) => ({ value, label })),
    ];

    const open = (plan: StrategicPlan) =>
        router.visit(`/governance/strategy/${plan.id}`);
    const actionsFor = (plan: StrategicPlan): MenuItem[] =>
        compactMenu([
            {
                label: 'Open plan',
                icon: ExternalLink,
                onClick: () => open(plan),
            },
            canCreate
                ? {
                      label: 'Edit plan',
                      icon: Pencil,
                      onClick: () =>
                          router.visit(
                              `/governance/strategy/${plan.id}?edit=1`,
                          ),
                  }
                : null,
            {
                label: 'View changes',
                icon: History,
                onClick: () =>
                    router.visit(`/governance/strategy/${plan.id}/changes`),
            },
        ]);

    const header = (
        <PageHeader
            icon={Compass}
            title="Strategic plans"
            subline={`Board-approved direction, goals and delivery horizons · ${counts.total} plan${counts.total === 1 ? '' : 's'}`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search plans…"
                    />
                    {canCreate ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New plan
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Plans"
                        href="/governance/strategy"
                    >
                        <PageHeaderMeterBig>{counts.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Across every horizon and version
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="In effect"
                        value={
                            inEffect
                                ? `${Math.round(inEffect.progress_pct)}%`
                                : undefined
                        }
                        href={
                            inEffect
                                ? `/governance/strategy/${inEffect.id}`
                                : '/governance/strategy?status=approved'
                        }
                        tone={inEffect ? 'success' : 'brand'}
                        ariaLabel={
                            inEffect
                                ? `View ${inEffect.title}`
                                : 'View approved plans'
                        }
                    >
                        {inEffect ? (
                            <>
                                <PageHeaderMeterBar
                                    percent={inEffect.progress_pct}
                                />
                                <PageHeaderMeterCaption>
                                    {inEffect.title} · goal progress
                                </PageHeaderMeterCaption>
                            </>
                        ) : (
                            <>
                                <PageHeaderMeterBig>0</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    No approved plan yet
                                </PageHeaderMeterCaption>
                            </>
                        )}
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Drafts"
                        href="/governance/strategy?status=draft"
                        tone={counts.draft > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>{counts.draft}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Draft or in review
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Superseded"
                        href="/governance/strategy?status=superseded"
                    >
                        <PageHeaderMeterBig>
                            {counts.superseded}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Replaced by a newer version
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Archived"
                        href="/governance/strategy?status=archived"
                    >
                        <PageHeaderMeterBig>
                            {counts.archived}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Closed plans
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status ?? ALL}
                        allValue={ALL}
                        options={STATUS_OPTIONS}
                        onChange={(value) =>
                            go({ status: value === ALL ? null : value })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Horizon"
                        value={filters.horizon ?? ALL}
                        allValue={ALL}
                        options={horizonOptions}
                        onChange={(value) =>
                            go({ horizon: value === ALL ? null : value })
                        }
                    />
                </>
            }
            rail={<GovernanceSectionRail />}
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Governance', href: '/governance/dashboard' },
                { title: 'Strategic plans', href: '/governance/strategy' },
            ]}
        >
            <Head title="Strategic plans" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Strategic plans"
                        caption={`${rows.length} of ${counts.total} shown`}
                    />

                    {rows.length === 0 ? (
                        <EmptyState
                            icon={Compass}
                            title={
                                hasFilters
                                    ? 'No plans match your filters'
                                    : 'No strategic plans yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : 'Create the first strategic plan to set the board’s long-term direction.'
                            }
                            action={
                                hasFilters ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setSearch('');
                                            go({
                                                status: null,
                                                horizon: null,
                                                search: null,
                                            });
                                        }}
                                    >
                                        <X className="h-3.5 w-3.5" />
                                        Clear filters
                                    </Button>
                                ) : canCreate ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        <Plus className="h-3.5 w-3.5" />
                                        New plan
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={rows}
                            rowKey={(plan) => plan.id}
                            identityLabel="Plan"
                            identity={(plan) => ({
                                icon: Compass,
                                name: plan.title,
                                subline: `Version ${plan.version_number}`,
                            })}
                            hrefFor={(plan) =>
                                `/governance/strategy/${plan.id}`
                            }
                            onOpen={open}
                            onRowContextMenu={ctxMenu.open}
                            actionsFor={actionsFor}
                            mutedFor={(plan) =>
                                [
                                    'superseded',
                                    'archived',
                                    'completed',
                                ].includes(plan.status)
                            }
                            columns={[
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '0.8fr',
                                    cell: (plan) => (
                                        <EntityStatusChip
                                            variant={planStatusVariant(
                                                plan.status,
                                            )}
                                        >
                                            {planStatusLabel(plan.status)}
                                        </EntityStatusChip>
                                    ),
                                },
                                {
                                    key: 'horizon',
                                    label: 'Horizon',
                                    width: '0.8fr',
                                    cell: (plan) => (
                                        <EntityChip>
                                            {formOptions?.horizons[
                                                plan.planning_horizon
                                            ] ??
                                                humaniseHorizon(
                                                    plan.planning_horizon,
                                                )}
                                        </EntityChip>
                                    ),
                                },
                                {
                                    key: 'period',
                                    label: 'Period',
                                    width: '1.1fr',
                                    cell: (plan) => (
                                        <span className="text-[12.5px] tabular-nums">
                                            {formatDateOnly(
                                                dateOnly(plan.period_start),
                                            )}{' '}
                                            –{' '}
                                            {formatDateOnly(
                                                dateOnly(plan.period_end),
                                            )}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'goals',
                                    label: 'Goals',
                                    width: '0.5fr',
                                    align: 'right',
                                    cell: (plan) => (
                                        <span className="tabular-nums">
                                            {plan.goals_count || 0}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'progress',
                                    label: 'Goal progress',
                                    width: '1.2fr',
                                    cell: (plan) =>
                                        plan.goals_count > 0 ? (
                                            <ProgressValue
                                                percent={Number(
                                                    plan.progress_pct ?? 0,
                                                )}
                                            >
                                                {Math.round(
                                                    Number(
                                                        plan.progress_pct ?? 0,
                                                    ),
                                                )}
                                                %
                                            </ProgressValue>
                                        ) : (
                                            <ProgressValue percent={null}>
                                                No goals yet
                                            </ProgressValue>
                                        ),
                                },
                            ]}
                        />
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Compass}
                    title={ctxMenu.ctx.record.title}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {canCreate && formOptions ? (
                <StrategicPlanWizardDialog
                    isOpen={createOpen}
                    onClose={() => setCreateOpen(false)}
                    options={formOptions}
                />
            ) : null}
        </AppLayout>
    );
}
