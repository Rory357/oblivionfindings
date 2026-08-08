/**
 * Health & Safety Analytics — recharts chart primitives.
 *
 * Semantic tokens only (var(--chart-*), var(--status-*), var(--primary)) so
 * the page re-tints when the brand colour changes. Every chart is wrapped in
 * ChartCard which adds an aria-label and a visually-hidden data-table fallback
 * for screen readers (WCAG AA — meaning is never colour-only).
 *
 * NZ-only: LTIFR / TRIFR, WorkSafe notifiable, Nga Paerewa, ACC.
 */
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import type { ReactNode } from 'react';
import { useState } from 'react';
import {
    Area,
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    ComposedChart,
    LabelList,
    Legend,
    Line,
    LineChart,
    Pie,
    PieChart,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import type { RootCauseRow, TrendPoint } from './analytics-types';

export const TOKEN = {
    primary: 'var(--primary)',
    c1: 'var(--chart-1)',
    c2: 'var(--chart-2)',
    c3: 'var(--chart-3)',
    c4: 'var(--chart-4)',
    c5: 'var(--chart-5)',
    success: 'var(--status-success)',
    warning: 'var(--status-warning)',
    critical: 'var(--status-critical)',
    info: 'var(--status-info)',
    grid: 'var(--border)',
    axis: 'var(--muted-foreground)',
};

export function severityFill(s: string): string {
    switch (s) {
        case 'critical':
            return TOKEN.critical;
        case 'high':
            return TOKEN.c3;
        case 'medium':
            return TOKEN.warning;
        case 'low':
            return TOKEN.info;
        default:
            return TOKEN.c5;
    }
}

export function riskFill(r: string): string {
    switch (r) {
        case 'extreme':
            return TOKEN.critical;
        case 'high':
            return TOKEN.c3;
        case 'medium':
            return TOKEN.warning;
        case 'low':
            return TOKEN.success;
        default:
            return TOKEN.c5;
    }
}

// ── shared chrome ───────────────────────────────────────────────────────

type SrTable = {
    caption: string;
    columns: string[];
    rows: (string | number)[][];
};

export function ChartCard({
    title,
    subtitle,
    aria,
    action,
    children,
    table,
    className,
}: {
    title: ReactNode;
    subtitle?: ReactNode;
    aria: string;
    action?: ReactNode;
    children: ReactNode;
    table?: SrTable;
    className?: string;
}) {
    return (
        <Card className={cn('rounded-xl shadow-sm', className)}>
            <CardHeader className="flex flex-row items-start justify-between gap-2 space-y-0 pb-1">
                <div className="min-w-0">
                    <CardTitle className="text-sm font-bold tracking-tight">
                        {title}
                    </CardTitle>
                    {subtitle ? (
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {subtitle}
                        </p>
                    ) : null}
                </div>
                {action}
            </CardHeader>
            <CardContent className="pt-2">
                <figure className="m-0" role="group" aria-label={aria}>
                    {children}
                    {table ? (
                        <table className="sr-only">
                            <caption>{table.caption}</caption>
                            <thead>
                                <tr>
                                    {table.columns.map((c) => (
                                        <th key={c}>{c}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {table.rows.map((r, i) => (
                                    <tr key={i}>
                                        {r.map((c, j) => (
                                            <td key={j}>{c}</td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    ) : null}
                </figure>
            </CardContent>
        </Card>
    );
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function ChartTooltip({ active, payload, label }: any) {
    if (!active || !payload?.length) {
        return null;
    }
    return (
        // eslint-disable-next-line no-restricted-syntax -- chart tooltip popover, not a content card
        <div className="rounded-lg border border-border bg-card px-3 py-2 text-xs shadow-lg">
            {label ? (
                <p className="mb-1 font-semibold text-foreground">{label}</p>
            ) : null}
            {/* eslint-disable-next-line @typescript-eslint/no-explicit-any */}
            {payload.map((p: any, i: number) => (
                <p
                    key={i}
                    className="flex items-center gap-1.5 text-muted-foreground"
                >
                    <span
                        className="inline-block h-2 w-2 rounded-full"
                        style={{
                            backgroundColor: p.color || p.stroke || p.fill,
                        }}
                    />
                    <span className="capitalize">{p.name}</span>
                    <span className="ml-auto pl-3 font-semibold text-foreground tabular-nums">
                        {p.value ?? '—'}
                    </span>
                </p>
            ))}
        </div>
    );
}

function NeedsData({ label }: { label: string }) {
    return (
        <div className="flex h-[200px] flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-border bg-muted/40 px-4 text-center">
            <p className="text-xs font-medium text-muted-foreground">{label}</p>
        </div>
    );
}

function EmptyState({
    height = 200,
    label = 'No data for this period.',
}: {
    height?: number;
    label?: string;
}) {
    return (
        <div
            className="flex flex-col items-center justify-center rounded-lg border border-dashed border-border bg-muted/40 text-center"
            style={{ height }}
        >
            <p className="text-xs text-muted-foreground">{label}</p>
        </div>
    );
}

const legendStyle = { fontSize: 11, paddingTop: 4 };

function lastBigDot(color: string, count: number) {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    return (props: any) => {
        const { cx, cy, index } = props;
        if (cx == null || cy == null) {
            return <g key={index} />;
        }
        const last = index === count - 1;
        return (
            <circle
                key={index}
                cx={cx}
                cy={cy}
                r={last ? 5 : 3}
                fill={color}
                stroke="var(--card)"
                strokeWidth={last ? 2 : 1}
            />
        );
    };
}

// ── Trend charts ────────────────────────────────────────────────────────

export function LtifrTrifrChart({
    trends,
    height = 240,
}: {
    trends: TrendPoint[];
    height?: number;
}) {
    if (!trends.some((t) => t.ltifr !== null || t.trifr !== null)) {
        return (
            <NeedsData label="LTIFR / TRIFR need recorded hours-worked data for this period." />
        );
    }
    return (
        <ResponsiveContainer width="100%" height={height}>
            <ComposedChart
                data={trends}
                margin={{ top: 8, right: 12, left: -14, bottom: 0 }}
            >
                <defs>
                    <linearGradient id="ltifr-grad" x1="0" y1="0" x2="0" y2="1">
                        <stop
                            offset="0%"
                            stopColor={TOKEN.warning}
                            stopOpacity={0.26}
                        />
                        <stop
                            offset="100%"
                            stopColor={TOKEN.warning}
                            stopOpacity={0.01}
                        />
                    </linearGradient>
                </defs>
                <CartesianGrid
                    strokeDasharray="3 3"
                    stroke={TOKEN.grid}
                    vertical={false}
                />
                <XAxis
                    dataKey="label"
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                />
                <YAxis
                    domain={[0, 'auto']}
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                    width={34}
                />
                <Tooltip content={<ChartTooltip />} />
                <Legend wrapperStyle={legendStyle} iconType="plainline" />
                <Area
                    type="monotone"
                    dataKey="ltifr"
                    name="LTIFR"
                    stroke={TOKEN.warning}
                    strokeWidth={2.5}
                    fill="url(#ltifr-grad)"
                    connectNulls
                    dot={lastBigDot(TOKEN.warning, trends.length)}
                    activeDot={{ r: 5 }}
                    isAnimationActive={false}
                />
                <Line
                    type="monotone"
                    dataKey="trifr"
                    name="TRIFR"
                    stroke={TOKEN.critical}
                    strokeWidth={2}
                    connectNulls
                    dot={lastBigDot(TOKEN.critical, trends.length)}
                    activeDot={{ r: 5 }}
                    isAnimationActive={false}
                />
            </ComposedChart>
        </ResponsiveContainer>
    );
}

export function SingleAreaChart({
    trends,
    dataKey,
    name,
    color,
    height = 200,
    domain,
}: {
    trends: TrendPoint[];
    dataKey: keyof TrendPoint;
    name: string;
    color: string;
    height?: number;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    domain?: any;
}) {
    const gradId = `area-${String(dataKey)}`;
    return (
        <ResponsiveContainer width="100%" height={height}>
            <ComposedChart
                data={trends}
                margin={{ top: 8, right: 12, left: -14, bottom: 0 }}
            >
                <defs>
                    <linearGradient id={gradId} x1="0" y1="0" x2="0" y2="1">
                        <stop
                            offset="0%"
                            stopColor={color}
                            stopOpacity={0.24}
                        />
                        <stop
                            offset="100%"
                            stopColor={color}
                            stopOpacity={0.01}
                        />
                    </linearGradient>
                </defs>
                <CartesianGrid
                    strokeDasharray="3 3"
                    stroke={TOKEN.grid}
                    vertical={false}
                />
                <XAxis
                    dataKey="label"
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                />
                <YAxis
                    domain={domain ?? [0, 'auto']}
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                    width={34}
                />
                <Tooltip content={<ChartTooltip />} />
                <Area
                    type="monotone"
                    dataKey={dataKey as string}
                    name={name}
                    stroke={color}
                    strokeWidth={2.5}
                    fill={`url(#${gradId})`}
                    connectNulls
                    dot={lastBigDot(color, trends.length)}
                    activeDot={{ r: 5 }}
                    isAnimationActive={false}
                />
            </ComposedChart>
        </ResponsiveContainer>
    );
}

export function HazardBurndownChart({
    trends,
    height = 240,
}: {
    trends: TrendPoint[];
    height?: number;
}) {
    return (
        <ResponsiveContainer width="100%" height={height}>
            <ComposedChart
                data={trends}
                margin={{ top: 8, right: 12, left: -14, bottom: 0 }}
                barGap={2}
            >
                <CartesianGrid
                    strokeDasharray="3 3"
                    stroke={TOKEN.grid}
                    vertical={false}
                />
                <XAxis
                    dataKey="label"
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                />
                <YAxis
                    allowDecimals={false}
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                    width={30}
                />
                <Tooltip
                    content={<ChartTooltip />}
                    cursor={{ fill: 'var(--accent)', fillOpacity: 0.5 }}
                />
                <Legend wrapperStyle={legendStyle} />
                <Bar
                    dataKey="hazards_opened"
                    name="Opened"
                    fill={TOKEN.warning}
                    radius={[3, 3, 0, 0]}
                    maxBarSize={18}
                    isAnimationActive={false}
                />
                <Bar
                    dataKey="hazards_closed"
                    name="Closed"
                    fill={TOKEN.success}
                    radius={[3, 3, 0, 0]}
                    maxBarSize={18}
                    isAnimationActive={false}
                />
                <Line
                    type="monotone"
                    dataKey="hazards_open"
                    name="Running open"
                    stroke={TOKEN.primary}
                    strokeWidth={2.5}
                    dot={{ r: 3 }}
                    activeDot={{ r: 5 }}
                    isAnimationActive={false}
                />
            </ComposedChart>
        </ResponsiveContainer>
    );
}

export function CaClosureChart({
    trends,
    height = 220,
}: {
    trends: TrendPoint[];
    height?: number;
}) {
    return (
        <ResponsiveContainer width="100%" height={height}>
            <ComposedChart
                data={trends}
                margin={{ top: 8, right: 6, left: -14, bottom: 0 }}
            >
                <CartesianGrid
                    strokeDasharray="3 3"
                    stroke={TOKEN.grid}
                    vertical={false}
                />
                <XAxis
                    dataKey="label"
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                />
                <YAxis
                    yAxisId="days"
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                    width={30}
                />
                <YAxis
                    yAxisId="pct"
                    orientation="right"
                    domain={[0, 100]}
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                    width={34}
                />
                <Tooltip content={<ChartTooltip />} />
                <Legend wrapperStyle={legendStyle} iconType="plainline" />
                <Line
                    yAxisId="days"
                    type="monotone"
                    dataKey="ca_avg_days"
                    name="Avg days to close"
                    stroke={TOKEN.primary}
                    strokeWidth={2.5}
                    connectNulls
                    dot={{ r: 3 }}
                    activeDot={{ r: 5 }}
                    isAnimationActive={false}
                />
                <Line
                    yAxisId="pct"
                    type="monotone"
                    dataKey="ca_pct_on_time"
                    name="% on time"
                    stroke={TOKEN.success}
                    strokeWidth={2}
                    strokeDasharray="5 4"
                    connectNulls
                    dot={{ r: 3 }}
                    activeDot={{ r: 5 }}
                    isAnimationActive={false}
                />
            </ComposedChart>
        </ResponsiveContainer>
    );
}

export function WorksafeNotifiableChart({
    trends,
    height = 220,
}: {
    trends: TrendPoint[];
    height?: number;
}) {
    return (
        <ResponsiveContainer width="100%" height={height}>
            <BarChart
                data={trends}
                margin={{ top: 8, right: 12, left: -14, bottom: 0 }}
                barGap={2}
            >
                <CartesianGrid
                    strokeDasharray="3 3"
                    stroke={TOKEN.grid}
                    vertical={false}
                />
                <XAxis
                    dataKey="label"
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                />
                <YAxis
                    allowDecimals={false}
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                    width={30}
                />
                <Tooltip
                    content={<ChartTooltip />}
                    cursor={{ fill: 'var(--accent)', fillOpacity: 0.5 }}
                />
                <Legend wrapperStyle={legendStyle} />
                <Bar
                    dataKey="worksafe_notified"
                    name="Notified"
                    fill={TOKEN.primary}
                    radius={[3, 3, 0, 0]}
                    maxBarSize={18}
                    isAnimationActive={false}
                />
                <Bar
                    dataKey="worksafe_awaiting"
                    name="Awaiting"
                    fill={TOKEN.critical}
                    radius={[3, 3, 0, 0]}
                    maxBarSize={18}
                    isAnimationActive={false}
                />
            </BarChart>
        </ResponsiveContainer>
    );
}

export function WorkerParticipationChart({
    trends,
    height = 220,
}: {
    trends: TrendPoint[];
    height?: number;
}) {
    if (
        !trends.some(
            (t) =>
                t.worker_engagement !== null || t.worker_consultation !== null,
        )
    ) {
        return (
            <NeedsData label="No worker-participation records (HSR/committee meetings, consultations) for this period." />
        );
    }
    return (
        <ResponsiveContainer width="100%" height={height}>
            <LineChart
                data={trends}
                margin={{ top: 8, right: 12, left: -14, bottom: 0 }}
            >
                <CartesianGrid
                    strokeDasharray="3 3"
                    stroke={TOKEN.grid}
                    vertical={false}
                />
                <XAxis
                    dataKey="label"
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                />
                <YAxis
                    domain={[
                        (min: number) =>
                            Math.min(50, Math.floor((min || 50) / 10) * 10),
                        100,
                    ]}
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                    width={34}
                />
                <Tooltip content={<ChartTooltip />} />
                <Legend wrapperStyle={legendStyle} iconType="plainline" />
                <Line
                    type="monotone"
                    dataKey="worker_engagement"
                    name="Engagement"
                    stroke={TOKEN.primary}
                    strokeWidth={2.5}
                    connectNulls
                    dot={{ r: 3 }}
                    activeDot={{ r: 5 }}
                    isAnimationActive={false}
                />
                <Line
                    type="monotone"
                    dataKey="worker_consultation"
                    name="Consultation"
                    stroke={TOKEN.c2}
                    strokeWidth={2}
                    strokeDasharray="5 4"
                    connectNulls
                    dot={{ r: 3 }}
                    activeDot={{ r: 5 }}
                    isAnimationActive={false}
                />
            </LineChart>
        </ResponsiveContainer>
    );
}

// ── Root-cause Pareto (the showpiece) ───────────────────────────────────

export function RootCausePareto({
    data,
    height = 280,
    onBar,
    onBarCtx,
}: {
    data: RootCauseRow[];
    height?: number;
    onBar?: (row: RootCauseRow) => void;
    onBarCtx?: (e: React.MouseEvent, row: RootCauseRow) => void;
}) {
    if (!data.length) {
        return (
            <EmptyState
                height={height}
                label="No root-cause categories recorded for this period."
            />
        );
    }
    const barColor = (i: number) =>
        i === 0 ? TOKEN.critical : i <= 2 ? TOKEN.warning : TOKEN.primary;
    return (
        <ResponsiveContainer width="100%" height={height}>
            <ComposedChart
                data={data}
                margin={{ top: 16, right: 8, left: -14, bottom: 0 }}
            >
                <CartesianGrid
                    strokeDasharray="3 3"
                    stroke={TOKEN.grid}
                    vertical={false}
                />
                <XAxis
                    dataKey="cause"
                    tick={{ fontSize: 10, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                    interval={0}
                    angle={-12}
                    textAnchor="end"
                    height={48}
                />
                <YAxis
                    yAxisId="count"
                    allowDecimals={false}
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                    width={28}
                />
                <YAxis
                    yAxisId="pct"
                    orientation="right"
                    domain={[0, 100]}
                    tickFormatter={(v) => `${v}%`}
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                    width={40}
                />
                <Tooltip
                    content={<ChartTooltip />}
                    cursor={{ fill: 'var(--accent)', fillOpacity: 0.5 }}
                />
                <ReferenceLine
                    yAxisId="pct"
                    y={80}
                    stroke={TOKEN.critical}
                    strokeDasharray="4 4"
                    strokeWidth={1.5}
                    ifOverflow="extendDomain"
                    label={{
                        value: '80%',
                        position: 'right',
                        fontSize: 10,
                        fill: TOKEN.critical,
                    }}
                />
                <Bar
                    yAxisId="count"
                    dataKey="count"
                    name="Events"
                    radius={[4, 4, 0, 0]}
                    maxBarSize={56}
                    cursor={onBar ? 'pointer' : undefined}
                    // eslint-disable-next-line @typescript-eslint/no-explicit-any
                    onClick={(d: any) => onBar?.(d?.payload ?? d)}
                    isAnimationActive={false}
                >
                    {data.map((row, i) => (
                        <Cell key={row.cause} fill={barColor(i)} />
                    ))}
                    <LabelList
                        dataKey="count"
                        position="top"
                        style={{
                            fontSize: 11,
                            fill: TOKEN.axis,
                            fontWeight: 600,
                        }}
                    />
                </Bar>
                <Line
                    yAxisId="pct"
                    type="monotone"
                    dataKey="cumulative_pct"
                    name="Cumulative %"
                    stroke={TOKEN.primary}
                    strokeWidth={2.5}
                    dot={{ r: 3, fill: TOKEN.primary }}
                    activeDot={{ r: 5 }}
                    isAnimationActive={false}
                />
            </ComposedChart>
        </ResponsiveContainer>
    );
}

// ── Hover-focus donut + interactive breakdown rows ──────────────────────

export type DonutDatum = {
    key: string;
    label: string;
    value: number;
    color: string;
};

export function FocusDonut({
    segments,
    onSegment,
    onSegmentCtx,
}: {
    segments: DonutDatum[];
    onSegment?: (d: DonutDatum) => void;
    onSegmentCtx?: (e: React.MouseEvent, d: DonutDatum) => void;
}) {
    const [active, setActive] = useState<number | null>(null);
    const total = segments.reduce((s, d) => s + d.value, 0);
    const focus = active != null ? segments[active] : null;

    if (total === 0) {
        return <EmptyState label="No records for this period." />;
    }

    return (
        <div className="flex flex-col items-center gap-3 sm:flex-row">
            <div
                className="relative shrink-0"
                style={{ width: 168, height: 168 }}
                aria-hidden="true"
            >
                <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                        <Pie
                            data={segments}
                            dataKey="value"
                            nameKey="label"
                            cx="50%"
                            cy="50%"
                            innerRadius={active != null ? 50 : 52}
                            outerRadius={active != null ? 76 : 72}
                            paddingAngle={2}
                            stroke="none"
                            onMouseEnter={(_, i) => setActive(i)}
                            onMouseLeave={() => setActive(null)}
                            onClick={(_, i) => onSegment?.(segments[i])}
                            isAnimationActive={false}
                        >
                            {segments.map((s, i) => (
                                <Cell
                                    key={s.key}
                                    fill={s.color}
                                    fillOpacity={
                                        active == null || active === i
                                            ? 1
                                            : 0.45
                                    }
                                    cursor={onSegment ? 'pointer' : undefined}
                                />
                            ))}
                        </Pie>
                    </PieChart>
                </ResponsiveContainer>
                <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-xl font-bold text-foreground tabular-nums">
                        {focus ? focus.value : total}
                    </span>
                    <span className="max-w-[92px] truncate text-[10px] font-medium tracking-wide text-muted-foreground uppercase">
                        {focus ? focus.label : 'Total'}
                    </span>
                </div>
            </div>
            <ul className="w-full space-y-0.5">
                {segments.map((s, i) => (
                    <li key={s.key}>
                        {/* eslint-disable-next-line no-restricted-syntax -- interactive legend/drill row, custom dense layout */}
                        <button
                            type="button"
                            className="flex w-full items-center gap-2 rounded-md px-2 py-1 text-left text-xs transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                            onMouseEnter={() => setActive(i)}
                            onMouseLeave={() => setActive(null)}
                            onClick={() => onSegment?.(s)}
                            onContextMenu={(e) => onSegmentCtx?.(e, s)}
                        >
                            <span
                                className="h-2.5 w-2.5 shrink-0 rounded-full"
                                style={{ backgroundColor: s.color }}
                            />
                            <span className="truncate text-foreground capitalize">
                                {s.label}
                            </span>
                            <span className="ml-auto pl-2 font-semibold text-foreground tabular-nums">
                                {s.value}
                            </span>
                            <span className="w-9 text-right text-muted-foreground tabular-nums">
                                {Math.round((s.value / total) * 100)}%
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

// ── Horizontal breakdown bars + interactive rows ────────────────────────

export type BreakdownItem = {
    key: string;
    label: string;
    value: number;
    color: string;
};

export function HorizontalBars({
    data,
    height = 200,
}: {
    data: BreakdownItem[];
    height?: number;
}) {
    if (!data.length) {
        return <EmptyState height={height} />;
    }
    return (
        <ResponsiveContainer width="100%" height={height}>
            <BarChart
                data={data}
                layout="vertical"
                margin={{ top: 4, right: 30, left: 6, bottom: 4 }}
            >
                <CartesianGrid
                    horizontal={false}
                    stroke={TOKEN.grid}
                    strokeDasharray="3 3"
                />
                <XAxis
                    type="number"
                    allowDecimals={false}
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                />
                <YAxis
                    type="category"
                    dataKey="label"
                    width={116}
                    tick={{ fontSize: 11, fill: TOKEN.axis }}
                    axisLine={false}
                    tickLine={false}
                />
                <Tooltip
                    content={<ChartTooltip />}
                    cursor={{ fill: 'var(--accent)', fillOpacity: 0.5 }}
                />
                <Bar
                    dataKey="value"
                    name="Count"
                    radius={[0, 5, 5, 0]}
                    maxBarSize={22}
                    isAnimationActive={false}
                >
                    {data.map((d) => (
                        <Cell key={d.key} fill={d.color} />
                    ))}
                    <LabelList
                        dataKey="value"
                        position="right"
                        style={{
                            fontSize: 11,
                            fill: TOKEN.axis,
                            fontWeight: 600,
                        }}
                    />
                </Bar>
            </BarChart>
        </ResponsiveContainer>
    );
}

/** Clickable + right-clickable legend rows — the drill + a11y surface under a chart. */
export function BreakdownRows({
    items,
    onItem,
    onItemCtx,
}: {
    items: BreakdownItem[];
    onItem?: (d: BreakdownItem) => void;
    onItemCtx?: (e: React.MouseEvent, d: BreakdownItem) => void;
}) {
    const total = items.reduce((s, d) => s + d.value, 0) || 1;
    if (!items.length) {
        return null;
    }
    return (
        <ul className="border-line mt-2 space-y-0.5 border-t pt-2">
            {items.map((d) => (
                <li key={d.key}>
                    {/* eslint-disable-next-line no-restricted-syntax -- interactive drill row, custom dense layout */}
                    <button
                        type="button"
                        className="flex w-full items-center gap-2 rounded-md px-2 py-1 text-left text-xs transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                        onClick={() => onItem?.(d)}
                        onContextMenu={(e) => onItemCtx?.(e, d)}
                    >
                        <span
                            className="h-2.5 w-2.5 shrink-0 rounded-full"
                            style={{ backgroundColor: d.color }}
                        />
                        <span className="truncate text-foreground capitalize">
                            {d.label}
                        </span>
                        <span className="ml-auto pl-2 font-semibold text-foreground tabular-nums">
                            {d.value}
                        </span>
                        <span className="w-9 text-right text-muted-foreground tabular-nums">
                            {Math.round((d.value / total) * 100)}%
                        </span>
                    </button>
                </li>
            ))}
        </ul>
    );
}
