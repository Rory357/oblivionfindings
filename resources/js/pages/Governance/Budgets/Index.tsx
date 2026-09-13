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
import type { StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { PageProps } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ExternalLink, Plus, Wallet, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { BudgetWizardDialog, type BudgetFormOptions } from './_dialogs';

interface Budget {
    id: number;
    fiscal_year: string;
    title: string | null;
    total_budget: number;
    status: string;
    version_number: number;
    approved_by_board_at: string | null;
    line_items_count: number;
    total_allocated: number;
    total_actual: number;
}

interface Props extends PageProps {
    budgets: Budget[] | { data: Budget[] };
    canCreate?: boolean;
    /** New-budget wizard options — only sent to viewers who may create. */
    formOptions?: BudgetFormOptions | null;
}

const ALL = '__all';
const PENDING = ['proposed', 'under_review'];

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'drafting', label: 'Drafting' },
    { value: 'pending', label: 'Pending review' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
];

function budgetStatusVariant(status: string): StatusVariant {
    if (status === 'approved') return 'success';
    if (status === 'rejected') return 'critical';
    if (PENDING.includes(status)) return 'warning';
    return 'neutral';
}

const humanise = (value: string) =>
    value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, ' ');

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
        maximumFractionDigits: 0,
    }).format(Number(amount) || 0);

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
        fiscal_year: params.get('fiscal_year'),
        search: params.get('search'),
    };
}

export default function BudgetsIndex({
    budgets,
    canCreate: canCreateProp = false,
    formOptions = null,
}: Props) {
    const budgetItems = useMemo(
        () => (Array.isArray(budgets) ? budgets : (budgets?.data ?? [])),
        [budgets],
    );
    const filters = useUrlFilters();
    const [search, setSearch] = useState(filters.search ?? '');
    const ctxMenu = useEntityContextMenu<Budget>();
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

    const fiscalYears = useMemo(
        () =>
            Array.from(new Set(budgetItems.map((b) => String(b.fiscal_year))))
                .sort()
                .reverse(),
        [budgetItems],
    );

    const visible = budgetItems.filter((budget) => {
        if (filters.status === 'pending' && !PENDING.includes(budget.status))
            return false;
        if (
            filters.status &&
            filters.status !== 'pending' &&
            budget.status !== filters.status
        )
            return false;
        if (
            filters.fiscal_year &&
            String(budget.fiscal_year) !== filters.fiscal_year
        )
            return false;
        if (filters.search) {
            const q = filters.search.toLowerCase();
            const haystack =
                `${budget.title ?? ''} ${budget.fiscal_year}`.toLowerCase();
            if (!haystack.includes(q)) return false;
        }
        return true;
    });

    const approved = budgetItems.filter((b) => b.status === 'approved');
    const pending = budgetItems.filter((b) => PENDING.includes(b.status));
    const allocated = budgetItems.reduce(
        (sum, b) => sum + Number(b.total_allocated || 0),
        0,
    );
    const actual = budgetItems.reduce(
        (sum, b) => sum + Number(b.total_actual || 0),
        0,
    );
    const hasFilters = Boolean(
        filters.status || filters.fiscal_year || filters.search,
    );

    const titleFor = (budget: Budget) =>
        budget.title || `Budget ${budget.fiscal_year}`;
    const open = (budget: Budget) =>
        router.visit(`/governance/budgets/${budget.id}`);
    const actionsFor = (budget: Budget): MenuItem[] =>
        compactMenu([
            {
                label: 'Open budget',
                icon: ExternalLink,
                onClick: () => open(budget),
            },
        ]);

    const header = (
        <PageHeader
            icon={Wallet}
            title="Budgets"
            subline="Plan, approve and monitor financial budgets across fiscal years"
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
                        <PageHeaderMeterBig>
                            {budgetItems.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Across {fiscalYears.length} fiscal year
                            {fiscalYears.length === 1 ? '' : 's'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Approved"
                        href="/governance/budgets?status=approved"
                        tone={approved.length > 0 ? 'success' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {approved.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            In effect
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Pending review"
                        href="/governance/budgets?status=pending"
                        tone={pending.length > 0 ? 'warning' : 'brand'}
                    >
                        <PageHeaderMeterBig>
                            {pending.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Proposed or under review
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Spent"
                        value={
                            allocated > 0
                                ? `${Math.round((actual / allocated) * 100)}%`
                                : undefined
                        }
                        href="/governance/budgets"
                        tone={
                            allocated > 0 && actual > allocated
                                ? 'warning'
                                : 'brand'
                        }
                    >
                        <PageHeaderMeterBig>
                            {formatCurrency(actual)}
                        </PageHeaderMeterBig>
                        {allocated > 0 ? (
                            <PageHeaderMeterBar
                                percent={(actual / allocated) * 100}
                            />
                        ) : null}
                        <PageHeaderMeterCaption>
                            {allocated > 0
                                ? actual > allocated
                                    ? `${formatCurrency(actual - allocated)} over ${formatCurrency(allocated)}`
                                    : `${formatCurrency(allocated - actual)} left of ${formatCurrency(allocated)}`
                                : 'No line items budgeted yet'}
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
                        label="Fiscal year"
                        value={filters.fiscal_year ?? ALL}
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'Any year' },
                            ...fiscalYears.map((year) => ({
                                value: year,
                                label: `FY ${year}`,
                            })),
                        ]}
                        onChange={(value) =>
                            go({ fiscal_year: value === ALL ? null : value })
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
                        caption={`${visible.length} of ${budgetItems.length} shown`}
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
                                    : 'Create your first budget to start financial planning.'
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
                                                fiscal_year: null,
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
                                        Create budget
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
                                name: titleFor(budget),
                                subline: `Fiscal year ${budget.fiscal_year}`,
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
                                    width: '0.9fr',
                                    cell: (budget) => (
                                        <EntityStatusChip
                                            variant={budgetStatusVariant(
                                                budget.status,
                                            )}
                                        >
                                            {humanise(budget.status)}
                                        </EntityStatusChip>
                                    ),
                                },
                                {
                                    key: 'version',
                                    label: 'Version',
                                    width: '0.5fr',
                                    cell: (budget) => (
                                        <EntityChip>
                                            v{budget.version_number}
                                        </EntityChip>
                                    ),
                                },
                                {
                                    key: 'total',
                                    label: 'Total budget',
                                    width: '0.9fr',
                                    align: 'right',
                                    cell: (budget) => (
                                        <span className="font-semibold tabular-nums">
                                            {formatCurrency(
                                                budget.total_budget,
                                            )}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'spend',
                                    label: 'Spent of budgeted',
                                    width: '1.4fr',
                                    cell: (budget) => {
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
                                                {formatCurrency(
                                                    budget.total_actual,
                                                )}{' '}
                                                of{' '}
                                                {formatCurrency(
                                                    budget.total_allocated,
                                                )}
                                            </ProgressValue>
                                        );
                                    },
                                },
                                {
                                    key: 'lines',
                                    label: 'Line items',
                                    width: '0.6fr',
                                    align: 'right',
                                    cell: (budget) => (
                                        <span className="tabular-nums">
                                            {budget.line_items_count || 0}
                                        </span>
                                    ),
                                },
                                {
                                    key: 'approval',
                                    label: 'Board approval',
                                    width: '0.9fr',
                                    cell: (budget) =>
                                        budget.approved_by_board_at ? (
                                            <EntityStatusChip variant="success">
                                                Approved
                                            </EntityStatusChip>
                                        ) : (
                                            <EntityStatusChip variant="warning">
                                                Pending approval
                                            </EntityStatusChip>
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
                    title={titleFor(ctxMenu.ctx.record)}
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
