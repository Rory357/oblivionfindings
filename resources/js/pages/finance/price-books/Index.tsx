import {
    FinanceSectionRail,
    PriceBookDialog,
    type EditablePriceBook,
} from '@/components/finance';
import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    EntityStatusChip,
    ListCaption,
    compactMenu,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
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
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    BookOpen,
    CalendarDays,
    Eye,
    Pencil,
    Plus,
    Star,
} from 'lucide-react';
import { useEffect, useState } from 'react';

const ALL = '__all';

type PriceBook = {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    is_default: boolean;
    effective_from: string | null;
    effective_to: string | null;
    items_count: number;
};

type Filters = { q?: string; status?: string };

type Props = {
    price_books: {
        data: PriceBook[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: Filters;
    stats: {
        total: number;
        active: number;
        active_items: number;
        default_book: string;
    };
    canManage: boolean;
};

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Receivables', href: '/finance/invoices' },
    { title: 'Price books', href: '/finance/price-books' },
];

function formatDate(value: string | null): string {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function PriceBooksIndex({
    price_books,
    filters = {},
    stats,
    canManage = false,
}: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editBook, setEditBook] = useState<EditablePriceBook | null>(null);
    const [search, setSearch] = useState(filters.q ?? '');
    const ctxMenu = useEntityContextMenu<PriceBook>();

    const go = (patch: Filters) => {
        const next = { ...filters, ...patch };
        const query: Record<string, string> = {};
        if (next.q) query.q = next.q;
        if (next.status && next.status !== ALL) query.status = next.status;
        router.get('/finance/price-books', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    useEffect(() => {
        setSearch(filters.q ?? '');
    }, [filters.q]);

    useEffect(() => {
        const handle = setTimeout(() => {
            if ((filters.q ?? '') !== search) {
                go({ q: search.trim() || undefined });
            }
        }, 350);
        return () => clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const hasFilters = Boolean(filters.q || filters.status);

    const clearFilters = () => {
        setSearch('');
        router.get('/finance/price-books', {}, { preserveState: true });
    };

    const openEdit = (book: PriceBook) =>
        setEditBook({
            id: book.id,
            name: book.name,
            description: book.description,
            effective_from: book.effective_from,
            effective_to: book.effective_to,
            is_active: book.is_active,
        });

    const actionsFor = (book: PriceBook): MenuItem[] =>
        compactMenu([
            {
                label: 'Open price book',
                icon: Eye,
                onClick: () => router.visit(`/finance/price-books/${book.id}`),
            },
            canManage
                ? {
                      label: 'Edit details',
                      icon: Pencil,
                      onClick: () => openEdit(book),
                  }
                : null,
        ]);

    const header = (
        <PageHeader
            variant="index"
            icon={BookOpen}
            title="Price books"
            titleChip={
                <PageHeaderStatusChip
                    variant={stats.active > 0 ? 'success' : 'neutral'}
                >
                    {stats.active} active
                </PageHeaderStatusChip>
            }
            subline={`Rate cards for quotes and invoices · ${stats.total} books · default ${stats.default_book}`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search price books…"
                    />
                    {canManage && (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New price book
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Price books"
                        href="/finance/price-books"
                        ariaLabel="View every price book"
                    >
                        <PageHeaderMeterBig>{stats.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Rate cards in the register
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Active"
                        tone="success"
                        href="/finance/price-books?status=active"
                        ariaLabel="View active price books"
                    >
                        <PageHeaderMeterBig>{stats.active}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            In use for new quotes
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Rate items"
                        href="/finance/price-books?status=active"
                        ariaLabel="View the books holding these rates"
                    >
                        <PageHeaderMeterBig>
                            {stats.active_items}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Active priced services
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Retired"
                        tone={
                            stats.total - stats.active > 0 ? 'warning' : 'brand'
                        }
                        href="/finance/price-books?status=inactive"
                        ariaLabel="View inactive price books"
                    >
                        <PageHeaderMeterBig>
                            {stats.total - stats.active}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Inactive rate cards
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterSelect
                    label="Status"
                    value={filters.status || ALL}
                    allValue={ALL}
                    options={STATUS_OPTIONS}
                    onChange={(value) =>
                        go({ status: value === ALL ? undefined : value })
                    }
                />
            }
            rail={<FinanceSectionRail />}
        />
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Price books" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Price books"
                        caption={`${price_books.data.length} of ${price_books.total} shown`}
                    />

                    {price_books.data.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                onClear={clearFilters}
                                title="No price books match your filters"
                            />
                        ) : (
                            <EmptyList
                                icon={BookOpen}
                                itemName="price book"
                                title="No price books yet"
                                description="Create a rate card so quotes and invoices can be built from agreed prices."
                                action={
                                    canManage ? (
                                        <Button
                                            size="sm"
                                            onClick={() => setCreateOpen(true)}
                                        >
                                            New price book
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )
                    ) : (
                        <>
                            <EntityCardGrid>
                                {price_books.data.map((book) => (
                                    <EntityCard
                                        key={book.id}
                                        meridian={
                                            book.is_active
                                                ? 'success'
                                                : 'warning'
                                        }
                                        icon={BookOpen}
                                        name={book.name}
                                        subline={
                                            book.description ?? 'No description'
                                        }
                                        actions={actionsFor(book)}
                                        href={`/finance/price-books/${book.id}`}
                                        onContextMenu={(e) =>
                                            ctxMenu.open(e, book)
                                        }
                                        muted={!book.is_active}
                                        chips={
                                            <>
                                                <EntityStatusChip
                                                    variant={
                                                        book.is_active
                                                            ? 'success'
                                                            : 'neutral'
                                                    }
                                                >
                                                    {book.is_active
                                                        ? 'Active'
                                                        : 'Inactive'}
                                                </EntityStatusChip>
                                                {book.is_default ? (
                                                    <EntityChip icon={Star}>
                                                        Default
                                                    </EntityChip>
                                                ) : null}
                                                <EntityChip
                                                    outline
                                                    icon={CalendarDays}
                                                >
                                                    {formatDate(
                                                        book.effective_from,
                                                    )}{' '}
                                                    –{' '}
                                                    {formatDate(
                                                        book.effective_to,
                                                    )}
                                                </EntityChip>
                                            </>
                                        }
                                        metric={{
                                            label: 'Rate items',
                                            value: String(book.items_count),
                                            percent: null,
                                        }}
                                    />
                                ))}
                            </EntityCardGrid>
                            <LaravelPagination
                                links={price_books.links}
                                lastPage={price_books.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={BookOpen}
                    title={ctxMenu.ctx.record.name}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

            {canManage && (
                <PriceBookDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                />
            )}

            {canManage && editBook && (
                <PriceBookDialog
                    key={editBook.id}
                    open
                    priceBook={editBook}
                    onClose={() => setEditBook(null)}
                />
            )}
        </AppLayout>
    );
}
