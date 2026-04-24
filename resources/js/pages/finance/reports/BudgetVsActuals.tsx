import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { RefreshCw, DollarSign, TrendingUp, TrendingDown, BarChart3 } from 'lucide-react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { useState, useMemo } from 'react';

type LineItem = {
    id: number;
    description: string;
    subcategory: string | null;
    account_code: string | null;
    budget_amount: number;
    actual_amount: number;
    variance_amount: number;
    variance_pct: number;
    variance_color: 'green' | 'yellow' | 'red';
    variance_explained: boolean;
    variance_explanation: string | null;
};

type CategorySubtotals = {
    budget_amount: number;
    actual_amount: number;
    variance_amount: number;
    variance_pct: number;
    variance_color: 'green' | 'yellow' | 'red';
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

const formatNZD = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const formatPct = (pct: number) => `${pct >= 0 ? '+' : ''}${pct.toFixed(1)}%`;

const varianceColorClasses: Record<string, string> = {
    green: 'text-status-success bg-status-success-bg dark:text-status-success dark:bg-status-success',
    yellow: 'text-status-warning bg-status-warning-bg dark:text-status-warning dark:bg-status-warning',
    red: 'text-status-critical bg-status-critical-bg dark:text-status-critical dark:bg-status-critical',
};

const varianceBadgeClasses: Record<string, string> = {
    green: 'bg-status-success-bg text-status-success border-status-success/30 dark:bg-status-success-bg dark:text-status-success dark:border-status-success/30',
    yellow: 'bg-status-warning-bg text-status-warning border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning dark:border-status-warning/30',
    red: 'bg-status-critical-bg text-status-critical border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical dark:border-status-critical/30',
};

const categoryLabels: Record<string, string> = {
    staffing: 'Staffing',
    operations: 'Operations',
    fleet: 'Fleet',
    compliance: 'Compliance',
    capital: 'Capital',
    admin: 'Administration',
    other: 'Other',
};

function ProgressBar({ value, color }: { value: number; color: string }) {
    const capped = Math.min(Math.max(value, 0), 150);
    const barColor =
        color === 'red'
            ? 'bg-status-critical'
            : color === 'yellow'
              ? 'bg-status-warning'
              : 'bg-status-success';
    const overBudget = value > 100;

    return (
        <div className="flex items-center gap-2">
            <div className="relative h-2 w-24 rounded-full bg-muted overflow-hidden">
                {overBudget && (
                    <div className="absolute inset-0 h-full w-full bg-muted" />
                )}
                <div
                    className={`absolute inset-y-0 left-0 h-full rounded-full transition-all ${barColor}`}
                    style={{ width: `${Math.min(capped, 100) * (100 / (overBudget ? 150 : 100))}%` }}
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
            <span className="text-xs tabular-nums text-muted-foreground w-12 text-right">
                {value.toFixed(0)}%
            </span>
        </div>
    );
}

function SummaryCard({
    title,
    value,
    subtitle,
    icon: Icon,
    color,
}: {
    title: string;
    value: string;
    subtitle?: string;
    icon: React.ElementType;
    color?: string;
}) {
    return (
        <Card>
            <CardContent className="pt-6">
                <div className="flex items-center justify-between">
                    <div className="space-y-1">
                        <p className="text-sm text-muted-foreground">{title}</p>
                        <p className={`text-2xl font-bold tabular-nums ${color || ''}`}>{value}</p>
                        {subtitle && (
                            <p className="text-xs text-muted-foreground">{subtitle}</p>
                        )}
                    </div>
                    <div className="rounded-lg bg-muted p-3">
                        <Icon className="h-5 w-5 text-muted-foreground" />
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

export default function BudgetVsActuals({ budgets, selectedBudgetId, report }: PageProps) {
    const { flash } = usePage<PageProps>().props;
    const [syncing, setSyncing] = useState(false);

    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Budget vs Actuals', href: '/finance/reports/budget-vs-actuals' },
    ];

    const handleBudgetChange = (value: string) => {
        router.get(
            '/finance/reports/budget-vs-actuals',
            { budget_id: value },
            { preserveState: true },
        );
    };

    const handleSync = () => {
        setSyncing(true);
        router.post('/finance/reports/budget-vs-actuals/sync', {}, {
            preserveScroll: true,
            onFinish: () => setSyncing(false),
        });
    };

    const { totals, categories } = report;
    const hasBudget = !!report.budget;

    const overallColor = Math.abs(totals.variance_pct) >= 10
        ? 'text-status-critical'
        : Math.abs(totals.variance_pct) >= 5
          ? 'text-status-warning'
          : 'text-status-success';

    const chartData = useMemo(() =>
        categories.map(cat => {
            const label = categoryLabels[cat.name] || cat.name;
            return {
                name: label,
                Budget: cat.subtotals.budget_amount,
                Actual: cat.subtotals.actual_amount,
            };
        }),
        [categories],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Budget vs Actuals" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                {/* Header */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Budget vs Actuals</h1>
                        <p className="text-muted-foreground">
                            {report.budget
                                ? `${report.budget.title || 'Budget'} - FY${report.budget.fiscal_year}`
                                : 'Compare budgeted amounts against actual GL transactions'}
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <Select
                            value={selectedBudgetId?.toString() ?? ''}
                            onValueChange={handleBudgetChange}
                        >
                            <SelectTrigger className="w-[260px]">
                                <SelectValue placeholder="Select a budget" />
                            </SelectTrigger>
                            <SelectContent>
                                {budgets.map((b) => (
                                    <SelectItem key={b.id} value={b.id.toString()}>
                                        <span className="flex items-center gap-2">
                                            {b.label}
                                            {b.status === 'approved' && (
                                                <Badge variant="outline" className="text-xs bg-status-success-bg text-status-success border-status-success/30">
                                                    Approved
                                                </Badge>
                                            )}
                                        </span>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Button
                            variant="outline"
                            onClick={handleSync}
                            disabled={syncing}
                        >
                            <RefreshCw className={`mr-2 h-4 w-4 ${syncing ? 'animate-spin' : ''}`} />
                            Sync Actuals
                        </Button>
                    </div>
                </div>

                {/* Flash message */}
                {flash?.success && (
                    <div className="rounded-lg border border-status-success/30 bg-status-success-bg p-4 text-sm text-status-success dark:border-status-success/30 dark:bg-status-success-bg dark:text-status-success">
                        {flash.success}
                    </div>
                )}

                {/* Summary cards */}
                {hasBudget && (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <SummaryCard
                            title="Total Budget"
                            value={formatNZD(totals.budget_amount)}
                            subtitle={`FY${report.budget!.fiscal_year}`}
                            icon={DollarSign}
                        />
                        <SummaryCard
                            title="Total Actual"
                            value={formatNZD(totals.actual_amount)}
                            subtitle="From posted journals"
                            icon={BarChart3}
                        />
                        <SummaryCard
                            title="Overall Variance"
                            value={formatPct(totals.variance_pct)}
                            subtitle={formatNZD(totals.variance_amount)}
                            icon={totals.variance_amount >= 0 ? TrendingUp : TrendingDown}
                            color={overallColor}
                        />
                        <SummaryCard
                            title="Budget Utilisation"
                            value={`${totals.utilization_pct.toFixed(1)}%`}
                            subtitle={`${formatNZD(totals.actual_amount)} of ${formatNZD(totals.budget_amount)}`}
                            icon={BarChart3}
                        />
                    </div>
                )}

                {/* Budget vs Actual chart */}
                {hasBudget && categories.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Budget vs Actual by Category</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-72">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={chartData} margin={{ top: 5, right: 20, left: 20, bottom: 5 }}>
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis dataKey="name" tick={{ fontSize: 12 }} />
                                        <YAxis tickFormatter={(v) => formatNZD(v)} />
                                        <Tooltip formatter={(value) => formatNZD(value as number)} />
                                        <Legend />
                                        <Bar dataKey="Budget" fill="#3b82f6" radius={[4, 4, 0, 0]} />
                                        <Bar dataKey="Actual" fill="#10b981" radius={[4, 4, 0, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Main report table */}
                {hasBudget ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Budget Line Items by Category</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-[300px]">Description</TableHead>
                                        <TableHead className="text-right">Budget</TableHead>
                                        <TableHead className="text-right">Actual</TableHead>
                                        <TableHead className="text-right">Variance ($)</TableHead>
                                        <TableHead className="text-right">Variance (%)</TableHead>
                                        <TableHead className="text-center">Status</TableHead>
                                        <TableHead>Utilisation</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {categories.map((category) => (
                                        <CategorySection key={category.name} category={category} />
                                    ))}
                                    {/* Grand total row */}
                                    <TableRow className="bg-muted/50 font-bold border-t-2">
                                        <TableCell className="font-bold">Grand Total</TableCell>
                                        <TableCell className="text-right font-mono tabular-nums">
                                            {formatNZD(totals.budget_amount)}
                                        </TableCell>
                                        <TableCell className="text-right font-mono tabular-nums">
                                            {formatNZD(totals.actual_amount)}
                                        </TableCell>
                                        <TableCell className="text-right font-mono tabular-nums">
                                            {formatNZD(totals.variance_amount)}
                                        </TableCell>
                                        <TableCell className="text-right font-mono tabular-nums">
                                            <span className={overallColor}>
                                                {formatPct(totals.variance_pct)}
                                            </span>
                                        </TableCell>
                                        <TableCell />
                                        <TableCell>
                                            <ProgressBar
                                                value={totals.utilization_pct}
                                                color={
                                                    Math.abs(totals.variance_pct) >= 10
                                                        ? 'red'
                                                        : Math.abs(totals.variance_pct) >= 5
                                                          ? 'yellow'
                                                          : 'green'
                                                }
                                            />
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                ) : (
                    <Card>
                        <CardContent className="py-12 text-center">
                            <DollarSign className="mx-auto h-12 w-12 text-muted-foreground/40" />
                            <h3 className="mt-4 text-lg font-medium">No budget found</h3>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Select a budget from the dropdown above, or create an approved budget in the
                                Governance module to get started.
                            </p>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}

function CategorySection({ category }: { category: Category }) {
    const { subtotals } = category;
    const label = categoryLabels[category.name] || category.name;

    return (
        <>
            {/* Category header */}
            <TableRow className="bg-muted/30 hover:bg-muted/40">
                <TableCell colSpan={7} className="font-semibold text-sm">
                    {label}
                </TableCell>
            </TableRow>

            {/* Line items */}
            {category.line_items.map((item) => (
                <TableRow key={item.id}>
                    <TableCell className="pl-8">
                        <div>
                            <span className="text-sm">{item.description}</span>
                            {item.account_code && (
                                <span className="ml-2 text-xs text-muted-foreground font-mono">
                                    ({item.account_code})
                                </span>
                            )}
                        </div>
                    </TableCell>
                    <TableCell className="text-right font-mono tabular-nums text-sm">
                        {formatNZD(item.budget_amount)}
                    </TableCell>
                    <TableCell className="text-right font-mono tabular-nums text-sm">
                        {formatNZD(item.actual_amount)}
                    </TableCell>
                    <TableCell className="text-right font-mono tabular-nums text-sm">
                        <span className={varianceColorClasses[item.variance_color]?.split(' ')[0] || ''}>
                            {formatNZD(item.variance_amount)}
                        </span>
                    </TableCell>
                    <TableCell className="text-right font-mono tabular-nums text-sm">
                        <Badge
                            variant="outline"
                            className={`text-xs ${varianceBadgeClasses[item.variance_color] || ''}`}
                        >
                            {formatPct(item.variance_pct)}
                        </Badge>
                    </TableCell>
                    <TableCell className="text-center">
                        {item.variance_explained ? (
                            <Badge variant="outline" className="text-xs bg-status-info-bg text-status-info border-status-info/30 dark:bg-status-info-bg dark:text-status-info">
                                Explained
                            </Badge>
                        ) : Math.abs(item.variance_pct) >= 5 ? (
                            <Badge variant="outline" className="text-xs bg-status-warning-bg text-status-warning border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning">
                                Review
                            </Badge>
                        ) : null}
                    </TableCell>
                    <TableCell>
                        <ProgressBar
                            value={
                                item.budget_amount !== 0
                                    ? (item.actual_amount / item.budget_amount) * 100
                                    : 0
                            }
                            color={item.variance_color}
                        />
                    </TableCell>
                </TableRow>
            ))}

            {/* Category subtotal */}
            <TableRow className="border-t bg-muted/10 font-medium">
                <TableCell className="pl-8 italic text-sm text-muted-foreground">
                    {label} Subtotal
                </TableCell>
                <TableCell className="text-right font-mono tabular-nums text-sm font-semibold">
                    {formatNZD(subtotals.budget_amount)}
                </TableCell>
                <TableCell className="text-right font-mono tabular-nums text-sm font-semibold">
                    {formatNZD(subtotals.actual_amount)}
                </TableCell>
                <TableCell className="text-right font-mono tabular-nums text-sm font-semibold">
                    <span className={varianceColorClasses[subtotals.variance_color]?.split(' ')[0] || ''}>
                        {formatNZD(subtotals.variance_amount)}
                    </span>
                </TableCell>
                <TableCell className="text-right font-mono tabular-nums text-sm">
                    <Badge
                        variant="outline"
                        className={`text-xs ${varianceBadgeClasses[subtotals.variance_color] || ''}`}
                    >
                        {formatPct(subtotals.variance_pct)}
                    </Badge>
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
