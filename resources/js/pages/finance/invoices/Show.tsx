import { ConfirmDialog, formatMoney } from '@/components/finance';
import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
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
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, PageProps } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
    Download,
    Edit,
    Mail,
    Send,
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
    tax_rate: { id: number; name: string; rate: string } | null;
    account: { id: number; code: string; name: string } | null;
}

interface Invoice {
    id: number;
    invoice_number: string;
    invoice_date: string;
    due_date: string;
    client_name: string;
    client_email: string | null;
    client_address: string | null;
    bill: { id: number; bill_number: string } | null;
    subtotal: string;
    tax_amount: string;
    total_amount: string;
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
}

const formatDate = (date: string | null) =>
    date
        ? new Date(date).toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          })
        : '-';

const formatDateTime = (date: string | null) =>
    date
        ? new Date(date).toLocaleString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '-';

export default function InvoiceShow({ auth, invoice }: Props) {
    const isOverdue =
        invoice.status !== 'paid' &&
        invoice.status !== 'cancelled' &&
        new Date(invoice.due_date) < new Date();
    const isDraft = invoice.status === 'draft';
    const canSend =
        invoice.status !== 'cancelled' &&
        invoice.status !== 'paid' &&
        !!invoice.client_email;
    const canMarkPaid =
        invoice.status !== 'cancelled' && invoice.status !== 'paid';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance' },
        { title: 'Invoices', href: '/finance/invoices' },
        {
            title: invoice.invoice_number,
            href: `/finance/invoices/${invoice.id}`,
        },
    ];

    const [sendOpen, setSendOpen] = useState(false);
    const [sending, setSending] = useState(false);
    const [markPaidOpen, setMarkPaidOpen] = useState(false);
    const [markingPaid, setMarkingPaid] = useState(false);

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

    const confirmMarkPaid = () => {
        router.post(
            `/finance/invoices/${invoice.id}/mark-paid`,
            {},
            {
                onStart: () => setMarkingPaid(true),
                onFinish: () => setMarkingPaid(false),
                onSuccess: () => setMarkPaidOpen(false),
            },
        );
    };

    return (
        <AppLayout user={auth.user} breadcrumbs={breadcrumbs}>
            <Head title={`Invoice ${invoice.invoice_number}`} />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        variant="compact"
                        backHref="/finance/invoices"
                        title={
                            <span className="flex flex-wrap items-center gap-3">
                                {invoice.invoice_number}
                                <StatusBadge status={invoice.status} />
                                {isOverdue && (
                                    <Badge className="bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical">
                                        <AlertTriangle className="mr-1 h-3 w-3" />
                                        Overdue
                                    </Badge>
                                )}
                            </span>
                        }
                        description={invoice.client_name}
                        actions={
                            <>
                                {isDraft && (
                                    <Button variant="outline" asChild>
                                        <Link
                                            href={`/finance/invoices/${invoice.id}/edit`}
                                        >
                                            <Edit className="mr-2 h-4 w-4" />
                                            Edit
                                        </Link>
                                    </Button>
                                )}
                                <Button variant="outline" asChild>
                                    <a
                                        href={`/finance/invoices/${invoice.id}/pdf`}
                                    >
                                        <Download className="mr-2 h-4 w-4" />
                                        Download PDF
                                    </a>
                                </Button>
                                {canSend && (
                                    <Button onClick={() => setSendOpen(true)}>
                                        <Send className="mr-2 h-4 w-4" />
                                        Send Email
                                    </Button>
                                )}
                                {canMarkPaid && (
                                    <Button
                                        variant="secondary"
                                        onClick={() => setMarkPaidOpen(true)}
                                    >
                                        <CheckCircle className="mr-2 h-4 w-4" />
                                        Mark Paid
                                    </Button>
                                )}
                            </>
                        }
                    />
                }
            >
                <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Invoice Info */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Invoice Details
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Invoice Date
                                </span>
                                <span className="font-medium">
                                    {formatDate(invoice.invoice_date)}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Due Date
                                </span>
                                <span
                                    className={cn(
                                        'font-medium',
                                        isOverdue &&
                                            'text-status-critical dark:text-status-critical',
                                    )}
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
                                        Linked Bill
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
                                        Created By
                                    </span>
                                    <span className="font-medium">
                                        {invoice.created_by.name}
                                    </span>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Client Details */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Client</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div>
                                <span className="text-base font-medium">
                                    {invoice.client_name}
                                </span>
                            </div>
                            {invoice.client_email && (
                                <div className="flex items-center gap-2">
                                    <Mail className="h-4 w-4 text-muted-foreground" />
                                    <span>{invoice.client_email}</span>
                                </div>
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

                    {/* Amounts */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Amounts</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    Subtotal
                                </span>
                                <span>
                                    {formatMoney(invoice.subtotal, {
                                        currency: invoice.currency_code,
                                    })}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">
                                    GST
                                </span>
                                <span>
                                    {formatMoney(invoice.tax_amount, {
                                        currency: invoice.currency_code,
                                    })}
                                </span>
                            </div>
                            <Separator />
                            <div className="flex justify-between text-base font-bold">
                                <span>Total</span>
                                <span>
                                    {formatMoney(invoice.total_amount, {
                                        currency: invoice.currency_code,
                                    })}
                                </span>
                            </div>

                            {invoice.sent_at && (
                                <>
                                    <Separator />
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">
                                            Sent At
                                        </span>
                                        <span className="font-medium">
                                            {formatDateTime(invoice.sent_at)}
                                        </span>
                                    </div>
                                </>
                            )}
                            {invoice.viewed_at && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">
                                        Viewed At
                                    </span>
                                    <span className="font-medium">
                                        {formatDateTime(invoice.viewed_at)}
                                    </span>
                                </div>
                            )}
                            {invoice.paid_at && (
                                <div className="flex justify-between text-status-success dark:text-status-success">
                                    <span>Paid At</span>
                                    <span className="font-medium">
                                        {formatDateTime(invoice.paid_at)}
                                    </span>
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
                                <TableHead className="text-right">
                                    Qty
                                </TableHead>
                                <TableHead className="text-right">
                                    Unit Price
                                </TableHead>
                                <TableHead>Tax Rate</TableHead>
                                <TableHead>Account</TableHead>
                                <TableHead className="text-right">
                                    Tax
                                </TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {invoice.lines.map((line) => (
                                <TableRow key={line.id}>
                                    <TableCell>{line.description}</TableCell>
                                    <TableCell className="text-right">
                                        {Number(line.quantity).toFixed(2)}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(line.unit_price, {
                                            currency: invoice.currency_code,
                                        })}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {line.tax_rate
                                            ? `${line.tax_rate.name} (${line.tax_rate.rate}%)`
                                            : '15% GST'}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {line.account
                                            ? `${line.account.code} - ${line.account.name}`
                                            : '-'}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {formatMoney(line.tax_amount, {
                                            currency: invoice.currency_code,
                                        })}
                                    </TableCell>
                                    <TableCell className="text-right font-medium">
                                        {formatMoney(line.line_total, {
                                            currency: invoice.currency_code,
                                        })}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>

                {/* Notes & Terms */}
                {(invoice.notes || invoice.terms) && (
                    <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
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
                                        Payment Terms
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
            </PageLayout>

            <ConfirmDialog
                open={sendOpen}
                onOpenChange={setSendOpen}
                title="Send invoice?"
                description={`This marks ${invoice.invoice_number} as sent and records the send date. The client can then be issued the invoice.`}
                confirmLabel="Send invoice"
                processing={sending}
                onConfirm={confirmSend}
            />
            <ConfirmDialog
                open={markPaidOpen}
                onOpenChange={setMarkPaidOpen}
                title="Mark invoice as paid?"
                description={`This records ${invoice.invoice_number} as fully paid and posts the receipt to the general ledger. This can't be undone.`}
                confirmLabel="Mark as paid"
                processing={markingPaid}
                onConfirm={confirmMarkPaid}
            />
        </AppLayout>
    );
}
