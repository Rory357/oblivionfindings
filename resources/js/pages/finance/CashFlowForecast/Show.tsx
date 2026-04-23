import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Printer, Trash2, TrendingUp, TrendingDown, DollarSign, ArrowUpDown } from 'lucide-react';
import { useState } from 'react';
import { ComposedChart, Bar, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { type BreadcrumbItem } from '@/types';

const CHART_COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];
const formatCurrency = (amount: number) => new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

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

type ChartDataset = {
    label: string;
    data: number[];
    type: string;
};

type ChartData = {
    labels: string[];
    datasets: ChartDataset[];
};

type PageProps = {
    forecast: Forecast;
    chartData: ChartData;
};

const formatNZD = (amount: string | number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(amount));

const formatDate = (dateStr: string) =>
    new Date(dateStr).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });

const statusConfig: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draft', className: 'bg-muted text-foreground border-border' },
    final: { label: 'Final', className: 'bg-green-100 text-green-700 border-green-300' },
};

const periodTypeLabels: Record<string, string> = {
    weekly: 'Weekly',
    fortnightly: 'Fortnightly',
    monthly: 'Monthly',
};

export default function CashFlowForecastShow({ forecast, chartData }: PageProps) {
    const [selectedScenario, setSelectedScenario] = useState<number | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance/dashboard' },
        { title: 'Cash Flow Forecast', href: '/finance/cash-flow-forecast' },
        { title: forecast.name, href: `/finance/cash-flow-forecast/${forecast.id}` },
    ];

    const status = statusConfig[forecast.status] ?? statusConfig.draft;
    const isDraft = forecast.status === 'draft';

    const activeForecastData =
        selectedScenario !== null
            ? forecast.scenarios.find((s) => s.id === selectedScenario)?.forecast_data ?? forecast.forecast_data
            : forecast.forecast_data;

    const activeScenarioName =
        selectedScenario !== null
            ? forecast.scenarios.find((s) => s.id === selectedScenario)?.name ?? 'Base'
            : 'Base Forecast';

    function handleDelete() {
        if (confirm('Are you sure you want to delete this forecast?')) {
            router.delete(`/finance/cash-flow-forecast/${forecast.id}`);
        }
    }

    // Build Recharts data from active forecast data
    const rechartsData = activeForecastData.map((period) => {
        const row: Record<string, string | number> = {
            period: period.period_label,
            inflows: Number(period.inflows.total),
            outflows: Math.abs(Number(period.outflows.total)),
            closingBalance: Number(period.closing_balance),
        };
        return row;
    });

    // Add scenario closing balances to chart data
    if (forecast.scenarios.length > 0) {
        forecast.scenarios.forEach((scenario) => {
            const scenarioData = scenario.forecast_data ?? [];
            scenarioData.forEach((period, idx) => {
                if (rechartsData[idx]) {
                    rechartsData[idx][`scenario_${scenario.id}`] = Number(period.closing_balance);
                }
            });
        });
    }

    // KPI calculations
    const totalInflows = activeForecastData.reduce((sum, p) => sum + Number(p.inflows.total), 0);
    const totalOutflows = activeForecastData.reduce((sum, p) => sum + Number(p.outflows.total), 0);
    const lastPeriod = activeForecastData[activeForecastData.length - 1];
    const finalBalance = lastPeriod ? Number(lastPeriod.closing_balance) : 0;
    const netCashFlow = totalInflows - Math.abs(totalOutflows);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={forecast.name} />

            <div className="mx-auto max-w-7xl space-y-6 p-6">
                {/* Header */}
                <div className="flex items-start justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <h1 className="text-2xl font-bold tracking-tight">{forecast.name}</h1>
                            <Badge variant="outline" className={status.className}>
                                {status.label}
                            </Badge>
                        </div>
                        <p className="text-muted-foreground">
                            {formatDate(forecast.period_start)} &ndash; {formatDate(forecast.period_end)}
                            {' | '}{periodTypeLabels[forecast.period_type]} | Opening: {formatNZD(forecast.opening_balance)}
                        </p>
                        {forecast.created_by && (
                            <p className="mt-1 text-sm text-muted-foreground">
                                Generated on {formatDate(forecast.forecast_date)} by {forecast.created_by.name}
                            </p>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" onClick={() => window.print()}>
                            <Printer className="mr-2 h-4 w-4" />
                            Print
                        </Button>
                        {isDraft && (
                            <Button variant="destructive" onClick={handleDelete}>
                                <Trash2 className="mr-2 h-4 w-4" />
                                Delete
                            </Button>
                        )}
                    </div>
                </div>

                {/* KPI Cards */}
                <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <Card>
                        <CardContent className="flex items-center justify-between p-6">
                            <div>
                                <p className="text-sm text-muted-foreground">Total Inflows</p>
                                <p className="text-2xl font-bold mt-1 text-green-600">{formatCurrency(totalInflows)}</p>
                            </div>
                            <TrendingUp className="h-8 w-8 text-green-500/50" />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center justify-between p-6">
                            <div>
                                <p className="text-sm text-muted-foreground">Total Outflows</p>
                                <p className="text-2xl font-bold mt-1 text-red-600">{formatCurrency(Math.abs(totalOutflows))}</p>
                            </div>
                            <TrendingDown className="h-8 w-8 text-red-500/50" />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center justify-between p-6">
                            <div>
                                <p className="text-sm text-muted-foreground">Net Cash Flow</p>
                                <p className={`text-2xl font-bold mt-1 ${netCashFlow >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                    {formatCurrency(netCashFlow)}
                                </p>
                            </div>
                            <ArrowUpDown className="h-8 w-8 text-muted-foreground/50" />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center justify-between p-6">
                            <div>
                                <p className="text-sm text-muted-foreground">Final Balance</p>
                                <p className={`text-2xl font-bold mt-1 ${finalBalance >= 0 ? 'text-foreground' : 'text-red-600'}`}>
                                    {formatCurrency(finalBalance)}
                                </p>
                            </div>
                            <DollarSign className="h-8 w-8 text-muted-foreground/50" />
                        </CardContent>
                    </Card>
                </div>

                {/* Scenario selector */}
                {forecast.scenarios.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Scenario Comparison</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap gap-3">
                                <Button
                                    variant={selectedScenario === null ? 'default' : 'outline'}
                                    size="sm"
                                    onClick={() => setSelectedScenario(null)}
                                >
                                    Base Forecast
                                </Button>
                                {forecast.scenarios.map((scenario) => (
                                    <Button
                                        key={scenario.id}
                                        variant={selectedScenario === scenario.id ? 'default' : 'outline'}
                                        size="sm"
                                        onClick={() => setSelectedScenario(scenario.id)}
                                    >
                                        {scenario.name}
                                        <span className="ml-2 text-xs opacity-70">
                                            ({scenario.adjustments.description})
                                        </span>
                                    </Button>
                                ))}
                            </div>
                            <p className="mt-2 text-sm text-muted-foreground">
                                Currently viewing: <span className="font-medium">{activeScenarioName}</span>
                            </p>
                        </CardContent>
                    </Card>
                )}

                {/* Recharts ComposedChart */}
                <Card>
                    <CardHeader>
                        <CardTitle>Cash Flow Overview</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="h-[400px] w-full">
                            <ResponsiveContainer width="100%" height="100%">
                                <ComposedChart data={rechartsData} margin={{ top: 20, right: 30, left: 20, bottom: 20 }}>
                                    <CartesianGrid strokeDasharray="3 3" className="opacity-30" />
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
                                            if (Math.abs(value) >= 1000000) return `$${(value / 1000000).toFixed(1)}M`;
                                            if (Math.abs(value) >= 1000) return `$${(value / 1000).toFixed(0)}k`;
                                            return `$${value}`;
                                        }}
                                    />
                                    <Tooltip formatter={(value) => formatCurrency(Number(value))} />
                                    <Legend />
                                    <Bar dataKey="inflows" name="Inflows" fill="#10b981" radius={[4, 4, 0, 0]} />
                                    <Bar dataKey="outflows" name="Outflows" fill="#ef4444" radius={[4, 4, 0, 0]} />
                                    <Line
                                        type="monotone"
                                        dataKey="closingBalance"
                                        name="Closing Balance"
                                        stroke="#3b82f6"
                                        strokeWidth={2}
                                        dot={{ r: 4 }}
                                    />
                                    {forecast.scenarios.map((scenario, idx) => (
                                        <Line
                                            key={scenario.id}
                                            type="monotone"
                                            dataKey={`scenario_${scenario.id}`}
                                            name={scenario.name}
                                            stroke={CHART_COLORS[(idx + 3) % CHART_COLORS.length]}
                                            strokeWidth={2}
                                            strokeDasharray="5 5"
                                            dot={false}
                                        />
                                    ))}
                                </ComposedChart>
                            </ResponsiveContainer>
                        </div>
                    </CardContent>
                </Card>

                {/* Detailed Breakdown Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Period Detail</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Period</TableHead>
                                        <TableHead className="text-right">Opening</TableHead>
                                        <TableHead className="text-right">Invoice Receipts</TableHead>
                                        <TableHead className="text-right">Overdue Collections</TableHead>
                                        <TableHead className="text-right">Recurring Income</TableHead>
                                        <TableHead className="text-right">Bill Payments</TableHead>
                                        <TableHead className="text-right">Overdue Bills</TableHead>
                                        <TableHead className="text-right">Recurring Exp.</TableHead>
                                        <TableHead className="text-right">GST</TableHead>
                                        <TableHead className="text-right">Net Flow</TableHead>
                                        <TableHead className="text-right">Closing</TableHead>
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
                                                <TableCell className="text-right font-mono tabular-nums">
                                                    {formatNZD(period.opening_balance)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums text-green-600">
                                                    {formatNZD(period.inflows.invoice_receipts)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums text-green-600">
                                                    {formatNZD(period.inflows.overdue_collections)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums text-green-600">
                                                    {formatNZD(period.inflows.recurring_income)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums text-red-600">
                                                    {formatNZD(period.outflows.bill_payments)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums text-red-600">
                                                    {formatNZD(period.outflows.overdue_bills)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums text-red-600">
                                                    {formatNZD(period.outflows.recurring_expenses)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums text-red-600">
                                                    {formatNZD(period.outflows.gst_payments)}
                                                </TableCell>
                                                <TableCell className={`text-right font-mono font-semibold tabular-nums ${netFlow >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                                    {formatNZD(netFlow)}
                                                </TableCell>
                                                <TableCell className={`text-right font-mono font-semibold tabular-nums ${closingBal >= 0 ? '' : 'text-red-600'}`}>
                                                    {formatNZD(closingBal)}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                {/* Scenario Comparison Summary */}
                {forecast.scenarios.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Scenario Summary</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Scenario</TableHead>
                                            <TableHead>Description</TableHead>
                                            <TableHead className="text-right">Final Balance</TableHead>
                                            <TableHead className="text-right">Total Inflows</TableHead>
                                            <TableHead className="text-right">Total Outflows</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {forecast.scenarios.map((scenario) => {
                                            const periods = scenario.forecast_data ?? [];
                                            const scenarioLastPeriod = periods[periods.length - 1];
                                            const scenarioTotalInflows = periods.reduce(
                                                (sum, p) => sum + Number(p.inflows?.total ?? 0),
                                                0,
                                            );
                                            const scenarioTotalOutflows = periods.reduce(
                                                (sum, p) => sum + Number(p.outflows?.total ?? 0),
                                                0,
                                            );
                                            const scenarioFinalBalance = scenarioLastPeriod
                                                ? Number(scenarioLastPeriod.closing_balance)
                                                : 0;

                                            return (
                                                <TableRow key={scenario.id}>
                                                    <TableCell className="font-medium">{scenario.name}</TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {scenario.adjustments.description}
                                                    </TableCell>
                                                    <TableCell className={`text-right font-mono font-semibold tabular-nums ${scenarioFinalBalance >= 0 ? '' : 'text-red-600'}`}>
                                                        {formatNZD(scenarioFinalBalance)}
                                                    </TableCell>
                                                    <TableCell className="text-right font-mono tabular-nums text-green-600">
                                                        {formatNZD(scenarioTotalInflows)}
                                                    </TableCell>
                                                    <TableCell className="text-right font-mono tabular-nums text-red-600">
                                                        {formatNZD(scenarioTotalOutflows)}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Assumptions */}
                {forecast.assumptions && forecast.assumptions.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Assumptions</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <ul className="list-disc list-inside space-y-1 text-sm text-muted-foreground">
                                {forecast.assumptions.map((assumption, idx) => (
                                    <li key={idx}>{assumption}</li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
