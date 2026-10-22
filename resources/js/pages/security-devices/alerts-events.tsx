import { LeaveCalendarRange } from '@/components/hr/leave-calendar-range';
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
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewRow } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTime, formatRelative } from '@/lib/datetime';
import { readableKey } from '@/lib/security-devices-labels';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    Bell,
    CalendarDays,
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
/** "fall.detected" → "Fall detected"; keys stay unchanged for filtering. */
const eventTitle = (value: string | null) => readableKey(value);
const eventName = (event: EventItem) =>
    `Event #${event.id}: ${eventTitle(event.event_type)}`;
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
                    : readableKey(event.severity)
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
    // Calendar days are New Zealand dates (YYYY-MM-DD); the server turns
    // each day's bounds into UTC instants for the stored event times.
    const [rangeFrom, setRangeFrom] = useState<string | null>(
        filters.from || null,
    );
    const [rangeTo, setRangeTo] = useState<string | null>(filters.to || null);
    const datesApplied = Boolean(filters.from || filters.to);
    const domainLabel = (value: string) =>
        filterOptions.domains.find((option) => option.value === value)?.label ??
        readableKey(value);
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
                    <ReviewRow
                        label="Event type"
                        value={eventTitle(event.event_type)}
                    />
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
                                ? domainLabel(event.device_domain)
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
                                            label: eventTitle(value),
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
                            <Popover
                                open={datesOpen}
                                onOpenChange={setDatesOpen}
                            >
                                <PopoverTrigger asChild>
                                    <PageHeaderFilterButton
                                        icon={CalendarDays}
                                        active={datesApplied}
                                    >
                                        {datesApplied
                                            ? `${formatDateOnly(filters.from, 'Any date')} – ${formatDateOnly(filters.to, 'Any date')}`
                                            : 'Date range'}
                                    </PageHeaderFilterButton>
                                </PopoverTrigger>
                                <PopoverContent
                                    align="end"
                                    aria-label="Event date range"
                                    className="w-auto max-w-[90vw]"
                                >
                                    <p className="text-subtle mb-3">
                                        Choose New Zealand calendar days. Event
                                        times show in New Zealand time.
                                    </p>
                                    <LeaveCalendarRange
                                        start={rangeFrom}
                                        end={rangeTo}
                                        onChange={(start, end) => {
                                            setRangeFrom(start);
                                            setRangeTo(end);
                                        }}
                                        required
                                    />
                                    <p className="text-caption mt-3">
                                        {rangeFrom && rangeTo
                                            ? rangeFrom === rangeTo
                                                ? formatDateOnly(rangeFrom)
                                                : `${formatDateOnly(rangeFrom)} – ${formatDateOnly(rangeTo)}`
                                            : rangeFrom
                                              ? 'Choose the end date. Choose the same day again for one day.'
                                              : 'Choose a start and end day.'}
                                    </p>
                                    <div className="mt-3 flex gap-2">
                                        {datesApplied ? (
                                            <Button
                                                variant="outline"
                                                className="flex-1"
                                                onClick={() => {
                                                    setDatesOpen(false);
                                                    apply({ from: '', to: '' });
                                                }}
                                            >
                                                Clear dates
                                            </Button>
                                        ) : null}
                                        <Button
                                            className="flex-1"
                                            disabled={!rangeFrom || !rangeTo}
                                            onClick={() => {
                                                if (!rangeFrom || !rangeTo)
                                                    return;
                                                setDatesOpen(false);
                                                apply({
                                                    from: rangeFrom,
                                                    to: rangeTo,
                                                    window: '',
                                                });
                                            }}
                                        >
                                            Apply dates
                                        </Button>
                                    </div>
                                </PopoverContent>
                            </Popover>
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
                                name: eventTitle(event.event_type),
                                subline: `Event #${event.id} · ${event.device_name || event.device_access?.label || 'Current device unavailable'}`,
                            })}
                            articleLabel={eventName}
                            actionsFor={actionsFor}
                            cardFor={(event) => ({
                                icon: Activity,
                                name: eventTitle(event.event_type),
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
                                                {domainLabel(
                                                    event.device_domain,
                                                )}
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
