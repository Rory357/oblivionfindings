import {
    ConfirmDialog,
    FinanceSectionRail,
    formatMoney,
    PriceBookDialog,
    PriceBookItemDialog,
    type EditablePriceBookItem,
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
    PageHeaderFilterCheck,
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
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    BookOpen,
    Pencil,
    Plus,
    Power,
    Tag,
    Trash2,
} from 'lucide-react';
import { useMemo, useState } from 'react';

const ALL = '__all';

type PriceBookItem = {
    id: number;
    service_code: string | null;
    name: string;
    description: string | null;
    unit: string;
    rate: number | string;
    rate_type: string;
    category: string | null;
    is_active: boolean;
};

type Props = {
    price_book: {
        id: number;
        name: string;
        description: string | null;
        is_default: boolean;
        is_active: boolean;
        effective_from: string | null;
        effective_to: string | null;
        items: PriceBookItem[];
    };
    canManage: boolean;
};

function formatDate(value: string | null): string {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function PriceBookShow({ price_book, canManage = false }: Props) {
    const [editOpen, setEditOpen] = useState(false);
    const [itemOpen, setItemOpen] = useState(false);
    const [editItem, setEditItem] = useState<EditablePriceBookItem | null>(null);
    const [removeItem, setRemoveItem] = useState<PriceBookItem | null>(null);
    const [removing, setRemoving] = useState(false);
    const [search, setSearch] = useState('');
    const [unit, setUnit] = useState(ALL);
    const [activeOnly, setActiveOnly] = useState(false);
    const ctxMenu = useEntityContextMenu<PriceBookItem>();

    const items = useMemo(() => price_book.items ?? [], [price_book.items]);
    const activeItems = items.filter((item) => item.is_active);

    const unitOptions = useMemo(
        () => [
            { value: ALL, label: 'Any unit' },
            ...Array.from(new Set(items.map((item) => item.unit))).map(
                (value) => ({
                    value,
                    label: value.charAt(0).toUpperCase() + value.slice(1),
                }),
            ),
        ],
        [items],
    );

    const rows = useMemo(() => {
        const term = search.trim().toLowerCase();
        return items.filter((item) => {
            if (
                term &&
                !item.name.toLowerCase().includes(term) &&
                !(item.service_code ?? '').toLowerCase().includes(term)
            )
                return false;
            if (unit !== ALL && item.unit !== unit) return false;
            if (activeOnly && !item.is_active) return false;
            return true;
        });
    }, [items, search, unit, activeOnly]);

    const hasFilters = Boolean(search.trim()) || unit !== ALL || activeOnly;

    const clearFilters = () => {
        setSearch('');
        setUnit(ALL);
        setActiveOnly(false);
    };

    const averageRate = activeItems.length
        ? activeItems.reduce((total, item) => total + Number(item.rate), 0) /
          activeItems.length
        : 0;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Finance', href: '/finance' },
        { title: 'Receivables', href: '/finance/invoices' },
        { title: 'Price books', href: '/finance/price-books' },
        { title: price_book.name },
    ];

    const toggleActive = (item: PriceBookItem) =>
        router.put(
            `/finance/price-books/${price_book.id}/items/${item.id}`,
            { is_active: !item.is_active },
            { preserveScroll: true },
        );

    const actionsFor = (item: PriceBookItem): MenuItem[] =>
        compactMenu(
            canManage
                ? [
                      {
                          label: 'Edit rate',
                          icon: Pencil,
                          onClick: () =>
                              setEditItem({
                                  id: item.id,
                                  service_code: item.service_code,
                                  name: item.name,
                                  unit: item.unit,
                                  rate: item.rate,
                                  description: item.description,
                              }),
                      },
                      {
                          label: item.is_active ? 'Deactivate' : 'Reactivate',
                          icon: Power,
                          onClick: () => toggleActive(item),
                      },
                      { separator: true },
                      {
                          label: 'Remove rate',
                          icon: Trash2,
                          danger: true,
                          onClick: () => setRemoveItem(item),
                      },
                  ]
                : [],
        );

    const columns: EntityTableColumn<PriceBookItem>[] = [
        {
            key: 'unit',
            label: 'Unit',
            width: '0.6fr',
            cell: (item) => (
                <span className="capitalize">{item.unit}</span>
            ),
        },
        {
            key: 'rate',
            label: 'Rate (NZD)',
            width: '0.8fr',
            align: 'right',
            cell: (item) => (
                <span className="font-medium tabular-nums">
                    {formatMoney(item.rate)}
                </span>
            ),
        },
        {
            key: 'rate_type',
            label: 'Rate type',
            width: '0.7fr',
            cell: (item) => (
                <span className="capitalize text-muted-foreground">
                    {item.rate_type}
                </span>
            ),
        },
        {
            key: 'category',
            label: 'Category',
            width: '0.9fr',
            cell: (item) => item.category ?? '—',
        },
        {
            key: 'status',
            label: 'Status',
            width: '0.7fr',
            cell: (item) => (
                <EntityStatusChip
                    variant={item.is_active ? 'success' : 'neutral'}
                >
                    {item.is_active ? 'Active' : 'Inactive'}
                </EntityStatusChip>
            ),
        },
    ];

    const header = (
        <PageHeader
            variant="profile"
            icon={BookOpen}
            backHref="/finance/price-books"
            title={price_book.name}
            titleChip={
                <PageHeaderStatusChip
                    variant={price_book.is_active ? 'success' : 'neutral'}
                >
                    {price_book.is_active ? 'Active' : 'Inactive'}
                </PageHeaderStatusChip>
            }
            subline={`${price_book.description ?? 'Rate card'} · effective ${formatDate(
                price_book.effective_from,
            )} – ${formatDate(price_book.effective_to)}${
                price_book.is_default ? ' · default book' : ''
            }`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search rates…"
                    />
                    {canManage && (
                        <>
                            <PageHeaderGlassButton
                                icon={Pencil}
                                onClick={() => setEditOpen(true)}
                            >
                                Edit book
                            </PageHeaderGlassButton>
                            <PageHeaderPrimaryButton
                                icon={Plus}
                                onClick={() => setItemOpen(true)}
                            >
                                Add rate item
                            </PageHeaderPrimaryButton>
                        </>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Rate items"
                        href="/finance/price-books"
                        ariaLabel="Back to the price-book register"
                    >
                        <PageHeaderMeterBig>{items.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            In this book
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Active rates"
                        tone="success"
                        href="/finance/price-books?status=active"
                        ariaLabel="View active price books"
                    >
                        <PageHeaderMeterBig>
                            {activeItems.length}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Available to quote
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Average rate"
                        href="/finance/quotes"
                        ariaLabel="View quotes built from these rates"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(averageRate)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Across active rates
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Effective to"
                        href="/finance/price-books"
                        ariaLabel="View every price book"
                    >
                        <PageHeaderMeterBig>
                            {formatDate(price_book.effective_to)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {price_book.effective_to
                                ? 'Rates expire on this date'
                                : 'No end date set'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={Tag}
                        label="Unit"
                        value={unit}
                        allValue={ALL}
                        options={unitOptions}
                        onChange={setUnit}
                    />
                    <PageHeaderFilterCheck
                        label="Active only"
                        checked={activeOnly}
                        onChange={setActiveOnly}
                    />
                </>
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={price_book.name} />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Rate items"
                        caption={`${rows.length} of ${items.length} shown`}
                    />

                    {rows.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                onClear={clearFilters}
                                title="No rates match your filters"
                            />
                        ) : (
                            <EmptyList
                                icon={BookOpen}
                                itemName="rate item"
                                title="No rate items yet"
                                description="Add the priced services this book covers so quotes can be built from it."
                                action={
                                    canManage ? (
                                        <Button
                                            size="sm"
                                            onClick={() => setItemOpen(true)}
                                        >
                                            Add rate item
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )
                    ) : (
                        <EntityTable
                            rows={rows}
                            rowKey={(item) => item.id}
                            identityLabel="Rate item"
                            identity={(item) => ({
                                icon: Tag,
                                name: item.name,
                                subline: item.service_code ?? 'No service code',
                            })}
                            columns={columns}
                            actionsFor={actionsFor}
                            onRowContextMenu={ctxMenu.open}
                            mutedFor={(item) => !item.is_active}
                            minWidth={960}
                        />
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Tag}
                    title={ctxMenu.ctx.record.name}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {canManage && editOpen && (
                <PriceBookDialog
                    open
                    onClose={() => setEditOpen(false)}
                    priceBook={{
                        id: price_book.id,
                        name: price_book.name,
                        description: price_book.description,
                        effective_from: price_book.effective_from,
                        effective_to: price_book.effective_to,
                        is_active: price_book.is_active,
                    }}
                />
            )}

            {canManage && (
                <PriceBookItemDialog
                    open={itemOpen}
                    onClose={() => setItemOpen(false)}
                    priceBookId={price_book.id}
                />
            )}

            {canManage && editItem && (
                <PriceBookItemDialog
                    key={editItem.id}
                    open
                    onClose={() => setEditItem(null)}
                    priceBookId={price_book.id}
                    item={editItem}
                />
            )}

            <ConfirmDialog
                variant="destructive"
                open={removeItem !== null}
                onClose={() => setRemoveItem(null)}
                title="Remove this rate item?"
                description={
                    removeItem
                        ? `“${removeItem.name}” is removed from ${price_book.name}. Quotes already built from it keep their prices. Deactivate it instead if you only want to stop new use.`
                        : ''
                }
                confirmText="Remove rate"
                processing={removing}
                onConfirm={() => {
                    if (!removeItem) return;
                    router.delete(
                        `/finance/price-books/${price_book.id}/items/${removeItem.id}`,
                        {
                            preserveScroll: true,
                            onStart: () => setRemoving(true),
                            onFinish: () => {
                                setRemoving(false);
                                setRemoveItem(null);
                            },
                        },
                    );
                }}
            />
        </AppLayout>
    );
}
