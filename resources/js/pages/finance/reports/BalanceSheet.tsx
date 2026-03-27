import { Head, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Printer, CheckCircle, AlertTriangle } from 'lucide-react';
import { useState } from 'react';

interface AccountRow {
    account_code: string;
    account_name: string;
    sub_type: string | null;
    balance: number;
}

interface Report {
    as_of_date: string;
    assets: AccountRow[];
    total_assets: number;
    liabilities: AccountRow[];
    total_liabilities: number;
    equity: AccountRow[];
    total_equity: number;
    balanced: boolean;
}

interface Props extends PageProps {
    report: Report;
    filters: { as_of_date: string };
}

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

function SectionTable({ title, rows, total }: { title: string; rows: AccountRow[]; total: number }) {
    return (
        <>
            <TableRow className="bg-muted/50">
                <TableCell colSpan={3} className="font-semibold">
                    {title}
                </TableCell>
            </TableRow>
            {rows.map((row, idx) => (
                <TableRow key={`${title}-${idx}`}>
                    <TableCell className="font-mono text-sm">{row.account_code || '-'}</TableCell>
                    <TableCell>{row.account_name}</TableCell>
                    <TableCell className="text-right">{formatCurrency(row.balance)}</TableCell>
                </TableRow>
            ))}
            {rows.length === 0 && (
                <TableRow>
                    <TableCell colSpan={3} className="text-muted-foreground">
                        No accounts.
                    </TableCell>
                </TableRow>
            )}
            <TableRow className="border-t font-semibold">
                <TableCell colSpan={2}>Total {title}</TableCell>
                <TableCell className="text-right">{formatCurrency(total)}</TableCell>
            </TableRow>
        </>
    );
}

export default function BalanceSheet({ report, filters }: Props) {
    const [asOfDate, setAsOfDate] = useState(filters.as_of_date);

    const applyFilter = () => {
        router.get('/finance/reports/balance-sheet', { as_of_date: asOfDate }, { preserveState: true });
    };

    return (
        <AppLayout>
            <Head title="Balance Sheet" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Balance Sheet</h1>
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
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle>
                            Balance Sheet as at{' '}
                            {new Date(report.as_of_date).toLocaleDateString('en-NZ', {
                                day: '2-digit',
                                month: 'long',
                                year: 'numeric',
                            })}
                        </CardTitle>
                        {report.balanced ? (
                            <Badge variant="outline" className="border-green-300 text-green-600">
                                <CheckCircle className="mr-1 h-3 w-3" />
                                Balanced
                            </Badge>
                        ) : (
                            <Badge variant="destructive">
                                <AlertTriangle className="mr-1 h-3 w-3" />
                                Out of Balance
                            </Badge>
                        )}
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-32">Account Code</TableHead>
                                    <TableHead>Account Name</TableHead>
                                    <TableHead className="text-right">Balance</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <SectionTable title="Assets" rows={report.assets} total={report.total_assets} />
                                <SectionTable
                                    title="Liabilities"
                                    rows={report.liabilities}
                                    total={report.total_liabilities}
                                />
                                <SectionTable title="Equity" rows={report.equity} total={report.total_equity} />

                                <TableRow className="border-t-2 text-lg font-bold">
                                    <TableCell colSpan={2}>Total Liabilities + Equity</TableCell>
                                    <TableCell className="text-right">
                                        {formatCurrency(report.total_liabilities + report.total_equity)}
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
