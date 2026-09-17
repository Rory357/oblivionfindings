import {
    FinanceSectionRail,
    FixedAssetDialog,
    FixedAssetDisposeDialog,
    formatMoney,
    type DisposableGlAccount,
    type EditableFixedAsset,
    type FixedAssetGlAccount,
} from '@/components/finance';
import {
    EmptyValue,
    EntityChip,
    EntityContextMenu,
    EntityTable,
    type EntityTableColumn,
    ListCaption,
    type MenuItem,
    useEntityContextMenu,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly } from '@/lib/datetime';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import {
    Calculator,
    Download,
    Eye,
    Package,
    PackageMinus,
    Pencil,
    Plus,
} from 'lucide-react';
import { useCallback, useState, type FormEvent } from 'react';

interface FixedAsset {
    id: number;
    asset_name: string;
    asset_tag: string | null;
    category: string;
    purchase_date: string;
    purchase_cost: string;
    accumulated_depreciation: string;
    residual_value: string;
    useful_life_months: number;
    depreciation_method: string;
    status: string;
    gl_asset_account_id: number | null;
    gl_depreciation_account_id: number | null;
    gl_expense_account_id: number | null;
    gl_asset_account: DisposableGlAccount;
    gl_depreciation_account: DisposableGlAccount;
    notes: string | null;
    has_depreciations: boolean;
}

interface PaginatedAssets {
    data: FixedAsset[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Summary {
    total_count: number;
    total_cost: number;
    total_depreciation: number;
    net_book_value: number;
    active_count: number;
}

interface Filters {
    category: string;
    status: string;
    search: string;
}

interface Props {
    assets: PaginatedAssets;
    summary: Summary;
    filters: Filters;
    canManage: boolean;
    assetAccounts: FixedAssetGlAccount[];
    expenseAccounts: FixedAssetGlAccount[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'General ledger', href: '/finance/ledger' },
    { title: 'Fixed assets', href: '/finance/fixed-assets' },
];

const CATEGORY_OPTIONS = [
    { value: 'all', label: 'All categories' },
    { value: 'vehicle', label: 'Vehicle' },
    { value: 'equipment', label: 'Equipment' },
    { value: 'building', label: 'Building' },
    { value: 'furniture', label: 'Furniture' },
    { value: 'it_equipment', label: 'IT equipment' },
    { value: 'land', label: 'Land' },
];

const STATUS_OPTIONS = [
    { value: 'all', label: 'All statuses' },
    { value: 'active', label: 'Active' },
    { value: 'fully_depreciated', label: 'Fully depreciated' },
    { value: 'disposed', label: 'Disposed' },
];

const categoryLabel = (value: string) =>
    CATEGORY_OPTIONS.find((o) => o.value === value)?.label ?? value;

const assetDate = (value: string) => formatDateOnly(value.slice(0, 10), value);

export default function FixedAssetsIndex({
    assets,
    summary,
    filters,
    canManage = false,
    assetAccounts = [],
    expenseAccounts = [],
}: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [depModalOpen, setDepModalOpen] = useState(false);
    const [createOpen, setCreateOpen] = useState(false);
    const [editAsset, setEditAsset] = useState<EditableFixedAsset | null>(null);
    const [disposeAsset, setDisposeAsset] = useState<FixedAsset | null>(null);

    const ctx = useEntityContextMenu<FixedAsset>();

    const openEdit = (asset: FixedAsset) =>
        setEditAsset({
            id: asset.id,
            asset_name: asset.asset_name,
            asset_tag: asset.asset_tag,
            category: asset.category,
            purchase_date: asset.purchase_date,
            purchase_cost: asset.purchase_cost,
            residual_value: asset.residual_value,
            useful_life_months: asset.useful_life_months,
            depreciation_method: asset.depreciation_method,
            gl_asset_account_id: asset.gl_asset_account_id,
            gl_depreciation_account_id: asset.gl_depreciation_account_id,
            gl_expense_account_id: asset.gl_expense_account_id,
            notes: asset.notes,
            has_depreciations: asset.has_depreciations,
        });

    const depForm = useForm({
        depreciation_date: new Date().toISOString().split('T')[0],
    });

    const applyFilters = useCallback(
        (newFilters: Partial<Filters>) => {
            router.get(
                '/finance/fixed-assets',
                { ...filters, ...newFilters, page: 1 },
                { preserveState: true, preserveScroll: true },
            );
        },
        [filters],
    );

    const handleRunDepreciation = (e: FormEvent) => {
        e.preventDefault();
        depForm.post('/finance/fixed-assets/run-depreciation', {
            onSuccess: () => setDepModalOpen(false),
        });
    };

    const hasFilters = Boolean(
        filters.search || filters.category || filters.status,
    );

    const exportUrl = `/finance/fixed-assets/export?${new URLSearchParams(
        Object.entries({
            category: filters.category ?? '',
            status: filters.status ?? '',
            search: filters.search ?? '',
        }).filter(([, v]) => v) as [string, string][],
    ).toString()}`;

    const depreciatedPercent =
        summary.total_cost > 0
            ? (summary.total_depreciation / summary.total_cost) * 100
            : 0;

    /* ---------------- Row actions ---------------- */

    const actionsFor = (asset: FixedAsset): MenuItem[] => {
        const items: MenuItem[] = [
            {
                label: 'Open asset',
                icon: Eye,
                onClick: () => router.visit(`/finance/fixed-assets/${asset.id}`),
            },
        ];
        if (canManage && asset.status !== 'disposed') {
            items.push({
                label: 'Edit asset',
                icon: Pencil,
                onClick: () => openEdit(asset),
            });
            items.push({
                label: 'Dispose asset',
                icon: PackageMinus,
                danger: true,
                onClick: () => setDisposeAsset(asset),
            });
        }
        return items;
    };

    /* ---------------- Columns ---------------- */

    const columns: EntityTableColumn<FixedAsset>[] = [
        {
            key: 'category',
            label: 'Category',
            width: '160px',
            cell: (asset) => (
                <EntityChip>{categoryLabel(asset.category)}</EntityChip>
            ),
        },
        {
            key: 'purchase_date',
            label: 'Purchased',
            width: '140px',
            cell: (asset) =>
                asset.purchase_date ? (
                    <span className="whitespace-nowrap text-muted-foreground">
                        {assetDate(asset.purchase_date)}
                    </span>
                ) : (
                    <EmptyValue />
                ),
        },
        {
            key: 'cost',
            label: 'Cost',
            width: '140px',
            align: 'right',
            cell: (asset) => (
                <span className="tabular-nums">
                    {formatMoney(asset.purchase_cost)}
                </span>
            ),
        },
        {
            key: 'depreciation',
            label: 'Accum. depr.',
            width: '150px',
            align: 'right',
            cell: (asset) => (
                <span className="tabular-nums">
                    {formatMoney(asset.accumulated_depreciation)}
                </span>
            ),
        },
        {
            key: 'book_value',
            label: 'Book value',
            width: '150px',
            align: 'right',
            cell: (asset) => (
                <span className="font-semibold tabular-nums">
                    {formatMoney(
                        Number(asset.purchase_cost) -
                            Number(asset.accumulated_depreciation),
                    )}
                </span>
            ),
        },
        {
            key: 'status',
            label: 'Status',
            width: '150px',
            cell: (asset) => <StatusBadge status={asset.status} />,
        },
    ];

    /* ---------------- Event Horizon header ---------------- */

    const header = (
        <PageHeader
            icon={Package}
            title="Fixed assets"
            titleChip={
                <PageHeaderStatusChip variant="success">
                    {summary.active_count} active
                </PageHeaderStatusChip>
            }
            subline={`General ledger · ${summary.total_count} assets · ${formatMoney(summary.net_book_value)} book value`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') applyFilters({ search });
                        }}
                        placeholder="Search name or tag…"
                    />
                    <PageHeaderGlassButton
                        icon={Download}
                        onClick={() => {
                            window.location.href = exportUrl;
                        }}
                    >
                        Export CSV
                    </PageHeaderGlassButton>
                    {canManage ? (
                        <PageHeaderGlassButton
                            icon={Calculator}
                            onClick={() => setDepModalOpen(true)}
                        >
                            Run depreciation
                        </PageHeaderGlassButton>
                    ) : null}
                    {canManage ? (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New asset
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Assets"
                        href="/finance/fixed-assets"
                        ariaLabel="View the whole fixed-asset register"
                    >
                        <PageHeaderMeterBig>
                            {summary.total_count}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {summary.active_count} still depreciating
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Cost"
                        href="/finance/fixed-assets"
                        ariaLabel="View assets at cost"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.total_cost)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            original purchase cost
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Depreciation"
                        tone="warning"
                        href="/finance/journals?type=standard"
                        ariaLabel="View the depreciation journals"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.total_depreciation)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterBar percent={depreciatedPercent} />
                        <PageHeaderMeterCaption>
                            {depreciatedPercent.toFixed(1)}% of cost written
                            down
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>

                    <PageHeaderMeterBlock
                        label="Book value"
                        tone="success"
                        href="/finance/reports/balance-sheet"
                        ariaLabel="View the balance sheet"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(summary.net_book_value)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            carried on the balance sheet
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Category"
                        value={filters.category || 'all'}
                        allValue="all"
                        options={CATEGORY_OPTIONS}
                        onChange={(value) =>
                            applyFilters({
                                category: value === 'all' ? '' : value,
                            })
                        }
                    />
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status || 'all'}
                        allValue="all"
                        options={STATUS_OPTIONS}
                        onChange={(value) =>
                            applyFilters({
                                status: value === 'all' ? '' : value,
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
            <Head title="Fixed assets" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Fixed assets"
                        caption={`${assets.data.length} of ${assets.total} shown`}
                    />

                    {assets.data.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                searchTerm={filters.search}
                                onClear={() => {
                                    setSearch('');
                                    applyFilters({
                                        search: '',
                                        category: '',
                                        status: '',
                                    });
                                }}
                                title="No assets match your search"
                            />
                        ) : (
                            <EmptyList
                                icon={Package}
                                itemName="fixed asset"
                                title="No fixed assets yet"
                                description="Register your first fixed asset to start tracking cost, depreciation and book value."
                                action={
                                    canManage ? (
                                        <Button
                                            size="sm"
                                            onClick={() => setCreateOpen(true)}
                                        >
                                            New asset
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )
                    ) : (
                        <>
                            <EntityTable
                                rows={assets.data}
                                rowKey={(asset) => asset.id}
                                identityLabel="Asset"
                                minWidth={1180}
                                identity={(asset) => ({
                                    icon: Package,
                                    name: asset.asset_name,
                                    subline: asset.asset_tag ?? undefined,
                                })}
                                hrefFor={(asset) =>
                                    `/finance/fixed-assets/${asset.id}`
                                }
                                columns={columns}
                                actionsFor={actionsFor}
                                mutedFor={(asset) => asset.status === 'disposed'}
                                onOpen={(asset) =>
                                    router.visit(
                                        `/finance/fixed-assets/${asset.id}`,
                                    )
                                }
                                onRowContextMenu={(e, asset) =>
                                    ctx.open(e, asset)
                                }
                            />
                            <LaravelPagination
                                links={assets.links}
                                lastPage={assets.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>

            {ctx.ctx ? (
                <EntityContextMenu
                    x={ctx.ctx.x}
                    y={ctx.ctx.y}
                    icon={Package}
                    title={ctx.ctx.record.asset_name}
                    items={actionsFor(ctx.ctx.record)}
                    onClose={ctx.close}
                />
            ) : null}

            {canManage ? (
                <Dialog open={depModalOpen} onOpenChange={setDepModalOpen}>
                    <DialogContent
                        style={{
                            maxWidth: 'min(92vw, 480px)',
                            width: 'min(92vw, 480px)',
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle>Run depreciation</DialogTitle>
                            <DialogDescription>
                                This posts this month&rsquo;s depreciation for
                                every active asset: it creates a depreciation
                                record per asset and posts the matching journals
                                to the general ledger.
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            onSubmit={handleRunDepreciation}
                            className="flex flex-col gap-4"
                        >
                            <div className="flex flex-col gap-1.5">
                                <Label htmlFor="depreciation_date">
                                    Depreciation date
                                </Label>
                                <Input
                                    id="depreciation_date"
                                    type="date"
                                    value={depForm.data.depreciation_date}
                                    onChange={(e) =>
                                        depForm.setData(
                                            'depreciation_date',
                                            e.target.value,
                                        )
                                    }
                                />
                                {depForm.errors.depreciation_date ? (
                                    <p className="text-sm text-destructive">
                                        {depForm.errors.depreciation_date}
                                    </p>
                                ) : null}
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setDepModalOpen(false)}
                                    disabled={depForm.processing}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={depForm.processing}
                                >
                                    {depForm.processing
                                        ? 'Processing…'
                                        : 'Run depreciation'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            ) : null}

            {canManage ? (
                <FixedAssetDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    assetAccounts={assetAccounts}
                    expenseAccounts={expenseAccounts}
                />
            ) : null}

            {canManage && editAsset ? (
                <FixedAssetDialog
                    key={editAsset.id}
                    open
                    asset={editAsset}
                    onClose={() => setEditAsset(null)}
                    assetAccounts={assetAccounts}
                    expenseAccounts={expenseAccounts}
                />
            ) : null}

            {canManage && disposeAsset ? (
                <FixedAssetDisposeDialog
                    key={`dispose-${disposeAsset.id}`}
                    open
                    onClose={() => setDisposeAsset(null)}
                    asset={{
                        id: disposeAsset.id,
                        asset_name: disposeAsset.asset_name,
                        asset_tag: disposeAsset.asset_tag,
                        purchase_cost: disposeAsset.purchase_cost,
                        accumulated_depreciation:
                            disposeAsset.accumulated_depreciation,
                        gl_asset_account: disposeAsset.gl_asset_account,
                        gl_depreciation_account:
                            disposeAsset.gl_depreciation_account,
                    }}
                />
            ) : null}
        </AppLayout>
    );
}
