import {
    ConfirmDialog,
    FinanceSectionRail,
    formatMoney,
    NewInvoiceDialog,
    RecordReceiptDialog,
    type ClientOption,
    type InvoiceAccountOption,
    type TaxRateOption,
} from '@/components/finance';
import { EntityTable, ListCaption } from '@/components/lists';
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
import { Separator } from '@/components/ui/separator';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Download,
    Pencil,
    Receipt,
    Send,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';

interface InvoiceLine {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    tax_amount: string;
    line_total: string;
    sort_order: number;
    tax_rate_id: number | null;
    account_id: number | null;
    tax_rate: { id: number; name: string; rate: string } | null;
    account: { id: number; code: string; name: string } | null;
}

interface Invoice {
    id: number;
    invoice_number: string;
    invoice_date: string;
    due_date: string;
    client_id: number | null;
    client_name: string;
    client_email: string | null;
    client_address: string | null;
    funding_body: string | null;
    bill: { id: number; bill_number: string } | null;
    subtotal: string;
    tax_amount: string;
    total_amount: string;
    amount_paid: number;
    amount_due: number;
    currency_code: string;
    status: string;
    sent_at: string | null;
    viewed_at: string | null;
    paid_at: string | null;
    notes: string | null;
    terms: string | null;
    pdf_path: string | null;
    email_subject: string | null;
    email_body: string | null;
    created_by: { id: number; name: string } | null;
    lines: InvoiceLine[];
}

interface Props extends PageProps {
    invoice: Invoice;
    canManage: boolean;
    clients: ClientOption[];
    taxRates: TaxRateOption[];
    accounts: InvoiceAccountOption[];
}

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

export default function InvoiceShow({
    auth,
    invoice,
    canManage,
    clients,
    taxRates,
    accounts,
}: Props) {
    const isOverdue =
        invoice.status !== 'paid' &&
        invoice.status !== 'cancelled' &&
        new Date(invoice.due_date) < new Date();
    const isDraft = invoice.status === 'draft';
    const canSend =
        canManage &&
        invoice.status !== 'cancelled' &&
        invoice.status !== 'paid' &&
        !!invoice.client_email;
    // Sending again emails another copy; it never re-posts the AR journal, so
    // the button and its confirmation say so rather than repeating the
    // first-send wording.
    const isResend = canSend && !isDraft;
    const canReceipt =
        canManage &&
        Number(invoice.amount_due ?? 0) > 0 &&
        !['draft', 'cancelled', 'paid'].includes(invoice.status);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Receivables', href: '/finance/invoices' },
        { title: 'Invoices', href: '/finance/invoices' },
        { title: invoice.invoice_number },
    ];

    const [sendOpen, setSendOpen] = useState(false);
    const [sending, setSending] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [receiptOpen, setReceiptOpen] = useState(false);

    const confirmSend = () => {
        router.post(
            `/finance/invoices/${invoice.id}/send`,
            {},
            {
                onStart: () => setSending(true),
                onFinish: () => setSending(false),
                onSuccess: () => setSendOpen(false),
            },
        );
    };

    const money = (value: string | number) =>
        formatMoney(value, { currency: invoice.currency_code });

    const header = (
        <PageHeader
            variant="profile"
            icon={Receipt}
            backHref="/finance/invoices"
            title={invoice.invoice_number}
            titleChip={
                isOverdue ? (
                    <PageHeaderStatusChip variant="critical">
                        Overdue
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip
                        variant={
                            invoice.status === 'paid'
                                ? 'success'
                                : invoice.status === 'cancelled'
                                  ? 'neutral'
                                  : 'info'
                        }
                    >
                        {invoice.status === 'paid'
                            ? 'Paid'
                            : invoice.status === 'draft'
                              ? 'Draft'
                              : invoice.status === 'cancelled'
                                ? 'Cancelled'
                                : 'Awaiting payment'}
                    </PageHeaderStatusChip>
                )
            }
            subline={`${invoice.client_name} · issued ${formatDate(
                invoice.invoice_date,
            )} · due ${formatDate(invoice.due_date)}`}
            actions={
                <>
                    <PageHeaderGlassButton
                        icon={Download}
                        onClick={() => {
                            window.location.href = `/finance/invoices/${invoice.id}/pdf`;
                        }}
                    >
                        Download PDF
                    </PageHeaderGlassButton>
                    {canManage && isDraft && (
                        <PageHeaderGlassButton
                            icon={Pencil}
                            onClick={() => setEditOpen(true)}
                        >
                            Edit
                        </PageHeaderGlassButton>
                    )}
                    {canReceipt ? (
                        <PageHeaderPrimaryButton
                            icon={Wallet}
                            onClick={() => setReceiptOpen(true)}
                        >
                            Record receipt
                        </PageHeaderPrimaryButton>
                    ) : canSend ? (
                        <PageHeaderPrimaryButton
                            icon={Send}
                            onClick={() => setSendOpen(true)}
                        >
                            {isResend ? 'Resend invoice' : 'Send invoice'}
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Invoice total"
                        href={`/finance/invoices/${invoice.id}/pdf`}
                        ariaLabel="Download this invoice as a PDF"
                    >
                        <PageHeaderMeterBig>
                            {money(invoice.total_amount)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Incl. {money(invoice.tax_amount)} GST
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Received"
                        tone="success"
                        href="/finance/payment-allocations?type=receivable"
                        ariaLabel="View receipt allocations"
                    >
                        <PageHeaderMeterBig>
                            {money(invoice.amount_paid ?? 0)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Allocated against this invoice
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Outstanding"
                        tone={
                            Number(invoice.amount_due ?? 0) > 0
                                ? isOverdue
                                    ? 'critical'
                                    : 'warning'
                                : 'success'
                        }
                        href={canReceipt ? undefined : '/finance/receivables'}
                        onClick={
                            canReceipt ? () => setReceiptOpen(true) : undefined
                        }
                        ariaLabel={
                            canReceipt
                                ? 'Record a receipt for the outstanding balance'
                                : 'View aged receivables'
                        }
                    >
                        <PageHeaderMeterBig>
                            {money(invoice.amount_due ?? 0)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {Number(invoice.amount_due ?? 0) > 0
                                ? 'Still to be received'
                                : 'Fully settled'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Due"
                        tone={isOverdue ? 'critical' : 'brand'}
                        href={
                            isOverdue
                                ? '/finance/invoices?status=overdue'
                                : '/finance/invoices?status=unpaid'
                        }
                        ariaLabel="View invoices due"
                    >
                        <PageHeaderMeterBig>
                            {formatDate(invoice.due_date)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {invoice.paid_at
                                ? `Paid ${formatDate(invoice.paid_at)}`
                                : isOverdue
                                  ? 'Past its due date'
                                  : 'Payment due date'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout user={auth.user} breadcrumbs={breadcrumbs}>
            <Head title={`Invoice ${invoice.invoice_number}`} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Invoice details
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Status
                                    </span>
                                    <StatusBadge status={invoice.status} />
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Invoice date
                                    </span>
                                    <span className="font-medium">
                                        {formatDate(invoice.invoice_date)}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Due date
                                    </span>
                                    <span
                                        className={
                                            isOverdue
                                                ? 'font-medium text-status-critical'
                                                : 'font-medium'
                                        }
                                    >
                                        {formatDate(invoice.due_date)}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Currency
                                    </span>
                                    <span className="font-medium">
                                        {invoice.currency_code}
                                    </span>
                                </div>
                                {invoice.bill && (
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Linked bill
                                        </span>
                                        <Link
                                            href={`/finance/bills/${invoice.bill.id}`}
                                            className="font-medium text-primary hover:underline"
                                        >
                                            {invoice.bill.bill_number}
                                        </Link>
                                    </div>
                                )}
                                {invoice.created_by && (
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Created by
                                        </span>
                                        <span className="font-medium">
                                            {invoice.created_by.name}
                                        </span>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Billed to
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="text-base font-medium">
                                    {invoice.client_name}
                                </div>
                                {invoice.funding_body && (
                                    <div className="text-muted-foreground">
                                        Funding body: {invoice.funding_body}
                                    </div>
                                )}
                                {invoice.client_email && (
                                    <div>{invoice.client_email}</div>
                                )}
                                {invoice.client_address && (
                                    <div className="border-t pt-2">
                                        <p className="whitespace-pre-wrap text-muted-foreground">
                                            {invoice.client_address}
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Delivery
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Sent
                                    </span>
                                    <span className="font-medium">
                                        {formatDateTime(invoice.sent_at)}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Viewed
                                    </span>
                                    <span className="font-medium">
                                        {formatDateTime(invoice.viewed_at)}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Paid
                                    </span>
                                    <span className="font-medium">
                                        {formatDateTime(invoice.paid_at)}
                                    </span>
                                </div>
                                <Separator />
                                <div>
                                    <p className="text-muted-foreground">
                                        Email subject
                                    </p>
                                    <p className="font-medium">
                                        {invoice.email_subject || '—'}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <ListCaption
                        title="Line items"
                        caption={`${invoice.lines.length} ${
                            invoice.lines.length === 1 ? 'line' : 'lines'
                        }`}
                    />
                    <EntityTable
                        rows={invoice.lines}
                        rowKey={(line) => line.id}
                        identityLabel="Description"
                        identity={(line) => ({
                            icon: Receipt,
                            name: line.description,
                            subline: line.account
                                ? `${line.account.code} — ${line.account.name}`
                                : 'Default revenue account',
                        })}
                        actionsFor={() => []}
                        minWidth={900}
                        columns={[
                            {
                                key: 'quantity',
                                label: 'Qty',
                                width: '0.5fr',
                                align: 'right',
                                cell: (line) => Number(line.quantity).toFixed(2),
                            },
                            {
                                key: 'unit_price',
                                label: 'Unit price',
                                width: '0.8fr',
                                align: 'right',
                                cell: (line) => money(line.unit_price),
                            },
                            {
                                key: 'tax',
                                label: 'Tax rate',
                                width: '0.9fr',
                                cell: (line) =>
                                    line.tax_rate
                                        ? `${line.tax_rate.name} (${line.tax_rate.rate}%)`
                                        : 'GST 15%',
                            },
                            {
                                key: 'tax_amount',
                                label: 'GST',
                                width: '0.7fr',
                                align: 'right',
                                cell: (line) => money(line.tax_amount),
                            },
                            {
                                key: 'line_total',
                                label: 'Total',
                                width: '0.8fr',
                                align: 'right',
                                cell: (line) => (
                                    <span className="font-medium tabular-nums">
                                        {money(line.line_total)}
                                    </span>
                                ),
                            },
                        ]}
                    />

                    <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
                        <Card className="lg:col-start-3">
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Totals
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Subtotal
                                    </span>
                                    <span>{money(invoice.subtotal)}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        GST
                                    </span>
                                    <span>{money(invoice.tax_amount)}</span>
                                </div>
                                <Separator />
                                <div className="flex justify-between text-base font-bold">
                                    <span>Total</span>
                                    <span>{money(invoice.total_amount)}</span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {(invoice.notes || invoice.terms) && (
                        <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
                            {invoice.notes && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">
                                            Notes
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                            {invoice.notes}
                                        </p>
                                    </CardContent>
                                </Card>
                            )}
                            {invoice.terms && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">
                                            Payment terms
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                            {invoice.terms}
                                        </p>
                                    </CardContent>
                                </Card>
                            )}
                        </div>
                    )}
                </div>
            </PageLayout>

            <ConfirmDialog
                variant="default"
                open={sendOpen}
                onClose={() => setSendOpen(false)}
                title={isResend ? 'Resend invoice?' : 'Send invoice?'}
                description={
                    isResend
                        ? `This emails ${invoice.invoice_number} to the client again. It stays as it is on the ledger — no second journal is posted.`
                        : `This marks ${invoice.invoice_number} as sent, emails it to the client and posts the AR journal to the ledger.`
                }
                confirmText={isResend ? 'Resend invoice' : 'Send invoice'}
                processing={sending}
                onConfirm={confirmSend}
            />

            {canReceipt && receiptOpen && (
                <RecordReceiptDialog
                    open
                    onClose={() => setReceiptOpen(false)}
                    invoice={{
                        id: invoice.id,
                        invoice_number: invoice.invoice_number,
                        client_name: invoice.client_name,
                        currency_code: invoice.currency_code,
                        total_amount: invoice.total_amount,
                        amount_due: Number(invoice.amount_due ?? 0),
                    }}
                />
            )}

            {canManage && isDraft && editOpen && (
                <NewInvoiceDialog
                    open
                    onClose={() => setEditOpen(false)}
                    clients={clients}
                    taxRates={taxRates}
                    accounts={accounts}
                    invoice={{
                        id: invoice.id,
                        client_id: invoice.client_id,
                        client_name: invoice.client_name,
                        funding_body: invoice.funding_body,
                        invoice_date: invoice.invoice_date,
                        due_date: invoice.due_date,
                        notes: invoice.notes,
                        terms: invoice.terms,
                        email_subject: invoice.email_subject,
                        email_body: invoice.email_body,
                        lines: invoice.lines.map((line) => ({
                            description: line.description,
                            quantity: line.quantity,
                            unit_price: line.unit_price,
                            tax_rate_id: line.tax_rate_id,
                            account_id: line.account_id,
                        })),
                    }}
                />
            )}
        </AppLayout>
    );
}
