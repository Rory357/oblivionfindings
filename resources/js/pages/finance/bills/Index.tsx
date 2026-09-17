import {
    FinanceSectionRail,
    NewBillDialog,
    formatMoney,
    type AccountOption,
    type SpendApprovalOption,
} from '@/components/finance';
import type {
    BillAttributionOption,
    BillPurchaseOrderOption,
    EditableBill,
} from '@/components/finance/new-bill-dialog';
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
    AlertTriangle,
    Building2,
    CalendarRange,
    Download,
    Eye,
    Pencil,
    Plus,
    Receipt,
} from 'lucide-react';
import { useState } from 'react';

interface Vendor {
    id: number;
    name: string;
}

interface BillLine {
    id: number;
    description: string;
    quantity: string;
    unit_price: string;
    gst_rate: string;
    account_id: number | null;
    cost_centre_id: number | null;
    funding_stream_id: number | null;
}

interface Bill {
    id: number;
    bill_number: string;
    vendor_id: number;
    vendor_reference: string | null;
    vendor: Vendor | null;
    bill_date: string;
    due_date: string;
    total_amount: string;
    amount_paid: string;
    status: string;
    notes: string | null;
    spend_approval_id: number | null;
    purchase_order_id: number | null;
    lines: BillLine[];
}

interface PaginatedBills {
    data: Bill[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
}

interface Filters {
    status?: string;
    vendor_id?: string;
    search?: string;
    date_from?: string;
    date_to?: string;
}

interface Summary {
    total_unpaid: number;
    unpaid_count: number;
    total_overdue: number;
    overdue_count: number;
    due_this_week: number;
    due_this_week_count: number;
    awaiting_total: number;
    awaiting_count: number;
}

interface Props extends PageProps {
    bills: PaginatedBills;
    vendors: Vendor[];
    filters: Filters;
    summary: Summary;
    canManage: boolean;
    accounts: AccountOption[];
    spendApprovals: SpendApprovalOption[];
    purchaseOrders: BillPurchaseOrderOption[];
    costCentres: BillAttributionOption[];
    fundingStreams: BillAttributionOption[];
}

const STATUS_OPTIONS = [
    { value: 'all', label: 'All statuses' },
    // The two grouped options the meters link to, so arriving from a meter
    // shows the filter that produced the number.
    { value: 'unpaid', label: 'Unpaid (approved and owing)' },
    { value: 'awaiting', label: 'Awaiting approval or draft' },
    { value: 'draft', label: 'Draft' },
    { value: 'awaiting_approval', label: 'Awaiting approval' },
    { value: 'approved', label: 'Approved' },
    { value: 'partially_paid', label: 'Partially paid' },
    { value: 'paid', label: 'Paid' },
    { value: 'cancelled', label: 'Cancelled' },
];

const STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    awaiting_approval: 'Awaiting approval',
    approved: 'Approved',
    partially_paid: 'Partially paid',
    paid: 'Paid',
    cancelled: 'Cancelled',
};

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
    { title: 'Bills', href: '/finance/bills' },
];

export default function BillsIndex({
    bills,
    vendors,
    filters,
    summary,
    canManage,
    accounts,
    spendApprovals,
    purchaseOrders,
    costCentres,
    fundingStreams,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [newBillOpen, setNewBillOpen] = useState(false);
    const [editBill, setEditBill] = useState<Bill | null>(null);
    const [rangeOpen, setRangeOpen] = useState(false);
    const [range, setRange] = useState({
        from: filters.date_from ?? '',
        to: filters.date_to ?? '',
    });

    const rangeLabel =
        filters.date_from && filters.date_to
            ? `${formatDate(filters.date_from)} – ${formatDate(filters.date_to)}`
            : filters.date_from
              ? `From ${formatDate(filters.date_from)}`
              : filters.date_to
                ? `To ${formatDate(filters.date_to)}`
                : 'Bill date';

    const apply = (next: Filters) =>
        router.get(
            '/finance/bills',
            Object.fromEntries(
                Object.entries({ ...filters, ...next }).filter(([, v]) => v),
            ),
            { preserveState: true, preserveScroll: true },
        );

    const clearFilters = () => {
        setSearch('');
        router.get('/finance/bills', {}, { preserveState: true });
    };

    const hasFilters = Boolean(
        filters.search ||
            filters.status ||
            filters.vendor_id ||
            filters.date_from ||
            filters.date_to,
    );

    const isOverdue = (bill: Bill) =>
        bill.status !== 'paid' &&
        bill.status !== 'cancelled' &&
        new Date(bill.due_date) < new Date();

    const ctx = useEntityContextMenu<Bill>();

    const menuFor = (bill: Bill): MenuItem[] =>
        compactMenu([
            {
                label: 'Open bill',
                icon: Eye,
                onClick: () => router.get(`/finance/bills/${bill.id}`),
            },
            canManage && bill.status === 'draft'
                ? {
                      label: 'Edit bill',
                      icon: Pencil,
                      onClick: () => setEditBill(bill),
                  }
                : false,
            bill.vendor
                ? {
                      label: 'Open vendor',
                      icon: Building2,
                      onClick: () =>
                          router.get(`/finance/vendors/${bill.vendor?.id}`),
                  }
                : false,
        ]);

    const columns: EntityTableColumn<Bill>[] = [
        {
            key: 'vendor',
            label: 'Vendor',
            width: '1.5fr',
            cell: (b) => (
                <span className="truncate">{b.vendor?.name ?? '—'}</span>
            ),
        },
        {
            key: 'bill_date',
            label: 'Bill date',
            width: '1.1fr',
            cell: (b) => (
                <span className="text-muted-foreground">
                    {formatDate(b.bill_date)}
                </span>
            ),
        },
        {
            key: 'due_date',
            label: 'Due',
            width: '1.3fr',
            cell: (b) =>
                isOverdue(b) ? (
                    <EntityStatusChip variant="critical" icon={AlertTriangle}>
                        {formatDate(b.due_date)}
                    </EntityStatusChip>
                ) : (
                    <span className="text-muted-foreground">
                        {formatDate(b.due_date)}
                    </span>
                ),
        },
        {
            key: 'total',
            label: 'Total',
            width: '1.1fr',
            align: 'right',
            cell: (b) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(b.total_amount)}
                </span>
            ),
        },
        {
            key: 'paid',
            label: 'Paid',
            width: '1.1fr',
            align: 'right',
            cell: (b) => (
                <span className="tabular-nums text-muted-foreground">
                    {formatMoney(b.amount_paid)}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '160px',
            cell: (b) => (
                <StatusBadge
                    status={b.status}
                    label={STATUS_LABELS[b.status]}
                    className="rounded-[8px] font-semibold"
                />
            ),
        },
    ];

    const exportUrl = `/finance/bills/export?${new URLSearchParams(
        Object.entries({
            status: filters.status ?? '',
            vendor_id: filters.vendor_id ?? '',
            search: filters.search ?? '',
            date_from: filters.date_from ?? '',
            date_to: filters.date_to ?? '',
        }).filter(([, v]) => v),
    ).toString()}`;

    const header = (
        <PageHeader
            variant="index"
            icon={Receipt}
            title="Bills"
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
            subline={`Accounts payable · ${bills.total} bills · ${formatMoney(summary.total_unpaid)} unpaid`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') apply({ search });
                        }}
                        placeholder="Search bill number or vendor reference…"
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
                            onClick={() => setNewBillOpen(true)}
                        >
                            New bill
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Unpaid"
                        href="/finance/bills?status=unpaid"
                        ariaLabel="View unpaid bills"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.total_unpaid)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.unpaid_count} bill
                            {summary.unpaid_count === 1 ? '' : 's'} approved and
                            owing
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Overdue"
                        tone="critical"
                        href="/finance/bills?due=overdue"
                        ariaLabel="View overdue bills"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.total_overdue)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.overdue_count} past their due date
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Due this week"
                        tone="warning"
                        href="/finance/bills?due=week"
                        ariaLabel="View bills due this week"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.due_this_week)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.due_this_week_count} due in the next 7 days
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Awaiting approval"
                        tone="warning"
                        href="/finance/bills?status=awaiting"
                        ariaLabel="View bills awaiting approval"
                    >
                        <PageHeaderMeterBig>
                            {summary.awaiting_count}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {formatMoney(summary.awaiting_total)} in drafts and
                            approvals
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status || 'all'}
                        options={STATUS_OPTIONS}
                        onChange={(value) =>
                            apply({ status: value === 'all' ? '' : value })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Vendor"
                        value={filters.vendor_id || 'all'}
                        options={[
                            { value: 'all', label: 'All vendors' },
                            ...vendors.map((v) => ({
                                value: String(v.id),
                                label: v.name,
                            })),
                        ]}
                        onChange={(value) =>
                            apply({ vendor_id: value === 'all' ? '' : value })
                        }
                    />
                    <Popover open={rangeOpen} onOpenChange={setRangeOpen}>
                        <PopoverTrigger asChild>
                            <PageHeaderFilterButton
                                icon={CalendarRange}
                                active={Boolean(
                                    filters.date_from || filters.date_to,
                                )}
                                aria-label="Filter by bill date"
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
                                    <Label htmlFor="bills-date-from">
                                        From
                                    </Label>
                                    <Input
                                        id="bills-date-from"
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
                                    <Label htmlFor="bills-date-to">To</Label>
                                    <Input
                                        id="bills-date-to"
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
            <Head title="Bills" />

            <PageLayout hero={header}>
                <ListCaption
                    title="Bills"
                    caption={`${bills.data.length} of ${bills.total} shown`}
                />

                {bills.data.length === 0 ? (
                    hasFilters ? (
                        <EmptySearch
                            onClear={clearFilters}
                            title="No bills match your filters"
                        />
                    ) : (
                        <EmptyList
                            icon={Receipt}
                            itemName="bill"
                            title="No bills yet"
                            description="Record your first supplier bill to get started."
                            action={
                                canManage ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setNewBillOpen(true)}
                                    >
                                        New bill
                                    </Button>
                                ) : undefined
                            }
                        />
                    )
                ) : (
                    <>
                        <EntityTable
                            rows={bills.data}
                            rowKey={(b) => b.id}
                            identityLabel="Bill"
                            identity={(b) => ({
                                icon: Receipt,
                                name: b.bill_number,
                                subline: b.vendor_reference
                                    ? `Ref ${b.vendor_reference}`
                                    : undefined,
                            })}
                            columns={columns}
                            actionsFor={menuFor}
                            hrefFor={(b) => `/finance/bills/${b.id}`}
                            onOpen={(b) => router.get(`/finance/bills/${b.id}`)}
                            onRowContextMenu={ctx.open}
                            mutedFor={(b) => b.status === 'cancelled'}
                            minWidth={1180}
                        />
                        <LaravelPagination
                            links={bills.links}
                            lastPage={bills.last_page}
                        />
                    </>
                )}
            </PageLayout>

            {ctx.ctx && (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={Receipt}
                    title={ctx.ctx.record.bill_number}
                    items={menuFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            )}

            {canManage && (
                <NewBillDialog
                    open={newBillOpen}
                    onClose={() => setNewBillOpen(false)}
                    vendors={vendors}
                    accounts={accounts}
                    spendApprovals={spendApprovals}
                    purchaseOrders={purchaseOrders}
                    costCentres={costCentres}
                    fundingStreams={fundingStreams}
                />
            )}

            {canManage && editBill && (
                <NewBillDialog
                    key={editBill.id}
                    open
                    bill={editBill as unknown as EditableBill}
                    onClose={() => setEditBill(null)}
                    vendors={vendors}
                    accounts={accounts}
                    spendApprovals={spendApprovals}
                    purchaseOrders={purchaseOrders}
                    costCentres={costCentres}
                    fundingStreams={fundingStreams}
                />
            )}
        </AppLayout>
    );
}
