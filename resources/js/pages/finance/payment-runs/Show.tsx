import { type BreadcrumbItem } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Head, Link, router } from '@inertiajs/react';
import { Banknote, CheckCircle, Download, FileText, Play } from 'lucide-react';
import { useState } from 'react';

type PaymentRunItem = {
    id: number;
    vendor: { id: number; name: string } | null;
    bill: { id: number; bill_number: string } | null;
    amount: number;
    bank_account_number: string | null;
    reference: string;
    status: string;
};

type PaymentRun = {
    id: number;
    run_number: string;
    payment_date: string;
    status: string;
    total_amount: number;
    item_count: number;
    notes: string | null;
    approved_at: string | null;
    processed_at: string | null;
    file_path: string | null;
    bank_account: { id: number; name: string; bank_name: string } | null;
    journal: { id: number; journal_number: string } | null;
    approved_by: { id: number; name: string } | null;
    processed_by: { id: number; name: string } | null;
    items: PaymentRunItem[];
};

type PageProps = {
    paymentRun: PaymentRun;
};

const formatNZD = (amount: number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(amount);

const statusConfig: Record<string, { label: string; variant: 'default' | 'secondary' | 'outline' | 'destructive' }> = {
    draft: { label: 'Draft', variant: 'secondary' },
    approved: { label: 'Approved', variant: 'outline' },
    processing: { label: 'Processing', variant: 'default' },
    completed: { label: 'Completed', variant: 'default' },
    failed: { label: 'Failed', variant: 'destructive' },
    pending: { label: 'Pending', variant: 'secondary' },
    paid: { label: 'Paid', variant: 'default' },
};

export default function PaymentRunShow({ paymentRun }: PageProps) {
    const [approving, setApproving] = useState(false);
    const [processingRun, setProcessingRun] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance/dashboard' },
        { title: 'Payment Runs', href: '/finance/payment-runs' },
        { title: paymentRun.run_number, href: `/finance/payment-runs/${paymentRun.id}` },
    ];

    const handleApprove = () => {
        setApproving(true);
        router.post(`/finance/payment-runs/${paymentRun.id}/approve`, {}, {
            preserveScroll: true,
            onFinish: () => setApproving(false),
        });
    };

    const handleProcess = () => {
        setProcessingRun(true);
        router.post(`/finance/payment-runs/${paymentRun.id}/process`, {}, {
            preserveScroll: true,
            onFinish: () => setProcessingRun(false),
        });
    };

    const config = statusConfig[paymentRun.status] || { label: paymentRun.status, variant: 'secondary' as const };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Payment Run ${paymentRun.run_number}`} />

            <div className="max-w-7xl mx-auto p-6 space-y-6">
                {/* Header */}
                <div className="flex items-start justify-between">
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight text-foreground">{paymentRun.run_number}</h1>
                        <p className="text-muted-foreground mt-1">Payment run details and items</p>
                    </div>
                    <div className="flex items-center gap-2">
                        {paymentRun.status === 'draft' && (
                            <Button onClick={handleApprove} disabled={approving} variant="outline">
                                <CheckCircle className="mr-2 h-4 w-4" />
                                {approving ? 'Approving...' : 'Approve'}
                            </Button>
                        )}
                        {paymentRun.status === 'approved' && (
                            <Button onClick={handleProcess} disabled={processingRun}>
                                <Play className="mr-2 h-4 w-4" />
                                {processingRun ? 'Processing...' : 'Process'}
                            </Button>
                        )}
                        {paymentRun.status === 'completed' && paymentRun.file_path && (
                            <a href={`/finance/payment-runs/${paymentRun.id}/download`}>
                                <Button variant="outline">
                                    <Download className="mr-2 h-4 w-4" />
                                    Download Bank File
                                </Button>
                            </a>
                        )}
                    </div>
                </div>

                {/* Summary Card */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <Banknote className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>Payment Run Details</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                            <div>
                                <p className="text-sm text-muted-foreground">Run Number</p>
                                <p className="font-mono font-medium">{paymentRun.run_number}</p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Payment Date</p>
                                <p className="font-medium">{paymentRun.payment_date}</p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Bank Account</p>
                                <p className="font-medium">
                                    {paymentRun.bank_account
                                        ? `${paymentRun.bank_account.name} (${paymentRun.bank_account.bank_name})`
                                        : '-'}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Status</p>
                                <Badge variant={config.variant}>{config.label}</Badge>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Total Amount</p>
                                <p className="text-lg font-semibold font-mono tabular-nums">
                                    {formatNZD(paymentRun.total_amount)}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">Item Count</p>
                                <p className="font-medium">{paymentRun.item_count}</p>
                            </div>
                            {paymentRun.approved_by && (
                                <div>
                                    <p className="text-sm text-muted-foreground">Approved By</p>
                                    <p className="font-medium">{paymentRun.approved_by.name}</p>
                                    {paymentRun.approved_at && (
                                        <p className="text-xs text-muted-foreground">{paymentRun.approved_at}</p>
                                    )}
                                </div>
                            )}
                            {paymentRun.processed_by && (
                                <div>
                                    <p className="text-sm text-muted-foreground">Processed By</p>
                                    <p className="font-medium">{paymentRun.processed_by.name}</p>
                                    {paymentRun.processed_at && (
                                        <p className="text-xs text-muted-foreground">{paymentRun.processed_at}</p>
                                    )}
                                </div>
                            )}
                        </div>

                        {paymentRun.notes && (
                            <div className="mt-4 border-t pt-4">
                                <p className="text-sm text-muted-foreground">Notes</p>
                                <p className="text-sm">{paymentRun.notes}</p>
                            </div>
                        )}

                        {paymentRun.journal && (
                            <div className="mt-4 border-t pt-4">
                                <p className="text-sm text-muted-foreground">GL Journal</p>
                                <Link
                                    href={`/finance/journals/${paymentRun.journal.id}`}
                                    className="text-sm font-mono text-primary hover:underline"
                                >
                                    {paymentRun.journal.journal_number}
                                </Link>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Items Table */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <FileText className="h-5 w-5 text-muted-foreground" />
                            <CardTitle>Payment Items</CardTitle>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {paymentRun.items.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 px-4">
                                <div className="rounded-full bg-muted p-4 mb-4">
                                    <FileText className="h-8 w-8 text-muted-foreground" />
                                </div>
                                <h3 className="text-lg font-semibold text-foreground mb-1">No payment items</h3>
                                <p className="text-sm text-muted-foreground text-center max-w-sm">
                                    This payment run has no items yet.
                                </p>
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Vendor</TableHead>
                                        <TableHead>Bill #</TableHead>
                                        <TableHead className="text-right">Amount</TableHead>
                                        <TableHead>Bank Account</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {paymentRun.items.map((item) => {
                                        const itemConfig = statusConfig[item.status] || { label: item.status, variant: 'secondary' as const };
                                        return (
                                            <TableRow key={item.id}>
                                                <TableCell className="font-medium">
                                                    {item.vendor?.name || '-'}
                                                </TableCell>
                                                <TableCell className="font-mono">
                                                    {item.bill ? (
                                                        <Link
                                                            href={`/finance/bills/${item.bill.id}`}
                                                            className="text-primary hover:underline"
                                                        >
                                                            {item.bill.bill_number}
                                                        </Link>
                                                    ) : (
                                                        '-'
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums">
                                                    {formatNZD(item.amount)}
                                                </TableCell>
                                                <TableCell className="font-mono text-muted-foreground">
                                                    {item.bank_account_number || '-'}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant={itemConfig.variant}>{itemConfig.label}</Badge>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
