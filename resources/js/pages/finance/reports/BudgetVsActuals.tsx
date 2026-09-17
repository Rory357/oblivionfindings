import { chartColor } from '@/components/finance/chart-palette';
import { formatMoney } from '@/components/finance/money';
import {
    FinanceReportPage,
    ReportCard,
} from '@/components/finance/report-page';
import {
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
} from '@/components/page';
import { EmptyList } from '@/components/ui/empty-state';
import { LoadingState } from '@/components/ui/loading-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { router, usePage } from '@inertiajs/react';
import { BarChart3, Printer, RefreshCw } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type VarianceColor = 'green' | 'yellow' | 'red';

type LineItem = {
    id: number;
    description: string;
    subcategory: string | null;
    account_code: string | null;
    budget_amount: number;
    actual_amount: number;
    variance_amount: number;
    variance_pct: number;
    variance_color: VarianceColor;
    variance_explained: boolean;
    variance_explanation: string | null;
};

type CategorySubtotals = {
    budget_amount: number;
    actual_amount: number;
    variance_amount: number;
    variance_pct: number;
    variance_color: VarianceColor;
    utilization_pct: number;
};

type Category = {
    name: string;
    line_items: LineItem[];
    subtotals: CategorySubtotals;
};

type BudgetInfo = {
    id: number;
    fiscal_year: string;
    title: string | null;
    status: string;
    currency: string;
    approved_at: string | null;
};

type Totals = {
    budget_amount: number;
    actual_amount: number;
    variance_amount: number;
    variance_pct: number;
    utilization_pct: number;
};

type BudgetOption = {
    id: number;
    label: string;
    fiscal_year: string;
    status: string;
};

type PageProps = {
    budgets: BudgetOption[];
    selectedBudgetId: number | null;
    report: {
        budget: BudgetInfo | null;
        categories: Category[];
        totals: Totals;
    };
    flash?: { success?: string };
};

const URL = '/finance/reports/budget-vs-actuals';

const formatPct = (pct: number) => `${pct >= 0 ? '+' : ''}${pct.toFixed(1)}%`;

/**
 * One variance scale for the whole page: the server's green/yellow/red
 * becomes a StatusBadge variant, a text token and a bar token — replacing
 * the five ad-hoc colour systems this page used to carry.
 */
const VARIANCE_VARIANT: Record<VarianceColor, StatusVariant> = {
    green: 'success',
    yellow: 'warning',
    red: 'critical',
};

const VARIANCE_TEXT: Record<VarianceColor, string> = {
    green: 'text-status-success',
    yellow: 'text-status-warning',
    red: 'text-status-critical',
};

const VARIANCE_BAR: Record<VarianceColor, string> = {
    green: 'bg-status-success',
    yellow: 'bg-status-warning',
    red: 'bg-status-critical',
};

const varianceColorFor = (variancePct: number): VarianceColor =>
    Math.abs(variancePct) >= 10
        ? 'red'
        : Math.abs(variancePct) >= 5
          ? 'yellow'
          : 'green';

const categoryLabels: Record<string, string> = {
    staffing: 'Staffing',
    operations: 'Operations',
    fleet: 'Fleet',
    compliance: 'Compliance',
    capital: 'Capital',
    admin: 'Administration',
    other: 'Other',
};

/** A line item's utilisation — 0 when it carries no budget. */
const utilisationOf = (item: LineItem) =>
    item.budget_amount !== 0
        ? (item.actual_amount / item.budget_amount) * 100
        : 0;

function ProgressBar({
    value,
    color,
}: {
    value: number;
    color: VarianceColor;
}) {
    const capped = Math.min(Math.max(value, 0), 150);
    const overBudget = value > 100;

    return (
        <div className="flex items-center gap-2">
            <div
                className="relative h-2 w-24 overflow-hidden rounded-full bg-muted"
                role="img"
                aria-label={`${value.toFixed(0)}% of budget used`}
            >
                <div
                    className={`absolute inset-y-0 left-0 h-full rounded-full transition-all ${VARIANCE_BAR[color]}`}
                    style={{
                        width: `${Math.min(capped, 100) * (100 / (overBudget ? 150 : 100))}%`,
                    }}
                />
                {overBudget && (
                    <div
                        className="absolute inset-y-0 h-full rounded-r-full bg-status-critical"
                        style={{
                            left: `${(100 / 150) * 100}%`,
                            width: `${((capped - 100) / 150) * 100}%`,
                        }}
                    />
                )}
            </div>
            <span className="w-12 text-right text-caption tabular-nums">
                {value.toFixed(0)}%
            </span>
        </div>
    );
}

export default function BudgetVsActuals({
    budgets,
    selectedBudgetId,
    report,
}: PageProps) {
    const { flash } = usePage<PageProps>().props;
    const [syncing, setSyncing] = useState(false);

    const { totals, categories } = report;
    const hasBudget = !!report.budget;

    const handleBudgetChange = (value: string) => {
        router.get(URL, { budget_id: value }, { preserveState: true });
    };

    const handleSync = () => {
        setSyncing(true);
        router.post(
            `${URL}/sync`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setSyncing(false),
            },
        );
    };

    const overallColor = varianceColorFor(totals.variance_pct);

    const remaining = totals.budget_amount - totals.actual_amount;
    const overBudget = remaining < 0;

    const needsReview = useMemo(
        () =>
            categories.reduce(
                (count, category) =>
                    count +
                    category.line_items.filter(
                        (item) =>
                            !item.variance_explained &&
                            Math.abs(item.variance_pct) >= 5,
                    ).length,
                0,
            ),
        [categories],
    );

    const lineItemCount = useMemo(
        () =>
            categories.reduce(
                (count, category) => count + category.line_items.length,
                0,
            ),
        [categories],
    );

    const chartData = useMemo(
        () =>
            categories.map((cat) => ({
                name: categoryLabels[cat.name] || cat.name,
                Budget: cat.subtotals.budget_amount,
                Actual: cat.subtotals.actual_amount,
            })),
        [categories],
    );

    const budgetHref = report.budget
        ? `/governance/budgets/${report.budget.id}`
        : '/governance/budgets';

    const meters = hasBudget ? (
        <>
            <PageHeaderMeterBlock
                label="Total budget"
                href={budgetHref}
                ariaLabel="View the budget in Governance"
            >
                <PageHeaderMeterBig>
                    {formatMoney(totals.budget_amount)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    FY{report.budget!.fiscal_year} · {lineItemCount} line items
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Actual spend"
                tone={overBudget ? 'warning' : 'brand'}
                href="/finance/journals"
                ariaLabel="View the posted journals behind the actuals"
            >
                <PageHeaderMeterBig>
                    {formatMoney(totals.actual_amount)}
                </PageHeaderMeterBig>
                <PageHeaderMeterBar percent={totals.utilization_pct} />
                <PageHeaderMeterCaption>
                    {overBudget
                        ? `${formatMoney(Math.abs(remaining))} over ${formatMoney(totals.budget_amount)}`
                        : `${formatMoney(remaining)} left of ${formatMoney(totals.budget_amount)}`}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Variance"
                tone={
                    overallColor === 'green'
                        ? 'success'
                        : overallColor === 'yellow'
                          ? 'warning'
                          : 'critical'
                }
                href="/finance/journals"
                ariaLabel="View the posted journals behind the variance"
            >
                <PageHeaderMeterBig>
                    {formatPct(totals.variance_pct)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {formatMoney(totals.variance_amount)} against budget
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Needs an explanation"
                tone={needsReview > 0 ? 'warning' : 'success'}
                href={budgetHref}
                ariaLabel="View the budget, where variance explanations are recorded"
            >
                <PageHeaderMeterBig>{needsReview}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Line items off budget by 5% or more
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    ) : undefined;

    return (
        <FinanceReportPage
            icon={BarChart3}
            title="Budget vs actuals"
            chip={
                report.budget ? (
                    <PageHeaderStatusChip
                        variant={
                            report.budget.status === 'approved'
                                ? 'success'
                                : 'neutral'
                        }
                    >
                        FY{report.budget.fiscal_year}
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="neutral">
                        No budget selected
                    </PageHeaderStatusChip>
                )
            }
            subline={
                report.budget
                    ? `${report.budget.title || 'Budget'} · ${categories.length} categories · actuals from posted journals`
                    : 'Budgeted amounts compared against actual ledger transactions'
            }
            actions={
                <>
                    <PageHeaderGlassButton
                        icon={Printer}
                        onClick={() => window.print()}
                    >
                        Print
                    </PageHeaderGlassButton>
                    <PageHeaderGlassButton
                        icon={RefreshCw}
                        onClick={handleSync}
                        disabled={syncing}
                    >
                        {syncing ? 'Syncing actuals…' : 'Sync actuals'}
                    </PageHeaderGlassButton>
                </>
            }
            meters={meters}
            filters={
                <PageHeaderFilterSelect
                    label="Budget"
                    value={selectedBudgetId ? String(selectedBudgetId) : ''}
                    allValue=""
                    options={budgets.map((b) => ({
                        value: String(b.id),
                        label: b.label,
                    }))}
                    onChange={handleBudgetChange}
                />
            }
        >
            {flash?.success && (
                <div className="rounded-lg border border-status-success/30 bg-status-success-bg p-4 text-sm text-status-success">
                    {flash.success}
                </div>
            )}

            {syncing ? (
                <LoadingState message="Syncing actuals from the posted journals…" />
            ) : !hasBudget ? (
                <EmptyList
                    icon={BarChart3}
                    itemName="budget"
                    title="No budget found"
                    description="Pick a budget from the header, or create an approved budget in the Governance module to compare against."
                />
            ) : (
                <>
                    {categories.length > 0 && (
                        <ReportCard
                            title="Budget vs actual by category"
                            scroll={false}
                        >
                            <div className="h-72">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart
                                        data={chartData}
                                        margin={{
                                            top: 5,
                                            right: 20,
                                            left: 20,
                                            bottom: 5,
                                        }}
                                    >
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis
                                            dataKey="name"
                                            tick={{ fontSize: 12 }}
                                        />
                                        <YAxis
                                            tickFormatter={(v) =>
                                                formatMoney(v)
                                            }
                                        />
                                        <Tooltip
                                            formatter={(value) =>
                                                formatMoney(value as number)
                                            }
                                        />
                                        <Legend />
                                        <Bar
                                            dataKey="Budget"
                                            fill={chartColor(0)}
                                            radius={[4, 4, 0, 0]}
                                        />
                                        <Bar
                                            dataKey="Actual"
                                            fill={chartColor(1)}
                                            radius={[4, 4, 0, 0]}
                                        />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </ReportCard>
                    )}

                    <ReportCard
                        title="Budget line items by category"
                        caption={`${lineItemCount} line items across ${categories.length} categories`}
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-[300px]">
                                        Description
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Budget
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Actual
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Variance ($)
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Variance (%)
                                    </TableHead>
                                    <TableHead className="text-center">
                                        Status
                                    </TableHead>
                                    <TableHead>Utilisation</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {categories.map((category) => (
                                    <CategorySection
                                        key={category.name}
                                        category={category}
                                    />
                                ))}
                                <TableRow className="border-t-2 bg-muted/50 font-bold">
                                    <TableCell className="font-bold">
                                        Grand total
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(totals.budget_amount)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(totals.actual_amount)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(totals.variance_amount)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        <span
                                            className={
                                                VARIANCE_TEXT[overallColor]
                                            }
                                        >
                                            {formatPct(totals.variance_pct)}
                                        </span>
                                    </TableCell>
                                    <TableCell />
                                    <TableCell>
                                        <ProgressBar
                                            value={totals.utilization_pct}
                                            color={overallColor}
                                        />
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </ReportCard>
                </>
            )}
        </FinanceReportPage>
    );
}

function CategorySection({ category }: { category: Category }) {
    const { subtotals } = category;
    const label = categoryLabels[category.name] || category.name;

    return (
        <>
            <TableRow className="bg-muted/30 hover:bg-muted/40">
                <TableCell colSpan={7} className="text-sm font-semibold">
                    {label}
                </TableCell>
            </TableRow>

            {category.line_items.map((item) => (
                <TableRow key={item.id}>
                    <TableCell className="pl-8">
                        <span className="text-sm">{item.description}</span>
                        {item.account_code && (
                            <span className="ml-2 font-mono text-xs text-muted-foreground">
                                ({item.account_code})
                            </span>
                        )}
                    </TableCell>
                    <TableCell className="text-right text-sm tabular-nums">
                        {formatMoney(item.budget_amount)}
                    </TableCell>
                    <TableCell className="text-right text-sm tabular-nums">
                        {formatMoney(item.actual_amount)}
                    </TableCell>
                    <TableCell
                        className={`text-right text-sm tabular-nums ${VARIANCE_TEXT[item.variance_color]}`}
                    >
                        {formatMoney(item.variance_amount)}
                    </TableCell>
                    <TableCell className="text-right text-sm tabular-nums">
                        <StatusBadge
                            size="sm"
                            variant={VARIANCE_VARIANT[item.variance_color]}
                        >
                            {formatPct(item.variance_pct)}
                        </StatusBadge>
                    </TableCell>
                    <TableCell className="text-center">
                        {item.variance_explained ? (
                            <StatusBadge size="sm" variant="info">
                                Explained
                            </StatusBadge>
                        ) : Math.abs(item.variance_pct) >= 5 ? (
                            <StatusBadge size="sm" variant="warning">
                                Needs a reason
                            </StatusBadge>
                        ) : null}
                    </TableCell>
                    <TableCell>
                        <ProgressBar
                            value={utilisationOf(item)}
                            color={item.variance_color}
                        />
                    </TableCell>
                </TableRow>
            ))}

            <TableRow className="border-t bg-muted/10 font-medium">
                <TableCell className="pl-8 text-sm text-muted-foreground italic">
                    {label} subtotal
                </TableCell>
                <TableCell className="text-right text-sm font-semibold tabular-nums">
                    {formatMoney(subtotals.budget_amount)}
                </TableCell>
                <TableCell className="text-right text-sm font-semibold tabular-nums">
                    {formatMoney(subtotals.actual_amount)}
                </TableCell>
                <TableCell
                    className={`text-right text-sm font-semibold tabular-nums ${VARIANCE_TEXT[subtotals.variance_color]}`}
                >
                    {formatMoney(subtotals.variance_amount)}
                </TableCell>
                <TableCell className="text-right text-sm tabular-nums">
                    <StatusBadge
                        size="sm"
                        variant={VARIANCE_VARIANT[subtotals.variance_color]}
                    >
                        {formatPct(subtotals.variance_pct)}
                    </StatusBadge>
                </TableCell>
                <TableCell />
                <TableCell>
                    <ProgressBar
                        value={subtotals.utilization_pct}
                        color={subtotals.variance_color}
                    />
                </TableCell>
            </TableRow>
        </>
    );
}
