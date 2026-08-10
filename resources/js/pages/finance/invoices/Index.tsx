import {
    formatMoney,
    NewInvoiceDialog,
    ReceivablesTabsFooter,
    RecordReceiptDialog,
    useRowContextMenu,
    type ClientOption,
    type RowCtxItem,
    type TaxRateOption,
} from '@/components/finance';
import { FinanceSummaryCard } from '@/components/finance/summary-card';
import { PageHero, PageLayout } from '@/components/page';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
import { PageProps, type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
    DollarSign,
    Download,
    Eye,
    FileText,
    Pencil,
    Plus,
    Receipt,
    Search,
    Send,
    Wallet,
} from 'lucide-react';
import { useState } from 'react';

interface InvoiceLine {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    tax_rate_id: number | null;
}

interface Invoice {
    id: number;
    invoice_number: string;
    invoice_date: string;
    due_date: string;
    client_id: number | null;
    client_name: string;
    client_email: string | null;
    funding_body: string | null;
    notes: string | null;
    total_amount: string;
    currency_code: string;
    status: string;
    sent_at: string | null;
    paid_at: string | null;
    amount_due?: number;
    amount_paid?: number;
    lines: InvoiceLine[];
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

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Finance', href: '/finance' },
    { title: 'Invoices', href: '/finance/invoices' },
];

export default function InvoicesIndex({
    auth,
    invoices,
    filters,
    summary,
    canManage,
    clients,
    taxRates,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const [receiptInvoice, setReceiptInvoice] = useState<Invoice | null>(null);
    const [newInvoiceOpen, setNewInvoiceOpen] = useState(false);
    const [editInvoice, setEditInvoice] = useState<Invoice | null>(null);

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

        router.get('/finance/invoices', params, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    const clearFilters = () => {
        setSearch('');
        setStatus('');
        setDateFrom('');
        setDateTo('');
        router.get('/finance/invoices', {}, { preserveState: true });
    };

    const hasFilters = Boolean(
        search || (status && status !== 'all') || dateFrom || dateTo,
    );

    const isOverdue = (invoice: Invoice) => {
        if (invoice.status === 'paid' || invoice.status === 'cancelled')
            return false;
        return new Date(invoice.due_date) < new Date();
    };

    // Right-click row menu — mirrors the row's existing inline actions (Open first).
    const rowMenu = useRowContextMenu();
    const rowMenuItems = (invoice: Invoice): RowCtxItem[] => {
        const items: RowCtxItem[] = [
            {
                kind: 'item',
                label: 'Open',
                icon: Eye,
                onSelect: () => router.get(`/finance/invoices/${invoice.id}`),
            },
        ];
        if (canManage && invoice.status === 'draft') {
            items.push({
                kind: 'item',
                label: 'Edit',
                icon: Pencil,
                onSelect: () => setEditInvoice(invoice),
            });
        }
        if (canReceipt(invoice)) {
            items.push({
                kind: 'item',
                label: 'Record receipt',
                icon: Wallet,
                onSelect: () => setReceiptInvoice(invoice),
            });
        }
        return items;
    };

    return (
        <AppLayout user={auth.user} breadcrumbs={breadcrumbs}>
            <Head title="Invoices" />

            <PageLayout
                hero={
                    <PageHero
                        category="finance"
                        icon={Receipt}
                        title="Invoices"
                        description="Manage and send invoices to clients"
                        stats={[
                            {
                                label: 'Outstanding',
                                value: formatMoney(summary.total_outstanding),
                            },
                            {
                                label: 'Overdue',
                                value: formatMoney(summary.total_overdue),
                            },
                            { label: 'Drafts', value: summary.draft_count },
                            {
                                label: 'Paid this month',
                                value: formatMoney(summary.paid_this_month),
                            },
                        ]}
                        actions={
                            <div className="flex flex-wrap items-center gap-2">
                                <Button size="sm" variant="outline" asChild>
                                    <a
                                        href={`/finance/invoices/export?${new URLSearchParams(Object.entries({ status, search, date_from: dateFrom, date_to: dateTo }).filter(([, v]) => v)).toString()}`}
                                    >
                                        <Download className="mr-1.5 h-4 w-4" />
                                        Export CSV
                                    </a>
                                </Button>
                                {canManage && (
                                    <Button
                                        size="sm"
                                        onClick={() => setNewInvoiceOpen(true)}
                                    >
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New Invoice
                                    </Button>
                                )}
                            </div>
                        }
                        footer={<ReceivablesTabsFooter active="invoices" />}
                    />
                }
            >
                {/* KPI Summary Cards */}
                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FinanceSummaryCard
                        icon={DollarSign}
                        tone="info"
                        label="Outstanding"
                        value={formatMoney(summary.total_outstanding)}
                    />
                    <FinanceSummaryCard
                        icon={AlertTriangle}
                        tone="critical"
                        label="Overdue"
                        value={formatMoney(summary.total_overdue)}
                    />
                    <FinanceSummaryCard
                        icon={FileText}
                        tone="muted"
                        label="Drafts"
                        value={summary.draft_count}
                    />
                    <FinanceSummaryCard
                        icon={CheckCircle}
                        tone="success"
                        label="Paid This Month"
                        value={formatMoney(summary.paid_this_month)}
                    />
                </div>

                {/* Filters */}
                <Card className="mb-6">
                    <CardContent className="pt-6">
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-5">
                            <div className="relative">
                                <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    placeholder="Search invoice #, client..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) =>
                                        e.key === 'Enter' && applyFilters()
                                    }
                                    className="pl-9"
                                />
                            </div>
                            <Select value={status} onValueChange={setStatus}>
                                <SelectTrigger aria-label="Filter by status">
                                    <SelectValue placeholder="All Statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Statuses
                                    </SelectItem>
                                    <SelectItem value="draft">Draft</SelectItem>
                                    <SelectItem value="sent">Sent</SelectItem>
                                    <SelectItem value="viewed">
                                        Viewed
                                    </SelectItem>
                                    <SelectItem value="paid">Paid</SelectItem>
                                    <SelectItem value="overdue">
                                        Overdue
                                    </SelectItem>
                                    <SelectItem value="cancelled">
                                        Cancelled
                                    </SelectItem>
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
                                <Button
                                    onClick={applyFilters}
                                    variant="secondary"
                                    className="shrink-0"
                                >
                                    Filter
                                </Button>
                                <Button
                                    onClick={clearFilters}
                                    variant="ghost"
                                    className="shrink-0"
                                >
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
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Sent</TableHead>
                                <TableHead className="text-right">
                                    Actions
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {invoices.data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={8} className="p-0">
                                        {hasFilters ? (
                                            <EmptySearch
                                                onClear={clearFilters}
                                                title="No invoices match your filters"
                                                className="border-0"
                                            />
                                        ) : (
                                            <EmptyList
                                                icon={Receipt}
                                                itemName="invoice"
                                                title="No invoices yet"
                                                description="Create and send your first invoice to get started."
                                                className="border-0"
                                                action={
                                                    canManage ? (
                                                        <Button
                                                            size="sm"
                                                            onClick={() =>
                                                                setNewInvoiceOpen(
                                                                    true,
                                                                )
                                                            }
                                                        >
                                                            New invoice
                                                        </Button>
                                                    ) : undefined
                                                }
                                            />
                                        )}
                                    </TableCell>
                                </TableRow>
                            ) : (
                                invoices.data.map((invoice) => (
                                    <TableRow
                                        key={invoice.id}
                                        className={cn(
                                            'cursor-pointer hover:bg-muted/50',
                                            isOverdue(invoice) &&
                                                'bg-status-critical-bg hover:bg-status-critical-bg dark:hover:bg-status-critical',
                                        )}
                                        onClick={() =>
                                            router.get(
                                                `/finance/invoices/${invoice.id}`,
                                            )
                                        }
                                        onContextMenu={rowMenu.open(
                                            rowMenuItems(invoice),
                                        )}
                                    >
                                        <TableCell className="font-medium">
                                            <Link
                                                href={`/finance/invoices/${invoice.id}`}
                                                className="text-primary hover:underline"
                                            >
                                                {invoice.invoice_number}
                                            </Link>
                                        </TableCell>
                                        <TableCell>
                                            <div>{invoice.client_name}</div>
                                            {invoice.client_email && (
                                                <div className="text-xs text-muted-foreground">
                                                    {invoice.client_email}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {formatDate(invoice.invoice_date)}
                                        </TableCell>
                                        <TableCell>
                                            <span className="inline-flex items-center gap-1">
                                                {isOverdue(invoice) && (
                                                    <AlertTriangle className="h-3.5 w-3.5 text-status-critical" />
                                                )}
                                                <span
                                                    className={cn(
                                                        isOverdue(invoice) &&
                                                            'font-medium text-status-critical dark:text-status-critical',
                                                    )}
                                                >
                                                    {formatDate(
                                                        invoice.due_date,
                                                    )}
                                                </span>
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right font-medium">
                                            {formatMoney(invoice.total_amount, {
                                                currency: invoice.currency_code,
                                            })}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                status={invoice.status}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            {invoice.sent_at ? (
                                                <Send className="h-4 w-4 text-status-success" />
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    -
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {canManage &&
                                                invoice.status === 'draft' && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            setEditInvoice(
                                                                invoice,
                                                            );
                                                        }}
                                                    >
                                                        Edit
                                                    </Button>
                                                )}
                                            {canReceipt(invoice) && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        setReceiptInvoice(
                                                            invoice,
                                                        );
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
                        <div className="flex items-center justify-center gap-1 border-t p-4">
                            {invoices.links.map((link, i) => (
                                <Button
                                    key={i}
                                    variant={link.active ? 'default' : 'ghost'}
                                    size="sm"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            { preserveState: true },
                                        )
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    )}
                </Card>

                {rowMenu.element}
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

            {canManage && editInvoice && (
                <NewInvoiceDialog
                    key={editInvoice.id}
                    open
                    invoice={editInvoice}
                    onClose={() => setEditInvoice(null)}
                    clients={clients}
                    taxRates={taxRates}
                />
            )}
        </AppLayout>
    );
}
