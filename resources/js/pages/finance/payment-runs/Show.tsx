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
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Banknote,
    CheckCircle,
    Download,
    FileText,
    Play,
    XCircle,
} from 'lucide-react';
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

export default function PaymentRunShow({ paymentRun }: PageProps) {
    const [approving, setApproving] = useState(false);
    const [processingRun, setProcessingRun] = useState(false);
    const [confirmAction, setConfirmAction] = useState<
        'approve' | 'process' | null
    >(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'Payment Runs', href: '/finance/payment-runs' },
        {
            title: paymentRun.run_number,
            href: `/finance/payment-runs/${paymentRun.id}`,
        },
    ];

    const handleApprove = () => {
        setApproving(true);
        router.post(
            `/finance/payment-runs/${paymentRun.id}/approve`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setApproving(false),
                onSuccess: () => setConfirmAction(null),
            },
        );
    };

    const handleProcess = () => {
        setProcessingRun(true);
        router.post(
            `/finance/payment-runs/${paymentRun.id}/process`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setProcessingRun(false),
                onSuccess: () => setConfirmAction(null),
            },
        );
    };

    const handleAccept = () => {
        const reference = window.prompt('Bank acceptance reference');
        const confirmationDigest = window.prompt(
            'Bank confirmation digest or immutable evidence reference',
        );
        if (!reference || !confirmationDigest) return;

        router.post(`/finance/payment-runs/${paymentRun.id}/accept`, {
            idempotency_key: `accept:${paymentRun.id}:${reference}:${confirmationDigest}`.slice(
                0,
                128,
            ),
            reference,
            evidence: { confirmation_digest: confirmationDigest },
        });
    };

    const handleSettle = () => {
        const acceptanceReference = paymentRun.settlement?.acceptance_reference;
        if (!acceptanceReference) return;
        router.post(`/finance/payment-runs/${paymentRun.id}/settle`, {
            idempotency_key: `settle:${paymentRun.id}:${acceptanceReference}`,
        });
    };

    const handleReject = () => {
        const reference = window.prompt('Bank rejection reference');
        const reason = window.prompt('Bank rejection reason');
        const evidenceReference = window.prompt(
            'Bank rejection digest or immutable evidence reference',
        );
        if (!reference || !reason || !evidenceReference) return;

        router.post(`/finance/payment-runs/${paymentRun.id}/reject`, {
            idempotency_key: `reject:${paymentRun.id}:${reference}:${evidenceReference}`.slice(
                0,
                128,
            ),
            reference,
            reason,
            evidence: { rejection_digest: evidenceReference },
        });
    };

    const handleReconcile = () => {
        const bankTransactionInput = window.prompt(
            'Cleared bank transaction ID',
        );
        const reference = window.prompt('Bank reconciliation reference');
        const evidenceReference = window.prompt(
            'Bank reconciliation digest or immutable evidence reference',
        );
        if (!bankTransactionInput || !reference || !evidenceReference) return;

        const bankTransactionId = Number(bankTransactionInput);
        if (!Number.isSafeInteger(bankTransactionId) || bankTransactionId < 1)
            return;

        router.post(`/finance/payment-runs/${paymentRun.id}/reconcile`, {
            idempotency_key: `payment-run-reconcile:${paymentRun.id}:${bankTransactionId}`,
            bank_transaction_id: bankTransactionId,
            reference,
            evidence: { reconciliation_digest: evidenceReference },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Payment Run ${paymentRun.run_number}`} />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        variant="compact"
                        backHref="/finance/payment-runs"
                        title={paymentRun.run_number}
                        description="Payment run details and items"
                        actions={
                            <>
                                {paymentRun.status === 'draft' && (
                                    <Button
                                        onClick={() =>
                                            setConfirmAction('approve')
                                        }
                                        disabled={approving}
                                        variant="outline"
                                    >
                                        <CheckCircle className="mr-2 h-4 w-4" />
                                        {approving ? 'Approving...' : 'Approve'}
                                    </Button>
                                )}
                                {paymentRun.status === 'approved' && (
                                    <Button
                                        onClick={() =>
                                            setConfirmAction('process')
                                        }
                                        disabled={processingRun}
                                    >
                                        <Play className="mr-2 h-4 w-4" />
                                        {processingRun
                                            ? 'Preparing...'
                                            : 'Prepare Bank File'}
                                    </Button>
                                )}
                                {[
                                    'prepared',
                                    'exported',
                                    'accepted',
                                    'settled',
                                    'reconciled',
                                ].includes(paymentRun.status) &&
                                    paymentRun.file_path && (
                                        <a
                                            href={`/finance/payment-runs/${paymentRun.id}/download`}
                                        >
                                            <Button variant="outline">
                                                <Download className="mr-2 h-4 w-4" />
                                                Download Bank File
                                            </Button>
                                        </a>
                                    )}
                                {paymentRun.status === 'exported' && (
                                    <Button onClick={handleAccept}>
                                        <CheckCircle className="mr-2 h-4 w-4" />
                                        Record Bank Acceptance
                                    </Button>
                                )}
                                {paymentRun.status === 'accepted' && (
                                    <Button onClick={handleSettle}>
                                        <Banknote className="mr-2 h-4 w-4" />
                                        Settle Accepted Run
                                    </Button>
                                )}
                                {paymentRun.status === 'settled' && (
                                    <Button onClick={handleReconcile}>
                                        <CheckCircle className="mr-2 h-4 w-4" />
                                        Record Bank Reconciliation
                                    </Button>
                                )}
                                {['exported', 'accepted'].includes(
                                    paymentRun.status,
                                ) && (
                                    <Button
                                        variant="outline"
                                        onClick={handleReject}
                                    >
                                        <XCircle className="mr-2 h-4 w-4" />
                                        Record Bank Rejection
                                    </Button>
                                )}
                            </>
                        }
                    />
                }
            >
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
                                <p className="text-sm text-muted-foreground">
                                    Run Number
                                </p>
                                <p className="font-mono font-medium">
                                    {paymentRun.run_number}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Payment Date
                                </p>
                                <p className="font-medium">
                                    {paymentRun.payment_date}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Bank Account
                                </p>
                                <p className="font-medium">
                                    {paymentRun.bank_account
                                        ? `${paymentRun.bank_account.name} (${paymentRun.bank_account.bank_name})`
                                        : '-'}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Status
                                </p>
                                <StatusBadge status={paymentRun.status} />
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Total Amount
                                </p>
                                <p className="font-mono text-lg font-semibold tabular-nums">
                                    {formatMoney(paymentRun.total_amount)}
                                </p>
                            </div>
                            <div>
                                <p className="text-sm text-muted-foreground">
                                    Item Count
                                </p>
                                <p className="font-medium">
                                    {paymentRun.item_count}
                                </p>
                            </div>
                            {paymentRun.approved_by && (
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Approved By
                                    </p>
                                    <p className="font-medium">
                                        {paymentRun.approved_by.name}
                                    </p>
                                    {paymentRun.approved_at && (
                                        <p className="text-xs text-muted-foreground">
                                            {paymentRun.approved_at}
                                        </p>
                                    )}
                                </div>
                            )}
                            {paymentRun.processed_by && (
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Processed By
                                    </p>
                                    <p className="font-medium">
                                        {paymentRun.processed_by.name}
                                    </p>
                                    {paymentRun.processed_at && (
                                        <p className="text-xs text-muted-foreground">
                                            {paymentRun.processed_at}
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>

                        {paymentRun.notes && (
                            <div className="mt-4 border-t pt-4">
                                <p className="text-sm text-muted-foreground">
                                    Notes
                                </p>
                                <p className="text-sm">{paymentRun.notes}</p>
                            </div>
                        )}

                        {paymentRun.journal && (
                            <div className="mt-4 border-t pt-4">
                                <p className="text-sm text-muted-foreground">
                                    GL Journal
                                </p>
                                <Link
                                    href={`/finance/journals/${paymentRun.journal.id}`}
                                    className="font-mono text-sm text-primary hover:underline"
                                >
                                    {paymentRun.journal.journal_number}
                                </Link>
                            </div>
                        )}
                        {paymentRun.settlement && (
                            <div className="mt-4 border-t pt-4">
                                <p className="text-sm text-muted-foreground">
                                    External settlement
                                </p>
                                <div className="mt-1 flex items-center gap-2">
                                    <StatusBadge
                                        status={paymentRun.settlement.status}
                                    />
                                    <span className="font-mono text-xs text-muted-foreground">
                                        {paymentRun.settlement.artifact_sha256}
                                    </span>
                                </div>
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
                            <div className="flex flex-col items-center justify-center px-4 py-12">
                                <div className="mb-4 rounded-full bg-muted p-4">
                                    <FileText className="h-8 w-8 text-muted-foreground" />
                                </div>
                                <h3 className="mb-1 text-lg font-semibold text-foreground">
                                    No payment items
                                </h3>
                                <p className="max-w-sm text-center text-sm text-muted-foreground">
                                    This payment run has no items yet.
                                </p>
                            </div>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Vendor</TableHead>
                                        <TableHead>Bill #</TableHead>
                                        <TableHead className="text-right">
                                            Amount
                                        </TableHead>
                                        <TableHead>Bank Account</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {paymentRun.items.map((item) => {
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
                                                            {
                                                                item.bill
                                                                    .bill_number
                                                            }
                                                        </Link>
                                                    ) : (
                                                        '-'
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right font-mono tabular-nums">
                                                    {formatMoney(item.amount)}
                                                </TableCell>
                                                <TableCell className="font-mono text-muted-foreground">
                                                    {item.bank_account_number ||
                                                        '-'}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        status={item.status}
                                                    />
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </PageLayout>

            <ConfirmDialog
                open={confirmAction === 'approve'}
                onOpenChange={(open) => !open && setConfirmAction(null)}
                title="Approve payment run?"
                description="This approves the payment run so an immutable bank file can be prepared. No bill is paid until a separate bank-accepted settlement."
                confirmLabel="Approve run"
                processing={approving}
                onConfirm={handleApprove}
            />
            <ConfirmDialog
                open={confirmAction === 'process'}
                onOpenChange={(open) => !open && setConfirmAction(null)}
                title="Prepare bank file?"
                description="This prepares the payment instruction only. It does not pay bills or post the bank journal."
                confirmLabel="Prepare file"
                processing={processingRun}
                onConfirm={handleProcess}
            />
        </AppLayout>
    );
}
