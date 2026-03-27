import { Head, Link, router } from '@inertiajs/react';
import { type BreadcrumbItem, PageProps } from '@/types';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Card, CardContent } from '@/components/ui/card';
import { Plus, Search, AlertTriangle, Send, DollarSign, Clock, FileText, CheckCircle } from 'lucide-react';
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
}

const formatCurrency = (amount: string | number, currency = 'NZD') =>
    new Intl.NumberFormat('en-NZ', { style: 'currency', currency }).format(Number(amount));

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });

const statusConfig: Record<string, { label: string; className: string }> = {
    draft: { label: 'Draft', className: 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300' },
    sent: { label: 'Sent', className: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300' },
    viewed: { label: 'Viewed', className: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-300' },
    paid: { label: 'Paid', className: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300' },
    overdue: { label: 'Overdue', className: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300' },
    cancelled: { label: 'Cancelled', className: 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400' },
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance/dashboard' },
    { title: 'Invoices', href: '/finance/invoices' },
];

export default function InvoicesIndex({ auth, invoices, filters, summary }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

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

            <div className="max-w-7xl mx-auto p-6">
                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-3xl font-bold text-foreground">Invoices</h1>
                        <p className="text-muted-foreground mt-1">Manage and send invoices to clients</p>
                    </div>
                    <Button asChild>
                        <Link href="/finance/invoices/create">
                            <Plus className="w-4 h-4 mr-2" />
                            New Invoice
                        </Link>
                    </Button>
                </div>

                {/* KPI Summary Cards */}
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
                    <Card>
                        <CardContent className="pt-6">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-blue-100 p-2 dark:bg-blue-900">
                                    <DollarSign className="h-5 w-5 text-blue-600 dark:text-blue-400" />
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
                                <div className="rounded-lg bg-red-100 p-2 dark:bg-red-900">
                                    <AlertTriangle className="h-5 w-5 text-red-600 dark:text-red-400" />
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
                                <div className="rounded-lg bg-gray-100 p-2 dark:bg-gray-800">
                                    <FileText className="h-5 w-5 text-gray-600 dark:text-gray-400" />
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
                                <div className="rounded-lg bg-green-100 p-2 dark:bg-green-900">
                                    <CheckCircle className="h-5 w-5 text-green-600 dark:text-green-400" />
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
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {invoices.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={7} className="text-center text-muted-foreground py-8">
                                        No invoices found.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                invoices.data.map((invoice) => (
                                    <TableRow
                                        key={invoice.id}
                                        className={cn(
                                            'cursor-pointer hover:bg-muted/50',
                                            isOverdue(invoice) && 'bg-red-50 hover:bg-red-100 dark:bg-red-950/20 dark:hover:bg-red-950/30',
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
                                                {isOverdue(invoice) && <AlertTriangle className="w-3.5 h-3.5 text-red-500" />}
                                                <span className={cn(isOverdue(invoice) && 'text-red-600 font-medium dark:text-red-400')}>
                                                    {formatDate(invoice.due_date)}
                                                </span>
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right font-medium">
                                            {formatCurrency(invoice.total_amount, invoice.currency_code)}
                                        </TableCell>
                                        <TableCell>
                                            <Badge className={statusConfig[invoice.status]?.className ?? 'bg-gray-100 text-gray-800'}>
                                                {statusConfig[invoice.status]?.label ?? invoice.status}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            {invoice.sent_at ? (
                                                <Send className="w-4 h-4 text-green-500" />
                                            ) : (
                                                <span className="text-muted-foreground">-</span>
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
            </div>
        </AppLayout>
    );
}
