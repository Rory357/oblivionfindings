import { Head, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Printer } from 'lucide-react';
import { useState } from 'react';

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

    return (
        <AppLayout>
            <Head title="Trial Balance" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Trial Balance</h1>
                    <Button variant="outline" size="sm" onClick={() => window.print()}>
                        <Printer className="mr-1 h-4 w-4" />
                        Print
                    </Button>
                </div>

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
                                    <>
                                        <TableRow key={`header-${group.type}`} className="bg-muted/50">
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
                                    </>
                                ))}
                                <TableRow className="border-t-2 font-bold">
                                    <TableCell colSpan={2}>Totals</TableCell>
                                    <TableCell className="text-right">{formatCurrency(report.total_debits)}</TableCell>
                                    <TableCell className="text-right">{formatCurrency(report.total_credits)}</TableCell>
                                </TableRow>
                                <TableRow>
                                    <TableCell colSpan={4} className="text-center">
                                        {isBalanced ? (
                                            <span className="font-medium text-green-600">
                                                Trial balance is in balance.
                                            </span>
                                        ) : (
                                            <span className="font-medium text-red-600">
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
