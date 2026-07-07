import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { type BreadcrumbItem } from '@/types';
import { PageHero, PageLayout } from '@/components/page';
import { ReceivablesTabsFooter } from '@/components/finance';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Wallet, ArrowLeftRight } from 'lucide-react';

type Allocation = {
    id: number;
    type: string;
    payment_date: string;
    amount: number;
    allocatable_type: string;
    allocatable_id: number | null;
    notes: string | null;
    created_at: string;
};

type Props = {
    allocations: {
        data: Allocation[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
        per_page: number;
    };
    filters: {
        type: string;
    };
};

const ANY = '__ANY__';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Payment Allocations', href: '/finance/payment-allocations' },
];

const formatCurrency = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });

export default function PaymentAllocationsIndex({ allocations, filters }: Props) {
    const totalAllocated = allocations.data.reduce((total, allocation) => total + allocation.amount, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payment Allocations" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={ArrowLeftRight}
                        title="Payment Allocations"
                        description="Track how incoming payments have been allocated across invoices and bills."
                        stats={[
                            { label: 'Allocations', value: allocations.total },
                            { label: 'Total (this page)', value: formatCurrency(totalAllocated) },
                        ]}
                        actions={
                            <div className="w-44">
                                <Select
                                    value={filters.type || ANY}
                                    onValueChange={(value) =>
                                        router.get(
                                            '/finance/payment-allocations',
                                            { type: value === ANY ? '' : value },
                                            { preserveState: true, preserveScroll: true },
                                        )
                                    }
                                >
                                    <SelectTrigger className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20">
                                        <SelectValue placeholder="All types" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>All types</SelectItem>
                                        <SelectItem value="payable">Payable</SelectItem>
                                        <SelectItem value="receivable">Receivable</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        }
                        footer={<ReceivablesTabsFooter active="allocations" />}
                    />
                }
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardContent className="flex items-center gap-4 pt-6">
                            <div className="rounded-lg bg-primary/10 p-3">
                                <Wallet className="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Allocations</p>
                                <p className="text-2xl font-bold">{allocations.total}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <p className="text-sm text-muted-foreground">Total allocated</p>
                            <p className="text-2xl font-bold">
                                {formatCurrency(
                                    allocations.data.reduce((total, allocation) => total + allocation.amount, 0),
                                )}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Allocation History</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Date</TableHead>
                                        <TableHead>Type</TableHead>
                                        <TableHead>Target</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                        <TableHead>Notes</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {allocations.data.length === 0 ? (
                                        <TableRow>
                                            <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                                                No payment allocations found for the selected filter.
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        allocations.data.map((allocation) => (
                                            <TableRow key={allocation.id}>
                                                <TableCell>{formatDate(allocation.payment_date)}</TableCell>
                                                <TableCell>
                                                    <Badge variant="outline" className="capitalize">
                                                        {allocation.type}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    {allocation.allocatable_type || 'Unlinked'}
                                                    {allocation.allocatable_id ? ` #${allocation.allocatable_id}` : ''}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums">
                                                    {formatCurrency(allocation.amount)}
                                                </TableCell>
                                                <TableCell className="max-w-sm truncate text-muted-foreground">
                                                    {allocation.notes || '-'}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </PageLayout>
        </AppLayout>
    );
}
