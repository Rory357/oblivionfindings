import { ConfirmDialog } from '@/components/finance';
import { chartColor } from '@/components/finance/chart-palette';
import { formatMoney } from '@/components/finance/money';
import {
    FinanceReportPage,
    ReportCard,
    formatReportDate,
} from '@/components/finance/report-page';
import {
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
} from '@/components/page';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { router } from '@inertiajs/react';
import { Printer, Trash2, TrendingUp } from 'lucide-react';
import { useState } from 'react';
import {
    Bar,
    CartesianGrid,
    ComposedChart,
    Legend,
    Line,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type Inflows = {
    total: string;
    invoice_receipts: string;
    overdue_collections: string;
    recurring_income: string;
};

type Outflows = {
    total: string;
    bill_payments: string;
    overdue_bills: string;
    recurring_expenses: string;
    gst_payments: string;
};

type ForecastPeriod = {
    period_label: string;
    period_start: string;
    period_end: string;
    opening_balance: string;
    inflows: Inflows;
    outflows: Outflows;
    net_cash_flow: string;
    closing_balance: string;
};

type Scenario = {
    id: number;
    name: string;
    adjustments: {
        inflow_adjustment: number;
        outflow_adjustment: number;
        description: string;
    };
    forecast_data: ForecastPeriod[];
};

type Forecast = {
    id: number;
    name: string;
    forecast_date: string;
    period_start: string;
    period_end: string;
    period_type: string;
    opening_balance: string;
    forecast_data: ForecastPeriod[];
    assumptions: string[];
    status: string;
    scenarios: Scenario[];
    created_by: { id: number; name: string } | null;
};

type PageProps = {
    forecast: Forecast;
};

const INDEX_URL = '/finance/cash-flow-forecast';

const BASE = 'base';

const periodTypeLabels: Record<string, string> = {
    weekly: 'Weekly',
    fortnightly: 'Fortnightly',
    monthly: 'Monthly',
};

export default function CashFlowForecastShow({ forecast }: PageProps) {
    const [view, setView] = useState<string>(BASE);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const isDraft = forecast.status === 'draft';

    const activeScenario =
        view === BASE
            ? null
            : (forecast.scenarios.find((s) => String(s.id) === view) ?? null);

    const activeForecastData =
        activeScenario?.forecast_data ?? forecast.forecast_data;

    const activeScenarioName = activeScenario?.name ?? 'Base forecast';

    function confirmDelete() {
        router.delete(`${INDEX_URL}/${forecast.id}`, {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
        });
    }

    const rechartsData = activeForecastData.map((period) => {
        const row: Record<string, string | number> = {
            period: period.period_label,
            inflows: Number(period.inflows.total),
            outflows: Math.abs(Number(period.outflows.total)),
            closingBalance: Number(period.closing_balance),
        };
        return row;
    });

    forecast.scenarios.forEach((scenario) => {
        (scenario.forecast_data ?? []).forEach((period, idx) => {
            if (rechartsData[idx]) {
                rechartsData[idx][`scenario_${scenario.id}`] = Number(
                    period.closing_balance,
                );
            }
        });
    });

    const totalInflows = activeForecastData.reduce(
        (sum, p) => sum + Number(p.inflows.total),
        0,
    );
    const totalOutflows = activeForecastData.reduce(
        (sum, p) => sum + Number(p.outflows.total),
        0,
    );
    const lastPeriod = activeForecastData[activeForecastData.length - 1];
    const finalBalance = lastPeriod ? Number(lastPeriod.closing_balance) : 0;
    const netCashFlow = totalInflows - Math.abs(totalOutflows);

    const meters = (
        <>
            <PageHeaderMeterBlock
                label="Projected inflows"
                tone="success"
                href="/finance/invoices"
                ariaLabel="View the invoices behind the projected inflows"
            >
                <PageHeaderMeterBig>
                    {formatMoney(totalInflows)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    {activeScenarioName} · {activeForecastData.length} periods
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Projected outflows"
                href="/finance/bills"
                ariaLabel="View the bills behind the projected outflows"
            >
                <PageHeaderMeterBig>
                    {formatMoney(Math.abs(totalOutflows))}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Bills, recurring costs and GST
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Net cash flow"
                tone={netCashFlow >= 0 ? 'success' : 'critical'}
                href="/finance/reports/cash-flow"
                ariaLabel="View the cash flow statement"
            >
                <PageHeaderMeterBig>
                    {formatMoney(netCashFlow)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Inflows less outflows
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
            <PageHeaderMeterBlock
                label="Final balance"
                tone={finalBalance >= 0 ? 'brand' : 'critical'}
                href="/finance/cash-position"
                ariaLabel="View the current cash position"
            >
                <PageHeaderMeterBig>
                    {formatMoney(finalBalance)}
                </PageHeaderMeterBig>
                <PageHeaderMeterCaption>
                    Opened at {formatMoney(forecast.opening_balance)}
                </PageHeaderMeterCaption>
            </PageHeaderMeterBlock>
        </>
    );

    return (
        <FinanceReportPage
            variant="profile"
            backHref={INDEX_URL}
            icon={TrendingUp}
            title={forecast.name}
            recordCrumb={forecast.name}
            chip={
                <PageHeaderStatusChip
                    variant={forecast.status === 'final' ? 'success' : 'neutral'}
                >
                    {forecast.status === 'final' ? 'Final' : 'Draft'}
                </PageHeaderStatusChip>
            }
            subline={[
                `${formatReportDate(forecast.period_start)} – ${formatReportDate(forecast.period_end)}`,
                periodTypeLabels[forecast.period_type] ??
                    forecast.period_type,
                `Opening ${formatMoney(forecast.opening_balance)}`,
                forecast.created_by
                    ? `Generated ${formatReportDate(forecast.forecast_date)} by ${forecast.created_by.name}`
                    : `Generated ${formatReportDate(forecast.forecast_date)}`,
            ].join(' · ')}
            actions={
                <>
                    <PageHeaderGlassButton
                        icon={Printer}
                        onClick={() => window.print()}
                    >
                        Print
                    </PageHeaderGlassButton>
                    {isDraft && (
                        <PageHeaderGlassButton
                            icon={Trash2}
                            onClick={() => setDeleteOpen(true)}
                        >
                            Delete draft
                        </PageHeaderGlassButton>
                    )}
                </>
            }
            meters={meters}
            filters={
                <PageHeaderViewToggle
                    ariaLabel="Forecast scenario"
                    value={view}
                    onChange={setView}
                    options={[
                        { value: BASE, label: 'Base' },
                        ...forecast.scenarios.map((scenario) => ({
                            value: String(scenario.id),
                            label: scenario.name,
                        })),
                    ]}
                />
            }
        >
            <ReportCard
                title="Cash flow overview"
                caption={
                    activeScenario
                        ? `${activeScenarioName} — ${activeScenario.adjustments.description}`
                        : 'Base forecast, with each scenario shown as a dashed balance line'
                }
                scroll={false}
            >
                <div className="h-[400px] w-full">
                    <ResponsiveContainer width="100%" height="100%">
                        <ComposedChart
                            data={rechartsData}
                            margin={{
                                top: 20,
                                right: 30,
                                left: 20,
                                bottom: 20,
                            }}
                        >
                            <CartesianGrid
                                strokeDasharray="3 3"
                                className="opacity-30"
                            />
                            <XAxis
                                dataKey="period"
                                tick={{ fontSize: 12 }}
                                angle={-45}
                                textAnchor="end"
                                height={80}
                            />
                            <YAxis
                                tick={{ fontSize: 12 }}
                                tickFormatter={(value: number) => {
                                    if (Math.abs(value) >= 1000000)
                                        return `$${(value / 1000000).toFixed(1)}M`;
                                    if (Math.abs(value) >= 1000)
                                        return `$${(value / 1000).toFixed(0)}k`;
                                    return `$${value}`;
                                }}
                            />
                            <Tooltip
                                formatter={(value) =>
                                    formatMoney(Number(value))
                                }
                            />
                            <Legend />
                            <Bar
                                dataKey="inflows"
                                name="Inflows"
                                fill={chartColor(0)}
                                radius={[4, 4, 0, 0]}
                            />
                            <Bar
                                dataKey="outflows"
                                name="Outflows"
                                fill={chartColor(1)}
                                radius={[4, 4, 0, 0]}
                            />
                            <Line
                                type="monotone"
                                dataKey="closingBalance"
                                name="Closing balance"
                                stroke={chartColor(2)}
                                strokeWidth={2}
                                dot={{ r: 4 }}
                            />
                            {forecast.scenarios.map((scenario, idx) => (
                                <Line
                                    key={scenario.id}
                                    type="monotone"
                                    dataKey={`scenario_${scenario.id}`}
                                    name={scenario.name}
                                    stroke={chartColor(idx + 3)}
                                    strokeWidth={2}
                                    strokeDasharray="5 5"
                                    dot={false}
                                />
                            ))}
                        </ComposedChart>
                    </ResponsiveContainer>
                </div>
            </ReportCard>

            <ReportCard
                title="Period detail"
                caption={activeScenarioName}
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Period</TableHead>
                            <TableHead className="text-right">
                                Opening
                            </TableHead>
                            <TableHead className="text-right">
                                Invoice receipts
                            </TableHead>
                            <TableHead className="text-right">
                                Overdue collections
                            </TableHead>
                            <TableHead className="text-right">
                                Recurring income
                            </TableHead>
                            <TableHead className="text-right">
                                Bill payments
                            </TableHead>
                            <TableHead className="text-right">
                                Overdue bills
                            </TableHead>
                            <TableHead className="text-right">
                                Recurring costs
                            </TableHead>
                            <TableHead className="text-right">GST</TableHead>
                            <TableHead className="text-right">
                                Net flow
                            </TableHead>
                            <TableHead className="text-right">
                                Closing
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {activeForecastData.map((period, idx) => {
                            const netFlow = Number(period.net_cash_flow);
                            const closingBal = Number(period.closing_balance);

                            return (
                                <TableRow key={idx}>
                                    <TableCell className="font-medium whitespace-nowrap">
                                        {period.period_label}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(period.opening_balance)}
                                    </TableCell>
                                    <TableCell className="text-right text-status-success tabular-nums">
                                        {formatMoney(
                                            period.inflows.invoice_receipts,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right text-status-success tabular-nums">
                                        {formatMoney(
                                            period.inflows.overdue_collections,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right text-status-success tabular-nums">
                                        {formatMoney(
                                            period.inflows.recurring_income,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right text-status-critical tabular-nums">
                                        {formatMoney(
                                            period.outflows.bill_payments,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right text-status-critical tabular-nums">
                                        {formatMoney(
                                            period.outflows.overdue_bills,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right text-status-critical tabular-nums">
                                        {formatMoney(
                                            period.outflows.recurring_expenses,
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right text-status-critical tabular-nums">
                                        {formatMoney(
                                            period.outflows.gst_payments,
                                        )}
                                    </TableCell>
                                    <TableCell
                                        className={`text-right font-semibold tabular-nums ${netFlow >= 0 ? 'text-status-success' : 'text-status-critical'}`}
                                    >
                                        {formatMoney(netFlow)}
                                    </TableCell>
                                    <TableCell
                                        className={`text-right font-semibold tabular-nums ${closingBal >= 0 ? '' : 'text-status-critical'}`}
                                    >
                                        {formatMoney(closingBal)}
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </ReportCard>

            {forecast.scenarios.length > 0 && (
                <ReportCard
                    title="Scenario summary"
                    caption="How each what-if compares with the base forecast"
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Scenario</TableHead>
                                <TableHead>What changes</TableHead>
                                <TableHead className="text-right">
                                    Final balance
                                </TableHead>
                                <TableHead className="text-right">
                                    Total inflows
                                </TableHead>
                                <TableHead className="text-right">
                                    Total outflows
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {forecast.scenarios.map((scenario) => {
                                const periods = scenario.forecast_data ?? [];
                                const scenarioLastPeriod =
                                    periods[periods.length - 1];
                                const scenarioTotalInflows = periods.reduce(
                                    (sum, p) =>
                                        sum + Number(p.inflows?.total ?? 0),
                                    0,
                                );
                                const scenarioTotalOutflows = periods.reduce(
                                    (sum, p) =>
                                        sum + Number(p.outflows?.total ?? 0),
                                    0,
                                );
                                const scenarioFinalBalance =
                                    scenarioLastPeriod
                                        ? Number(
                                              scenarioLastPeriod.closing_balance,
                                          )
                                        : 0;

                                return (
                                    <TableRow key={scenario.id}>
                                        <TableCell className="font-medium">
                                            {scenario.name}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {scenario.adjustments.description}
                                        </TableCell>
                                        <TableCell
                                            className={`text-right font-semibold tabular-nums ${scenarioFinalBalance >= 0 ? '' : 'text-status-critical'}`}
                                        >
                                            {formatMoney(scenarioFinalBalance)}
                                        </TableCell>
                                        <TableCell className="text-right text-status-success tabular-nums">
                                            {formatMoney(scenarioTotalInflows)}
                                        </TableCell>
                                        <TableCell className="text-right text-status-critical tabular-nums">
                                            {formatMoney(scenarioTotalOutflows)}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </ReportCard>
            )}

            {forecast.assumptions && forecast.assumptions.length > 0 && (
                <ReportCard
                    title="Assumptions"
                    caption="What this forecast takes as given"
                    scroll={false}
                >
                    <ul className="list-inside list-disc space-y-1 text-sm text-muted-foreground">
                        {forecast.assumptions.map((assumption, idx) => (
                            <li key={idx}>{assumption}</li>
                        ))}
                    </ul>
                </ReportCard>
            )}

            <ConfirmDialog
                open={deleteOpen}
                onClose={() => setDeleteOpen(false)}
                title="Delete this forecast?"
                description={
                    <>
                        This permanently deletes the forecast{' '}
                        <span className="font-medium text-foreground">
                            {forecast.name}
                        </span>{' '}
                        and its scenarios. This can&rsquo;t be undone.
                    </>
                }
                confirmText="Delete forecast"
                variant="destructive"
                processing={deleting}
                onConfirm={confirmDelete}
            />
        </FinanceReportPage>
    );
}
