import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs } from '@/components/ui/tabs';
import { ReviewRow } from '@/components/wizard/shell';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import type { Bill } from '@/pages/finance/bills/Show';
import {
    ReviewRequestDialog,
    type ReviewRequest,
} from '@/pages/finance/vehicle-reviews/_dialogs';
import { Link } from '@inertiajs/react';
import {
    ArrowLeft,
    ArrowUpRight,
    Building2,
    CheckCircle2,
    FileText,
    ReceiptText,
    ShieldCheck,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import {
    BillEvidenceSection,
    DuplicateBillWarning,
    type DuplicateBill,
} from './bill-review';
import { DocumentPreview } from './document-preview';
import { formatMoney } from './money';

export type BillWorkContext = {
    work: {
        id: number;
        reference: string | null;
        title: string;
        status: string;
        url: string | null;
    } | null;
    estimate: {
        id: number;
        vendor_name: string;
        quote_reference: string;
        amount: string;
        reason: string;
        at: string;
    } | null;
    documents: Array<{
        id: number;
        name: string;
        mime: string;
        state: string;
        url: string | null;
    }>;
    requests: ReviewRequest[];
    period: { name: string; status: string } | null;
};
function Panel({ title, children }: { title: string; children: ReactNode }) {
    return (
        <Card className="min-w-0 gap-0 overflow-hidden py-0">
            <CardHeader className="border-b bg-muted/20 px-5 py-4">
                <CardTitle className="text-section-title">{title}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 p-5">{children}</CardContent>
        </Card>
    );
}

export function BillWorkspace({
    bill,
    context,
    duplicates,
    canManage,
    onApprove,
}: {
    bill: Bill;
    context?: BillWorkContext | null;
    duplicates: DuplicateBill[];
    canManage: boolean;
    onApprove: () => void;
}) {
    const [source, setSource] = useState('invoice');
    const [review, setReview] = useState<ReviewRequest | null>(null);
    const [preview, setPreview] = useState<number | null>(null);
    const approved = !!bill.journal;
    const canApprove =
        canManage && ['draft', 'awaiting_approval'].includes(bill.status);
    const outstanding = Number(bill.total_amount) - Number(bill.amount_paid);
    const order = bill.purchase_order;
    const quote = context?.estimate;
    const quoteFile =
        context?.documents.find((file) => file.id === preview) ??
        context?.documents.find((file) => file.url);
    const notReady =
        (context?.documents ?? []).some((file) => !file.url) ||
        (bill.documents ?? []).some(
            (file) => !['available', 'withdrawn'].includes(file.state),
        );
    return (
        <section
            className="min-w-0 space-y-5 max-sm:[&_a[data-slot=button]]:min-h-[44px] max-sm:[&_button]:min-h-[44px]"
            aria-label="Bill review workspace"
        >
            {context?.work && (
                <div className="flex flex-wrap justify-between gap-3 text-sm">
                    {context.work.url ? (
                        <Link
                            className="inline-flex items-center gap-2 font-medium text-primary hover:underline"
                            href={context.work.url}
                        >
                            <ArrowLeft className="size-4" />
                            {context.work.title} · {context.work.reference}
                        </Link>
                    ) : (
                        <span>
                            {context.work.title} · {context.work.reference}
                        </span>
                    )}
                    <span className="text-muted-foreground">
                        Same work · {bill.site_name ?? 'Site not assigned'}
                    </span>
                </div>
            )}
            <DuplicateBillWarning bills={duplicates} />
            {approved && (
                <div
                    role="status"
                    className="rounded-lg border border-status-success/25 bg-status-success-bg p-4 text-sm"
                >
                    <strong>
                        Bill approved · journal {bill.journal?.status}
                    </strong>
                    <p>
                        {outstanding > 0
                            ? 'Payment remains outstanding.'
                            : 'No balance remains on this bill.'}
                    </p>
                </div>
            )}
            <div className="grid min-w-0 grid-cols-1 items-start gap-5 xl:grid-cols-[minmax(0,1.65fr)_minmax(320px,1fr)]">
                <div className="min-w-0 space-y-5">
                    <Panel title="Invoice & supporting records">
                        <p className="text-caption">
                            Compare the source records before making a decision.
                        </p>
                        <Tabs
                            value={source}
                            onValueChange={setSource}
                            tabs={[
                                {
                                    key: 'invoice',
                                    label: 'Invoice',
                                    icon: <ReceiptText className="size-4" />,
                                    content: (
                                        <Card className="min-w-0 gap-0 space-y-5 bg-background p-4 sm:p-5">
                                            <div className="flex flex-wrap justify-between gap-4 border-b pb-5">
                                                <div className="flex min-w-0 gap-3">
                                                    <Building2 className="size-9 shrink-0 rounded-lg bg-primary/10 p-2 text-primary" />
                                                    <div className="min-w-0 break-words">
                                                        <h3 className="text-section-title">
                                                            {bill.vendor
                                                                ?.name ??
                                                                'Supplier unavailable'}
                                                        </h3>
                                                        <p className="text-caption">
                                                            Supplier invoice
                                                        </p>
                                                    </div>
                                                </div>
                                                <div className="text-right">
                                                    <strong>
                                                        {bill.vendor_reference ??
                                                            bill.bill_number}
                                                    </strong>
                                                    <p className="text-caption">
                                                        {formatDateOnly(
                                                            bill.bill_date.slice(
                                                                0,
                                                                10,
                                                            ),
                                                        )}{' '}
                                                        · NZD
                                                    </p>
                                                </div>
                                            </div>
                                            <p className="text-sm">
                                                {context?.work?.title ??
                                                    bill.notes ??
                                                    'Accounts payable bill'}
                                                <span className="text-caption mt-1 block">
                                                    {bill.site_name ??
                                                        'Assign an approved Site before approval'}
                                                </span>
                                            </p>
                                            <ul className="divide-y">
                                                {bill.lines.map((line) => (
                                                    <li
                                                        key={line.id}
                                                        className="flex flex-wrap justify-between gap-3 py-3"
                                                    >
                                                        <div>
                                                            <strong className="text-sm">
                                                                {
                                                                    line.description
                                                                }
                                                            </strong>
                                                            <p className="text-caption">
                                                                {line.quantity}{' '}
                                                                ×{' '}
                                                                {formatMoney(
                                                                    line.unit_price,
                                                                )}{' '}
                                                                ·{' '}
                                                                {line.account
                                                                    ?.name ??
                                                                    'Account missing'}
                                                            </p>
                                                        </div>
                                                        <span className="shrink-0 text-sm font-semibold tabular-nums">
                                                            {formatMoney(
                                                                line.line_total,
                                                            )}
                                                        </span>
                                                    </li>
                                                ))}
                                            </ul>
                                            <ReviewRow
                                                label="Subtotal"
                                                value={formatMoney(
                                                    bill.subtotal,
                                                )}
                                            />
                                            <ReviewRow
                                                label="Tax on source bill"
                                                value={formatMoney(
                                                    bill.gst_amount,
                                                )}
                                            />
                                            <div className="flex flex-wrap justify-between gap-3 border-t pt-4 text-lg font-semibold">
                                                <span>Total including tax</span>
                                                <span>
                                                    {formatMoney(
                                                        bill.total_amount,
                                                    )}
                                                </span>
                                            </div>
                                        </Card>
                                    ),
                                },
                                {
                                    key: 'quote',
                                    label: 'Quote',
                                    icon: <FileText className="size-4" />,
                                    content: quote ? (
                                        <div className="space-y-4">
                                            <h3 className="text-section-title">
                                                {quote.vendor_name} ·{' '}
                                                {quote.quote_reference}
                                            </h3>
                                            <ReviewRow
                                                label="Recorded estimate"
                                                value={formatMoney(
                                                    quote.amount,
                                                )}
                                            />
                                            <p className="text-caption">
                                                {quote.reason} ·{' '}
                                                {formatDateTime(quote.at)}
                                            </p>
                                            {context?.documents.map((file) => (
                                                <Button
                                                    key={file.id}
                                                    variant={
                                                        quoteFile?.id ===
                                                        file.id
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    size="sm"
                                                    disabled={!file.url}
                                                    onClick={() =>
                                                        setPreview(file.id)
                                                    }
                                                >
                                                    {file.name} ·{' '}
                                                    {file.state.replaceAll(
                                                        '_',
                                                        ' ',
                                                    )}
                                                </Button>
                                            ))}
                                            {quoteFile?.url ? (
                                                <DocumentPreview
                                                    url={
                                                        quoteFile.url +
                                                        '?inline=1'
                                                    }
                                                    name={quoteFile.name}
                                                    mime={quoteFile.mime}
                                                />
                                            ) : (
                                                <p className="text-sm text-muted-foreground">
                                                    Quote evidence is
                                                    unavailable. Open the work
                                                    record to check the selected
                                                    files.
                                                </p>
                                            )}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            No quote estimate is linked to this
                                            bill’s work.
                                        </p>
                                    ),
                                },
                                {
                                    key: 'order',
                                    label: 'Purchase order',
                                    icon: <ShieldCheck className="size-4" />,
                                    content: order ? (
                                        <div className="space-y-4">
                                            <h3 className="text-section-title">
                                                {order.po_number}
                                            </h3>
                                            <ReviewRow
                                                label="Committed amount"
                                                value={
                                                    order.total_amount
                                                        ? formatMoney(
                                                              order.total_amount,
                                                          )
                                                        : 'Open the original order'
                                                }
                                            />
                                            <p className="text-caption">
                                                The purchase order records a
                                                commitment. This bill records
                                                the invoiced cost.
                                            </p>
                                            <Button variant="outline" asChild>
                                                <Link
                                                    href={`/finance/purchase-orders/${order.id}`}
                                                >
                                                    Open purchase order
                                                    <ArrowUpRight className="size-4" />
                                                </Link>
                                            </Button>
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            No purchase order linked.
                                        </p>
                                    ),
                                },
                                {
                                    key: 'files',
                                    label: 'Supporting files',
                                    icon: <FileText className="size-4" />,
                                    content: (
                                        <BillEvidenceSection
                                            billId={bill.id}
                                            documents={bill.documents ?? []}
                                            editable={canApprove}
                                        />
                                    ),
                                },
                            ]}
                        />
                    </Panel>
                    <Panel title="Allocation & linked review">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p className="text-caption">
                                    Site & cost centre
                                </p>
                                <strong>
                                    {bill.site_name ?? 'Site missing'}
                                </strong>
                                <p className="text-sm">
                                    {[
                                        ...new Set(
                                            bill.lines
                                                .map(
                                                    (line) =>
                                                        line.cost_centre?.name,
                                                )
                                                .filter(Boolean),
                                        ),
                                    ].join(' · ') || 'No cost centre recorded'}
                                </p>
                            </div>
                            <div>
                                <p className="text-caption">
                                    Expense / asset accounts
                                </p>
                                <strong>
                                    {[
                                        ...new Set(
                                            bill.lines
                                                .map(
                                                    (line) =>
                                                        line.account?.name,
                                                )
                                                .filter(Boolean),
                                        ),
                                    ].join(' · ') || 'Account missing'}
                                </strong>
                            </div>
                        </div>
                        {context?.requests.length ? (
                            context.requests.map((item) => (
                                <Button
                                    key={item.id}
                                    variant="outline"
                                    className="h-auto w-full justify-between py-3 text-left whitespace-normal"
                                    onClick={() => setReview(item)}
                                >
                                    <span>
                                        {item.reference}
                                        <span className="text-caption block">
                                            {item.status_label} · Review
                                            decision is separate from bill
                                            approval
                                        </span>
                                    </span>
                                    <ArrowUpRight className="size-4" />
                                </Button>
                            ))
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No linked Finance review request.
                            </p>
                        )}
                    </Panel>
                    <Panel title="Payment history">
                        {bill.payment_allocations?.length ? (
                            bill.payment_allocations.map((payment) => (
                                <div
                                    key={payment.id}
                                    className="flex justify-between gap-3 border-b pb-3"
                                >
                                    <div>
                                        <strong className="text-sm">
                                            {formatDateOnly(
                                                payment.payment_date.slice(
                                                    0,
                                                    10,
                                                ),
                                            )}
                                        </strong>
                                        <p className="text-caption">
                                            {payment.notes ??
                                                'Payment allocation'}
                                        </p>
                                    </div>
                                    <strong>
                                        {formatMoney(payment.amount)}
                                    </strong>
                                </div>
                            ))
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No payments recorded against this bill.
                            </p>
                        )}
                    </Panel>
                </div>
                <aside
                    className="min-w-0 space-y-5"
                    aria-label="Bill decision and result"
                >
                    <Panel title="Accounts payable">
                        <h2 className="text-xl font-semibold">
                            {approved
                                ? 'Approval complete'
                                : bill.status === 'cancelled'
                                  ? 'Bill cancelled'
                                  : notReady
                                    ? 'Evidence needed'
                                    : 'Ready for your review'}
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            {approved
                                ? 'Follow the recorded journal and the separate payment process.'
                                : notReady
                                  ? 'Finish checking or withdraw unavailable files before approval.'
                                  : 'Compare the invoice, evidence and allocation, then confirm your decision.'}
                        </p>
                        <div className="border-y py-4">
                            <p className="text-caption">Bill total · NZD</p>
                            <strong className="text-3xl tabular-nums">
                                {formatMoney(bill.total_amount)}
                            </strong>
                            <p className="text-caption">Including source tax</p>
                            {order?.total_amount && (
                                <p className="mt-3 text-sm text-primary">
                                    {formatMoney(
                                        Math.abs(
                                            Number(bill.total_amount) -
                                                Number(order.total_amount),
                                        ),
                                    )}{' '}
                                    {Number(bill.total_amount) >=
                                    Number(order.total_amount)
                                        ? 'above'
                                        : 'below'}{' '}
                                    purchase order
                                </p>
                            )}
                        </div>
                        <p className="text-sm font-medium">
                            {bill.approved_by?.name ?? 'Accounts payable'}
                            <span className="text-caption block">
                                {bill.approved_at
                                    ? formatDateTime(bill.approved_at)
                                    : 'Finance approver'}
                            </span>
                        </p>
                        {canApprove && (
                            <Button className="w-full" onClick={onApprove}>
                                <ShieldCheck className="size-4" />
                                Review & approve
                            </Button>
                        )}
                        <ul className="space-y-3 text-sm">
                            <li className="flex gap-2">
                                <CheckCircle2 className="size-4 text-primary" />
                                <span>
                                    Allocation and period
                                    <span className="text-caption block">
                                        {context?.period
                                            ? `${context.period.name} · ${context.period.status}`
                                            : 'Finance checks the posting period when you confirm'}
                                    </span>
                                </span>
                            </li>
                            <li className="flex gap-2">
                                <FileText className="size-4 text-primary" />
                                <span>
                                    Source evidence
                                    <span className="text-caption block">
                                        {notReady
                                            ? 'Some files are not available'
                                            : `${(bill.documents ?? []).filter((file) => file.state === 'available').length} bill file(s) · ${(context?.documents ?? []).filter((file) => file.url).length} quote file(s) available`}
                                    </span>
                                </span>
                            </li>
                            <li className="flex gap-2">
                                <ShieldCheck className="size-4 text-primary" />
                                <span>
                                    Approval rules and authority
                                    <span className="text-caption block">
                                        {approved
                                            ? 'Financial approval recorded'
                                            : 'Rechecked when you confirm'}
                                    </span>
                                </span>
                            </li>
                        </ul>
                        <p className="text-caption border-t pt-3">
                            Approval records this bill and its journal. Payment
                            is a separate step.
                        </p>
                    </Panel>
                    <Panel title="Posting & payment">
                        <ReviewRow
                            label="Bill approval"
                            value={bill.status.replaceAll('_', ' ')}
                        />
                        <ReviewRow
                            label="Journal"
                            value={
                                bill.journal ? (
                                    <Link
                                        className="text-primary underline"
                                        href={`/finance/journals/${bill.journal.id}`}
                                    >
                                        {bill.journal.journal_number} ·{' '}
                                        {bill.journal.status}
                                    </Link>
                                ) : (
                                    'Not posted'
                                )
                            }
                        />
                        <ReviewRow
                            label="Paid"
                            value={formatMoney(bill.amount_paid)}
                        />
                        <ReviewRow
                            label="Outstanding"
                            value={formatMoney(outstanding)}
                        />
                        {bill.journal && (
                            <Button
                                asChild
                                variant="outline"
                                className="w-full"
                            >
                                <Link
                                    href={`/finance/journals/${bill.journal.id}`}
                                >
                                    Open Finance event
                                    <ArrowUpRight className="size-4" />
                                </Link>
                            </Button>
                        )}
                    </Panel>
                </aside>
            </div>
            {review && (
                <ReviewRequestDialog
                    request={review}
                    onClose={() => setReview(null)}
                />
            )}
        </section>
    );
}
