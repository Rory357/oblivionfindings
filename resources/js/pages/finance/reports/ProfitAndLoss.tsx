import { chartColor } from '@/components/finance/chart-palette';
import { FinancePeriodFilter } from '@/components/finance/finance-period-filter';
import { formatMoney } from '@/components/finance/money';
import {
    FinanceReportPage,
    ReportCard,
    formatReportDate,
    reportRangeLabel,
} from '@/components/finance/report-page';
import {
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
} from '@/components/page';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { PageProps } from '@/types';
import { router } from '@inertiajs/react';
import { Printer, TrendingUp } from 'lucide-react';
import { useMemo } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

interface AccountRow {
    account_code: string;
    account_name: string;
    sub_type: string | null;
    amount: number;
}

interface Report {
    start_date: string;
    end_date: string;
    revenue: AccountRow[];
    total_revenue: number;
    expenses: AccountRow[];
    total_expenses: number;
    net_profit: number;
}

interface Props extends PageProps {
    report: Report;
    filters: { start_date: string; end_date: string };
}

const URL = '/finance/reports/profit-loss';

const REVENUE_COLOUR = chartColor(0);
const EXPENSE_COLOUR = chartColor(1);

const truncate = (value: string, max: number) =>
    value.length > max ? `${value.substring(0, max)}…` : value;

export default function ProfitAndLoss({ report, filters }: Props) {
    const inProfit = report.net_profit >= 0;

    const marginPct =
        report.total_revenue > 0
            ? (report.net_profit / report.total_revenue) * 100
            : 0;

    const chartData = useMemo(() => {
        const revenueAccounts = report.revenue.map((r) => ({
            name: truncate(r.account_name, 25),
            amount: Math.abs(r.amount),
            type: 'revenue' as const,
        }));
        const expenseAccounts = report.expenses.map((e) => ({
            name: truncate(e.account_name, 25),
            amount: Math.abs(e.amount),
            type: 'expense' as const,
        }));
        return [...revenueAccounts, ...expenseAccounts]
            .sort((a, b) => b.amount - a.amount)
            .slice(0, 8);
    }, [report.revenue, report.expenses]);

    const meters = (
        <>
            <PageHeaderMeterBlock
                label="Revenue"
                tone="success"
                href="/finance/invoices"
                ariaLabel="View invoices, where revenue is billed"
            >
                <PageHeaderMeterBig>
                    {formatMoney(report.total_revenue)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {report.revenue.length} revenue accounts
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Expenses"
                href="/finance/bills"
                ariaLabel="View bills, where expenses are recorded"
            >
                <PageHeaderMeterBig>
                    {formatMoney(report.total_expenses)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {report.expenses.length} expense accounts
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label={inProfit ? 'Net profit' : 'Net loss'}
                tone={inProfit ? 'success' : 'critical'}
                href="/finance/reports/cash-flow"
                ariaLabel="View the cash flow statement"
            >
                <PageHeaderMeterBig>
                    {formatMoney(report.net_profit)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Revenue less expenses
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Net margin"
                tone={inProfit ? 'success' : 'critical'}
                href="/finance/reports/funding-stream-summary"
                ariaLabel="View margin by funding stream"
            >
                <PageHeaderMeterDonut
                    percent={marginPct}
                    caption={
                        report.total_revenue > 0
                            ? inProfit
                                ? `${formatMoney(report.net_profit)} kept of ${formatMoney(report.total_revenue)}`
                                : `${formatMoney(Math.abs(report.net_profit))} short of breaking even`
                            : 'No revenue in this period'
                    }
                />
            </PageHeaderMeterBlock>
        </>
    );

    return (
        <FinanceReportPage
            icon={TrendingUp}
            title="Profit & loss"
            periodLabel={reportRangeLabel(filters.start_date, filters.end_date)}
            subline={`Revenue and expenses for the period · ${report.revenue.length + report.expenses.length} accounts with movement`}
            actions={
                <PageHeaderGlassButton
                    icon={Printer}
                    onClick={() => window.print()}
                >
                    Print
                </PageHeaderGlassButton>
            }
            meters={meters}
            filters={
                <FinancePeriodFilter
                    url={URL}
                    from={filters.start_date}
                    to={filters.end_date}
                    idPrefix="profit-loss-period"
                    onApply={({ from, to }) =>
                        router.get(
                            URL,
                            { start_date: from, end_date: to },
                            { preserveScroll: true },
                        )
                    }
                />
            }
        >
            {chartData.length > 0 && (
                <ReportCard
                    title="Largest accounts"
                    caption="The eight biggest revenue and expense accounts in the period"
                    scroll={false}
                    right={
                        <div className="flex items-center gap-3 text-caption">
                            <span className="flex items-center gap-1.5">
                                <span
                                    className="inline-block h-3 w-3 rounded-sm"
                                    style={{
                                        backgroundColor: REVENUE_COLOUR,
                                    }}
                                />
                                Revenue
                            </span>
                            <span className="flex items-center gap-1.5">
                                <span
                                    className="inline-block h-3 w-3 rounded-sm"
                                    style={{
                                        backgroundColor: EXPENSE_COLOUR,
                                    }}
                                />
                                Expense
                            </span>
                        </div>
                    }
                >
                    <div className="h-64">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart
                                data={chartData}
                                layout="vertical"
                                margin={{ left: 20, right: 20 }}
                            >
                                <CartesianGrid strokeDasharray="3 3" />
                                <XAxis
                                    type="number"
                                    tickFormatter={(v) => formatMoney(v)}
                                />
                                <YAxis
                                    type="category"
                                    dataKey="name"
                                    width={160}
                                    tick={{ fontSize: 12 }}
                                />
                                <Tooltip
                                    formatter={(value?: number) => [
                                        formatMoney(value ?? 0),
                                        'Amount',
                                    ]}
                                />
                                <Bar dataKey="amount" radius={[0, 4, 4, 0]}>
                                    {chartData.map((entry, index) => (
                                        <Cell
                                            key={`cell-${index}`}
                                            fill={
                                                entry.type === 'revenue'
                                                    ? REVENUE_COLOUR
                                                    : EXPENSE_COLOUR
                                            }
                                        />
                                    ))}
                                </Bar>
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </ReportCard>
            )}

            <ReportCard
                title={`Profit & loss: ${formatReportDate(report.start_date)} to ${formatReportDate(report.end_date)}`}
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-32">Account code</TableHead>
                            <TableHead>Account name</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow className="bg-muted/50">
                            <TableCell colSpan={3} className="font-semibold">
                                Revenue
                            </TableCell>
                        </TableRow>
                        {report.revenue.map((row, idx) => (
                            <TableRow key={`rev-${idx}`}>
                                <TableCell className="font-mono text-sm">
                                    {row.account_code}
                                </TableCell>
                                <TableCell>{row.account_name}</TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatMoney(row.amount)}
                                </TableCell>
                            </TableRow>
                        ))}
                        {report.revenue.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={3}
                                    className="text-muted-foreground"
                                >
                                    No revenue for this period.
                                </TableCell>
                            </TableRow>
                        )}
                        <TableRow className="border-t font-semibold">
                            <TableCell colSpan={2}>Total revenue</TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatMoney(report.total_revenue)}
                            </TableCell>
                        </TableRow>

                        <TableRow className="bg-muted/50">
                            <TableCell colSpan={3} className="font-semibold">
                                Expenses
                            </TableCell>
                        </TableRow>
                        {report.expenses.map((row, idx) => (
                            <TableRow key={`exp-${idx}`}>
                                <TableCell className="font-mono text-sm">
                                    {row.account_code}
                                </TableCell>
                                <TableCell>{row.account_name}</TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatMoney(row.amount)}
                                </TableCell>
                            </TableRow>
                        ))}
                        {report.expenses.length === 0 && (
                            <TableRow>
                                <TableCell
                                    colSpan={3}
                                    className="text-muted-foreground"
                                >
                                    No expenses for this period.
                                </TableCell>
                            </TableRow>
                        )}
                        <TableRow className="border-t font-semibold">
                            <TableCell colSpan={2}>Total expenses</TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatMoney(report.total_expenses)}
                            </TableCell>
                        </TableRow>

                        <TableRow className="border-t-2 font-bold">
                            <TableCell colSpan={2}>
                                {inProfit ? 'Net profit' : 'Net loss'}
                            </TableCell>
                            <TableCell
                                className={`text-right tabular-nums ${inProfit ? 'text-status-success' : 'text-status-critical'}`}
                            >
                                {formatMoney(report.net_profit)}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </ReportCard>
        </FinanceReportPage>
    );
}
