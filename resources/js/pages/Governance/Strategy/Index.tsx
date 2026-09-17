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
import { governanceStatus } from '@/lib/governance-labels';
import { Head, router } from '@inertiajs/react';
import { Compass, ExternalLink, History, Pencil, Plus, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    StrategicPlanWizardDialog,
    humaniseHorizon,
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
    inEffect?: {
        id: number;
        title: string;
        goals_count: number;
        progress_pct: number;
    } | null;
    filters?: Filters;
    horizons?: Record<string, string>;
    canCreate?: boolean;
    /** New-plan wizard options — only sent to viewers who may create. */
    formOptions?: StrategicPlanFormOptions | null;
}

const ALL = '__all';

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'draft', label: 'Draft' },
    { value: 'approved', label: 'Approved' },
    { value: 'superseded', label: 'Replaced by a newer version' },
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
    horizons,
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
    const lengthOptions = [
        { value: ALL, label: 'Any length' },
        ...Object.entries(
            horizons ??
                formOptions?.horizons ?? {
                    '3_year': '3-year plan',
                    '5_year': '5-year plan',
                },
        ).map(([value, label]) => ({ value, label })),
    ];

    const open = (plan: StrategicPlan) =>
        router.visit(`/governance/strategy/${plan.id}`);
    const isDraft = (plan: StrategicPlan) =>
        ['draft', 'review', 'consultation'].includes(plan.status);
    const actionsFor = (plan: StrategicPlan): MenuItem[] =>
        compactMenu([
            {
                label: 'Open plan',
                icon: ExternalLink,
                onClick: () => open(plan),
            },
            canCreate && isDraft(plan)
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
                label: 'See what changed',
                icon: History,
                onClick: () =>
                    router.visit(`/governance/strategy/${plan.id}/changes`),
            },
        ]);

    const inEffectTracked =
        inEffect !== null &&
        inEffect.goals_count > 0 &&
        inEffect.progress_pct > 0;

    const header = (
        <PageHeader
            icon={Compass}
            title="Strategic plan"
            subline={`The board's plan for the next few years and how it will know it's getting there · ${counts.total} version${counts.total === 1 ? '' : 's'}`}
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
                        label="Current plan"
                        value={
                            inEffect && inEffectTracked
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
                                ? `Open ${inEffect.title}`
                                : 'View approved plans'
                        }
                    >
                        {inEffect ? (
                            <>
                                {inEffectTracked ? (
                                    <PageHeaderMeterBar
                                        percent={inEffect.progress_pct}
                                    />
                                ) : (
                                    <PageHeaderMeterBig>Approved</PageHeaderMeterBig>
                                )}
                                <PageHeaderMeterCaption>
                                    {inEffectTracked
                                        ? `${inEffect.title} · goal progress`
                                        : `${inEffect.title} · progress not tracked yet`}
                                </PageHeaderMeterCaption>
                            </>
                        ) : (
                            <>
                                <PageHeaderMeterBig>None</PageHeaderMeterBig>
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
                            Not approved by the board yet
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Replaced"
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
                            No longer in use
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
                        label="Plan length"
                        value={filters.horizon ?? ALL}
                        allValue={ALL}
                        options={lengthOptions}
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
                { title: 'Strategic plan', href: '/governance/strategy' },
            ]}
        >
            <Head title="Strategic plan" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Plans and versions"
                        caption={`${rows.length} of ${counts.total} shown`}
                    />

                    {rows.length === 0 ? (
                        <EmptyState
                            icon={Compass}
                            title={
                                hasFilters
                                    ? 'No plans match your filters'
                                    : 'No strategic plan yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : 'The strategic plan sets out what the organisation wants to achieve over the next few years.'
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
                                    width: '1fr',
                                    cell: (plan) => {
                                        const chip = governanceStatus(
                                            'strategic_plan_status',
                                            plan.status,
                                        );
                                        return (
                                            <EntityStatusChip
                                                variant={chip.variant}
                                            >
                                                {chip.label}
                                            </EntityStatusChip>
                                        );
                                    },
                                },
                                {
                                    key: 'horizon',
                                    label: 'Plan length',
                                    width: '0.8fr',
                                    cell: (plan) => (
                                        <EntityChip>
                                            {horizons?.[plan.planning_horizon] ??
                                                humaniseHorizon(
                                                    plan.planning_horizon,
                                                )}
                                        </EntityChip>
                                    ),
                                },
                                {
                                    key: 'period',
                                    label: 'Dates',
                                    width: '1.1fr',
                                    cell: (plan) => (
                                        <span className="tabular-nums">
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
                                        plan.goals_count > 0 &&
                                        Number(plan.progress_pct ?? 0) > 0 ? (
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
                                                {plan.goals_count > 0
                                                    ? 'Not tracked yet'
                                                    : 'No goals yet'}
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
