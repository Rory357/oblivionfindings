import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptySearch, EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Bell,
    Loader,
    Search,
    Zap,
} from 'lucide-react';
import { useState } from 'react';

import {
    type FilterOption,
    type Paginated,
    FilterSelect,
    StatCard,
} from './devices/shared';

// ── Types ─────────────────────────────────────────────────────────

type EventItem = {
    id: number;
    device_id: number;
    device_name: string | null;
    device_uid: string | null;
    device_domain: string | null;
    event_type: string;
    severity: string;
    source: string | null;
    occurred_at: string;
    processed_at: string | null;
    payload: Record<string, unknown> | null;
};

type Props = {
    pageMeta?: { title: string; description: string; href: string };
    stats: {
        total24h: number;
        critical24h: number;
        warning24h: number;
        unprocessed: number;
    };
    events: Paginated<EventItem>;
    filters: Record<string, string>;
    filterOptions: {
        eventTypes: string[];
        sources: string[];
        domains: FilterOption[];
    };
};

// ── Helpers ───────────────────────────────────────────────────────

function severityVariant(
    severity: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (severity) {
        case 'critical':
            return 'destructive';
        case 'warning':
            return 'outline';
        default:
            return 'secondary';
    }
}

function severityIcon(severity: string) {
    switch (severity) {
        case 'critical':
            return <AlertTriangle className="h-3.5 w-3.5" />;
        case 'warning':
            return <Zap className="h-3.5 w-3.5" />;
        default:
            return <Activity className="h-3.5 w-3.5" />;
    }
}

function formatDateTime(iso: string | null): string {
    if (!iso) return '-';
    return new Date(iso).toLocaleString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

function formatTimeSince(iso: string): string {
    const diff = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'Just now';
    if (mins < 60) return `${mins}m ago`;
    const hours = Math.floor(mins / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    return `${days}d ago`;
}

// ── Component ─────────────────────────────────────────────────────

export default function AlertsEvents({
    pageMeta,
    stats,
    events,
    filters,
    filterOptions,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const pageTitle = pageMeta?.title ?? 'Alerts & Events';
    const pageDescription =
        pageMeta?.description ??
        'Read-only device event stream. For alert triage and escalation, use Control Room.';
    const pageUrl = pageMeta?.href ?? '/security-devices/alerts-events';

    const applyFilters = (newFilters: Record<string, string>) => {
        router.get(
            pageUrl,
            { ...filters, ...newFilters, page: '1' },
            { preserveState: true },
        );
    };

    const clearFilters = () => {
        router.get(pageUrl, {}, { preserveState: true });
        setSearch('');
    };

    const hasActiveFilters = Object.values(filters).some(
        (v) => v && v !== 'all' && v !== '',
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                { title: pageTitle, href: pageUrl },
            ]}
        >
            <Head title={`${pageTitle} - Security & Devices`} />

            <PageShell>
                <PageHero
                    icon={Bell}
                    title={pageTitle}
                    description={pageDescription}
                    stats={[
                        { label: 'Events (24h)', value: stats.total24h },
                        { label: 'Critical', value: stats.critical24h },
                        { label: 'Warning', value: stats.warning24h },
                        { label: 'Unprocessed', value: stats.unprocessed },
                    ]}
                />

                {/* Stats */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Events (24h)"
                        value={stats.total24h}
                        icon={Activity}
                    />
                    <StatCard
                        label="Critical (24h)"
                        value={stats.critical24h}
                        icon={AlertTriangle}
                        variant={stats.critical24h > 0 ? 'warning' : 'default'}
                    />
                    <StatCard
                        label="Warning (24h)"
                        value={stats.warning24h}
                        icon={Zap}
                        variant={stats.warning24h > 0 ? 'warning' : 'default'}
                    />
                    <StatCard
                        label="Unprocessed"
                        value={stats.unprocessed}
                        icon={Loader}
                        variant={stats.unprocessed > 0 ? 'warning' : 'default'}
                    />
                </div>

                {/* Filters */}
                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                    <div className="relative flex-1 sm:max-w-xs">
                        <Search className="absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search event type, source, device..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) =>
                                e.key === 'Enter' && applyFilters({ search })
                            }
                            className="pl-9"
                        />
                    </div>

                    <FilterSelect
                        value={filters.severity}
                        onChange={(v) => applyFilters({ severity: v })}
                        placeholder="Severity"
                        options={[
                            { value: 'critical', label: 'Critical' },
                            { value: 'warning', label: 'Warning' },
                            { value: 'info', label: 'Info' },
                        ]}
                    />
                    {filterOptions.eventTypes.length > 0 && (
                        <FilterSelect
                            value={filters.event_type}
                            onChange={(v) => applyFilters({ event_type: v })}
                            placeholder="Event type"
                            options={filterOptions.eventTypes.map((t) => ({
                                value: t,
                                label: t.replace(/_/g, ' '),
                            }))}
                        />
                    )}
                    <FilterSelect
                        value={filters.domain}
                        onChange={(v) => applyFilters({ domain: v })}
                        placeholder="Domain"
                        options={filterOptions.domains}
                    />
                    {filterOptions.sources.length > 0 && (
                        <FilterSelect
                            value={filters.source}
                            onChange={(v) => applyFilters({ source: v })}
                            placeholder="Source"
                            options={filterOptions.sources.map((s) => ({
                                value: s,
                                label: s,
                            }))}
                        />
                    )}
                    <FilterSelect
                        value={filters.processed}
                        onChange={(v) => applyFilters({ processed: v })}
                        placeholder="Processed"
                        options={[
                            { value: 'yes', label: 'Processed' },
                            { value: 'no', label: 'Unprocessed' },
                        ]}
                    />

                    {/* Date range */}
                    <Input
                        type="date"
                        value={filters.from ?? ''}
                        onChange={(e) => applyFilters({ from: e.target.value })}
                        className="w-[140px]"
                        placeholder="From"
                    />
                    <Input
                        type="date"
                        value={filters.to ?? ''}
                        onChange={(e) => applyFilters({ to: e.target.value })}
                        className="w-[140px]"
                        placeholder="To"
                    />

                    {hasActiveFilters && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={clearFilters}
                        >
                            Clear
                        </Button>
                    )}
                </div>

                {/* Event list */}
                {events.data.length > 0 ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Device Events</CardTitle>
                            <CardDescription>
                                {events.meta.total} event
                                {events.meta.total !== 1 ? 's' : ''} found
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="space-y-1">
                                {events.data.map((evt) => (
                                    <div
                                        key={evt.id}
                                        className={`flex items-start gap-3 rounded-md border p-3 text-sm ${
                                            evt.severity === 'critical'
                                                ? 'border-status-critical/30 bg-status-critical-bg dark:border-status-critical/30'
                                                : evt.severity === 'warning'
                                                  ? 'border-status-warning/30 bg-status-warning-bg dark:border-status-warning/30'
                                                  : ''
                                        }`}
                                    >
                                        <div className="mt-0.5 shrink-0">
                                            {severityIcon(evt.severity)}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge
                                                    variant={severityVariant(
                                                        evt.severity,
                                                    )}
                                                    className="text-[10px]"
                                                >
                                                    {evt.severity}
                                                </Badge>
                                                <span className="font-medium">
                                                    {evt.event_type.replace(
                                                        /_/g,
                                                        ' ',
                                                    )}
                                                </span>
                                                {evt.source && (
                                                    <span className="text-xs text-muted-foreground">
                                                        via {evt.source}
                                                    </span>
                                                )}
                                                {!evt.processed_at && (
                                                    <Badge
                                                        variant="outline"
                                                        className="text-[10px]"
                                                    >
                                                        unprocessed
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="mt-1 flex flex-wrap items-center gap-x-4 text-xs text-muted-foreground">
                                                {evt.device_name && (
                                                    <Link
                                                        href={`/security-devices/devices/${evt.device_id}`}
                                                        className="text-primary hover:underline"
                                                    >
                                                        {evt.device_name} (
                                                        {evt.device_uid})
                                                    </Link>
                                                )}
                                                <span
                                                    title={formatDateTime(
                                                        evt.occurred_at,
                                                    )}
                                                >
                                                    {formatTimeSince(
                                                        evt.occurred_at,
                                                    )}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                ) : hasActiveFilters ? (
                    <EmptySearch
                        onClear={clearFilters}
                        title="No matching events"
                    />
                ) : (
                    <EmptyState
                        icon={Bell}
                        title="No device events"
                        description="Device events will appear here as devices report activity through integrations or manual logging."
                        variant="compact"
                    />
                )}

                {/* Pagination */}
                {(events.meta.last_page ?? 1) > 1 && (
                    <div className="flex items-center justify-center gap-1">
                        {events.links.map((link, i) => (
                            <Button
                                key={i}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={!link.url}
                                onClick={() =>
                                    link.url &&
                                    router.get(
                                        link.url,
                                        {},
                                        { preserveState: true },
                                    )
                                }
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
