import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ArrowLeft } from 'lucide-react';

type ClientAging = {
    client_id: number;
    client_name: string;
    current: number;
    '1_30': number;
    '31_60': number;
    '61_90': number;
    '90_plus': number;
    total: number;
};

type Totals = {
    current: number;
    '1_30': number;
    '31_60': number;
    '61_90': number;
    '90_plus': number;
    total: number;
};

type PageProps = {
    clients: ClientAging[];
    totals: Totals;
};

const formatNZD = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const bucketColors: Record<string, string> = {
    current: 'text-green-700 dark:text-green-400',
    '1_30': 'text-yellow-700 dark:text-yellow-400',
    '31_60': 'text-orange-700 dark:text-orange-400',
    '61_90': 'text-red-600 dark:text-red-400',
    '90_plus': 'text-red-800 dark:text-red-300 font-semibold',
};

const bucketBgColors: Record<string, string> = {
    current: 'bg-green-50 dark:bg-green-950/30',
    '1_30': 'bg-yellow-50 dark:bg-yellow-950/30',
    '31_60': 'bg-orange-50 dark:bg-orange-950/30',
    '61_90': 'bg-red-50 dark:bg-red-950/20',
    '90_plus': 'bg-red-100 dark:bg-red-950/40',
};

function AmountCell({ amount, bucket }: { amount: number; bucket: string }) {
    if (amount === 0) {
        return <TableCell className="text-right text-muted-foreground">-</TableCell>;
    }
    return (
        <TableCell className={`text-right ${bucketColors[bucket] ?? ''}`}>
            {formatNZD(amount)}
        </TableCell>
    );
}

export default function AgingReport({ clients, totals }: PageProps) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Finance', href: '/finance/dashboard' },
                { title: 'Accounts Receivable', href: '/finance/receivables' },
                { title: 'Aging Report', href: '/finance/receivables/aging' },
            ]}
        >
            <Head title="Aged Receivables" />
            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Aged Receivables</h1>
                        <p className="text-sm text-muted-foreground">
                            Outstanding receivables grouped by client and aging bucket.
                        </p>
                    </div>
                    <Link href="/finance/receivables">
                        <Button variant="outline">
                            <ArrowLeft className="mr-2 h-4 w-4" />
                            Back to Receivables
                        </Button>
                    </Link>
                </div>

                {/* Summary cards for each bucket */}
                <div className="grid grid-cols-2 gap-4 md:grid-cols-6">
                    {[
                        { key: 'current', label: 'Current' },
                        { key: '1_30', label: '1-30 Days' },
                        { key: '31_60', label: '31-60 Days' },
                        { key: '61_90', label: '61-90 Days' },
                        { key: '90_plus', label: '90+ Days' },
                        { key: 'total', label: 'Total' },
                    ].map((bucket) => (
                        <Card
                            key={bucket.key}
                            className={bucket.key !== 'total' ? bucketBgColors[bucket.key] : ''}
                        >
                            <CardHeader className="pb-2">
                                <CardTitle className="text-xs font-medium text-muted-foreground">
                                    {bucket.label}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div
                                    className={`text-lg font-bold ${
                                        bucket.key !== 'total'
                                            ? bucketColors[bucket.key]
                                            : ''
                                    }`}
                                >
                                    {formatNZD(
                                        totals[bucket.key as keyof Totals]
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Aging Table */}
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Aging by Client</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {clients.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                No outstanding receivables.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Client</TableHead>
                                        <TableHead className="text-right">Current</TableHead>
                                        <TableHead className="text-right">1-30 Days</TableHead>
                                        <TableHead className="text-right">31-60 Days</TableHead>
                                        <TableHead className="text-right">61-90 Days</TableHead>
                                        <TableHead className="text-right">90+ Days</TableHead>
                                        <TableHead className="text-right">Total</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {clients.map((client) => (
                                        <TableRow key={client.client_id}>
                                            <TableCell className="font-medium">
                                                {client.client_name}
                                            </TableCell>
                                            <AmountCell
                                                amount={client.current}
                                                bucket="current"
                                            />
                                            <AmountCell
                                                amount={client['1_30']}
                                                bucket="1_30"
                                            />
                                            <AmountCell
                                                amount={client['31_60']}
                                                bucket="31_60"
                                            />
                                            <AmountCell
                                                amount={client['61_90']}
                                                bucket="61_90"
                                            />
                                            <AmountCell
                                                amount={client['90_plus']}
                                                bucket="90_plus"
                                            />
                                            <TableCell className="text-right font-bold">
                                                {formatNZD(client.total)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow className="font-bold">
                                        <TableCell>Grand Total</TableCell>
                                        <TableCell className={`text-right ${bucketColors.current}`}>
                                            {formatNZD(totals.current)}
                                        </TableCell>
                                        <TableCell className={`text-right ${bucketColors['1_30']}`}>
                                            {formatNZD(totals['1_30'])}
                                        </TableCell>
                                        <TableCell className={`text-right ${bucketColors['31_60']}`}>
                                            {formatNZD(totals['31_60'])}
                                        </TableCell>
                                        <TableCell className={`text-right ${bucketColors['61_90']}`}>
                                            {formatNZD(totals['61_90'])}
                                        </TableCell>
                                        <TableCell className={`text-right ${bucketColors['90_plus']}`}>
                                            {formatNZD(totals['90_plus'])}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {formatNZD(totals.total)}
                                        </TableCell>
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
