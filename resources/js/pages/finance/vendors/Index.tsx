import {
    FinanceSectionRail,
    NewVendorDialog,
    type AccountOption,
} from '@/components/finance';
import {
    CounterPill,
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
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
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
import { type BreadcrumbItem, type PageProps } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Building2, Download, Eye, Plus, Receipt } from 'lucide-react';
import { useCallback, useState } from 'react';

interface Vendor {
    id: number;
    name: string;
    trading_name: string | null;
    vendor_type: string;
    email: string | null;
    phone: string | null;
    is_active: boolean;
    bills_count: number;
}

interface PaginatedVendors {
    data: Vendor[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Filters {
    search: string;
    vendor_type: string;
    is_active: string;
}

interface Summary {
    total: number;
    active: number;
    inactive: number;
    suppliers: number;
    contractors: number;
}

interface Props extends PageProps {
    vendors: PaginatedVendors;
    filters: Filters;
    summary: Summary;
    canManage: boolean;
    expenseAccounts: AccountOption[];
}

const VENDOR_TYPE_LABELS: Record<string, string> = {
    supplier: 'Supplier',
    contractor: 'Contractor',
    utility: 'Utility',
    government: 'Government',
    other: 'Other',
};

const TYPE_OPTIONS = [
    { value: 'all', label: 'All types' },
    ...Object.entries(VENDOR_TYPE_LABELS).map(([value, label]) => ({
        value,
        label,
    })),
];

const ACTIVE_OPTIONS = [
    { value: 'all', label: 'Active & inactive' },
    { value: '1', label: 'Active only' },
    { value: '0', label: 'Inactive only' },
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Payables', href: '/finance/payables' },
    { title: 'Vendors', href: '/finance/vendors' },
];

export default function VendorsIndex({
    vendors,
    filters,
    summary,
    canManage,
    expenseAccounts,
}: Props) {
    const [search, setSearch] = useState(filters.search);
    const [newVendorOpen, setNewVendorOpen] = useState(false);

    const applyFilters = useCallback(
        (newFilters: Partial<Filters>) => {
            router.get(
                '/finance/vendors',
                { ...filters, ...newFilters, page: 1 },
                { preserveState: true, preserveScroll: true },
            );
        },
        [filters],
    );

    const handleSearchKeyDown = useCallback(
        (e: React.KeyboardEvent) => {
            if (e.key === 'Enter') applyFilters({ search });
        },
        [search, applyFilters],
    );

    const clearFilters = useCallback(() => {
        setSearch('');
        router.get(
            '/finance/vendors',
            {},
            { preserveState: true, preserveScroll: true },
        );
    }, []);

    const hasFilters = Boolean(
        filters.search || filters.vendor_type || filters.is_active,
    );

    const ctx = useEntityContextMenu<Vendor>();

    // ONE MenuItem[] feeds both the kebab and the right-click menu.
    const menuFor = (vendor: Vendor): MenuItem[] =>
        compactMenu([
            {
                label: 'Open vendor',
                icon: Eye,
                onClick: () => router.get(`/finance/vendors/${vendor.id}`),
            },
            {
                label: 'View bills',
                icon: Receipt,
                onClick: () =>
                    router.get('/finance/bills', { vendor_id: vendor.id }),
            },
        ]);

    const columns: EntityTableColumn<Vendor>[] = [
        {
            key: 'type',
            label: 'Type',
            width: '1fr',
            cell: (v) => (
                <EntityChip>
                    {VENDOR_TYPE_LABELS[v.vendor_type] ?? v.vendor_type}
                </EntityChip>
            ),
        },
        {
            key: 'email',
            label: 'Email',
            width: '1.6fr',
            cell: (v) => (
                <span className="truncate text-muted-foreground">
                    {v.email || '—'}
                </span>
            ),
        },
        {
            key: 'phone',
            label: 'Phone',
            width: '1.2fr',
            cell: (v) => (
                <span className="truncate text-muted-foreground">
                    {v.phone || '—'}
                </span>
            ),
        },
        {
            key: 'bills',
            label: 'Bills',
            width: '80px',
            align: 'center',
            cell: (v) => <CounterPill tone="neutral">{v.bills_count}</CounterPill>,
        },
        {
            key: 'status',
            label: 'Status',
            width: '110px',
            cell: (v) => (
                <StatusBadge
                    status={v.is_active ? 'active' : 'inactive'}
                    label={v.is_active ? 'Active' : 'Inactive'}
                    className="rounded-[8px] font-semibold"
                />
            ),
        },
    ];

    const exportUrl = `/finance/vendors/export?${new URLSearchParams(
        Object.entries({
            search: filters.search,
            vendor_type: filters.vendor_type,
            is_active: filters.is_active,
        }).filter(([, v]) => v),
    ).toString()}`;

    const pct = (n: number) =>
        summary.total > 0 ? (n / summary.total) * 100 : 0;

    const header = (
        <PageHeader
            variant="index"
            icon={Building2}
            title="Vendors"
            titleChip={
                <PageHeaderStatusChip variant="success">
                    {summary.active} active
                </PageHeaderStatusChip>
            }
            subline={`Accounts payable · ${summary.total} vendors · ${summary.suppliers} suppliers · ${summary.contractors} contractors`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        onKeyDown={handleSearchKeyDown}
                        placeholder="Search vendors by name or email…"
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
                            onClick={() => setNewVendorOpen(true)}
                        >
                            New vendor
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="All vendors"
                        href="/finance/vendors"
                        ariaLabel="View all vendors"
                    >
                        <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Suppliers, contractors and services
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Active"
                        tone="success"
                        href="/finance/vendors?is_active=1"
                        ariaLabel="View active vendors"
                    >
                        <PageHeaderMeterDonut
                            percent={pct(summary.active)}
                            caption={`${summary.active} active · ${summary.inactive} inactive`}
                        />
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Suppliers"
                        href="/finance/vendors?vendor_type=supplier"
                        ariaLabel="View suppliers"
                    >
                        <PageHeaderMeterBig>
                            {summary.suppliers}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Goods and services accounts
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Contractors"
                        href="/finance/vendors?vendor_type=contractor"
                        ariaLabel="View contractors"
                    >
                        <PageHeaderMeterBig>
                            {summary.contractors}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Engaged on contract
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Type"
                        value={filters.vendor_type || 'all'}
                        options={TYPE_OPTIONS}
                        onChange={(value) =>
                            applyFilters({
                                vendor_type: value === 'all' ? '' : value,
                            })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.is_active || 'all'}
                        options={ACTIVE_OPTIONS}
                        onChange={(value) =>
                            applyFilters({
                                is_active: value === 'all' ? '' : value,
                            })
                        }
                    />
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Vendors" />

            <PageLayout hero={header}>
                <ListCaption
                    title="Vendors"
                    caption={`${vendors.data.length} of ${vendors.total} shown`}
                />

                {vendors.data.length === 0 ? (
                    hasFilters ? (
                        <EmptySearch
                            onClear={clearFilters}
                            title="No vendors match your filters"
                        />
                    ) : (
                        <EmptyList
                            icon={Building2}
                            itemName="vendor"
                            title="No vendors yet"
                            description="Add your first supplier or contractor to get started."
                            action={
                                canManage ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setNewVendorOpen(true)}
                                    >
                                        New vendor
                                    </Button>
                                ) : undefined
                            }
                        />
                    )
                ) : (
                    <>
                        <EntityTable
                            rows={vendors.data}
                            rowKey={(v) => v.id}
                            identityLabel="Vendor"
                            identity={(v) => ({
                                icon: Building2,
                                name: v.name,
                                subline: v.trading_name
                                    ? `Trading as ${v.trading_name}`
                                    : undefined,
                            })}
                            columns={columns}
                            actionsFor={menuFor}
                            hrefFor={(v) => `/finance/vendors/${v.id}`}
                            onOpen={(v) =>
                                router.get(`/finance/vendors/${v.id}`)
                            }
                            onRowContextMenu={ctx.open}
                            mutedFor={(v) => !v.is_active}
                            minWidth={1000}
                        />
                        <LaravelPagination
                            links={vendors.links}
                            lastPage={vendors.last_page}
                        />
                    </>
                )}
            </PageLayout>

            {ctx.ctx && (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={Building2}
                    title={ctx.ctx.record.name}
                    items={menuFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            )}

            {canManage && (
                <NewVendorDialog
                    open={newVendorOpen}
                    onClose={() => setNewVendorOpen(false)}
                    expenseAccounts={expenseAccounts}
                />
            )}
        </AppLayout>
    );
}
