/* Health & Safety charts (WS5) — pixel-faithful to the prototype (PROTOTYPE_DIGEST §3).
 * Radial gauges + ratio/severity donuts are token-driven SVG ports (exact geometry: r=48,
 * circumference ≈301.6); the incident trend (bars + LTIFR/TRIFR lines) and hazard burn-down
 * use recharts tuned to match. Colours are semantic tokens via var(--…); no raw hex/oklch. */
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import {
    Bar,
    CartesianGrid,
    ComposedChart,
    Legend,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

const RADIUS = 48;
const CIRC = 2 * Math.PI * RADIUS; // ≈ 301.59

const TOOLTIP_STYLE = {
    background: 'var(--popover)',
    border: '1px solid var(--border)',
    borderRadius: 8,
    fontSize: 11,
    color: 'var(--popover-foreground)',
} as const;

function monthLabel(ym: string): string {
    const [, m] = ym.split('-');
    const months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return months[Number(m)] ?? ym;
}

function titleCase(value: string): string {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function ChartCard({
    title,
    subtitle,
    children,
    className,
}: {
    title: string;
    subtitle?: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <Card className={className}>
            <CardHeader className="pb-2">
                <CardTitle className="text-base font-semibold">{title}</CardTitle>
                {subtitle ? <p className="mt-0.5 text-xs text-muted-foreground">{subtitle}</p> : null}
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
    );
}

/* ------------------------------------------------------------------ */
/*  Radial gauge (drill / training)                                    */
/* ------------------------------------------------------------------ */

function Gauge({ pct }: { pct: number }) {
    const clamped = Math.max(0, Math.min(100, pct));
    const offset = CIRC * (1 - clamped / 100);
    return (
        <svg viewBox="0 0 120 120" className="h-32 w-32" role="img" aria-label={`${pct}%`}>
            <circle cx="60" cy="60" r={RADIUS} fill="none" stroke="var(--muted)" strokeWidth="12" />
            <circle
                cx="60"
                cy="60"
                r={RADIUS}
                fill="none"
                stroke="var(--status-success)"
                strokeWidth="12"
                strokeLinecap="round"
                strokeDasharray={CIRC}
                strokeDashoffset={offset}
                transform="rotate(-90 60 60)"
            />
            <text x="60" y="68" textAnchor="middle" fill="var(--foreground)" fontSize="24" fontWeight="700">
                {pct}%
            </text>
        </svg>
    );
}

export function GaugeCard({ pct, title }: { pct: number; title: string }) {
    return (
        <ChartCard title={title}>
            <div className="flex items-center justify-center py-1">
                <Gauge pct={pct} />
            </div>
        </ChartCard>
    );
}

/* ------------------------------------------------------------------ */
/*  Near-miss : incident ratio donut                                   */
/* ------------------------------------------------------------------ */

export function RatioDonutCard({
    ratio,
    operands,
}: {
    ratio: number | null;
    operands: { near_misses: number; recordable: number };
}) {
    const total = operands.near_misses + operands.recordable;
    const fillPct = total > 0 ? operands.near_misses / total : 0;
    const offset = CIRC * (1 - fillPct);
    return (
        <ChartCard title="Near-miss : incident ratio">
            <div className="flex items-center gap-4">
                <svg viewBox="0 0 120 120" className="h-28 w-28 shrink-0" role="img" aria-label="Near-miss ratio">
                    <circle cx="60" cy="60" r={RADIUS} fill="none" stroke="var(--muted)" strokeWidth="14" />
                    <circle
                        cx="60"
                        cy="60"
                        r={RADIUS}
                        fill="none"
                        stroke="var(--status-success)"
                        strokeWidth="14"
                        strokeLinecap="round"
                        strokeDasharray={CIRC}
                        strokeDashoffset={offset}
                        transform="rotate(-90 60 60)"
                    />
                    <text x="60" y="62" textAnchor="middle" fill="var(--foreground)" fontSize="22" fontWeight="700">
                        {ratio == null ? '—' : `${ratio}×`}
                    </text>
                    <text x="60" y="78" textAnchor="middle" fill="var(--muted-foreground)" fontSize="9">
                        ratio
                    </text>
                </svg>
                <div className="min-w-0 text-xs text-muted-foreground">
                    <div>
                        <span className="font-bold text-foreground">{operands.near_misses}</span> near misses reported
                    </div>
                    <div>
                        <span className="font-bold text-foreground">{operands.recordable}</span> recordable incidents
                    </div>
                    <p className="mt-1.5 text-status-success">
                        A high ratio means hazards are caught before harm.
                    </p>
                </div>
            </div>
        </ChartCard>
    );
}

/* ------------------------------------------------------------------ */
/*  Severity breakdown donut                                           */
/* ------------------------------------------------------------------ */

type SeverityCounts = { minorModerate: number; serious: number; critical: number };

export function mapSeverity(record: Record<string, number> | undefined): SeverityCounts {
    const r = record ?? {};
    return {
        minorModerate: (r.low ?? 0) + (r.medium ?? 0),
        serious: r.high ?? 0,
        critical: r.critical ?? 0,
    };
}

export function SeverityDonutCard({ data }: { data: SeverityCounts }) {
    const total = data.minorModerate + data.serious + data.critical || 1;
    const segs = [
        { label: 'Minor / moderate', count: data.minorModerate, color: 'var(--status-success)' },
        { label: 'Serious', count: data.serious, color: 'var(--status-warning)' },
        { label: 'Critical', count: data.critical, color: 'var(--status-critical)' },
    ];
    let cumulative = 0;
    return (
        <ChartCard title="Severity breakdown" subtitle="Open H&S events by severity">
            <div className="flex items-center gap-4">
                <svg viewBox="0 0 120 120" className="h-28 w-28 shrink-0" role="img" aria-label="Severity breakdown">
                    {segs.map((s) => {
                        const len = (s.count / total) * CIRC;
                        const seg = (
                            <circle
                                key={s.label}
                                cx="60"
                                cy="60"
                                r={RADIUS}
                                fill="none"
                                stroke={s.color}
                                strokeWidth="16"
                                strokeDasharray={`${len} ${CIRC - len}`}
                                strokeDashoffset={-cumulative}
                                transform="rotate(-90 60 60)"
                            />
                        );
                        cumulative += len;
                        return seg;
                    })}
                </svg>
                <ul className="min-w-0 space-y-1 text-xs">
                    {segs.map((s) => (
                        <li key={s.label} className="flex items-center gap-2">
                            <span className="h-2.5 w-2.5 rounded-sm" style={{ background: s.color }} />
                            <span className="text-muted-foreground">{s.label}</span>
                            <span className="ml-auto font-bold tabular-nums text-foreground">{s.count}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </ChartCard>
    );
}

/* ------------------------------------------------------------------ */
/*  Incidents by category — horizontal bars                            */
/* ------------------------------------------------------------------ */

export function CategoryBarsCard({ data }: { data: Array<{ label: string; count: number }> }) {
    const max = Math.max(...data.map((d) => d.count), 1);
    return (
        <ChartCard title="Incidents by category" subtitle="Recordable incidents this period">
            {data.length === 0 ? (
                <p className="py-6 text-center text-xs text-muted-foreground">No incidents this period.</p>
            ) : (
                <div className="space-y-2.5 py-1">
                    {data.map((d) => (
                        <div key={d.label} className="flex items-center gap-2">
                            <span className="w-32 shrink-0 truncate text-xs text-muted-foreground">{titleCase(d.label)}</span>
                            <div className="h-[7px] flex-1 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full rounded-full bg-primary"
                                    style={{ width: `${(d.count / max) * 100}%` }}
                                />
                            </div>
                            <span className="w-5 shrink-0 text-right text-xs font-semibold tabular-nums text-foreground">
                                {d.count}
                            </span>
                        </div>
                    ))}
                </div>
            )}
        </ChartCard>
    );
}

/* ------------------------------------------------------------------ */
/*  Incident & near-miss trend — bars + LTIFR/TRIFR lines              */
/* ------------------------------------------------------------------ */

export function IncidentTrendCard({
    bars,
    frequency,
    variant,
}: {
    bars: Array<{ month: string; count: number }>;
    frequency: Array<{ month: string; ltifr: number | null; trifr: number | null }>;
    variant: 'mini' | 'full';
}) {
    const freqByMonth = new Map(frequency.map((f) => [f.month, f]));
    const data = bars.map((b) => ({
        label: monthLabel(b.month),
        incidents: b.count,
        ltifr: freqByMonth.get(b.month)?.ltifr ?? null,
        trifr: freqByMonth.get(b.month)?.trifr ?? null,
    }));

    return (
        <ChartCard
            title="Incident & near-miss trend"
            subtitle="Monthly incidents · TRIFR & LTIFR per million hours"
        >
            <ResponsiveContainer width="100%" height={variant === 'full' ? 240 : 140}>
                <ComposedChart data={data} margin={{ top: 8, right: 8, bottom: 0, left: -20 }}>
                    {variant === 'full' ? (
                        <CartesianGrid strokeDasharray="3 4" vertical={false} stroke="var(--border)" />
                    ) : null}
                    <XAxis
                        dataKey="label"
                        tickLine={false}
                        axisLine={false}
                        tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }}
                    />
                    <YAxis hide />
                    <Tooltip contentStyle={TOOLTIP_STYLE} cursor={{ fill: 'var(--muted)', opacity: 0.4 }} />
                    {variant === 'full' ? <Legend wrapperStyle={{ fontSize: 11 }} /> : null}
                    <Bar
                        dataKey="incidents"
                        name="Incidents"
                        fill="var(--primary)"
                        fillOpacity={0.7}
                        radius={[2, 2, 0, 0]}
                        maxBarSize={22}
                    />
                    <Line
                        dataKey="trifr"
                        name="TRIFR"
                        stroke="var(--status-critical)"
                        strokeWidth={2.5}
                        dot={false}
                        connectNulls
                    />
                    <Line
                        dataKey="ltifr"
                        name="LTIFR"
                        stroke="var(--status-warning)"
                        strokeWidth={2.5}
                        dot={false}
                        connectNulls
                    />
                </ComposedChart>
            </ResponsiveContainer>
        </ChartCard>
    );
}

/* ------------------------------------------------------------------ */
/*  Hazard burn-down line                                              */
/* ------------------------------------------------------------------ */

export function HazardBurndownCard({ series }: { series: Array<{ week: string; open: number }> }) {
    const first = series[0]?.open ?? 0;
    const last = series[series.length - 1]?.open ?? 0;
    return (
        <ChartCard
            title="Hazard burn-down"
            subtitle={`${first} open → ${last} open over ${series.length} weeks`}
        >
            <ResponsiveContainer width="100%" height={140}>
                <LineChart data={series} margin={{ top: 8, right: 12, bottom: 0, left: -28 }}>
                    <XAxis dataKey="week" hide />
                    <YAxis hide />
                    <Tooltip contentStyle={TOOLTIP_STYLE} />
                    <Line
                        dataKey="open"
                        name="Open hazards"
                        stroke="var(--primary)"
                        strokeWidth={2.5}
                        dot={{ r: 2, fill: 'var(--primary)' }}
                        activeDot={{ r: 4 }}
                    />
                </LineChart>
            </ResponsiveContainer>
        </ChartCard>
    );
}

/* ------------------------------------------------------------------ */
/*  Grouped tab layouts                                                */
/* ------------------------------------------------------------------ */

export function LeadingCharts({
    ratio,
    operands,
    burndown,
    drillPct,
    trainingPct,
}: {
    ratio: number | null;
    operands: { near_misses: number; recordable: number };
    burndown: Array<{ week: string; open: number }>;
    drillPct: number;
    trainingPct: number;
}) {
    return (
        <div className="flex flex-col gap-3">
            <div className="grid gap-3 lg:grid-cols-2">
                <RatioDonutCard ratio={ratio} operands={operands} />
                <HazardBurndownCard series={burndown} />
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <GaugeCard pct={drillPct} title="Drill compliance" />
                <GaugeCard pct={trainingPct} title="Training compliance" />
            </div>
        </div>
    );
}

export function LaggingCharts({
    bars,
    frequency,
    severity,
    category,
}: {
    bars: Array<{ month: string; count: number }>;
    frequency: Array<{ month: string; ltifr: number | null; trifr: number | null }>;
    severity: Record<string, number> | undefined;
    category: Array<{ label: string; count: number }>;
}) {
    return (
        <div className={cn('flex flex-col gap-3')}>
            <IncidentTrendCard bars={bars} frequency={frequency} variant="full" />
            <div className="grid gap-3 lg:grid-cols-2">
                <SeverityDonutCard data={mapSeverity(severity)} />
                <CategoryBarsCard data={category} />
            </div>
        </div>
    );
}
