import { Head, router } from '@inertiajs/react';
import { type BreadcrumbItem, PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Printer, RefreshCw, DollarSign, TrendingUp, TrendingDown } from 'lucide-react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';
import { useState, useMemo } from 'react';

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

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const formatPct = (pct: number) => pct.toFixed(1) + '%';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Reports', href: '/finance/reports/funding-stream-summary' },
    { title: 'Funding Stream Summary', href: '/finance/reports/funding-stream-summary' },
];

export default function FundingStreamSummary({ startDate, endDate, data }: Props) {
    const [start, setStart] = useState(startDate ?? '');
    const [end, setEnd] = useState(endDate ?? '');

    const handleGenerate = () => {
        router.get('/finance/reports/funding-stream-summary', {
            start_date: start,
            end_date: end,
        }, { preserveState: true });
    };

    const overallMarginPct = data.totals.revenue > 0
        ? (data.totals.net_margin / data.totals.revenue) * 100
        : 0;

    const chartData = useMemo(() =>
        data.streams.map(s => ({
            name: s.name.length > 18 ? s.name.substring(0, 18) + '...' : s.name,
            Revenue: s.revenue,
            Expenses: s.expenses,
        })),
        [data.streams],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Funding Stream Summary" />

            <div className="mx-auto max-w-7xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Funding Stream Summary</h1>
                        <p className="text-muted-foreground">Revenue, expenses and margin by funding stream</p>
                    </div>
                    <Button variant="outline" size="sm" onClick={() => window.print()}>
                        <Printer className="mr-1 h-4 w-4" />
                        Print
                    </Button>
                </div>

                {/* Date filter */}
                <Card>
                    <CardContent className="pt-6">
                        <div className="flex flex-wrap items-end gap-4">
                            <div className="space-y-1">
                                <Label htmlFor="start_date">Start Date</Label>
                                <Input
                                    id="start_date"
                                    type="date"
                                    value={start}
                                    onChange={(e) => setStart(e.target.value)}
                                    className="w-44"
                                />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="end_date">End Date</Label>
                                <Input
                                    id="end_date"
                                    type="date"
                                    value={end}
                                    onChange={(e) => setEnd(e.target.value)}
                                    className="w-44"
                                />
                            </div>
                            <Button onClick={handleGenerate} className="gap-2">
                                <RefreshCw className="h-4 w-4" />
                                Generate
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* KPI Cards */}
                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div className="space-y-1">
                                    <p className="text-sm text-muted-foreground">Total Revenue</p>
                                    <p className="text-2xl font-bold tabular-nums text-status-success dark:text-status-success">{formatCurrency(data.totals.revenue)}</p>
                                </div>
                                <div className="rounded-lg bg-muted p-3">
                                    <TrendingUp className="h-5 w-5 text-muted-foreground" />
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div className="space-y-1">
                                    <p className="text-sm text-muted-foreground">Total Expenses</p>
                                    <p className="text-2xl font-bold tabular-nums text-status-critical dark:text-status-critical">{formatCurrency(data.totals.expenses)}</p>
                                </div>
                                <div className="rounded-lg bg-muted p-3">
                                    <TrendingDown className="h-5 w-5 text-muted-foreground" />
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center justify-between">
                                <div className="space-y-1">
                                    <p className="text-sm text-muted-foreground">Overall Margin</p>
                                    <p className={`text-2xl font-bold tabular-nums ${overallMarginPct >= 0 ? 'text-status-success dark:text-status-success' : 'text-status-critical dark:text-status-critical'}`}>
                                        {formatPct(overallMarginPct)}
                                    </p>
                                    <p className="text-xs text-muted-foreground">{formatCurrency(data.totals.net_margin)} net</p>
                                </div>
                                <div className="rounded-lg bg-muted p-3">
                                    <DollarSign className="h-5 w-5 text-muted-foreground" />
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Chart */}
                {data.streams.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Revenue vs Expenses by Funding Stream</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-72">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={chartData} margin={{ top: 5, right: 20, left: 20, bottom: 5 }}>
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis dataKey="name" tick={{ fontSize: 12 }} />
                                        <YAxis tickFormatter={(v) => formatCurrency(v)} />
                                        <Tooltip formatter={(value) => formatCurrency(value as number)} />
                                        <Legend />
                                        <Bar dataKey="Revenue" fill="#10b981" radius={[4, 4, 0, 0]} />
                                        <Bar dataKey="Expenses" fill="#ef4444" radius={[4, 4, 0, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>Funding Stream Performance</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {data.streams.length === 0 ? (
                            <p className="text-muted-foreground text-center py-8">
                                No funding stream data for the selected period.
                            </p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Funding Stream</TableHead>
                                            <TableHead className="text-right">Revenue</TableHead>
                                            <TableHead className="text-right">Expenses</TableHead>
                                            <TableHead className="text-right">Net Margin</TableHead>
                                            <TableHead className="text-right">Margin %</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {data.streams.map((stream, idx) => (
                                            <TableRow key={idx}>
                                                <TableCell className="font-medium">{stream.name}</TableCell>
                                                <TableCell className="text-right font-mono tabular-nums">
                                                    {formatCurrency(stream.revenue)}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums">
                                                    {formatCurrency(stream.expenses)}
                                                </TableCell>
                                                <TableCell className={`text-right font-mono tabular-nums font-semibold ${stream.net_margin >= 0 ? 'text-status-success dark:text-status-success' : 'text-status-critical dark:text-status-critical'}`}>
                                                    {formatCurrency(stream.net_margin)}
                                                </TableCell>
                                                <TableCell className={`text-right font-mono tabular-nums ${stream.margin_pct >= 0 ? 'text-status-success dark:text-status-success' : 'text-status-critical dark:text-status-critical'}`}>
                                                    {formatPct(stream.margin_pct)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        <TableRow className="border-t-2 font-bold">
                                            <TableCell>Totals</TableCell>
                                            <TableCell className="text-right font-mono tabular-nums">
                                                {formatCurrency(data.totals.revenue)}
                                            </TableCell>
                                            <TableCell className="text-right font-mono tabular-nums">
                                                {formatCurrency(data.totals.expenses)}
                                            </TableCell>
                                            <TableCell className={`text-right font-mono tabular-nums ${data.totals.net_margin >= 0 ? 'text-status-success dark:text-status-success' : 'text-status-critical dark:text-status-critical'}`}>
                                                {formatCurrency(data.totals.net_margin)}
                                            </TableCell>
                                            <TableCell className={`text-right font-mono tabular-nums ${overallMarginPct >= 0 ? 'text-status-success dark:text-status-success' : 'text-status-critical dark:text-status-critical'}`}>
                                                {formatPct(overallMarginPct)}
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
