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
    PageHeaderMeterDelta,
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
import { Printer, Wallet } from 'lucide-react';
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

interface CashFlowEntry {
    account_name: string;
    amount: number;
}

interface Report {
    start_date: string;
    end_date: string;
    operating: CashFlowEntry[];
    total_operating: number;
    investing: CashFlowEntry[];
    total_investing: number;
    financing: CashFlowEntry[];
    total_financing: number;
    net_cash_change: number;
    opening_cash: number;
    closing_cash: number;
}

interface Props extends PageProps {
    report: Report;
    filters: { start_date: string; end_date: string };
}

const URL = '/finance/reports/cash-flow';

const POSITIVE_COLOUR = chartColor(0);
const NEGATIVE_COLOUR = chartColor(3);

function CashFlowSection({
    title,
    entries,
    total,
}: {
    title: string;
    entries: CashFlowEntry[];
    total: number;
}) {
    return (
        <>
            <TableRow className="bg-muted/50">
                <TableCell colSpan={2} className="font-semibold">
                    {title}
                </TableCell>
            </TableRow>
            {entries.map((entry, idx) => (
                <TableRow key={`${title}-${idx}`}>
                    <TableCell className="pl-8">{entry.account_name}</TableCell>
                    <TableCell
                        className={`text-right tabular-nums ${entry.amount < 0 ? 'text-status-critical' : ''}`}
                    >
                        {formatMoney(entry.amount)}
                    </TableCell>
                </TableRow>
            ))}
            {entries.length === 0 && (
                <TableRow>
                    <TableCell
                        colSpan={2}
                        className="pl-8 text-muted-foreground"
                    >
                        No activity.
                    </TableCell>
                </TableRow>
            )}
            <TableRow className="border-t font-semibold">
                <TableCell>Net {title.toLowerCase()}</TableCell>
                <TableCell
                    className={`text-right tabular-nums ${total < 0 ? 'text-status-critical' : ''}`}
                >
                    {formatMoney(total)}
                </TableCell>
            </TableRow>
        </>
    );
}

export default function CashFlow({ report, filters }: Props) {
    const barData = useMemo(
        () => [
            { name: 'Operating', amount: report.total_operating },
            { name: 'Investing', amount: report.total_investing },
            { name: 'Financing', amount: report.total_financing },
        ],
        [
            report.total_operating,
            report.total_investing,
            report.total_financing,
        ],
    );

    const cashCompareData = useMemo(
        () => [
            { name: 'Opening cash', amount: report.opening_cash },
            { name: 'Closing cash', amount: report.closing_cash },
        ],
        [report.opening_cash, report.closing_cash],
    );

    const cashUp = report.net_cash_change >= 0;

    const meters = (
        <>
            <PageHeaderMeterBlock
                label="Operating"
                tone={report.total_operating >= 0 ? 'success' : 'critical'}
                href="/finance/reports/profit-loss"
                ariaLabel="View the profit and loss statement"
            >
                <PageHeaderMeterBig>
                    {formatMoney(report.total_operating)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {report.operating.length} accounts
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Investing"
                href="/finance/fixed-assets"
                ariaLabel="View fixed assets"
            >
                <PageHeaderMeterBig>
                    {formatMoney(report.total_investing)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {report.investing.length} accounts
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Financing"
                href="/finance/bank-accounts"
                ariaLabel="View bank accounts"
            >
                <PageHeaderMeterBig>
                    {formatMoney(report.total_financing)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {report.financing.length} accounts
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Net change"
                tone={cashUp ? 'success' : 'critical'}
                href="/finance/bank-transactions"
                ariaLabel="View bank transactions"
            >
                <PageHeaderMeterBig>
                    {formatMoney(report.net_cash_change)}
                </PageHeaderMeterBig>
                <PageHeaderMeterDelta trend={cashUp ? 'up' : 'down'} good={cashUp}>
                    {cashUp ? 'Cash up on the period' : 'Cash down on the period'}
                </PageHeaderMeterDelta>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Closing cash"
                href="/finance/cash-position"
                ariaLabel="View the cash position"
            >
                <PageHeaderMeterBig>
                    {formatMoney(report.closing_cash)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Opened at {formatMoney(report.opening_cash)}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    );

    return (
        <FinanceReportPage
            icon={Wallet}
            title="Cash flow"
            headTitle="Cash flow statement"
            periodLabel={reportRangeLabel(filters.start_date, filters.end_date)}
            subline="Cash in and out across operating, investing and financing activities"
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
                    idPrefix="cash-flow-period"
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
            <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                <ReportCard title="Cash flow by activity" scroll={false}>
                    <div className="h-64">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart
                                data={barData}
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
                                    formatter={(value?: number) => [
                                        formatMoney(value ?? 0),
                                        'Amount',
                                    ]}
                                />
                                <Bar dataKey="amount" radius={[4, 4, 0, 0]}>
                                    {barData.map((entry, index) => (
                                        <Cell
                                            key={`cell-${index}`}
                                            fill={
                                                entry.amount >= 0
                                                    ? POSITIVE_COLOUR
                                                    : NEGATIVE_COLOUR
                                            }
                                        />
                                    ))}
                                </Bar>
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </ReportCard>

                <ReportCard title="Opening vs closing cash" scroll={false}>
                    <div className="h-64">
                        <ResponsiveContainer width="100%" height="100%">
                            <BarChart
                                data={cashCompareData}
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
                                    formatter={(value?: number) => [
                                        formatMoney(value ?? 0),
                                        'Cash',
                                    ]}
                                />
                                <Bar dataKey="amount" radius={[4, 4, 0, 0]}>
                                    <Cell fill={chartColor(0)} />
                                    <Cell fill={chartColor(1)} />
                                </Bar>
                            </BarChart>
                        </ResponsiveContainer>
                    </div>
                </ReportCard>
            </div>

            <ReportCard
                title={`Cash flow: ${formatReportDate(report.start_date)} to ${formatReportDate(report.end_date)}`}
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Description</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow className="font-semibold">
                            <TableCell>Opening cash balance</TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatMoney(report.opening_cash)}
                            </TableCell>
                        </TableRow>

                        <CashFlowSection
                            title="Operating activities"
                            entries={report.operating}
                            total={report.total_operating}
                        />
                        <CashFlowSection
                            title="Investing activities"
                            entries={report.investing}
                            total={report.total_investing}
                        />
                        <CashFlowSection
                            title="Financing activities"
                            entries={report.financing}
                            total={report.total_financing}
                        />

                        <TableRow className="border-t-2 font-bold">
                            <TableCell>Net cash change</TableCell>
                            <TableCell
                                className={`text-right tabular-nums ${cashUp ? 'text-status-success' : 'text-status-critical'}`}
                            >
                                {formatMoney(report.net_cash_change)}
                            </TableCell>
                        </TableRow>

                        <TableRow className="font-bold">
                            <TableCell>Closing cash balance</TableCell>
                            <TableCell className="text-right tabular-nums">
                                {formatMoney(report.closing_cash)}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </ReportCard>
        </FinanceReportPage>
    );
}
