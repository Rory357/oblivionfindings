import {
    ConfirmDialog,
    FinanceSectionRail,
    NewBillDialog,
    formatMoney,
    type AccountOption,
    type SpendApprovalOption,
} from '@/components/finance';
import {
    BillApprovalWizard,
    type BillEvidence,
    type DuplicateBill,
} from '@/components/finance/bill-review';
import {
    BillWorkspace,
    type BillWorkContext,
} from '@/components/finance/bill-workspace';
import type {
    BillAttributionOption,
    BillPurchaseOrderOption,
} from '@/components/finance/new-bill-dialog';
import { todayInAuckland } from '@/components/fleet-assets/vehicle-workspace/workspace-model';
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
import { Button } from '@/components/ui/button';
import { type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { PageProps, type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
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

export interface Bill {
    site_name?: string | null;
    id: number;
    bill_number: string;
    site_id: number | null;
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
    purchase_order: {
        id: number;
        po_number: string;
        total_amount?: string;
        status?: string;
    } | null;
    documents?: BillEvidence[];
    lines: BillLine[];
    payment_allocations: PaymentAllocation[];
}

interface Props extends PageProps {
    workContext?: BillWorkContext | null;
    duplicateBills?: DuplicateBill[];
    sites: { id: number; name: string }[];
    bill: Bill;
    approvalSnapshot: string;
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

export default function BillShow({
    bill,
    approvalSnapshot,
    workContext = null,
    duplicateBills = [],
    canManage,
    sites = [],
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
        bill.due_date.slice(0, 10) < todayInAuckland();
    const isDraft = bill.status === 'draft';
    const canApprove =
        canManage && ['draft', 'awaiting_approval'].includes(bill.status);
    const canCancel =
        canManage &&
        (bill.status === 'draft' || bill.status === 'awaiting_approval');
    const amountDue = Number(bill.total_amount) - Number(bill.amount_paid);

    const [confirmAction, setConfirmAction] = useState<
        'approve' | 'cancel' | null
    >(null);
    const [processing, setProcessing] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [reviewedApproval, setReviewedApproval] = useState<{
        snapshot: string;
        amount: string;
    } | null>(null);
    const [approvalStale, setApprovalStale] = useState(false);
    const [actionError, setActionError] = useState('');

    const post = (action: 'approve' | 'cancel') => {
        if (
            processing ||
            (action === 'approve' && (!reviewedApproval || approvalStale))
        )
            return;
        router.post(
            `/finance/bills/${bill.id}/${action}`,
            action === 'approve'
                ? { approval_snapshot: reviewedApproval!.snapshot }
                : {},
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setConfirmAction(null);
                    setActionError('');
                    setReviewedApproval(null);
                },
                onError: (errors) => {
                    setActionError(
                        errors.approval_snapshot ||
                            errors.bill ||
                            'The action could not be completed. Try again.',
                    );
                    if (errors.approval_snapshot) {
                        setApprovalStale(true);
                        setConfirmAction(null);
                        setReviewedApproval(null);
                    }
                },
            },
        );
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Payables', href: '/finance/payables' },
        { title: 'Bills', href: '/finance/bills' },
        { title: bill.bill_number, href: `/finance/bills/${bill.id}` },
    ];

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
                            className="min-h-[44px] sm:min-h-9"
                            icon={Pencil}
                            onClick={() => setEditOpen(true)}
                        >
                            Edit
                        </PageHeaderGlassButton>
                    )}
                    {canCancel && (
                        <PageHeaderGlassButton
                            className="min-h-[44px] sm:min-h-9"
                            icon={XCircle}
                            onClick={() => setConfirmAction('cancel')}
                        >
                            Cancel bill
                        </PageHeaderGlassButton>
                    )}
                    {canApprove && (
                        <PageHeaderPrimaryButton
                            className="min-h-[44px] sm:min-h-9"
                            icon={CheckCircle}
                            disabled={approvalStale}
                            onClick={() => {
                                setActionError('');
                                setReviewedApproval({
                                    snapshot: approvalSnapshot,
                                    amount: bill.total_amount,
                                });
                                setConfirmAction('approve');
                            }}
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
                {actionError && (
                    <div
                        role="alert"
                        className="mb-4 rounded-lg border border-status-warning/30 bg-status-warning-bg p-4 text-sm text-status-warning"
                    >
                        <p>{actionError}</p>
                        {approvalStale && (
                            <Button
                                variant="outline"
                                className="mt-3"
                                onClick={() =>
                                    router.get(
                                        `/finance/bills/${bill.id}`,
                                        {},
                                        { preserveState: false },
                                    )
                                }
                            >
                                Load current bill
                            </Button>
                        )}
                    </div>
                )}
                <BillWorkspace
                    bill={bill}
                    context={workContext}
                    duplicates={duplicateBills}
                    canManage={canManage}
                    onApprove={() => {
                        setActionError('');
                        setReviewedApproval({
                            snapshot: approvalSnapshot,
                            amount: bill.total_amount,
                        });
                        setConfirmAction('approve');
                    }}
                />{' '}
            </PageLayout>

            {confirmAction === 'approve' && (
                <BillApprovalWizard
                    bill={bill}
                    context={workContext}
                    snapshot={reviewedApproval?.snapshot ?? approvalSnapshot}
                    duplicates={duplicateBills}
                    onClose={() => setConfirmAction(null)}
                />
            )}
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
                    bill={bill}
                    onClose={() => setEditOpen(false)}
                    sites={sites}
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
