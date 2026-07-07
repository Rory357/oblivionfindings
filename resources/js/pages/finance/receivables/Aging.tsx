import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { PageHero, PageLayout } from '@/components/page';
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
import { ArrowLeft, Clock } from 'lucide-react';
import { formatMoney } from '@/components/finance/money';

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

const bucketColors: Record<string, string> = {
    current: 'text-status-success dark:text-status-success',
    '1_30': 'text-status-warning dark:text-status-warning',
    '31_60': 'text-status-warning dark:text-status-warning',
    '61_90': 'text-status-critical dark:text-status-critical',
    '90_plus': 'text-status-critical dark:text-status-critical font-semibold',
};

const bucketBgColors: Record<string, string> = {
    current: 'bg-status-success-bg',
    '1_30': 'bg-status-warning-bg',
    '31_60': 'bg-status-warning-bg',
    '61_90': 'bg-status-critical-bg',
    '90_plus': 'bg-status-critical-bg',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Accounts Receivable', href: '/finance/receivables' },
    { title: 'Aging Report', href: '/finance/receivables/aging' },
];

function AmountCell({ amount, bucket }: { amount: number; bucket: string }) {
    if (amount === 0) {
        return <TableCell className="text-right text-muted-foreground">-</TableCell>;
    }
    return (
        <TableCell className={`text-right ${bucketColors[bucket] ?? ''}`}>
            {formatMoney(amount)}
        </TableCell>
    );
}

export default function AgingReport({ clients, totals }: PageProps) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Aged Receivables" />
            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={Clock}
                        title="Aged Receivables"
                        description="Outstanding receivables grouped by client and aging bucket."
                        stats={[
                            { label: 'Total', value: formatMoney(totals.total) },
                            { label: 'Current', value: formatMoney(totals.current) },
                            { label: '31-90', value: formatMoney(totals['31_60'] + totals['61_90']) },
                            { label: '90+', value: formatMoney(totals['90_plus']) },
                        ]}
                        actions={
                            <Link href="/finance/receivables">
                                <Button variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground">
                                    <ArrowLeft className="mr-2 h-4 w-4" />
                                    Back to Receivables
                                </Button>
                            </Link>
                        }
                    />
                }
            >
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
                                    {formatMoney(
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
                                                {formatMoney(client.total)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow className="font-bold">
                                        <TableCell>Grand Total</TableCell>
                                        <TableCell className={`text-right ${bucketColors.current}`}>
                                            {formatMoney(totals.current)}
                                        </TableCell>
                                        <TableCell className={`text-right ${bucketColors['1_30']}`}>
                                            {formatMoney(totals['1_30'])}
                                        </TableCell>
                                        <TableCell className={`text-right ${bucketColors['31_60']}`}>
                                            {formatMoney(totals['31_60'])}
                                        </TableCell>
                                        <TableCell className={`text-right ${bucketColors['61_90']}`}>
                                            {formatMoney(totals['61_90'])}
                                        </TableCell>
                                        <TableCell className={`text-right ${bucketColors['90_plus']}`}>
                                            {formatMoney(totals['90_plus'])}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {formatMoney(totals.total)}
                                        </TableCell>
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
