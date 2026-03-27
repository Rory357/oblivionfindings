import { Head, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Printer } from 'lucide-react';
import { useState } from 'react';

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
                    <TableCell className={`text-right ${entry.amount >= 0 ? '' : 'text-red-600'}`}>
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
                <TableCell className={`text-right ${total >= 0 ? '' : 'text-red-600'}`}>
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
            route('finance.reports.cash-flow'),
            { start_date: startDate, end_date: endDate },
            { preserveState: true },
        );
    };

    return (
        <AppLayout>
            <Head title="Cash Flow Statement" />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Cash Flow Statement</h1>
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
                                        className={`text-right ${report.net_cash_change >= 0 ? 'text-green-600' : 'text-red-600'}`}
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
