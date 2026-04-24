import { Button } from '@/components/ui/button';
import { Tabs } from '@/components/ui/tabs';
import { useMemo, useState, type ReactNode } from 'react';

type Point = {
    date: string; // YYYY-MM-DD
    count: number;
    hours?: number;
    status?: { scheduled?: number; completed?: number; cancelled?: number };
};

type DonutSlice = { label: string; value: number };

function clamp(n: number, min: number, max: number) {
    return Math.min(max, Math.max(min, n));
}

function formatShortDate(iso: string) {
    const d = new Date(iso + 'T00:00:00');
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function safeMax(values: number[]) {
    const m = Math.max(...values);
    return Number.isFinite(m) && m > 0 ? m : 1;
}

function Tooltip({
    x,
    y,
    children,
}: {
    x: number;
    y: number;
    children: ReactNode;
}) {
    return (
        /* eslint-disable-next-line no-restricted-syntax -- Chart tooltip is an anchored overlay, not a reusable Card surface. */
        <div
            className="pointer-events-none absolute z-10 min-w-[160px] -translate-x-1/2 rounded-xl border bg-background/95 p-2 text-xs shadow"
            style={{ left: x, top: y }}
        >
            {children}
        </div>
    );
}

export function ShiftLineChart({
    title,
    subtitle,
    data,
}: {
    title: string;
    subtitle?: string;
    data: Point[];
}) {
    const [hover, setHover] = useState<{
        i: number;
        x: number;
        y: number;
    } | null>(null);

    const points = useMemo(() => {
        const width = 640;
        const height = 180;
        const padL = 34;
        const padR = 14;
        const padT = 16;
        const padB = 28;

        const hours = data.map((d) => Number(d.hours ?? 0));
        const counts = data.map((d) => Number(d.count ?? 0));
        const maxY = safeMax([...hours, ...counts]);

        const innerW = width - padL - padR;
        const innerH = height - padT - padB;
        const step = data.length > 1 ? innerW / (data.length - 1) : 0;

        const mapY = (v: number) => padT + innerH - (v / maxY) * innerH;
        const mapX = (i: number) => padL + i * step;

        const hoursPath = data
            .map(
                (d, i) =>
                    `${i === 0 ? 'M' : 'L'} ${mapX(i)} ${mapY(Number(d.hours ?? 0))}`,
            )
            .join(' ');
        const countsPath = data
            .map(
                (d, i) =>
                    `${i === 0 ? 'M' : 'L'} ${mapX(i)} ${mapY(Number(d.count ?? 0))}`,
            )
            .join(' ');

        return {
            width,
            height,
            padL,
            padR,
            padT,
            padB,
            maxY,
            mapX,
            mapY,
            hoursPath,
            countsPath,
        };
    }, [data]);

    const totalHours = useMemo(
        () =>
            Math.round(
                data.reduce((acc, d) => acc + Number(d.hours ?? 0), 0) * 10,
            ) / 10,
        [data],
    );
    const totalShifts = useMemo(
        () => data.reduce((acc, d) => acc + Number(d.count ?? 0), 0),
        [data],
    );
    const avgHoursPerShift = useMemo(() => {
        if (!totalShifts) return 0;
        return Math.round((totalHours / totalShifts) * 10) / 10;
    }, [totalHours, totalShifts]);

    return (
        <div className="rounded-xl border p-4">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <div className="text-sm font-semibold">{title}</div>
                    {subtitle ? (
                        <div className="mt-1 text-xs text-muted-foreground">
                            {subtitle}
                        </div>
                    ) : null}
                </div>
                <div className="flex flex-wrap gap-3 text-xs text-muted-foreground">
                    <div>
                        <span className="font-medium text-foreground">
                            {totalShifts}
                        </span>{' '}
                        shifts
                    </div>
                    <div>
                        <span className="font-medium text-foreground">
                            {totalHours}
                        </span>{' '}
                        hrs
                    </div>
                    <div>
                        <span className="font-medium text-foreground">
                            {avgHoursPerShift}
                        </span>{' '}
                        hrs/shift
                    </div>
                </div>
            </div>

            <div className="relative mt-4 w-full overflow-x-auto">
                <svg
                    width={points.width}
                    height={points.height}
                    className="text-foreground"
                    onMouseLeave={() => setHover(null)}
                >
                    {/* grid */}
                    {Array.from({ length: 4 }).map((_, i) => {
                        const y =
                            points.padT +
                            ((points.height - points.padT - points.padB) * i) /
                                3;
                        return (
                            <g key={i} opacity={0.12}>
                                <line
                                    x1={points.padL}
                                    y1={y}
                                    x2={points.width - points.padR}
                                    y2={y}
                                    stroke="currentColor"
                                />
                            </g>
                        );
                    })}

                    {/* lines */}
                    <path
                        d={points.hoursPath}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth={2}
                        opacity={0.85}
                    />
                    <path
                        d={points.countsPath}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth={2}
                        opacity={0.45}
                        strokeDasharray="4 4"
                    />

                    {/* points + hover targets */}
                    {data.map((d, i) => {
                        const x = points.mapX(i);
                        const yH = points.mapY(Number(d.hours ?? 0));
                        const yC = points.mapY(Number(d.count ?? 0));
                        return (
                            <g key={d.date}>
                                <circle
                                    cx={x}
                                    cy={yH}
                                    r={3}
                                    fill="currentColor"
                                    opacity={0.9}
                                />
                                <circle
                                    cx={x}
                                    cy={yC}
                                    r={3}
                                    fill="currentColor"
                                    opacity={0.55}
                                />
                                <rect
                                    x={x - 10}
                                    y={points.padT}
                                    width={20}
                                    height={
                                        points.height -
                                        points.padT -
                                        points.padB
                                    }
                                    fill="transparent"
                                    onMouseMove={(e) => {
                                        const rect = (
                                            e.currentTarget
                                                .ownerSVGElement as SVGSVGElement
                                        ).getBoundingClientRect();
                                        setHover({
                                            i,
                                            x: e.clientX - rect.left,
                                            y: points.padT,
                                        });
                                    }}
                                />
                            </g>
                        );
                    })}

                    {/* x labels */}
                    {data.map((d, i) => {
                        if (data.length > 12 && i % 2 === 1) return null;
                        const x = points.mapX(i);
                        return (
                            <text
                                key={d.date}
                                x={x}
                                y={points.height - 8}
                                fontSize={10}
                                textAnchor="middle"
                                opacity={0.7}
                            >
                                {formatShortDate(d.date)}
                            </text>
                        );
                    })}
                </svg>

                {hover ? (
                    <Tooltip x={hover.x} y={hover.y}>
                        <div className="font-medium">
                            {formatShortDate(data[hover.i].date)}
                        </div>
                        <div className="mt-1 flex items-center justify-between gap-3">
                            <span className="text-muted-foreground">Hours</span>
                            <span className="font-medium">
                                {Math.round(
                                    Number(data[hover.i].hours ?? 0) * 10,
                                ) / 10}
                            </span>
                        </div>
                        <div className="flex items-center justify-between gap-3">
                            <span className="text-muted-foreground">
                                Shifts
                            </span>
                            <span className="font-medium">
                                {Number(data[hover.i].count ?? 0)}
                            </span>
                        </div>
                        {data[hover.i].status ? (
                            <div className="mt-2 border-t pt-2">
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-muted-foreground">
                                        Scheduled
                                    </span>
                                    <span className="font-medium">
                                        {Number(
                                            data[hover.i].status?.scheduled ??
                                                0,
                                        )}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-muted-foreground">
                                        Completed
                                    </span>
                                    <span className="font-medium">
                                        {Number(
                                            data[hover.i].status?.completed ??
                                                0,
                                        )}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between gap-3">
                                    <span className="text-muted-foreground">
                                        Cancelled
                                    </span>
                                    <span className="font-medium">
                                        {Number(
                                            data[hover.i].status?.cancelled ??
                                                0,
                                        )}
                                    </span>
                                </div>
                            </div>
                        ) : null}
                    </Tooltip>
                ) : null}
            </div>

            <div className="mt-3 flex flex-wrap gap-3 text-xs text-muted-foreground">
                <div className="flex items-center gap-2">
                    <span className="inline-block h-2 w-4 rounded-full bg-foreground/70" />{' '}
                    Hours
                </div>
                <div className="flex items-center gap-2">
                    <span className="inline-block h-0.5 w-4 rounded-full bg-foreground/50" />{' '}
                    Shifts (dashed)
                </div>
            </div>
        </div>
    );
}

export function IncidentLineChart({
    title,
    subtitle,
    data,
}: {
    title: string;
    subtitle?: string;
    data: Array<{ date: string; count: number }>;
}) {
    const [hover, setHover] = useState<{
        i: number;
        x: number;
        y: number;
    } | null>(null);

    const points = useMemo(() => {
        const width = 640;
        const height = 180;
        const padL = 34;
        const padR = 14;
        const padT = 16;
        const padB = 28;

        const counts = data.map((d) => Number(d.count ?? 0));
        const maxY = safeMax(counts);

        const innerW = width - padL - padR;
        const innerH = height - padT - padB;
        const step = data.length > 1 ? innerW / (data.length - 1) : 0;

        const mapY = (v: number) => padT + innerH - (v / maxY) * innerH;
        const mapX = (i: number) => padL + i * step;

        const line = data
            .map(
                (d, i) =>
                    `${i === 0 ? 'M' : 'L'} ${mapX(i)} ${mapY(Number(d.count ?? 0))}`,
            )
            .join(' ');

        return {
            width,
            height,
            padL,
            padR,
            padT,
            padB,
            innerW,
            innerH,
            step,
            mapX,
            mapY,
            maxY,
            line,
        };
    }, [data]);

    const total = useMemo(
        () => data.reduce((s, d) => s + Number(d.count ?? 0), 0),
        [data],
    );

    return (
        <div className="relative rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="text-sm font-semibold">{title}</div>
                    {subtitle ? (
                        <div className="mt-1 text-xs text-muted-foreground">
                            {subtitle}
                        </div>
                    ) : null}
                </div>
                <div className="text-right">
                    <div className="text-xs text-muted-foreground">
                        Total (30d)
                    </div>
                    <div className="text-sm font-semibold">{total}</div>
                </div>
            </div>

            <div className="mt-3">
                <svg
                    viewBox={`0 0 ${points.width} ${points.height}`}
                    className="h-[220px] w-full select-none"
                >
                    {/* grid */}
                    {[0, 0.25, 0.5, 0.75, 1].map((t) => {
                        const y =
                            points.padT + points.innerH - t * points.innerH;
                        return (
                            <line
                                key={t}
                                x1={points.padL}
                                x2={points.width - points.padR}
                                y1={y}
                                y2={y}
                                className="stroke-muted"
                            />
                        );
                    })}

                    {/* y labels */}
                    {[0, 0.5, 1].map((t) => {
                        const v = Math.round(t * points.maxY);
                        const y =
                            points.padT + points.innerH - t * points.innerH;
                        return (
                            <text
                                key={t}
                                x={points.padL - 8}
                                y={y + 4}
                                textAnchor="end"
                                className="fill-muted-foreground text-[10px]"
                            >
                                {v}
                            </text>
                        );
                    })}

                    {/* line */}
                    <path
                        d={points.line}
                        className="fill-none stroke-foreground"
                        strokeWidth={2}
                    />

                    {/* points/hover */}
                    {data.map((d, i) => {
                        const x = points.mapX(i);
                        const y = points.mapY(Number(d.count ?? 0));
                        return (
                            <circle
                                key={i}
                                cx={x}
                                cy={y}
                                r={10}
                                className="fill-transparent"
                                onMouseEnter={(e) => {
                                    const rect = (
                                        e.currentTarget
                                            .ownerSVGElement as SVGSVGElement
                                    ).getBoundingClientRect();
                                    setHover({
                                        i,
                                        x:
                                            rect.left +
                                            (x / points.width) * rect.width,
                                        y:
                                            rect.top +
                                            (y / points.height) * rect.height,
                                    });
                                }}
                                onMouseLeave={() => setHover(null)}
                            />
                        );
                    })}

                    {/* x labels (sparse) */}
                    {data.map((d, i) => {
                        const show =
                            data.length <= 10
                                ? true
                                : i % Math.ceil(data.length / 8) === 0 ||
                                  i === data.length - 1;
                        if (!show) return null;
                        const x = points.mapX(i);
                        return (
                            <text
                                key={i}
                                x={x}
                                y={points.height - 10}
                                textAnchor="middle"
                                className="fill-muted-foreground text-[10px]"
                            >
                                {formatShortDate(d.date)}
                            </text>
                        );
                    })}
                </svg>
            </div>

            {hover ? (
                <Tooltip x={hover.x} y={hover.y - 10}>
                    <div className="font-medium">
                        {formatShortDate(data[hover.i].date)}
                    </div>
                    <div className="mt-1 flex items-center justify-between gap-4">
                        <span className="text-muted-foreground">Incidents</span>
                        <span className="font-semibold">
                            {Number(data[hover.i].count ?? 0)}
                        </span>
                    </div>
                </Tooltip>
            ) : null}
        </div>
    );
}
function DonutChart({
    title,
    subtitle,
    slices,
    emptyText,
}: {
    title: string;
    subtitle?: string;
    slices: DonutSlice[];
    emptyText?: string;
}) {
    const total = slices.reduce((acc, s) => acc + (Number(s.value) || 0), 0);
    const size = 180;
    const r = 70;
    const stroke = 18;
    const cx = size / 2;
    const cy = size / 2;
    const circumference = 2 * Math.PI * r;

    const normalized = slices
        .map((s) => ({ ...s, value: Number(s.value) || 0 }))
        .filter((s) => s.value > 0);

    let offset = 0;

    return (
        <div className="rounded-xl border p-4">
            <div>
                <div className="text-sm font-semibold">{title}</div>
                {subtitle ? (
                    <div className="mt-1 text-xs text-muted-foreground">
                        {subtitle}
                    </div>
                ) : null}
            </div>

            {total ? (
                <div className="mt-4 grid gap-4 md:grid-cols-2 md:items-center">
                    <div className="flex items-center justify-center">
                        <svg
                            width={size}
                            height={size}
                            className="text-foreground"
                        >
                            <circle
                                cx={cx}
                                cy={cy}
                                r={r}
                                fill="none"
                                stroke="currentColor"
                                opacity={0.1}
                                strokeWidth={stroke}
                            />

                            {normalized.map((s, i) => {
                                const frac = s.value / total;
                                const dash = frac * circumference;
                                const dashArray = `${dash} ${circumference - dash}`;
                                const node = (
                                    <circle
                                        key={s.label + i}
                                        cx={cx}
                                        cy={cy}
                                        r={r}
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth={stroke}
                                        strokeDasharray={dashArray}
                                        strokeDashoffset={-offset}
                                        opacity={clamp(
                                            0.85 - i * 0.12,
                                            0.25,
                                            0.9,
                                        )}
                                        strokeLinecap="butt"
                                    />
                                );
                                offset += dash;
                                return node;
                            })}

                            <text
                                x={cx}
                                y={cy - 2}
                                textAnchor="middle"
                                fontSize={20}
                                fontWeight={700}
                            >
                                {total}
                            </text>
                            <text
                                x={cx}
                                y={cy + 16}
                                textAnchor="middle"
                                fontSize={10}
                                opacity={0.7}
                            >
                                total
                            </text>
                        </svg>
                    </div>

                    <div className="space-y-2">
                        {normalized.map((s, i) => {
                            const pct = Math.round((s.value / total) * 100);
                            return (
                                <div
                                    key={s.label}
                                    className="flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm"
                                >
                                    <div className="min-w-0">
                                        <div className="truncate font-medium">
                                            {s.label}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {pct}%
                                        </div>
                                    </div>
                                    <div className="text-right font-semibold">
                                        {s.value}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            ) : (
                <div className="mt-4 text-sm text-muted-foreground">
                    {emptyText ?? 'No data available.'}
                </div>
            )}
        </div>
    );
}

export function DashboardAnalytics({
    shiftSeries7,
    shiftSeries30,
    timesheetByStatus,
    timesheetSeries30,
    incidentSeries30,
    incidentBySeverity30,
}: {
    shiftSeries7: Point[];
    shiftSeries30: Point[];
    timesheetByStatus: Array<{ status: string; count: number }>;
    timesheetSeries30: Point[];
    incidentSeries30: Array<{ date: string; count: number }>;
    incidentBySeverity30: Array<{ severity: string; count: number }>;
}) {
    const [range, setRange] = useState<'7d' | '30d'>('7d');

    const shiftData = range === '7d' ? shiftSeries7 : shiftSeries30;

    const timesheetSlices = useMemo(
        () =>
            (timesheetByStatus ?? [])
                .map((d) => ({ label: d.status, value: Number(d.count ?? 0) }))
                .sort((a, b) => b.value - a.value),
        [timesheetByStatus],
    );

    const severitySlices = useMemo(
        () =>
            (incidentBySeverity30 ?? [])
                .map((d) => ({
                    label: d.severity,
                    value: Number(d.count ?? 0),
                }))
                .sort((a, b) => b.value - a.value),
        [incidentBySeverity30],
    );

    const timesheetTrend = useMemo(
        () =>
            (timesheetSeries30 ?? []).map((d) => ({
                date: d.date,
                count: Number(d.count ?? 0),
                hours: Number(d.hours ?? 0),
            })),
        [timesheetSeries30],
    );

    const incidentTrend = useMemo(
        () =>
            (incidentSeries30 ?? []).map((d) => ({
                date: d.date,
                count: Number(d.count ?? 0),
            })),
        [incidentSeries30],
    );

    return (
        <div className="space-y-4">
            <div>
                <div className="text-lg font-semibold">Analytics</div>
                <div className="mt-1 text-xs text-muted-foreground">
                    Breakdowns for shifts, timesheets and incidents. Hover
                    charts for daily detail.
                </div>
            </div>

            <Tabs
                tabs={[
                    {
                        key: 'shifts',
                        label: 'Shifts',
                        content: (
                            <div className="space-y-4">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div className="text-sm font-semibold">
                                        Shift coverage
                                    </div>

                                    <div className="flex items-center gap-2 rounded-xl border p-1">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setRange('7d')}
                                            className={`h-auto rounded-lg px-3 py-1.5 text-xs ${
                                                range === '7d'
                                                    ? 'bg-muted font-medium'
                                                    : 'text-muted-foreground hover:bg-muted/50'
                                            }`}
                                        >
                                            Next 7 days
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setRange('30d')}
                                            className={`h-auto rounded-lg px-3 py-1.5 text-xs ${
                                                range === '30d'
                                                    ? 'bg-muted font-medium'
                                                    : 'text-muted-foreground hover:bg-muted/50'
                                            }`}
                                        >
                                            Last 30 days
                                        </Button>
                                    </div>
                                </div>

                                <div className="lg:max-w-4xl">
                                    <ShiftLineChart
                                        title={
                                            range === '7d'
                                                ? 'Shifts & hours (next 7 days)'
                                                : 'Shifts & hours (last 30 days)'
                                        }
                                        subtitle="Solid line = hours, dashed line = shift count"
                                        data={shiftData}
                                    />
                                </div>
                            </div>
                        ),
                    },
                    {
                        key: 'timesheets',
                        label: 'Timesheets',
                        content: (
                            <div className="grid gap-4 lg:grid-cols-3">
                                <div className="lg:col-span-1">
                                    <DonutChart
                                        title="Timesheets by status"
                                        subtitle="All time"
                                        slices={timesheetSlices}
                                        emptyText="No timesheets yet."
                                    />
                                </div>

                                <div className="lg:col-span-2">
                                    <ShiftLineChart
                                        title="Timesheet volume (last 30 days)"
                                        subtitle="Solid line = hours logged, dashed line = timesheet count"
                                        data={timesheetTrend}
                                    />
                                </div>
                            </div>
                        ),
                    },
                    {
                        key: 'incidents',
                        label: 'Incidents',
                        content: (
                            <div className="grid gap-4 lg:grid-cols-3">
                                <div className="lg:col-span-1">
                                    <DonutChart
                                        title="Incidents by severity (last 30 days)"
                                        subtitle="Grouping based on incident severity"
                                        slices={severitySlices}
                                        emptyText="No incidents in the last 30 days."
                                    />
                                </div>
                                <div className="lg:col-span-2">
                                    <IncidentLineChart
                                        title="Incidents trend (last 30 days)"
                                        subtitle="Daily incident count"
                                        data={incidentTrend}
                                    />
                                </div>
                            </div>
                        ),
                    },
                ]}
            />
        </div>
    );
}
