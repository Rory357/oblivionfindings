import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Filter, X } from 'lucide-react';
import { useState } from 'react';

type PaginatedData<T> = {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
};

type EventRecord = {
    id: number;
    client_id: number;
    event_type: string;
    severity: string;
    occurred_at: string;
    description: string;
    requires_followup: boolean;
    followup_completed_at: string | null;
    reviewed_at: string | null;
    status: string;
    client:
        | {
              id: number;
              first_name: string;
              last_name: string;
              site_id: number | null;
              site?: { id: number; name: string } | null;
          }
        | null;
    site: { id: number; name: string } | null;
    reporter: { id: number; name: string } | null;
    reviewer: { id: number; name: string } | null;
};

type Stats = {
    total_7d: number;
    total_30d: number;
    pending_follow_ups: number;
    unreviewed: number;
};

type SelectOption = {
    value: string;
    label: string;
};

type FilterOptions = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    sites: Array<{ id: number; name: string }>;
    event_types: SelectOption[];
    severities: SelectOption[];
    follow_up_statuses: SelectOption[];
    review_statuses: SelectOption[];
};

type Filters = {
    client_id?: string;
    event_type?: string;
    severity?: string;
    site_id?: string;
    follow_up_status?: string;
    review_status?: string;
    date_from?: string;
    date_to?: string;
};

type Props = {
    events: PaginatedData<EventRecord>;
    stats: Stats;
    filters: Filters;
    filter_options: FilterOptions;
};

const ALL_SENTINEL = '__all__';

const severityColor: Record<string, string> = {
    low: 'bg-status-info-bg text-status-info',
    medium: 'bg-status-warning-bg text-status-warning',
    high: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
};

function formatNzDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export default function EventRegister({
    events,
    stats,
    filters,
    filter_options,
}: Props) {
    const [local, setLocal] = useState<Filters>({
        client_id: filters.client_id ?? '',
        event_type: filters.event_type ?? '',
        severity: filters.severity ?? '',
        site_id: filters.site_id ?? '',
        follow_up_status: filters.follow_up_status ?? '',
        review_status: filters.review_status ?? '',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
    });

    const applyFilters = () => {
        const clean = Object.fromEntries(
            Object.entries(local).filter(([, value]) => value !== '' && value !== undefined),
        );

        router.get('/health-clinical/events', clean, {
            preserveState: true,
            replace: true,
        });
    };

    const clearFilters = () => {
        setLocal({});
        router.get('/health-clinical/events', {}, {
            preserveState: true,
            replace: true,
        });
    };

    const hasFilters = Object.values(local).some((value) => value !== '' && value !== undefined);

    const eventTypeLabel = (value: string) =>
        filter_options.event_types.find((type) => type.value === value)?.label ?? value;

    const siteName = (event: EventRecord) =>
        event.site?.name ?? event.client?.site?.name ?? '—';

    return (
        <AppLayout>
            <Head title="Clinical Event Register — Health & Clinical" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Clinical Event Register
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Cross-client oversight of recorded clinical events.
                        </p>
                    </div>
                    <Link href="/health-clinical">
                        <Button variant="outline" size="sm">
                            Dashboard
                        </Button>
                    </Link>
                </div>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div className="rounded-xl border bg-primary/10 p-4">
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-status-critical">
                            Last 7 days
                        </p>
                        <p className="mt-1 text-2xl font-bold text-status-critical">
                            {stats.total_7d}
                        </p>
                    </div>
                    <div className="rounded-xl border bg-status-warning-bg p-4">
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-status-warning">
                            Last 30 days
                        </p>
                        <p className="mt-1 text-2xl font-bold text-status-warning">
                            {stats.total_30d}
                        </p>
                    </div>
                    <div className="rounded-xl border bg-status-critical-bg p-4">
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-status-critical">
                            Pending follow-up
                        </p>
                        <p className="mt-1 text-2xl font-bold text-status-critical">
                            {stats.pending_follow_ups}
                        </p>
                    </div>
                    <div className="rounded-xl border bg-primary/10 p-4">
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                            Unreviewed
                        </p>
                        <p className="mt-1 text-2xl font-bold text-foreground">
                            {stats.unreviewed}
                        </p>
                    </div>
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-sm">
                            <Filter className="h-4 w-4" /> Filters
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                            <div>
                                <Label className="text-xs">Client</Label>
                                <Select
                                    value={local.client_id || ALL_SENTINEL}
                                    onValueChange={(value) =>
                                        setLocal((current) => ({
                                            ...current,
                                            client_id: value === ALL_SENTINEL ? '' : value,
                                        }))
                                    }
                                >
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue placeholder="All clients" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>All clients</SelectItem>
                                        {filter_options.clients.map((client) => (
                                            <SelectItem key={client.id} value={String(client.id)}>
                                                {client.first_name} {client.last_name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Type</Label>
                                <Select
                                    value={local.event_type || ALL_SENTINEL}
                                    onValueChange={(value) =>
                                        setLocal((current) => ({
                                            ...current,
                                            event_type: value === ALL_SENTINEL ? '' : value,
                                        }))
                                    }
                                >
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue placeholder="All types" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>All types</SelectItem>
                                        {filter_options.event_types.map((type) => (
                                            <SelectItem key={type.value} value={type.value}>
                                                {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Severity</Label>
                                <Select
                                    value={local.severity || ALL_SENTINEL}
                                    onValueChange={(value) =>
                                        setLocal((current) => ({
                                            ...current,
                                            severity: value === ALL_SENTINEL ? '' : value,
                                        }))
                                    }
                                >
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue placeholder="All severities" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>All severities</SelectItem>
                                        {filter_options.severities.map((severity) => (
                                            <SelectItem key={severity.value} value={severity.value}>
                                                {severity.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Follow-up</Label>
                                <Select
                                    value={local.follow_up_status || ALL_SENTINEL}
                                    onValueChange={(value) =>
                                        setLocal((current) => ({
                                            ...current,
                                            follow_up_status: value === ALL_SENTINEL ? '' : value,
                                        }))
                                    }
                                >
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue placeholder="Any status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>Any status</SelectItem>
                                        {filter_options.follow_up_statuses.map((status) => (
                                            <SelectItem key={status.value} value={status.value}>
                                                {status.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Review</Label>
                                <Select
                                    value={local.review_status || ALL_SENTINEL}
                                    onValueChange={(value) =>
                                        setLocal((current) => ({
                                            ...current,
                                            review_status: value === ALL_SENTINEL ? '' : value,
                                        }))
                                    }
                                >
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue placeholder="Any review status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>Any review status</SelectItem>
                                        {filter_options.review_statuses.map((status) => (
                                            <SelectItem key={status.value} value={status.value}>
                                                {status.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Site</Label>
                                <Select
                                    value={local.site_id || ALL_SENTINEL}
                                    onValueChange={(value) =>
                                        setLocal((current) => ({
                                            ...current,
                                            site_id: value === ALL_SENTINEL ? '' : value,
                                        }))
                                    }
                                >
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue placeholder="All sites" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>All sites</SelectItem>
                                        {filter_options.sites.map((site) => (
                                            <SelectItem key={site.id} value={String(site.id)}>
                                                {site.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">From</Label>
                                <Input
                                    type="date"
                                    className="h-8 text-xs"
                                    value={local.date_from ?? ''}
                                    onChange={(event) =>
                                        setLocal((current) => ({
                                            ...current,
                                            date_from: event.target.value,
                                        }))
                                    }
                                />
                            </div>
                            <div>
                                <Label className="text-xs">To</Label>
                                <Input
                                    type="date"
                                    className="h-8 text-xs"
                                    value={local.date_to ?? ''}
                                    onChange={(event) =>
                                        setLocal((current) => ({
                                            ...current,
                                            date_to: event.target.value,
                                        }))
                                    }
                                />
                            </div>
                        </div>
                        <div className="mt-3 flex gap-2">
                            <Button size="sm" onClick={applyFilters}>
                                Apply
                            </Button>
                            {hasFilters && (
                                <Button size="sm" variant="ghost" onClick={clearFilters} className="gap-1">
                                    <X className="h-3 w-3" /> Clear
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        {events.data.length === 0 ? (
                            <div className="p-8 text-center text-sm text-muted-foreground">
                                No clinical events match the selected filters.
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/40">
                                            <th className="px-4 py-3 text-left font-medium">Client</th>
                                            <th className="px-4 py-3 text-left font-medium">Type</th>
                                            <th className="px-4 py-3 text-left font-medium">Severity</th>
                                            <th className="px-4 py-3 text-left font-medium">Summary</th>
                                            <th className="px-4 py-3 text-left font-medium">Occurred</th>
                                            <th className="px-4 py-3 text-left font-medium">Reported by</th>
                                            <th className="px-4 py-3 text-left font-medium">Site</th>
                                            <th className="px-4 py-3 text-left font-medium">Follow-up</th>
                                            <th className="px-4 py-3 text-left font-medium">Review</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {events.data.map((event) => (
                                            <tr
                                                key={event.id}
                                                className={`border-b transition-colors hover:bg-muted/20 ${
                                                    event.severity === 'critical' ? 'bg-status-critical-bg' : ''
                                                }`}
                                            >
                                                <td className="px-4 py-3">
                                                    {event.client ? (
                                                        <Link
                                                            href={`/operations/clients/${event.client.id}`}
                                                            className="font-medium text-status-info hover:underline"
                                                        >
                                                            {event.client.first_name} {event.client.last_name}
                                                        </Link>
                                                    ) : (
                                                        <span className="text-muted-foreground">—</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge variant="outline" className="text-[10px]">
                                                        {eventTypeLabel(event.event_type)}
                                                    </Badge>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge className={`text-[10px] ${severityColor[event.severity] ?? ''}`}>
                                                        {event.severity}
                                                    </Badge>
                                                </td>
                                                <td className="max-w-[260px] truncate px-4 py-3 text-xs text-muted-foreground">
                                                    {event.description || '—'}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3 text-xs text-muted-foreground">
                                                    {formatNzDate(event.occurred_at)}
                                                </td>
                                                <td className="px-4 py-3 text-xs">
                                                    {event.reporter?.name ?? '—'}
                                                </td>
                                                <td className="px-4 py-3 text-xs text-muted-foreground">
                                                    {siteName(event)}
                                                </td>
                                                <td className="px-4 py-3 text-xs">
                                                    {!event.requires_followup ? (
                                                        <span className="text-muted-foreground">None</span>
                                                    ) : event.followup_completed_at ? (
                                                        <Badge variant="secondary" className="text-[10px]">
                                                            Complete
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="destructive" className="text-[10px]">
                                                            Pending
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-xs">
                                                    {event.reviewed_at ? (
                                                        <div className="space-y-1">
                                                            <Badge variant="secondary" className="text-[10px]">
                                                                Reviewed
                                                            </Badge>
                                                            <p className="text-muted-foreground">
                                                                {event.reviewer?.name ?? '—'}
                                                            </p>
                                                        </div>
                                                    ) : (
                                                        <span className="text-muted-foreground">Unreviewed</span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {events.last_page > 1 && (
                            <div className="flex items-center justify-between border-t px-4 py-3">
                                <p className="text-xs text-muted-foreground">
                                    Page {events.current_page} of {events.last_page} ({events.total} total)
                                </p>
                                <div className="flex gap-1">
                                    {events.links.map((link, index) => (
                                        <Button
                                            key={index}
                                            variant={link.active ? 'default' : 'outline'}
                                            size="sm"
                                            className="h-7 min-w-[28px] px-2 text-xs"
                                            disabled={!link.url}
                                            onClick={() =>
                                                link.url &&
                                                router.get(link.url, {}, { preserveState: true })
                                            }
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
