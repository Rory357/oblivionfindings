import { Head, Link, router } from '@inertiajs/react';
import { type BreadcrumbItem, PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Separator } from '@/components/ui/separator';
import { AlertTriangle, CheckCircle, Download, Edit, Mail, Send } from 'lucide-react';
import { cn } from '@/lib/utils';

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

const formatCurrency = (amount: string | number, currency = 'NZD') =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency }).format(Number(amount));

const formatDate = (date: string | null) =>
    date ? new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' }) : '-';

const formatDateTime = (date: string | null) =>
    date ? new Date(date).toLocaleString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '-';

const statusConfig: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draft', className: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300' },
    sent: { label: 'Sent', className: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300' },
    viewed: { label: 'Viewed', className: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300' },
    paid: { label: 'Paid', className: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300' },
    overdue: { label: 'Overdue', className: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300' },
    cancelled: { label: 'Cancelled', className: 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' },
};

export default function InvoiceShow({ auth, invoice }: Props) {
    const isOverdue = invoice.status !== 'paid' && invoice.status !== 'cancelled' && new Date(invoice.due_date) < new Date();
    const isDraft = invoice.status === 'draft';
    const canSend = invoice.status !== 'cancelled' && invoice.status !== 'paid' && !!invoice.client_email;
    const canMarkPaid = invoice.status !== 'cancelled' && invoice.status !== 'paid';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Finance', href: '/finance/dashboard' },
        { title: 'Invoices', href: '/finance/invoices' },
        { title: invoice.invoice_number, href: `/finance/invoices/${invoice.id}` },
    ];

    const handleSend = () => {
        if (confirm('Send this invoice to ' + invoice.client_email + '?')) {
            router.post(`/finance/invoices/${invoice.id}/send`);
        }
    };

    const handleMarkPaid = () => {
        if (confirm('Mark this invoice as paid?')) {
            router.post(`/finance/invoices/${invoice.id}/mark-paid`);
        }
    };

    return (
        <AppLayout user={auth.user} breadcrumbs={breadcrumbs}>
            <Head title={`Invoice ${invoice.invoice_number}`} />

            <div className="max-w-7xl mx-auto p-6">
                {/* Header */}
                <div className="flex items-start justify-between mb-6">
                    <div>
                        <div className="flex items-center gap-3">
                            <h1 className="text-3xl font-bold text-foreground">{invoice.invoice_number}</h1>
                            <Badge className={statusConfig[invoice.status]?.className ?? 'bg-gray-100 text-gray-800'}>
                                {statusConfig[invoice.status]?.label ?? invoice.status}
                            </Badge>
                            {isOverdue && (
                                <Badge className="bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300">
                                    <AlertTriangle className="w-3 h-3 mr-1" />
                                    Overdue
                                </Badge>
                            )}
                        </div>
                        <p className="text-muted-foreground mt-1">{invoice.client_name}</p>
                    </div>
                    <div className="flex items-center gap-2">
                        {isDraft && (
                            <Button variant="outline" asChild>
                                <Link href={`/finance/invoices/${invoice.id}/edit`}>
                                    <Edit className="w-4 h-4 mr-2" />
                                    Edit
                                </Link>
                            </Button>
                        )}
                        <Button variant="outline" asChild>
                            <a href={`/finance/invoices/${invoice.id}/pdf`}>
                                <Download className="w-4 h-4 mr-2" />
                                Download PDF
                            </a>
                        </Button>
                        {canSend && (
                            <Button onClick={handleSend}>
                                <Send className="w-4 h-4 mr-2" />
                                Send Email
                            </Button>
                        )}
                        {canMarkPaid && (
                            <Button variant="secondary" onClick={handleMarkPaid}>
                                <CheckCircle className="w-4 h-4 mr-2" />
                                Mark Paid
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                    {/* Invoice Info */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Invoice Details</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Invoice Date</span>
                                <span className="font-medium">{formatDate(invoice.invoice_date)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Due Date</span>
                                <span className={cn('font-medium', isOverdue && 'text-red-600 dark:text-red-400')}>
                                    {formatDate(invoice.due_date)}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Currency</span>
                                <span className="font-medium">{invoice.currency_code}</span>
                            </div>
                            {invoice.bill && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Linked Bill</span>
                                    <Link href={`/finance/bills/${invoice.bill.id}`} className="text-primary hover:underline font-medium">
                                        {invoice.bill.bill_number}
                                    </Link>
                                </div>
                            )}
                            {invoice.created_by && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Created By</span>
                                    <span className="font-medium">{invoice.created_by.name}</span>
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
                                <span className="font-medium text-base">{invoice.client_name}</span>
                            </div>
                            {invoice.client_email && (
                                <div className="flex items-center gap-2">
                                    <Mail className="w-4 h-4 text-muted-foreground" />
                                    <span>{invoice.client_email}</span>
                                </div>
                            )}
                            {invoice.client_address && (
                                <div className="pt-2 border-t">
                                    <p className="text-muted-foreground whitespace-pre-wrap">{invoice.client_address}</p>
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
                                <span>{formatCurrency(invoice.subtotal, invoice.currency_code)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">GST</span>
                                <span>{formatCurrency(invoice.tax_amount, invoice.currency_code)}</span>
                            </div>
                            <Separator />
                            <div className="flex justify-between font-bold text-base">
                                <span>Total</span>
                                <span>{formatCurrency(invoice.total_amount, invoice.currency_code)}</span>
                            </div>

                            {invoice.sent_at && (
                                <>
                                    <Separator />
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground">Sent At</span>
                                        <span className="font-medium">{formatDateTime(invoice.sent_at)}</span>
                                    </div>
                                </>
                            )}
                            {invoice.viewed_at && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">Viewed At</span>
                                    <span className="font-medium">{formatDateTime(invoice.viewed_at)}</span>
                                </div>
                            )}
                            {invoice.paid_at && (
                                <div className="flex justify-between text-green-700 dark:text-green-400">
                                    <span>Paid At</span>
                                    <span className="font-medium">{formatDateTime(invoice.paid_at)}</span>
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
                                <TableHead>Tax Rate</TableHead>
                                <TableHead>Account</TableHead>
                                <TableHead className="text-right">Tax</TableHead>
                                <TableHead className="text-right">Total</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {invoice.lines.map((line) => (
                                <TableRow key={line.id}>
                                    <TableCell>{line.description}</TableCell>
                                    <TableCell className="text-right">{Number(line.quantity).toFixed(2)}</TableCell>
                                    <TableCell className="text-right">{formatCurrency(line.unit_price, invoice.currency_code)}</TableCell>
                                    <TableCell className="text-sm">
                                        {line.tax_rate ? `${line.tax_rate.name} (${line.tax_rate.rate}%)` : '15% GST'}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {line.account ? `${line.account.code} - ${line.account.name}` : '-'}
                                    </TableCell>
                                    <TableCell className="text-right">{formatCurrency(line.tax_amount, invoice.currency_code)}</TableCell>
                                    <TableCell className="text-right font-medium">{formatCurrency(line.line_total, invoice.currency_code)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>

                {/* Notes & Terms */}
                {(invoice.notes || invoice.terms) && (
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        {invoice.notes && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Notes</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-sm text-muted-foreground whitespace-pre-wrap">{invoice.notes}</p>
                                </CardContent>
                            </Card>
                        )}
                        {invoice.terms && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Payment Terms</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-sm text-muted-foreground whitespace-pre-wrap">{invoice.terms}</p>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
