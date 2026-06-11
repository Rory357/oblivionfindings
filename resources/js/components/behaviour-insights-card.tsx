/* Behaviour-pattern (PBS) analytics for the client profile Behaviour / ABC tab.
 * Reads the `behaviour_patterns` prop (App\Services\Client\BehaviourPatternsService),
 * which aggregates structured behaviour_abc_entries: a frequency trend, the
 * function-of-behaviour breakdown, intensity mix, and the most common settings,
 * behaviour types and effective strategies. Theme-token div charts (no recharts)
 * so colours follow the brand and the card stays light. */
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import {
    Activity,
    AlertTriangle,
    HeartCrack,
    Sparkles,
    TrendingUp,
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
};

type Props = {
    patterns?: BehaviourPattern;
    title?: string;
    description?: string;
};

function StatTile({
    icon: Icon,
    label,
    value,
    tone,
}: {
    icon: typeof Activity;
    label: string;
    value: number;
    tone: string;
}) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- compact KPI tile nested inside the analytics Card.
        <div className="rounded-lg border bg-card p-3">
            <div className="flex items-center gap-2">
                <Icon className={cn('h-4 w-4', tone)} />
                <span className="text-xs text-muted-foreground">{label}</span>
            </div>
            <p className="mt-1 text-2xl font-semibold">{value}</p>
        </div>
    );
}

/** 30-day frequency trend — stacked ABC entries + concern notes per day. */
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
                            <span className="block w-full bg-primary/70" style={{ height: `${e}%` }} />
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

/** Horizontal token bars for the function-of-behaviour breakdown. */
function FunctionBreakdown({
    rows,
}: {
    rows: Array<{ key: string; label: string; count: number }>;
}) {
    if (!rows || rows.length === 0) {
        return (
            <p className="text-sm italic text-muted-foreground">
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

/** Low / moderate / high intensity as one stacked bar. */
function IntensityMix({
    mix,
}: {
    mix: { low: number; medium: number; high: number };
}) {
    const total = mix.low + mix.medium + mix.high;
    if (total === 0) {
        return (
            <p className="text-sm italic text-muted-foreground">
                No entries yet.
            </p>
        );
    }
    const seg = [
        { key: 'low', label: 'Low', value: mix.low, cls: 'bg-status-success' },
        { key: 'medium', label: 'Moderate', value: mix.medium, cls: 'bg-status-warning' },
        { key: 'high', label: 'High', value: mix.high, cls: 'bg-status-critical' },
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
                    <span key={s.key} className="inline-flex items-center gap-1.5">
                        <span className={cn('inline-block h-2 w-2 rounded-sm', s.cls)} />
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
        return <p className="text-sm italic text-muted-foreground">{empty}</p>;
    }
    return (
        <ul className="space-y-1.5">
            {items.map((item) => (
                <li key={item.label} className="flex items-center justify-between gap-2 text-sm">
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
}: Props) {
    const data: BehaviourPattern = patterns ?? {};
    const entryCount = data.entry_count ?? 0;
    const concernCount = data.concern_note_count ?? 0;
    const windowDays = data.window_days ?? 30;
    const intensity = data.intensity_mix ?? { low: 0, medium: 0, high: 0 };

    const hasData = entryCount > 0 || concernCount > 0;

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
                    <p className="text-xs text-muted-foreground">{description}</p>
                ) : null}
            </CardHeader>
            <CardContent className="space-y-5">
                {!hasData ? (
                    <div className="flex flex-col items-center justify-center gap-2 py-8 text-center">
                        <span className="grid h-11 w-11 place-items-center rounded-xl bg-accent text-primary">
                            <Sparkles className="h-5 w-5" />
                        </span>
                        <p className="text-sm font-medium">No behaviour patterns yet</p>
                        <p className="max-w-sm text-xs text-muted-foreground">
                            Log ABC entries below and trends — function, intensity,
                            triggers and what works — will build here automatically.
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <StatTile icon={Activity} label="ABC entries" value={entryCount} tone="text-primary" />
                            <StatTile
                                icon={AlertTriangle}
                                label="Escalated"
                                value={data.escalated_count ?? 0}
                                tone="text-status-warning"
                            />
                            <StatTile
                                icon={HeartCrack}
                                label="With harm"
                                value={data.with_harm_count ?? 0}
                                tone="text-status-critical"
                            />
                            <StatTile
                                icon={AlertTriangle}
                                label="Concern notes"
                                value={concernCount}
                                tone="text-muted-foreground"
                            />
                        </div>

                        {data.daily_series && data.daily_series.length > 0 ? (
                            <FrequencyTrend series={data.daily_series} />
                        ) : null}

                        <div className="grid gap-5 md:grid-cols-2">
                            <div>
                                <p className="mb-2 text-xs font-medium text-muted-foreground">
                                    Function of behaviour
                                </p>
                                <FunctionBreakdown rows={data.function_breakdown ?? []} />
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
                    </>
                )}
            </CardContent>
        </Card>
    );
}
