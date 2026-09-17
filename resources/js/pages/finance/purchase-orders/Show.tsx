import {
    ConfirmDialog,
    FinanceSectionRail,
    NewPoDialog,
    formatMoney,
    type AccountOption,
} from '@/components/finance';
import type {
    EditablePurchaseOrder,
    PoAttributionOption,
} from '@/components/finance/new-po-dialog';
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
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    CheckCircle,
    FileText,
    Pencil,
    Receipt,
    ShoppingCart,
} from 'lucide-react';
import { useState } from 'react';

type Account = { id: number; code: string; name: string };
type Vendor = { id: number; name: string };
type Attribution = { id: number; code: string; name: string };
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
    account_id: number | null;
    account?: Account | null;
};

type PurchaseOrder = {
    id: number;
    po_number: string;
    status: string;
    vendor_id: number;
    order_date: string;
    expected_date: string | null;
    subtotal: string;
    gst_amount: string;
    total_amount: string;
    notes: string | null;
    approved_at: string | null;
    cost_centre_id: number | null;
    funding_stream_id: number | null;
    vendor?: Vendor | null;
    lines: Line[];
    approved_by_user?: ApprovedBy | null;
    approved_by?: ApprovedBy | null;
    cost_centre?: Attribution | null;
    funding_stream?: Attribution | null;
    bills: Bill[];
};

type PoShowProps = {
    purchaseOrder: PurchaseOrder;
    canManage: boolean;
    vendors: Vendor[];
    accounts: AccountOption[];
    costCentres: PoAttributionOption[];
    fundingStreams: PoAttributionOption[];
};

const STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    approved: 'Approved',
    sent: 'Sent',
    partially_received: 'Partially received',
    received: 'Received',
    cancelled: 'Cancelled',
};

const CHIP_VARIANTS: Record<string, StatusVariant> = {
    draft: 'neutral',
    approved: 'success',
    sent: 'info',
    partially_received: 'warning',
    received: 'success',
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

/** Whole days from today to the expected date; negative once it has passed. */
function daysToExpected(expected: string | null): number | null {
    if (!expected) return null;
    const midnight = (d: Date) =>
        new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    const diff = midnight(new Date(expected)) - midnight(new Date());
    return Math.round(diff / 86_400_000);
}

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

export default function PurchaseOrderShow() {
    const {
        purchaseOrder: po,
        canManage,
        vendors,
        accounts,
        costCentres,
        fundingStreams,
    } = usePage().props as unknown as PoShowProps;

    const approver = po.approved_by ?? po.approved_by_user;
    const canApprove = canManage && po.status === 'draft';
    const canEdit = canManage && po.status === 'draft';
    const canConvert =
        canManage &&
        ['approved', 'partially_received', 'received'].includes(po.status);

    const [confirmAction, setConfirmAction] = useState<
        'approve' | 'convert' | null
    >(null);
    const [processing, setProcessing] = useState(false);
    const [editOpen, setEditOpen] = useState(false);

    const handleApprove = () => {
        router.post(
            `/finance/purchase-orders/${po.id}/approve`,
            {},
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => setConfirmAction(null),
            },
        );
    };

    const handleConvertToBill = () => {
        router.post(
            `/finance/purchase-orders/${po.id}/convert-to-bill`,
            {},
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => setConfirmAction(null),
            },
        );
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Payables', href: '/finance/payables' },
        { title: 'Purchase orders', href: '/finance/purchase-orders' },
        {
            title: po.po_number,
            href: `/finance/purchase-orders/${po.id}`,
        },
    ];

    const days = daysToExpected(po.expected_date);
    const lineColumns: EntityTableColumn<Line>[] = [
        {
            key: 'qty',
            label: 'Qty',
            width: '80px',
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
            key: 'gst_rate',
            label: 'GST %',
            width: '80px',
            align: 'right',
            cell: (l) => (
                <span className="tabular-nums text-muted-foreground">
                    {(Number(l.gst_rate) * 100).toFixed(0)}%
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
            key: 'account',
            label: 'Account',
            width: '1.6fr',
            cell: (l) => (
                <span className="truncate text-muted-foreground">
                    {l.account
                        ? `${l.account.code} · ${l.account.name}`
                        : '—'}
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

    const billColumns: EntityTableColumn<Bill>[] = [
        {
            key: 'bill_date',
            label: 'Bill date',
            width: '1fr',
            cell: (b) => (
                <span className="text-muted-foreground">
                    {formatDate(b.bill_date)}
                </span>
            ),
        },
        {
            key: 'total',
            label: 'Total',
            width: '1fr',
            align: 'right',
            cell: (b) => (
                <span className="tabular-nums">
                    {formatMoney(b.total_amount)}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '150px',
            cell: (b) => (
                <StatusBadge
                    status={b.status}
                    className="rounded-[8px] font-semibold"
                />
            ),
        },
    ];

    const editablePo: EditablePurchaseOrder = {
        id: po.id,
        vendor_id: po.vendor_id,
        order_date: po.order_date,
        expected_date: po.expected_date,
        notes: po.notes,
        cost_centre_id: po.cost_centre_id,
        funding_stream_id: po.funding_stream_id,
        lines: po.lines.map((l) => ({
            description: l.description,
            quantity: l.quantity,
            unit_price: l.unit_price,
            account_id: l.account_id,
            gst_rate: l.gst_rate,
        })),
    };

    const header = (
        <PageHeader
            variant="profile"
            backHref="/finance/purchase-orders"
            icon={ShoppingCart}
            title={po.po_number}
            titleChip={
                <PageHeaderStatusChip
                    variant={CHIP_VARIANTS[po.status] ?? 'neutral'}
                >
                    {STATUS_LABELS[po.status] ?? po.status}
                </PageHeaderStatusChip>
            }
            subline={[
                po.vendor?.name ?? 'No vendor',
                `Ordered ${formatDate(po.order_date)}`,
                po.expected_date
                    ? `Expected ${formatDate(po.expected_date)}`
                    : null,
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    {canEdit && (
                        <PageHeaderGlassButton
                            icon={Pencil}
                            onClick={() => setEditOpen(true)}
                        >
                            Edit
                        </PageHeaderGlassButton>
                    )}
                    {canConvert && (
                        <PageHeaderGlassButton
                            icon={Receipt}
                            onClick={() => setConfirmAction('convert')}
                        >
                            Convert to bill
                        </PageHeaderGlassButton>
                    )}
                    {canApprove && (
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
                        href={`/finance/purchase-orders/${po.id}`}
                        ariaLabel="View this purchase order's total"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(po.total_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {formatMoney(po.subtotal)} plus GST
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="GST"
                        href={`/finance/purchase-orders/${po.id}`}
                        ariaLabel="View this purchase order's GST"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(po.gst_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {po.lines.length} line
                            {po.lines.length === 1 ? '' : 's'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Linked bills"
                        href={`/finance/bills?vendor_id=${po.vendor_id}`}
                        ariaLabel="View bills raised from this purchase order"
                    >
                        <PageHeaderMeterBig>
                            {po.bills?.length ?? 0}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Raised from this order
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {days !== null && (
                        <PageHeaderMeterBlock
                            label="Expected"
                            tone={days < 0 ? 'critical' : 'brand'}
                            href={`/finance/purchase-orders/${po.id}`}
                            ariaLabel="View the expected delivery date"
                        >
                            <PageHeaderMeterBig>
                                {Math.abs(days)}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {days < 0
                                    ? `day${Math.abs(days) === 1 ? '' : 's'} overdue · ${formatDate(po.expected_date)}`
                                    : `day${days === 1 ? '' : 's'} away · ${formatDate(po.expected_date)}`}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    )}
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Purchase order ${po.po_number}`} />

            <PageLayout hero={header}>
                <Card>
                    <CardHeader>
                        <CardTitle className="text-section-title">
                            Order details
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <DetailRow label="Order date">
                                {formatDate(po.order_date)}
                            </DetailRow>
                            <DetailRow label="Expected date">
                                {formatDate(po.expected_date)}
                            </DetailRow>
                            <DetailRow label="Cost centre">
                                {po.cost_centre
                                    ? `${po.cost_centre.code} · ${po.cost_centre.name}`
                                    : '—'}
                            </DetailRow>
                            <DetailRow label="Funding stream">
                                {po.funding_stream
                                    ? `${po.funding_stream.code} · ${po.funding_stream.name}`
                                    : '—'}
                            </DetailRow>
                            {approver && (
                                <DetailRow label="Approved by">
                                    {approver.name}
                                    {po.approved_at
                                        ? ` on ${formatDate(po.approved_at)}`
                                        : ''}
                                </DetailRow>
                            )}
                            {po.notes && (
                                <div className="sm:col-span-2 lg:col-span-4">
                                    <dt className="text-caption">Notes</dt>
                                    <dd className="mt-1 text-sm whitespace-pre-wrap">
                                        {po.notes}
                                    </dd>
                                </div>
                            )}
                        </dl>
                    </CardContent>
                </Card>

                <ListCaption
                    title="Line items"
                    caption={`${po.lines.length} line${po.lines.length === 1 ? '' : 's'} · ${formatMoney(po.total_amount)} total`}
                />
                <EntityTable
                    rows={po.lines}
                    rowKey={(l) => l.id}
                    identityLabel="Description"
                    identity={(l) => ({ icon: FileText, name: l.description })}
                    columns={lineColumns}
                    actionsFor={() => []}
                    minWidth={1040}
                />

                {po.bills && po.bills.length > 0 && (
                    <>
                        <ListCaption
                            title="Linked bills"
                            caption={`${po.bills.length} raised from this order`}
                        />
                        <EntityTable
                            rows={po.bills}
                            rowKey={(b) => b.id}
                            identityLabel="Bill"
                            identity={(b) => ({
                                icon: Receipt,
                                name: b.bill_number,
                            })}
                            columns={billColumns}
                            actionsFor={() => []}
                            hrefFor={(b) => `/finance/bills/${b.id}`}
                            onOpen={(b) => router.get(`/finance/bills/${b.id}`)}
                            minWidth={760}
                        />
                    </>
                )}
            </PageLayout>

            <ConfirmDialog
                variant="default"
                open={confirmAction === 'approve'}
                onClose={() => setConfirmAction(null)}
                title="Approve purchase order?"
                description={`This approves ${po.po_number} so it can be received and converted to a bill.`}
                confirmText="Approve PO"
                processing={processing}
                onConfirm={handleApprove}
            />
            <ConfirmDialog
                variant="default"
                open={confirmAction === 'convert'}
                onClose={() => setConfirmAction(null)}
                title="Convert to bill?"
                description={`This creates a draft supplier bill from ${po.po_number}. You can review and edit the bill before approving it.`}
                confirmText="Convert to bill"
                processing={processing}
                onConfirm={handleConvertToBill}
            />

            {canEdit && editOpen && (
                <NewPoDialog
                    open
                    purchaseOrder={editablePo}
                    onClose={() => setEditOpen(false)}
                    vendors={vendors}
                    accounts={accounts}
                    costCentres={costCentres}
                    fundingStreams={fundingStreams}
                />
            )}
        </AppLayout>
    );
}
