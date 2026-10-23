import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { PageHeaderFilterButton } from '@/components/page';
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
import { formatDateOnly } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { BarChart3, CalendarDays, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    LabelList,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

/**
 * Shared pieces of the Control Room Reports views (Overview, Trends): a
 * report panel, one-hue bar and trend charts on the chart tokens, and the
 * "Not measured" wording for a value the backend did not record — never a
 * fake 0 (DESIGN.md dataviz + plain-language rules).
 */

export const NOT_MEASURED = 'Not measured';

/** A number that may be missing: null/undefined, or a 0 the source cannot tell apart from "none". */
export function measured(
    value: number | null | undefined,
    { zeroIsMissing = false }: { zeroIsMissing?: boolean } = {},
): number | null {
    if (value === null || value === undefined || !Number.isFinite(value))
        return null;
    if (zeroIsMissing && value <= 0) return null;
    return value;
}

/** Scroll a report panel into view and move focus to it (meter blocks use this). */
export function showSection(id: string) {
    const el = typeof document === 'undefined' ? null : document.getElementById(id);
    if (!el) return;
    const reduce =
        typeof window !== 'undefined' &&
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    el.scrollIntoView?.({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
    el.focus({ preventScroll: true });
}

/** One report card: title, one line, optional action on the right. */
export function ReportPanel({
    id,
    title,
    description,
    action,
    className,
    children,
}: {
    /** Anchor for the header meter that points here. */
    id?: string;
    title: string;
    description?: ReactNode;
    action?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <Card
            id={id}
            tabIndex={id ? -1 : undefined}
            aria-labelledby={id ? `${id}-title` : undefined}
            className={cn(
                'min-w-0 scroll-mt-5 gap-4 px-5 py-5 outline-none focus-visible:ring-2 focus-visible:ring-ring',
                className,
            )}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 id={id ? `${id}-title` : undefined} className="text-section-title">
                        {title}
                    </h2>
                    {description ? (
                        <p className="text-subtle mt-0.5">{description}</p>
                    ) : null}
                </div>
                {action}
            </div>
            {children}
        </Card>
    );
}

const TICK = { fill: 'var(--muted-foreground)', fontSize: 12 };

const TOOLTIP_STYLE = {
    contentStyle: {
        background: 'var(--popover)',
        border: '1px solid var(--border)',
        borderRadius: 'var(--radius-md)',
        color: 'var(--popover-foreground)',
        fontSize: 12,
    },
    itemStyle: { color: 'var(--popover-foreground)' },
    labelStyle: { color: 'var(--popover-foreground)', fontWeight: 600 },
};

export type ChartRow = { key: string; label: string; value: number };

/** The exact values behind a chart, for screen readers and anyone who wants the numbers. */
function ChartData({
    rows,
    labelHeading,
    valueHeading,
    total,
}: {
    rows: ChartRow[];
    labelHeading: string;
    valueHeading: string;
    /** Adds a share-of-total column. */
    total?: number;
}) {
    return (
        <details className="text-sm">
            <summary className="cursor-pointer font-medium text-muted-foreground hover:text-foreground">
                View chart data
            </summary>
            <div className="mt-2 max-h-72 overflow-auto scrollbar-pretty">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{labelHeading}</TableHead>
                            <TableHead className="text-right">{valueHeading}</TableHead>
                            {total ? (
                                <TableHead className="text-right">Share</TableHead>
                            ) : null}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={row.key}>
                                <TableHead scope="row" className="font-normal">
                                    {row.label}
                                </TableHead>
                                <TableCell className="text-right tabular-nums">
                                    {row.value}
                                </TableCell>
                                {total ? (
                                    <TableCell className="text-right tabular-nums">
                                        {Math.round((row.value / total) * 100)}%
                                    </TableCell>
                                ) : null}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </details>
    );
}

/**
 * Magnitude by category — one hue (chart-1), horizontal bars so long labels
 * stay readable, the value at each bar end in text ink.
 */
export function CategoryBars({
    rows,
    unit = 'Alerts',
    labelHeading = 'Category',
    showShare = false,
    emptyTitle,
    emptyDescription,
    emptyIcon = BarChart3,
}: {
    rows: ChartRow[];
    /** The value's name in the tooltip and data table, e.g. "Alerts". */
    unit?: string;
    labelHeading?: string;
    showShare?: boolean;
    emptyTitle: string;
    emptyDescription?: string;
    emptyIcon?: LucideIcon;
}) {
    const total = rows.reduce((sum, row) => sum + row.value, 0);
    if (rows.length === 0 || total === 0) {
        return (
            <EmptyState
                variant="compact"
                icon={emptyIcon}
                title={emptyTitle}
                description={emptyDescription}
            />
        );
    }
    const height = rows.length * 34 + 12;
    const summary = rows.map((row) => `${row.label} ${row.value}`).join(', ');
    return (
        <div className="grid gap-3">
            <div
                className="w-full"
                style={{ height }}
                role="img"
                aria-label={`${unit} by ${labelHeading.toLowerCase()}: ${summary}`}
            >
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart
                        data={rows}
                        layout="vertical"
                        margin={{ top: 0, right: 40, bottom: 0, left: 0 }}
                        barCategoryGap={8}
                    >
                        <CartesianGrid horizontal={false} stroke="var(--border)" />
                        <XAxis
                            type="number"
                            allowDecimals={false}
                            tick={TICK}
                            tickLine={false}
                            axisLine={false}
                            hide
                        />
                        <YAxis
                            type="category"
                            dataKey="label"
                            width={150}
                            tick={TICK}
                            tickLine={false}
                            axisLine={false}
                            tickFormatter={(value: string) =>
                                value.length > 22 ? `${value.slice(0, 21)}…` : value
                            }
                        />
                        <Tooltip
                            cursor={{ fill: 'var(--muted)', opacity: 0.5 }}
                            {...TOOLTIP_STYLE}
                            formatter={(value) => [String(value), unit]}
                        />
                        <Bar
                            dataKey="value"
                            name={unit}
                            fill="var(--chart-1)"
                            radius={[0, 4, 4, 0]}
                            maxBarSize={18}
                            isAnimationActive={false}
                        >
                            <LabelList
                                dataKey="value"
                                position="right"
                                fill="var(--foreground)"
                                fontSize={12}
                            />
                        </Bar>
                    </BarChart>
                </ResponsiveContainer>
            </div>
            <ChartData
                rows={rows}
                labelHeading={labelHeading}
                valueHeading={unit}
                total={showShare ? total : undefined}
            />
        </div>
    );
}

/** Counts over time — one series on chart-1, as bars (daily) or a 2px line. */
export function TrendChart({
    rows,
    kind = 'bars',
    unit = 'Alerts',
    labelHeading = 'Day',
    emptyTitle,
    emptyDescription,
}: {
    rows: ChartRow[];
    kind?: 'bars' | 'line';
    unit?: string;
    labelHeading?: string;
    emptyTitle: string;
    emptyDescription?: string;
}) {
    const total = rows.reduce((sum, row) => sum + row.value, 0);
    if (rows.length === 0 || total === 0) {
        return (
            <EmptyState
                variant="compact"
                icon={BarChart3}
                title={emptyTitle}
                description={emptyDescription}
            />
        );
    }
    const peak = rows.reduce((top, row) => (row.value > top.value ? row : top), rows[0]);
    const axes = (
        <>
            <CartesianGrid vertical={false} stroke="var(--border)" />
            <XAxis
                dataKey="label"
                tick={TICK}
                tickLine={false}
                axisLine={false}
                interval="preserveStartEnd"
                minTickGap={16}
            />
            <YAxis
                allowDecimals={false}
                tick={TICK}
                tickLine={false}
                axisLine={false}
                width={32}
            />
            <Tooltip
                {...TOOLTIP_STYLE}
                cursor={
                    kind === 'line'
                        ? { stroke: 'var(--muted-foreground)', strokeWidth: 1 }
                        : { fill: 'var(--muted)', opacity: 0.5 }
                }
                formatter={(value) => [String(value), unit]}
            />
        </>
    );
    return (
        <div className="grid gap-3">
            <div
                className="h-64 w-full"
                role="img"
                aria-label={`${unit} over time: ${total} in total, the most (${peak.value}) on ${peak.label}. Exact values are under View chart data.`}
            >
                <ResponsiveContainer width="100%" height="100%">
                    {kind === 'line' ? (
                        <LineChart data={rows} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
                            {axes}
                            <Line
                                type="linear"
                                dataKey="value"
                                name={unit}
                                stroke="var(--chart-1)"
                                strokeWidth={2}
                                strokeLinejoin="round"
                                strokeLinecap="round"
                                dot={
                                    rows.length <= 31
                                        ? { r: 4, strokeWidth: 2, stroke: 'var(--card)', fill: 'var(--chart-1)' }
                                        : false
                                }
                                activeDot={{ r: 5, strokeWidth: 2, stroke: 'var(--card)' }}
                                isAnimationActive={false}
                            />
                        </LineChart>
                    ) : (
                        <BarChart data={rows} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
                            {axes}
                            <Bar
                                dataKey="value"
                                name={unit}
                                fill="var(--chart-1)"
                                radius={[4, 4, 0, 0]}
                                maxBarSize={28}
                                isAnimationActive={false}
                            />
                        </BarChart>
                    )}
                </ResponsiveContainer>
            </div>
            <ChartData rows={rows} labelHeading={labelHeading} valueHeading={unit} />
        </div>
    );
}

/**
 * From / To day pickers for the header filter row (the approved DatePicker,
 * never a browser date box). Keeps From ≤ To by moving the other end.
 */
export function HeaderDateRange({
    idPrefix,
    from,
    to,
    onChange,
}: {
    idPrefix: string;
    from: string;
    to: string;
    onChange: (from: string, to: string) => void;
}) {
    return (
        <>
            <DatePicker
                id={`${idPrefix}-from`}
                label="From"
                value={from}
                trigger={
                    <PageHeaderFilterButton
                        icon={CalendarDays}
                        aria-label={`From: ${formatDateOnly(from, 'Choose date')}`}
                    >
                        From · {formatDateOnly(from, 'Choose date')}
                    </PageHeaderFilterButton>
                }
                onChange={(next) => onChange(next, to && to < next ? next : to)}
            />
            <DatePicker
                id={`${idPrefix}-to`}
                label="To"
                value={to}
                trigger={
                    <PageHeaderFilterButton
                        icon={CalendarDays}
                        aria-label={`To: ${formatDateOnly(to, 'Choose date')}`}
                    >
                        To · {formatDateOnly(to, 'Choose date')}
                    </PageHeaderFilterButton>
                }
                onChange={(next) => onChange(from && from > next ? next : from, next)}
            />
        </>
    );
}

/** "14:00" → "2 pm"; "Oct 3" → "3 Oct" — the server sends trend buckets as display labels. */
export function trendLabel(label: string): string {
    const hour = /^(\d{1,2}):00$/.exec(label);
    if (hour) {
        const h = Number(hour[1]);
        return `${h % 12 === 0 ? 12 : h % 12} ${h < 12 ? 'am' : 'pm'}`;
    }
    const monthDay = /^([A-Z][a-z]{2}) (\d{1,2})$/.exec(label);
    if (monthDay) return `${monthDay[2]} ${monthDay[1]}`;
    return label;
}

/** Severity keys in the order people read them. */
export const SEVERITY_ORDER = ['critical', 'high', 'medium', 'low'] as const;

export const SEVERITY_LABEL: Record<string, string> = {
    critical: 'Critical',
    high: 'High',
    medium: 'Medium',
    low: 'Low',
};
