import {
    plainLabel,
    severityBadge,
} from '@/components/control-room/alert-record/alert-labels';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    ControlRoomRowActions,
    type ControlRoomRowAction,
} from '@/components/control-room/control-room-row-actions';
import { HeaderDateRange } from '@/components/control-room/reports/report-parts';
import {
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateOnly, formatDateTime } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import { Copy, Eye, Gauge, ShieldCheck, Target, Timer } from 'lucide-react';

// --- Types ---

type BreachType = 'acknowledge' | 'response' | 'resolution';

interface BreachRecord {
    id: number;
    alert_id: number;
    alert_reference: string | null;
    alert_type: string | null;
    severity: string | null;
    source: string | null;
    sla_name: string | null;
    sla_code: string | null;
    breach_types: string[];
    acknowledge_deadline: string | null;
    acknowledged_at: string | null;
    acknowledge_variance_minutes: number | null;
    response_deadline: string | null;
    responded_at: string | null;
    response_variance_minutes: number | null;
    resolution_deadline: string | null;
    resolved_at: string | null;
    resolution_variance_minutes: number | null;
    first_breach_at: string | null;
}

interface PaginatedBreaches {
    data: BreachRecord[];
    links: Array<{
        url: string | null;
        label: string;
        active: boolean;
    }>;
    current_page: number;
    last_page: number;
    total: number;
    from?: number | null;
    to?: number | null;
}

interface Stats {
    total: number;
    acknowledge: number;
    response: number;
    resolution: number;
}

interface Filters {
    date_from: string;
    date_to: string;
    severity: string | null;
    breach_type: string | null;
}

interface Props {
    breaches: PaginatedBreaches;
    stats: Stats;
    filters: Filters;
}

// --- Constants ---

const BASE = '/control-room/sla/breaches';

const TARGET_LABEL: Record<BreachType, string> = {
    acknowledge: 'Acknowledge',
    response: 'Response',
    resolution: 'Resolution',
};

const SEVERITY_OPTIONS = [
    { value: 'all', label: 'All severities' },
    { value: 'critical', label: 'Critical' },
    { value: 'high', label: 'High' },
    { value: 'medium', label: 'Medium' },
    { value: 'low', label: 'Low' },
];

const TARGET_OPTIONS = [
    { value: 'all', label: 'All targets' },
    { value: 'acknowledge', label: 'Acknowledge' },
    { value: 'response', label: 'Response' },
    { value: 'resolution', label: 'Resolution' },
];

// --- Helpers ---

function formatVariance(minutes: number | null): string {
    if (minutes === null || minutes === undefined) return '-';
    const absMinutes = Math.abs(Math.round(minutes));
    if (absMinutes < 60) return `${absMinutes}m late`;
    const hours = Math.floor(absMinutes / 60);
    const mins = absMinutes % 60;
    if (mins === 0) return `${hours}h late`;
    return `${hours}h ${mins}m late`;
}

/** The worst missed target decides the row's deadline, actual and variance. */
function primaryTarget(breach: BreachRecord): BreachType {
    if (breach.breach_types.includes('resolution')) return 'resolution';
    if (breach.breach_types.includes('response')) return 'response';
    return 'acknowledge';
}

function milestone(breach: BreachRecord, target: BreachType) {
    switch (target) {
        case 'acknowledge':
            return {
                deadline: breach.acknowledge_deadline,
                actual: breach.acknowledged_at,
                variance: breach.acknowledge_variance_minutes,
            };
        case 'response':
            return {
                deadline: breach.response_deadline,
                actual: breach.responded_at,
                variance: breach.response_variance_minutes,
            };
        case 'resolution':
            return {
                deadline: breach.resolution_deadline,
                actual: breach.resolved_at,
                variance: breach.resolution_variance_minutes,
            };
    }
}

// --- Main Page ---

/**
 * Reports › Target breaches — every alert that missed a response target in
 * the chosen dates, with the deadline, when it actually happened and how
 * late. The header numbers filter the list by which target was missed.
 */
export default function SlaBreaches({ breaches, stats, filters }: Props) {
    const visit = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        const params: Record<string, string> = {
            date_from: merged.date_from,
            date_to: merged.date_to,
        };
        if (merged.severity) params.severity = merged.severity;
        if (merged.breach_type) params.breach_type = merged.breach_type;
        router.get(BASE, params, { preserveState: true, preserveScroll: true });
    };

    const range = `${formatDateOnly(filters.date_from)} – ${formatDateOnly(filters.date_to)}`;
    const active = filters.breach_type;

    const meter = (
        type: BreachType | null,
        label: string,
        count: number,
        caption: string,
    ) => (
        <PageHeaderMeterBlock
            label={label}
            tone={count > 0 ? (type === null || type === 'resolution' ? 'critical' : 'warning') : 'brand'}
            onClick={() => visit({ breach_type: type })}
            pressed={(active ?? null) === type}
            ariaLabel={
                type === null
                    ? 'Show every missed response target'
                    : `Show missed ${type} targets`
            }
        >
            <PageHeaderMeterBig>{count}</PageHeaderMeterBig>
            <PageHeaderMeterCaption>{caption}</PageHeaderMeterCaption>
        </PageHeaderMeterBlock>
    );

    const first = breaches.from ?? (breaches.data.length ? (breaches.current_page - 1) * 25 + 1 : 0);
    const last = breaches.to ?? first + breaches.data.length - 1;

    return (
        <ControlRoomPage
            headTitle="Target breaches"
            header={{
                subline: `Alerts that missed a response target, and how late they were · ${range}`,
                actions: (
                    <PageHeaderGlassButton icon={Target} asChild>
                        <Link href="/control-room/sla">Response targets</Link>
                    </PageHeaderGlassButton>
                ),
                meters: (
                    <>
                        {meter(null, 'Missed targets', stats.total, 'alerts in these dates')}
                        {meter('acknowledge', 'Acknowledge', stats.acknowledge, 'acknowledged late')}
                        {meter('response', 'Response', stats.response, 'responded to late')}
                        {meter('resolution', 'Resolution', stats.resolution, 'resolved late')}
                    </>
                ),
                filters: (
                    <>
                        <PageHeaderFilterSelect
                            icon={Gauge}
                            label="All severities"
                            value={filters.severity ?? 'all'}
                            options={SEVERITY_OPTIONS}
                            onChange={(value) =>
                                visit({ severity: value === 'all' ? null : value })
                            }
                        />
                        <PageHeaderFilterSelect
                            icon={Timer}
                            label="All targets"
                            value={filters.breach_type ?? 'all'}
                            options={TARGET_OPTIONS}
                            onChange={(value) =>
                                visit({ breach_type: value === 'all' ? null : value })
                            }
                        />
                        <HeaderDateRange
                            idPrefix="breaches-range"
                            from={filters.date_from}
                            to={filters.date_to}
                            onChange={(date_from, date_to) => visit({ date_from, date_to })}
                        />
                    </>
                ),
            }}
        >
            <Card className="min-w-0 gap-0 overflow-hidden py-0">
                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-border px-5 py-4">
                    <div className="min-w-0">
                        <h2 className="text-section-title">Missed response targets</h2>
                        <p className="text-subtle mt-0.5">
                            Each row shows the latest target the alert missed: when it was due,
                            when it happened and how late.
                        </p>
                    </div>
                    {breaches.total > 0 ? (
                        <StatusBadge variant="neutral" className="tabular-nums">
                            Showing {first}–{last} of {breaches.total}
                        </StatusBadge>
                    ) : null}
                </div>
                {breaches.data.length === 0 ? (
                    <div className="px-5 py-10">
                        <EmptyState
                            icon={ShieldCheck}
                            title="No missed response targets"
                            description={`Nothing matches these filters for ${range}.`}
                        />
                    </div>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="pl-5">Alert</TableHead>
                                <TableHead>Severity</TableHead>
                                <TableHead>Missed target</TableHead>
                                <TableHead>Deadline</TableHead>
                                <TableHead>Actual</TableHead>
                                <TableHead>Variance</TableHead>
                                <TableHead>Response target</TableHead>
                                <TableHead className="w-12 pr-5">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {breaches.data.map((breach) => (
                                <BreachRow key={breach.id} breach={breach} />
                            ))}
                        </TableBody>
                    </Table>
                )}
                {breaches.last_page > 1 ? (
                    <div className="border-t border-border px-5 py-3">
                        <LaravelPagination
                            links={breaches.links}
                            lastPage={breaches.last_page}
                            preserveScroll
                        />
                    </div>
                ) : null}
            </Card>
        </ControlRoomPage>
    );
}

// --- Breach Row ---

function BreachRow({ breach }: { breach: BreachRecord }) {
    const reference = breach.alert_reference ?? `Alert ${breach.alert_id}`;
    const href = `/control-room/alerts/${breach.alert_id}`;
    const target = primaryTarget(breach);
    const { deadline, actual, variance } = milestone(breach, target);
    const severity = breach.severity ? severityBadge(breach.severity) : null;

    const items: ControlRoomRowAction[] = [
        {
            key: 'open',
            label: 'Open alert',
            icon: Eye,
            onSelect: () => router.visit(href),
        },
        {
            key: 'copy',
            label: 'Copy alert reference',
            icon: Copy,
            onSelect: () => void navigator.clipboard?.writeText(reference),
        },
    ];

    return (
        <ControlRoomRowActions label={`Actions for ${reference}`} items={items}>
            {({ rowProps, overflowButton }) => (
                <TableRow
                    {...rowProps}
                    className="cursor-pointer outline-none focus-visible:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                    onClick={(event) => {
                        // The reference link and the kebab handle their own clicks.
                        if ((event.target as HTMLElement).closest('a,button')) return;
                        router.visit(href);
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && event.target === event.currentTarget) {
                            router.visit(href);
                        }
                    }}
                >
                    <TableCell className="pl-5">
                        {/* What happened first; the reference is the small secondary line. */}
                        <span className="block font-medium text-foreground">
                            {plainLabel(breach.alert_type) || 'Alert'}
                        </span>
                        <span className="text-caption block">
                            <Link href={href} className="font-medium text-primary hover:underline">
                                {reference}
                            </Link>
                            {breach.source ? ` · ${plainLabel(breach.source)}` : null}
                        </span>
                    </TableCell>
                    <TableCell>
                        {severity ? (
                            <StatusBadge variant={severity.tone} size="sm">
                                {severity.label}
                            </StatusBadge>
                        ) : null}
                    </TableCell>
                    <TableCell>
                        <span className="flex flex-wrap gap-1">
                            {breach.breach_types.map((type) => (
                                <StatusBadge key={type} variant="neutral" size="sm">
                                    {TARGET_LABEL[type as BreachType] ?? plainLabel(type)}
                                </StatusBadge>
                            ))}
                        </span>
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-muted-foreground">
                        {deadline ? formatDateTime(deadline) : null}
                    </TableCell>
                    <TableCell className="whitespace-nowrap text-muted-foreground">
                        {actual ? formatDateTime(actual) : 'Pending'}
                    </TableCell>
                    <TableCell className="whitespace-nowrap">
                        {variance !== null ? (
                            <span className="font-semibold text-status-critical">
                                {formatVariance(variance)}
                            </span>
                        ) : (
                            <StatusBadge variant="critical" size="sm">
                                Ongoing
                            </StatusBadge>
                        )}
                    </TableCell>
                    <TableCell>
                        {breach.sla_name ? (
                            <span className="block min-w-0">
                                {breach.sla_name}
                                {breach.sla_code ? (
                                    <span className="text-caption block">{breach.sla_code}</span>
                                ) : null}
                            </span>
                        ) : null}
                    </TableCell>
                    <TableCell className="pr-5 text-right">{overflowButton}</TableCell>
                </TableRow>
            )}
        </ControlRoomRowActions>
    );
}
