import {
    ConfirmDialog,
    FinanceSectionRail,
    NewBillDialog,
    formatMoney,
    type AccountOption,
    type SpendApprovalOption,
} from '@/components/finance';
import type {
    BillAttributionOption,
    BillPurchaseOrderOption,
    EditableBill,
} from '@/components/finance/new-bill-dialog';
import {
    EntityTable,
    ListCaption,
    type EntityTableColumn,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { PageProps, type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Banknote,
    CheckCircle,
    FileText,
    Pencil,
    Receipt,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

interface BillLine {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    gst_rate: string;
    gst_amount: string;
    line_total: string;
    account_id: number | null;
    cost_centre_id: number | null;
    funding_stream_id: number | null;
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
    vendor_id: number;
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
    spend_approval_id: number | null;
    purchase_order_id: number | null;
    approved_by: { id: number; name: string } | null;
    approved_at: string | null;
    journal: {
        id: number;
        journal_number: string;
        status: string;
        posted_at: string;
    } | null;
    purchase_order: { id: number; po_number: string } | null;
    lines: BillLine[];
    payment_allocations: PaymentAllocation[];
}

interface Props extends PageProps {
    bill: Bill;
    canManage: boolean;
    vendors: { id: number; name: string }[];
    accounts: AccountOption[];
    costCentres: BillAttributionOption[];
    fundingStreams: BillAttributionOption[];
    purchaseOrders: BillPurchaseOrderOption[];
    spendApprovals: SpendApprovalOption[];
}

const STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    awaiting_approval: 'Awaiting approval',
    approved: 'Approved',
    partially_paid: 'Partially paid',
    paid: 'Paid',
    cancelled: 'Cancelled',
};

const CHIP_VARIANTS: Record<string, StatusVariant> = {
    draft: 'neutral',
    awaiting_approval: 'warning',
    approved: 'info',
    partially_paid: 'warning',
    paid: 'success',
    cancelled: 'neutral',
};

const formatDate = (date: string | null) =>
    date
        ? new Date(date).toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          })
        : '—';

const formatDateTime = (date: string | null) =>
    date
        ? new Date(date).toLocaleString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '—';

function DetailRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <dt className="text-caption">{label}</dt>
            <dd className="mt-1 text-sm">{children}</dd>
        </div>
    );
}

export default function BillShow({
    bill,
    canManage,
    vendors,
    accounts,
    costCentres,
    fundingStreams,
    purchaseOrders,
    spendApprovals,
}: Props) {
    const isOverdue =
        bill.status !== 'paid' &&
        bill.status !== 'cancelled' &&
        new Date(bill.due_date) < new Date();
    const isDraft = bill.status === 'draft';
    const canCancel =
        canManage &&
        (bill.status === 'draft' || bill.status === 'awaiting_approval');
    const amountDue = Number(bill.total_amount) - Number(bill.amount_paid);

    const [confirmAction, setConfirmAction] = useState<
        'approve' | 'cancel' | null
    >(null);
    const [processing, setProcessing] = useState(false);
    const [editOpen, setEditOpen] = useState(false);

    const post = (action: 'approve' | 'cancel') =>
        router.post(
            `/finance/bills/${bill.id}/${action}`,
            {},
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => setConfirmAction(null),
            },
        );

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Payables', href: '/finance/payables' },
        { title: 'Bills', href: '/finance/bills' },
        { title: bill.bill_number, href: `/finance/bills/${bill.id}` },
    ];

    const lineColumns: EntityTableColumn<BillLine>[] = [
        {
            key: 'qty',
            label: 'Qty',
            width: '70px',
            align: 'right',
            cell: (l) => (
                <span className="tabular-nums">
                    {Number(l.quantity).toFixed(2)}
                </span>
            ),
        },
        {
            key: 'unit',
            label: 'Unit price',
            width: '1fr',
            align: 'right',
            cell: (l) => (
                <span className="tabular-nums">
                    {formatMoney(l.unit_price)}
                </span>
            ),
        },
        {
            key: 'account',
            label: 'Account',
            width: '1.5fr',
            cell: (l) => (
                <span className="truncate text-muted-foreground">
                    {l.account
                        ? `${l.account.code} · ${l.account.name}`
                        : '—'}
                </span>
            ),
        },
        {
            key: 'cost_centre',
            label: 'Cost centre',
            width: '1.3fr',
            cell: (l) => (
                <span className="truncate text-muted-foreground">
                    {l.cost_centre
                        ? `${l.cost_centre.code} · ${l.cost_centre.name}`
                        : '—'}
                </span>
            ),
        },
        {
            key: 'funding_stream',
            label: 'Funding stream',
            width: '1.3fr',
            cell: (l) => (
                <span className="truncate text-muted-foreground">
                    {l.funding_stream
                        ? `${l.funding_stream.code} · ${l.funding_stream.name}`
                        : '—'}
                </span>
            ),
        },
        {
            key: 'gst',
            label: 'GST',
            width: '1fr',
            align: 'right',
            cell: (l) => (
                <span className="tabular-nums text-muted-foreground">
                    {formatMoney(l.gst_amount)}
                </span>
            ),
        },
        {
            key: 'total',
            label: 'Line total',
            width: '1.1fr',
            align: 'right',
            cell: (l) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(l.line_total)}
                </span>
            ),
        },
    ];

    const paymentColumns: EntityTableColumn<PaymentAllocation>[] = [
        {
            key: 'amount',
            label: 'Amount',
            width: '1fr',
            align: 'right',
            cell: (p) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(p.amount)}
                </span>
            ),
        },
        {
            key: 'notes',
            label: 'Notes',
            width: '2.4fr',
            cell: (p) => (
                <span className="truncate text-muted-foreground">
                    {p.notes ?? '—'}
                </span>
            ),
        },
    ];

    const editableBill: EditableBill = {
        id: bill.id,
        vendor_id: bill.vendor_id,
        vendor_reference: bill.vendor_reference,
        bill_date: bill.bill_date,
        due_date: bill.due_date,
        notes: bill.notes,
        spend_approval_id: bill.spend_approval_id,
        purchase_order_id: bill.purchase_order_id,
        lines: bill.lines.map((l) => ({
            description: l.description,
            quantity: l.quantity,
            unit_price: l.unit_price,
            account_id: l.account_id,
            gst_rate: l.gst_rate,
            cost_centre_id: l.cost_centre_id,
            funding_stream_id: l.funding_stream_id,
        })),
    };

    const header = (
        <PageHeader
            variant="profile"
            backHref="/finance/bills"
            icon={Receipt}
            title={bill.bill_number}
            titleChip={
                isOverdue ? (
                    <PageHeaderStatusChip
                        variant="critical"
                        icon={AlertTriangle}
                    >
                        Overdue
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip
                        variant={CHIP_VARIANTS[bill.status] ?? 'neutral'}
                    >
                        {STATUS_LABELS[bill.status] ?? bill.status}
                    </PageHeaderStatusChip>
                )
            }
            subline={[
                bill.vendor?.name ?? 'No vendor',
                bill.vendor_reference ? `Ref ${bill.vendor_reference}` : null,
                `Due ${formatDate(bill.due_date)}`,
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    {canManage && isDraft && (
                        <PageHeaderGlassButton
                            icon={Pencil}
                            onClick={() => setEditOpen(true)}
                        >
                            Edit
                        </PageHeaderGlassButton>
                    )}
                    {canCancel && (
                        <PageHeaderGlassButton
                            icon={XCircle}
                            onClick={() => setConfirmAction('cancel')}
                        >
                            Cancel bill
                        </PageHeaderGlassButton>
                    )}
                    {canManage && isDraft && (
                        <PageHeaderPrimaryButton
                            icon={CheckCircle}
                            onClick={() => setConfirmAction('approve')}
                        >
                            Approve
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Total"
                        href={`/finance/bills/${bill.id}`}
                        ariaLabel="View this bill's total"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(bill.total_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {formatMoney(bill.subtotal)} plus GST
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Amount due"
                        tone={amountDue > 0 ? 'critical' : 'success'}
                        href={`/finance/bills?vendor_id=${bill.vendor_id}`}
                        ariaLabel="View this vendor's outstanding bills"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(amountDue)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {formatMoney(bill.amount_paid)} paid so far
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="GST"
                        href={`/finance/bills/${bill.id}`}
                        ariaLabel="View this bill's GST"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(bill.gst_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {bill.lines.length} line
                            {bill.lines.length === 1 ? '' : 's'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Payments"
                        href="/finance/payment-runs"
                        ariaLabel="View payment runs"
                    >
                        <PageHeaderMeterBig>
                            {bill.payment_allocations?.length ?? 0}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Allocations against this bill
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Bill ${bill.bill_number}`} />

            <PageLayout hero={header}>
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-section-title">
                                Bill details
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <DetailRow label="Bill date">
                                    {formatDate(bill.bill_date)}
                                </DetailRow>
                                <DetailRow label="Due date">
                                    {formatDate(bill.due_date)}
                                </DetailRow>
                                <DetailRow label="Vendor">
                                    {bill.vendor ? (
                                        <Link
                                            href={`/finance/vendors/${bill.vendor.id}`}
                                            className="text-primary hover:underline"
                                        >
                                            {bill.vendor.name}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </DetailRow>
                                <DetailRow label="Purchase order">
                                    {bill.purchase_order ? (
                                        <Link
                                            href={`/finance/purchase-orders/${bill.purchase_order.id}`}
                                            className="text-primary hover:underline"
                                        >
                                            {bill.purchase_order.po_number}
                                        </Link>
                                    ) : (
                                        '—'
                                    )}
                                </DetailRow>
                                {bill.approved_by && (
                                    <DetailRow label="Approved by">
                                        {bill.approved_by.name}
                                        {bill.approved_at
                                            ? ` · ${formatDateTime(bill.approved_at)}`
                                            : ''}
                                    </DetailRow>
                                )}
                                {bill.notes && (
                                    <div className="sm:col-span-2">
                                        <dt className="text-caption">Notes</dt>
                                        <dd className="mt-1 text-sm whitespace-pre-wrap">
                                            {bill.notes}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-section-title">
                                Ledger journal
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {bill.journal ? (
                                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <DetailRow label="Journal">
                                        <Link
                                            href={`/finance/journals/${bill.journal.id}`}
                                            className="text-primary hover:underline"
                                        >
                                            {bill.journal.journal_number}
                                        </Link>
                                    </DetailRow>
                                    <DetailRow label="Status">
                                        <StatusBadge
                                            status={bill.journal.status}
                                            className="rounded-[8px] font-semibold"
                                        />
                                    </DetailRow>
                                    <DetailRow label="Posted">
                                        {formatDateTime(bill.journal.posted_at)}
                                    </DetailRow>
                                </dl>
                            ) : (
                                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <FileText className="size-4" />
                                    No journal posted yet — approving this bill
                                    posts one to the ledger.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <ListCaption
                    title="Line items"
                    caption={`${bill.lines.length} line${bill.lines.length === 1 ? '' : 's'} · ${formatMoney(bill.total_amount)} total`}
                />
                <EntityTable
                    rows={bill.lines}
                    rowKey={(l) => l.id}
                    identityLabel="Description"
                    identity={(l) => ({ icon: FileText, name: l.description })}
                    columns={lineColumns}
                    actionsFor={() => []}
                    minWidth={1280}
                />

                {bill.payment_allocations &&
                    bill.payment_allocations.length > 0 && (
                        <>
                            <ListCaption
                                title="Payment history"
                                caption={`${formatMoney(bill.amount_paid)} paid across ${bill.payment_allocations.length} allocation${bill.payment_allocations.length === 1 ? '' : 's'}`}
                            />
                            <EntityTable
                                rows={bill.payment_allocations}
                                rowKey={(p) => p.id}
                                identityLabel="Payment date"
                                identity={(p) => ({
                                    icon: Banknote,
                                    name: formatDate(p.payment_date),
                                })}
                                columns={paymentColumns}
                                actionsFor={() => []}
                                minWidth={760}
                            />
                        </>
                    )}
            </PageLayout>

            <ConfirmDialog
                variant="default"
                open={confirmAction === 'approve'}
                onClose={() => setConfirmAction(null)}
                title="Approve this bill?"
                description={
                    <>
                        This approves{' '}
                        <span className="font-medium text-foreground">
                            {bill.bill_number}
                        </span>{' '}
                        for {formatMoney(bill.total_amount)} and{' '}
                        <span className="font-medium text-foreground">
                            posts a journal to the ledger
                        </span>
                        . The bill can then be paid in a payment run.
                    </>
                }
                confirmText="Approve bill"
                processing={processing}
                onConfirm={() => post('approve')}
            />
            <ConfirmDialog
                open={confirmAction === 'cancel'}
                onClose={() => setConfirmAction(null)}
                title="Cancel this bill?"
                description={
                    <>
                        This cancels bill{' '}
                        <span className="font-medium text-foreground">
                            {bill.bill_number}
                        </span>
                        . A cancelled bill can&rsquo;t be approved or paid.
                    </>
                }
                confirmText="Cancel bill"
                cancelText="Keep bill"
                variant="destructive"
                processing={processing}
                onConfirm={() => post('cancel')}
            />

            {canManage && isDraft && editOpen && (
                <NewBillDialog
                    open
                    bill={editableBill}
                    onClose={() => setEditOpen(false)}
                    vendors={vendors}
                    accounts={accounts}
                    spendApprovals={spendApprovals}
                    purchaseOrders={purchaseOrders}
                    costCentres={costCentres}
                    fundingStreams={fundingStreams}
                />
            )}
        </AppLayout>
    );
}
