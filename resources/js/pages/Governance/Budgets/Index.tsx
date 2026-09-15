import { GovernanceSectionRail } from '@/components/governance/GovernanceSectionRail';
import { useDialogDeepLink } from '@/components/governance/governance-dialog-deep-link';
import {
    EmptyValue,
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
import { formatDateLong } from '@/lib/datetime';
import { formatNzd, governanceStatus } from '@/lib/governance-labels';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ExternalLink, Plus, Wallet, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { BudgetWizardDialog, type BudgetFormOptions } from './_dialogs';

export interface BudgetRow {
    id: number;
    fiscal_year: string;
    financial_year_label: string;
    title: string | null;
    display_name: string;
    total_budget: number | string;
    status: string;
    version_number: number;
    supersedes_version: number | null;
    approved_by_board_at: string | null;
    line_items_count: number;
    total_allocated: number;
    total_actual: number;
    actuals_recorded: boolean;
}

export interface BudgetSummary {
    financial_year: string;
    total: number;
    waiting: number;
    drafts: number;
    approved_this_year: number;
    budgeted_this_year: number;
    spent_this_year: number;
    actuals_recorded_this_year: boolean;
}

interface Props extends PageProps {
    budgets: BudgetRow[];
    summary: BudgetSummary;
    canCreate?: boolean;
    /** New-budget wizard options — only sent to viewers who may create. */
    formOptions?: BudgetFormOptions | null;
}

const ALL = '__all';
const WAITING = ['proposed', 'under_review'];

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'drafting', label: 'Draft' },
    { value: 'pending', label: 'Waiting for the board' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Not approved' },
];

/** "Version 2 (replaces version 1)". */
function versionText(budget: Pick<BudgetRow, 'version_number' | 'supersedes_version'>) {
    return budget.supersedes_version
        ? `Version ${budget.version_number} (replaces version ${budget.supersedes_version})`
        : `Version ${budget.version_number}`;
}

/**
 * The index loads every budget (no server pagination), so the header
 * filters narrow the list client-side from the URL query — meter links
 * (`?status=approved`) and shared URLs land on the same view.
 */
function useUrlFilters() {
    const page = usePage();
    const params = new URLSearchParams(page.url.split('?')[1] ?? '');
    return {
        status: params.get('status'),
        year: params.get('year'),
        search: params.get('search'),
    };
}

export default function BudgetsIndex({
    budgets,
    summary,
    canCreate: canCreateProp = false,
    formOptions = null,
}: Props) {
    const filters = useUrlFilters();
    const [search, setSearch] = useState(filters.search ?? '');
    const ctxMenu = useEntityContextMenu<BudgetRow>();
    const canCreate = Boolean(canCreateProp && formOptions);
    const [createOpen, setCreateOpen] = useDialogDeepLink('create', canCreate);

    const go = (next: Partial<typeof filters>) => {
        const merged = { ...filters, ...next };
        const query = Object.fromEntries(
            Object.entries(merged).filter(([, value]) => value),
        );
        router.get('/governance/budgets', query, {
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

    const years = useMemo(
        () =>
            Array.from(
                new Set(budgets.map((budget) => budget.financial_year_label)),
            )
                .sort()
                .reverse(),
        [budgets],
    );

    const visible = budgets.filter((budget) => {
        if (filters.status === 'pending' && !WAITING.includes(budget.status))
            return false;
        if (
            filters.status &&
            filters.status !== 'pending' &&
            budget.status !== filters.status
        )
            return false;
        if (filters.year && budget.financial_year_label !== filters.year)
            return false;
        if (filters.search) {
            const q = filters.search.toLowerCase();
            const haystack =
                `${budget.display_name} ${budget.financial_year_label}`.toLowerCase();
            if (!haystack.includes(q)) return false;
        }
        return true;
    });

    const hasFilters = Boolean(filters.status || filters.year || filters.search);
    const thisYearHref = `/governance/budgets?status=approved&year=${encodeURIComponent(summary.financial_year)}`;
    const budgeted = summary.budgeted_this_year;
    const spent = summary.spent_this_year;

    const open = (budget: BudgetRow) =>
        router.visit(`/governance/budgets/${budget.id}`);
    const actionsFor = (budget: BudgetRow): MenuItem[] =>
        compactMenu([
            {
                label: 'Open budget',
                icon: ExternalLink,
                onClick: () => open(budget),
            },
        ]);

    const spentMeter = () => {
        if (summary.approved_this_year === 0) {
            return (
                <>
                    <PageHeaderMeterBig>None</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        No approved budget for {summary.financial_year}
                    </PageHeaderMeterCaption>
                </>
            );
        }
        if (!summary.actuals_recorded_this_year) {
            return (
                <>
                    <PageHeaderMeterBig>Not recorded</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        Actual spend not recorded yet
                    </PageHeaderMeterCaption>
                </>
            );
        }
        return (
            <>
                <PageHeaderMeterBig>{formatNzd(spent)}</PageHeaderMeterBig>
                {budgeted > 0 ? (
                    <PageHeaderMeterBar percent={(spent / budgeted) * 100} />
                ) : null}
                <PageHeaderMeterCaption>
                    {spent > budgeted
                        ? `Over budget by ${formatNzd(spent - budgeted)}`
                        : `${formatNzd(budgeted - spent)} left of ${formatNzd(budgeted)}`}
                </PageHeaderMeterCaption>
            </>
        );
    };

    const header = (
        <PageHeader
            icon={Wallet}
            title="Budgets"
            subline={`The yearly spending plan the board approves · This financial year is ${summary.financial_year}`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search budgets…"
                    />
                    {canCreate ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New budget
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Budgets"
                        href="/governance/budgets"
                    >
                        <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.drafts > 0
                                ? `${summary.drafts} still a draft`
                                : `Across ${years.length} financial year${years.length === 1 ? '' : 's'}`}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Approved this year"
                        href={thisYearHref}
                        tone={summary.approved_this_year > 0 ? 'success' : 'brand'}
                        ariaLabel={`View budgets approved for ${summary.financial_year}`}
                    >
                        <PageHeaderMeterBig>
                            {summary.approved_this_year}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Financial year {summary.financial_year}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Waiting for the board"
                        href="/governance/budgets?status=pending"
                        tone={summary.waiting > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>{summary.waiting}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Sent to the board for a decision
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label={`Spent this year (${summary.financial_year})`}
                        href={thisYearHref}
                        tone={
                            summary.actuals_recorded_this_year && spent > budgeted
                                ? 'warning'
                                : 'brand'
                        }
                        ariaLabel={`View spending against approved budgets for ${summary.financial_year}`}
                    >
                        {spentMeter()}
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
                        label="Financial year"
                        value={filters.year ?? ALL}
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'Any year' },
                            ...years.map((year) => ({
                                value: year,
                                label: year,
                            })),
                        ]}
                        onChange={(value) =>
                            go({ year: value === ALL ? null : value })
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
                { title: 'Budgets', href: '/governance/budgets' },
            ]}
        >
            <Head title="Budgets" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Budgets"
                        caption={`${visible.length} of ${budgets.length} shown`}
                    />

                    {visible.length === 0 ? (
                        <EmptyState
                            icon={Wallet}
                            title={
                                hasFilters
                                    ? 'No budgets match your filters'
                                    : 'No budgets yet'
                            }
                            description={
                                hasFilters
                                    ? 'Try clearing a filter or search term.'
                                    : 'A budget is the yearly spending plan the board approves.'
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
                                                year: null,
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
                                        New budget
                                    </Button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <EntityTable
                            rows={visible}
                            rowKey={(budget) => budget.id}
                            identityLabel="Budget"
                            identity={(budget) => ({
                                icon: Wallet,
                                name: budget.display_name,
                                subline: `Financial year ${budget.financial_year_label} · ${versionText(budget)}`,
                            })}
                            hrefFor={(budget) =>
                                `/governance/budgets/${budget.id}`
                            }
                            onOpen={open}
                            onRowContextMenu={ctxMenu.open}
                            actionsFor={actionsFor}
                            columns={[
                                {
                                    key: 'status',
                                    label: 'Status',
                                    width: '1fr',
                                    cell: (budget) => {
                                        const chip = governanceStatus(
                                            'budget_status',
                                            budget.status,
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
                                    key: 'total',
                                    label: 'Total budget',
                                    width: '0.9fr',
                                    align: 'right',
                                    cell: (budget) => (
                                        <span className="font-semibold tabular-nums">
                                            {formatNzd(budget.total_budget)}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'spend',
                                    label: 'Spent so far',
                                    width: '1.4fr',
                                    cell: (budget) => {
                                        if (budget.status !== 'approved') {
                                            return <EmptyValue />;
                                        }
                                        if (!budget.actuals_recorded) {
                                            return (
                                                <span className="text-caption">
                                                    Actual spend not recorded
                                                    yet
                                                </span>
                                            );
                                        }
                                        const pct =
                                            budget.total_allocated > 0
                                                ? (budget.total_actual /
                                                      budget.total_allocated) *
                                                  100
                                                : null;
                                        return (
                                            <ProgressValue
                                                percent={pct}
                                                tone={
                                                    pct != null && pct > 100
                                                        ? 'critical'
                                                        : 'brand'
                                                }
                                            >
                                                {formatNzd(budget.total_actual)}{' '}
                                                of{' '}
                                                {formatNzd(
                                                    budget.total_allocated,
                                                )}
                                            </ProgressValue>
                                        );
                                    },
                                },
                                {
                                    key: 'lines',
                                    label: 'Lines',
                                    width: '0.5fr',
                                    align: 'right',
                                    cell: (budget) => (
                                        <span className="tabular-nums">
                                            {budget.line_items_count || 0}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'approved',
                                    label: 'Approved',
                                    width: '0.9fr',
                                    cell: (budget) =>
                                        budget.approved_by_board_at ? (
                                            <EntityChip>
                                                {formatDateLong(
                                                    budget.approved_by_board_at,
                                                )}
                                            </EntityChip>
                                        ) : (
                                            <EmptyValue />
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
                    icon={Wallet}
                    title={ctxMenu.ctx.record.display_name}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {canCreate && formOptions ? (
                <BudgetWizardDialog
                    isOpen={createOpen}
                    onClose={() => setCreateOpen(false)}
                    options={formOptions}
                />
            ) : null}
        </AppLayout>
    );
}
