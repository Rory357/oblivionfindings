import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { Activity, AlertTriangle, Sparkles, TrendingUp } from 'lucide-react';

export type BehaviourPattern = {
    window_days?: number;
    observation_count?: number;
    concern_note_count?: number;
    top_triggers?: Array<{ label: string; count: number }>;
    top_antecedents?: Array<{ label: string; count: number }>;
    top_responses?: Array<{ label: string; count: number }>;
    top_behaviour_tags?: Array<{ label: string; count: number }>;
    daily_series?: Array<{
        date: string;
        observations: number;
        concerns: number;
    }>;
};

type Props = {
    patterns?: BehaviourPattern;
    title?: string;
    description?: string;
};

function ChipList({
    items,
    accent,
}: {
    items: Array<{ label: string; count: number }>;
    accent: string;
}) {
    if (!items || items.length === 0) {
        return (
            <p className="text-sm italic text-muted-foreground">
                Nothing tracked yet.
            </p>
        );
    }
    return (
        <ul className="space-y-1.5">
            {items.map((item) => (
                <li
                    key={item.label}
                    className="flex items-center justify-between text-sm"
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

function Sparkline({
    series,
}: {
    series: Array<{ date: string; observations: number; concerns: number }>;
}) {
    if (!series || series.length === 0) return null;

    const peak = Math.max(
        1,
        ...series.map((s) => Math.max(s.observations, s.concerns)),
    );

    return (
        <div className="mt-2 flex h-16 items-end gap-px">
            {series.map((point) => {
                const obsHeight = (point.observations / peak) * 100;
                const concernHeight = (point.concerns / peak) * 100;
                return (
                    <div
                        key={point.date}
                        className="flex flex-1 flex-col-reverse gap-px"
                        title={`${point.date}: ${point.observations} obs · ${point.concerns} concerns`}
                    >
                        <span
                            className="block w-full bg-primary/60"
                            style={{ height: `${obsHeight}%` }}
                        />
                        <span
                            className="block w-full bg-status-warning/70"
                            style={{ height: `${concernHeight}%` }}
                        />
                    </div>
                );
            })}
        </div>
    );
}

export function BehaviourInsightsCard({
    patterns,
    title = 'Behaviour patterns',
    description,
}: Props) {
    const data: BehaviourPattern = patterns ?? {};
    const observationCount = data.observation_count ?? 0;
    const concernCount = data.concern_note_count ?? 0;
    const windowDays = data.window_days ?? 30;

    if (observationCount === 0 && concernCount === 0) {
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
            <CardContent className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    {/* eslint-disable-next-line no-restricted-syntax -- compact KPI tiles nested inside Card. */}
                    <div className="rounded-lg border bg-card p-3">
                        <div className="flex items-center gap-2">
                            <Activity className="h-4 w-4 text-primary" />
                            <span className="text-xs text-muted-foreground">
                                Observations
                            </span>
                        </div>
                        <p className="mt-1 text-2xl font-semibold">
                            {observationCount}
                        </p>
                    </div>
                    {/* eslint-disable-next-line no-restricted-syntax -- compact KPI tiles nested inside Card. */}
                    <div className="rounded-lg border bg-card p-3">
                        <div className="flex items-center gap-2">
                            <AlertTriangle className="h-4 w-4 text-status-warning" />
                            <span className="text-xs text-muted-foreground">
                                Concern notes
                            </span>
                        </div>
                        <p className="mt-1 text-2xl font-semibold">
                            {concernCount}
                        </p>
                    </div>
                </div>

                {data.daily_series && data.daily_series.length > 0 ? (
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Daily activity
                            <span className="ml-2 inline-flex items-center gap-2">
                                <span className="inline-block h-2 w-2 rounded-sm bg-primary/60" />
                                obs
                                <span className="inline-block h-2 w-2 rounded-sm bg-status-warning/70" />
                                concerns
                            </span>
                        </p>
                        <Sparkline series={data.daily_series} />
                    </div>
                ) : null}

                <div className="grid gap-4 md:grid-cols-3">
                    <div>
                        <p className="mb-2 flex items-center gap-1 text-xs font-medium text-muted-foreground">
                            <Sparkles className="h-3 w-3" /> Top triggers
                        </p>
                        <ChipList
                            items={data.top_triggers ?? []}
                            accent="text-status-warning"
                        />
                    </div>
                    <div>
                        <p className="mb-2 flex items-center gap-1 text-xs font-medium text-muted-foreground">
                            <Sparkles className="h-3 w-3" /> Top antecedents
                        </p>
                        <ChipList
                            items={data.top_antecedents ?? []}
                            accent="text-status-info"
                        />
                    </div>
                    <div>
                        <p className="mb-2 flex items-center gap-1 text-xs font-medium text-muted-foreground">
                            <Sparkles className="h-3 w-3" /> Effective responses
                        </p>
                        <ChipList
                            items={data.top_responses ?? []}
                            accent="text-status-success"
                        />
                    </div>
                </div>

                {data.top_behaviour_tags
                && data.top_behaviour_tags.length > 0 ? (
                    <div>
                        <p className="mb-2 text-xs font-medium text-muted-foreground">
                            Recurring behaviour tags
                        </p>
                        <div className="flex flex-wrap gap-1.5">
                            {data.top_behaviour_tags.map((tag) => (
                                <Badge key={tag.label} variant="outline">
                                    {tag.label} ({tag.count})
                                </Badge>
                            ))}
                        </div>
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}
