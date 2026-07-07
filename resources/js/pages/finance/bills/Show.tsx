import { Head, Link, router } from '@inertiajs/react';
import { PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Separator } from '@/components/ui/separator';
import { AlertTriangle, CheckCircle, Edit, XCircle, FileText } from 'lucide-react';
import { PageHero, PageLayout } from '@/components/page';
import { cn } from '@/lib/utils';

interface BillLine {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    gst_rate: string;
    gst_amount: string;
    line_total: string;
    account: { id: number; code: string; name: string } | null;
    cost_centre: { id: number; code: string; name: string } | null;
    funding_stream: { id: number; code: string; name: string } | null;
}

interface PaymentAllocation {
    id: number;
    payment_date: string;
    amount: string;
    notes: string | null;
}

interface Bill {
    id: number;
    bill_number: string;
    vendor_reference: string | null;
    vendor: { id: number; name: string } | null;
    status: string;
    bill_date: string;
    due_date: string;
    subtotal: string;
    gst_amount: string;
    total_amount: string;
    amount_paid: string;
    notes: string | null;
    approved_by: { id: number; name: string } | null;
    approved_at: string | null;
    journal: { id: number; journal_number: string; status: string; posted_at: string } | null;
    purchase_order: { id: number; po_number: string } | null;
    lines: BillLine[];
    payment_allocations: PaymentAllocation[];
}

interface Props extends PageProps {
    bill: Bill;
}

const formatCurrency = (amount: string | number) =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(Number(amount));

const formatDate = (date: string | null) =>
    date ? new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';

const formatDateTime = (date: string | null) =>
    date ? new Date(date).toLocaleString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';

const statusConfig: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draft', className: 'bg-muted text-foreground' },
    awaiting_approval: { label: 'Awaiting Approval', className: 'bg-status-warning-bg text-status-warning' },
    approved: { label: 'Approved', className: 'bg-status-info-bg text-status-info' },
    partially_paid: { label: 'Partially Paid', className: 'bg-status-warning-bg text-status-warning' },
    paid: { label: 'Paid', className: 'bg-status-success-bg text-status-success' },
    cancelled: { label: 'Cancelled', className: 'bg-status-critical-bg text-status-critical' },
};

export default function BillShow({ auth, bill }: Props) {
    const isOverdue = bill.status !== 'paid' && bill.status !== 'cancelled' && new Date(bill.due_date) < new Date();
    const isDraft = bill.status === 'draft';
    const canCancel = bill.status === 'draft' || bill.status === 'awaiting_approval';
    const amountDue = Number(bill.total_amount) - Number(bill.amount_paid);

    const handleApprove = () => {
        router.post(`/finance/bills/${bill.id}/approve`);
    };

    const handleCancel = () => {
        if (confirm('Are you sure you want to cancel this bill?')) {
            router.post(`/finance/bills/${bill.id}/cancel`);
        }
    };

    return (
        <AppLayout
            user={auth.user}
            breadcrumbs={[
                { title: 'Finance', href: '/finance' },
                { title: 'Bills', href: '/finance/bills' },
                { title: bill.bill_number, href: `/finance/bills/${bill.id}` },
            ]}
        >
            <Head title={`Bill ${bill.bill_number}`} />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        variant="compact"
                        backHref="/finance/bills"
                        title={
                            <span className="flex flex-wrap items-center gap-3">
                                {bill.bill_number}
                                <Badge className={statusConfig[bill.status]?.className ?? 'bg-muted text-foreground'}>
                                    {statusConfig[bill.status]?.label ?? bill.status}
                                </Badge>
                                {isOverdue && (
                                    <Badge className="bg-status-critical-bg text-status-critical">
                                        <AlertTriangle className="w-3 h-3 mr-1" />
                                        Overdue
                                    </Badge>
                                )}
                            </span>
                        }
                        description={
                            <>
                                {bill.vendor?.name ?? 'Unknown vendor'}
                                {bill.vendor_reference && <span> - Ref: {bill.vendor_reference}</span>}
                            </>
                        }
                        actions={
                            <>
                                {isDraft && (
                                    <>
                                        <Button variant="outline" asChild>
                                            <Link href={`/finance/bills/${bill.id}/edit`}>
                                                <Edit className="w-4 h-4 mr-2" />
                                                Edit
                                            </Link>
                                        </Button>
                                        <Button onClick={handleApprove}>
                                            <CheckCircle className="w-4 h-4 mr-2" />
                                            Approve
                                        </Button>
                                    </>
                                )}
                                {canCancel && (
                                    <Button variant="destructive" onClick={handleCancel}>
                                        <XCircle className="w-4 h-4 mr-2" />
                                        Cancel
                                    </Button>
                                )}
                            </>
                        }
                    />
                }
            >
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                    {/* Bill Info */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Bill Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Bill Date</span>
                                <span className="font-medium">{formatDate(bill.bill_date)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Due Date</span>
                                <span className={cn('font-medium', isOverdue && 'text-status-critical')}>
                                    {formatDate(bill.due_date)}
                                </span>
                            </div>
                            {bill.purchase_order && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Purchase Order</span>
                                    <Link href={`/finance/purchase-orders/${bill.purchase_order.id}`} className="text-status-info hover:underline font-medium">
                                        {bill.purchase_order.po_number}
                                    </Link>
                                </div>
                            )}
                            {bill.approved_by && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Approved By</span>
                                    <span className="font-medium">{bill.approved_by.name}</span>
                                </div>
                            )}
                            {bill.approved_at && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Approved At</span>
                                    <span className="font-medium">{formatDateTime(bill.approved_at)}</span>
                                </div>
                            )}
                            {bill.notes && (
                                <div className="pt-2 border-t">
                                    <span className="text-muted-foreground block mb-1">Notes</span>
                                    <p className="text-foreground whitespace-pre-wrap">{bill.notes}</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Amounts */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Amounts</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Subtotal</span>
                                <span>{formatCurrency(bill.subtotal)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">GST</span>
                                <span>{formatCurrency(bill.gst_amount)}</span>
                            </div>
                            <Separator />
                            <div className="flex justify-between font-bold">
                                <span>Total</span>
                                <span>{formatCurrency(bill.total_amount)}</span>
                            </div>
                            <div className="flex justify-between text-status-success">
                                <span>Paid</span>
                                <span>{formatCurrency(bill.amount_paid)}</span>
                            </div>
                            <Separator />
                            <div className={cn('flex justify-between font-bold', amountDue > 0 ? 'text-status-critical' : 'text-status-success')}>
                                <span>Amount Due</span>
                                <span>{formatCurrency(amountDue)}</span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* GL Journal */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">GL Journal</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {bill.journal ? (
                                <div className="space-y-3 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Journal #</span>
                                        <Link href={`/finance/journals/${bill.journal.id}`} className="text-status-info hover:underline font-medium">
                                            {bill.journal.journal_number}
                                        </Link>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Status</span>
                                        <Badge className="bg-status-success-bg text-status-success">{bill.journal.status}</Badge>
                                    </div>
                                    {bill.journal.posted_at && (
                                        <div className="flex justify-between">
                                            <span className="text-muted-foreground">Posted</span>
                                            <span className="font-medium">{formatDateTime(bill.journal.posted_at)}</span>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center py-4 text-muted-foreground">
                                    <FileText className="w-8 h-8 mb-2" />
                                    <p className="text-sm">No journal posted yet</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Line Items */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>Line Items</CardTitle>
                    </CardHeader>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Description</TableHead>
                                <TableHead className="text-right">Qty</TableHead>
                                <TableHead className="text-right">Unit Price</TableHead>
                                <TableHead className="text-right">GST %</TableHead>
                                <TableHead>Account</TableHead>
                                <TableHead>Cost Centre</TableHead>
                                <TableHead>Funding Stream</TableHead>
                                <TableHead className="text-right">GST</TableHead>
                                <TableHead className="text-right">Total</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {bill.lines.map((line) => (
                                <TableRow key={line.id}>
                                    <TableCell>{line.description}</TableCell>
                                    <TableCell className="text-right">{Number(line.quantity).toFixed(2)}</TableCell>
                                    <TableCell className="text-right">{formatCurrency(line.unit_price)}</TableCell>
                                    <TableCell className="text-right">{Number(line.gst_rate).toFixed(2)}%</TableCell>
                                    <TableCell className="text-sm">
                                        {line.account ? `${line.account.code} - ${line.account.name}` : '-'}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {line.cost_centre ? `${line.cost_centre.code} - ${line.cost_centre.name}` : '-'}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {line.funding_stream ? `${line.funding_stream.code} - ${line.funding_stream.name}` : '-'}
                                    </TableCell>
                                    <TableCell className="text-right">{formatCurrency(line.gst_amount)}</TableCell>
                                    <TableCell className="text-right font-medium">{formatCurrency(line.line_total)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>

                {/* Payment History */}
                {bill.payment_allocations && bill.payment_allocations.length > 0 && (
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>Payment History</CardTitle>
                        </CardHeader>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead className="text-right">Amount</TableHead>
                                    <TableHead>Notes</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {bill.payment_allocations.map((payment) => (
                                    <TableRow key={payment.id}>
                                        <TableCell>{formatDate(payment.payment_date)}</TableCell>
                                        <TableCell className="text-right font-medium">{formatCurrency(payment.amount)}</TableCell>
                                        <TableCell className="text-muted-foreground">{payment.notes ?? '-'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                )}
            </PageLayout>
        </AppLayout>
    );
}
