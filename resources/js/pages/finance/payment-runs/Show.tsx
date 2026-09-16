import { ConfirmDialog, FinanceSectionRail, formatMoney } from '@/components/finance';
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
import { EmptyList } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Banknote,
    Building2,
    CheckCircle,
    Download,
    Landmark,
    Play,
    Receipt,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';

import { SettlementEvidenceDialog, type SettlementAction } from './_dialogs';

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
    settlement: {
        status: string;
        artifact_sha256: string;
        exported_at: string | null;
        accepted_at: string | null;
        acceptance_reference: string | null;
        rejected_at: string | null;
        rejection_reason: string | null;
        settled_at: string | null;
        reconciled_at: string | null;
    } | null;
    items: PaymentRunItem[];
};

type PageProps = {
    paymentRun: PaymentRun;
};

const STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    approved: 'Approved',
    prepared: 'Prepared',
    exported: 'Exported',
    accepted: 'Bank accepted',
    settled: 'Settled',
    reconciled: 'Reconciled',
    rejected: 'Rejected',
    failed: 'Failed',
    processing: 'Processed (legacy)',
    completed: 'Completed (legacy)',
};

const CHIP_VARIANTS: Record<string, StatusVariant> = {
    draft: 'neutral',
    approved: 'info',
    prepared: 'info',
    exported: 'warning',
    accepted: 'success',
    settled: 'success',
    reconciled: 'success',
    rejected: 'critical',
    failed: 'critical',
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

export default function PaymentRunShow({ paymentRun }: PageProps) {
    const [processing, setProcessing] = useState(false);
    const [confirmAction, setConfirmAction] = useState<
        'approve' | 'process' | null
    >(null);
    // Accept / reject / settle / reconcile all collect their bank evidence in
    // one dialog with real fields and validation — never window.prompt().
    const [settlementAction, setSettlementAction] =
        useState<SettlementAction | null>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Payables', href: '/finance/payables' },
        { title: 'Payment runs', href: '/finance/payment-runs' },
        {
            title: paymentRun.run_number,
            href: `/finance/payment-runs/${paymentRun.id}`,
        },
    ];

    const post = (action: 'approve' | 'process') =>
        router.post(
            `/finance/payment-runs/${paymentRun.id}/${action}`,
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => setConfirmAction(null),
            },
        );

    const canDownload =
        ['prepared', 'exported', 'accepted', 'settled', 'reconciled'].includes(
            paymentRun.status,
        ) && Boolean(paymentRun.file_path);

    const itemColumns: EntityTableColumn<PaymentRunItem>[] = [
        {
            key: 'bill',
            label: 'Bill',
            width: '1.4fr',
            cell: (item) =>
                item.bill ? (
                    <Link
                        href={`/finance/bills/${item.bill.id}`}
                        onClick={(e) => e.stopPropagation()}
                        className="text-primary hover:underline"
                    >
                        {item.bill.bill_number}
                    </Link>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        },
        {
            key: 'bank_account',
            label: 'Bank account',
            width: '1.4fr',
            cell: (item) => (
                <span className="truncate text-muted-foreground">
                    {item.bank_account_number || '—'}
                </span>
            ),
        },
        {
            key: 'reference',
            label: 'Reference',
            width: '1.4fr',
            cell: (item) => (
                <span className="truncate text-muted-foreground">
                    {item.reference || '—'}
                </span>
            ),
        },
        {
            key: 'amount',
            label: 'Amount',
            width: '1.2fr',
            align: 'right',
            cell: (item) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(item.amount)}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '140px',
            cell: (item) => (
                <StatusBadge
                    status={item.status}
                    label={STATUS_LABELS[item.status]}
                    className="rounded-[8px] font-semibold"
                />
            ),
        },
    ];

    const header = (
        <PageHeader
            variant="profile"
            backHref="/finance/payment-runs"
            icon={Banknote}
            title={paymentRun.run_number}
            titleChip={
                <PageHeaderStatusChip
                    variant={CHIP_VARIANTS[paymentRun.status] ?? 'neutral'}
                >
                    {STATUS_LABELS[paymentRun.status] ?? paymentRun.status}
                </PageHeaderStatusChip>
            }
            subline={[
                paymentRun.bank_account
                    ? `${paymentRun.bank_account.name} · ${paymentRun.bank_account.bank_name}`
                    : 'No bank account',
                `Payment date ${formatDate(paymentRun.payment_date)}`,
                `${paymentRun.item_count} bill${paymentRun.item_count === 1 ? '' : 's'}`,
            ]
                .filter(Boolean)
                .join(' · ')}
            actions={
                <>
                    {canDownload && (
                        <PageHeaderGlassButton
                            icon={Download}
                            onClick={() => {
                                window.location.href = `/finance/payment-runs/${paymentRun.id}/download`;
                            }}
                        >
                            Download bank file
                        </PageHeaderGlassButton>
                    )}
                    {['exported', 'accepted'].includes(paymentRun.status) && (
                        <PageHeaderGlassButton
                            icon={XCircle}
                            onClick={() => setSettlementAction('reject')}
                        >
                            Record bank rejection
                        </PageHeaderGlassButton>
                    )}
                    {paymentRun.status === 'draft' && (
                        <PageHeaderPrimaryButton
                            icon={CheckCircle}
                            onClick={() => setConfirmAction('approve')}
                        >
                            Approve
                        </PageHeaderPrimaryButton>
                    )}
                    {paymentRun.status === 'approved' && (
                        <PageHeaderPrimaryButton
                            icon={Play}
                            onClick={() => setConfirmAction('process')}
                        >
                            Prepare bank file
                        </PageHeaderPrimaryButton>
                    )}
                    {paymentRun.status === 'exported' && (
                        <PageHeaderPrimaryButton
                            icon={CheckCircle}
                            onClick={() => setSettlementAction('accept')}
                        >
                            Record bank acceptance
                        </PageHeaderPrimaryButton>
                    )}
                    {paymentRun.status === 'accepted' && (
                        <PageHeaderPrimaryButton
                            icon={Banknote}
                            onClick={() => setSettlementAction('settle')}
                        >
                            Settle run
                        </PageHeaderPrimaryButton>
                    )}
                    {paymentRun.status === 'settled' && (
                        <PageHeaderPrimaryButton
                            icon={Landmark}
                            onClick={() => setSettlementAction('reconcile')}
                        >
                            Record bank reconciliation
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Total"
                        href={`/finance/payment-runs/${paymentRun.id}`}
                        ariaLabel="View this run's total"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(paymentRun.total_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Across {paymentRun.item_count} bill
                            {paymentRun.item_count === 1 ? '' : 's'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Bills paid"
                        href="/finance/bills?status=paid"
                        ariaLabel="View paid bills"
                    >
                        <PageHeaderMeterBig>
                            {paymentRun.items.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Items in this run
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Bank settlement"
                        tone={
                            paymentRun.settlement?.settled_at
                                ? 'success'
                                : paymentRun.settlement?.rejected_at
                                  ? 'critical'
                                  : 'warning'
                        }
                        href={`/finance/payment-runs/${paymentRun.id}`}
                        ariaLabel="View the bank settlement status"
                    >
                        <PageHeaderMeterBig>
                            {paymentRun.settlement
                                ? (STATUS_LABELS[
                                      paymentRun.settlement.status
                                  ] ?? paymentRun.settlement.status)
                                : 'Not started'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {paymentRun.settlement?.acceptance_reference
                                ? `Reference ${paymentRun.settlement.acceptance_reference}`
                                : 'No bank evidence recorded yet'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Ledger journal"
                        tone={paymentRun.journal ? 'success' : 'warning'}
                        href={
                            paymentRun.journal
                                ? `/finance/journals/${paymentRun.journal.id}`
                                : '/finance/journals'
                        }
                        ariaLabel="View the ledger journal for this run"
                    >
                        <PageHeaderMeterBig>
                            {paymentRun.journal
                                ? paymentRun.journal.journal_number
                                : 'Not posted'}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {paymentRun.journal
                                ? 'Posted on settlement'
                                : 'Posts when the bank settles the run'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Payment run ${paymentRun.run_number}`} />

            <PageLayout hero={header}>
                <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-section-title">
                                Run details
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <DetailRow label="Payment date">
                                    {formatDate(paymentRun.payment_date)}
                                </DetailRow>
                                <DetailRow label="Bank account">
                                    {paymentRun.bank_account
                                        ? `${paymentRun.bank_account.name} · ${paymentRun.bank_account.bank_name}`
                                        : '—'}
                                </DetailRow>
                                {paymentRun.approved_by && (
                                    <DetailRow label="Approved by">
                                        {paymentRun.approved_by.name}
                                        {paymentRun.approved_at
                                            ? ` · ${formatDateTime(paymentRun.approved_at)}`
                                            : ''}
                                    </DetailRow>
                                )}
                                {paymentRun.processed_by && (
                                    <DetailRow label="Prepared by">
                                        {paymentRun.processed_by.name}
                                        {paymentRun.processed_at
                                            ? ` · ${formatDateTime(paymentRun.processed_at)}`
                                            : ''}
                                    </DetailRow>
                                )}
                                {paymentRun.notes && (
                                    <div className="sm:col-span-2">
                                        <dt className="text-caption">Notes</dt>
                                        <dd className="mt-1 text-sm whitespace-pre-wrap">
                                            {paymentRun.notes}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-section-title">
                                Bank settlement evidence
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {paymentRun.settlement ? (
                                <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <DetailRow label="Status">
                                        <StatusBadge
                                            status={
                                                paymentRun.settlement.status
                                            }
                                            label={
                                                STATUS_LABELS[
                                                    paymentRun.settlement.status
                                                ]
                                            }
                                            className="rounded-[8px] font-semibold"
                                        />
                                    </DetailRow>
                                    <DetailRow label="File digest">
                                        <span className="font-mono text-[12px] break-all">
                                            {
                                                paymentRun.settlement
                                                    .artifact_sha256
                                            }
                                        </span>
                                    </DetailRow>
                                    <DetailRow label="Exported">
                                        {formatDateTime(
                                            paymentRun.settlement.exported_at,
                                        )}
                                    </DetailRow>
                                    <DetailRow label="Accepted">
                                        {formatDateTime(
                                            paymentRun.settlement.accepted_at,
                                        )}
                                        {paymentRun.settlement
                                            .acceptance_reference
                                            ? ` · ${paymentRun.settlement.acceptance_reference}`
                                            : ''}
                                    </DetailRow>
                                    <DetailRow label="Settled">
                                        {formatDateTime(
                                            paymentRun.settlement.settled_at,
                                        )}
                                    </DetailRow>
                                    <DetailRow label="Reconciled">
                                        {formatDateTime(
                                            paymentRun.settlement.reconciled_at,
                                        )}
                                    </DetailRow>
                                    {paymentRun.settlement.rejected_at && (
                                        <div className="sm:col-span-2">
                                            <dt className="text-caption">
                                                Rejected
                                            </dt>
                                            <dd className="mt-1 text-sm">
                                                {formatDateTime(
                                                    paymentRun.settlement
                                                        .rejected_at,
                                                )}
                                                {paymentRun.settlement
                                                    .rejection_reason
                                                    ? ` — ${paymentRun.settlement.rejection_reason}`
                                                    : ''}
                                            </dd>
                                        </div>
                                    )}
                                </dl>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No bank evidence yet. Once the bank file is
                                    prepared and exported, record the bank&rsquo;s
                                    acceptance, rejection or reconciliation here.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <ListCaption
                    title="Payment items"
                    caption={`${paymentRun.items.length} item${paymentRun.items.length === 1 ? '' : 's'} · ${formatMoney(paymentRun.total_amount)} total`}
                />
                {paymentRun.items.length === 0 ? (
                    <EmptyList
                        icon={Receipt}
                        itemName="payment item"
                        title="No payment items"
                        description="This payment run has no bills attached to it."
                    />
                ) : (
                    <EntityTable
                        rows={paymentRun.items}
                        rowKey={(item) => item.id}
                        identityLabel="Vendor"
                        identity={(item) => ({
                            icon: Building2,
                            name: item.vendor?.name ?? 'Unknown vendor',
                        })}
                        columns={itemColumns}
                        actionsFor={() => []}
                        minWidth={1140}
                    />
                )}
            </PageLayout>

            <ConfirmDialog
                variant="default"
                open={confirmAction === 'approve'}
                onClose={() => setConfirmAction(null)}
                title="Approve payment run?"
                description="This approves the payment run so a bank file can be prepared. No bill is paid until the bank confirms settlement."
                confirmText="Approve run"
                processing={processing}
                onConfirm={() => post('approve')}
            />
            <ConfirmDialog
                variant="default"
                open={confirmAction === 'process'}
                onClose={() => setConfirmAction(null)}
                title="Prepare bank file?"
                description="This prepares the payment instruction only. It does not pay bills or post a journal to the ledger."
                confirmText="Prepare file"
                processing={processing}
                onConfirm={() => post('process')}
            />

            <SettlementEvidenceDialog
                action={settlementAction}
                paymentRunId={paymentRun.id}
                runNumber={paymentRun.run_number}
                acceptanceReference={
                    paymentRun.settlement?.acceptance_reference
                }
                onClose={() => setSettlementAction(null)}
            />
        </AppLayout>
    );
}
