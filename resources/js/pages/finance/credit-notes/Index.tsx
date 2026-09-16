import {
    CreditNoteDialog,
    FinanceSectionRail,
    formatMoney,
    type CreditNoteAccountOption,
    type CreditNoteClientOption,
    type CreditNoteVendorOption,
} from '@/components/finance';
import {
    EntityChip,
    EntityContextMenu,
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
    Banknote,
    CalendarRange,
    Download,
    Eye,
    FileMinus,
    Plus,
    Receipt,
} from 'lucide-react';
import { useState } from 'react';

interface CreditNote {
    id: number;
    credit_note_number: string;
    type: string;
    vendor: { id: number; name: string } | null;
    credit_date: string;
    total_amount: string;
    status: string;
}

interface PaginatedCreditNotes {
    data: CreditNote[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
}

interface Filters {
    type?: string;
    status?: string;
    search?: string;
    date_from?: string;
    date_to?: string;
}

interface Summary {
    total: number;
    payable_count: number;
    payable_total: number;
    receivable_count: number;
    receivable_total: number;
    draft_count: number;
}

interface Props extends PageProps {
    creditNotes: PaginatedCreditNotes;
    filters: Filters;
    summary: Summary;
    canManage: boolean;
    vendors: CreditNoteVendorOption[];
    clients: CreditNoteClientOption[];
    accounts: CreditNoteAccountOption[];
}

const TYPE_LABELS: Record<string, string> = {
    payable: 'Accounts payable',
    receivable: 'Accounts receivable',
};

const TYPE_OPTIONS = [
    { value: 'all', label: 'All types' },
    { value: 'payable', label: 'Accounts payable' },
    { value: 'receivable', label: 'Accounts receivable' },
];

const STATUS_OPTIONS = [
    { value: 'all', label: 'All statuses' },
    { value: 'draft', label: 'Draft' },
    { value: 'approved', label: 'Approved' },
    { value: 'applied', label: 'Applied' },
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
    { title: 'Payables', href: '/finance/payables' },
    { title: 'Credit notes', href: '/finance/credit-notes' },
];

export default function CreditNotesIndex({
    creditNotes,
    filters,
    summary,
    canManage = false,
    vendors = [],
    clients = [],
    accounts = [],
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [createOpen, setCreateOpen] = useState(false);
    const [rangeOpen, setRangeOpen] = useState(false);
    const [range, setRange] = useState({
        from: filters.date_from ?? '',
        to: filters.date_to ?? '',
    });

    const apply = (next: Filters) =>
        router.get(
            '/finance/credit-notes',
            Object.fromEntries(
                Object.entries({ ...filters, ...next }).filter(([, v]) => v),
            ),
            { preserveState: true, preserveScroll: true },
        );

    const clearFilters = () => {
        setSearch('');
        setRange({ from: '', to: '' });
        router.get('/finance/credit-notes', {}, { preserveState: true });
    };

    const hasFilters = Boolean(
        filters.search ||
            filters.type ||
            filters.status ||
            filters.date_from ||
            filters.date_to,
    );

    const rangeLabel =
        filters.date_from && filters.date_to
            ? `${formatDate(filters.date_from)} – ${formatDate(filters.date_to)}`
            : filters.date_from
              ? `From ${formatDate(filters.date_from)}`
              : filters.date_to
                ? `To ${formatDate(filters.date_to)}`
                : 'Credit date';

    const ctx = useEntityContextMenu<CreditNote>();

    const menuFor = (creditNote: CreditNote): MenuItem[] =>
        compactMenu([
            {
                label: 'Open credit note',
                icon: Eye,
                onClick: () =>
                    router.get(`/finance/credit-notes/${creditNote.id}`),
            },
        ]);

    const columns: EntityTableColumn<CreditNote>[] = [
        {
            key: 'type',
            label: 'Type',
            width: '1.4fr',
            cell: (cn) => (
                <EntityChip
                    icon={cn.type === 'payable' ? Receipt : Banknote}
                >
                    {TYPE_LABELS[cn.type] ?? cn.type}
                </EntityChip>
            ),
        },
        {
            key: 'party',
            label: 'Vendor / client',
            width: '1.6fr',
            cell: (cn) => (
                <span className="truncate">{cn.vendor?.name ?? '—'}</span>
            ),
        },
        {
            key: 'date',
            label: 'Credit date',
            width: '1.1fr',
            cell: (cn) => (
                <span className="text-muted-foreground">
                    {formatDate(cn.credit_date)}
                </span>
            ),
        },
        {
            key: 'total',
            label: 'Total',
            width: '1.1fr',
            align: 'right',
            cell: (cn) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(cn.total_amount)}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '120px',
            cell: (cn) => (
                <StatusBadge
                    status={cn.status}
                    className="rounded-[8px] font-semibold"
                />
            ),
        },
    ];

    const exportUrl = `/finance/credit-notes/export?${new URLSearchParams(
        Object.entries({
            type: filters.type ?? '',
            status: filters.status ?? '',
            search: filters.search ?? '',
            date_from: filters.date_from ?? '',
            date_to: filters.date_to ?? '',
        }).filter(([, v]) => v),
    ).toString()}`;

    const header = (
        <PageHeader
            variant="index"
            icon={FileMinus}
            title="Credit notes"
            titleChip={
                <PageHeaderStatusChip
                    variant={summary.draft_count > 0 ? 'warning' : 'success'}
                >
                    {summary.draft_count} draft
                </PageHeaderStatusChip>
            }
            subline={`Credits against bills and invoices · ${summary.total} credit notes · ${summary.payable_count} payable · ${summary.receivable_count} receivable`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') apply({ search });
                        }}
                        placeholder="Search credit notes or parties…"
                    />
                    <PageHeaderGlassButton
                        icon={Download}
                        onClick={() => {
                            window.location.href = exportUrl;
                        }}
                    >
                        Export CSV
                    </PageHeaderGlassButton>
                    {canManage && (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New credit note
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="All credit notes"
                        href="/finance/credit-notes"
                        ariaLabel="View all credit notes"
                    >
                        <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Across payables and receivables
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Payable credits"
                        href="/finance/credit-notes?type=payable"
                        ariaLabel="View accounts-payable credit notes"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.payable_total)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.payable_count} credited back by vendors
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Receivable credits"
                        href="/finance/credit-notes?type=receivable"
                        ariaLabel="View accounts-receivable credit notes"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.receivable_total)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.receivable_count} credited to clients
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Draft"
                        tone="warning"
                        href="/finance/credit-notes?status=draft"
                        ariaLabel="View draft credit notes"
                    >
                        <PageHeaderMeterBig>
                            {summary.draft_count}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Waiting to be approved
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Type"
                        value={filters.type || 'all'}
                        options={TYPE_OPTIONS}
                        onChange={(value) =>
                            apply({ type: value === 'all' ? '' : value })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status || 'all'}
                        options={STATUS_OPTIONS}
                        onChange={(value) =>
                            apply({ status: value === 'all' ? '' : value })
                        }
                    />
                    <Popover open={rangeOpen} onOpenChange={setRangeOpen}>
                        <PopoverTrigger asChild>
                            <PageHeaderFilterButton
                                icon={CalendarRange}
                                active={Boolean(
                                    filters.date_from || filters.date_to,
                                )}
                                aria-label="Filter by credit date"
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
                                    apply({
                                        date_from: range.from,
                                        date_to: range.to,
                                    });
                                }}
                            >
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="credit-notes-date-from">
                                        From
                                    </Label>
                                    <Input
                                        id="credit-notes-date-from"
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
                                    <Label htmlFor="credit-notes-date-to">
                                        To
                                    </Label>
                                    <Input
                                        id="credit-notes-date-to"
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
                                            apply({
                                                date_from: '',
                                                date_to: '',
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
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Credit notes" />

            <PageLayout hero={header}>
                <ListCaption
                    title="Credit notes"
                    caption={`${creditNotes.data.length} of ${creditNotes.total} shown`}
                />

                {creditNotes.data.length === 0 ? (
                    hasFilters ? (
                        <EmptySearch
                            onClear={clearFilters}
                            title="No credit notes match your filters"
                        />
                    ) : (
                        <EmptyList
                            icon={FileMinus}
                            itemName="credit note"
                            title="No credit notes yet"
                            description="Credit notes adjust a bill or an invoice. Create one to get started."
                            action={
                                canManage ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setCreateOpen(true)}
                                    >
                                        New credit note
                                    </Button>
                                ) : undefined
                            }
                        />
                    )
                ) : (
                    <>
                        <EntityTable
                            rows={creditNotes.data}
                            rowKey={(cn) => cn.id}
                            identityLabel="Credit note"
                            identity={(cn) => ({
                                icon: FileMinus,
                                name: cn.credit_note_number,
                                subline: cn.vendor?.name ?? undefined,
                            })}
                            columns={columns}
                            actionsFor={menuFor}
                            hrefFor={(cn) => `/finance/credit-notes/${cn.id}`}
                            onOpen={(cn) =>
                                router.get(`/finance/credit-notes/${cn.id}`)
                            }
                            onRowContextMenu={ctx.open}
                            mutedFor={(cn) => cn.status === 'cancelled'}
                            minWidth={1080}
                        />
                        <LaravelPagination
                            links={creditNotes.links}
                            lastPage={creditNotes.last_page}
                        />
                    </>
                )}
            </PageLayout>

            {ctx.ctx && (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={FileMinus}
                    title={ctx.ctx.record.credit_note_number}
                    items={menuFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            )}

            {canManage && (
                <CreditNoteDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    vendors={vendors}
                    clients={clients}
                    accounts={accounts}
                />
            )}
        </AppLayout>
    );
}
