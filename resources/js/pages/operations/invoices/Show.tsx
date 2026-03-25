import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Ban, CalendarDays, CheckCircle2, Send } from 'lucide-react';

type LineItem = {
    id: number;
    description: string;
    quantity: number;
    unit_price: number;
    amount: number;
};

type Props = {
    invoice: {
        id: number;
        invoice_number: string;
        status: string;
        client_id: number;
        funding_body: string | null;
        issue_date: string;
        due_date: string;
        payment_terms: string | null;
        notes: string | null;
        subtotal: number;
        tax: number;
        total: number;
        paid_at: string | null;
        created_at: string;
        client: { id: number; first_name: string; last_name: string } | null;
        line_items: LineItem[];
    };
};

const STATUS_VARIANT: Record<string, string> = {
    draft: 'outline',
    sent: 'default',
    paid: 'default',
    overdue: 'destructive',
    void: 'outline',
};

function formatCurrency(n: number): string {
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD', minimumFractionDigits: 2 }).format(n);
}

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function InvoiceShow({ invoice }: Props) {
    const clientDisplay = invoice.client
        ? `${invoice.client.first_name} ${invoice.client.last_name}`
        : 'Unknown';

    const handleAction = (action: string) => {
        router.post(`/operations/invoices/${invoice.id}/${action}`, {}, { preserveScroll: true });
    };

    const isOverdue = invoice.status === 'sent' && new Date(invoice.due_date) < new Date();

    return (
        <AppLayout>
            <Head title={`Invoice ${invoice.invoice_number}`} />
            <PageHeader title={`Invoice ${invoice.invoice_number}`} description={clientDisplay} backHref="/operations/invoices" />
            <PageShell>
                {/* Header */}
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant={STATUS_VARIANT[invoice.status] as any ?? 'outline'} className="capitalize">{invoice.status}</Badge>
                    {isOverdue && <Badge variant="destructive">Overdue</Badge>}
                    {invoice.funding_body && <Badge variant="outline">{invoice.funding_body}</Badge>}
                    <span className="flex items-center gap-1 text-xs text-muted-foreground">
                        <CalendarDays className="h-3 w-3" /> Issued: {formatDate(invoice.issue_date)}
                    </span>
                    <span className={`text-xs ${isOverdue ? 'font-medium text-red-600' : 'text-muted-foreground'}`}>
                        Due: {formatDate(invoice.due_date)}
                    </span>
                    {invoice.payment_terms && (
                        <span className="text-xs text-muted-foreground capitalize">{invoice.payment_terms.replace(/_/g, ' ')}</span>
                    )}
                    <div className="ml-auto flex gap-1">
                        {invoice.status === 'draft' && (
                            <Button size="sm" onClick={() => handleAction('send')}>
                                <Send className="mr-1.5 h-3.5 w-3.5" /> Send
                            </Button>
                        )}
                        {(invoice.status === 'sent' || invoice.status === 'overdue') && (
                            <Button size="sm" onClick={() => handleAction('mark-paid')}>
                                <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" /> Mark Paid
                            </Button>
                        )}
                        {invoice.status !== 'void' && invoice.status !== 'paid' && (
                            <Button size="sm" variant="outline" onClick={() => handleAction('void')}>
                                <Ban className="mr-1.5 h-3.5 w-3.5" /> Void
                            </Button>
                        )}
                    </div>
                </div>

                {/* Summary Cards */}
                <div className="mt-4 grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Client</CardTitle></CardHeader>
                        <CardContent className="space-y-1 text-xs">
                            <div className="flex justify-between"><span className="text-muted-foreground">Name</span><span className="font-medium">{clientDisplay}</span></div>
                            {invoice.funding_body && <div className="flex justify-between"><span className="text-muted-foreground">Funding Body</span><span>{invoice.funding_body}</span></div>}
                            <div className="flex justify-between"><span className="text-muted-foreground">Created</span><span>{formatDate(invoice.created_at)}</span></div>
                            {invoice.paid_at && <div className="flex justify-between"><span className="text-muted-foreground">Paid</span><span className="font-medium text-emerald-600">{formatDate(invoice.paid_at)}</span></div>}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Dates</CardTitle></CardHeader>
                        <CardContent className="space-y-1 text-xs">
                            <div className="flex justify-between"><span className="text-muted-foreground">Issue Date</span><span>{formatDate(invoice.issue_date)}</span></div>
                            <div className="flex justify-between"><span className="text-muted-foreground">Due Date</span><span className={isOverdue ? 'font-medium text-red-600' : ''}>{formatDate(invoice.due_date)}</span></div>
                            {invoice.payment_terms && <div className="flex justify-between"><span className="text-muted-foreground">Terms</span><span className="capitalize">{invoice.payment_terms.replace(/_/g, ' ')}</span></div>}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Totals</CardTitle></CardHeader>
                        <CardContent className="space-y-1 text-xs">
                            <div className="flex justify-between"><span className="text-muted-foreground">Subtotal</span><span className="tabular-nums">{formatCurrency(invoice.subtotal)}</span></div>
                            <div className="flex justify-between"><span className="text-muted-foreground">GST (15%)</span><span className="tabular-nums">{formatCurrency(invoice.tax)}</span></div>
                            <div className="flex justify-between border-t pt-1 font-semibold"><span>Total (NZD)</span><span className="tabular-nums">{formatCurrency(invoice.total)}</span></div>
                        </CardContent>
                    </Card>
                </div>

                {/* Line Items */}
                <Card className="mt-4">
                    <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Line Items ({invoice.line_items.length})</CardTitle></CardHeader>
                    <CardContent className="p-0">
                        {invoice.line_items.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">No line items.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                                            <th className="px-4 py-2">Description</th>
                                            <th className="px-4 py-2 text-right">Qty</th>
                                            <th className="px-4 py-2 text-right">Unit Price</th>
                                            <th className="px-4 py-2 text-right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {invoice.line_items.map((item) => (
                                            <tr key={item.id} className="border-b last:border-0">
                                                <td className="px-4 py-2 text-xs font-medium">{item.description}</td>
                                                <td className="px-4 py-2 text-right text-xs tabular-nums">{item.quantity}</td>
                                                <td className="px-4 py-2 text-right text-xs tabular-nums">{formatCurrency(item.unit_price)}</td>
                                                <td className="px-4 py-2 text-right text-xs font-medium tabular-nums">{formatCurrency(item.amount)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot>
                                        <tr className="border-t">
                                            <td colSpan={3} className="px-4 py-2 text-right text-xs text-muted-foreground">Subtotal</td>
                                            <td className="px-4 py-2 text-right text-xs tabular-nums">{formatCurrency(invoice.subtotal)}</td>
                                        </tr>
                                        <tr>
                                            <td colSpan={3} className="px-4 py-1 text-right text-xs text-muted-foreground">GST (15%)</td>
                                            <td className="px-4 py-1 text-right text-xs tabular-nums">{formatCurrency(invoice.tax)}</td>
                                        </tr>
                                        <tr className="border-t font-semibold">
                                            <td colSpan={3} className="px-4 py-2 text-right text-xs">Total (NZD)</td>
                                            <td className="px-4 py-2 text-right text-xs tabular-nums">{formatCurrency(invoice.total)}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Notes */}
                {invoice.notes && (
                    <Card className="mt-4">
                        <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Notes</CardTitle></CardHeader>
                        <CardContent><p className="whitespace-pre-wrap text-xs">{invoice.notes}</p></CardContent>
                    </Card>
                )}
            </PageShell>
        </AppLayout>
    );
}
