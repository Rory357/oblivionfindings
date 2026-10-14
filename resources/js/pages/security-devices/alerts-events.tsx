import {
    EntityChip,
    type EntityTableColumn,
    type MenuItem,
} from '@/components/lists';
import { EvidenceRecords } from '@/components/lists/evidence-records';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page';
import { ControlRoomAlertAccessRequired } from '@/components/security-devices/permission-destinations';
import { Button } from '@/components/ui/button';
import { EmptySearch, EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewRow } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    Bell,
    CheckCircle2,
    Clock3,
    Eye,
    LayoutGrid,
    List,
    RefreshCw,
    Search,
} from 'lucide-react';
import { useState } from 'react';
import type { FilterOption, Paginated } from './devices/shared';

export type EventItem = {
    id: number;
    device_id: number;
    device_name: string | null;
    device_uid: string | null;
    device_domain: string | null;
    device_href: string | null;
    device_access: {
        state: 'available' | 'restricted';
        metadata_state: 'available' | 'restricted';
        label: string;
    };
    event_type: string;
    severity: string;
    source: string | null;
    occurred_at: string | null;
    processed_at: string | null;
};
export type EventsProps = {
    pageMeta?: { title: string; description: string; href: string };
    stats: {
        total24h: number;
        critical24h: number;
        warning24h: number;
        unprocessed: number;
    };
    snapshotAt?: string | null;
    windowStart?: string | null;
    controlRoom?: { href: string | null };
    events: Paginated<EventItem>;
    filters: Record<string, string>;
    filterOptions: {
        eventTypes: string[];
        sources: string[];
        domains: FilterOption[];
    };
};
type EventView = 'history' | 'all' | 'critical' | 'warning' | 'unprocessed';
const words = (value: string | null) =>
    value ? value.replaceAll('_', ' ').replaceAll('.', ' · ') : 'Not recorded';
const eventName = (event: EventItem) =>
    `Event #${event.id}: ${words(event.event_type)}`;
const deviceHref = (event: EventItem) =>
    event.device_access?.state === 'available' &&
    event.device_access.metadata_state === 'available' &&
    event.device_href === `/security-devices/devices/${event.device_id}`
        ? event.device_href
        : null;
function Severity({ event }: { event: EventItem }) {
    return (
        <StatusBadge
            variant={
                event.severity === 'critical'
                    ? 'critical'
                    : event.severity === 'warning'
                      ? 'warning'
                      : event.severity === 'info'
                        ? 'info'
                        : 'neutral'
            }
            label={
                event.severity === 'info'
                    ? 'Information'
                    : words(event.severity)
            }
        />
    );
}
function Processing({ event }: { event: EventItem }) {
    return (
        <StatusBadge
            variant="neutral"
            label={event.processed_at ? 'Processed' : 'Not processed'}
        />
    );
}
function EventTime({ value }: { value: string | null }) {
    return value ? (
        <span title={formatDateTime(value)}>{formatRelative(value)}</span>
    ) : (
        <span>Time not recorded</span>
    );
}
function permittedEvent(event: EventItem): EventItem {
    return event.device_access?.metadata_state === 'available'
        ? event
        : {
              ...event,
              device_name: null,
              device_uid: null,
              device_domain: null,
              device_href: null,
              device_access: {
                  state: 'restricted',
                  metadata_state: 'restricted',
                  label: 'Current device details restricted',
              },
          };
}
export default function AlertsEvents(props: EventsProps) {
    const [layout, setLayout] = useState<'cards' | 'table'>('cards');
    const [datesOpen, setDatesOpen] = useState(false);
    const { auth } = usePage<{
        auth?: { user?: { id: number } | null; can?: Record<string, unknown> };
    }>().props;
    return (
        <EventStream
            key={JSON.stringify([auth?.user?.id, auth?.can, props])}
            {...props}
            events={{
                ...props.events,
                data: props.events.data.map(permittedEvent),
            }}
            layout={layout}
            setLayout={setLayout}
            datesOpen={datesOpen}
            setDatesOpen={setDatesOpen}
        />
    );
}
function EventStream({
    pageMeta,
    stats,
    snapshotAt,
    windowStart,
    controlRoom,
    events,
    filters,
    filterOptions,
    layout,
    setLayout,
    datesOpen,
    setDatesOpen,
}: EventsProps & {
    layout: 'cards' | 'table';
    setLayout: (value: 'cards' | 'table') => void;
    datesOpen: boolean;
    setDatesOpen: (open: boolean) => void;
}) {
    const title = pageMeta?.title ?? 'Alerts & events';
    const pageUrl = pageMeta?.href ?? '/security-devices/alerts-events';
    const [search, setSearch] = useState(filters.search ?? '');
    const hasFilters = Object.values(filters).some(
        (value) => value && value !== 'all',
    );
    const apply = (next: Record<string, string>) =>
        router.get(
            pageUrl,
            { ...filters, ...next, page: '1' },
            { preserveState: true },
        );
    const clear = () => {
        setSearch('');
        router.get(pageUrl, {}, { preserveState: true });
    };
    const selectSummary = (view: EventView) => {
        setSearch('');
        router.get(
            pageUrl,
            view === 'history'
                ? { page: '1' }
                : view === 'unprocessed'
                  ? { processed: 'no', page: '1' }
                  : {
                        window: '24h',
                        ...(view === 'all' ? {} : { severity: view }),
                        page: '1',
                    },
            { preserveState: true },
        );
    };
    const narrowed = Object.entries(filters).some(
        ([key, value]) =>
            !['window', 'severity', 'processed'].includes(key) &&
            value &&
            value !== 'all',
    );
    const view: EventView = narrowed
        ? 'history'
        : filters.processed === 'no' &&
            filters.window !== '24h' &&
            (!filters.severity || filters.severity === 'all')
          ? 'unprocessed'
          : filters.window === '24h' &&
              (!filters.processed || filters.processed === 'all')
            ? filters.severity === 'critical'
                ? 'critical'
                : filters.severity === 'warning'
                  ? 'warning'
                  : !filters.severity || filters.severity === 'all'
                    ? 'all'
                    : 'history'
            : 'history';
    const summaryViews = [
        { key: 'all' as const, label: 'Last 24 hours', count: stats.total24h },
        {
            key: 'critical' as const,
            label: 'Critical',
            count: stats.critical24h,
        },
        { key: 'warning' as const, label: 'Warning', count: stats.warning24h },
        {
            key: 'unprocessed' as const,
            label: 'Not processed',
            count: stats.unprocessed,
        },
    ];
    const ownerHref =
        controlRoom?.href &&
        ['/control-room', '/control-room/alerts'].includes(controlRoom.href)
            ? controlRoom.href
            : null;
    const actionsFor = (event: EventItem): MenuItem[] => [
        {
            label: 'Open device profile',
            icon: Eye,
            onClick: () => {
                const href = deviceHref(event);
                if (href) router.visit(href);
            },
            ...(deviceHref(event)
                ? {}
                : {
                      disabled:
                          event.device_access?.label ||
                          'Device profile access required',
                  }),
        },
    ];
    const scan: EntityTableColumn<EventItem>[] = [
        {
            key: 'severity',
            label: 'Severity',
            width: '110px',
            cell: (event) => <Severity event={event} />,
        },
        {
            key: 'device',
            label: 'Device',
            width: '180px',
            cell: (event) =>
                event.device_name ||
                event.device_access?.label ||
                'Current device unavailable',
        },
        {
            key: 'source',
            label: 'Source',
            width: '120px',
            cell: (event) => event.source || 'Not recorded',
        },
        {
            key: 'occurred',
            label: 'Occurred',
            width: '120px',
            cell: (event) => <EventTime value={event.occurred_at} />,
        },
        {
            key: 'processed',
            label: 'Processing',
            width: '120px',
            cell: (event) => <Processing event={event} />,
        },
    ];
    const details: EntityTableColumn<EventItem>[] = [
        {
            key: 'details',
            label: 'Event details',
            width: '1fr',
            cell: (event) => (
                <div className="space-y-1">
                    <ReviewRow label="Event reference" value={`#${event.id}`} />
                    <ReviewRow label="Event type" value={event.event_type} />
                    <ReviewRow
                        label="Severity"
                        value={<Severity event={event} />}
                    />
                    <ReviewRow
                        label="Source"
                        value={event.source || 'Not recorded'}
                    />
                    <ReviewRow
                        label="Occurred (NZ time)"
                        value={
                            event.occurred_at
                                ? formatDateTime(event.occurred_at)
                                : 'Time not recorded'
                        }
                    />
                    <ReviewRow
                        label="Processing"
                        value={<Processing event={event} />}
                    />
                    <ReviewRow
                        label="Processed (NZ time)"
                        value={
                            event.processed_at
                                ? formatDateTime(event.processed_at)
                                : 'No processing time recorded'
                        }
                    />
                    <ReviewRow
                        label="Device reference"
                        value={`#${event.device_id}`}
                    />
                    <ReviewRow
                        label="Current device"
                        value={
                            event.device_name ||
                            event.device_access?.label ||
                            'Current device unavailable'
                        }
                    />
                    <ReviewRow
                        label="Device identifier"
                        value={event.device_uid || 'Not available'}
                    />
                    <ReviewRow
                        label="Device area"
                        value={
                            event.device_domain
                                ? words(event.device_domain)
                                : 'Not available'
                        }
                    />
                    <ReviewRow
                        label="Device profile"
                        value={
                            deviceHref(event) ? (
                                <Link
                                    href={deviceHref(event)!}
                                    className="frontline-focus rounded-sm text-primary hover:underline"
                                >
                                    Open device profile
                                </Link>
                            ) : (
                                event.device_access?.label ||
                                'Device profile access required'
                            )
                        }
                    />
                </div>
            ),
        },
    ];
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Security & Devices', href: '/security-devices' },
                { title, href: pageUrl },
            ]}
        >
            <Head title={`${title} - Security & Devices`} />
            <div className="space-y-5">
                <PageHeader
                    icon={Bell}
                    title={title}
                    subline={
                        pageMeta?.description ||
                        'Read-only device events within your permitted history. Use Control Room for alert triage and escalation.'
                    }
                    mobileSummary={`${events.meta.total} matching events · ${stats.total24h} in the last 24 hours`}
                    actions={
                        <>
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter')
                                        apply({ search });
                                }}
                                ariaLabel="Search device events"
                                placeholder="Search event type, source, permitted device…"
                            />
                            <PageHeaderGlassButton
                                icon={Search}
                                onClick={() => apply({ search })}
                            >
                                Search
                            </PageHeaderGlassButton>
                            <PageHeaderGlassButton
                                icon={RefreshCw}
                                onClick={() =>
                                    router.get(pageUrl, filters, {
                                        preserveState: true,
                                        preserveScroll: true,
                                    })
                                }
                            >
                                Refresh
                            </PageHeaderGlassButton>
                            {ownerHref ? (
                                <PageHeaderPrimaryButton asChild>
                                    <Link href={ownerHref}>
                                        Open Control Room
                                    </Link>
                                </PageHeaderPrimaryButton>
                            ) : (
                                <ControlRoomAlertAccessRequired
                                    label="Control Room access required"
                                    className="text-band-foreground/80"
                                />
                            )}
                        </>
                    }
                    meters={summaryViews.map((item) => (
                        <PageHeaderMeterBlock
                            key={item.key}
                            label={item.label}
                            ariaLabel={`View ${item.label.toLowerCase()} events`}
                            onClick={() => selectSummary(item.key)}
                            tone={
                                item.key === 'critical'
                                    ? 'critical'
                                    : item.key === 'warning'
                                      ? 'warning'
                                      : 'brand'
                            }
                        >
                            <PageHeaderMeterBig>
                                {item.count}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {item.key === 'unprocessed'
                                    ? 'Across all permitted history'
                                    : 'Across permitted history in the last 24 hours'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ))}
                    filters={
                        <>
                            <PageHeaderFilterSelect
                                label="All severities"
                                value={filters.severity || 'all'}
                                onChange={(severity) => apply({ severity })}
                                options={[
                                    { value: 'critical', label: 'Critical' },
                                    { value: 'warning', label: 'Warning' },
                                    { value: 'info', label: 'Information' },
                                ]}
                            />
                            {filterOptions.eventTypes.length > 0 && (
                                <PageHeaderFilterSelect
                                    label="All event types"
                                    value={filters.event_type || 'all'}
                                    onChange={(event_type) =>
                                        apply({ event_type })
                                    }
                                    options={filterOptions.eventTypes.map(
                                        (value) => ({
                                            value,
                                            label: words(value),
                                        }),
                                    )}
                                />
                            )}
                            <PageHeaderFilterSelect
                                label="All device areas"
                                value={filters.domain || 'all'}
                                onChange={(domain) => apply({ domain })}
                                options={filterOptions.domains}
                            />
                            {filterOptions.sources.length > 0 && (
                                <PageHeaderFilterSelect
                                    label="All sources"
                                    value={filters.source || 'all'}
                                    onChange={(source) => apply({ source })}
                                    options={filterOptions.sources.map(
                                        (value) => ({ value, label: value }),
                                    )}
                                />
                            )}
                            <PageHeaderFilterSelect
                                label="All processing states"
                                value={filters.processed || 'all'}
                                onChange={(processed) => apply({ processed })}
                                options={[
                                    { value: 'yes', label: 'Processed' },
                                    { value: 'no', label: 'Not processed' },
                                ]}
                            />
                            <PageHeaderFilterButton
                                active={
                                    datesOpen ||
                                    Boolean(filters.from || filters.to)
                                }
                                onClick={() => setDatesOpen(!datesOpen)}
                            >
                                Date range
                                {filters.from || filters.to ? ' applied' : ''}
                            </PageHeaderFilterButton>
                            {hasFilters || search ? (
                                <PageHeaderFilterButton onClick={clear}>
                                    Clear filters
                                </PageHeaderFilterButton>
                            ) : null}
                            <PageHeaderViewToggle
                                value={layout}
                                onChange={setLayout}
                                options={[
                                    {
                                        value: 'cards',
                                        label: 'Cards',
                                        icon: LayoutGrid,
                                    },
                                    {
                                        value: 'table',
                                        label: 'Table',
                                        icon: List,
                                    },
                                ]}
                            />
                        </>
                    }
                    rail={
                        <PageHeaderRail<EventView>
                            items={[
                                {
                                    key: 'history' as const,
                                    label: hasFilters
                                        ? 'Filtered history'
                                        : 'All history',
                                },
                                ...summaryViews,
                            ]}
                            value={view}
                            onSelect={selectSummary}
                            ariaLabel="Event summary views"
                        />
                    }
                />
                {datesOpen && (
                    <section
                        aria-label="Event date range"
                        className="flex flex-wrap items-end gap-3 rounded-lg border p-4"
                    >
                        <div>
                            <label
                                htmlFor="event-date-from"
                                className="mb-1 block text-sm font-medium"
                            >
                                From date (UTC)
                            </label>
                            <Input
                                id="event-date-from"
                                type="date"
                                value={filters.from || ''}
                                onChange={(event) =>
                                    apply({
                                        from: event.target.value,
                                        window: '',
                                    })
                                }
                            />
                        </div>
                        <div>
                            <label
                                htmlFor="event-date-to"
                                className="mb-1 block text-sm font-medium"
                            >
                                To date (UTC)
                            </label>
                            <Input
                                id="event-date-to"
                                type="date"
                                value={filters.to || ''}
                                onChange={(event) =>
                                    apply({
                                        to: event.target.value,
                                        window: '',
                                    })
                                }
                            />
                        </div>
                        <Button
                            variant="outline"
                            onClick={() => setDatesOpen(false)}
                        >
                            Close date range
                        </Button>
                        <p className="text-caption w-full">
                            Date filters use the event register's UTC
                            boundaries. Event times display in New Zealand time.
                        </p>
                    </section>
                )}
                <section
                    aria-label="Permitted device event records"
                    className="space-y-3"
                >
                    <p className="text-caption">
                        {events.meta.total} matching events across all pages.
                        Summary counts cover all permitted history
                        {snapshotAt
                            ? ` as of ${formatDateTime(snapshotAt)}`
                            : ''}
                        .
                        {filters.window === '24h' && windowStart
                            ? ` This view covers ${formatDateTime(windowStart)} to ${formatDateTime(snapshotAt)}.`
                            : ''}
                    </p>
                    {events.data.length === 0 ? (
                        hasFilters ? (
                            <EmptySearch
                                title="No matching events"
                                onClear={clear}
                            />
                        ) : (
                            <EmptyState
                                icon={Bell}
                                title="No permitted device events"
                                description="No events are available within your current event-history access."
                                variant="compact"
                            />
                        )
                    ) : (
                        <EvidenceRecords<EventItem>
                            title="Device events"
                            description={`${events.data.length} loaded on this page. Processing state is separate from alert triage or escalation.`}
                            rows={events.data}
                            total={events.meta.total}
                            view={layout}
                            rowKey={(event) => event.id}
                            identity={(event) => ({
                                icon: Activity,
                                name: words(event.event_type),
                                subline: `Event #${event.id} · ${event.device_name || event.device_access?.label || 'Current device unavailable'}`,
                            })}
                            articleLabel={eventName}
                            actionsFor={actionsFor}
                            cardFor={(event) => ({
                                icon: Activity,
                                name: words(event.event_type),
                                subline: `Event #${event.id} · ${event.device_name || event.device_access?.label || 'Current device unavailable'}`,
                                meridian:
                                    event.severity === 'critical'
                                        ? 'critical'
                                        : event.severity === 'warning'
                                          ? 'warning'
                                          : 'neutral',
                                chips: (
                                    <>
                                        <Severity event={event} />
                                        <Processing event={event} />
                                        {event.device_domain && (
                                            <EntityChip>
                                                {words(event.device_domain)}
                                            </EntityChip>
                                        )}
                                    </>
                                ),
                                alerts: (
                                    <div className="space-y-1">
                                        <p>
                                            Source:{' '}
                                            {event.source || 'Not recorded'}
                                        </p>
                                        <p className="text-caption font-mono">
                                            {event.device_uid ||
                                                'Device identifier not available'}
                                        </p>
                                    </div>
                                ),
                                footer: {
                                    personIcon: event.processed_at
                                        ? CheckCircle2
                                        : Clock3,
                                    primary: (
                                        <EventTime value={event.occurred_at} />
                                    ),
                                    secondary: event.processed_at
                                        ? `Processed ${formatRelative(event.processed_at)}`
                                        : 'No processing time recorded',
                                },
                            })}
                            columns={details}
                            compactColumns={scan}
                            icon={Activity}
                            empty="No permitted events."
                            minWidth={1100}
                        />
                    )}
                    <LaravelPagination
                        links={events.links}
                        lastPage={events.meta.last_page}
                    />
                </section>
            </div>
        </AppLayout>
    );
}
