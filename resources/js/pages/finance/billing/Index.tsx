import { FinanceSectionRail, formatMoney } from '@/components/finance';
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
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
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
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    CalendarRange,
    Clock,
    Receipt,
    User as UserIcon,
} from 'lucide-react';
import { useEffect, useState } from 'react';

const ALL = '__all';

type BillingEntry = {
    id: number;
    service_date: string;
    hours: number;
    rate: number;
    amount: number;
    rate_type: string;
    status: string;
    notes: string | null;
    client: { id: number; first_name: string; last_name: string } | null;
    staff: { id: number; name: string } | null;
    service_agreement: { id: number; title: string } | null;
};

type ClientOption = { id: number; first_name: string; last_name: string };

type Filters = {
    status?: string;
    q?: string;
    client_id?: string | number;
    date_from?: string;
    date_to?: string;
};

type Props = {
    stats: {
        billed_this_month: number;
        outstanding: number;
        paid_this_month: number;
        pending_count: number;
    };
    entries: {
        data: BillingEntry[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
        total: number;
    };
    clients: ClientOption[];
    status_breakdown: Record<string, number>;
    filters: Filters;
};

const STATUS_OPTIONS = [
    { value: ALL, label: 'Any status' },
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'billed', label: 'Billed' },
    { value: 'paid', label: 'Paid' },
    { value: 'cancelled', label: 'Cancelled' },
];

const RATE_TYPE_LABELS: Record<string, string> = {
    weekday: 'Weekday',
    weekend: 'Weekend',
    public_holiday: 'Public holiday',
    sleepover: 'Sleepover',
    overnight: 'Overnight',
    travel: 'Travel',
};

const MERIDIAN: Record<string, EntityMeridian> = {
    pending: 'warning',
    approved: 'warning',
    billed: 'success',
    paid: 'success',
    cancelled: 'critical',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Finance', href: '/finance' },
    { title: 'Receivables', href: '/finance/invoices' },
    { title: 'Billing', href: '/finance/billing' },
];

function formatDate(value: string): string {
    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export default function BillingIndex({
    stats,
    entries,
    clients = [],
    status_breakdown = {},
    filters = {},
}: Props) {
    const [search, setSearch] = useState(filters.q ?? '');
    const [range, setRange] = useState({
        from: filters.date_from ?? '',
        to: filters.date_to ?? '',
    });
    const [rangeOpen, setRangeOpen] = useState(false);
    const ctxMenu = useEntityContextMenu<BillingEntry>();

    const go = (patch: Filters) => {
        const next = { ...filters, ...patch };
        const query: Record<string, string> = {};
        if (next.q) query.q = String(next.q);
        if (next.status && next.status !== ALL) query.status = next.status;
        if (next.client_id && next.client_id !== ALL)
            query.client_id = String(next.client_id);
        if (next.date_from) query.date_from = next.date_from;
        if (next.date_to) query.date_to = next.date_to;

        router.get('/finance/billing', query, {
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

    const hasFilters = Boolean(
        filters.q ||
            filters.status ||
            filters.client_id ||
            filters.date_from ||
            filters.date_to,
    );

    const clearFilters = () => {
        setSearch('');
        setRange({ from: '', to: '' });
        router.get('/finance/billing', {}, { preserveState: true });
    };

    const totalEntries = Object.values(status_breakdown).reduce(
        (total, count) => total + count,
        0,
    );

    const rangeLabel =
        filters.date_from || filters.date_to
            ? `${filters.date_from ? formatDate(filters.date_from) : 'Start'} – ${
                  filters.date_to ? formatDate(filters.date_to) : 'Today'
              }`
            : 'Any date';

    const clientName = (entry: BillingEntry) =>
        entry.client
            ? `${entry.client.first_name} ${entry.client.last_name}`
            : 'Unknown client';

    const actionsFor = (entry: BillingEntry): MenuItem[] =>
        compactMenu([
            {
                label: 'View this client’s invoices',
                icon: Receipt,
                onClick: () =>
                    router.visit(
                        `/finance/invoices?search=${encodeURIComponent(
                            clientName(entry),
                        )}`,
                    ),
            },
            {
                label: 'View aged receivables',
                icon: UserIcon,
                onClick: () => router.visit('/finance/receivables'),
            },
        ]);

    const header = (
        <PageHeader
            variant="index"
            icon={Receipt}
            title="Billing"
            titleChip={
                stats.pending_count > 0 ? (
                    <PageHeaderStatusChip variant="warning">
                        {stats.pending_count} pending
                    </PageHeaderStatusChip>
                ) : (
                    <PageHeaderStatusChip variant="success">
                        Nothing pending
                    </PageHeaderStatusChip>
                )
            }
            subline={`Delivered support ready to invoice · ${totalEntries} entries · generated from approved timesheets`}
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search entries, clients…"
                />
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Billed this month"
                        href="/finance/billing?status=billed"
                        ariaLabel="View billed entries"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(stats.billed_this_month)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Approved, billed or paid
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Ready to invoice"
                        tone={stats.outstanding > 0 ? 'warning' : 'brand'}
                        href="/finance/billing?status=approved"
                        ariaLabel="View approved entries awaiting invoicing"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(stats.outstanding)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Approved, not yet invoiced
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Paid this month"
                        tone="success"
                        href="/finance/billing?status=paid"
                        ariaLabel="View paid entries"
                    >
                        <PageHeaderMeterBig>
                            {formatMoney(stats.paid_this_month)}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Settled service this month
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Pending approval"
                        tone={stats.pending_count > 0 ? 'warning' : 'brand'}
                        href="/finance/billing?status=pending"
                        ariaLabel="View entries pending approval"
                    >
                        <PageHeaderMeterBig>
                            {stats.pending_count}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Awaiting timesheet approval
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        label="Status"
                        value={filters.status || ALL}
                        allValue={ALL}
                        options={STATUS_OPTIONS}
                        onChange={(value) =>
                            go({ status: value === ALL ? undefined : value })
                        }
                    />
                    <PageHeaderFilterSelect
                        icon={UserIcon}
                        label="Client"
                        value={
                            filters.client_id ? String(filters.client_id) : ALL
                        }
                        allValue={ALL}
                        options={[
                            { value: ALL, label: 'Any client' },
                            ...clients.map((client) => ({
                                value: String(client.id),
                                label: `${client.first_name} ${client.last_name}`,
                            })),
                        ]}
                        onChange={(value) =>
                            go({ client_id: value === ALL ? undefined : value })
                        }
                    />
                    <Popover open={rangeOpen} onOpenChange={setRangeOpen}>
                        <PopoverTrigger asChild>
                            <PageHeaderFilterButton
                                icon={CalendarRange}
                                active={Boolean(
                                    filters.date_from || filters.date_to,
                                )}
                                aria-label="Filter by service date"
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
                                    go({
                                        date_from: range.from || undefined,
                                        date_to: range.to || undefined,
                                    });
                                }}
                            >
                                <div className="flex flex-col gap-1.5">
                                    <Label htmlFor="billing-from">From</Label>
                                    <Input
                                        id="billing-from"
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
                                    <Label htmlFor="billing-to">To</Label>
                                    <Input
                                        id="billing-to"
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
                                            go({
                                                date_from: undefined,
                                                date_to: undefined,
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
            <Head title="Billing" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title="Billing entries"
                        caption={`${entries.data.length} of ${entries.total} shown`}
                    />

                    {entries.data.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                onClear={clearFilters}
                                title="No billing entries match your filters"
                            />
                        ) : (
                            <EmptyList
                                icon={Receipt}
                                itemName="billing entry"
                                title="No billing entries yet"
                                description="Entries are generated automatically from approved timesheets."
                            />
                        )
                    ) : (
                        <>
                            <EntityCardGrid>
                                {entries.data.map((entry) => (
                                    <EntityCard
                                        key={entry.id}
                                        meridian={
                                            MERIDIAN[entry.status] ?? 'warning'
                                        }
                                        icon={Receipt}
                                        name={clientName(entry)}
                                        subline={`${formatDate(entry.service_date)} · ${entry.hours}h @ ${formatMoney(entry.rate)}/h`}
                                        sublineIcon={Clock}
                                        actions={actionsFor(entry)}
                                        onContextMenu={(e) =>
                                            ctxMenu.open(e, entry)
                                        }
                                        muted={entry.status === 'cancelled'}
                                        chips={
                                            <>
                                                <EntityStatusChip
                                                    variant={
                                                        entry.status === 'paid'
                                                            ? 'success'
                                                            : entry.status ===
                                                                'cancelled'
                                                              ? 'neutral'
                                                              : entry.status ===
                                                                  'pending'
                                                                ? 'warning'
                                                                : 'info'
                                                    }
                                                >
                                                    {entry.status
                                                        .charAt(0)
                                                        .toUpperCase() +
                                                        entry.status.slice(1)}
                                                </EntityStatusChip>
                                                <EntityChip outline>
                                                    {RATE_TYPE_LABELS[
                                                        entry.rate_type
                                                    ] ?? entry.rate_type}
                                                </EntityChip>
                                                {entry.service_agreement ? (
                                                    <EntityChip>
                                                        {
                                                            entry
                                                                .service_agreement
                                                                .title
                                                        }
                                                    </EntityChip>
                                                ) : null}
                                            </>
                                        }
                                        metric={{
                                            label: 'Amount',
                                            value: formatMoney(entry.amount),
                                            percent: null,
                                        }}
                                        footer={{
                                            personName:
                                                entry.staff?.name ?? null,
                                            primary:
                                                entry.staff?.name ??
                                                'Unassigned',
                                            secondary:
                                                entry.notes ?? 'Support worker',
                                        }}
                                    />
                                ))}
                            </EntityCardGrid>
                            <LaravelPagination
                                links={entries.links}
                                lastPage={entries.last_page}
                            />
                        </>
                    )}
                </div>
            </PageLayout>

            {ctxMenu.ctx ? (
                <EntityContextMenu
                    x={ctxMenu.ctx.x}
                    y={ctxMenu.ctx.y}
                    icon={Receipt}
                    title={clientName(ctxMenu.ctx.record)}
                    items={actionsFor(ctxMenu.ctx.record)}
                    onClose={ctxMenu.close}
                />
            ) : null}
        </AppLayout>
    );
}
