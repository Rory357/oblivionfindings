import { chartColor } from '@/components/finance/chart-palette';
import { formatMoney } from '@/components/finance/money';
import {
    FinanceReportPage,
    ReportAsAtFilter,
    ReportCard,
    formatReportDate,
    reportAsAtLabel,
} from '@/components/finance/report-page';
import {
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { PageProps } from '@/types';
import { BookOpen, Printer } from 'lucide-react';
import { Fragment, useMemo } from 'react';
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

interface TrialBalanceRow {
    account_code: string;
    account_name: string;
    account_type: string;
    debit_balance: number;
    credit_balance: number;
}

interface Report {
    as_of_date: string;
    rows: TrialBalanceRow[];
    total_debits: number;
    total_credits: number;
}

interface Props extends PageProps {
    report: Report;
    filters: { as_of_date: string };
}

const URL = '/finance/reports/trial-balance';

const typeLabels: Record<string, string> = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    revenue: 'Revenue',
    expense: 'Expenses',
};

const typeOrder = ['asset', 'liability', 'equity', 'revenue', 'expense'];

export default function TrialBalance({ report, filters }: Props) {
    const grouped = typeOrder
        .map((type) => ({
            type,
            label: typeLabels[type],
            rows: report.rows.filter((r) => r.account_type === type),
        }))
        .filter((g) => g.rows.length > 0);

    const difference = report.total_debits - report.total_credits;
    const isBalanced = Math.abs(difference) < 0.01;

    const chartData = useMemo(
        () =>
            typeOrder
                .map((type) => {
                    const rows = report.rows.filter(
                        (r) => r.account_type === type,
                    );
                    if (rows.length === 0) return null;
                    return {
                        name: typeLabels[type],
                        debit: rows.reduce(
                            (sum, r) => sum + r.debit_balance,
                            0,
                        ),
                        credit: rows.reduce(
                            (sum, r) => sum + r.credit_balance,
                            0,
                        ),
                    };
                })
                .filter(Boolean) as {
                name: string;
                debit: number;
                credit: number;
            }[],
        [report.rows],
    );

    const meters = (
        <>
            <PageHeaderMeterBlock
                label="Total debits"
                href="/finance/journals"
                ariaLabel="View journals"
            >
                <PageHeaderMeterBig>
                    {formatMoney(report.total_debits)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Across every posted journal
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Total credits"
                href="/finance/journals"
                ariaLabel="View journals"
            >
                <PageHeaderMeterBig>
                    {formatMoney(report.total_credits)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Across every posted journal
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Difference"
                tone={isBalanced ? 'success' : 'critical'}
                href="/finance/journals"
                ariaLabel="View journals to find the imbalance"
            >
                <PageHeaderMeterBig>
                    {isBalanced ? 'Balanced' : formatMoney(Math.abs(difference))}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {isBalanced
                        ? 'Debits equal credits'
                        : 'Debits less credits'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Accounts"
                href="/finance/accounts"
                ariaLabel="View the chart of accounts"
            >
                <PageHeaderMeterBig>{report.rows.length}</PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    With a balance at this date
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    );

    return (
        <FinanceReportPage
            icon={BookOpen}
            title="Trial balance"
            periodLabel={reportAsAtLabel(filters.as_of_date)}
            subline={`Every account balance, checking debits equal credits · ${report.rows.length} accounts`}
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
                <ReportAsAtFilter
                    url={URL}
                    value={filters.as_of_date}
                    idPrefix="trial-balance"
                />
            }
        >
            {chartData.length > 0 && (
                <ReportCard
                    title="Debits and credits by account type"
                    scroll={false}
                >
                    <div className="h-64">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart
                                data={chartData}
                                margin={{
                                    top: 5,
                                    right: 20,
                                    bottom: 5,
                                    left: 20,
                                }}
                            >
                                <CartesianGrid strokeDasharray="3 3" />
                                <XAxis dataKey="name" />
                                <YAxis tickFormatter={(v) => formatMoney(v)} />
                                <Tooltip
                                    formatter={(value?: number) =>
                                        formatMoney(value ?? 0)
                                    }
                                />
                                <Legend />
                                <Bar
                                    dataKey="debit"
                                    name="Debit"
                                    fill={chartColor(0)}
                                    radius={[4, 4, 0, 0]}
                                />
                                <Bar
                                    dataKey="credit"
                                    name="Credit"
                                    fill={chartColor(1)}
                                    radius={[4, 4, 0, 0]}
                                />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </ReportCard>
            )}

            <ReportCard
                title={`Trial balance as at ${formatReportDate(report.as_of_date)}`}
                caption={
                    isBalanced
                        ? undefined
                        : `Out of balance by ${formatMoney(Math.abs(difference))} — check the journals posted up to this date.`
                }
                right={
                    <StatusBadge status={isBalanced ? 'balanced' : 'unbalanced'} />
                }
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-32">Account code</TableHead>
                            <TableHead>Account name</TableHead>
                            <TableHead className="text-right">Debit</TableHead>
                            <TableHead className="text-right">Credit</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {grouped.map((group) => (
                            <Fragment key={`group-${group.type}`}>
                                <TableRow className="bg-muted/50">
                                    <TableCell
                                        colSpan={4}
                                        className="font-semibold"
                                    >
                                        {group.label}
                                    </TableCell>
                                </TableRow>
                                {group.rows.map((row, idx) => (
                                    <TableRow key={`${group.type}-${idx}`}>
                                        <TableCell className="font-mono text-sm">
                                            {row.account_code}
                                        </TableCell>
                                        <TableCell>
                                            {row.account_name}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.debit_balance > 0
                                                ? formatMoney(row.debit_balance)
                                                : ''}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.credit_balance > 0
                                                ? formatMoney(
                                                      row.credit_balance,
                                                  )
                                                : ''}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </Fragment>
                        ))}
                        <TableRow className="border-t-2 font-bold">
                            <TableCell colSpan={2}>Totals</TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatMoney(report.total_debits)}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatMoney(report.total_credits)}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </ReportCard>
        </FinanceReportPage>
    );
}
