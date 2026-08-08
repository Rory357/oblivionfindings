import { ConfirmDialog, formatMoney } from '@/components/finance';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Account = { id: number; code: string; name: string };
type Vendor = { id: number; name: string };
type CostCentre = { id: number; code: string; name: string };
type FundingStream = { id: number; code: string; name: string };
type ApprovedBy = { id: number; name: string };
type Bill = {
    id: number;
    bill_number: string;
    status: string;
    total_amount: string;
    bill_date: string;
};

type Line = {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    gst_rate: string;
    gst_amount: string;
    line_total: string;
    account?: Account | null;
};

type PurchaseOrder = {
    id: number;
    po_number: string;
    status: string;
    order_date: string;
    expected_date: string | null;
    subtotal: string;
    gst_amount: string;
    total_amount: string;
    notes: string | null;
    approved_at: string | null;
    vendor?: Vendor | null;
    lines: Line[];
    approved_by_user?: ApprovedBy | null;
    approved_by?: ApprovedBy | null;
    cost_centre?: CostCentre | null;
    funding_stream?: FundingStream | null;
    bills: Bill[];
};

export default function PurchaseOrderShow() {
    const { purchaseOrder } = usePage().props as unknown as {
        purchaseOrder: PurchaseOrder;
    };
    const po = purchaseOrder;
    const approver = po.approved_by ?? po.approved_by_user;
    const canApprove = po.status === 'draft';
    const canEdit = po.status === 'draft';
    const canConvert = ['approved', 'partially_received', 'received'].includes(
        po.status,
    );
    const [confirmAction, setConfirmAction] = useState<
        'approve' | 'convert' | null
    >(null);
    const [processing, setProcessing] = useState(false);

    function handleApprove() {
        router.post(
            `/finance/purchase-orders/${po.id}/approve`,
            {},
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => setConfirmAction(null),
            },
        );
    }

    function handleConvertToBill() {
        router.post(
            `/finance/purchase-orders/${po.id}/convert-to-bill`,
            {},
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => setConfirmAction(null),
            },
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Finance', href: '/finance' },
                { title: 'Purchase Orders', href: '/finance/purchase-orders' },
                { title: po.po_number, href: '#' },
            ]}
        >
            <Head title={`PO ${po.po_number}`} />
            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        variant="compact"
                        backHref="/finance/purchase-orders"
                        title={
                            <span className="flex flex-wrap items-center gap-3">
                                {po.po_number}
                                <StatusBadge status={po.status} />
                            </span>
                        }
                        description={`Vendor: ${po.vendor?.name ?? '—'}`}
                        actions={
                            <>
                                {canEdit && (
                                    <Link
                                        href={`/finance/purchase-orders/${po.id}/edit`}
                                    >
                                        <Button variant="outline">Edit</Button>
                                    </Link>
                                )}
                                {canApprove && (
                                    <Button
                                        onClick={() =>
                                            setConfirmAction('approve')
                                        }
                                    >
                                        Approve
                                    </Button>
                                )}
                                {canConvert && (
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            setConfirmAction('convert')
                                        }
                                    >
                                        Convert to Bill
                                    </Button>
                                )}
                            </>
                        }
                    />
                }
            >
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Order Details
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <dt className="text-xs font-medium text-muted-foreground">
                                    Order Date
                                </dt>
                                <dd className="mt-1 text-sm">
                                    {po.order_date}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-muted-foreground">
                                    Expected Date
                                </dt>
                                <dd className="mt-1 text-sm">
                                    {po.expected_date ?? '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-muted-foreground">
                                    Cost Centre
                                </dt>
                                <dd className="mt-1 text-sm">
                                    {po.cost_centre
                                        ? `${po.cost_centre.code} - ${po.cost_centre.name}`
                                        : '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-muted-foreground">
                                    Funding Stream
                                </dt>
                                <dd className="mt-1 text-sm">
                                    {po.funding_stream
                                        ? `${po.funding_stream.code} - ${po.funding_stream.name}`
                                        : '—'}
                                </dd>
                            </div>
                            {approver && (
                                <div>
                                    <dt className="text-xs font-medium text-muted-foreground">
                                        Approved By
                                    </dt>
                                    <dd className="mt-1 text-sm">
                                        {approver.name}
                                        {po.approved_at
                                            ? ` on ${po.approved_at}`
                                            : ''}
                                    </dd>
                                </div>
                            )}
                            {po.notes && (
                                <div className="sm:col-span-2 lg:col-span-4">
                                    <dt className="text-xs font-medium text-muted-foreground">
                                        Notes
                                    </dt>
                                    <dd className="mt-1 text-sm whitespace-pre-wrap">
                                        {po.notes}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Line Items</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Description</TableHead>
                                    <TableHead className="text-right">
                                        Qty
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Unit Price
                                    </TableHead>
                                    <TableHead className="text-right">
                                        GST Rate
                                    </TableHead>
                                    <TableHead className="text-right">
                                        GST
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Line Total
                                    </TableHead>
                                    <TableHead>Account</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {po.lines.map((line) => (
                                    <TableRow key={line.id}>
                                        <TableCell>
                                            {line.description}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {Number(line.quantity).toFixed(2)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {formatMoney(line.unit_price)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {(
                                                Number(line.gst_rate) * 100
                                            ).toFixed(0)}
                                            %
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {formatMoney(line.gst_amount)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {formatMoney(line.line_total)}
                                        </TableCell>
                                        <TableCell>
                                            {line.account
                                                ? `${line.account.code} - ${line.account.name}`
                                                : '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>

                        <div className="border-t p-4">
                            <div className="flex justify-end">
                                <div className="w-64 space-y-1 text-sm">
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Subtotal
                                        </span>
                                        <span>{formatMoney(po.subtotal)}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            GST
                                        </span>
                                        <span>
                                            {formatMoney(po.gst_amount)}
                                        </span>
                                    </div>
                                    <div className="flex justify-between border-t pt-1 font-semibold">
                                        <span>Total</span>
                                        <span>
                                            {formatMoney(po.total_amount)}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {po.bills && po.bills.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Linked Bills
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Bill Number</TableHead>
                                        <TableHead>Date</TableHead>
                                        <TableHead className="text-right">
                                            Total
                                        </TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {po.bills.map((bill) => (
                                        <TableRow key={bill.id}>
                                            <TableCell>
                                                <Link
                                                    href={`/finance/bills/${bill.id}`}
                                                    className="font-medium text-status-info hover:underline"
                                                >
                                                    {bill.bill_number}
                                                </Link>
                                            </TableCell>
                                            <TableCell>
                                                {bill.bill_date}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {formatMoney(bill.total_amount)}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={bill.status}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                )}
            </PageLayout>

            <ConfirmDialog
                open={confirmAction === 'approve'}
                onOpenChange={(open) => !open && setConfirmAction(null)}
                title="Approve purchase order?"
                description={`This approves ${po.po_number} so it can be received and converted to a bill.`}
                confirmLabel="Approve PO"
                processing={processing}
                onConfirm={handleApprove}
            />
            <ConfirmDialog
                open={confirmAction === 'convert'}
                onOpenChange={(open) => !open && setConfirmAction(null)}
                title="Convert to bill?"
                description={`This creates a draft supplier bill from ${po.po_number}. You can review and edit the bill before approving it.`}
                confirmLabel="Convert to bill"
                processing={processing}
                onConfirm={handleConvertToBill}
            />
        </AppLayout>
    );
}
