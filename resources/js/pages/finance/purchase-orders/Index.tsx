import {
    FinanceSectionRail,
    NewPoDialog,
    formatMoney,
    type AccountOption,
} from '@/components/finance';
import type { PoAttributionOption } from '@/components/finance/new-po-dialog';
import {
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
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Building2,
    Download,
    Eye,
    Plus,
    Receipt,
    ShoppingCart,
} from 'lucide-react';
import { useState } from 'react';

type Vendor = { id: number; name: string };

type PurchaseOrder = {
    id: number;
    po_number: string;
    vendor?: Vendor | null;
    order_date: string;
    expected_date: string | null;
    total_amount: string;
    status: string;
};

type Paginated<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
};

type Summary = {
    total: number;
    draft: number;
    approved: number;
    open_value: number;
};

type PoIndexProps = {
    purchaseOrders: Paginated<PurchaseOrder>;
    vendors: Vendor[];
    filters: { status: string; vendor_id: string; search: string };
    summary: Summary;
    canManage: boolean;
    accounts: AccountOption[];
    costCentres: PoAttributionOption[];
    fundingStreams: PoAttributionOption[];
};

const STATUS_OPTIONS = [
    { value: 'all', label: 'All statuses' },
    { value: 'draft', label: 'Draft' },
    { value: 'approved', label: 'Approved' },
    { value: 'sent', label: 'Sent' },
    { value: 'partially_received', label: 'Partially received' },
    { value: 'received', label: 'Received' },
    { value: 'cancelled', label: 'Cancelled' },
];

const STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    approved: 'Approved',
    sent: 'Sent',
    partially_received: 'Partially received',
    received: 'Received',
    cancelled: 'Cancelled',
};

const formatDate = (date: string | null) =>
    date
        ? new Date(date).toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
          })
        : '—';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Payables', href: '/finance/payables' },
    { title: 'Purchase orders', href: '/finance/purchase-orders' },
];

export default function PurchaseOrderIndex() {
    const {
        purchaseOrders,
        vendors,
        filters,
        summary,
        canManage,
        accounts,
        costCentres,
        fundingStreams,
    } = usePage().props as unknown as PoIndexProps;

    const [newPoOpen, setNewPoOpen] = useState(false);
    const [search, setSearch] = useState(filters?.search ?? '');

    const current = {
        status: filters?.status ?? '',
        vendor_id: filters?.vendor_id ?? '',
        search: filters?.search ?? '',
    };

    const apply = (next: Record<string, string>) =>
        router.get(
            '/finance/purchase-orders',
            { ...current, ...next },
            { preserveState: true, preserveScroll: true },
        );

    const clearFilters = () => {
        setSearch('');
        router.get(
            '/finance/purchase-orders',
            {},
            { preserveState: true, preserveScroll: true },
        );
    };

    const hasFilters = Boolean(
        current.search || current.status || current.vendor_id,
    );

    const ctx = useEntityContextMenu<PurchaseOrder>();

    const menuFor = (po: PurchaseOrder): MenuItem[] =>
        compactMenu([
            {
                label: 'Open purchase order',
                icon: Eye,
                onClick: () =>
                    router.get(`/finance/purchase-orders/${po.id}`),
            },
            po.vendor
                ? {
                      label: 'Open vendor',
                      icon: Building2,
                      onClick: () =>
                          router.get(`/finance/vendors/${po.vendor?.id}`),
                  }
                : false,
        ]);

    const columns: EntityTableColumn<PurchaseOrder>[] = [
        {
            key: 'vendor',
            label: 'Vendor',
            width: '1.6fr',
            cell: (po) => (
                <span className="truncate">{po.vendor?.name ?? '—'}</span>
            ),
        },
        {
            key: 'order_date',
            label: 'Ordered',
            width: '1.1fr',
            cell: (po) => (
                <span className="text-muted-foreground">
                    {formatDate(po.order_date)}
                </span>
            ),
        },
        {
            key: 'expected_date',
            label: 'Expected',
            width: '1.1fr',
            cell: (po) => (
                <span className="text-muted-foreground">
                    {formatDate(po.expected_date)}
                </span>
            ),
        },
        {
            key: 'total',
            label: 'Total',
            width: '1.1fr',
            align: 'right',
            cell: (po) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(po.total_amount)}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '150px',
            cell: (po) => (
                <StatusBadge
                    status={po.status}
                    label={STATUS_LABELS[po.status]}
                    className="rounded-[8px] font-semibold"
                />
            ),
        },
    ];

    const exportUrl = `/finance/purchase-orders/export?${new URLSearchParams(
        Object.entries({
            status: current.status,
            vendor_id: current.vendor_id,
            search: current.search,
        }).filter(([, v]) => v),
    ).toString()}`;

    const header = (
        <PageHeader
            variant="index"
            icon={ShoppingCart}
            title="Purchase orders"
            titleChip={
                <PageHeaderStatusChip variant="warning">
                    {summary.draft} draft
                </PageHeaderStatusChip>
            }
            subline={`Accounts payable · ${summary.total} purchase orders · ${formatMoney(summary.open_value)} still open`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') apply({ search });
                        }}
                        placeholder="Search purchase orders by number…"
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
                            onClick={() => setNewPoOpen(true)}
                        >
                            New purchase order
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="All purchase orders"
                        href="/finance/purchase-orders"
                        ariaLabel="View all purchase orders"
                    >
                        <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Raised across every vendor
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Draft"
                        tone="warning"
                        href="/finance/purchase-orders?status=draft"
                        ariaLabel="View draft purchase orders"
                    >
                        <PageHeaderMeterBig>{summary.draft}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Waiting to be approved
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Approved"
                        tone="success"
                        href="/finance/purchase-orders?status=approved"
                        ariaLabel="View approved purchase orders"
                    >
                        <PageHeaderMeterBig>
                            {summary.approved}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Ready to receive or bill
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Open value"
                        href="/finance/purchase-orders?status=approved"
                        ariaLabel="View open purchase-order value"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.open_value)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Not yet fully received
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Status"
                        value={current.status || 'all'}
                        options={STATUS_OPTIONS}
                        onChange={(value) =>
                            apply({ status: value === 'all' ? '' : value })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Vendor"
                        value={
                            current.vendor_id ? String(current.vendor_id) : 'all'
                        }
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
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Purchase orders" />

            <PageLayout hero={header}>
                <ListCaption
                    title="Purchase orders"
                    caption={`${purchaseOrders.data.length} of ${purchaseOrders.total} shown`}
                />

                {purchaseOrders.data.length === 0 ? (
                    hasFilters ? (
                        <EmptySearch
                            onClear={clearFilters}
                            title="No purchase orders match your filters"
                        />
                    ) : (
                        <EmptyList
                            icon={ShoppingCart}
                            itemName="purchase order"
                            title="No purchase orders yet"
                            description="Create your first purchase order to get started."
                            action={
                                canManage ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setNewPoOpen(true)}
                                    >
                                        New purchase order
                                    </Button>
                                ) : undefined
                            }
                        />
                    )
                ) : (
                    <>
                        <EntityTable
                            rows={purchaseOrders.data}
                            rowKey={(po) => po.id}
                            identityLabel="PO number"
                            identity={(po) => ({
                                icon: Receipt,
                                name: po.po_number,
                                subline: po.vendor?.name ?? undefined,
                            })}
                            columns={columns}
                            actionsFor={menuFor}
                            hrefFor={(po) =>
                                `/finance/purchase-orders/${po.id}`
                            }
                            onOpen={(po) =>
                                router.get(`/finance/purchase-orders/${po.id}`)
                            }
                            onRowContextMenu={ctx.open}
                            mutedFor={(po) => po.status === 'cancelled'}
                            minWidth={1040}
                        />
                        <LaravelPagination
                            links={purchaseOrders.links}
                            lastPage={purchaseOrders.last_page}
                        />
                    </>
                )}
            </PageLayout>

            {ctx.ctx && (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={Receipt}
                    title={ctx.ctx.record.po_number}
                    items={menuFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            )}

            {canManage && (
                <NewPoDialog
                    open={newPoOpen}
                    onClose={() => setNewPoOpen(false)}
                    vendors={vendors}
                    accounts={accounts}
                    costCentres={costCentres}
                    fundingStreams={fundingStreams}
                />
            )}
        </AppLayout>
    );
}
