import {
    FinanceSectionRail,
    formatMoney,
    QuoteDialog,
    type EditableQuote,
    type QuoteClientOption,
    type QuotePriceBook,
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
    type EntityMeridian,
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
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    Calculator,
    CalendarDays,
    Download,
    Eye,
    FileText,
    Pencil,
    Plus,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const ALL = '__all';

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
    lines: Array<{
        description: string;
        quantity: number | string;
        unit_price: number | string;
    }>;
};

type Props = {
    quotes: {
        data: Quote[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        status?: string;
    };
    stats: {
        total: number;
        pending: number;
        accepted: number;
        converted: number;
        pending_value: number;
    };
    canManage: boolean;
    clients: QuoteClientOption[];
    priceBooks: QuotePriceBook[];
};

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'draft', label: 'Draft' },
    { value: 'sent', label: 'Sent' },
    { value: 'accepted', label: 'Accepted' },
    { value: 'declined', label: 'Declined' },
    { value: 'expired', label: 'Expired' },
    { value: 'converted', label: 'Converted' },
];

const MERIDIAN: Record<string, EntityMeridian> = {
    draft: 'warning',
    sent: 'warning',
    accepted: 'success',
    converted: 'success',
    declined: 'critical',
    expired: 'critical',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Receivables', href: '/finance/invoices' },
    { title: 'Quotes', href: '/finance/quotes' },
];

function formatDate(value: string | null): string {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function QuotesIndex({
    quotes,
    filters = {},
    stats,
    canManage = false,
    clients = [],
    priceBooks = [],
}: Props) {
    const [createOpen, setCreateOpen] = useState(false);
    const [editQuote, setEditQuote] = useState<EditableQuote | null>(null);
    const [search, setSearch] = useState('');
    const ctxMenu = useEntityContextMenu<Quote>();

    const go = (patch: { status?: string }) => {
        const next = { ...filters, ...patch };
        const query: Record<string, string> = {};
        if (next.status && next.status !== ALL) query.status = next.status;
        router.get('/finance/quotes', query, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    useEffect(() => {
        setSearch('');
    }, [filters.status]);

    const clientName = (quote: Quote) =>
        quote.client
            ? `${quote.client.first_name} ${quote.client.last_name}`
            : 'No client';

    const rows = useMemo(() => {
        const term = search.trim().toLowerCase();
        if (!term) return quotes.data;
        return quotes.data.filter(
            (quote) =>
                quote.reference.toLowerCase().includes(term) ||
                quote.title.toLowerCase().includes(term) ||
                clientName(quote).toLowerCase().includes(term),
        );
    }, [quotes.data, search]);

    const hasFilters = Boolean(filters.status) || Boolean(search.trim());

    const clearFilters = () => {
        setSearch('');
        router.get('/finance/quotes', {}, { preserveState: true });
    };

    const openEdit = (quote: Quote) =>
        setEditQuote({
            id: quote.id,
            client_id: quote.client_id,
            title: quote.title,
            valid_until: quote.valid_until,
            notes: quote.notes,
            lines: quote.lines,
        });

    const actionsFor = (quote: Quote): MenuItem[] =>
        compactMenu([
            {
                label: 'Open quote',
                icon: Eye,
                onClick: () => router.visit(`/finance/quotes/${quote.id}`),
            },
            canManage && quote.status === 'draft'
                ? {
                      label: 'Edit quote',
                      icon: Pencil,
                      onClick: () => openEdit(quote),
                  }
                : null,
        ]);

    const header = (
        <PageHeader
            variant="index"
            icon={Calculator}
            title="Quotes"
            titleChip={
                <PageHeaderStatusChip
                    variant={stats.pending > 0 ? 'warning' : 'success'}
                >
                    {stats.pending} awaiting a decision
                </PageHeaderStatusChip>
            }
            subline={`Service quotes · ${stats.total} in total · ${stats.converted} converted to work`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search quotes, clients…"
                    />
                    <PageHeaderGlassButton
                        icon={Download}
                        onClick={() => {
                            const query = filters.status
                                ? `?status=${filters.status}`
                                : '';
                            window.location.href = `/finance/quotes/export${query}`;
                        }}
                    >
                        Export CSV
                    </PageHeaderGlassButton>
                    {canManage && (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={() => setCreateOpen(true)}
                        >
                            New quote
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Open quotes"
                        tone={stats.pending > 0 ? 'warning' : 'brand'}
                        href="/finance/quotes?status=sent"
                        ariaLabel="View sent quotes"
                    >
                        <PageHeaderMeterBig>{stats.pending}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Draft or sent
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Value in play"
                        href="/finance/quotes?status=sent"
                        ariaLabel="View quotes awaiting a decision"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(stats.pending_value)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Across open quotes
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Accepted"
                        tone="success"
                        href="/finance/quotes?status=accepted"
                        ariaLabel="View accepted quotes"
                    >
                        <PageHeaderMeterBig>
                            {stats.accepted}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Ready to convert
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Converted"
                        href="/finance/quotes?status=converted"
                        ariaLabel="View converted quotes"
                    >
                        <PageHeaderMeterBig>
                            {stats.converted}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Now an agreement or invoice
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
            <Head title="Quotes" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Quotes"
                        caption={`${rows.length} of ${quotes.total} shown`}
                    />

                    {rows.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                onClear={clearFilters}
                                title="No quotes match your filters"
                            />
                        ) : (
                            <EmptyList
                                icon={FileText}
                                itemName="quote"
                                title="No quotes yet"
                                description="Create your first quote to get started."
                                action={
                                    canManage ? (
                                        <Button
                                            size="sm"
                                            onClick={() => setCreateOpen(true)}
                                        >
                                            New quote
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )
                    ) : (
                        <>
                            <EntityCardGrid>
                                {rows.map((quote) => {
                                    const expired =
                                        quote.valid_until &&
                                        new Date(quote.valid_until) <
                                            new Date() &&
                                        !['accepted', 'converted'].includes(
                                            quote.status,
                                        );
                                    return (
                                        <EntityCard
                                            key={quote.id}
                                            meridian={
                                                MERIDIAN[quote.status] ??
                                                'warning'
                                            }
                                            icon={FileText}
                                            name={quote.reference}
                                            subline={quote.title}
                                            actions={actionsFor(quote)}
                                            href={`/finance/quotes/${quote.id}`}
                                            onContextMenu={(e) =>
                                                ctxMenu.open(e, quote)
                                            }
                                            muted={[
                                                'declined',
                                                'expired',
                                            ].includes(quote.status)}
                                            chips={
                                                <>
                                                    <EntityStatusChip
                                                        variant={
                                                            quote.status ===
                                                                'accepted' ||
                                                            quote.status ===
                                                                'converted'
                                                                ? 'success'
                                                                : quote.status ===
                                                                        'declined' ||
                                                                    quote.status ===
                                                                        'expired'
                                                                  ? 'critical'
                                                                  : quote.status ===
                                                                      'draft'
                                                                    ? 'neutral'
                                                                    : 'info'
                                                        }
                                                    >
                                                        {quote.status
                                                            .charAt(0)
                                                            .toUpperCase() +
                                                            quote.status.slice(
                                                                1,
                                                            )}
                                                    </EntityStatusChip>
                                                    <EntityChip outline>
                                                        {quote.items_count}{' '}
                                                        {quote.items_count === 1
                                                            ? 'item'
                                                            : 'items'}
                                                    </EntityChip>
                                                    <EntityChip
                                                        icon={CalendarDays}
                                                    >
                                                        Valid to{' '}
                                                        {formatDate(
                                                            quote.valid_until,
                                                        )}
                                                    </EntityChip>
                                                </>
                                            }
                                            metric={{
                                                label: 'Quote total',
                                                value: formatMoney(
                                                    quote.total_amount,
                                                ),
                                                percent: null,
                                            }}
                                            alerts={
                                                expired ? (
                                                    <EntityStatusChip variant="critical">
                                                        Past its valid-until
                                                        date
                                                    </EntityStatusChip>
                                                ) : undefined
                                            }
                                            footer={{
                                                personName:
                                                    quote.creator?.name ?? null,
                                                primary: clientName(quote),
                                                secondary: `Raised ${formatDate(quote.created_at)}${
                                                    quote.creator
                                                        ? ` · ${quote.creator.name}`
                                                        : ''
                                                }`,
                                            }}
                                        />
                                    );
                                })}
                            </EntityCardGrid>
                            <LaravelPagination
                                links={quotes.links}
                                lastPage={quotes.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={FileText}
                    title={ctxMenu.ctx.record.reference}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}

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
