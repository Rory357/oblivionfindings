import { Head, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Printer } from 'lucide-react';
import { useState } from 'react';

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

    return (
        <AppLayout>
            <Head title="Profit & Loss" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Profit & Loss Statement</h1>
                    <Button variant="outline" size="sm" onClick={() => window.print()}>
                        <Printer className="mr-1 h-4 w-4" />
                        Print
                    </Button>
                </div>

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
                                        className={`text-right ${report.net_profit >= 0 ? 'text-green-600' : 'text-red-600'}`}
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
