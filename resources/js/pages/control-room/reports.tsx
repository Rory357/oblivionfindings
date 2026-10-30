import { plainLabel } from '@/components/control-room/alert-record/alert-labels';
import { ControlRoomPage } from '@/components/control-room/control-room-page';
import {
    CategoryBars,
    HeaderDateRange,
    NOT_MEASURED,
    ReportPanel,
    SEVERITY_LABEL,
    SEVERITY_ORDER,
    TrendChart,
    measured,
    showSection,
    type ChartRow,
} from '@/components/control-room/reports/report-parts';
import {
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
} from '@/components/page';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import { ALERT_STATUS_VOCAB } from '@/lib/control-room-vocab';
import { Link, router, usePage } from '@inertiajs/react';
import { ArrowUpRight, CalendarRange, Download, Timer, Users } from 'lucide-react';

type SeverityTargets = {
    total: number;
    assessed_total: number;
    met: number;
    breached: number;
    in_progress: number;
    compliance_pct: number | null;
};

interface Props {
    /** 7d · 30d · 90d · 1y, or "custom" when date_from and date_to are in the address. */
    period: string;
    site_id?: number | null;
    stats: {
        total_alerts: number;
        resolved_alerts: number;
        resolution_rate: number;
        avg_resolution_hours: number;
        escalated_count: number;
        escalation_rate: number;
    };
    by_severity: Record<string, number>;
    by_status: Record<string, number>;
    by_source: Record<string, number>;
    by_alert_type: Record<string, number>;
    daily_trend: { date: string; count: number }[];
    /** Minutes per severity; the server does not record these yet, so it is often empty. */
    response_time_by_severity: Record<string, number>;
    top_assignees: { user: string; count: number }[];
    sla?: {
        total_with_sla: number;
        avg_acknowledge_minutes: number;
        avg_response_minutes: number;
        by_severity?: Record<string, SeverityTargets>;
    };
    escalation?: {
        escalated: number;
        by_level?: Record<string, number>;
        stuck_at_high_escalation?: number;
    };
}

const BASE = '/control-room/reports';

const PERIODS = [
    { value: '7d', label: 'Last 7 days' },
    { value: '30d', label: 'Last 30 days' },
    { value: '90d', label: 'Last 90 days' },
    { value: '1y', label: 'Last year' },
    { value: 'custom', label: 'Choose dates' },
];

const DEFAULT_PERIOD = '30d';

function queryOf(url: string) {
    return new URL(url, 'https://control-room.invalid').searchParams;
}

/** The report's scope as query params — the same set the export link carries. */
function scopeParams({
    period,
    from,
    to,
    siteId,
}: {
    period: string;
    from?: string;
    to?: string;
    siteId?: number | null;
}): Record<string, string> {
    const params: Record<string, string> =
        period === 'custom' && from && to
            ? { date_from: from, date_to: to }
            : { period };
    if (siteId) params.site_id = String(siteId);
    return params;
}

function minutes(value: number) {
    return `${Math.round(value)} min`;
}

/**
 * Reports › Overview — what happened over a period: alert volume, outcomes,
 * escalation and response times, with the CSV export of the same scope.
 * Each header number points at the panel that breaks it down.
 */
export default function ControlRoomReports({
    period,
    site_id: siteId = null,
    stats,
    by_severity,
    by_status,
    by_source,
    by_alert_type,
    daily_trend,
    response_time_by_severity,
    top_assignees,
    sla,
    escalation,
}: Props) {
    const page = usePage();
    const query = queryOf(page.url);
    const from = query.get('date_from') ?? '';
    const to = query.get('date_to') ?? '';
    const custom = period === 'custom' && Boolean(from && to);
    const periodValue = custom ? 'custom' : period;
    const periodName = custom
        ? `${formatDateOnly(from)} – ${formatDateOnly(to)}`
        : (PERIODS.find((p) => p.value === period)?.label ?? 'Last 30 days');

    const visit = (params: Record<string, string>) =>
        router.get(BASE, params, { preserveState: true, preserveScroll: true });

    const choosePeriod = (value: string) => {
        if (value === 'custom') {
            const today = toDateInput(new Date());
            const start = toDateInput(Date.now() - 30 * 86_400_000);
            visit(scopeParams({ period: 'custom', from: from || start, to: to || today, siteId }));
            return;
        }
        visit(scopeParams({ period: value, siteId }));
    };

    const exportHref = `${BASE}/export?${new URLSearchParams(
        scopeParams({ period: periodValue, from, to, siteId }),
    ).toString()}`;

    const total = stats.total_alerts;
    const resolutionRate = total > 0 ? stats.resolution_rate : null;
    const escalationRate = total > 0 ? stats.escalation_rate : null;
    const avgResolution = measured(stats.avg_resolution_hours, { zeroIsMissing: true });
    const avgAcknowledge = measured(sla?.avg_acknowledge_minutes, { zeroIsMissing: true });
    const avgResponse = measured(sla?.avg_response_minutes, { zeroIsMissing: true });

    const trendRows: ChartRow[] = (daily_trend ?? []).map((day) => ({
        key: day.date,
        label: formatDateOnly(day.date, day.date).replace(/ \d{4}$/, ''),
        value: Number(day.count) || 0,
    }));
    const severityRows: ChartRow[] = SEVERITY_ORDER.map((key) => ({
        key,
        label: SEVERITY_LABEL[key],
        value: Number(by_severity?.[key]) || 0,
    }));
    const statusRows: ChartRow[] = Object.entries(by_status ?? {}).map(([key, count]) => ({
        key,
        label: ALERT_STATUS_VOCAB[key]?.label ?? plainLabel(key),
        value: Number(count) || 0,
    }));
    const sourceRows: ChartRow[] = Object.entries(by_source ?? {}).map(([key, count]) => ({
        key,
        label: plainLabel(key) || 'Not recorded',
        value: Number(count) || 0,
    }));
    const typeRows: ChartRow[] = Object.entries(by_alert_type ?? {})
        .slice(0, 10)
        .map(([key, count]) => ({
            key,
            label: plainLabel(key) || 'Not recorded',
            value: Number(count) || 0,
        }));
    const levelRows: ChartRow[] = Object.entries(escalation?.by_level ?? {}).map(
        ([level, count]) => ({
            key: level,
            label: `Level ${level}`,
            value: Number(count) || 0,
        }),
    );
    const stuck = escalation?.stuck_at_high_escalation ?? 0;

    // Only what was measured: a severity with no response targets in the
    // period is left out, and so is a column that would be empty on every row.
    const averageEntries: Array<[string, number | null]> = [
        ['Average time to acknowledge', avgAcknowledge],
        ['Average time to respond', avgResponse],
    ];
    const averages = averageEntries.filter(
        (entry): entry is [string, number] => entry[1] !== null,
    );
    const targetRows = SEVERITY_ORDER.map((key) => ({
        key,
        time: measured(response_time_by_severity?.[key], { zeroIsMissing: true }),
        targets: sla?.by_severity?.[key],
    })).filter((row) => row.time !== null || (row.targets?.total ?? 0) > 0);
    const timesKnown = targetRows.some((row) => row.time !== null);

    return (
        <ControlRoomPage
            header={{
                subline: `Alert volume, outcomes and response times · ${periodName}`,
                actions: (
                    <PageHeaderGlassButton icon={Download} asChild>
                        <a href={exportHref}>Export CSV</a>
                    </PageHeaderGlassButton>
                ),
                meters: (
                    <>
                        <PageHeaderMeterBlock
                            label="Total alerts"
                            onClick={() => showSection('daily-trend')}
                            ariaLabel="Show alerts by day"
                        >
                            <PageHeaderMeterBig>{total}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>raised in this period</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Resolution rate"
                            onClick={() => showSection('by-status')}
                            ariaLabel="Show alerts by status"
                        >
                            <PageHeaderMeterBig>
                                {resolutionRate === null ? NOT_MEASURED : `${resolutionRate}%`}
                            </PageHeaderMeterBig>
                            {resolutionRate !== null ? (
                                <PageHeaderMeterBar percent={resolutionRate} />
                            ) : null}
                            <PageHeaderMeterCaption>
                                {resolutionRate === null
                                    ? 'no alerts in this period'
                                    : `${stats.resolved_alerts} resolved or closed`}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Average resolution"
                            onClick={() => showSection('response-times')}
                            ariaLabel="Show response times"
                        >
                            <PageHeaderMeterBig>
                                {avgResolution === null ? NOT_MEASURED : `${avgResolution} h`}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {avgResolution === null
                                    ? 'no timed resolutions yet'
                                    : 'per alert with a response target'}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Escalation rate"
                            tone={stats.escalated_count > 0 ? 'warning' : 'brand'}
                            onClick={() => showSection('escalations')}
                            ariaLabel="Show escalations by level"
                        >
                            <PageHeaderMeterBig>
                                {escalationRate === null ? NOT_MEASURED : `${escalationRate}%`}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {escalationRate === null
                                    ? 'no alerts in this period'
                                    : `${stats.escalated_count} escalated`}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                ),
                filters: (
                    <>
                        <PageHeaderFilterSelect
                            icon={CalendarRange}
                            label="Last 30 days"
                            allValue={DEFAULT_PERIOD}
                            value={periodValue}
                            options={PERIODS}
                            onChange={choosePeriod}
                        />
                        {custom ? (
                            <HeaderDateRange
                                idPrefix="reports-range"
                                from={from}
                                to={to}
                                onChange={(nextFrom, nextTo) =>
                                    visit(
                                        scopeParams({
                                            period: 'custom',
                                            from: nextFrom,
                                            to: nextTo,
                                            siteId,
                                        }),
                                    )
                                }
                            />
                        ) : null}
                    </>
                ),
            }}
        >
            {total === 0 ? (
                <Card
                    id="report-empty"
                    tabIndex={-1}
                    className="min-w-0 px-5 py-10 outline-none focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <EmptyState
                        icon={CalendarRange}
                        title="No alerts in this period"
                        description="Nothing was raised in the dates chosen. Pick a longer period in the header to see more."
                    />
                </Card>
            ) : (
            <div className="grid min-w-0 gap-5 lg:grid-cols-2">
                <ReportPanel
                    id="daily-trend"
                    title="Alerts by day"
                    description="Every alert raised in the period, by the day it came in."
                >
                    <TrendChart
                        rows={trendRows}
                        emptyTitle="No alerts in this period"
                        emptyDescription="Try a longer period."
                    />
                </ReportPanel>
                <ReportPanel
                    id="by-severity"
                    title="By severity"
                    description="How serious the alerts in this period were."
                >
                    <CategoryBars
                        rows={severityRows}
                        labelHeading="Severity"
                        showShare
                        emptyTitle="No alerts in this period"
                    />
                </ReportPanel>
                <ReportPanel
                    id="by-status"
                    title="By status"
                    description="Where the alerts raised in this period are now."
                >
                    <CategoryBars
                        rows={statusRows}
                        labelHeading="Status"
                        showShare
                        emptyTitle="No alerts in this period"
                    />
                </ReportPanel>
                <ReportPanel
                    id="escalations"
                    title="Escalations by level"
                    description="Alerts that needed a higher response level, by the highest level reached."
                    action={
                        stuck > 0 ? (
                            <Link
                                href="/control-room/escalations"
                                className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                            >
                                {stuck} still open at level 3 or above
                                <ArrowUpRight aria-hidden="true" className="size-4" />
                            </Link>
                        ) : null
                    }
                >
                    <CategoryBars
                        rows={levelRows}
                        labelHeading="Level"
                        emptyTitle="Nothing was escalated"
                        emptyDescription="No alert in this period needed a higher response level."
                    />
                </ReportPanel>
                <ReportPanel
                    id="response-times"
                    title="Response time by severity"
                    description="How quickly alerts were answered, and how often the response target was met."
                    className="lg:col-span-2"
                >
                    {averages.length ? (
                        <dl className="grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                            {averages.map(([label, value]) => (
                                <div
                                    key={label}
                                    className="flex items-baseline justify-between gap-3 border-b border-border pb-2"
                                >
                                    <dt className="text-muted-foreground">{label}</dt>
                                    <dd className="font-semibold tabular-nums">{minutes(value)}</dd>
                                </div>
                            ))}
                        </dl>
                    ) : null}
                    {targetRows.length ? (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Severity</TableHead>
                                    {timesKnown ? (
                                        <TableHead className="text-right">Average response</TableHead>
                                    ) : null}
                                    <TableHead className="text-right">Targets met</TableHead>
                                    <TableHead className="text-right">Targets missed</TableHead>
                                    <TableHead className="text-right">Met on time</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {targetRows.map(({ key, time, targets }) => (
                                    <TableRow key={key}>
                                        <TableHead scope="row" className="font-medium text-foreground">
                                            {SEVERITY_LABEL[key]}
                                        </TableHead>
                                        {timesKnown ? (
                                            <TableCell className="text-right tabular-nums">
                                                {time === null ? (
                                                    <span className="text-muted-foreground">
                                                        {NOT_MEASURED}
                                                    </span>
                                                ) : (
                                                    minutes(time)
                                                )}
                                            </TableCell>
                                        ) : null}
                                        <TableCell className="text-right tabular-nums">
                                            {targets?.met ?? 0}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {targets?.breached ?? 0}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {targets?.compliance_pct != null ? (
                                                `${targets.compliance_pct}%`
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    {targets?.in_progress ? 'Still running' : NOT_MEASURED}
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    ) : (
                        <EmptyState
                            variant="compact"
                            icon={Timer}
                            title="No response targets ran in this period"
                            description="Times show here once alerts with a response target come in."
                        />
                    )}
                    {targetRows.length && !timesKnown ? (
                        <p className="text-caption">
                            Average response time by severity isn&apos;t recorded yet.
                        </p>
                    ) : null}
                    <p className="text-caption">
                        Missed response targets are listed under{' '}
                        <Link href="/control-room/sla/breaches" className="font-medium text-primary hover:underline">
                            Target breaches
                        </Link>
                        .
                    </p>
                </ReportPanel>
                <ReportPanel
                    id="by-source"
                    title="By source"
                    description="Where the alerts came from."
                >
                    <CategoryBars
                        rows={sourceRows}
                        labelHeading="Source"
                        emptyTitle="No alerts in this period"
                    />
                </ReportPanel>
                <ReportPanel
                    id="by-type"
                    title="Top alert types"
                    description="The ten most common kinds of alert."
                >
                    <CategoryBars
                        rows={typeRows}
                        labelHeading="Alert type"
                        emptyTitle="No alerts in this period"
                    />
                </ReportPanel>
                <ReportPanel
                    id="assignees"
                    title="Top assignees"
                    description="Who handled the most alerts in this period."
                    className="lg:col-span-2"
                >
                    {top_assignees?.length ? (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Assignee</TableHead>
                                    <TableHead className="text-right">Alerts handled</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {top_assignees.map((row, index) => (
                                    <TableRow key={`${row.user}-${index}`}>
                                        <TableHead scope="row" className="font-medium text-foreground">
                                            {row.user}
                                        </TableHead>
                                        <TableCell className="text-right tabular-nums">
                                            {row.count}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    ) : (
                        <EmptyState
                            variant="compact"
                            icon={Users}
                            title="No assignments in this period"
                            description="No alert in this period was assigned to a person."
                        />
                    )}
                </ReportPanel>
            </div>
            )}
        </ControlRoomPage>
    );
}
