import { Head, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Printer, RefreshCw } from 'lucide-react';
import { useState } from 'react';

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

export default function FundingStreamSummary({ startDate, endDate, data }: Props) {
    const [start, setStart] = useState(startDate ?? '');
    const [end, setEnd] = useState(endDate ?? '');

    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Reports', href: '/finance/reports/trial-balance' },
        { title: 'Funding Stream Summary', href: '/finance/reports/funding-stream-summary' },
    ];

    const handleGenerate = () => {
        router.get('/finance/reports/funding-stream-summary', {
            start_date: start,
            end_date: end,
        }, { preserveState: true });
    };

    const overallMarginPct = data.totals.revenue > 0
        ? (data.totals.net_margin / data.totals.revenue) * 100
        : 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Funding Stream Summary" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">Funding Stream Summary</h1>
                        <p className="text-gray-500 mt-1">Revenue, expenses and margin by funding stream</p>
                    </div>
                    <Button variant="outline" size="sm" onClick={() => window.print()}>
                        <Printer className="mr-1 h-4 w-4" />
                        Print
                    </Button>
                </div>

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
                                                <TableCell className={`text-right font-mono tabular-nums font-semibold ${stream.net_margin >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                                                    {formatCurrency(stream.net_margin)}
                                                </TableCell>
                                                <TableCell className={`text-right font-mono tabular-nums ${stream.margin_pct >= 0 ? 'text-green-700' : 'text-red-700'}`}>
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
                                            <TableCell className={`text-right font-mono tabular-nums ${data.totals.net_margin >= 0 ? 'text-green-700' : 'text-red-700'}`}>
                                                {formatCurrency(data.totals.net_margin)}
                                            </TableCell>
                                            <TableCell className={`text-right font-mono tabular-nums ${overallMarginPct >= 0 ? 'text-green-700' : 'text-red-700'}`}>
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
