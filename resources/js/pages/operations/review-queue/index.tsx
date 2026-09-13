import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Building2,
    CalendarRange,
    CheckCircle2,
    ClipboardList,
    Clock,
    Layers,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type ReviewItem = {
    id: number;
    client_id: number;
    client_name: string;
    site_name?: string | null;
    site_id?: number | null;
    subject?: string | null;
    body?: string | null;
    category?: string | null;
    flagged_reason?: string | null;
    mood_rating?: number | null;
    created_at?: string | null;
    hours_open: number;
    age_severity: 'critical' | 'warning' | 'info';
    author?: { id: number; name: string } | null;
    deep_link: string;
};

type SiteOption = { id: number; name: string };

type Stats = {
    total: number;
    critical: number;
    warning: number;
    sites: number;
    clients: number;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedItems = {
    data: ReviewItem[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
};

type PageProps = {
    items: PaginatedItems;
    sites: SiteOption[];
    stats: Stats;
    filters: { site: number | null; age: string; severity?: string };
};

type ViewKey = 'all' | 'critical' | 'warning' | 'recent';

function formatHours(hours: number): string {
    if (hours < 1) return 'just now';
    if (hours < 24) return `${hours}h open`;
    const days = Math.floor(hours / 24);
    return `${days}d open`;
}

const severityClass = {
    critical: 'border-l-status-critical bg-status-critical-bg/30',
    warning: 'border-l-status-warning bg-status-warning-bg/30',
    info: 'border-l-status-info bg-status-info-bg/30',
};

const severityIcon = {
    critical: AlertTriangle,
    warning: Clock,
    info: ClipboardList,
};

const AGE_OPTIONS = [
    { value: 'all', label: 'Any age' },
    { value: '24h', label: 'Last 24 hours' },
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
];

export default function ReviewQueuePage({
    items,
    sites,
    stats,
    filters,
}: PageProps) {
    const [search, setSearch] = useState('');

    const view: ViewKey = (['critical', 'warning', 'recent'] as const).includes(
        filters.severity as any,
    )
        ? (filters.severity as ViewKey)
        : 'all';

    const applyFilters = (overrides: {
        view?: ViewKey;
        site?: string;
        age?: string;
    }) => {
        const nextView = overrides.view ?? view;
        const nextSite =
            overrides.site ?? (filters.site ? String(filters.site) : 'all');
        const nextAge = overrides.age ?? (filters.age || 'all');
        const params: Record<string, string> = {};
        if (nextView !== 'all') params.severity = nextView;
        if (nextSite !== 'all') params.site = nextSite;
        if (nextAge !== 'all') params.age = nextAge;
        router.get('/operations/review-queue', params, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    const markReviewed = (item: ReviewItem) => {
        router.post(
            `/operations/clients/${item.client_id}/daily-notes/${item.id}/review`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => router.reload({ only: ['items', 'stats'] }),
            },
        );
    };

    const shown = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return items.data;
        return items.data.filter((item) =>
            `${item.client_name} ${item.site_name ?? ''} ${
                item.subject ?? ''
            } ${item.body ?? ''} ${item.flagged_reason ?? ''} ${
                item.author?.name ?? ''
            }`
                .toLowerCase()
                .includes(q),
        );
    }, [items.data, search]);

    const recentCount = Math.max(
        0,
        stats.total - stats.critical - stats.warning,
    );

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        { key: 'all', label: 'All open', icon: Layers, count: stats.total },
        {
            key: 'critical',
            label: 'Open 48h+',
            icon: AlertTriangle,
            count: stats.critical,
            alert: true,
        },
        {
            key: 'warning',
            label: 'Open 24–48h',
            icon: Clock,
            count: stats.warning,
        },
        {
            key: 'recent',
            label: 'Under 24h',
            icon: ClipboardList,
            count: recentCount,
        },
    ];

    const currentViewLabel =
        railItems.find((v) => v.key === view)?.label ?? 'All open';
    const viewTotal =
        view === 'critical'
            ? stats.critical
            : view === 'warning'
              ? stats.warning
              : view === 'recent'
                ? recentCount
                : stats.total;

    const titleChip =
        stats.critical > 0 ? (
            <PageHeaderStatusChip variant="critical">
                {stats.critical} open 48h+
            </PageHeaderStatusChip>
        ) : stats.warning > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {stats.warning} ageing
            </PageHeaderStatusChip>
        ) : stats.total > 0 ? (
            <PageHeaderStatusChip variant="info">
                {stats.total} to review
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="success">
                Inbox zero
            </PageHeaderStatusChip>
        );

    const siteOptions = [
        { value: 'all', label: 'All sites' },
        ...sites.map((s) => ({ value: String(s.id), label: s.name })),
    ];

    const header = (
        <PageHeader
            icon={AlertTriangle}
            title="Review queue"
            titleChip={titleChip}
            subline={`Flagged daily notes awaiting manager review · ${stats.clients} ${
                stats.clients === 1 ? 'client' : 'clients'
            } · ${stats.sites} ${stats.sites === 1 ? 'site' : 'sites'}`}
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search flagged notes…"
                />
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Open"
                        ariaLabel="View all open items"
                        onClick={() => applyFilters({ view: 'all' })}
                    >
                        <PageHeaderMeterBig>{stats.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            flagged notes awaiting review
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Open 48h+"
                        tone={stats.critical > 0 ? 'critical' : 'success'}
                        ariaLabel="View items open more than 48 hours"
                        onClick={() => applyFilters({ view: 'critical' })}
                    >
                        <PageHeaderMeterBig>
                            {stats.critical}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            waiting two days or more
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Open 24–48h"
                        tone={stats.warning > 0 ? 'warning' : 'success'}
                        ariaLabel="View items open 24 to 48 hours"
                        onClick={() => applyFilters({ view: 'warning' })}
                    >
                        <PageHeaderMeterBig>{stats.warning}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            ageing past one day
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Clients affected"
                        ariaLabel="View all open items"
                        onClick={() => applyFilters({ view: 'all' })}
                    >
                        <PageHeaderMeterBig>{stats.clients}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {stats.sites}{' '}
                            {stats.sites === 1 ? 'site' : 'sites'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={Building2}
                        label="All sites"
                        value={filters.site ? String(filters.site) : 'all'}
                        options={siteOptions}
                        onChange={(v) => applyFilters({ site: v })}
                    />
                    <PageHeaderFilterSelect
                        icon={CalendarRange}
                        label="Any age"
                        value={filters.age || 'all'}
                        options={AGE_OPTIONS}
                        onChange={(v) => applyFilters({ age: v })}
                    />
                </>
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={(key) => applyFilters({ view: key })}
                    ariaLabel="Queue views"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                {
                    title: 'Review queue',
                    href: '/operations/review-queue',
                },
            ]}
        >
            <Head title="Review queue" />

            <PageLayout hero={header}>
                <div className="space-y-5">
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${shown.length} of ${viewTotal} shown`}
                    />

                    {shown.length > 0 ? (
                        <div className="space-y-3">
                            {shown.map((item) => {
                                const Icon = severityIcon[item.age_severity];
                                return (
                                    <Card
                                        key={`${item.client_id}-${item.id}`}
                                        className={cn(
                                            'border-l-4',
                                            severityClass[item.age_severity],
                                        )}
                                    >
                                        <CardHeader className="pb-3">
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <CardTitle className="flex flex-wrap items-center gap-2 text-base">
                                                        <Icon className="h-4 w-4" />
                                                        <Link
                                                            href={`/operations/clients/${item.client_id}`}
                                                            className="text-primary hover:underline"
                                                        >
                                                            {item.client_name}
                                                        </Link>
                                                        {item.site_name ? (
                                                            <Badge
                                                                variant="outline"
                                                                className="gap-1"
                                                            >
                                                                <Building2 className="h-3 w-3" />
                                                                {item.site_name}
                                                            </Badge>
                                                        ) : null}
                                                        {item.category ? (
                                                            <Badge
                                                                variant="secondary"
                                                                className="capitalize"
                                                            >
                                                                {item.category}
                                                            </Badge>
                                                        ) : null}
                                                    </CardTitle>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {formatHours(
                                                            item.hours_open,
                                                        )}
                                                        {item.author
                                                            ? ` · ${item.author.name}`
                                                            : ''}
                                                    </p>
                                                </div>
                                                <div className="flex gap-2">
                                                    <Button
                                                        size="sm"
                                                        onClick={() =>
                                                            markReviewed(item)
                                                        }
                                                    >
                                                        <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                                                        Mark reviewed
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={
                                                                item.deep_link
                                                            }
                                                        >
                                                            Open note
                                                            <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
                                                        </Link>
                                                    </Button>
                                                </div>
                                            </div>
                                        </CardHeader>
                                        <CardContent className="pt-0 text-sm">
                                            {item.subject ? (
                                                <p className="font-medium">
                                                    {item.subject}
                                                </p>
                                            ) : null}
                                            {item.flagged_reason ? (
                                                <p className="mt-1 text-status-warning">
                                                    Flag: {item.flagged_reason}
                                                </p>
                                            ) : null}
                                            {item.body ? (
                                                <p className="mt-2 line-clamp-3 text-muted-foreground">
                                                    {item.body}
                                                </p>
                                            ) : null}
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    ) : (
                        <EmptyState
                            icon={Users}
                            title="Inbox zero"
                            description={
                                search.trim() !== '' || view !== 'all'
                                    ? 'No flagged notes match this view or your search.'
                                    : 'No flagged daily notes are waiting for review across your clients.'
                            }
                        />
                    )}

                    {items.last_page > 1 ? (
                        <div className="flex items-center justify-between gap-3 pt-2">
                            <p className="text-xs text-muted-foreground">
                                Showing {items.from ?? 0}–{items.to ?? 0} of{' '}
                                {items.total}
                            </p>
                            <LaravelPagination links={items.links} />
                        </div>
                    ) : null}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
