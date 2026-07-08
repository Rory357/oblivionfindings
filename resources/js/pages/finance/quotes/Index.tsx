import { OpsStatCard } from '@/components/ops-stat-card';
import PageShell from '@/components/page-shell';
import { StatusBadge } from '@/components/ui/status-badge';
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
import { EmptyList, EmptySearch } from '@/components/ui/empty-state';
import {
    formatMoney,
    QuoteDialog,
    ReceivablesTabsFooter,
    useRowContextMenu,
    type EditableQuote,
    type QuoteClientOption,
    type QuotePriceBook,
    type RowCtxItem,
} from '@/components/finance';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowRightLeft, Calculator, CheckCircle2, Clock, Download, Eye, FileText, Pencil, Plus, Search } from 'lucide-react';
import { useState } from 'react';

const ANY = '__ANY__';

type Quote = {
    id: number;
    reference: string;
    status: string;
    total_amount: number;
    valid_until: string | null;
    created_at: string;
    client: { id: number; first_name: string; last_name: string } | null;
    creator: { id: number; name: string } | null;
    items_count: number;
    // Raw header + lines for prefilling the edit modal (draft rows only).
    client_id: number | null;
    title: string;
    notes: string | null;
    lines: Array<{ description: string; quantity: number | string; unit_price: number | string }>;
};

type Props = {
    quotes: {
        data: Quote[];
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
        pending: number;
        accepted: number;
        converted: number;
    };
    canManage: boolean;
    clients: QuoteClientOption[];
    priceBooks: QuotePriceBook[];
};

function formatDate(d: string | null): string {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function QuotesIndex({
    quotes = { data: [], links: [], current_page: 1, last_page: 1, total: 0 },
    filters = {} as any,
    stats = {} as any,
    canManage = false,
    clients = [],
    priceBooks = [],
}: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editQuote, setEditQuote] = useState<EditableQuote | null>(null);

    const updateFilters = (key: string, value: string | null) => {
        router.get('/finance/quotes', { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    const clearFilters = () => {
        router.get('/finance/quotes', {}, { preserveState: true, replace: true });
    };

    const hasFilters = Boolean(filters?.q || (filters?.status && filters.status !== ANY));

    const openEdit = (quote: Quote) =>
        setEditQuote({
            id: quote.id,
            client_id: quote.client_id,
            title: quote.title,
            valid_until: quote.valid_until,
            notes: quote.notes,
            lines: quote.lines,
        });

    // Right-click row menu — mirrors the row's existing inline actions (Open first).
    const rowMenu = useRowContextMenu();
    const rowMenuItems = (quote: Quote): RowCtxItem[] => {
        const items: RowCtxItem[] = [
            { kind: 'item', label: 'Open', icon: Eye, onSelect: () => router.get(`/finance/quotes/${quote.id}`) },
        ];
        if (canManage && quote.status === 'draft') {
            items.push({ kind: 'item', label: 'Edit', icon: Pencil, onSelect: () => openEdit(quote) });
        }
        return items;
    };

    return (
        <AppLayout>
            <Head title="Quotes" />
            <PageHero category="finance"
                icon={Calculator}
                title="Quotes"
                description="Create and manage service quotes for clients."
                stats={[
                    { label: 'Total', value: stats?.total ?? 0 },
                    { label: 'Pending', value: stats?.pending ?? 0 },
                    { label: 'Accepted', value: stats?.accepted ?? 0 },
                    { label: 'Converted', value: stats?.converted ?? 0 },
                ]}
                actions={
                    <Button size="sm" variant="outline" asChild>
                        <a href={`/finance/quotes/export?${new URLSearchParams(Object.entries({ status: filters?.status ?? '' }).filter(([, v]) => v)).toString()}`}>
                            <Download className="mr-1.5 h-4 w-4" />
                            Export CSV
                        </a>
                    </Button>
                }
                footer={<ReceivablesTabsFooter active="quotes" />}
            />
            <PageShell>
                {/* Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <OpsStatCard label="Total Quotes" value={stats?.total ?? 0} icon={FileText} color="indigo" />
                    <OpsStatCard label="Pending" value={stats?.pending ?? 0} icon={Clock} color="amber" />
                    <OpsStatCard label="Accepted" value={stats?.accepted ?? 0} icon={CheckCircle2} color="emerald" />
                    <OpsStatCard label="Converted" value={stats?.converted ?? 0} icon={ArrowRightLeft} color="blue" />
                </div>

                {/* Filters */}
                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                        <Input
                            placeholder="Search quotes..."
                            className="h-9 pl-8 text-sm"
                            defaultValue={filters?.q ?? ''}
                            onChange={(e) => updateFilters('q', e.target.value || null)}
                        />
                    </div>
                    <Select value={filters?.status ?? ANY} onValueChange={(v) => updateFilters('status', v === ANY ? null : v)}>
                        <SelectTrigger className="h-9 w-[130px] text-xs">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Status</SelectItem>
                            <SelectItem value="draft">Draft</SelectItem>
                            <SelectItem value="sent">Sent</SelectItem>
                            <SelectItem value="accepted">Accepted</SelectItem>
                            <SelectItem value="declined">Declined</SelectItem>
                            <SelectItem value="converted">Converted</SelectItem>
                        </SelectContent>
                    </Select>
                    {canManage && (
                        <Button size="sm" onClick={() => setCreateOpen(true)}>
                            <Plus className="mr-1.5 h-3.5 w-3.5" />
                            New Quote
                        </Button>
                    )}
                </div>

                {/* List */}
                <div className="mt-4 space-y-2">
                    {(quotes?.data ?? []).length === 0 && (
                        <Card>
                            {hasFilters ? (
                                <EmptySearch
                                    onClear={clearFilters}
                                    title="No quotes match your filters"
                                    className="border-0"
                                />
                            ) : (
                                <EmptyList
                                    icon={FileText}
                                    itemName="quote"
                                    title="No quotes yet"
                                    description="Create your first quote to get started."
                                    className="border-0"
                                    action={
                                        canManage ? (
                                            <Button size="sm" onClick={() => setCreateOpen(true)}>
                                                New quote
                                            </Button>
                                        ) : undefined
                                    }
                                />
                            )}
                        </Card>
                    )}
                    {(quotes?.data ?? []).map((quote) => (
                        <Card
                            key={quote.id}
                            className="transition-all hover:border-border hover:shadow-sm"
                            onContextMenu={rowMenu.open(rowMenuItems(quote))}
                        >
                            <CardContent className="flex items-center gap-4 p-4">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70">
                                    <FileText className="h-5 w-5" />
                                </div>
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <Link href={`/finance/quotes/${quote.id}`} className="text-sm font-semibold hover:underline">
                                            {quote.reference}
                                        </Link>
                                        <StatusBadge status={quote.status} size="sm" />
                                        <span className="text-sm font-semibold text-status-success dark:text-status-success">
                                            {formatMoney(quote.total_amount)}
                                        </span>
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                        {quote.client && (
                                            <span>{quote.client.first_name} {quote.client.last_name}</span>
                                        )}
                                        <span>{quote.items_count} items</span>
                                        {quote.valid_until && (
                                            <span className={new Date(quote.valid_until) < new Date() ? 'font-medium text-status-critical' : ''}>
                                                Valid until: {formatDate(quote.valid_until)}
                                            </span>
                                        )}
                                        <span>Created: {formatDate(quote.created_at)}</span>
                                    </div>
                                </div>
                                <div className="flex shrink-0 gap-1">
                                    <Button asChild size="sm" variant="ghost" className="h-7 w-7 p-0">
                                        <Link href={`/finance/quotes/${quote.id}`} aria-label={`View ${quote.reference}`}>
                                            <Eye className="h-3.5 w-3.5" />
                                        </Link>
                                    </Button>
                                    {canManage && quote.status === 'draft' && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="h-7 w-7 p-0"
                                            aria-label={`Edit ${quote.reference}`}
                                            onClick={() => openEdit(quote)}
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
                {(quotes?.last_page ?? 1) > 1 && (
                    <div className="mt-4 flex items-center justify-center gap-1">
                        {(quotes?.links ?? []).map((link: any, i: number) => (
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

                {rowMenu.element}
            </PageShell>

            {canManage && (
                <QuoteDialog
                    open={createOpen}
                    onClose={() => setCreateOpen(false)}
                    clients={clients}
                    priceBooks={priceBooks}
                />
            )}

            {canManage && editQuote && (
                <QuoteDialog
                    key={editQuote.id}
                    open
                    quote={editQuote}
                    onClose={() => setEditQuote(null)}
                    clients={clients}
                    priceBooks={priceBooks}
                />
            )}
        </AppLayout>
    );
}
