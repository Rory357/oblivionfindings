import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowRight, CalendarDays, CheckCircle2, FileText, Mail, Pencil, Send } from 'lucide-react';

type LineItem = {
    id: number;
    description: string;
    quantity: number;
    unit: string;
    unit_price: number;
    amount: number;
};

type Props = {
    quote: {
        id: number;
        title: string;
        status: string;
        client_id: number | null;
        client_name: string | null;
        client_email: string | null;
        client_phone: string | null;
        valid_until: string | null;
        notes: string | null;
        terms: string | null;
        subtotal: number;
        tax: number;
        total: number;
        created_at: string;
        client: { id: number; first_name: string; last_name: string } | null;
        line_items: LineItem[];
    };
};

const STATUS_COLORS: Record<string, string> = {
    draft: 'outline',
    sent: 'default',
    accepted: 'default',
    declined: 'destructive',
    expired: 'outline',
    converted: 'default',
};

const STATUS_STEPS = ['draft', 'sent', 'accepted', 'converted'];

function formatCurrency(n: number): string {
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD', minimumFractionDigits: 2 }).format(n);
}

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function QuoteShow({ quote }: Props) {
    const clientDisplay = quote.client
        ? `${quote.client.first_name} ${quote.client.last_name}`
        : quote.client_name ?? 'Unknown';

    const currentStepIndex = STATUS_STEPS.indexOf(quote.status);

    const handleAction = (action: string) => {
        router.post(`/finance/quotes/${quote.id}/${action}`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title={quote.title} />
            <PageHero variant="compact" title={quote.title} description={clientDisplay} backHref="/finance/quotes" />
            <PageShell>
                {/* Header */}
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant={STATUS_COLORS[quote.status] as any ?? 'outline'} className="capitalize">{quote.status}</Badge>
                    {quote.valid_until && (
                        <span className={`flex items-center gap-1 text-xs ${new Date(quote.valid_until) < new Date() ? 'font-medium text-status-warning' : 'text-muted-foreground'}`}>
                            <CalendarDays className="h-3 w-3" /> Valid until: {formatDate(quote.valid_until)}
                        </span>
                    )}
                    <div className="ml-auto flex gap-1">
                        {quote.status === 'draft' && (
                            <>
                                <Button asChild size="sm" variant="outline">
                                    <Link href={`/finance/quotes/${quote.id}/edit`}><Pencil className="mr-1.5 h-3.5 w-3.5" /> Edit</Link>
                                </Button>
                                <Button size="sm" onClick={() => handleAction('send')}>
                                    <Send className="mr-1.5 h-3.5 w-3.5" /> Send
                                </Button>
                            </>
                        )}
                        {quote.status === 'sent' && (
                            <Button size="sm" onClick={() => handleAction('accept')}>
                                <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" /> Accept
                            </Button>
                        )}
                        {quote.status === 'accepted' && (
                            <Button size="sm" onClick={() => handleAction('convert')}>
                                <FileText className="mr-1.5 h-3.5 w-3.5" /> Convert to Agreement
                            </Button>
                        )}
                    </div>
                </div>

                {/* Status Workflow */}
                <Card className="mt-4">
                    <CardContent className="py-4">
                        <div className="flex items-center justify-between">
                            {STATUS_STEPS.map((step, index) => (
                                <div key={step} className="flex items-center">
                                    <div className="flex flex-col items-center">
                                        <div className={`flex h-8 w-8 items-center justify-center rounded-full text-xs font-medium ${
                                            index <= currentStepIndex
                                                ? 'bg-primary text-primary-foreground'
                                                : 'bg-muted text-muted-foreground'
                                        }`}>
                                            {index + 1}
                                        </div>
                                        <span className="mt-1 text-[10px] capitalize text-muted-foreground">{step}</span>
                                    </div>
                                    {index < STATUS_STEPS.length - 1 && (
                                        <div className={`mx-2 h-0.5 w-12 sm:w-20 ${index < currentStepIndex ? 'bg-primary' : 'bg-muted'}`} />
                                    )}
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {/* Client Info + Details */}
                <div className="mt-4 grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Client Information</CardTitle></CardHeader>
                        <CardContent className="space-y-1 text-xs">
                            <div className="flex justify-between"><span className="text-muted-foreground">Name</span><span className="font-medium">{clientDisplay}</span></div>
                            {quote.client_email && <div className="flex justify-between"><span className="text-muted-foreground">Email</span><span>{quote.client_email}</span></div>}
                            {quote.client_phone && <div className="flex justify-between"><span className="text-muted-foreground">Phone</span><span>{quote.client_phone}</span></div>}
                            <div className="flex justify-between"><span className="text-muted-foreground">Created</span><span>{formatDate(quote.created_at)}</span></div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Quote Summary</CardTitle></CardHeader>
                        <CardContent className="space-y-1 text-xs">
                            <div className="flex justify-between"><span className="text-muted-foreground">Subtotal</span><span className="tabular-nums">{formatCurrency(quote.subtotal)}</span></div>
                            <div className="flex justify-between"><span className="text-muted-foreground">GST (15%)</span><span className="tabular-nums">{formatCurrency(quote.tax)}</span></div>
                            <div className="flex justify-between border-t pt-1 font-semibold"><span>Total (NZD)</span><span className="tabular-nums">{formatCurrency(quote.total)}</span></div>
                        </CardContent>
                    </Card>
                </div>

                {/* Line Items */}
                <Card className="mt-4">
                    <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Line Items ({quote.line_items.length})</CardTitle></CardHeader>
                    <CardContent className="p-0">
                        {quote.line_items.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">No line items.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                                            <th className="px-4 py-2">Description</th>
                                            <th className="px-4 py-2 text-right">Qty</th>
                                            <th className="px-4 py-2">Unit</th>
                                            <th className="px-4 py-2 text-right">Unit Price</th>
                                            <th className="px-4 py-2 text-right">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {quote.line_items.map((item) => (
                                            <tr key={item.id} className="border-b last:border-0">
                                                <td className="px-4 py-2 text-xs font-medium">{item.description}</td>
                                                <td className="px-4 py-2 text-right text-xs tabular-nums">{item.quantity}</td>
                                                <td className="px-4 py-2 text-xs capitalize text-muted-foreground">{item.unit}</td>
                                                <td className="px-4 py-2 text-right text-xs tabular-nums">{formatCurrency(item.unit_price)}</td>
                                                <td className="px-4 py-2 text-right text-xs font-medium tabular-nums">{formatCurrency(item.amount)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot>
                                        <tr className="border-t">
                                            <td colSpan={4} className="px-4 py-2 text-right text-xs text-muted-foreground">Subtotal</td>
                                            <td className="px-4 py-2 text-right text-xs tabular-nums">{formatCurrency(quote.subtotal)}</td>
                                        </tr>
                                        <tr>
                                            <td colSpan={4} className="px-4 py-1 text-right text-xs text-muted-foreground">GST (15%)</td>
                                            <td className="px-4 py-1 text-right text-xs tabular-nums">{formatCurrency(quote.tax)}</td>
                                        </tr>
                                        <tr className="border-t font-semibold">
                                            <td colSpan={4} className="px-4 py-2 text-right text-xs">Total (NZD)</td>
                                            <td className="px-4 py-2 text-right text-xs tabular-nums">{formatCurrency(quote.total)}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Notes & Terms */}
                {(quote.notes || quote.terms) && (
                    <div className="mt-4 grid gap-4 md:grid-cols-2">
                        {quote.notes && (
                            <Card>
                                <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Notes</CardTitle></CardHeader>
                                <CardContent><p className="whitespace-pre-wrap text-xs">{quote.notes}</p></CardContent>
                            </Card>
                        )}
                        {quote.terms && (
                            <Card>
                                <CardHeader className="pb-2"><CardTitle className="text-sm font-medium">Terms & Conditions</CardTitle></CardHeader>
                                <CardContent><p className="whitespace-pre-wrap text-xs">{quote.terms}</p></CardContent>
                            </Card>
                        )}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
