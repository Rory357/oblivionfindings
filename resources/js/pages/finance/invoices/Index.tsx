import { Head, Link, router } from '@inertiajs/react';
import { type BreadcrumbItem, PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { PageHero, PageLayout } from '@/components/page';
import { NewInvoiceDialog, ReceivablesTabsFooter, RecordReceiptDialog, type ClientOption, type TaxRateOption } from '@/components/finance';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card, CardContent } from '@/components/ui/card';
import { Plus, Search, AlertTriangle, Send, DollarSign, Clock, FileText, CheckCircle, Receipt, Wallet } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useState } from 'react';

interface Invoice {
    id: number;
    invoice_number: string;
    invoice_date: string;
    due_date: string;
    client_name: string;
    client_email: string | null;
    total_amount: string;
    currency_code: string;
    status: string;
    sent_at: string | null;
    paid_at: string | null;
    amount_due?: number;
    amount_paid?: number;
}

interface PaginatedInvoices {
    data: Invoice[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
}

interface Filters {
    status?: string;
    search?: string;
    date_from?: string;
    date_to?: string;
}

interface Summary {
    total_outstanding: number;
    total_overdue: number;
    draft_count: number;
    paid_this_month: number;
}

interface Props extends PageProps {
    invoices: PaginatedInvoices;
    filters: Filters;
    summary: Summary;
    canManage: boolean;
    clients: ClientOption[];
    taxRates: TaxRateOption[];
}

const formatCurrency = (amount: string | number, currency = 'NZD') =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency }).format(Number(amount));

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

const statusConfig: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draft', className: 'bg-muted text-foreground dark:bg-muted dark:text-muted-foreground' },
    sent: { label: 'Sent', className: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info' },
    viewed: { label: 'Viewed', className: 'bg-primary/10 text-primary dark:bg-primary dark:text-primary/70' },
    paid: { label: 'Paid', className: 'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success' },
    overdue: { label: 'Overdue', className: 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical' },
    cancelled: { label: 'Cancelled', className: 'bg-muted text-muted-foreground dark:bg-muted dark:text-muted-foreground' },
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Invoices', href: '/finance/invoices' },
];

export default function InvoicesIndex({ auth, invoices, filters, summary, canManage, clients, taxRates }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const [receiptInvoice, setReceiptInvoice] = useState<Invoice | null>(null);
    const [newInvoiceOpen, setNewInvoiceOpen] = useState(false);

    const canReceipt = (invoice: Invoice) =>
        canManage &&
        Number(invoice.amount_due ?? 0) > 0 &&
        !['draft', 'cancelled', 'paid'].includes(invoice.status);

    const applyFilters = () => {
        const params: Record<string, string> = {};
        if (search) params.search = search;
        if (status) params.status = status;
        if (dateFrom) params.date_from = dateFrom;
        if (dateTo) params.date_to = dateTo;

        router.get('/finance/invoices', params, { preserveState: true, preserveScroll: true });
    };

    const clearFilters = () => {
        setSearch('');
        setStatus('');
        setDateFrom('');
        setDateTo('');
        router.get('/finance/invoices', {}, { preserveState: true });
    };

    const isOverdue = (invoice: Invoice) => {
        if (invoice.status === 'paid' || invoice.status === 'cancelled') return false;
        return new Date(invoice.due_date) < new Date();
    };

    return (
        <AppLayout user={auth.user} breadcrumbs={breadcrumbs}>
            <Head title="Invoices" />

            <PageLayout
                hero={
                    <PageHero category="finance"
                        icon={Receipt}
                        title="Invoices"
                        description="Manage and send invoices to clients"
                        stats={[
                            { label: 'Outstanding', value: formatCurrency(summary.total_outstanding) },
                            { label: 'Overdue', value: formatCurrency(summary.total_overdue) },
                            { label: 'Drafts', value: summary.draft_count },
                            { label: 'Paid this month', value: formatCurrency(summary.paid_this_month) },
                        ]}
                        actions={
                            canManage && (
                                <Button size="sm" onClick={() => setNewInvoiceOpen(true)}>
                                    <Plus className="w-4 h-4 mr-1.5" />
                                    New Invoice
                                </Button>
                            )
                        }
                        footer={<ReceivablesTabsFooter active="invoices" />}
                    />
                }
            >
                {/* KPI Summary Cards */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-info-bg p-2">
                                    <DollarSign className="h-5 w-5 text-status-info dark:text-status-info" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Outstanding</p>
                                    <p className="text-xl font-bold text-foreground">{formatCurrency(summary.total_outstanding)}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-critical-bg p-2">
                                    <AlertTriangle className="h-5 w-5 text-status-critical dark:text-status-critical" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Overdue</p>
                                    <p className="text-xl font-bold text-foreground">{formatCurrency(summary.total_overdue)}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-muted p-2 dark:bg-muted">
                                    <FileText className="h-5 w-5 text-muted-foreground dark:text-muted-foreground" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Drafts</p>
                                    <p className="text-xl font-bold text-foreground">{summary.draft_count}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-status-success-bg p-2">
                                    <CheckCircle className="h-5 w-5 text-status-success dark:text-status-success" />
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Paid This Month</p>
                                    <p className="text-xl font-bold text-foreground">{formatCurrency(summary.paid_this_month)}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <Card className="mb-6">
                    <CardContent className="pt-6">
                        <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <div className="relative">
                                <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search invoice #, client..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                                    className="pl-9"
                                />
                            </div>
                            <Select value={status} onValueChange={setStatus}>
                                <SelectTrigger>
                                    <SelectValue placeholder="All Statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Statuses</SelectItem>
                                    <SelectItem value="draft">Draft</SelectItem>
                                    <SelectItem value="sent">Sent</SelectItem>
                                    <SelectItem value="viewed">Viewed</SelectItem>
                                    <SelectItem value="paid">Paid</SelectItem>
                                    <SelectItem value="overdue">Overdue</SelectItem>
                                    <SelectItem value="cancelled">Cancelled</SelectItem>
                                </SelectContent>
                            </Select>
                            <Input
                                type="date"
                                value={dateFrom}
                                onChange={(e) => setDateFrom(e.target.value)}
                                placeholder="From"
                            />
                            <Input
                                type="date"
                                value={dateTo}
                                onChange={(e) => setDateTo(e.target.value)}
                                placeholder="To"
                            />
                            <div className="flex gap-2">
                                <Button onClick={applyFilters} variant="secondary" className="shrink-0">
                                    Filter
                                </Button>
                                <Button onClick={clearFilters} variant="ghost" className="shrink-0">
                                    Clear
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Invoice #</TableHead>
                                <TableHead>Client</TableHead>
                                <TableHead>Date</TableHead>
                                <TableHead>Due Date</TableHead>
                                <TableHead className="text-right">Total</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Sent</TableHead>
                                <TableHead className="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {invoices.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={8} className="text-center text-muted-foreground py-8">
                                        No invoices found.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                invoices.data.map((invoice) => (
                                    <TableRow
                                        key={invoice.id}
                                        className={cn(
                                            'cursor-pointer hover:bg-muted/50',
                                            isOverdue(invoice) && 'bg-status-critical-bg hover:bg-status-critical-bg dark:hover:bg-status-critical',
                                        )}
                                        onClick={() => router.get(`/finance/invoices/${invoice.id}`)}
                                    >
                                        <TableCell className="font-medium">
                                            <Link href={`/finance/invoices/${invoice.id}`} className="text-primary hover:underline">
                                                {invoice.invoice_number}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <div>{invoice.client_name}</div>
                                            {invoice.client_email && (
                                                <div className="text-xs text-muted-foreground">{invoice.client_email}</div>
                                            )}
                                        </TableCell>
                                        <TableCell>{formatDate(invoice.invoice_date)}</TableCell>
                                        <TableCell>
                                            <span className="inline-flex items-center gap-1">
                                                {isOverdue(invoice) && <AlertTriangle className="w-3.5 h-3.5 text-status-critical" />}
                                                <span className={cn(isOverdue(invoice) && 'text-status-critical font-medium dark:text-status-critical')}>
                                                    {formatDate(invoice.due_date)}
                                                </span>
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right font-medium">
                                            {formatCurrency(invoice.total_amount, invoice.currency_code)}
                                        </TableCell>
                                        <TableCell>
                                            <Badge className={statusConfig[invoice.status]?.className ?? 'bg-muted text-foreground'}>
                                                {statusConfig[invoice.status]?.label ?? invoice.status}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {invoice.sent_at ? (
                                                <Send className="w-4 h-4 text-status-success" />
                                            ) : (
                                                <span className="text-muted-foreground">-</span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {canReceipt(invoice) && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        setReceiptInvoice(invoice);
                                                    }}
                                                >
                                                    <Wallet className="mr-1.5 h-3.5 w-3.5" />
                                                    Record receipt
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>

                    {/* Pagination */}
                    {invoices.last_page > 1 && (
                        <div className="flex items-center justify-center gap-1 p-4 border-t">
                            {invoices.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={link.active ? 'default' : 'ghost'}
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    )}
                </Card>
            </PageLayout>

            {receiptInvoice && (
                <RecordReceiptDialog
                    key={receiptInvoice.id}
                    open
                    onClose={() => setReceiptInvoice(null)}
                    invoice={{
                        id: receiptInvoice.id,
                        invoice_number: receiptInvoice.invoice_number,
                        client_name: receiptInvoice.client_name,
                        currency_code: receiptInvoice.currency_code,
                        total_amount: receiptInvoice.total_amount,
                        amount_due: Number(receiptInvoice.amount_due ?? 0),
                    }}
                />
            )}

            {canManage && (
                <NewInvoiceDialog
                    open={newInvoiceOpen}
                    onClose={() => setNewInvoiceOpen(false)}
                    clients={clients}
                    taxRates={taxRates}
                />
            )}
        </AppLayout>
    );
}
