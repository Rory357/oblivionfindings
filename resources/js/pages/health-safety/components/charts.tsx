/* Health & Safety charts (WS5) — pixel-faithful to the prototype (PROTOTYPE_DIGEST §3).
 * Radial gauges + ratio/severity donuts are token-driven SVG ports (exact geometry: r=48,
 * circumference ≈301.6); the incident trend (bars + LTIFR/TRIFR lines) and hazard burn-down
 * use recharts tuned to match. Colours are semantic tokens via var(--…); no raw hex/oklch. */
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { Building2, type LucideIcon } from 'lucide-react';
import {
    Bar,
    CartesianGrid,
    ComposedChart,
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
    const months = [
        '',
        'Jan',
        'Feb',
        'Mar',
        'Apr',
        'May',
        'Jun',
        'Jul',
        'Aug',
        'Sep',
        'Oct',
        'Nov',
        'Dec',
    ];
    return months[Number(m)] ?? ym;
}

function titleCase(value: string): string {
    return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const BADGE_TONE = {
    critical: 'bg-status-critical-bg text-status-critical',
    warning: 'bg-status-warning-bg text-status-warning',
    success: 'bg-status-success-bg text-status-success',
    info: 'bg-status-info-bg text-status-info',
    primary: 'bg-accent text-primary',
} as const;
type BadgeTone = keyof typeof BADGE_TONE;

function ChartCard({
    title,
    subtitle,
    children,
    className,
    icon: Icon,
    iconTone = 'primary',
    headerRight,
}: {
    title: string;
    subtitle?: string;
    children: React.ReactNode;
    className?: string;
    icon?: LucideIcon;
    iconTone?: BadgeTone;
    headerRight?: React.ReactNode;
}) {
    return (
        <Card className={className}>
            <CardHeader className="pb-2">
                <div className="flex items-start justify-between gap-2">
                    <div className="flex items-center gap-2.5">
                        {Icon ? (
                            <span
                                className={cn(
                                    'inline-flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-[9px]',
                                    BADGE_TONE[iconTone],
                                )}
                            >
                                <Icon className="h-4 w-4" />
                            </span>
                        ) : null}
                        <div>
                            <CardTitle className="text-sm leading-tight font-bold">
                                {title}
                            </CardTitle>
                            {subtitle ? (
                                <p className="mt-0.5 text-[11.5px] text-muted-foreground">
                                    {subtitle}
                                </p>
                            ) : null}
                        </div>
                    </div>
                    {headerRight ?? null}
                </div>
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
        <svg
            viewBox="0 0 120 120"
            className="h-32 w-32"
            role="img"
            aria-label={`${pct}%`}
        >
            <circle
                cx="60"
                cy="60"
                r={RADIUS}
                fill="none"
                stroke="var(--muted)"
                strokeWidth="12"
            />
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
            <text
                x="60"
                y="68"
                textAnchor="middle"
                fill="var(--foreground)"
                fontSize="24"
                fontWeight="700"
            >
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
                <svg
                    viewBox="0 0 120 120"
                    className="h-28 w-28 shrink-0"
                    role="img"
                    aria-label="Near-miss ratio"
                >
                    <circle
                        cx="60"
                        cy="60"
                        r={RADIUS}
                        fill="none"
                        stroke="var(--muted)"
                        strokeWidth="14"
                    />
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
                    <text
                        x="60"
                        y="62"
                        textAnchor="middle"
                        fill="var(--foreground)"
                        fontSize="22"
                        fontWeight="700"
                    >
                        {ratio == null ? '—' : `${ratio}×`}
                    </text>
                    <text
                        x="60"
                        y="78"
                        textAnchor="middle"
                        fill="var(--muted-foreground)"
                        fontSize="9"
                    >
                        ratio
                    </text>
                </svg>
                <div className="min-w-0 text-xs text-muted-foreground">
                    <div>
                        <span className="font-bold text-foreground">
                            {operands.near_misses}
                        </span>{' '}
                        near misses reported
                    </div>
                    <div>
                        <span className="font-bold text-foreground">
                            {operands.recordable}
                        </span>{' '}
                        recordable incidents
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

type SeverityCounts = {
    minorModerate: number;
    serious: number;
    critical: number;
};

export function mapSeverity(
    record: Record<string, number> | undefined,
): SeverityCounts {
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
        {
            label: 'Minor / moderate',
            count: data.minorModerate,
            color: 'var(--status-success)',
        },
        {
            label: 'Serious',
            count: data.serious,
            color: 'var(--status-warning)',
        },
        {
            label: 'Critical',
            count: data.critical,
            color: 'var(--status-critical)',
        },
    ];
    let cumulative = 0;
    return (
        <ChartCard
            title="Severity breakdown"
            subtitle="Open H&S events by severity"
        >
            <div className="flex items-center gap-4">
                <svg
                    viewBox="0 0 120 120"
                    className="h-28 w-28 shrink-0"
                    role="img"
                    aria-label="Severity breakdown"
                >
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
                            <span
                                className="h-2.5 w-2.5 rounded-sm"
                                style={{ background: s.color }}
                            />
                            <span className="text-muted-foreground">
                                {s.label}
                            </span>
                            <span className="ml-auto font-bold text-foreground tabular-nums">
                                {s.count}
                            </span>
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

export function CategoryBarsCard({
    data,
}: {
    data: Array<{ label: string; count: number }>;
}) {
    const max = Math.max(...data.map((d) => d.count), 1);
    return (
        <ChartCard
            title="Incidents by category"
            subtitle="Recordable incidents this period"
        >
            {data.length === 0 ? (
                <p className="py-6 text-center text-xs text-muted-foreground">
                    No incidents this period.
                </p>
            ) : (
                <div className="space-y-2.5 py-1">
                    {data.map((d) => (
                        <div key={d.label} className="flex items-center gap-2">
                            <span className="w-32 shrink-0 truncate text-xs text-muted-foreground">
                                {titleCase(d.label)}
                            </span>
                            <div className="h-[7px] flex-1 overflow-hidden rounded-full bg-muted">
                                <div
                                    className="h-full rounded-full bg-primary"
                                    style={{
                                        width: `${(d.count / max) * 100}%`,
                                    }}
                                />
                            </div>
                            <span className="w-5 shrink-0 text-right text-xs font-semibold text-foreground tabular-nums">
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
    title = 'Incident & near-miss trend',
}: {
    bars: Array<{ month: string; count: number }>;
    frequency: Array<{
        month: string;
        ltifr: number | null;
        trifr: number | null;
    }>;
    variant: 'mini' | 'full';
    title?: string;
}) {
    const freqByMonth = new Map(frequency.map((f) => [f.month, f]));
    const data = bars.map((b) => ({
        label: monthLabel(b.month),
        incidents: b.count,
        ltifr: freqByMonth.get(b.month)?.ltifr ?? null,
        trifr: freqByMonth.get(b.month)?.trifr ?? null,
    }));

    const legend = (
        <div className="flex flex-wrap items-center justify-end gap-x-3.5 gap-y-1 text-[11.5px] text-muted-foreground">
            <span className="inline-flex items-center gap-1.5">
                <span
                    className="h-[9px] w-[9px] rounded-sm"
                    style={{ background: 'var(--primary)' }}
                />
                Incidents
            </span>
            <span className="inline-flex items-center gap-1.5">
                <span
                    className="h-[3px] w-3.5 rounded-sm"
                    style={{ background: 'var(--status-critical)' }}
                />
                TRIFR
            </span>
            <span className="inline-flex items-center gap-1.5">
                <span
                    className="h-[3px] w-3.5 rounded-sm"
                    style={{ background: 'var(--status-warning)' }}
                />
                LTIFR
            </span>
        </div>
    );

    return (
        <ChartCard title={title} headerRight={legend}>
            <ResponsiveContainer
                width="100%"
                height={variant === 'full' ? 240 : 140}
            >
                <ComposedChart
                    data={data}
                    margin={{ top: 8, right: 8, bottom: 0, left: -20 }}
                >
                    <CartesianGrid
                        strokeDasharray="3 4"
                        vertical={false}
                        stroke="var(--border)"
                    />
                    <XAxis
                        dataKey="label"
                        tickLine={false}
                        axisLine={false}
                        tick={{ fontSize: 10, fill: 'var(--muted-foreground)' }}
                    />
                    <YAxis hide />
                    <Tooltip
                        contentStyle={TOOLTIP_STYLE}
                        cursor={{ fill: 'var(--muted)', opacity: 0.4 }}
                    />
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

export function HazardBurndownCard({
    series,
}: {
    series: Array<{ week: string; open: number }>;
}) {
    const first = series[0]?.open ?? 0;
    const last = series[series.length - 1]?.open ?? 0;
    return (
        <ChartCard
            title="Hazard burn-down"
            subtitle={`${first} open → ${last} open over ${series.length} weeks`}
        >
            <ResponsiveContainer width="100%" height={140}>
                <LineChart
                    data={series}
                    margin={{ top: 8, right: 12, bottom: 0, left: -28 }}
                >
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
/*  Site safety league — horizontal bars per site                      */
/* ------------------------------------------------------------------ */

export function SiteLeagueCard({
    data,
}: {
    data: Array<{
        id: number;
        name: string;
        incidents: number;
        hazards: number;
    }>;
}) {
    // Rank by risk score and show the top sites only — the prototype is a compact league,
    // not an exhaustive list (orgs can have many sites).
    const ranked = data
        .map((d) => ({ ...d, score: d.incidents * 2 + d.hazards }))
        .sort((a, b) => b.score - a.score)
        .slice(0, 6);
    const max = Math.max(...ranked.map((d) => d.score), 1);
    return (
        <ChartCard
            title="Site safety league"
            subtitle="Incidents · open hazards (30d)"
            icon={Building2}
            iconTone="primary"
        >
            {ranked.length === 0 ? (
                <p className="py-6 text-center text-xs text-muted-foreground">
                    No sites to compare.
                </p>
            ) : (
                <div className="flex flex-col gap-3 py-1">
                    {ranked.map((d) => {
                        const tone =
                            d.score === 0
                                ? 'success'
                                : d.score >= max * 0.6
                                  ? 'critical'
                                  : 'warning';
                        return (
                            <Link
                                key={d.id}
                                href={`/sites/${d.id}`}
                                className="block rounded-md transition-colors hover:bg-muted/50"
                            >
                                <div className="mb-1 flex items-center justify-between gap-2 text-xs">
                                    <span className="min-w-0 truncate font-semibold text-foreground">
                                        {d.name}
                                    </span>
                                    <span className="shrink-0 text-muted-foreground tabular-nums">
                                        {d.incidents} inc · {d.hazards} haz
                                    </span>
                                </div>
                                <div className="h-[7px] overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full"
                                        style={{
                                            width: `${Math.max((d.score / max) * 100, 6)}%`,
                                            background: `var(--status-${tone})`,
                                        }}
                                    />
                                </div>
                            </Link>
                        );
                    })}
                </div>
            )}
        </ChartCard>
    );
}

/* ------------------------------------------------------------------ */
/*  Open hazards list (Leading row 3)                                  */
/* ------------------------------------------------------------------ */

export type OpenHazardRow = {
    id: number;
    site_id: number | null;
    title: string;
    risk_rating: string | null;
    site: string | null;
};

const HAZARD_TONE: Record<string, { label: string; cls: string }> = {
    extreme: {
        label: 'High',
        cls: 'bg-status-critical-bg text-status-critical',
    },
    high: { label: 'High', cls: 'bg-status-critical-bg text-status-critical' },
    medium: { label: 'Med', cls: 'bg-status-warning-bg text-status-warning' },
    low: { label: 'Low', cls: 'bg-muted text-muted-foreground' },
};

export function OpenHazardsCard({ hazards }: { hazards: OpenHazardRow[] }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <div className="flex items-center justify-between gap-2">
                    <CardTitle className="text-sm leading-tight font-bold">
                        Open hazards
                    </CardTitle>
                    <Link
                        href="/compliance/hazards"
                        className="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-primary hover:underline"
                    >
                        Register →
                    </Link>
                </div>
            </CardHeader>
            <CardContent>
                {hazards.length === 0 ? (
                    <p className="py-6 text-center text-xs text-muted-foreground">
                        No open hazards.
                    </p>
                ) : (
                    <div className="flex flex-col gap-1">
                        {hazards.map((h) => {
                            const tone = HAZARD_TONE[
                                (h.risk_rating ?? '').toLowerCase()
                            ] ?? {
                                label: 'Low',
                                cls: 'bg-muted text-muted-foreground',
                            };
                            const inner = (
                                <>
                                    <span
                                        className={cn(
                                            'shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold',
                                            tone.cls,
                                        )}
                                    >
                                        {tone.label}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate text-[12.5px] font-semibold text-foreground">
                                        {h.title}
                                    </span>
                                </>
                            );
                            return h.site_id ? (
                                <Link
                                    key={h.id}
                                    href={`/sites/${h.site_id}`}
                                    className="flex items-center gap-2.5 rounded-lg border border-transparent px-2.5 py-2 transition-colors hover:border-border hover:bg-muted/50"
                                >
                                    {inner}
                                </Link>
                            ) : (
                                <div
                                    key={h.id}
                                    className="flex items-center gap-2.5 rounded-lg px-2.5 py-2"
                                >
                                    {inner}
                                </div>
                            );
                        })}
                    </div>
                )}
            </CardContent>
        </Card>
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
    openHazards,
}: {
    ratio: number | null;
    operands: { near_misses: number; recordable: number };
    burndown: Array<{ week: string; open: number }>;
    drillPct: number;
    trainingPct: number;
    openHazards: OpenHazardRow[];
}) {
    return (
        <div className="flex flex-col gap-3">
            <div className="grid gap-3 lg:grid-cols-2">
                <RatioDonutCard ratio={ratio} operands={operands} />
                <HazardBurndownCard series={burndown} />
            </div>
            <div className="grid gap-3 lg:grid-cols-[1fr_1fr_1.4fr]">
                <GaugeCard pct={drillPct} title="Drill compliance" />
                <GaugeCard pct={trainingPct} title="Training compliance" />
                <OpenHazardsCard hazards={openHazards} />
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
    frequency: Array<{
        month: string;
        ltifr: number | null;
        trifr: number | null;
    }>;
    severity: Record<string, number> | undefined;
    category: Array<{ label: string; count: number }>;
}) {
    return (
        <div className={cn('flex flex-col gap-3')}>
            <IncidentTrendCard
                bars={bars}
                frequency={frequency}
                variant="full"
                title="Incident trend with LTIFR & TRIFR"
            />
            <div className="grid gap-3 lg:grid-cols-2">
                <SeverityDonutCard data={mapSeverity(severity)} />
                <CategoryBarsCard data={category} />
            </div>
        </div>
    );
}
