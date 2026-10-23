/* Health & Safety charts (WS5) — pixel-faithful to the prototype (PROTOTYPE_DIGEST §3).
 * Radial gauges + ratio/severity donuts are token-driven SVG ports (exact geometry: r=48,
 * circumference ≈301.6); the incident trend (bars + LTIFR/TRIFR lines) and hazard burn-down
 * use recharts tuned to match. Colours are semantic tokens via var(--…); no raw hex/oklch. */
import { Ring, RingCard, RowList, SectionHead } from '@/components/signature/blocks';
import { StatusBadge } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type LucideIcon } from 'lucide-react';
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

/** Every overview chart sits on the signature surface (approved H&S drawings, 23 Oct): title · sub-line, the
 * legend or a link on the right; no icon square. `icon`/`iconTone` are kept for callers but no longer drawn. */
function ChartCard({
    title,
    subtitle,
    children,
    className,
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
        <section className={cn('sg-surface', className)}>
            <div className="sg-head">
                <h3>{title}</h3>
                {subtitle ? <p>{subtitle}</p> : null}
                {headerRight ? <div className="ml-auto">{headerRight}</div> : null}
            </div>
            <div className="px-[18px] pt-2 pb-4">{children}</div>
        </section>
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

export function GaugeCard({
    pct,
    title,
}: {
    pct: number | null;
    title: string;
}) {
    return (
        <ChartCard title={title}>
            <div className="flex items-center justify-center py-1">
                {pct === null ? (
                    <p className="text-subtle py-6">Not confirmed</p>
                ) : (
                    <Gauge pct={pct} />
                )}
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
    operands: { near_misses: number; recordable: number | null };
}) {
    const total =
        operands.recordable === null
            ? null
            : operands.near_misses + operands.recordable;
    const confirmed = total !== null && ratio !== null;
    const fillPct = confirmed && total > 0 ? operands.near_misses / total : 0;
    const offset = CIRC * (1 - fillPct);
    return (
        <ChartCard title="Near misses for each recordable injury" subtitle="Reports you can see · last 12 months">
            <div className="flex items-center gap-4">
                <svg
                    viewBox="0 0 120 120"
                    className="h-28 w-28 shrink-0"
                    role="img"
                    aria-label={
                        confirmed
                            ? 'Near-miss ratio'
                            : 'Near-miss ratio not confirmed'
                    }
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
                        stroke="var(--chart-1)"
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
                        {confirmed ? `${ratio}×` : '—'}
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
                            {operands.recordable ?? 'Not confirmed'}
                        </span>{' '}
                        recordable injuries
                    </div>
                    <p className="mt-1.5 text-muted-foreground">
                        {confirmed
                            ? 'Compares readable near misses with recordable injuries.'
                            : 'The ratio is not confirmed; its operands may be incomplete.'}
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
            title="By severity"
            subtitle="Open safety reviews by severity"
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
            title="Incidents by type"
            subtitle="Incidents you can see, this period"
        >
            {data.length === 0 ? (
                <p className="py-6 text-center text-xs text-muted-foreground">
                    No matching readable incidents in this period.
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
            title="Open hazards coming down"
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

export function SiteSafetyComparisonCard({
    data,
}: {
    data: Array<{
        id: number;
        name: string;
        incidents: number;
        hazards: number | null;
    }>;
}) {
    // A–Z, never ranked: reporting activity is not a safety rating (approved H&S drawings, 23 Oct).
    const sites = [...data].sort(
        (a, b) => a.name.localeCompare(b.name, 'en-NZ') || a.id - b.id,
    );
    return (
        <section className="sg-side" aria-label="Sites side by side">
            <SectionHead
                title="Sites side by side"
                sub="Reports this period and hazards open now, A–Z, from records you can see. This isn't a safety rating: fewer reports don't mean a safer site."
            />
            {sites.length === 0 ? (
                <p className="sg-surface sg-quiet">
                    <Ring tone="none" />
                    No sites are available in this view.
                </p>
            ) : (
                <ul className="grid gap-3">
                    {sites.map((site) => (
                        <li key={site.id} className="sg-surface sg-place">
                            <Link
                                href={`/sites/${site.id}`}
                                aria-label={`Open ${site.name} safety comparison`}
                                className="frontline-tap grid gap-2.5 rounded-md outline-none focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <span className="sg-ph">
                                    <b>{site.name}</b>
                                </span>
                                <span className="sg-facts2">
                                    <span>
                                        <Ring tone="none" />
                                        <span>
                                            <b className="tabular-nums">
                                                {site.incidents}
                                            </b>{' '}
                                            safety reports
                                        </span>
                                    </span>
                                    <span>
                                        <Ring tone="none" />
                                        <span>
                                            <b className="tabular-nums">
                                                {site.hazards === null
                                                    ? 'Restricted'
                                                    : site.hazards}
                                            </b>{' '}
                                            open hazards
                                        </span>
                                    </span>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
/** Retain the previous import contract while the dashboard uses the neutral comparison. */
export const SiteLeagueCard = SiteSafetyComparisonCard;

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

const HAZARD_LABEL: Record<string, string> = {
    extreme: 'Extreme',
    high: 'High',
    medium: 'Medium',
    low: 'Low',
};
const HAZARD_VARIANT: Record<string, 'critical' | 'warning' | 'neutral'> = {
    extreme: 'critical',
    high: 'critical',
    medium: 'warning',
    low: 'neutral',
};

export function OpenHazardsCard({ hazards }: { hazards: OpenHazardRow[] }) {
    return (
        <RowList
            title="Open hazards"
            sub="Register"
            action={{ label: 'Open register', href: '/compliance/hazards' }}
            empty="No open hazards in this view."
            items={hazards.map((h) => {
                const rating = (h.risk_rating ?? '').toLowerCase();
                return {
                    key: h.id,
                    title: h.title,
                    sub: h.site ?? undefined,
                    href: h.site_id ? `/sites/${h.site_id}` : undefined,
                    badge: (
                        <StatusBadge
                            variant={HAZARD_VARIANT[rating] ?? 'neutral'}
                            size="sm"
                        >
                            {HAZARD_LABEL[rating] ?? 'Low'}
                        </StatusBadge>
                    ),
                };
            })}
        />
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
    openHazards,
}: {
    ratio: number | null;
    operands: { near_misses: number; recordable: number | null };
    burndown: Array<{ week: string; open: number }>;
    drillPct: number | null;
    trainingPct: number | null;
    openHazards: OpenHazardRow[];
}) {
    // Prevention (approved H&S drawings, 23 Oct): one block per row; training is a header number.
    return (
        <div className="flex flex-col gap-5">
            <RatioDonutCard ratio={ratio} operands={operands} />
            <HazardBurndownCard series={burndown} />
            <RingCard
                value={drillPct ?? 0}
                mark={drillPct === null ? '—' : undefined}
                label={drillPct === null ? 'Drills done on schedule' : `Drills done on schedule — ${drillPct}%`}
                sub={drillPct === null ? 'Not confirmed' : undefined}
            />
            <OpenHazardsCard hazards={openHazards} />
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
        <div className="flex flex-col gap-5">
            <IncidentTrendCard
                bars={bars}
                frequency={frequency}
                variant="full"
                title="Incidents with the injury rates"
            />
            <SeverityDonutCard data={mapSeverity(severity)} />
            <CategoryBarsCard data={category} />
        </div>
    );
}
