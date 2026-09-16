import { chartColor } from '@/components/finance/chart-palette';
import { FinancePeriodFilter } from '@/components/finance/finance-period-filter';
import { formatMoney } from '@/components/finance/money';
import {
    FinanceReportPage,
    ReportCard,
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
import { PieChart as PieChartIcon, Printer } from 'lucide-react';
import { useMemo } from 'react';
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

interface FundingStream {
    name: string;
    revenue: number;
    expenses: number;
    net_margin: number;
    margin_pct: number;
}

interface Totals {
    revenue: number;
    expenses: number;
    net_margin: number;
}

interface ReportData {
    streams: FundingStream[];
    totals: Totals;
}

interface Props extends PageProps {
    startDate: string;
    endDate: string;
    data: ReportData;
}

const URL = '/finance/reports/funding-stream-summary';

const REVENUE_COLOUR = chartColor(0);
const EXPENSE_COLOUR = chartColor(1);

const formatPct = (pct: number) => `${pct.toFixed(1)}%`;

const truncate = (value: string, max: number) =>
    value.length > max ? `${value.substring(0, max)}…` : value;

export default function FundingStreamSummary({
    startDate,
    endDate,
    data,
}: Props) {
    const overallMarginPct =
        data.totals.revenue > 0
            ? (data.totals.net_margin / data.totals.revenue) * 100
            : 0;

    const inMargin = data.totals.net_margin >= 0;

    const loseMoney = data.streams.filter((s) => s.net_margin < 0).length;

    const chartData = useMemo(
        () =>
            data.streams.map((s) => ({
                name: truncate(s.name, 18),
                Revenue: s.revenue,
                Expenses: s.expenses,
            })),
        [data.streams],
    );

    const meters = (
        <>
            <PageHeaderMeterBlock
                label="Revenue"
                tone="success"
                href="/finance/invoices"
                ariaLabel="View invoices"
            >
                <PageHeaderMeterBig>
                    {formatMoney(data.totals.revenue)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {data.streams.length} funding streams
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Expenses"
                href="/finance/bills"
                ariaLabel="View bills"
            >
                <PageHeaderMeterBig>
                    {formatMoney(data.totals.expenses)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Attributed to a funding stream
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label={inMargin ? 'Net margin' : 'Net shortfall'}
                tone={inMargin ? 'success' : 'critical'}
                href="/finance/reports/profit-loss"
                ariaLabel="View the profit and loss statement"
            >
                <PageHeaderMeterBig>
                    {formatMoney(data.totals.net_margin)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {loseMoney > 0
                        ? `${loseMoney} streams running at a loss`
                        : 'Every stream covering its costs'}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Overall margin"
                tone={inMargin ? 'success' : 'critical'}
                href="/finance/funding-streams"
                ariaLabel="View funding streams"
            >
                <PageHeaderMeterDonut
                    percent={overallMarginPct}
                    caption={
                        data.totals.revenue > 0
                            ? `${formatMoney(data.totals.net_margin)} of ${formatMoney(data.totals.revenue)}`
                            : 'No revenue in this period'
                    }
                />
            </PageHeaderMeterBlock>
        </>
    );

    return (
        <FinanceReportPage
            icon={PieChartIcon}
            title="Funding summary"
            headTitle="Funding stream summary"
            periodLabel={reportRangeLabel(startDate, endDate)}
            subline={`Revenue, expenses and margin by funding stream · ${data.streams.length} streams with activity`}
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
                    from={startDate ?? ''}
                    to={endDate ?? ''}
                    idPrefix="funding-summary-period"
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
            {data.streams.length > 0 && (
                <ReportCard
                    title="Revenue vs expenses by funding stream"
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
                                <XAxis dataKey="name" tick={{ fontSize: 12 }} />
                                <YAxis tickFormatter={(v) => formatMoney(v)} />
                                <Tooltip
                                    formatter={(value) =>
                                        formatMoney(value as number)
                                    }
                                />
                                <Legend />
                                <Bar
                                    dataKey="Revenue"
                                    fill={REVENUE_COLOUR}
                                    radius={[4, 4, 0, 0]}
                                />
                                <Bar
                                    dataKey="Expenses"
                                    fill={EXPENSE_COLOUR}
                                    radius={[4, 4, 0, 0]}
                                />
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </ReportCard>
            )}

            <ReportCard title="Funding stream performance">
                {data.streams.length === 0 ? (
                    <p className="py-8 text-center text-muted-foreground">
                        No funding stream activity for the selected period.
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Funding stream</TableHead>
                                <TableHead className="text-right">
                                    Revenue
                                </TableHead>
                                <TableHead className="text-right">
                                    Expenses
                                </TableHead>
                                <TableHead className="text-right">
                                    Net margin
                                </TableHead>
                                <TableHead className="text-right">
                                    Margin %
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {data.streams.map((stream, idx) => (
                                <TableRow key={idx}>
                                    <TableCell className="font-medium">
                                        {stream.name}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(stream.revenue)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(stream.expenses)}
                                    </TableCell>
                                    <TableCell
                                        className={`text-right font-semibold tabular-nums ${stream.net_margin >= 0 ? 'text-status-success' : 'text-status-critical'}`}
                                    >
                                        {formatMoney(stream.net_margin)}
                                    </TableCell>
                                    <TableCell
                                        className={`text-right tabular-nums ${stream.margin_pct >= 0 ? 'text-status-success' : 'text-status-critical'}`}
                                    >
                                        {formatPct(stream.margin_pct)}
                                    </TableCell>
                                </TableRow>
                            ))}
                            <TableRow className="border-t-2 font-bold">
                                <TableCell>Totals</TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatMoney(data.totals.revenue)}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatMoney(data.totals.expenses)}
                                </TableCell>
                                <TableCell
                                    className={`text-right tabular-nums ${inMargin ? 'text-status-success' : 'text-status-critical'}`}
                                >
                                    {formatMoney(data.totals.net_margin)}
                                </TableCell>
                                <TableCell
                                    className={`text-right tabular-nums ${inMargin ? 'text-status-success' : 'text-status-critical'}`}
                                >
                                    {formatPct(overallMarginPct)}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                )}
            </ReportCard>
        </FinanceReportPage>
    );
}
