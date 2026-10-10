import { cn } from '@/lib/utils';

import { MicroStats, type MicroStat } from './micro-stats';

export type AnalyticsTrendPoint = { week: string; completion: number | null };
export type DailyCoveragePoint = {
    day: string;
    date: string;
    scheduled: number;
    filled: number;
    open: number;
};
export type ShiftTypeSlice = {
    key: string;
    label: string;
    value: number;
    color: string;
};
export type FillBySite = { site: string; rate: number };

export type AnalyticsPaneProps = {
    stats: MicroStat[];
    completionTrend: AnalyticsTrendPoint[];
    dailyCoverage?: DailyCoveragePoint[];
    shiftTypes: ShiftTypeSlice[];
    fillBySite: FillBySite[];
    overtimeTrend?: number[];
};

export function AnalyticsPane({
    stats,
    completionTrend,
    dailyCoverage = [],
    shiftTypes,
    fillBySite,
    overtimeTrend = [],
}: AnalyticsPaneProps) {
    const totalShifts = shiftTypes.reduce((s, x) => s + x.value, 0);
    const maxDailyScheduled = Math.max(
        1,
        ...dailyCoverage.map((d) => d.scheduled),
    );

    const W = 520;
    const H = 170;
    const PAD = 28;
    const points = completionTrend;
    const xs = points.map(
        (_, i) =>
            PAD +
            (points.length === 1
                ? (W - 2 * PAD) / 2
                : (i / (points.length - 1)) * (W - 2 * PAD)),
    );
    const ymin = 0;
    const ymax = 100;
    const yFor = (v: number) =>
        H - PAD - ((v - ymin) / (ymax - ymin)) * (H - 2 * PAD - 12);
    const linePath = points
        .map((p, i) =>
            p.completion === null
                ? ''
                : `${i === 0 || points[i - 1].completion === null ? 'M' : 'L'}${xs[i]},${yFor(p.completion)}`,
        )
        .join(' ');

    const maxOt = Math.max(1, ...overtimeTrend);
    const otDelta =
        overtimeTrend.length >= 2
            ? overtimeTrend[overtimeTrend.length - 1] - overtimeTrend[0]
            : 0;

    return (
        <div className="space-y-4">
            <MicroStats stats={stats} />

            <div className="grid gap-3 lg:grid-cols-[1.4fr_1fr]">
                <section className="min-w-0 rounded-[14px] border border-border bg-card p-4 shadow-sm">
                    <h3 className="text-section-title">
                        Shift completion · {points.length} weeks
                    </h3>
                    <p className="text-caption mt-1">
                        Completed records as a share of all recorded shifts,
                        including cancellations.
                    </p>
                    {points.some((point) => point.completion !== null) ? (
                        <svg
                            viewBox={`0 0 ${W} ${H}`}
                            width="100%"
                            role="img"
                            aria-label="Weekly shift completion percentages"
                        >
                            {[0, 25, 50, 75, 100].map((value) => (
                                <g key={value}>
                                    <line
                                        x1={PAD}
                                        x2={W - PAD}
                                        y1={yFor(value)}
                                        y2={yFor(value)}
                                        stroke="var(--border)"
                                    />
                                    <text
                                        x={PAD - 6}
                                        y={yFor(value) + 3}
                                        textAnchor="end"
                                        fontSize="10"
                                        fill="var(--muted-foreground)"
                                    >
                                        {value}%
                                    </text>
                                </g>
                            ))}
                            <path
                                d={linePath}
                                stroke="var(--primary)"
                                strokeWidth="2.5"
                                fill="none"
                            />
                            {points.map((point, index) => (
                                <g key={point.week}>
                                    {point.completion !== null && (
                                        <circle
                                            cx={xs[index]}
                                            cy={yFor(point.completion)}
                                            r="4"
                                            fill="var(--background)"
                                            stroke="var(--primary)"
                                            strokeWidth="2"
                                        >
                                            <title>
                                                {point.week}: {point.completion}
                                                % completed
                                            </title>
                                        </circle>
                                    )}
                                    <text
                                        x={xs[index]}
                                        y={H - 8}
                                        textAnchor="middle"
                                        fontSize="10"
                                        fill="var(--muted-foreground)"
                                    >
                                        {point.week}
                                    </text>
                                </g>
                            ))}
                        </svg>
                    ) : (
                        <p className="py-8 text-sm text-muted-foreground">
                            No recorded shifts in this period.
                        </p>
                    )}
                    <ul className="text-caption mt-3 flex flex-wrap gap-x-4 gap-y-2">
                        {points.map((point) => (
                            <li key={point.week}>
                                {point.week}:{' '}
                                {point.completion === null
                                    ? 'No shifts'
                                    : `${point.completion}% completed`}
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="rounded-[14px] border border-border bg-card p-4 shadow-sm">
                    <div className="mb-3">
                        <h3 className="text-sm font-bold tracking-tight">
                            Recorded shift starts
                        </h3>
                        <div className="text-[11px] text-muted-foreground">
                            Assigned and unassigned starts · all statuses
                        </div>
                    </div>
                    <div className="space-y-2">
                        {dailyCoverage.length === 0 ? (
                            <div className="text-xs text-muted-foreground">
                                No recorded shift starts this week.
                            </div>
                        ) : null}
                        {dailyCoverage.map((day) => {
                            const assignedWidth =
                                (day.filled / maxDailyScheduled) * 100;
                            const openWidth =
                                (day.open / maxDailyScheduled) * 100;

                            return (
                                <div
                                    key={day.date}
                                    className="grid grid-cols-[48px_1fr_96px] items-center gap-2 text-xs"
                                >
                                    <div>
                                        <div className="font-semibold">
                                            {day.day}
                                        </div>
                                        <div className="text-[10px] text-muted-foreground">
                                            {new Date(
                                                `${day.date}T00:00:00`,
                                            ).toLocaleDateString(undefined, {
                                                day: '2-digit',
                                                month: 'short',
                                            })}
                                        </div>
                                    </div>
                                    <div
                                        className="flex h-2.5 overflow-hidden rounded-full bg-muted"
                                        title={`${day.filled}/${day.scheduled} assigned · ${day.open} open`}
                                    >
                                        <span
                                            className="block h-full bg-status-success"
                                            style={{
                                                width: `${Math.max(0, assignedWidth)}%`,
                                            }}
                                        />
                                        <span
                                            className="block h-full bg-status-warning"
                                            style={{
                                                width: `${Math.max(0, openWidth)}%`,
                                            }}
                                        />
                                    </div>
                                    <div className="text-right">
                                        <div className="font-semibold tabular-nums">
                                            {day.filled}/{day.scheduled}{' '}
                                            assigned
                                        </div>
                                        <div
                                            className={cn(
                                                'text-[10px] tabular-nums',
                                                day.open > 0
                                                    ? 'text-status-warning'
                                                    : 'text-muted-foreground',
                                            )}
                                        >
                                            {day.open} open
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </section>

                <section className="rounded-[14px] border border-border bg-card p-4 shadow-sm">
                    <div className="mb-3">
                        <h3 className="text-sm font-bold tracking-tight">
                            Shift type mix
                        </h3>
                        <div className="text-[11px] text-muted-foreground">
                            {totalShifts} shifts · this week
                        </div>
                    </div>
                    <div className="space-y-3">
                        <div className="flex h-3.5 overflow-hidden rounded-full">
                            {shiftTypes.map((t) => (
                                <div
                                    key={t.key}
                                    style={{
                                        width: `${(t.value / Math.max(1, totalShifts)) * 100}%`,
                                        background: t.color,
                                    }}
                                    title={`${t.label} · ${t.value}`}
                                />
                            ))}
                        </div>
                        <ul className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                            {shiftTypes.map((t) => (
                                <li
                                    key={t.key}
                                    className="flex items-center gap-1.5"
                                >
                                    <span
                                        className="inline-block h-2.5 w-2.5 rounded-sm"
                                        style={{ background: t.color }}
                                    />
                                    <span className="flex-1 truncate text-muted-foreground">
                                        {t.label}
                                    </span>
                                    <span className="font-semibold tabular-nums">
                                        {t.value}
                                        <span className="ml-1 text-[10px] text-muted-foreground">
                                            ·{' '}
                                            {Math.round(
                                                (t.value /
                                                    Math.max(1, totalShifts)) *
                                                    100,
                                            )}
                                            %
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>

                <section className="rounded-[14px] border border-border bg-card p-4 shadow-sm">
                    <div className="mb-3">
                        <h3 className="text-sm font-bold tracking-tight">
                            Staff numbers by site
                        </h3>
                        <div className="text-[11px] text-muted-foreground">
                            Windows at or above configured staff numbers
                        </div>
                    </div>
                    <ul className="space-y-2">
                        {fillBySite.length === 0 ? (
                            <li className="text-xs text-muted-foreground">
                                No configured coverage windows were assessed.
                            </li>
                        ) : null}
                        {fillBySite
                            .slice()
                            .sort((a, b) => b.rate - a.rate)
                            .map((s) => {
                                return (
                                    <li
                                        key={s.site}
                                        className="grid grid-cols-[130px_1fr_44px] items-center gap-2"
                                    >
                                        <span className="truncate text-xs">
                                            {s.site}
                                        </span>
                                        <span className="relative h-2 overflow-hidden rounded-full bg-muted">
                                            <span
                                                className="block h-full bg-primary"
                                                style={{
                                                    width: `${Math.min(100, Math.max(0, s.rate))}%`,
                                                }}
                                            />
                                        </span>
                                        <span className="text-right text-xs font-semibold tabular-nums">
                                            {s.rate}%
                                        </span>
                                    </li>
                                );
                            })}
                    </ul>
                </section>

                <section className="rounded-[14px] border border-border bg-card p-4 shadow-sm">
                    <div className="mb-3 flex items-center justify-between">
                        <div>
                            <h3 className="text-sm font-bold tracking-tight">
                                Overtime hours · trend
                            </h3>
                            <div className="text-[11px] text-muted-foreground">
                                {overtimeTrend.length > 0
                                    ? 'Recorded hours by week'
                                    : 'Recorded overtime hours are not available'}
                            </div>
                        </div>
                        {overtimeTrend.length >= 2 ? (
                            <span
                                className={cn(
                                    'rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                    otDelta >= 0
                                        ? 'bg-status-warning-bg text-status-warning'
                                        : 'bg-status-success-bg text-status-success',
                                )}
                            >
                                {otDelta >= 0 ? '▲' : '▼'} {Math.abs(otDelta)}h
                            </span>
                        ) : null}
                    </div>
                    <div className="grid h-[110px] grid-cols-8 items-end gap-1">
                        {overtimeTrend.length === 0 ? (
                            <div className="col-span-8 text-center text-xs text-muted-foreground">
                                No data
                            </div>
                        ) : null}
                        {overtimeTrend.map((v, i) => (
                            <div key={i} className="flex flex-col items-center">
                                <span
                                    className={cn(
                                        'w-full rounded-t',
                                        i === overtimeTrend.length - 1
                                            ? 'bg-status-warning'
                                            : 'bg-muted-foreground/30',
                                    )}
                                    style={{
                                        height: `${(v / maxOt) * 80}%`,
                                    }}
                                />
                                <span className="mt-1 text-[9px] text-muted-foreground">
                                    W{i + 1}
                                </span>
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </div>
    );
}

export default AnalyticsPane;
