import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    AlertOctagon,
    AlertTriangle,
    Calendar,
    Check,
    ChevronDown,
    ChevronUp,
    ClipboardCheck,
    Edit,
    FileText,
    Loader2,
    Package,
    Pill,
    Trash2,
    XCircle,
} from 'lucide-react';
import { useCallback, useState } from 'react';

// ─── Types ────────────────────────────────────────────────────────

type AuditEvent = {
    id: string;
    event_type: string;
    timestamp: string;
    description: string;
    performed_by: string | null;
    client_id: number | null;
    client_name: string;
    details: Record<string, unknown>;
};

type ClientOption = {
    id: number;
    name: string;
};

type Stats = {
    total: number;
    this_week: number;
    this_month: number;
};

type Filters = {
    client_id: string | null;
    date_from: string | null;
    date_to: string | null;
    event_types: string[];
};

type Props = {
    events: AuditEvent[];
    stats: Stats;
    hasMore: boolean;
    currentPage: number;
    clients: ClientOption[];
    filters: Filters;
};

// ─── Event type config ──────────────────────────────────────────

const EVENT_CONFIG: Record<
    string,
    {
        label: string;
        icon: React.ElementType;
        color: string;
        bg: string;
        badgeVariant: string;
    }
> = {
    medication_started: {
        label: 'Medication Started',
        icon: Pill,
        color: 'text-status-success dark:text-status-success',
        bg: 'bg-status-success-bg',
        badgeVariant:
            'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success',
    },
    medication_ceased: {
        label: 'Medication Ceased',
        icon: XCircle,
        color: 'text-status-critical dark:text-status-critical',
        bg: 'bg-status-critical-bg',
        badgeVariant:
            'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
    },
    medication_changed: {
        label: 'Medication Changed',
        icon: Edit,
        color: 'text-status-warning dark:text-status-warning',
        bg: 'bg-status-warning-bg',
        badgeVariant:
            'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning',
    },
    dose_administered: {
        label: 'Dose Administered',
        icon: Check,
        color: 'text-status-info dark:text-status-info',
        bg: 'bg-status-info-bg',
        badgeVariant:
            'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
    },
    dose_refused: {
        label: 'Dose Refused',
        icon: XCircle,
        color: 'text-status-warning dark:text-status-warning',
        bg: 'bg-status-warning-bg',
        badgeVariant:
            'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning',
    },
    dose_missed: {
        label: 'Dose Missed',
        icon: AlertTriangle,
        color: 'text-status-critical dark:text-status-critical',
        bg: 'bg-status-critical-bg',
        badgeVariant:
            'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
    },
    prescriber_order: {
        label: 'Prescriber Order',
        icon: FileText,
        color: 'text-primary dark:text-primary',
        bg: 'bg-primary/10 dark:bg-primary/40',
        badgeVariant:
            'bg-primary/10 text-primary dark:bg-primary/40 dark:text-primary/70',
    },
    review_completed: {
        label: 'Review Completed',
        icon: ClipboardCheck,
        color: 'text-status-info dark:text-status-info',
        bg: 'bg-status-info-bg',
        badgeVariant:
            'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
    },
    stock_received: {
        label: 'Stock Received',
        icon: Package,
        color: 'text-status-success dark:text-status-success',
        bg: 'bg-status-success-bg',
        badgeVariant:
            'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success',
    },
    destruction: {
        label: 'Medication Destroyed',
        icon: Trash2,
        color: 'text-status-critical dark:text-status-critical',
        bg: 'bg-status-critical-bg',
        badgeVariant:
            'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
    },
    error_reported: {
        label: 'Error Reported',
        icon: AlertOctagon,
        color: 'text-status-critical dark:text-status-critical',
        bg: 'bg-status-critical-bg',
        badgeVariant:
            'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
    },
};

const ALL_EVENT_TYPES = Object.keys(EVENT_CONFIG);

// ─── Helpers ────────────────────────────────────────────────────

function formatTimestamp(iso: string): { date: string; time: string } {
    const d = new Date(iso);
    return {
        date: d.toLocaleDateString('en-NZ', {
            day: 'numeric',
            month: 'short',
            year: 'numeric',
        }),
        time: d.toLocaleTimeString('en-NZ', {
            hour: '2-digit',
            minute: '2-digit',
        }),
    };
}

// ─── Timeline Entry ──────────────────────────────────────────────

function TimelineEntry({ event }: { event: AuditEvent }) {
    const [expanded, setExpanded] = useState(false);
    const config =
        EVENT_CONFIG[event.event_type] ?? EVENT_CONFIG.medication_started;
    const Icon = config.icon;
    const { date, time } = formatTimestamp(event.timestamp);

    const detailEntries = Object.entries(event.details ?? {}).filter(
        ([, v]) => v !== null && v !== undefined && v !== '',
    );

    return (
        <div className="group relative flex gap-4 pb-8 last:pb-0">
            {/* Timeline line */}
            <div className="absolute top-10 bottom-0 left-5 w-px bg-border group-last:hidden" />

            {/* Icon */}
            <div
                className={`relative z-10 flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2 border-background shadow-sm ${config.bg}`}
            >
                <Icon className={`h-4 w-4 ${config.color}`} />
            </div>

            {/* Content */}
            <div className="min-w-0 flex-1 pt-0.5">
                <div className="flex flex-wrap items-center gap-2">
                    <span
                        className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${config.badgeVariant}`}
                    >
                        {config.label}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {date} at {time}
                    </span>
                </div>

                <p className="mt-1 text-sm font-medium">{event.description}</p>

                {event.performed_by && (
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        By {event.performed_by}
                    </p>
                )}

                {detailEntries.length > 0 && (
                    <Button
                        type="button"
                        variant="link"
                        size="sm"
                        onClick={() => setExpanded(!expanded)}
                        className="mt-1.5 h-auto gap-1 p-0 text-xs font-medium text-primary"
                    >
                        {expanded ? (
                            <>
                                Hide details <ChevronUp className="h-3 w-3" />
                            </>
                        ) : (
                            <>
                                Show details <ChevronDown className="h-3 w-3" />
                            </>
                        )}
                    </Button>
                )}

                {expanded && detailEntries.length > 0 && (
                    <div className="mt-2 rounded-md border bg-muted/30 p-3">
                        <dl className="grid gap-1 text-xs sm:grid-cols-2">
                            {detailEntries.map(([key, val]) => (
                                <div key={key}>
                                    <dt className="font-medium text-muted-foreground capitalize">
                                        {key.replace(/_/g, ' ')}
                                    </dt>
                                    <dd className="mt-0.5">{String(val)}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                )}
            </div>
        </div>
    );
}

// ─── Main Component ──────────────────────────────────────────────

export default function AuditLog({
    events,
    stats,
    hasMore,
    currentPage,
    clients,
    filters,
}: Props) {
    const [loading, setLoading] = useState(false);
    const [selectedTypes, setSelectedTypes] = useState<string[]>(
        filters.event_types ?? [],
    );
    const [clientId, setClientId] = useState<string>(filters.client_id ?? '');
    const [dateFrom, setDateFrom] = useState<string>(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState<string>(filters.date_to ?? '');

    const applyFilters = useCallback(
        (
            overrides: Partial<{
                client_id: string;
                date_from: string;
                date_to: string;
                event_types: string[];
            }> = {},
        ) => {
            const params: Record<string, string> = {};
            const cid = overrides.client_id ?? clientId;
            const df = overrides.date_from ?? dateFrom;
            const dt = overrides.date_to ?? dateTo;
            const et = overrides.event_types ?? selectedTypes;

            if (cid) params.client_id = cid;
            if (df) params.date_from = df;
            if (dt) params.date_to = dt;
            if (et.length > 0) params.event_types = et.join(',');

            router.get('/emar/audit', params, {
                preserveState: true,
                preserveScroll: true,
            });
        },
        [clientId, dateFrom, dateTo, selectedTypes],
    );

    const loadMore = () => {
        setLoading(true);
        const params: Record<string, string> = {
            page: String(currentPage + 1),
        };
        if (clientId) params.client_id = clientId;
        if (dateFrom) params.date_from = dateFrom;
        if (dateTo) params.date_to = dateTo;
        if (selectedTypes.length > 0)
            params.event_types = selectedTypes.join(',');

        router.get('/emar/audit', params, {
            preserveState: true,
            preserveScroll: true,
            onFinish: () => setLoading(false),
        });
    };

    const toggleEventType = (type: string) => {
        const next = selectedTypes.includes(type)
            ? selectedTypes.filter((t) => t !== type)
            : [...selectedTypes, type];
        setSelectedTypes(next);
        applyFilters({ event_types: next });
    };

    const clearFilters = () => {
        setClientId('');
        setDateFrom('');
        setDateTo('');
        setSelectedTypes([]);
        router.get('/emar/audit', {}, { preserveState: true });
    };

    const hasActiveFilters =
        clientId || dateFrom || dateTo || selectedTypes.length > 0;

    return (
        <AppLayout>
            <Head title="Medication Audit Trail" />
            <PageHero variant="compact"
                title="Medication Audit Trail"
                description="Comprehensive timeline of all medication-related events across clients."
            />
            <PageShell>
                {/* ── Stats Bar ─────────────────────────────────────── */}
                <div className="mb-6 grid gap-3 sm:grid-cols-3">
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-status-info-bg">
                                <FileText className="h-5 w-5 text-status-info" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">
                                    {stats.total.toLocaleString()}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Total events
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-status-success-bg">
                                <Calendar className="h-5 w-5 text-status-success" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">
                                    {stats.this_week.toLocaleString()}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    This week
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 dark:bg-primary/40">
                                <Calendar className="h-5 w-5 text-primary" />
                            </div>
                            <div>
                                <p className="text-2xl font-bold">
                                    {stats.this_month.toLocaleString()}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    This month
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* ── Filters ──────────────────────────────────────── */}
                <Card className="mb-6">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm font-medium">
                            Filters
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {/* Row 1: Client + Dates */}
                        <div className="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                                    Client
                                </label>
                                <Select
                                    value={clientId}
                                    onValueChange={(val) => {
                                        const v = val === 'all' ? '' : val;
                                        setClientId(v);
                                        applyFilters({ client_id: v });
                                    }}
                                >
                                    <SelectTrigger className="w-full">
                                        <SelectValue placeholder="All clients" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">
                                            All clients
                                        </SelectItem>
                                        {clients.map((c) => (
                                            <SelectItem
                                                key={c.id}
                                                value={String(c.id)}
                                            >
                                                {c.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                                    From
                                </label>
                                <input
                                    type="date"
                                    value={dateFrom}
                                    onChange={(e) => {
                                        setDateFrom(e.target.value);
                                        applyFilters({
                                            date_from: e.target.value,
                                        });
                                    }}
                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs font-medium text-muted-foreground">
                                    To
                                </label>
                                <input
                                    type="date"
                                    value={dateTo}
                                    onChange={(e) => {
                                        setDateTo(e.target.value);
                                        applyFilters({
                                            date_to: e.target.value,
                                        });
                                    }}
                                    className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                                />
                            </div>
                        </div>

                        {/* Row 2: Event type pills */}
                        <div>
                            <label className="mb-1.5 block text-xs font-medium text-muted-foreground">
                                Event types
                            </label>
                            <div className="flex flex-wrap gap-1.5">
                                {ALL_EVENT_TYPES.map((type) => {
                                    const cfg = EVENT_CONFIG[type];
                                    const active =
                                        selectedTypes.length === 0 ||
                                        selectedTypes.includes(type);
                                    return (
                                        <Button
                                            key={type}
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                toggleEventType(type)
                                            }
                                            className={`h-7 rounded-full px-2.5 text-xs ${
                                                active
                                                    ? `${cfg.badgeVariant} border-transparent`
                                                    : 'border-border bg-muted/30 text-muted-foreground opacity-50'
                                            }`}
                                        >
                                            {cfg.label}
                                        </Button>
                                    );
                                })}
                            </div>
                        </div>

                        {hasActiveFilters && (
                            <div className="flex justify-end">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={clearFilters}
                                >
                                    Clear all filters
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ── Timeline ────────────────────────────────────── */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-sm font-medium">
                            Event Timeline
                            <Badge variant="secondary" className="ml-2">
                                {events.length} shown
                            </Badge>
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {events.length === 0 ? (
                            <div className="flex flex-col items-center py-12 text-center">
                                <FileText className="mb-3 h-10 w-10 text-muted-foreground/40" />
                                <p className="text-sm font-medium text-muted-foreground">
                                    No events found
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Try adjusting your filters to see more
                                    results.
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-0">
                                {events.map((event) => (
                                    <TimelineEntry
                                        key={event.id}
                                        event={event}
                                    />
                                ))}
                            </div>
                        )}

                        {hasMore && (
                            <div className="mt-6 flex justify-center">
                                <Button
                                    variant="outline"
                                    onClick={loadMore}
                                    disabled={loading}
                                    className="min-w-[160px]"
                                >
                                    {loading ? (
                                        <>
                                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                            Loading...
                                        </>
                                    ) : (
                                        'Load more events'
                                    )}
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
