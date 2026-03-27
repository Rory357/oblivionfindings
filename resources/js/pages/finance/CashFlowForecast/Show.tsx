import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Printer, Trash2, TrendingUp, TrendingDown, ArrowRight } from 'lucide-react';
import { useState } from 'react';

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
    draft: { label: 'Draft', className: 'bg-gray-100 text-gray-700 border-gray-300' },
    final: { label: 'Final', className: 'bg-green-100 text-green-700 border-green-300' },
};

const periodTypeLabels: Record<string, string> = {
    weekly: 'Weekly',
    fortnightly: 'Fortnightly',
    monthly: 'Monthly',
};

export default function CashFlowForecastShow({ forecast, chartData }: PageProps) {
    const [selectedScenario, setSelectedScenario] = useState<number | null>(null);

    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
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

    // Simple bar chart using CSS (no chart library dependency)
    const maxValue = Math.max(
        ...activeForecastData.map((p) =>
            Math.max(Math.abs(Number(p.inflows.total)), Math.abs(Number(p.outflows.total)), Math.abs(Number(p.closing_balance)))
        ),
        1,
    );

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

                {/* Visual Chart (CSS-based) */}
                <Card>
                    <CardHeader>
                        <CardTitle>Cash Flow Overview</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-3">
                            {activeForecastData.map((period, idx) => {
                                const inflowPct = (Math.abs(Number(period.inflows.total)) / maxValue) * 100;
                                const outflowPct = (Math.abs(Number(period.outflows.total)) / maxValue) * 100;
                                const netFlow = Number(period.net_cash_flow);
                                const closingBal = Number(period.closing_balance);

                                return (
                                    <div key={idx} className="space-y-1">
                                        <div className="flex items-center justify-between text-sm">
                                            <span className="font-medium w-32 shrink-0">{period.period_label}</span>
                                            <div className="flex-1 mx-4 space-y-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="w-16 text-xs text-muted-foreground">In</span>
                                                    <div className="flex-1 bg-muted rounded-full h-4 overflow-hidden">
                                                        <div
                                                            className="h-full bg-green-500 rounded-full transition-all"
                                                            style={{ width: `${Math.min(inflowPct, 100)}%` }}
                                                        />
                                                    </div>
                                                    <span className="w-28 text-right font-mono tabular-nums text-xs text-green-600">
                                                        {formatNZD(period.inflows.total)}
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <span className="w-16 text-xs text-muted-foreground">Out</span>
                                                    <div className="flex-1 bg-muted rounded-full h-4 overflow-hidden">
                                                        <div
                                                            className="h-full bg-red-500 rounded-full transition-all"
                                                            style={{ width: `${Math.min(outflowPct, 100)}%` }}
                                                        />
                                                    </div>
                                                    <span className="w-28 text-right font-mono tabular-nums text-xs text-red-600">
                                                        {formatNZD(period.outflows.total)}
                                                    </span>
                                                </div>
                                            </div>
                                            <div className="text-right shrink-0 w-36">
                                                <div className={`font-mono text-xs font-semibold tabular-nums ${netFlow >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                                    Net: {formatNZD(netFlow)}
                                                </div>
                                                <div className={`font-mono text-xs tabular-nums ${closingBal >= 0 ? 'text-foreground' : 'text-red-600 font-bold'}`}>
                                                    Bal: {formatNZD(closingBal)}
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
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
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-left text-muted-foreground">
                                        <th className="pb-3 pr-4 font-medium">Period</th>
                                        <th className="pb-3 pr-4 font-medium text-right">Opening</th>
                                        <th className="pb-3 pr-4 font-medium text-right">Invoice Receipts</th>
                                        <th className="pb-3 pr-4 font-medium text-right">Overdue Collections</th>
                                        <th className="pb-3 pr-4 font-medium text-right">Recurring Income</th>
                                        <th className="pb-3 pr-4 font-medium text-right">Bill Payments</th>
                                        <th className="pb-3 pr-4 font-medium text-right">Overdue Bills</th>
                                        <th className="pb-3 pr-4 font-medium text-right">Recurring Exp.</th>
                                        <th className="pb-3 pr-4 font-medium text-right">GST</th>
                                        <th className="pb-3 pr-4 font-medium text-right">Net Flow</th>
                                        <th className="pb-3 font-medium text-right">Closing</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {activeForecastData.map((period, idx) => {
                                        const netFlow = Number(period.net_cash_flow);
                                        const closingBal = Number(period.closing_balance);

                                        return (
                                            <tr key={idx} className="border-b last:border-0">
                                                <td className="py-3 pr-4 font-medium whitespace-nowrap">
                                                    {period.period_label}
                                                </td>
                                                <td className="py-3 pr-4 text-right font-mono tabular-nums">
                                                    {formatNZD(period.opening_balance)}
                                                </td>
                                                <td className="py-3 pr-4 text-right font-mono tabular-nums text-green-600">
                                                    {formatNZD(period.inflows.invoice_receipts)}
                                                </td>
                                                <td className="py-3 pr-4 text-right font-mono tabular-nums text-green-600">
                                                    {formatNZD(period.inflows.overdue_collections)}
                                                </td>
                                                <td className="py-3 pr-4 text-right font-mono tabular-nums text-green-600">
                                                    {formatNZD(period.inflows.recurring_income)}
                                                </td>
                                                <td className="py-3 pr-4 text-right font-mono tabular-nums text-red-600">
                                                    {formatNZD(period.outflows.bill_payments)}
                                                </td>
                                                <td className="py-3 pr-4 text-right font-mono tabular-nums text-red-600">
                                                    {formatNZD(period.outflows.overdue_bills)}
                                                </td>
                                                <td className="py-3 pr-4 text-right font-mono tabular-nums text-red-600">
                                                    {formatNZD(period.outflows.recurring_expenses)}
                                                </td>
                                                <td className="py-3 pr-4 text-right font-mono tabular-nums text-red-600">
                                                    {formatNZD(period.outflows.gst_payments)}
                                                </td>
                                                <td className={`py-3 pr-4 text-right font-mono font-semibold tabular-nums ${netFlow >= 0 ? 'text-green-600' : 'text-red-600'}`}>
                                                    {formatNZD(netFlow)}
                                                </td>
                                                <td className={`py-3 text-right font-mono font-semibold tabular-nums ${closingBal >= 0 ? '' : 'text-red-600'}`}>
                                                    {formatNZD(closingBal)}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
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
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="pb-3 pr-4 font-medium">Scenario</th>
                                            <th className="pb-3 pr-4 font-medium">Description</th>
                                            <th className="pb-3 pr-4 font-medium text-right">Final Balance</th>
                                            <th className="pb-3 pr-4 font-medium text-right">Total Inflows</th>
                                            <th className="pb-3 font-medium text-right">Total Outflows</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {forecast.scenarios.map((scenario) => {
                                            const periods = scenario.forecast_data ?? [];
                                            const lastPeriod = periods[periods.length - 1];
                                            const totalInflows = periods.reduce(
                                                (sum, p) => sum + Number(p.inflows?.total ?? 0),
                                                0,
                                            );
                                            const totalOutflows = periods.reduce(
                                                (sum, p) => sum + Number(p.outflows?.total ?? 0),
                                                0,
                                            );
                                            const finalBalance = lastPeriod
                                                ? Number(lastPeriod.closing_balance)
                                                : 0;

                                            return (
                                                <tr key={scenario.id} className="border-b last:border-0">
                                                    <td className="py-3 pr-4 font-medium">{scenario.name}</td>
                                                    <td className="py-3 pr-4 text-muted-foreground">
                                                        {scenario.adjustments.description}
                                                    </td>
                                                    <td className={`py-3 pr-4 text-right font-mono font-semibold tabular-nums ${finalBalance >= 0 ? '' : 'text-red-600'}`}>
                                                        {formatNZD(finalBalance)}
                                                    </td>
                                                    <td className="py-3 pr-4 text-right font-mono tabular-nums text-green-600">
                                                        {formatNZD(totalInflows)}
                                                    </td>
                                                    <td className="py-3 text-right font-mono tabular-nums text-red-600">
                                                        {formatNZD(totalOutflows)}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
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
