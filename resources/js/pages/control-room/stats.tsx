import { plainLabel } from '@/components/control-room/alert-record/alert-labels';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    CategoryBars,
    NOT_MEASURED,
    ReportPanel,
    SEVERITY_LABEL,
    SEVERITY_ORDER,
    TrendChart,
    measured,
    showSection,
    trendLabel,
    type ChartRow,
} from '@/components/control-room/reports/report-parts';
import {
    PageHeaderFilterSelect,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime, formatRelative, formatTime } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import { ArrowUpRight, CalendarRange, Clock, Radio, Users } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    period: string;
    kpis?: {
        avg_acknowledge_minutes: number | null;
        avg_resolution_hours: number | null;
        sla_compliance_pct: number | null;
        open_alerts: number | null;
        alerts_today: number | null;
    } | null;
    volume_trend?: { label: string; count: number }[];
    top_sources?: { name: string; count: number }[];
    top_alert_types?: { name: string; count: number }[];
    severity_distribution?: Record<string, number>;
    operator_performance?: {
        name: string;
        alerts_handled: number;
        avg_response_minutes: number | null;
    }[];
    shift_comparison?: {
        name: string;
        duration_hours: number;
        alerts_created: number;
        alerts_resolved: number;
        alerts_escalated: number;
    }[];
    signal_sources?: {
        name: string;
        status: string;
        last_heartbeat_at: string | null;
        signal_count_24h: number;
        is_healthy: boolean;
    }[];
}

const BASE = '/control-room/stats';

const PERIODS = [
    { value: '24h', label: 'Last 24 hours' },
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
];

/** Props the 30-second refresh reloads. */
const LIVE_PROPS = [
    'kpis',
    'volume_trend',
    'top_sources',
    'top_alert_types',
    'severity_distribution',
    'operator_performance',
    'shift_comparison',
    'signal_sources',
];

function countRows(rows: { name: string; count: number }[] | undefined): ChartRow[] {
    return (rows ?? []).map((row) => ({
        key: row.name,
        label: plainLabel(row.name) || 'Not recorded',
        value: Number(row.count) || 0,
    }));
}

/**
 * Reports › Trends — how alert volume, response speed and workload change
 * over a period, refreshed every 30 seconds. Every figure the server did not
 * record reads "Not measured", never 0.
 */
export default function ControlRoomStats({
    period,
    kpis,
    volume_trend,
    top_sources,
    top_alert_types,
    severity_distribution,
    operator_performance = [],
    shift_comparison = [],
    signal_sources = [],
}: Props) {
    const [updatedAt, setUpdatedAt] = useState(() => new Date());
    const [refreshing, setRefreshing] = useState(false);

    useEffect(() => {
        const poll = window.setInterval(() => {
            if (document.hidden) return;
            setRefreshing(true);
            router.reload({
                only: LIVE_PROPS,
                preserveScroll: true,
                onSuccess: () => setUpdatedAt(new Date()),
                onFinish: () => setRefreshing(false),
            });
        }, 30_000);
        return () => window.clearInterval(poll);
    }, []);

    const choosePeriod = (value: string) =>
        router.get(BASE, { period: value }, { preserveState: true, preserveScroll: true });

    const periodName = PERIODS.find((p) => p.value === period)?.label ?? 'Last 7 days';
    const hourly = period === '24h';

    const openAlerts = measured(kpis?.open_alerts);
    const alertsToday = measured(kpis?.alerts_today);
    const compliance = measured(kpis?.sla_compliance_pct);
    const avgAcknowledge = measured(kpis?.avg_acknowledge_minutes, { zeroIsMissing: true });
    const avgResolution = measured(kpis?.avg_resolution_hours, { zeroIsMissing: true });

    const trendRows: ChartRow[] = (volume_trend ?? []).map((point, index) => ({
        key: `${index}-${point.label}`,
        label: trendLabel(point.label),
        value: Number(point.count) || 0,
    }));
    const severityRows: ChartRow[] = SEVERITY_ORDER.map((key) => ({
        key,
        label: SEVERITY_LABEL[key],
        value: Number(severity_distribution?.[key]) || 0,
    }));
    const busiest = Math.max(1, ...operator_performance.map((op) => op.alerts_handled));
    // A column that would read "Not measured" on every row is left out.
    const ackKnown = operator_performance.some(
        (op) => measured(op.avg_response_minutes, { zeroIsMissing: true }) !== null,
    );

    return (
        <ControlRoomPage
            header={{
                subline: (
                    <>
                        How alert volume, response speed and workload change over time ·{' '}
                        <span aria-live="polite">
                            {refreshing ? 'Refreshing…' : `Updated ${formatTime(updatedAt)}`}
                        </span>
                    </>
                ),
                meters: (
                    <>
                        <PageHeaderMeterBlock
                            label="Open alerts"
                            tone={openAlerts ? 'warning' : 'brand'}
                            href="/control-room"
                            ariaLabel="Show the alerts that need action"
                        >
                            <PageHeaderMeterBig>{openAlerts ?? NOT_MEASURED}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>need action now</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Alerts today"
                            onClick={() => showSection('volume-trend')}
                            ariaLabel="Show alert volume over time"
                        >
                            <PageHeaderMeterBig>{alertsToday ?? NOT_MEASURED}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>since midnight</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Targets met"
                            tone={compliance !== null && compliance < 90 ? 'warning' : 'brand'}
                            href="/control-room/sla/breaches"
                            ariaLabel="Show missed response targets"
                        >
                            <PageHeaderMeterBig>
                                {compliance === null ? NOT_MEASURED : `${compliance}%`}
                            </PageHeaderMeterBig>
                            {compliance !== null ? <PageHeaderMeterBar percent={compliance} /> : null}
                            <PageHeaderMeterCaption>
                                {compliance === null
                                    ? 'no response targets finished yet'
                                    : `response targets met · ${periodName.toLowerCase()}`}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Average acknowledge"
                            onClick={() => showSection('operators')}
                            ariaLabel="Show response speed by operator"
                        >
                            <PageHeaderMeterBig>
                                {avgAcknowledge === null ? NOT_MEASURED : `${avgAcknowledge} min`}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {avgResolution === null
                                    ? 'resolution time not measured'
                                    : `resolved in ${avgResolution} h on average`}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                ),
                filters: (
                    <PageHeaderFilterSelect
                        icon={CalendarRange}
                        label="Last 7 days"
                        allValue="7d"
                        value={period}
                        options={PERIODS}
                        onChange={choosePeriod}
                    />
                ),
            }}
        >
            <div className="grid min-w-0 gap-5 lg:grid-cols-2">
                <ReportPanel
                    id="volume-trend"
                    title="Alert volume"
                    description={hourly ? 'Alerts per hour over the last 24 hours.' : `Alerts per day · ${periodName.toLowerCase()}.`}
                >
                    <TrendChart
                        rows={trendRows}
                        kind="line"
                        labelHeading={hourly ? 'Hour' : 'Day'}
                        emptyTitle="No alerts in this period"
                        emptyDescription="Try a longer period."
                    />
                </ReportPanel>
                <ReportPanel
                    id="open-by-severity"
                    title="Open alerts by severity"
                    description="Alerts that still need action, right now."
                >
                    <CategoryBars
                        rows={severityRows}
                        labelHeading="Severity"
                        showShare
                        emptyTitle="No open alerts"
                        emptyDescription="Everything raised has been dealt with."
                    />
                </ReportPanel>
                <ReportPanel
                    id="top-sources"
                    title="Top sources"
                    description={`Where alerts came from · ${periodName.toLowerCase()}.`}
                >
                    <CategoryBars
                        rows={countRows(top_sources)}
                        labelHeading="Source"
                        emptyTitle="No alerts in this period"
                    />
                </ReportPanel>
                <ReportPanel
                    id="top-types"
                    title="Top alert types"
                    description={`The most common kinds of alert · ${periodName.toLowerCase()}.`}
                >
                    <CategoryBars
                        rows={countRows(top_alert_types)}
                        labelHeading="Alert type"
                        emptyTitle="No alerts in this period"
                    />
                </ReportPanel>

                <ReportPanel
                    id="operators"
                    title="Operator workload"
                    description={
                        ackKnown
                            ? `Alerts assigned to each person, and how long they took to acknowledge after assignment · ${periodName.toLowerCase()}.`
                            : `Alerts assigned to each person · ${periodName.toLowerCase()}.`
                    }
                    className="lg:col-span-2"
                >
                    {operator_performance.length ? (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Operator</TableHead>
                                    <TableHead className="text-right">Alerts handled</TableHead>
                                    {ackKnown ? (
                                        <TableHead className="text-right">
                                            Average time to acknowledge
                                        </TableHead>
                                    ) : null}
                                    <TableHead className="hidden w-[30%] sm:table-cell">
                                        <span className="sr-only">Share of the busiest operator</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {operator_performance.map((op, index) => {
                                    const avg = measured(op.avg_response_minutes, {
                                        zeroIsMissing: true,
                                    });
                                    return (
                                        <TableRow key={`${op.name}-${index}`}>
                                            <TableHead scope="row" className="font-medium text-foreground">
                                                {op.name}
                                            </TableHead>
                                            <TableCell className="text-right tabular-nums">
                                                {op.alerts_handled}
                                            </TableCell>
                                            {ackKnown ? (
                                                <TableCell className="text-right tabular-nums">
                                                    {avg === null ? (
                                                        <span className="text-muted-foreground">
                                                            {NOT_MEASURED}
                                                        </span>
                                                    ) : (
                                                        `${avg} min`
                                                    )}
                                                </TableCell>
                                            ) : null}
                                            <TableCell className="hidden sm:table-cell">
                                                <span
                                                    aria-hidden="true"
                                                    className="block h-2 overflow-hidden rounded-full bg-muted"
                                                >
                                                    <span
                                                        className="block h-full rounded-full bg-chart-1"
                                                        style={{
                                                            width: `${(op.alerts_handled / busiest) * 100}%`,
                                                        }}
                                                    />
                                                </span>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    ) : (
                        <EmptyState
                            variant="compact"
                            icon={Users}
                            title="No assigned alerts in this period"
                            description="Workload shows once alerts are assigned to people."
                        />
                    )}
                </ReportPanel>

                <ReportPanel
                    id="recent-duties"
                    title="Recent duties"
                    description="The last five finished duties side by side."
                    action={
                        <Link
                            href="/control-room/shifts"
                            className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                        >
                            Duty handover
                            <ArrowUpRight aria-hidden="true" className="size-4" />
                        </Link>
                    }
                >
                    {shift_comparison.length ? (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Duty</TableHead>
                                    <TableHead className="text-right">Length</TableHead>
                                    <TableHead className="text-right">Created</TableHead>
                                    <TableHead className="text-right">Resolved</TableHead>
                                    <TableHead className="text-right">Escalated</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {shift_comparison.map((duty, index) => (
                                    <TableRow key={`${duty.name}-${index}`}>
                                        <TableHead scope="row" className="font-medium whitespace-normal text-foreground">
                                            {duty.name}
                                        </TableHead>
                                        <TableCell className="text-right tabular-nums">
                                            {duty.duration_hours} h
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {duty.alerts_created}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {duty.alerts_resolved}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {duty.alerts_escalated}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    ) : (
                        <EmptyState
                            variant="compact"
                            icon={Clock}
                            title="No finished duties to compare"
                            description="Duty counts cover every site, so they show only for people who can see all sites."
                        />
                    )}
                </ReportPanel>

                <ReportPanel
                    id="signal-sources"
                    title="Signal sources"
                    description="Whether each connected source has checked in during the last 10 minutes."
                    action={
                        <Link
                            href="/control-room/devices"
                            className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                        >
                            Signals
                            <ArrowUpRight aria-hidden="true" className="size-4" />
                        </Link>
                    }
                >
                    {signal_sources.length ? (
                        <ul className="divide-y divide-border">
                            {signal_sources.map((source, index) => (
                                <li
                                    key={`${source.name}-${index}`}
                                    className="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 py-2.5 first:pt-0 last:pb-0"
                                >
                                    <span className="min-w-0">
                                        <span className="block font-medium">{source.name}</span>
                                        <span
                                            className="text-caption block"
                                            title={
                                                source.last_heartbeat_at
                                                    ? formatDateTime(source.last_heartbeat_at)
                                                    : undefined
                                            }
                                        >
                                            {source.last_heartbeat_at
                                                ? `Last check-in ${formatRelative(source.last_heartbeat_at)}`
                                                : 'Has never checked in'}{' '}
                                            · {source.signal_count_24h} signals in 24 hours
                                        </span>
                                    </span>
                                    <StatusBadge variant={source.is_healthy ? 'success' : 'critical'}>
                                        {source.is_healthy ? 'Checking in' : 'Not checking in'}
                                    </StatusBadge>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <EmptyState
                            variant="compact"
                            icon={Radio}
                            title="No signal sources set up"
                            description="Sources are added under Settings › Sources."
                        />
                    )}
                </ReportPanel>
            </div>
        </ControlRoomPage>
    );
}
