import { Head } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Printer } from 'lucide-react';

interface AgedRow {
    client_name: string;
    current: number;
    days_1_30: number;
    days_31_60: number;
    days_61_90: number;
    days_90_plus: number;
    total: number;
}

interface GrandTotal {
    current: number;
    days_1_30: number;
    days_31_60: number;
    days_61_90: number;
    days_90_plus: number;
    total: number;
}

interface Report {
    rows: AgedRow[];
    grand_total: GrandTotal;
}

interface Props extends PageProps {
    report: Report;
}

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const agingColumns = [
    { key: 'current' as const, label: 'Current', className: 'text-green-700' },
    { key: 'days_1_30' as const, label: '1-30 Days', className: 'text-yellow-700' },
    { key: 'days_31_60' as const, label: '31-60 Days', className: 'text-orange-700' },
    { key: 'days_61_90' as const, label: '61-90 Days', className: 'text-red-600' },
    { key: 'days_90_plus' as const, label: '90+ Days', className: 'text-red-800' },
];

export default function AgedReceivables({ report }: Props) {
    const breadcrumbs = [
        { title: 'Finance', href: '/finance' },
        { title: 'Reports', href: '/finance/reports/trial-balance' },
        { title: 'Aged Receivables', href: '/finance/reports/aged-receivables' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Aged Receivables" />

            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900">Aged Receivables</h1>
                        <p className="text-gray-500 mt-1">Outstanding receivables by client and aging period</p>
                    </div>
                    <Button variant="outline" size="sm" onClick={() => window.print()}>
                        <Printer className="mr-1 h-4 w-4" />
                        Print
                    </Button>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Outstanding Receivables by Client</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {report.rows.length === 0 ? (
                            <p className="text-muted-foreground text-center py-8">No outstanding receivables.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Client</TableHead>
                                            {agingColumns.map((col) => (
                                                <TableHead key={col.key} className={`text-right ${col.className}`}>
                                                    {col.label}
                                                </TableHead>
                                            ))}
                                            <TableHead className="text-right font-bold">Total</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {report.rows.map((row, idx) => (
                                            <TableRow key={idx}>
                                                <TableCell className="font-medium">{row.client_name}</TableCell>
                                                {agingColumns.map((col) => (
                                                    <TableCell key={col.key} className="text-right">
                                                        {row[col.key] > 0 ? formatCurrency(row[col.key]) : '-'}
                                                    </TableCell>
                                                ))}
                                                <TableCell className="text-right font-semibold">
                                                    {formatCurrency(row.total)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        <TableRow className="border-t-2 font-bold">
                                            <TableCell>Grand Total</TableCell>
                                            {agingColumns.map((col) => (
                                                <TableCell key={col.key} className={`text-right ${col.className}`}>
                                                    {formatCurrency(report.grand_total[col.key])}
                                                </TableCell>
                                            ))}
                                            <TableCell className="text-right">
                                                {formatCurrency(report.grand_total.total)}
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
