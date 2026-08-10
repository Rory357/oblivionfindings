/* Behaviour-pattern (PBS) analytics for the client profile Behaviour / ABC tab.
 * Reads the `behaviour_patterns` prop (App\Services\Client\BehaviourPatternsService).
 *  - BehaviourStatStrip: the headline MiniStat strip (entries 90d, avg duration,
 *    quarter trend, top antecedent, entries-by-month) — matches the design.
 *  - BehaviourInsightsCard: the deeper patterns card (frequency, function-of-
 *    behaviour breakdown, intensity mix, common settings/types/strategies).
 * Theme-token div charts (no recharts) so colours follow the brand. */
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import {
    Activity,
    Clock,
    Sparkles,
    TrendingDown,
    TrendingUp,
    Zap,
    type LucideIcon,
} from 'lucide-react';

export type BehaviourPattern = {
    window_days?: number;
    entry_count?: number;
    concern_note_count?: number;
    escalated_count?: number;
    with_harm_count?: number;
    function_breakdown?: Array<{ key: string; label: string; count: number }>;
    intensity_mix?: { low: number; medium: number; high: number };
    top_settings?: Array<{ label: string; count: number }>;
    top_strategies?: Array<{ label: string; count: number }>;
    top_behaviour_tags?: Array<{ label: string; count: number }>;
    daily_series?: Array<{ date: string; entries: number; concerns: number }>;
    summary?: {
        entries_90d?: number;
        avg_duration_seconds?: number | null;
        trend_pct?: number | null;
        top_antecedent?: string | null;
        entries_by_month?: Array<{ key: string; label: string; count: number }>;
    };
};

const TONES: Record<string, string> = {
    primary: 'bg-primary/10 text-primary',
    neutral: 'bg-muted text-muted-foreground',
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
};

function fmtDuration(sec?: number | null): string {
    if (sec == null) return '—';
    if (sec < 60) return `${sec}s`;
    const m = Math.round(sec / 60);
    if (m < 60) return `${m}m`;
    const h = Math.floor(m / 60);
    const rem = m % 60;
    return rem ? `${h}h ${rem}m` : `${h}h`;
}

function truncate(s: string, n: number): string {
    return s.length > n ? `${s.slice(0, n).trimEnd()}…` : s;
}

/* ---------------------------------------------------- headline stat strip */

function MiniStat({
    icon: Icon,
    tone,
    value,
    label,
    title,
}: {
    icon: LucideIcon;
    tone: string;
    value: string;
    label: string;
    title?: string;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- MiniStat tile from the design system (icon square + value + label).
        <div
            className="flex min-w-[150px] flex-1 items-center gap-3 rounded-xl border border-border bg-card px-4 py-3"
            title={title}
        >
            <span
                className={cn(
                    'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                    TONES[tone],
                )}
            >
                <Icon className="h-[18px] w-[18px]" />
            </span>
            <div className="min-w-0 leading-tight">
                <div className="truncate text-xl font-bold">{value}</div>
                <div className="text-xs text-muted-foreground">{label}</div>
            </div>
        </div>
    );
}

function MonthBars({
    months,
}: {
    months: Array<{ key: string; label: string; count: number }>;
}) {
    const peak = Math.max(1, ...months.map((m) => m.count));
    const range = months.length
        ? `${months[0].label} – ${months[months.length - 1].label}`
        : '';
    return (
        // eslint-disable-next-line no-restricted-syntax -- wider stat card with the entries-by-month sparkline.
        <div className="flex min-w-[280px] flex-[2] flex-col rounded-xl border border-border bg-card px-4 py-3">
            <div className="mb-2 flex items-center justify-between">
                <span className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    Entries by month
                </span>
                <span className="text-[11px] text-muted-foreground">
                    {range}
                </span>
            </div>
            {months.length === 0 ? (
                <div className="flex flex-1 items-center text-xs text-muted-foreground italic">
                    No entries yet
                </div>
            ) : (
                <div
                    className="flex flex-1 items-end gap-2"
                    style={{ minHeight: 38 }}
                >
                    {months.map((m) => (
                        <div
                            key={m.key}
                            className="flex flex-1 items-end"
                            style={{ height: 38 }}
                            title={`${m.label}: ${m.count} ${m.count === 1 ? 'entry' : 'entries'}`}
                        >
                            <div
                                className={cn(
                                    'w-full rounded-md',
                                    m.count > 0
                                        ? 'bg-primary/80'
                                        : 'bg-primary/15',
                                )}
                                style={{
                                    height: `${m.count > 0 ? Math.max(16, (m.count / peak) * 100) : 12}%`,
                                }}
                            />
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

export function BehaviourStatStrip({
    patterns,
}: {
    patterns?: BehaviourPattern;
}) {
    const s = patterns?.summary ?? {};
    const entries = s.entries_90d ?? 0;
    const trend = s.trend_pct ?? null;
    const topAntecedent = s.top_antecedent ?? null;
    const months = s.entries_by_month ?? [];

    const trendUp = (trend ?? 0) > 0;
    const trendLabel = trend == null ? '—' : `${trend > 0 ? '+' : ''}${trend}%`;

    return (
        <div className="flex flex-wrap gap-3" data-test="abc-stat-strip">
            <MiniStat
                icon={Activity}
                tone="primary"
                value={String(entries)}
                label="Entries (90 days)"
            />
            <MiniStat
                icon={Clock}
                tone="neutral"
                value={fmtDuration(s.avg_duration_seconds)}
                label="Avg duration"
            />
            <MiniStat
                icon={trendUp ? TrendingUp : TrendingDown}
                tone={
                    trend == null ? 'neutral' : trendUp ? 'warning' : 'success'
                }
                value={trendLabel}
                label="vs last quarter"
            />
            <MiniStat
                icon={Zap}
                tone="warning"
                value={topAntecedent ? truncate(topAntecedent, 16) : '—'}
                label="Top antecedent"
                title={topAntecedent ?? undefined}
            />
            <MonthBars months={months} />
        </div>
    );
}

/* --------------------------------------------------- deeper patterns card */

function FrequencyTrend({
    series,
}: {
    series: Array<{ date: string; entries: number; concerns: number }>;
}) {
    if (!series || series.length === 0) return null;
    const peak = Math.max(1, ...series.map((s) => s.entries + s.concerns));

    return (
        <div>
            <p className="mb-1 text-xs font-medium text-muted-foreground">
                Frequency
                <span className="ml-2 inline-flex items-center gap-2">
                    <span className="inline-block h-2 w-2 rounded-sm bg-primary/70" />
                    entries
                    <span className="inline-block h-2 w-2 rounded-sm bg-status-warning/70" />
                    concern notes
                </span>
            </p>
            <div className="flex h-16 items-end gap-px">
                {series.map((point) => {
                    const e = (point.entries / peak) * 100;
                    const c = (point.concerns / peak) * 100;
                    return (
                        <div
                            key={point.date}
                            className="flex flex-1 flex-col-reverse"
                            title={`${point.date}: ${point.entries} entries · ${point.concerns} concern notes`}
                        >
                            <span
                                className="block w-full bg-primary/70"
                                style={{ height: `${e}%` }}
                            />
                            <span
                                className="block w-full bg-status-warning/70"
                                style={{ height: `${c}%` }}
                            />
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function FunctionBreakdown({
    rows,
}: {
    rows: Array<{ key: string; label: string; count: number }>;
}) {
    if (!rows || rows.length === 0) {
        return (
            <p className="text-sm text-muted-foreground italic">
                No function recorded yet.
            </p>
        );
    }
    const max = Math.max(1, ...rows.map((r) => r.count));
    return (
        <div className="space-y-2">
            {rows.map((row) => (
                <div key={row.key}>
                    <div className="mb-0.5 flex items-center justify-between text-[13px]">
                        <span>{row.label}</span>
                        <span className="font-semibold text-muted-foreground">
                            {row.count}
                        </span>
                    </div>
                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            className="h-full rounded-full bg-primary"
                            style={{ width: `${(row.count / max) * 100}%` }}
                        />
                    </div>
                </div>
            ))}
        </div>
    );
}

function IntensityMix({
    mix,
}: {
    mix: { low: number; medium: number; high: number };
}) {
    const total = mix.low + mix.medium + mix.high;
    if (total === 0) {
        return (
            <p className="text-sm text-muted-foreground italic">
                No entries yet.
            </p>
        );
    }
    const seg = [
        { key: 'low', label: 'Low', value: mix.low, cls: 'bg-status-success' },
        {
            key: 'medium',
            label: 'Moderate',
            value: mix.medium,
            cls: 'bg-status-warning',
        },
        {
            key: 'high',
            label: 'High',
            value: mix.high,
            cls: 'bg-status-critical',
        },
    ];
    return (
        <div>
            <div className="flex h-3 overflow-hidden rounded-full bg-muted">
                {seg.map((s) =>
                    s.value > 0 ? (
                        <div
                            key={s.key}
                            className={cn('h-full', s.cls)}
                            style={{ width: `${(s.value / total) * 100}%` }}
                            title={`${s.label}: ${s.value}`}
                        />
                    ) : null,
                )}
            </div>
            <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-muted-foreground">
                {seg.map((s) => (
                    <span
                        key={s.key}
                        className="inline-flex items-center gap-1.5"
                    >
                        <span
                            className={cn(
                                'inline-block h-2 w-2 rounded-sm',
                                s.cls,
                            )}
                        />
                        {s.label} {s.value}
                    </span>
                ))}
            </div>
        </div>
    );
}

function ChipList({
    items,
    accent,
    empty,
}: {
    items: Array<{ label: string; count: number }>;
    accent: string;
    empty: string;
}) {
    if (!items || items.length === 0) {
        return <p className="text-sm text-muted-foreground italic">{empty}</p>;
    }
    return (
        <ul className="space-y-1.5">
            {items.map((item) => (
                <li
                    key={item.label}
                    className="flex items-center justify-between gap-2 text-sm"
                >
                    <span className="truncate">{item.label}</span>
                    <Badge variant="outline" className={cn('shrink-0', accent)}>
                        {item.count}
                    </Badge>
                </li>
            ))}
        </ul>
    );
}

export function BehaviourInsightsCard({
    patterns,
    title = 'Behaviour patterns',
    description,
}: {
    patterns?: BehaviourPattern;
    title?: string;
    description?: string;
}) {
    const data: BehaviourPattern = patterns ?? {};
    const entryCount = data.entry_count ?? 0;
    const concernCount = data.concern_note_count ?? 0;
    const windowDays = data.window_days ?? 30;
    const intensity = data.intensity_mix ?? { low: 0, medium: 0, high: 0 };

    // The headline stat strip carries the "no data" story; the deeper card
    // only appears once there is something to break down.
    if (entryCount === 0 && concernCount === 0) {
        return null;
    }

    return (
        <Card data-test="client-behaviour-insights-card">
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <TrendingUp className="h-4 w-4 text-primary" />
                    {title}
                    <Badge variant="outline" className="ml-auto">
                        Last {windowDays} days
                    </Badge>
                </CardTitle>
                {description ? (
                    <p className="text-xs text-muted-foreground">
                        {description}
                    </p>
                ) : null}
            </CardHeader>
            <CardContent className="space-y-5">
                {data.daily_series && data.daily_series.length > 0 ? (
                    <FrequencyTrend series={data.daily_series} />
                ) : null}

                <div className="grid gap-5 md:grid-cols-2">
                    <div>
                        <p className="mb-2 text-xs font-medium text-muted-foreground">
                            Function of behaviour
                        </p>
                        <FunctionBreakdown
                            rows={data.function_breakdown ?? []}
                        />
                    </div>
                    <div>
                        <p className="mb-2 text-xs font-medium text-muted-foreground">
                            Intensity
                        </p>
                        <IntensityMix mix={intensity} />
                    </div>
                </div>

                <div className="grid gap-5 md:grid-cols-3">
                    <div>
                        <p className="mb-2 flex items-center gap-1 text-xs font-medium text-muted-foreground">
                            <Sparkles className="h-3 w-3" /> Common settings
                        </p>
                        <ChipList
                            items={data.top_settings ?? []}
                            accent="text-status-info"
                            empty="No settings tracked."
                        />
                    </div>
                    <div>
                        <p className="mb-2 flex items-center gap-1 text-xs font-medium text-muted-foreground">
                            <Sparkles className="h-3 w-3" /> Behaviour types
                        </p>
                        <ChipList
                            items={data.top_behaviour_tags ?? []}
                            accent="text-status-warning"
                            empty="No types tagged."
                        />
                    </div>
                    <div>
                        <p className="mb-2 flex items-center gap-1 text-xs font-medium text-muted-foreground">
                            <Sparkles className="h-3 w-3" /> What works
                        </p>
                        <ChipList
                            items={data.top_strategies ?? []}
                            accent="text-status-success"
                            empty="No strategies logged."
                        />
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
