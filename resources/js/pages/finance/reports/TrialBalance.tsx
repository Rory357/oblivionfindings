import { Head, router } from '@inertiajs/react';
import { PageProps, type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Printer, CheckCircle, AlertTriangle } from 'lucide-react';
import { Fragment, useState, useMemo } from 'react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend } from 'recharts';

const CHART_COLORS = ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16'];

interface TrialBalanceRow {
    account_code: string;
    account_name: string;
    account_type: string;
    debit_balance: number;
    credit_balance: number;
}

interface Report {
    as_of_date: string;
    rows: TrialBalanceRow[];
    total_debits: number;
    total_credits: number;
}

interface Props extends PageProps {
    report: Report;
    filters: { as_of_date: string };
}

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const typeLabels: Record<string, string> = {
    asset: 'Assets',
    liability: 'Liabilities',
    equity: 'Equity',
    revenue: 'Revenue',
    expense: 'Expenses',
};

const typeOrder = ['asset', 'liability', 'equity', 'revenue', 'expense'];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Reports' },
    { title: 'Trial Balance' },
];

export default function TrialBalance({ report, filters }: Props) {
    const [asOfDate, setAsOfDate] = useState(filters.as_of_date);

    const applyFilter = () => {
        router.get('/finance/reports/trial-balance', { as_of_date: asOfDate }, { preserveState: true });
    };

    const grouped = typeOrder
        .map((type) => ({
            type,
            label: typeLabels[type],
            rows: report.rows.filter((r) => r.account_type === type),
        }))
        .filter((g) => g.rows.length > 0);

    const isBalanced = Math.abs(report.total_debits - report.total_credits) < 0.01;

    const chartData = useMemo(() => {
        return typeOrder
            .map((type) => {
                const rows = report.rows.filter((r) => r.account_type === type);
                if (rows.length === 0) return null;
                return {
                    name: typeLabels[type],
                    debit: rows.reduce((sum, r) => sum + r.debit_balance, 0),
                    credit: rows.reduce((sum, r) => sum + r.credit_balance, 0),
                };
            })
            .filter(Boolean) as { name: string; debit: number; credit: number }[];
    }, [report.rows]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Trial Balance" />

            <div className="mx-auto max-w-7xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">Trial Balance</h1>
                        <p className="text-muted-foreground">
                            Summary of all account balances verifying debits equal credits
                        </p>
                    </div>
                    <Button variant="outline" size="sm" onClick={() => window.print()}>
                        <Printer className="mr-1 h-4 w-4" />
                        Print
                    </Button>
                </div>

                {/* KPI Summary Cards */}
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <Card>
                        <CardContent className="flex items-center justify-between pt-6">
                            <div>
                                <p className="text-sm text-muted-foreground">Total Debits</p>
                                <p className="text-2xl font-bold text-blue-600 dark:text-blue-400">
                                    {formatCurrency(report.total_debits)}
                                </p>
                            </div>
                            {isBalanced ? (
                                <Badge variant="outline" className="border-emerald-300 text-emerald-600 dark:text-emerald-400">
                                    <CheckCircle className="mr-1 h-3 w-3" />
                                    Balanced
                                </Badge>
                            ) : (
                                <Badge variant="destructive">
                                    <AlertTriangle className="mr-1 h-3 w-3" />
                                    Unbalanced
                                </Badge>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center justify-between pt-6">
                            <div>
                                <p className="text-sm text-muted-foreground">Total Credits</p>
                                <p className="text-2xl font-bold text-violet-600 dark:text-violet-400">
                                    {formatCurrency(report.total_credits)}
                                </p>
                            </div>
                            {!isBalanced && (
                                <div className="text-right">
                                    <p className="text-xs text-muted-foreground">Difference</p>
                                    <p className="text-sm font-semibold text-red-600 dark:text-red-400">
                                        {formatCurrency(Math.abs(report.total_debits - report.total_credits))}
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Filter */}
                <Card>
                    <CardContent className="flex items-end gap-4 pt-6">
                        <div>
                            <label className="mb-1 block text-sm font-medium">As of Date</label>
                            <Input
                                type="date"
                                value={asOfDate}
                                onChange={(e) => setAsOfDate(e.target.value)}
                                className="w-48"
                            />
                        </div>
                        <Button onClick={applyFilter}>Generate</Button>
                    </CardContent>
                </Card>

                {/* Bar Chart - Debits/Credits by Account Type */}
                {chartData.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Debits & Credits by Account Type</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={chartData} margin={{ top: 5, right: 20, bottom: 5, left: 20 }}>
                                        <CartesianGrid strokeDasharray="3 3" />
                                        <XAxis dataKey="name" />
                                        <YAxis tickFormatter={(v) => formatCurrency(v)} />
                                        <Tooltip formatter={(value: number) => formatCurrency(value)} />
                                        <Legend />
                                        <Bar dataKey="debit" name="Debit" fill="#3b82f6" radius={[4, 4, 0, 0]} />
                                        <Bar dataKey="credit" name="Credit" fill="#8b5cf6" radius={[4, 4, 0, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Existing Table */}
                <Card>
                    <CardHeader>
                        <CardTitle>
                            Trial Balance as at{' '}
                            {new Date(report.as_of_date).toLocaleDateString('en-NZ', {
                                day: '2-digit',
                                month: 'long',
                                year: 'numeric',
                            })}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-32">Account Code</TableHead>
                                    <TableHead>Account Name</TableHead>
                                    <TableHead className="text-right">Debit</TableHead>
                                    <TableHead className="text-right">Credit</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {grouped.map((group) => (
                                    <Fragment key={`group-${group.type}`}>
                                        <TableRow className="bg-muted/50">
                                            <TableCell colSpan={4} className="font-semibold">
                                                {group.label}
                                            </TableCell>
                                        </TableRow>
                                        {group.rows.map((row, idx) => (
                                            <TableRow key={`${group.type}-${idx}`}>
                                                <TableCell className="font-mono text-sm">{row.account_code}</TableCell>
                                                <TableCell>{row.account_name}</TableCell>
                                                <TableCell className="text-right">
                                                    {row.debit_balance > 0 ? formatCurrency(row.debit_balance) : ''}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {row.credit_balance > 0 ? formatCurrency(row.credit_balance) : ''}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </Fragment>
                                ))}
                                <TableRow className="border-t-2 font-bold">
                                    <TableCell colSpan={2}>Totals</TableCell>
                                    <TableCell className="text-right">{formatCurrency(report.total_debits)}</TableCell>
                                    <TableCell className="text-right">{formatCurrency(report.total_credits)}</TableCell>
                                </TableRow>
                                <TableRow>
                                    <TableCell colSpan={4} className="text-center">
                                        {isBalanced ? (
                                            <span className="font-medium text-emerald-600 dark:text-emerald-400">
                                                Trial balance is in balance.
                                            </span>
                                        ) : (
                                            <span className="font-medium text-red-600 dark:text-red-400">
                                                Warning: Trial balance is out of balance by{' '}
                                                {formatCurrency(Math.abs(report.total_debits - report.total_credits))}.
                                            </span>
                                        )}
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
