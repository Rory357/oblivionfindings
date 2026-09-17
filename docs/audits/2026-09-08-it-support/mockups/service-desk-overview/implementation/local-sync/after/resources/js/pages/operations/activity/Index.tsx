import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterSpark,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageLayout,
} from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    CalendarDays,
    CalendarRange,
    CircleDot,
    Clock,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type ActivityItem = {
    id: string;
    type: string;
    action: string;
    title: string;
    description: string;
    timestamp: string;
    link: string;
};

type StreamSummary = {
    total: number;
    /** Events per day, oldest → today (last 7 days). */
    series: number[];
};

type Summary = {
    shifts: StreamSummary & {
        completed: number;
        started: number;
        cancelled: number;
    };
    timesheets: StreamSummary & {
        submitted: number;
        approved: number;
        rejected: number;
    };
    clients: StreamSummary;
};

type ViewKey = 'all' | 'shifts' | 'timesheets' | 'clients';

type Props = {
    activities: ActivityItem[];
    filter: string;
    summary: Summary;
};

function formatRelativeTime(iso: string): string {
    const d = new Date(iso);
    const now = new Date();
    const diff = Math.floor((now.getTime() - d.getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    return `${Math.floor(diff / 86400)}d ago`;
}

function typeIcon(type: string) {
    switch (type) {
        case 'shift':
            return <CalendarDays className="h-4 w-4" />;
        case 'timesheet':
            return <Clock className="h-4 w-4" />;
        case 'client':
            return <Users className="h-4 w-4" />;
        default:
            return <Activity className="h-4 w-4" />;
    }
}

function actionColor(action: string): string {
    switch (action) {
        case 'completed':
        case 'approved':
        case 'created':
            return 'bg-status-success-bg text-status-success';
        case 'started':
        case 'submitted':
            return 'bg-status-info-bg text-status-info';
        case 'cancelled':
        case 'rejected':
            return 'bg-status-critical-bg text-status-critical';
        default:
            return 'bg-muted text-foreground';
    }
}

const RANGE_OPTIONS = [
    { value: '7d', label: 'Last 7 days' },
    { value: '3d', label: 'Last 3 days' },
    { value: 'today', label: 'Today' },
    { value: 'yesterday', label: 'Yesterday' },
];

/** Per-view action vocabularies — the clients stream only ever "created",
 *  so it gets no action pill (a one-option select filters nothing). */
const ACTION_OPTIONS: Record<ViewKey, { value: string; label: string }[]> = {
    all: [
        { value: 'all', label: 'All actions' },
        { value: 'completed', label: 'Completed' },
        { value: 'started', label: 'Started' },
        { value: 'cancelled', label: 'Cancelled' },
        { value: 'submitted', label: 'Submitted' },
        { value: 'approved', label: 'Approved' },
        { value: 'rejected', label: 'Rejected' },
        { value: 'created', label: 'Created' },
    ],
    shifts: [
        { value: 'all', label: 'All actions' },
        { value: 'completed', label: 'Completed' },
        { value: 'started', label: 'Started' },
        { value: 'cancelled', label: 'Cancelled' },
    ],
    timesheets: [
        { value: 'all', label: 'All actions' },
        { value: 'submitted', label: 'Submitted' },
        { value: 'approved', label: 'Approved' },
        { value: 'rejected', label: 'Rejected' },
    ],
    clients: [],
};

export default function ActivityFeed({ activities, filter, summary }: Props) {
    const { labels } = usePage().props as any;
    const clientPlural: string = labels?.['client.plural'] ?? 'Clients';

    const view: ViewKey = (
        ['all', 'shifts', 'timesheets', 'clients'] as const
    ).includes(filter as ViewKey)
        ? (filter as ViewKey)
        : 'all';

    const [search, setSearch] = useState('');
    const [action, setAction] = useState('all');
    const [range, setRange] = useState('7d');

    const selectView = (key: ViewKey) => {
        // Action vocabularies differ per stream — a carried-over action
        // would silently empty the next view.
        setAction('all');
        router.get(
            '/operations/activity',
            { filter: key },
            { preserveState: true, replace: true },
        );
    };

    const totalEvents =
        summary.shifts.total + summary.timesheets.total + summary.clients.total;
    const allSeries = summary.shifts.series.map(
        (v, i) =>
            v +
            (summary.timesheets.series[i] ?? 0) +
            (summary.clients.series[i] ?? 0),
    );

    const shown = useMemo(() => {
        const q = search.trim().toLowerCase();
        const startOfDay = (daysAgo: number) => {
            const d = new Date();
            d.setHours(0, 0, 0, 0);
            d.setDate(d.getDate() - daysAgo);
            return d.getTime();
        };
        return activities.filter((item) => {
            if (action !== 'all' && item.action !== action) return false;
            if (range !== '7d') {
                const t = item.timestamp
                    ? new Date(item.timestamp).getTime()
                    : 0;
                if (range === 'today' && t < startOfDay(0)) return false;
                if (
                    range === 'yesterday' &&
                    (t < startOfDay(1) || t >= startOfDay(0))
                ) {
                    return false;
                }
                if (range === '3d' && t < startOfDay(2)) return false;
            }
            if (q) {
                const hay =
                    `${item.title} ${item.description} ${item.type} ${item.action}`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
    }, [activities, search, action, range]);

    const hasNarrowing =
        search.trim() !== '' || action !== 'all' || range !== '7d';

    const railItems: PageHeaderRailItem<ViewKey>[] = [
        {
            key: 'all',
            label: 'All activity',
            icon: Activity,
            count: totalEvents,
        },
        {
            key: 'shifts',
            label: 'Shifts',
            icon: CalendarDays,
            count: summary.shifts.total,
        },
        {
            key: 'timesheets',
            label: 'Timesheets',
            icon: Clock,
            count: summary.timesheets.total,
        },
        {
            key: 'clients',
            label: clientPlural,
            icon: Users,
            count: summary.clients.total,
        },
    ];

    const actionOptions = ACTION_OPTIONS[view];
    const currentViewLabel =
        railItems.find((v) => v.key === view)?.label ?? 'All activity';
    // The feed itself caps at the most recent 25 events; the header counts
    // cover the whole 7-day window — the caption reconciles the two.
    const viewTotal = view === 'all' ? totalEvents : summary[view].total;

    const header = (
        <PageHeader
            icon={Activity}
            title="Activity feed"
            titleChip={
                <PageHeaderStatusChip variant="info">
                    {totalEvents} this week
                </PageHeaderStatusChip>
            }
            subline={`Shifts, timesheets and new ${clientPlural.toLowerCase()} · last 7 days · newest first`}
            actions={
                <PageHeaderSearch
                    value={search}
                    onChange={setSearch}
                    placeholder="Search activity, people…"
                />
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="All activity"
                        value={totalEvents}
                        ariaLabel="View all activity"
                        onClick={() => selectView('all')}
                    >
                        <PageHeaderMeterSpark values={allSeries} />
                        <PageHeaderMeterCaption>
                            events per day · last 7 days
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Shifts"
                        value={summary.shifts.total}
                        ariaLabel="View shift activity"
                        onClick={() => selectView('shifts')}
                    >
                        <PageHeaderMeterSpark values={summary.shifts.series} />
                        <PageHeaderMeterCaption>
                            {summary.shifts.completed} completed ·{' '}
                            {summary.shifts.started} started ·{' '}
                            {summary.shifts.cancelled} cancelled
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Timesheets"
                        value={summary.timesheets.total}
                        ariaLabel="View timesheet activity"
                        onClick={() => selectView('timesheets')}
                    >
                        <PageHeaderMeterSpark
                            values={summary.timesheets.series}
                        />
                        <PageHeaderMeterCaption>
                            {summary.timesheets.submitted} submitted ·{' '}
                            {summary.timesheets.approved} approved ·{' '}
                            {summary.timesheets.rejected} rejected
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label={`New ${clientPlural.toLowerCase()}`}
                        value={summary.clients.total}
                        ariaLabel={`View new ${clientPlural.toLowerCase()}`}
                        onClick={() => selectView('clients')}
                    >
                        <PageHeaderMeterSpark values={summary.clients.series} />
                        <PageHeaderMeterCaption>
                            added in the last 7 days
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    {actionOptions.length > 0 ? (
                        <PageHeaderFilterSelect
                            icon={CircleDot}
                            label="All actions"
                            value={action}
                            options={actionOptions}
                            onChange={setAction}
                        />
                    ) : null}
                    <PageHeaderFilterSelect
                        icon={CalendarRange}
                        label="Last 7 days"
                        value={range}
                        allValue="7d"
                        options={RANGE_OPTIONS}
                        onChange={setRange}
                    />
                </>
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={view}
                    onSelect={selectView}
                    ariaLabel="Activity streams"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                { title: 'Activity', href: '/operations/activity' },
            ]}
        >
            <Head title="Activity feed" />

            <PageLayout hero={header}>
                <div className="flex flex-col gap-5">
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${shown.length} of ${viewTotal} ${
                            viewTotal === 1 ? 'event' : 'events'
                        } shown`}
                    />
                    <div className="space-y-2">
                        {shown.length === 0 ? (
                            <EmptyState
                                icon={Activity}
                                title="No activity found"
                                description={
                                    hasNarrowing
                                        ? 'Try clearing a filter or search term.'
                                        : 'Nothing has happened in the last 7 days.'
                                }
                            />
                        ) : (
                            shown.map((item) => (
                                <Link
                                    key={item.id}
                                    href={item.link}
                                    className="block"
                                >
                                    <Card className="transition-all hover:border-border hover:shadow-sm">
                                        <CardContent className="flex items-center gap-4 p-3">
                                            <div
                                                className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${actionColor(item.action)}`}
                                            >
                                                {typeIcon(item.type)}
                                            </div>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-medium">
                                                        {item.title}
                                                    </span>
                                                    <Badge
                                                        variant="outline"
                                                        className="h-4 px-1.5 text-[9px] capitalize"
                                                    >
                                                        {item.type}
                                                    </Badge>
                                                </div>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {item.description}
                                                </p>
                                            </div>
                                            <span className="shrink-0 text-xs text-muted-foreground">
                                                {item.timestamp
                                                    ? formatRelativeTime(
                                                          item.timestamp,
                                                      )
                                                    : ''}
                                            </span>
                                        </CardContent>
                                    </Card>
                                </Link>
                            ))
                        )}
                    </div>
                </div>
            </PageLayout>
        </AppLayout>
    );
}
