import { HalfMoonGauge, HorizontalBarChart } from '@/components/fleet-charts';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateOnly } from '@/lib/datetime';
import { ArrowUpRight, BarChart3 } from 'lucide-react';
import { createContext, useContext, type ReactNode } from 'react';
import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    hiddenLabel,
    hiddenMeasureLabel,
    shownCount,
    suppressionCaption,
    type ReferralReport,
    type ReportGroup,
} from './report-types';
import { label } from './types';

/** The report's small-group rule, for breakdowns rendered without the whole report. */
export const ReportPrivacyContext =
    createContext<ReferralReport['privacy']>(undefined);
const useReportPrivacy = () => ({ privacy: useContext(ReportPrivacyContext) });

export const reportMetric = (report: ReferralReport, key: string) =>
    report.metrics.find((metric) => metric.key === key);

/** A measure's number, or null when it is hidden by the small-group rule (never 0). */
export const metricValue = (report: ReferralReport, key: string) => {
    const metric = reportMetric(report, key);
    return metric ? metric.value : 0;
};

/** A measure as the page shows it: its number, or "Fewer than 5". */
export const metricText = (report: ReferralReport, key: string) => {
    const metric = reportMetric(report, key);
    return metric ? shownCount(report, metric.value, metric.suppressed) : '0';
};

/** Share of the cohort; null when either number is hidden by the small-group rule. */
export const metricPercent = (
    report: ReferralReport,
    key: string,
): number | null => {
    const total = metricValue(report, 'received');
    const part = metricValue(report, key);
    if (total === 0) return 0;
    return total === null || part === null ? null : (part / total) * 100;
};

/** A hidden figure in place of a chart: the words and the one-line reason. */
function HiddenFigure({
    report,
    text,
}: {
    report: Pick<ReferralReport, 'privacy'>;
    text: string;
}) {
    return (
        <div className="space-y-1 py-4 text-center">
            <p className="text-section-title">{text}</p>
            <p className="text-subtle">{suppressionCaption(report)}</p>
        </div>
    );
}

/** One report card: title, one-line description, optional header action. */
export function ReportPanel({
    title,
    description,
    action,
    className,
    children,
}: {
    title: string;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <Card className={className}>
            <CardHeader className="gap-1">
                <div className="flex items-start justify-between gap-3">
                    <CardTitle className="text-section-title">
                        <h2>{title}</h2>
                    </CardTitle>
                    {action}
                </div>
                {description && <p className="text-subtle">{description}</p>}
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

/** Received referrals and current successful-contact status by NZ received date. */
export function ReferralTrend({
    report,
    onOpen,
}: {
    report: ReferralReport;
    onOpen: () => void;
}) {
    // A hidden day is null, so the line skips it rather than dropping to 0.
    const data = report.trend.map((point) => ({
        ...point,
        label: formatDateOnly(point.date).replace(/ \d{4}$/, ''),
    }));
    const hiddenDays = report.trend.some(
        (point) => point.received_suppressed || point.contacted_suppressed,
    );
    return (
        <ReportPanel
            title="Referral intake & contact"
            description={report.definitions.trend}
            action={
                <Button variant="ghost" size="sm" onClick={onOpen}>
                    View referrals
                    <ArrowUpRight className="size-4" aria-hidden />
                </Button>
            }
        >
            <div className="space-y-4">
                <div className="flex flex-wrap gap-4 text-sm">
                    <span className="inline-flex items-center gap-2">
                        <span className="h-0.5 w-5 bg-chart-1" aria-hidden />
                        Received
                    </span>
                    <span className="inline-flex items-center gap-2">
                        <span
                            className="h-0.5 w-5 bg-muted-foreground"
                            aria-hidden
                        />
                        Successful contact recorded
                    </span>
                </div>
                {hiddenDays && (
                    <p className="text-subtle">
                        Days with fewer than {report.privacy?.threshold ?? 5}{' '}
                        referrals or contacts are left out of the line, and one
                        more day may be too, so nobody can be picked out.
                    </p>
                )}
                {data.length ? (
                    <div
                        className="h-64"
                        role="img"
                        aria-label="Line graph of referrals by received date and their current contact status. Exact values are under View chart data."
                    >
                        <ResponsiveContainer width="100%" height="100%">
                            <LineChart
                                data={data}
                                margin={{
                                    top: 10,
                                    right: 12,
                                    left: -18,
                                    bottom: 0,
                                }}
                            >
                                <CartesianGrid
                                    vertical={false}
                                    stroke="var(--border)"
                                />
                                <XAxis
                                    dataKey="label"
                                    tick={{
                                        fill: 'var(--muted-foreground)',
                                        fontSize: 11,
                                    }}
                                    tickLine={false}
                                    axisLine={false}
                                    minTickGap={18}
                                />
                                <YAxis
                                    allowDecimals={false}
                                    tick={{
                                        fill: 'var(--muted-foreground)',
                                        fontSize: 11,
                                    }}
                                    tickLine={false}
                                    axisLine={false}
                                />
                                <Tooltip
                                    contentStyle={{
                                        background: 'var(--popover)',
                                        border: '1px solid var(--border)',
                                        borderRadius: 'var(--radius-md)',
                                        color: 'var(--popover-foreground)',
                                    }}
                                    itemStyle={{
                                        color: 'var(--popover-foreground)',
                                    }}
                                />
                                <Line
                                    name="Received"
                                    dataKey="received"
                                    type="linear"
                                    stroke="var(--chart-1)"
                                    strokeWidth={2}
                                    strokeLinejoin="round"
                                    strokeLinecap="round"
                                    dot={
                                        data.length <= 40
                                            ? {
                                                  r: 4,
                                                  strokeWidth: 2,
                                                  stroke: 'var(--card)',
                                              }
                                            : false
                                    }
                                    isAnimationActive={false}
                                />
                                <Line
                                    name="Contact recorded"
                                    dataKey="contacted"
                                    type="linear"
                                    stroke="var(--muted-foreground)"
                                    strokeWidth={2}
                                    strokeLinejoin="round"
                                    strokeLinecap="round"
                                    dot={
                                        data.length <= 40
                                            ? {
                                                  r: 4,
                                                  strokeWidth: 2,
                                                  stroke: 'var(--card)',
                                              }
                                            : false
                                    }
                                    isAnimationActive={false}
                                />
                            </LineChart>
                        </ResponsiveContainer>
                    </div>
                ) : (
                    <EmptyState
                        variant="compact"
                        icon={BarChart3}
                        title="No referrals received in this period"
                    />
                )}
                <dl className="grid grid-cols-2 gap-4 border-t border-border pt-4">
                    {(['attempt', 'contact'] as const).map((key) => (
                        <div key={key}>
                            <dt className="text-caption">
                                Average hours to first{' '}
                                {key === 'attempt' ? 'attempt' : 'conversation'}
                            </dt>
                            <dd className="text-section-title tabular-nums">
                                {report.delays[key].suppressed
                                    ? 'Not shown'
                                    : (report.delays[key].mean_hours ??
                                      'Not recorded')}
                            </dd>
                            <dd className="text-caption">
                                {report.delays[key].suppressed
                                    ? hiddenMeasureLabel(report)
                                    : `From ${report.delays[key].count} referrals`}
                            </dd>
                        </div>
                    ))}
                </dl>
                <details>
                    <summary className="cursor-pointer text-sm font-medium">
                        View chart data
                    </summary>
                    <div className="mt-2 max-h-72 overflow-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Received</TableHead>
                                    <TableHead>Referrals</TableHead>
                                    <TableHead>Contact recorded</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {report.trend.map((point) => (
                                    <TableRow key={point.date}>
                                        <TableHead
                                            scope="row"
                                            className="font-normal"
                                        >
                                            {formatDateOnly(point.date)}
                                        </TableHead>
                                        <TableCell className="tabular-nums">
                                            {shownCount(
                                                report,
                                                point.received,
                                                point.received_suppressed,
                                            )}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {shownCount(
                                                report,
                                                point.contacted,
                                                point.contacted_suppressed,
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </details>
            </div>
        </ReportPanel>
    );
}

/** Measures that open their matching referrals. */
export function ReportMeasureLinks({
    report,
    keys,
    onOpen,
}: {
    report: ReferralReport;
    keys: string[];
    onOpen: (key: string) => void;
}) {
    const rows = keys
        .map((key) => report.metrics.find((metric) => metric.key === key))
        .filter((metric) => metric !== undefined);
    const list = (
        <ul className="divide-y divide-border">
            {rows.map((metric) => (
                <li key={metric.key}>
                    {/* eslint-disable-next-line no-restricted-syntax -- full-width measure row with label, definition and count */}
                    <button
                        type="button"
                        onClick={() => onOpen(metric.key)}
                        className="flex w-full items-center justify-between gap-3 rounded-md px-2 py-2.5 text-left text-sm transition-colors outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                        aria-label={`${metric.label}: ${shownCount(report, metric.value, metric.suppressed)}. View matching referrals`}
                    >
                        <span className="min-w-0">
                            <span className="block font-medium">
                                {metric.label}
                            </span>
                            <span className="text-caption block text-muted-foreground">
                                {metric.definition}
                            </span>
                        </span>
                        <span className="flex shrink-0 items-center gap-1.5 font-semibold tabular-nums">
                            {shownCount(
                                report,
                                metric.value,
                                metric.suppressed,
                            )}
                            <ArrowUpRight
                                className="size-4 text-muted-foreground"
                                aria-hidden
                            />
                        </span>
                    </button>
                </li>
            ))}
        </ul>
    );
    return rows.some((metric) => metric.suppressed) ? (
        <div className="space-y-2">
            {list}
            <p className="text-subtle px-2">{suppressionCaption(report)}</p>
        </div>
    ) : (
        list
    );
}

/** Conversion and commencement are separate milestones; the gauge shows hand-over only. */
export function ReferralHandoverGauge({
    report,
    onOpen,
}: {
    report: ReferralReport;
    onOpen: (key: string) => void;
}) {
    const total = metricValue(report, 'received');
    const converted = metricValue(report, 'converted');
    return (
        <ReportPanel
            title="Supported living handover"
            description="Handover and actual commencement are separate milestones."
        >
            <div className="space-y-4">
                {total === 0 ? (
                    <EmptyState
                        variant="compact"
                        icon={BarChart3}
                        title="No referrals in this scope"
                    />
                ) : total === null || converted === null ? (
                    // A hidden small group has no honest share to draw.
                    <HiddenFigure
                        report={report}
                        text={`${metricText(report, 'converted')} of ${metricText(report, 'received')} linked to a supported person`}
                    />
                ) : (
                    <div className="flex justify-center">
                        <HalfMoonGauge
                            value={(converted / total) * 100}
                            label="Linked to a supported person"
                            sublabel={`${converted} of ${total} referrals`}
                            color="var(--chart-1)"
                        />
                    </div>
                )}
                <ReportMeasureLinks
                    report={report}
                    keys={['converted', 'commenced', 'reopened']}
                    onOpen={onOpen}
                />
            </div>
        </ReportPanel>
    );
}

export function ReferralDistribution({
    title,
    rows,
    description,
    onSelect,
    unit = 'referrals',
    className,
}: {
    title: string;
    rows: ReportGroup[];
    description?: string;
    onSelect?: (key: string) => void;
    unit?: 'referrals' | 'cases';
    className?: string;
}) {
    return (
        <ReportPanel
            title={title}
            description={description}
            className={className}
        >
            <DistributionRows rows={rows} onSelect={onSelect} unit={unit} />
        </ReportPanel>
    );
}

/**
 * Bars encode counts only, so every bar takes one colour: the chart tokens
 * other than --chart-1 are green, red and amber and would read as status.
 * Pass `total` to show each row's share of the whole. A group hidden by the
 * small-group rule has no bar and can't be opened from here; it shows its
 * words ("Fewer than 5") and the breakdown gains a one-line reason.
 */
function DistributionRows({
    rows,
    onSelect,
    unit = 'referrals',
    total,
}: {
    rows: ReportGroup[];
    onSelect?: (key: string) => void;
    unit?: 'referrals' | 'cases';
    total?: number | null;
}) {
    const privacy = useReportPrivacy();
    const max = Math.max(1, ...rows.map((row) => row.count ?? 0));
    if (!rows.length) {
        return (
            <EmptyState
                variant="compact"
                icon={BarChart3}
                title="Nothing recorded in this scope"
            />
        );
    }
    const hidden = rows.some((row) => row.count === null);
    return (
        <div className="space-y-1">
            {rows.map((row) => {
                const name = row.label ?? label(row.key);
                if (row.count === null) {
                    return (
                        <div
                            key={row.key}
                            className="flex items-center justify-between gap-2 p-2 text-xs"
                        >
                            <span className="max-w-[60%] truncate text-muted-foreground">
                                {name}
                            </span>
                            <span className="font-medium">
                                {hiddenLabel(
                                    privacy,
                                    row.suppressed ?? 'small',
                                )}
                            </span>
                        </div>
                    );
                }
                const count = row.count;
                const share = total
                    ? ` · ${Math.round((count / total) * 100)}%`
                    : '';
                const chart = (
                    <HorizontalBarChart
                        items={[
                            {
                                label: name + share,
                                value: count,
                                maxValue: max,
                            },
                        ]}
                        color="var(--chart-1)"
                    />
                );
                // "Other reported regions" combines hidden regions, so it has no single list to open.
                return onSelect && row.regions === undefined ? (
                    // eslint-disable-next-line no-restricted-syntax -- full-width chart row that opens its matching records
                    <button
                        type="button"
                        key={row.key}
                        className="block w-full rounded-md p-2 text-left transition-colors outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                        onClick={() => onSelect(row.key)}
                        aria-label={`${name}: ${count} ${unit}. View matching records`}
                    >
                        {chart}
                    </button>
                ) : (
                    <div key={row.key} className="p-2">
                        {chart}
                    </div>
                );
            })}
            {hidden && (
                <p className="text-subtle px-2 pt-1">
                    {suppressionCaption(privacy, unit)}
                </p>
            )}
        </div>
    );
}

/**
 * Intake channel as ranked bars with each channel's share. A categorical
 * donut needs distinct non-status series colours, which the app doesn't have
 * until the proposed chart tokens land (design refresh CHARTS.md).
 */
export function ReferralSourceChart({
    rows,
    onSelect,
}: {
    rows: ReportGroup[];
    onSelect: (key: string) => void;
}) {
    // With a hidden channel the shown rows no longer add up to the cohort, so no shares are given.
    const complete = rows.every((row) => row.count !== null);
    const total = complete
        ? rows.reduce((sum, row) => sum + (row.count ?? 0), 0)
        : null;
    return (
        <ReportPanel
            title="Where referrals come from"
            description={`${total === null ? 'Referrals' : `${total} referrals`} by recorded intake channel, including open, closed and handed-over referrals.`}
        >
            <DistributionRows rows={rows} onSelect={onSelect} total={total} />
        </ReportPanel>
    );
}

/** Every measure with its definition — the reference table for Detailed reports. */
export function ReportMeasuresTable({
    report,
    selected,
    onOpen,
}: {
    report: ReferralReport;
    selected: string;
    onOpen: (key: string) => void;
}) {
    return (
        <ReportPanel
            title="Measures & definitions"
            description="Select a measure to list its matching referrals below."
        >
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Measure</TableHead>
                        <TableHead className="hidden lg:table-cell">
                            Definition
                        </TableHead>
                        <TableHead className="text-right">Referrals</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {report.metrics.map((metric) => (
                        <TableRow
                            key={metric.key}
                            data-state={
                                selected === metric.key ? 'selected' : undefined
                            }
                        >
                            <TableCell className="font-medium">
                                <Button
                                    variant="link"
                                    className="h-auto p-0 text-left whitespace-normal"
                                    onClick={() => onOpen(metric.key)}
                                    aria-pressed={selected === metric.key}
                                >
                                    {metric.label}
                                </Button>
                            </TableCell>
                            <TableCell className="hidden text-muted-foreground lg:table-cell">
                                {metric.definition}
                            </TableCell>
                            <TableCell className="text-right font-semibold tabular-nums">
                                {shownCount(
                                    report,
                                    metric.value,
                                    metric.suppressed,
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
            {report.metrics.some((metric) => metric.suppressed) && (
                <p className="text-subtle mt-3">{suppressionCaption(report)}</p>
            )}
        </ReportPanel>
    );
}
