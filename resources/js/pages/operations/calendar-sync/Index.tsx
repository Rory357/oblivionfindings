import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Calendar,
    CheckCircle2,
    Clock,
    Globe,
    Link2,
    Pause,
    Plus,
    RefreshCw,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type SyncConnection = {
    id: number;
    provider: string;
    calendar_id: string | null;
    sync_direction: string;
    is_active: boolean;
    last_synced_at: string | null;
};

type Summary = {
    total: number;
    active: number;
    paused: number;
    /** Active connections with no successful sync in the last 24 hours. */
    stale: number;
    providers: number;
    last_synced_at: string | null;
};

type ViewKey = 'all' | 'active' | 'paused' | 'stale';

type Props = {
    connections: {
        data: SyncConnection[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: { status: string };
    summary: Summary;
};

const PROVIDER_LABELS: Record<string, string> = {
    google: 'Google Calendar',
    outlook: 'Microsoft Outlook',
    apple: 'Apple iCloud',
    caldav: 'CalDAV',
    ical: 'iCal feed',
};

const DIRECTION_LABELS: Record<string, string> = {
    push: 'Push only',
    pull: 'Pull only',
    both: 'Two-way',
};

function formatDate(d: string | null): string {
    if (!d) return 'Never';
    return new Date(d).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function timeAgo(iso: string): string {
    const diff = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}

const PROVIDER_OPTIONS = [
    { value: 'all', label: 'All providers' },
    ...Object.entries(PROVIDER_LABELS).map(([value, label]) => ({
        value,
        label,
    })),
];

const DIRECTION_OPTIONS = [
    { value: 'all', label: 'All directions' },
    ...Object.entries(DIRECTION_LABELS).map(([value, label]) => ({
        value,
        label,
    })),
];

export default function CalendarSyncIndex({
    connections = {
        data: [],
        links: [],
        current_page: 1,
        last_page: 1,
        total: 0,
    },
    filters,
    summary,
}: Props) {
    const view: ViewKey = (
        ['all', 'active', 'paused', 'stale'] as const
    ).includes(filters.status as ViewKey)
        ? (filters.status as ViewKey)
        : 'all';

    const [search, setSearch] = useState('');
    const [provider, setProvider] = useState('all');
    const [direction, setDirection] = useState('all');

    const selectView = (key: ViewKey) => {
        router.get(
            '/operations/calendar-sync',
            { status: key },
            { preserveState: true, replace: true },
        );
    };

    const triggerSync = (id: number) => {
        router.post(
            `/operations/calendar-sync/${id}/trigger`,
            {},
            { preserveState: true },
        );
    };

    const shown = useMemo(() => {
        const rows = connections?.data ?? [];
        const q = search.trim().toLowerCase();
        return rows.filter((conn) => {
            if (provider !== 'all' && conn.provider !== provider) return false;
            if (direction !== 'all' && conn.sync_direction !== direction) {
                return false;
            }
            if (q) {
                const hay =
                    `${PROVIDER_LABELS[conn.provider] ?? conn.provider} ${
                        conn.calendar_id ?? ''
                    } ${DIRECTION_LABELS[conn.sync_direction] ?? conn.sync_direction}`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
    }, [connections?.data, search, provider, direction]);

    const hasNarrowing =
        search.trim() !== '' || provider !== 'all' || direction !== 'all';

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        {
            key: 'all',
            label: 'All connections',
            icon: Link2,
            count: summary.total,
        },
        {
            key: 'active',
            label: 'Active',
            icon: CheckCircle2,
            count: summary.active,
        },
        { key: 'paused', label: 'Paused', icon: Pause, count: summary.paused },
        { key: 'stale', label: 'Stale', icon: Clock, count: summary.stale },
    ];

    const currentViewLabel =
        railItems.find((v) => v.key === view)?.label ?? 'All connections';
    const viewTotal = summary[view === 'all' ? 'total' : view];

    const header = (
        <PageHeader
            icon={Calendar}
            title="Calendar sync"
            titleChip={
                <PageHeaderStatusChip
                    variant={summary.active > 0 ? 'success' : 'neutral'}
                >
                    {summary.active} active
                </PageHeaderStatusChip>
            }
            subline={`Shift and schedule sync for your linked calendars · ${
                summary.total
            } ${summary.total === 1 ? 'connection' : 'connections'} · ${
                summary.providers
            } ${summary.providers === 1 ? 'provider' : 'providers'}`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search connections…"
                    />
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={() =>
                            router.visit('/operations/calendar-sync/create')
                        }
                    >
                        Add connection
                    </PageHeaderPrimaryButton>
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Connections"
                        ariaLabel="View all connections"
                        onClick={() => selectView('all')}
                    >
                        <PageHeaderMeterBig>{summary.total}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {summary.providers}{' '}
                            {summary.providers === 1 ? 'provider' : 'providers'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {summary.total > 0 ? (
                        <PageHeaderMeterBlock
                            label="Active"
                            ariaLabel="View active connections"
                            onClick={() => selectView('active')}
                        >
                            <PageHeaderMeterDonut
                                percent={(summary.active / summary.total) * 100}
                                caption={
                                    <>
                                        {summary.active} of {summary.total}
                                        <br />
                                        syncing
                                    </>
                                }
                            />
                        </PageHeaderMeterBlock>
                    ) : null}
                    <PageHeaderMeterBlock
                        label="Stale"
                        tone={summary.stale > 0 ? 'warning' : 'success'}
                        ariaLabel="View stale connections"
                        onClick={() => selectView('stale')}
                    >
                        <PageHeaderMeterBig>{summary.stale}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            no sync in the last 24 h
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    {summary.last_synced_at ? (
                        <PageHeaderMeterBlock
                            label="Last sync"
                            ariaLabel="View all connections by last sync"
                            onClick={() => selectView('all')}
                        >
                            <PageHeaderMeterBig>
                                {timeAgo(summary.last_synced_at)}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                most recent successful sync
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ) : null}
                </>
            }
            filters={
                <>
                    <PageHeaderFilterSelect
                        icon={Globe}
                        label="All providers"
                        value={provider}
                        options={PROVIDER_OPTIONS}
                        onChange={setProvider}
                    />
                    <PageHeaderFilterSelect
                        icon={ArrowLeftRight}
                        label="All directions"
                        value={direction}
                        options={DIRECTION_OPTIONS}
                        onChange={setDirection}
                    />
                </>
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={selectView}
                    ariaLabel="Connection views"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                { title: 'Calendar sync', href: '/operations/calendar-sync' },
            ]}
        >
            <Head title="Calendar sync" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${shown.length} of ${viewTotal} ${
                            viewTotal === 1 ? 'connection' : 'connections'
                        } shown`}
                    />

                    <div className="space-y-2">
                        {shown.length === 0 ? (
                            <EmptyState
                                icon={Calendar}
                                title="No calendar connections"
                                description={
                                    hasNarrowing
                                        ? 'Try clearing a filter or search term.'
                                        : 'Add a calendar connection to sync shifts and schedules.'
                                }
                                action={
                                    hasNarrowing ? undefined : (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                router.visit(
                                                    '/operations/calendar-sync/create',
                                                )
                                            }
                                        >
                                            <Plus className="h-3.5 w-3.5" />
                                            Add connection
                                        </Button>
                                    )
                                }
                            />
                        ) : (
                            shown.map((conn) => (
                                <Card
                                    key={conn.id}
                                    className="transition-all hover:border-border hover:shadow-sm"
                                >
                                    <CardContent className="flex items-center gap-4 p-4">
                                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                            <Calendar className="h-5 w-5" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="text-sm font-semibold">
                                                    {PROVIDER_LABELS[
                                                        conn.provider
                                                    ] ?? conn.provider}
                                                </span>
                                                <StatusBadge
                                                    variant={
                                                        conn.is_active
                                                            ? 'success'
                                                            : 'neutral'
                                                    }
                                                >
                                                    {conn.is_active
                                                        ? 'Active'
                                                        : 'Paused'}
                                                </StatusBadge>
                                                <Badge
                                                    variant="outline"
                                                    className="h-4 px-1.5 text-[9px]"
                                                >
                                                    {DIRECTION_LABELS[
                                                        conn.sync_direction
                                                    ] ?? conn.sync_direction}
                                                </Badge>
                                            </div>
                                            <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                                <span className="truncate">
                                                    {conn.calendar_id ??
                                                        'Primary calendar'}
                                                </span>
                                                <span className="flex shrink-0 items-center gap-1">
                                                    <Clock className="h-3 w-3" />
                                                    Last synced:{' '}
                                                    {formatDate(
                                                        conn.last_synced_at,
                                                    )}
                                                </span>
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 gap-1">
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                className="h-7 px-2 text-xs"
                                                onClick={() =>
                                                    triggerSync(conn.id)
                                                }
                                            >
                                                <RefreshCw className="mr-1 h-3 w-3" />{' '}
                                                Sync now
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))
                        )}
                    </div>

                    {(connections?.last_page ?? 1) > 1 && (
                        <div className="flex items-center justify-center gap-1">
                            {(connections?.links ?? []).map((link, i) => (
                                <Button
                                    key={i}
                                    size="sm"
                                    variant={
                                        link.active ? 'default' : 'outline'
                                    }
                                    className="h-7 min-w-[28px] px-2 text-xs"
                                    disabled={!link.url}
                                    onClick={() =>
                                        link.url &&
                                        router.get(
                                            link.url,
                                            {},
                                            { preserveState: true },
                                        )
                                    }
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </PageLayout>
        </AppLayout>
    );
}
