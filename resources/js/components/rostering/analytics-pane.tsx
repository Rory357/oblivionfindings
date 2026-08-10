import { cn } from '@/lib/utils';

import { Card as GuardrailCard } from '@/components/ui/card';
import { MicroStats, type MicroStat } from './micro-stats';

export type AnalyticsTrendPoint = { week: string; coverage: number };
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
    coverageTrend: AnalyticsTrendPoint[];
    dailyCoverage?: DailyCoveragePoint[];
    shiftTypes: ShiftTypeSlice[];
    fillBySite: FillBySite[];
    overtimeTrend?: number[];
};

export function AnalyticsPane({
    stats,
    coverageTrend,
    dailyCoverage = [],
    shiftTypes,
    fillBySite,
    overtimeTrend = [],
}: AnalyticsPaneProps) {
    const totalShifts = shiftTypes.reduce((s, x) => s + x.value, 0) || 1;
    const maxDailyScheduled = Math.max(
        1,
        ...dailyCoverage.map((d) => d.scheduled),
    );

    const W = 520;
    const H = 170;
    const PAD = 28;
    const points =
        coverageTrend.length > 0 ? coverageTrend : [{ week: '—', coverage: 0 }];
    const xs = points.map(
        (_, i) =>
            PAD +
            (points.length === 1
                ? (W - 2 * PAD) / 2
                : (i / (points.length - 1)) * (W - 2 * PAD)),
    );
    const ymin = 80;
    const ymax = 100;
    const yFor = (v: number) =>
        H - PAD - ((v - ymin) / (ymax - ymin)) * (H - 2 * PAD - 12);
    const linePath = points
        .map(
            (p, i) =>
                `${i === 0 ? 'M' : 'L'}${xs[i]},${yFor(Math.max(ymin, Math.min(ymax, p.coverage)))}`,
        )
        .join(' ');
    const areaPath =
        linePath + ` L${xs[xs.length - 1]},${H - PAD} L${xs[0]},${H - PAD} Z`;

    const maxOt = Math.max(1, ...overtimeTrend);
    const otDelta =
        overtimeTrend.length >= 2
            ? overtimeTrend[overtimeTrend.length - 1] - overtimeTrend[0]
            : 0;

    return (
        <div className="space-y-4">
            <MicroStats stats={stats} />

            <div className="grid gap-3 lg:grid-cols-[1.4fr_1fr]">
                <section className="rounded-[14px] border border-border bg-card p-4 shadow-sm">
                    <div className="mb-3 flex items-center justify-between">
                        <div>
                            <h3 className="text-sm font-bold tracking-tight">
                                Coverage trend · last {points.length} weeks
                            </h3>
                            <div className="text-[11px] text-muted-foreground">
                                Filled vs. target (95%)
                            </div>
                        </div>
                        <GuardrailCard
                            unstyled
                            className="inline-flex rounded-md border border-border bg-background p-0.5 text-[11px]"
                        >
                            <button
                                type="button"
                                className="rounded-sm px-2 py-1 font-semibold text-muted-foreground hover:bg-accent"
                            >
                                4w
                            </button>
                            <button
                                type="button"
                                className="rounded-sm bg-primary px-2 py-1 font-semibold text-primary-foreground"
                            >
                                8w
                            </button>
                            <button
                                type="button"
                                className="rounded-sm px-2 py-1 font-semibold text-muted-foreground hover:bg-accent"
                            >
                                12w
                            </button>
                        </GuardrailCard>
                    </div>
                    <div className="w-full overflow-hidden">
                        <svg
                            viewBox={`0 0 ${W} ${H}`}
                            width="100%"
                            preserveAspectRatio="none"
                        >
                            <defs>
                                <linearGradient
                                    id="rost-trendFill"
                                    x1="0"
                                    x2="0"
                                    y1="0"
                                    y2="1"
                                >
                                    <stop
                                        offset="0%"
                                        stopColor="var(--primary)"
                                        stopOpacity="0.30"
                                    />
                                    <stop
                                        offset="100%"
                                        stopColor="var(--primary)"
                                        stopOpacity="0"
                                    />
                                </linearGradient>
                            </defs>
                            {[80, 85, 90, 95, 100].map((v) => (
                                <g key={v}>
                                    <line
                                        x1={PAD}
                                        x2={W - PAD}
                                        y1={yFor(v)}
                                        y2={yFor(v)}
                                        stroke="var(--border)"
                                        strokeWidth="1"
                                        strokeDasharray={v === 95 ? '4 4' : '0'}
                                    />
                                    <text
                                        x={PAD - 6}
                                        y={yFor(v) + 3}
                                        textAnchor="end"
                                        fontSize="10"
                                        fill="var(--muted-foreground)"
                                    >
                                        {v}%
                                    </text>
                                </g>
                            ))}
                            <path d={areaPath} fill="url(#rost-trendFill)" />
                            <path
                                d={linePath}
                                stroke="var(--primary)"
                                strokeWidth="2.5"
                                fill="none"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                            />
                            {points.map((p, i) => (
                                <g key={i}>
                                    <circle
                                        cx={xs[i]}
                                        cy={yFor(
                                            Math.max(
                                                ymin,
                                                Math.min(ymax, p.coverage),
                                            ),
                                        )}
                                        r={i === points.length - 1 ? 5 : 3.5}
                                        fill="var(--background)"
                                        stroke="var(--primary)"
                                        strokeWidth="2"
                                    />
                                    <text
                                        x={xs[i]}
                                        y={H - 8}
                                        textAnchor="middle"
                                        fontSize="10"
                                        fill="var(--muted-foreground)"
                                    >
                                        {p.week}
                                    </text>
                                </g>
                            ))}
                        </svg>
                    </div>
                </section>

                <section className="rounded-[14px] border border-border bg-card p-4 shadow-sm">
                    <div className="mb-3">
                        <h3 className="text-sm font-bold tracking-tight">
                            Daily coverage
                        </h3>
                        <div className="text-[11px] text-muted-foreground">
                            Filled vs. open shifts this week
                        </div>
                    </div>
                    <div className="space-y-2">
                        {dailyCoverage.length === 0 ? (
                            <div className="text-xs text-muted-foreground">
                                No daily coverage data this week.
                            </div>
                        ) : null}
                        {dailyCoverage.map((day) => {
                            const filledWidth =
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
                                        title={`${day.filled}/${day.scheduled} filled · ${day.open} open`}
                                    >
                                        <span
                                            className="block h-full bg-status-success"
                                            style={{
                                                width: `${Math.max(0, filledWidth)}%`,
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
                                            {day.filled}/{day.scheduled} filled
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
                                        width: `${(t.value / totalShifts) * 100}%`,
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
                                                (t.value / totalShifts) * 100,
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
                            Fill rate by site
                        </h3>
                        <div className="text-[11px] text-muted-foreground">
                            This week
                        </div>
                    </div>
                    <ul className="space-y-2">
                        {fillBySite.length === 0 ? (
                            <li className="text-xs text-muted-foreground">
                                No site data this week.
                            </li>
                        ) : null}
                        {fillBySite
                            .slice()
                            .sort((a, b) => b.rate - a.rate)
                            .map((s) => {
                                const cls =
                                    s.rate >= 95
                                        ? 'bg-status-success'
                                        : s.rate >= 90
                                          ? 'bg-status-info'
                                          : s.rate >= 85
                                            ? 'bg-status-warning'
                                            : 'bg-status-critical';
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
                                                className={cn(
                                                    'block h-full',
                                                    cls,
                                                )}
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
                                Weekly · last {overtimeTrend.length} weeks
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
