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
    PageHeaderMeterDonut,
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
import { Printer, Scale } from 'lucide-react';
import { useMemo } from 'react';
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';

interface AccountRow {
    account_code: string;
    account_name: string;
    sub_type: string | null;
    balance: number;
}

interface Report {
    as_of_date: string;
    assets: AccountRow[];
    total_assets: number;
    liabilities: AccountRow[];
    total_liabilities: number;
    equity: AccountRow[];
    total_equity: number;
    balanced: boolean;
}

interface Props extends PageProps {
    report: Report;
    filters: { as_of_date: string };
}

const URL = '/finance/reports/balance-sheet';

function SectionRows({
    title,
    rows,
    total,
}: {
    title: string;
    rows: AccountRow[];
    total: number;
}) {
    return (
        <>
            <TableRow className="bg-muted/50">
                <TableCell colSpan={3} className="font-semibold">
                    {title}
                </TableCell>
            </TableRow>
            {rows.map((row, idx) => (
                <TableRow key={`${title}-${idx}`}>
                    <TableCell className="font-mono text-sm">
                        {row.account_code || '—'}
                    </TableCell>
                    <TableCell>{row.account_name}</TableCell>
                    <TableCell className="text-right tabular-nums">
                        {formatMoney(row.balance)}
                    </TableCell>
                </TableRow>
            ))}
            {rows.length === 0 && (
                <TableRow>
                    <TableCell colSpan={3} className="text-muted-foreground">
                        No accounts.
                    </TableCell>
                </TableRow>
            )}
            <TableRow className="border-t font-semibold">
                <TableCell colSpan={2}>Total {title.toLowerCase()}</TableCell>
                <TableCell className="text-right tabular-nums">
                    {formatMoney(total)}
                </TableCell>
            </TableRow>
        </>
    );
}

export default function BalanceSheet({ report, filters }: Props) {
    const pieData = useMemo(
        () =>
            [
                { name: 'Assets', value: Math.abs(report.total_assets) },
                {
                    name: 'Liabilities',
                    value: Math.abs(report.total_liabilities),
                },
                { name: 'Equity', value: Math.abs(report.total_equity) },
            ].filter((d) => d.value > 0),
        [report.total_assets, report.total_liabilities, report.total_equity],
    );

    const difference =
        report.total_assets -
        (report.total_liabilities + report.total_equity);

    const gearingPct =
        report.total_assets !== 0
            ? (report.total_liabilities / report.total_assets) * 100
            : 0;

    const meters = (
        <>
            <PageHeaderMeterBlock
                label="Assets"
                href="/finance/accounts"
                ariaLabel="View the chart of accounts"
            >
                <PageHeaderMeterBig>
                    {formatMoney(report.total_assets)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {report.assets.length} asset accounts
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Liabilities"
                href="/finance/bills"
                ariaLabel="View bills, where liabilities are raised"
            >
                <PageHeaderMeterBig>
                    {formatMoney(report.total_liabilities)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {report.liabilities.length} liability accounts
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Equity"
                href="/finance/reports/profit-loss"
                ariaLabel="View the profit and loss statement"
            >
                <PageHeaderMeterBig>
                    {formatMoney(report.total_equity)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {report.equity.length} equity accounts
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Funded by liabilities"
                tone={gearingPct >= 80 ? 'warning' : 'brand'}
                href="/finance/reports/aged-payables"
                ariaLabel="View aged payables"
            >
                <PageHeaderMeterDonut
                    percent={gearingPct}
                    caption={
                        report.total_assets !== 0
                            ? `${formatMoney(report.total_liabilities)} of ${formatMoney(report.total_assets)} assets`
                            : 'No assets recorded'
                    }
                />
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Balance check"
                tone={report.balanced ? 'success' : 'critical'}
                href="/finance/reports/trial-balance"
                ariaLabel="View the trial balance"
            >
                <PageHeaderMeterBig>
                    {report.balanced ? 'Balanced' : formatMoney(difference)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {report.balanced
                        ? 'Assets equal liabilities plus equity'
                        : 'Assets less liabilities and equity'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    );

    return (
        <FinanceReportPage
            icon={Scale}
            title="Balance sheet"
            periodLabel={reportAsAtLabel(filters.as_of_date)}
            subline={`Financial position · assets, liabilities and equity · ${report.assets.length + report.liabilities.length + report.equity.length} accounts`}
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
                    idPrefix="balance-sheet"
                />
            }
        >
            {pieData.length > 0 && (
                <ReportCard
                    title="Composition"
                    caption="Assets, liabilities and equity as a share of the whole"
                    scroll={false}
                >
                    <div className="h-64">
                        <ResponsiveContainer width="100%" height="100%">
                            <PieChart>
                                <Pie
                                    data={pieData}
                                    cx="50%"
                                    cy="50%"
                                    innerRadius={60}
                                    outerRadius={100}
                                    paddingAngle={3}
                                    dataKey="value"
                                    label={({ name, percent }) =>
                                        `${name} ${((percent ?? 0) * 100).toFixed(0)}%`
                                    }
                                >
                                    {pieData.map((_, index) => (
                                        <Cell
                                            key={`cell-${index}`}
                                            fill={chartColor(index)}
                                        />
                                    ))}
                                </Pie>
                                <Tooltip
                                    formatter={(value?: number) =>
                                        formatMoney(value ?? 0)
                                    }
                                />
                            </PieChart>
                        </ResponsiveContainer>
                    </div>
                </ReportCard>
            )}

            <ReportCard
                title={`Balance sheet as at ${formatReportDate(report.as_of_date)}`}
                right={
                    <StatusBadge
                        status={report.balanced ? 'balanced' : 'unbalanced'}
                    />
                }
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-32">Account code</TableHead>
                            <TableHead>Account name</TableHead>
                            <TableHead className="text-right">
                                Balance
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <SectionRows
                            title="Assets"
                            rows={report.assets}
                            total={report.total_assets}
                        />
                        <SectionRows
                            title="Liabilities"
                            rows={report.liabilities}
                            total={report.total_liabilities}
                        />
                        <SectionRows
                            title="Equity"
                            rows={report.equity}
                            total={report.total_equity}
                        />

                        <TableRow className="border-t-2 font-bold">
                            <TableCell colSpan={2}>
                                Total liabilities and equity
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatMoney(
                                    report.total_liabilities +
                                        report.total_equity,
                                )}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </ReportCard>
        </FinanceReportPage>
    );
}
