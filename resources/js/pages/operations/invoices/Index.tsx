import { OPS_COLORS, OpsStatCard } from '@/components/ops-stat-card';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, DollarSign, Eye, FileText, Plus, Receipt, Search } from 'lucide-react';

const ANY = '__ANY__';

type Invoice = {
    id: number;
    invoice_number: string;
    reference: string | null;
    status: string;
    issue_date: string;
    due_date: string | null;
    paid_date: string | null;
    subtotal: number;
    tax_amount: number;
    total_amount: number;
    client: { id: number; first_name: string; last_name: string } | null;
    funding_body: string | null;
    items_count: number;
};

type Props = {
    invoices: {
        data: Invoice[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: { status?: string; q?: string };
    stats: { total: number; draft: number; sent: number; paid: number; overdue: number };
};

const STATUS_VARIANTS: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    draft: 'outline',
    sent: 'secondary',
    paid: 'default',
    overdue: 'destructive',
    cancelled: 'secondary',
};

function formatCurrency(n: number): string {
    return new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD' }).format(n);
}

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function InvoicesIndex({ invoices = { data: [], links: [], current_page: 1, last_page: 1, total: 0 }, filters = {} as any, stats = {} as any }: Props) {
    const s = stats ?? { total: 0, draft: 0, sent: 0, paid: 0, overdue: 0 };

    const updateFilters = (key: string, value: string | null) => {
        router.get('/operations/invoices', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout>
            <Head title="Invoices" />
            <PageHeader title="Invoices" description="Create and manage invoices for clients and funding bodies." backHref="/operations" />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <OpsStatCard label="Draft" value={s.draft} icon={FileText} color="slate" />
                    <OpsStatCard label="Sent" value={s.sent} icon={Receipt} color="blue" />
                    <OpsStatCard label="Paid" value={s.paid} icon={DollarSign} color="emerald" />
                    <OpsStatCard label="Overdue" value={s.overdue} icon={AlertTriangle} color={s.overdue > 0 ? 'red' : 'slate'} />
                </div>

                {/* Filters */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input placeholder="Search invoices..." className="h-9 pl-8 text-sm" defaultValue={filters?.q ?? ''} onChange={(e) => updateFilters('q', e.target.value || null)} />
                    </div>
                    <Select value={filters?.status ?? ANY} onValueChange={(v) => updateFilters('status', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[120px] text-xs"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Status</SelectItem>
                            <SelectItem value="draft">Draft</SelectItem>
                            <SelectItem value="sent">Sent</SelectItem>
                            <SelectItem value="paid">Paid</SelectItem>
                            <SelectItem value="overdue">Overdue</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button asChild size="sm">
                        <Link href="/operations/invoices/create">
                            <Plus className="mr-1.5 h-3.5 w-3.5" /> Create Invoice
                        </Link>
                    </Button>
                </div>

                {/* Invoice list */}
                <div className="mt-4 space-y-2">
                    {(invoices?.data ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <Receipt className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Invoices</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Generate invoices from billing entries.</p>
                                <Button asChild size="sm" className="mt-4">
                                    <Link href="/operations/invoices/create">Create Invoice</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    )}
                    {(invoices?.data ?? []).map((inv) => (
                        <Card key={inv.id} className="transition-all hover:border-border hover:shadow-sm">
                            <CardContent className="flex items-center gap-4 p-4">
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <Link href={`/operations/invoices/${inv.id}`} className="text-sm font-semibold hover:underline">
                                            {inv.invoice_number}
                                        </Link>
                                        <Badge variant={STATUS_VARIANTS[inv.status] ?? 'outline'} className="h-4 px-1.5 text-[9px] capitalize">{inv.status}</Badge>
                                        {inv.reference && <span className="text-[10px] text-muted-foreground">Ref: {inv.reference}</span>}
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                        {inv.client && <span>{inv.client.first_name} {inv.client.last_name}</span>}
                                        {inv.funding_body && <span>{inv.funding_body}</span>}
                                        <span>Issued: {formatDate(inv.issue_date)}</span>
                                        {inv.due_date && <span>Due: {formatDate(inv.due_date)}</span>}
                                        <span>{inv.items_count} items</span>
                                    </div>
                                </div>
                                <div className="flex items-center gap-3">
                                    <span className="text-sm font-bold tabular-nums">{formatCurrency(inv.total_amount)}</span>
                                    <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                        <Link href={`/operations/invoices/${inv.id}`}><Eye className="h-3.5 w-3.5" /></Link>
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Pagination */}
                {(invoices?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(invoices?.links ?? []).map((link: any, i: number) => (
                            <Button key={i} size="sm" variant={link.active ? 'default' : 'outline'} className="h-7 min-w-[28px] px-2 text-xs" disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })} dangerouslySetInnerHTML={{ __html: link.label }} />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
