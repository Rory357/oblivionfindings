import { Head, router } from '@inertiajs/react';
import { PageProps, type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Printer, ArrowUpCircle, ArrowDownCircle, TrendingUp, Wallet } from 'lucide-react';
import { useState, useMemo } from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Cell } from 'recharts';

const CHART_COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];

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

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'long', year: 'numeric' });

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Reports' },
    { title: 'Cash Flow' },
];

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
                        className={`text-right ${entry.amount < 0 ? 'text-red-600 dark:text-red-400' : ''}`}
                    >
                        {formatCurrency(entry.amount)}
                    </TableCell>
                </TableRow>
            ))}
            {entries.length === 0 && (
                <TableRow>
                    <TableCell colSpan={2} className="pl-8 text-muted-foreground">
                        No activity.
                    </TableCell>
                </TableRow>
            )}
            <TableRow className="border-t font-semibold">
                <TableCell>Net {title}</TableCell>
                <TableCell
                    className={`text-right ${total < 0 ? 'text-red-600 dark:text-red-400' : ''}`}
                >
                    {formatCurrency(total)}
                </TableCell>
            </TableRow>
        </>
    );
}

export default function CashFlow({ report, filters }: Props) {
    const [startDate, setStartDate] = useState(filters.start_date);
    const [endDate, setEndDate] = useState(filters.end_date);

    const applyFilter = () => {
        router.get(
            '/finance/reports/cash-flow',
            { start_date: startDate, end_date: endDate },
            { preserveState: true },
        );
    };

    const barData = useMemo(
        () => [
            { name: 'Operating', amount: report.total_operating },
            { name: 'Investing', amount: report.total_investing },
            { name: 'Financing', amount: report.total_financing },
        ],
        [report.total_operating, report.total_investing, report.total_financing],
    );

    const cashCompareData = useMemo(
        () => [
            { name: 'Opening Cash', amount: report.opening_cash },
            { name: 'Closing Cash', amount: report.closing_cash },
        ],
        [report.opening_cash, report.closing_cash],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cash Flow Statement" />

            <div className="mx-auto max-w-7xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Cash Flow Statement</h1>
                        <p className="text-muted-foreground">
                            Cash inflows and outflows across operating, investing, and financing activities
                        </p>
                    </div>
                    <Button variant="outline" size="sm" onClick={() => window.print()}>
                        <Printer className="mr-1 h-4 w-4" />
                        Print
                    </Button>
                </div>

                {/* KPI Summary Cards */}
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div
                                className={`rounded-full p-3 ${
                                    report.total_operating >= 0
                                        ? 'bg-emerald-100 dark:bg-emerald-900/30'
                                        : 'bg-red-100 dark:bg-red-900/30'
                                }`}
                            >
                                <ArrowUpCircle
                                    className={`h-5 w-5 ${
                                        report.total_operating >= 0
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-red-600 dark:text-red-400'
                                    }`}
                                />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Operating</p>
                                <p
                                    className={`text-2xl font-bold ${
                                        report.total_operating >= 0
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-red-600 dark:text-red-400'
                                    }`}
                                >
                                    {formatCurrency(report.total_operating)}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div
                                className={`rounded-full p-3 ${
                                    report.total_investing >= 0
                                        ? 'bg-emerald-100 dark:bg-emerald-900/30'
                                        : 'bg-red-100 dark:bg-red-900/30'
                                }`}
                            >
                                <ArrowDownCircle
                                    className={`h-5 w-5 ${
                                        report.total_investing >= 0
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-red-600 dark:text-red-400'
                                    }`}
                                />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Investing</p>
                                <p
                                    className={`text-2xl font-bold ${
                                        report.total_investing >= 0
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-red-600 dark:text-red-400'
                                    }`}
                                >
                                    {formatCurrency(report.total_investing)}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div
                                className={`rounded-full p-3 ${
                                    report.total_financing >= 0
                                        ? 'bg-emerald-100 dark:bg-emerald-900/30'
                                        : 'bg-red-100 dark:bg-red-900/30'
                                }`}
                            >
                                <TrendingUp
                                    className={`h-5 w-5 ${
                                        report.total_financing >= 0
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-red-600 dark:text-red-400'
                                    }`}
                                />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Financing</p>
                                <p
                                    className={`text-2xl font-bold ${
                                        report.total_financing >= 0
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-red-600 dark:text-red-400'
                                    }`}
                                >
                                    {formatCurrency(report.total_financing)}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div
                                className={`rounded-full p-3 ${
                                    report.net_cash_change >= 0
                                        ? 'bg-emerald-100 dark:bg-emerald-900/30'
                                        : 'bg-red-100 dark:bg-red-900/30'
                                }`}
                            >
                                <Wallet
                                    className={`h-5 w-5 ${
                                        report.net_cash_change >= 0
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-red-600 dark:text-red-400'
                                    }`}
                                />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Net Cash Change</p>
                                <p
                                    className={`text-2xl font-bold ${
                                        report.net_cash_change >= 0
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-red-600 dark:text-red-400'
                                    }`}
                                >
                                    {formatCurrency(report.net_cash_change)}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filter */}
                <Card>
                    <CardContent className="flex items-end gap-4 pt-6">
                        <div>
                            <label className="mb-1 block text-sm font-medium">Start Date</label>
                            <Input
                                type="date"
                                value={startDate}
                                onChange={(e) => setStartDate(e.target.value)}
                                className="w-48"
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium">End Date</label>
                            <Input
                                type="date"
                                value={endDate}
                                onChange={(e) => setEndDate(e.target.value)}
                                className="w-48"
                            />
                        </div>
                        <Button onClick={applyFilter}>Generate</Button>
                    </CardContent>
                </Card>

                {/* Charts Row */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    {/* Activity Bar Chart */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Cash Flow by Activity</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={barData} margin={{ top: 5, right: 20, bottom: 5, left: 20 }}>
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis dataKey="name" />
                                        <YAxis tickFormatter={(v) => formatCurrency(v)} />
                                        <Tooltip formatter={(value?: number) => [formatCurrency(value ?? 0), 'Amount']} />
                                        <Bar dataKey="amount" radius={[4, 4, 0, 0]}>
                                            {barData.map((entry, index) => (
                                                <Cell
                                                    key={`cell-${index}`}
                                                    fill={entry.amount >= 0 ? '#10b981' : '#ef4444'}
                                                />
                                            ))}
                                        </Bar>
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Opening vs Closing Cash */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Opening vs Closing Cash</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={cashCompareData} margin={{ top: 5, right: 20, bottom: 5, left: 20 }}>
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis dataKey="name" />
                                        <YAxis tickFormatter={(v) => formatCurrency(v)} />
                                        <Tooltip formatter={(value?: number) => [formatCurrency(value ?? 0), 'Cash']} />
                                        <Bar dataKey="amount" radius={[4, 4, 0, 0]}>
                                            <Cell fill="#3b82f6" />
                                            <Cell fill="#8b5cf6" />
                                        </Bar>
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Existing Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>
                            Cash Flow: {formatDate(report.start_date)} to {formatDate(report.end_date)}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Description</TableHead>
                                    <TableHead className="text-right">Amount</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {/* Opening Cash */}
                                <TableRow className="font-semibold">
                                    <TableCell>Opening Cash Balance</TableCell>
                                    <TableCell className="text-right">
                                        {formatCurrency(report.opening_cash)}
                                    </TableCell>
                                </TableRow>

                                <CashFlowSection
                                    title="Operating Activities"
                                    entries={report.operating}
                                    total={report.total_operating}
                                />
                                <CashFlowSection
                                    title="Investing Activities"
                                    entries={report.investing}
                                    total={report.total_investing}
                                />
                                <CashFlowSection
                                    title="Financing Activities"
                                    entries={report.financing}
                                    total={report.total_financing}
                                />

                                {/* Net Cash Change */}
                                <TableRow className="border-t-2 text-lg font-bold">
                                    <TableCell>Net Cash Change</TableCell>
                                    <TableCell
                                        className={`text-right ${
                                            report.net_cash_change >= 0
                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                : 'text-red-600 dark:text-red-400'
                                        }`}
                                    >
                                        {formatCurrency(report.net_cash_change)}
                                    </TableCell>
                                </TableRow>

                                {/* Closing Cash */}
                                <TableRow className="text-lg font-bold">
                                    <TableCell>Closing Cash Balance</TableCell>
                                    <TableCell className="text-right">
                                        {formatCurrency(report.closing_cash)}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
