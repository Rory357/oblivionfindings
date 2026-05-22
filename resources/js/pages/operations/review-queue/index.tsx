import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { PageHero, PageLayout } from '@/components/page';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Building2,
    CheckCircle2,
    ClipboardList,
    Clock,
    Users,
} from 'lucide-react';
import { useState } from 'react';

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
    filters: { site: number | null; age: string };
};

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

export default function ReviewQueuePage({
    items,
    sites,
    stats,
    filters,
}: PageProps) {
    const [siteFilter, setSiteFilter] = useState<string>(
        filters.site ? String(filters.site) : 'all',
    );
    const [ageFilter, setAgeFilter] = useState<string>(filters.age || 'all');

    const applyFilters = (nextSite: string, nextAge: string) => {
        const params: Record<string, string> = {};
        if (nextSite !== 'all') params.site = nextSite;
        if (nextAge !== 'all') params.age = nextAge;
        router.get('/operations/review-queue', params, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const markReviewed = (item: ReviewItem) => {
        router.post(
            `/operations/clients/${item.client_id}/daily-notes/${item.id}/review`,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    router.reload({ only: ['items', 'stats'] }),
            },
        );
    };

    return (
        <AppLayout>
            <Head title="Review queue" />
            <PageLayout>
                <PageHero
                    icon={AlertTriangle}
                    title="Manager review queue"
                    description="Every flagged daily note across all clients in your scope. Mark each one reviewed as you work through it."
                    stats={[
                        { label: 'Open', value: stats.total },
                        { label: 'Critical', value: stats.critical },
                        { label: 'Clients', value: stats.clients },
                        { label: 'Sites', value: stats.sites },
                    ]}
                />

                <div className="space-y-6">
                    <div className="flex flex-wrap items-end gap-3">
                        <div className="flex flex-col gap-1">
                            <label className="text-xs text-muted-foreground">
                                Site
                            </label>
                            <Select
                                value={siteFilter}
                                onValueChange={(value) => {
                                    setSiteFilter(value);
                                    applyFilters(value, ageFilter);
                                }}
                            >
                                <SelectTrigger className="min-h-11 w-56">
                                    <SelectValue placeholder="All sites" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All sites
                                    </SelectItem>
                                    {sites.map((site) => (
                                        <SelectItem
                                            key={site.id}
                                            value={String(site.id)}
                                        >
                                            {site.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex flex-col gap-1">
                            <label className="text-xs text-muted-foreground">
                                Age
                            </label>
                            <Select
                                value={ageFilter}
                                onValueChange={(value) => {
                                    setAgeFilter(value);
                                    applyFilters(siteFilter, value);
                                }}
                            >
                                <SelectTrigger className="min-h-11 w-44">
                                    <SelectValue placeholder="Any age" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Any age</SelectItem>
                                    <SelectItem value="24h">
                                        Last 24 hours
                                    </SelectItem>
                                    <SelectItem value="7d">
                                        Last 7 days
                                    </SelectItem>
                                    <SelectItem value="30d">
                                        Last 30 days
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        {(siteFilter !== 'all' || ageFilter !== 'all') && (
                            <Button
                                variant="ghost"
                                onClick={() => {
                                    setSiteFilter('all');
                                    setAgeFilter('all');
                                    applyFilters('all', 'all');
                                }}
                            >
                                Clear filters
                            </Button>
                        )}
                    </div>

                    {items.data.length > 0 ? (
                        <div className="space-y-3">
                            {items.data.map((item) => {
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
                            description="No flagged daily notes are waiting for review across your clients."
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
