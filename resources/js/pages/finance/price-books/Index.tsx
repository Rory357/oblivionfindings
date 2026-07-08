import { OpsStatCard } from '@/components/ops-stat-card';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PageHero } from '@/components/page';
import {
    PriceBookDialog,
    ReceivablesTabsFooter,
    type EditablePriceBook,
} from '@/components/finance';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { BookOpen, CalendarDays, Eye, Hash, Pencil, Plus, Search, Star } from 'lucide-react';
import { useState } from 'react';

const ANY = '__ANY__';

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

type Props = {
    price_books: {
        data: PriceBook[];
        links: any[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q?: string;
        status?: string;
    };
    stats: {
        total: number;
        active_items: number;
        default_book: string;
    };
    canManage: boolean;
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function PriceBooksIndex({ price_books = { data: [], links: [], current_page: 1, last_page: 1, total: 0 }, filters = {} as any, stats = {} as any, canManage = false }: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editBook, setEditBook] = useState<EditablePriceBook | null>(null);

    const updateFilters = (key: string, value: string | null) => {
        router.get('/finance/price-books', { ...filters, [key]: value }, { preserveState: true, replace: true });
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

    return (
        <AppLayout>
            <Head title="Price Books" />
            <PageHero category="finance"
                icon={BookOpen}
                title="Price Books"
                description="Manage pricing structures and rate schedules for services."
                stats={[
                    { label: 'Total books', value: stats?.total ?? 0 },
                    { label: 'Active items', value: stats?.active_items ?? 0 },
                    { label: 'Default book', value: stats?.default_book ?? 'None' },
                ]}
                footer={<ReceivablesTabsFooter active="price-books" />}
            />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    <OpsStatCard label="Total Books" value={stats?.total ?? 0} icon={BookOpen} color="indigo" />
                    <OpsStatCard label="Active Items" value={stats?.active_items ?? 0} icon={Hash} color="emerald" />
                    <OpsStatCard label="Default Book" value={stats?.default_book ?? 'None'} icon={Star} color="amber" />
                </div>

                {/* Filters */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search price books..."
                            className="h-9 pl-8 text-sm"
                            defaultValue={filters?.q ?? ''}
                            onChange={(e) => updateFilters('q', e.target.value || null)}
                        />
                    </div>
                    <Select value={filters?.status ?? ANY} onValueChange={(v) => updateFilters('status', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[130px] text-xs" aria-label="Filter by status">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                    {canManage && (
                        <Button size="sm" onClick={() => setCreateOpen(true)}>
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            New Price Book
                        </Button>
                    )}
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {(price_books?.data ?? []).length === 0 && (
                        <Card>
                            <CardContent className="flex flex-col items-center justify-center py-16">
                                <BookOpen className="mb-4 h-12 w-12 text-muted-foreground/30" />
                                <h2 className="text-lg font-semibold text-muted-foreground">No Price Books Found</h2>
                                <p className="mt-1 text-sm text-muted-foreground/80">Create your first price book to get started.</p>
                                {canManage && (
                                    <Button size="sm" className="mt-4" onClick={() => setCreateOpen(true)}>
                                        Create Price Book
                                    </Button>
                                )}
                            </CardContent>
                        </Card>
                    )}
                    {(price_books?.data ?? []).map((book) => (
                        <Card key={book.id} className="transition-all hover:border-border hover:shadow-sm">
                            <CardContent className="flex items-center gap-4 p-4">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70">
                                    <BookOpen className="h-5 w-5" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <Link href={`/finance/price-books/${book.id}`} className="text-sm font-semibold hover:underline">
                                            {book.name}
                                        </Link>
                                        <Badge variant={book.is_active ? 'default' : 'secondary'} className="h-4 px-1.5 text-[9px]">
                                            {book.is_active ? 'Active' : 'Inactive'}
                                        </Badge>
                                        {book.is_default && (
                                            <Badge variant="outline" className="h-4 px-1.5 text-[9px]">
                                                <Star className="mr-0.5 h-2.5 w-2.5" /> Default
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                        <span>{book.items_count} items</span>
                                        {book.effective_from && (
                                            <span className="flex items-center gap-1">
                                                <CalendarDays className="h-3 w-3" />
                                                {formatDate(book.effective_from)} - {formatDate(book.effective_to)}
                                            </span>
                                        )}
                                        {book.description && <span className="truncate">{book.description}</span>}
                                    </div>
                                </div>
                                <div className="flex shrink-0 gap-1">
                                    <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                        <Link href={`/finance/price-books/${book.id}`} aria-label={`View ${book.name}`}>
                                            <Eye className="h-3.5 w-3.5" />
                                        </Link>
                                    </Button>
                                    {canManage && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="h-7 w-7 p-0"
                                            aria-label={`Edit ${book.name}`}
                                            onClick={() => openEdit(book)}
                                        >
                                            <Pencil className="h-3.5 w-3.5" />
                                        </Button>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {/* Pagination */}
                {(price_books?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(price_books?.links ?? []).map((link: any, i: number) => (
                            <Button
                                key={i}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                className="h-7 min-w-[28px] px-2 text-xs"
                                disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>

            {canManage && (
                <PriceBookDialog open={createOpen} onClose={() => setCreateOpen(false)} />
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
