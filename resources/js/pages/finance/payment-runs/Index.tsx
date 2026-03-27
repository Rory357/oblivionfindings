import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Head, Link, router } from '@inertiajs/react';
import { Banknote, Plus } from 'lucide-react';

type PaymentRun = {
    id: number;
    run_number: string;
    payment_date: string;
    bank_account: { id: number; name: string; bank_name: string } | null;
    item_count: number;
    total_amount: number;
    status: string;
    processed_at: string | null;
};

type PaginatedData<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

type PageProps = {
    paymentRuns: PaginatedData<PaymentRun>;
    filters: { status: string };
};

const formatNZD = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const statusConfig: Record<string, { label: string; variant: 'default' | 'secondary' | 'outline' | 'destructive' }> = {
    draft: { label: 'Draft', variant: 'secondary' },
    approved: { label: 'Approved', variant: 'outline' },
    processing: { label: 'Processing', variant: 'default' },
    completed: { label: 'Completed', variant: 'default' },
    failed: { label: 'Failed', variant: 'destructive' },
};

export default function PaymentRunsIndex({ paymentRuns, filters }: PageProps) {
    const breadcrumbs = [
        { title: 'Finance', href: route('finance.dashboard') },
        { title: 'Payment Runs', href: route('finance.payment-runs.index') },
    ];

    const handleStatusFilter = (value: string) => {
        router.get(
            route('finance.payment-runs.index'),
            { status: value === 'all' ? '' : value },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payment Runs" />

            <div className="mx-auto max-w-6xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Payment Runs</h1>
                        <p className="text-muted-foreground">Manage batch payments to vendors</p>
                    </div>
                    <Link href={route('finance.payment-runs.create')}>
                        <Button>
                            <Plus className="mr-2 h-4 w-4" />
                            New Payment Run
                        </Button>
                    </Link>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <Banknote className="h-5 w-5 text-muted-foreground" />
                                <CardTitle>Payment Runs</CardTitle>
                            </div>
                            <Select
                                value={filters.status || 'all'}
                                onValueChange={handleStatusFilter}
                            >
                                <SelectTrigger className="w-[160px]">
                                    <SelectValue placeholder="Filter by status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="draft">Draft</SelectItem>
                                    <SelectItem value="approved">Approved</SelectItem>
                                    <SelectItem value="processing">Processing</SelectItem>
                                    <SelectItem value="completed">Completed</SelectItem>
                                    <SelectItem value="failed">Failed</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {paymentRuns.data.length === 0 ? (
                            <div className="py-12 text-center text-muted-foreground">
                                No payment runs found. Create one to get started.
                            </div>
                        ) : (
                            <>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Run #</TableHead>
                                            <TableHead>Payment Date</TableHead>
                                            <TableHead>Bank Account</TableHead>
                                            <TableHead className="text-center">Items</TableHead>
                                            <TableHead className="text-right">Total Amount</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Processed At</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {paymentRuns.data.map((run) => {
                                            const config = statusConfig[run.status] || { label: run.status, variant: 'secondary' as const };
                                            return (
                                                <TableRow
                                                    key={run.id}
                                                    className="cursor-pointer"
                                                    onClick={() => router.visit(route('finance.payment-runs.show', run.id))}
                                                >
                                                    <TableCell className="font-mono font-medium">
                                                        {run.run_number}
                                                    </TableCell>
                                                    <TableCell>{run.payment_date}</TableCell>
                                                    <TableCell>
                                                        {run.bank_account
                                                            ? `${run.bank_account.name} (${run.bank_account.bank_name})`
                                                            : '-'}
                                                    </TableCell>
                                                    <TableCell className="text-center">{run.item_count}</TableCell>
                                                    <TableCell className="text-right font-mono tabular-nums">
                                                        {formatNZD(run.total_amount)}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge variant={config.variant}>{config.label}</Badge>
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {run.processed_at || '-'}
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>

                                {paymentRuns.last_page > 1 && (
                                    <div className="mt-4 flex items-center justify-center gap-1">
                                        {paymentRuns.links.map((link, i) => (
                                            <Button
                                                key={i}
                                                variant={link.active ? 'default' : 'outline'}
                                                size="sm"
                                                disabled={!link.url}
                                                onClick={() => {
                                                    if (link.url) {
                                                        router.visit(link.url);
                                                    }
                                                }}
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                            />
                                        ))}
                                    </div>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
