import { Head, router } from '@inertiajs/react';
import { PageProps, type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Printer, TrendingUp, TrendingDown, DollarSign } from 'lucide-react';
import { useState, useMemo } from 'react';
import { BarChart, Bar, Cell, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';

const CHART_COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];

interface AccountRow {
    account_code: string;
    account_name: string;
    sub_type: string | null;
    amount: number;
}

interface Report {
    start_date: string;
    end_date: string;
    revenue: AccountRow[];
    total_revenue: number;
    expenses: AccountRow[];
    total_expenses: number;
    net_profit: number;
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
    { title: 'Profit & Loss' },
];

export default function ProfitAndLoss({ report, filters }: Props) {
    const [startDate, setStartDate] = useState(filters.start_date);
    const [endDate, setEndDate] = useState(filters.end_date);

    const applyFilter = () => {
        router.get(
            '/finance/reports/profit-loss',
            { start_date: startDate, end_date: endDate },
            { preserveState: true },
        );
    };

    const chartData = useMemo(() => {
        const revenueAccounts = report.revenue.map((r) => ({
            name: r.account_name.length > 25 ? r.account_name.substring(0, 25) + '...' : r.account_name,
            amount: Math.abs(r.amount),
            type: 'revenue' as const,
        }));
        const expenseAccounts = report.expenses.map((e) => ({
            name: e.account_name.length > 25 ? e.account_name.substring(0, 25) + '...' : e.account_name,
            amount: Math.abs(e.amount),
            type: 'expense' as const,
        }));
        return [...revenueAccounts, ...expenseAccounts]
            .sort((a, b) => b.amount - a.amount)
            .slice(0, 8);
    }, [report.revenue, report.expenses]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Profit & Loss" />

            <div className="mx-auto max-w-7xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Profit & Loss Statement</h1>
                        <p className="text-muted-foreground">
                            Revenue and expense summary for the selected period
                        </p>
                    </div>
                    <Button variant="outline" size="sm" onClick={() => window.print()}>
                        <Printer className="mr-1 h-4 w-4" />
                        Print
                    </Button>
                </div>

                {/* KPI Summary Cards */}
                <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div className="rounded-full bg-emerald-100 p-3 dark:bg-emerald-900/30">
                                <TrendingUp className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Total Revenue</p>
                                <p className="text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                                    {formatCurrency(report.total_revenue)}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div className="rounded-full bg-red-100 p-3 dark:bg-red-900/30">
                                <TrendingDown className="h-5 w-5 text-red-600 dark:text-red-400" />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Total Expenses</p>
                                <p className="text-2xl font-bold text-red-600 dark:text-red-400">
                                    {formatCurrency(report.total_expenses)}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div
                                className={`rounded-full p-3 ${
                                    report.net_profit >= 0
                                        ? 'bg-emerald-100 dark:bg-emerald-900/30'
                                        : 'bg-red-100 dark:bg-red-900/30'
                                }`}
                            >
                                <DollarSign
                                    className={`h-5 w-5 ${
                                        report.net_profit >= 0
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-red-600 dark:text-red-400'
                                    }`}
                                />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    {report.net_profit >= 0 ? 'Net Profit' : 'Net Loss'}
                                </p>
                                <p
                                    className={`text-2xl font-bold ${
                                        report.net_profit >= 0
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-red-600 dark:text-red-400'
                                    }`}
                                >
                                    {formatCurrency(report.net_profit)}
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

                {/* Bar Chart - Top Accounts */}
                {chartData.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Top Accounts by Amount</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={chartData} layout="vertical" margin={{ left: 20, right: 20 }}>
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis type="number" tickFormatter={(v) => formatCurrency(v)} />
                                        <YAxis type="category" dataKey="name" width={160} tick={{ fontSize: 12 }} />
                                        <Tooltip
                                            formatter={(value: number) => [formatCurrency(value), 'Amount']}
                                        />
                                        <Bar dataKey="amount" radius={[0, 4, 4, 0]}>
                                            {chartData.map((entry, index) => (
                                                <Cell
                                                    key={`cell-${index}`}
                                                    fill={entry.type === 'revenue' ? '#10b981' : '#ef4444'}
                                                />
                                            ))}
                                        </Bar>
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                            <div className="mt-3 flex items-center gap-4 text-sm text-muted-foreground">
                                <span className="flex items-center gap-1.5">
                                    <span className="inline-block h-3 w-3 rounded-sm bg-emerald-500" /> Revenue
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <span className="inline-block h-3 w-3 rounded-sm bg-red-500" /> Expense
                                </span>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Existing Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>
                            Profit & Loss: {formatDate(report.start_date)} to {formatDate(report.end_date)}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-32">Account Code</TableHead>
                                    <TableHead>Account Name</TableHead>
                                    <TableHead className="text-right">Amount</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {/* Revenue Section */}
                                <TableRow className="bg-muted/50">
                                    <TableCell colSpan={3} className="font-semibold">
                                        Revenue
                                    </TableCell>
                                </TableRow>
                                {report.revenue.map((row, idx) => (
                                    <TableRow key={`rev-${idx}`}>
                                        <TableCell className="font-mono text-sm">{row.account_code}</TableCell>
                                        <TableCell>{row.account_name}</TableCell>
                                        <TableCell className="text-right">{formatCurrency(row.amount)}</TableCell>
                                    </TableRow>
                                ))}
                                {report.revenue.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={3} className="text-muted-foreground">
                                            No revenue for this period.
                                        </TableCell>
                                    </TableRow>
                                )}
                                <TableRow className="border-t font-semibold">
                                    <TableCell colSpan={2}>Total Revenue</TableCell>
                                    <TableCell className="text-right">
                                        {formatCurrency(report.total_revenue)}
                                    </TableCell>
                                </TableRow>

                                {/* Expenses Section */}
                                <TableRow className="bg-muted/50">
                                    <TableCell colSpan={3} className="font-semibold">
                                        Expenses
                                    </TableCell>
                                </TableRow>
                                {report.expenses.map((row, idx) => (
                                    <TableRow key={`exp-${idx}`}>
                                        <TableCell className="font-mono text-sm">{row.account_code}</TableCell>
                                        <TableCell>{row.account_name}</TableCell>
                                        <TableCell className="text-right">{formatCurrency(row.amount)}</TableCell>
                                    </TableRow>
                                ))}
                                {report.expenses.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={3} className="text-muted-foreground">
                                            No expenses for this period.
                                        </TableCell>
                                    </TableRow>
                                )}
                                <TableRow className="border-t font-semibold">
                                    <TableCell colSpan={2}>Total Expenses</TableCell>
                                    <TableCell className="text-right">
                                        {formatCurrency(report.total_expenses)}
                                    </TableCell>
                                </TableRow>

                                {/* Net Profit */}
                                <TableRow className="border-t-2 text-lg font-bold">
                                    <TableCell colSpan={2}>
                                        {report.net_profit >= 0 ? 'Net Profit' : 'Net Loss'}
                                    </TableCell>
                                    <TableCell
                                        className={`text-right ${report.net_profit >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'}`}
                                    >
                                        {formatCurrency(report.net_profit)}
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
