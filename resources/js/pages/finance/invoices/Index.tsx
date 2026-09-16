import {
    FinanceSectionRail,
    formatMoney,
    NewInvoiceDialog,
    RecordReceiptDialog,
    type ClientOption,
    type InvoiceAccountOption,
    type TaxRateOption,
} from '@/components/finance';
import {
    EntityContextMenu,
    EntityStatusChip,
    EntityTable,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { PageProps, type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    CalendarRange,
    Download,
    Eye,
    Pencil,
    Plus,
    Receipt,
    Send,
    Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';

interface InvoiceLine {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    tax_rate_id: number | null;
    account_id: number | null;
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
    terms: string | null;
    email_subject: string | null;
    email_body: string | null;
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
    total: number;
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
    outstanding_count: number;
    overdue_count: number;
    draft_count: number;
    paid_this_month: number;
    paid_this_month_count: number;
}

interface Props extends PageProps {
    invoices: PaginatedInvoices;
    filters: Filters;
    summary: Summary;
    canManage: boolean;
    clients: ClientOption[];
    taxRates: TaxRateOption[];
    accounts: InvoiceAccountOption[];
}

const ALL = '__all';

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'unpaid', label: 'Unpaid (sent or overdue)' },
    { value: 'draft', label: 'Draft' },
    { value: 'sent', label: 'Sent' },
    { value: 'viewed', label: 'Viewed' },
    { value: 'overdue', label: 'Overdue' },
    { value: 'paid', label: 'Paid' },
    { value: 'cancelled', label: 'Cancelled' },
];

const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-NZ', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Receivables', href: '/finance/invoices' },
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
    accounts,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [range, setRange] = useState({
        from: filters.date_from ?? '',
        to: filters.date_to ?? '',
    });
    const [rangeOpen, setRangeOpen] = useState(false);
    const [receiptInvoice, setReceiptInvoice] = useState<Invoice | null>(null);
    const [newInvoiceOpen, setNewInvoiceOpen] = useState(false);
    const [editInvoice, setEditInvoice] = useState<Invoice | null>(null);
    const ctxMenu = useEntityContextMenu<Invoice>();

    const status = filters.status || ALL;

    const go = (patch: Partial<Filters>) => {
        const next = { ...filters, ...patch };
        const query: Record<string, string> = {};
        if (next.search) query.search = next.search;
        if (next.status && next.status !== ALL) query.status = next.status;
        if (next.date_from) query.date_from = next.date_from;
        if (next.date_to) query.date_to = next.date_to;

        router.get('/finance/invoices', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    useEffect(() => {
        setSearch(filters.search ?? '');
    }, [filters.search]);

    useEffect(() => {
        const handle = setTimeout(() => {
            if ((filters.search ?? '') !== search) {
                go({ search: search.trim() || undefined });
            }
        }, 350);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const clearFilters = () => {
        setSearch('');
        setRange({ from: '', to: '' });
        router.get('/finance/invoices', {}, { preserveState: true });
    };

    const hasFilters = Boolean(
        filters.search || filters.status || filters.date_from || filters.date_to,
    );

    const exportQuery = new URLSearchParams(
        Object.entries({
            status: filters.status ?? '',
            search: filters.search ?? '',
            date_from: filters.date_from ?? '',
            date_to: filters.date_to ?? '',
        }).filter(([, value]) => value),
    ).toString();

    const isOverdue = (invoice: Invoice) => {
        if (invoice.status === 'paid' || invoice.status === 'cancelled')
            return false;
        return new Date(invoice.due_date) < new Date();
    };

    const canReceipt = (invoice: Invoice) =>
        canManage &&
        Number(invoice.amount_due ?? 0) > 0 &&
        !['draft', 'cancelled', 'paid'].includes(invoice.status);

    const openInvoice = (invoice: Invoice) =>
        router.visit(`/finance/invoices/${invoice.id}`);

    const actionsFor = (invoice: Invoice): MenuItem[] =>
        compactMenu([
            { label: 'Open invoice', icon: Eye, onClick: () => openInvoice(invoice) },
            canManage && invoice.status === 'draft'
                ? {
                      label: 'Edit invoice',
                      icon: Pencil,
                      onClick: () => setEditInvoice(invoice),
                  }
                : null,
            canReceipt(invoice)
                ? {
                      label: 'Record receipt',
                      icon: Wallet,
                      onClick: () => setReceiptInvoice(invoice),
                  }
                : null,
        ]);

    const rangeLabel =
        filters.date_from || filters.date_to
            ? `${filters.date_from ? formatDate(filters.date_from) : 'Start'} – ${
                  filters.date_to ? formatDate(filters.date_to) : 'Today'
              }`
            : 'Any date';

    const columns: EntityTableColumn<Invoice>[] = [
        {
            key: 'invoice_date',
            label: 'Issued',
            width: '0.9fr',
            cell: (invoice) => formatDate(invoice.invoice_date),
        },
        {
            key: 'due_date',
            label: 'Due',
            width: '0.9fr',
            cell: (invoice) => (
                <span
                    className={
                        isOverdue(invoice)
                            ? 'font-semibold text-status-critical'
                            : undefined
                    }
                >
                    {formatDate(invoice.due_date)}
                </span>
            ),
        },
        {
            key: 'total',
            label: 'Total',
            width: '0.9fr',
            align: 'right',
            cell: (invoice) => (
                <span className="font-medium tabular-nums">
                    {formatMoney(invoice.total_amount, {
                        currency: invoice.currency_code,
                    })}
                </span>
            ),
        },
        {
            key: 'due_amount',
            label: 'Outstanding',
            width: '0.9fr',
            align: 'right',
            cell: (invoice) => (
                <span className="tabular-nums">
                    {formatMoney(invoice.amount_due ?? 0, {
                        currency: invoice.currency_code,
                    })}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.8fr',
            cell: (invoice) => <StatusBadge status={invoice.status} />,
        },
        {
            key: 'sent',
            label: 'Sent',
            width: '0.6fr',
            cell: (invoice) =>
                invoice.sent_at ? (
                    <EntityStatusChip variant="success" icon={Send}>
                        Sent
                    </EntityStatusChip>
                ) : (
                    <EntityStatusChip variant="neutral">
                        Not sent
                    </EntityStatusChip>
                ),
        },
    ];

    const header = (
        <PageHeader
            variant="index"
            icon={Receipt}
            title="Invoices"
            titleChip={
                summary.overdue_count > 0 ? (
                    <PageHeaderStatusChip variant="critical">
                        {summary.overdue_count} overdue
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        Nothing overdue
                    </PageHeaderStatusChip>
                )
            }
            subline={`Accounts receivable · ${summary.outstanding_count} unpaid · ${formatMoney(
                summary.total_outstanding,
            )} outstanding`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search invoices, clients…"
                    />
                    <PageHeaderGlassButton
                        icon={Download}
                        onClick={() => {
                            window.location.href = `/finance/invoices/export${
                                exportQuery ? `?${exportQuery}` : ''
                            }`;
                        }}
                    >
                        Export CSV
                    </PageHeaderGlassButton>
                    {canManage && (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setNewInvoiceOpen(true)}
                        >
                            New invoice
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Outstanding"
                        href="/finance/invoices?status=unpaid"
                        ariaLabel="View unpaid invoices"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.total_outstanding)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.outstanding_count} unpaid invoices
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Overdue"
                        tone={summary.total_overdue > 0 ? 'critical' : 'brand'}
                        href="/finance/invoices?status=overdue"
                        ariaLabel="View overdue invoices"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.total_overdue)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.overdue_count} past their due date
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Drafts"
                        href="/finance/invoices?status=draft"
                        ariaLabel="View draft invoices"
                    >
                        <PageHeaderMeterBig>
                            {summary.draft_count}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Not yet sent
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Paid this month"
                        tone="success"
                        href="/finance/invoices?status=paid"
                        ariaLabel="View paid invoices"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.paid_this_month)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.paid_this_month_count} settled since the
                            1st
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Aged AR"
                        href="/finance/receivables"
                        ariaLabel="View the aged receivables report"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(
                                Math.max(
                                    0,
                                    summary.total_outstanding -
                                        summary.total_overdue,
                                ),
                            )}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Outstanding but not yet due
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Status"
                        value={status}
                        allValue={ALL}
                        options={STATUS_OPTIONS}
                        onChange={(value) =>
                            go({ status: value === ALL ? undefined : value })
                        }
                    />
                    <Popover open={rangeOpen} onOpenChange={setRangeOpen}>
                        <PopoverTrigger asChild>
                            <PageHeaderFilterButton
                                icon={CalendarRange}
                                active={Boolean(
                                    filters.date_from || filters.date_to,
                                )}
                                aria-label="Filter by invoice date"
                            >
                                {rangeLabel}
                            </PageHeaderFilterButton>
                        </PopoverTrigger>
                        <PopoverContent align="end" className="w-64">
                            <form
                                className="flex flex-col gap-3"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    setRangeOpen(false);
                                    go({
                                        date_from: range.from || undefined,
                                        date_to: range.to || undefined,
                                    });
                                }}
                            >
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="invoice-from">From</Label>
                                    <Input
                                        id="invoice-from"
                                        type="date"
                                        value={range.from}
                                        onChange={(e) =>
                                            setRange((r) => ({
                                                ...r,
                                                from: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="invoice-to">To</Label>
                                    <Input
                                        id="invoice-to"
                                        type="date"
                                        value={range.to}
                                        onChange={(e) =>
                                            setRange((r) => ({
                                                ...r,
                                                to: e.target.value,
                                            }))
                                        }
                                    />
                                </div>
                                <div className="flex justify-between gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setRange({ from: '', to: '' });
                                            setRangeOpen(false);
                                            go({
                                                date_from: undefined,
                                                date_to: undefined,
                                            });
                                        }}
                                    >
                                        Clear
                                    </Button>
                                    <Button type="submit" size="sm">
                                        Show these dates
                                    </Button>
                                </div>
                            </form>
                        </PopoverContent>
                    </Popover>
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout user={auth.user} breadcrumbs={breadcrumbs}>
            <Head title="Invoices" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Invoices"
                        caption={`${invoices.data.length} of ${invoices.total} shown`}
                    />

                    {invoices.data.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                onClear={clearFilters}
                                title="No invoices match your filters"
                            />
                        ) : (
                            <EmptyList
                                icon={Receipt}
                                itemName="invoice"
                                title="No invoices yet"
                                description="Create and send your first invoice to get started."
                                action={
                                    canManage ? (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                setNewInvoiceOpen(true)
                                            }
                                        >
                                            New invoice
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )
                    ) : (
                        <>
                            <EntityTable
                                rows={invoices.data}
                                rowKey={(invoice) => invoice.id}
                                identityLabel="Invoice"
                                identity={(invoice) => ({
                                    icon: Receipt,
                                    name: invoice.invoice_number,
                                    subline: invoice.client_name,
                                })}
                                columns={columns}
                                actionsFor={actionsFor}
                                hrefFor={(invoice) =>
                                    `/finance/invoices/${invoice.id}`
                                }
                                onOpen={openInvoice}
                                onRowContextMenu={ctxMenu.open}
                                mutedFor={(invoice) =>
                                    invoice.status === 'cancelled'
                                }
                                minWidth={1040}
                            />
                            <LaravelPagination
                                links={invoices.links}
                                lastPage={invoices.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Receipt}
                    title={ctxMenu.ctx.record.invoice_number}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

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
                    accounts={accounts}
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
                    accounts={accounts}
                />
            )}
        </AppLayout>
    );
}
