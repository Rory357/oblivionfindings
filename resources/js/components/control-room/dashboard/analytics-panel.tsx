import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { BarChart3, Gauge, Siren, TrendingUp } from 'lucide-react';

export type DeskAnalytics = {
    period: string;
    volume: {
        total?: number;
        resolved?: number;
        resolution_rate?: number;
        daily_trend?: Array<{ date: string; count: number }>;
        [key: string]: unknown;
    };
    sla: {
        compliance_pct?: number | null;
        avg_response_minutes?: number | null;
        [key: string]: unknown;
    };
    escalation: {
        escalation_rate?: number;
        stuck_at_high_escalation?: number;
        [key: string]: unknown;
    };
    sla_daily_trend?: Array<{ date: string; compliance_pct: number | null }>;
    sites: Array<Record<string, unknown>>;
    cached_for_seconds?: number;
};

export function AnalyticsPanel({ analytics }: { analytics: DeskAnalytics }) {
    const trend = analytics.volume.daily_trend ?? [];
    const maximum = Math.max(1, ...trend.map((day) => Number(day.count)));

    return (
        <Card className="gap-4 py-5">
            <CardHeader className="gap-1 px-5">
                <CardTitle>
                    <h2>Historical performance</h2>
                </CardTitle>
                <CardDescription>
                    Trend and service performance for{' '}
                    {periodLabel(analytics.period)}. This panel is cached and
                    kept out of the live polling path.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4 px-5">
                <div className="grid gap-3 xl:grid-cols-4">
                    <Metric
                        icon={BarChart3}
                        label="Alerts received"
                        value={number(analytics.volume.total)}
                        help="in selected period"
                    />
                    <Metric
                        icon={TrendingUp}
                        label="Resolution rate"
                        value={percent(analytics.volume.resolution_rate)}
                        help={`${number(analytics.volume.resolved)} resolved`}
                    />
                    <Metric
                        icon={Gauge}
                        label="SLA compliance"
                        value={percent(analytics.sla.compliance_pct)}
                        help={
                            analytics.sla.avg_response_minutes == null
                                ? 'response average unavailable'
                                : `${analytics.sla.avg_response_minutes} min response average`
                        }
                    />
                    <Metric
                        icon={Siren}
                        label="Escalation rate"
                        value={percent(analytics.escalation.escalation_rate)}
                        help={`${number(analytics.escalation.stuck_at_high_escalation)} stuck at high escalation`}
                    />
                </div>

                <div className="rounded-xl border p-4">
                    <p className="text-sm font-semibold">Alert volume trend</p>
                    {trend.length === 0 ? (
                        <p className="mt-3 text-sm text-muted-foreground">
                            No alert volume was recorded in this period.
                        </p>
                    ) : (
                        <div
                            className="mt-4 flex h-36 items-end gap-2"
                            aria-label="Alert volume by day"
                        >
                            {trend.map((day) => (
                                <div
                                    key={day.date}
                                    className="flex min-w-0 flex-1 flex-col items-center gap-1.5"
                                >
                                    <span className="text-[10px] font-semibold tabular-nums">
                                        {day.count}
                                    </span>
                                    <span
                                        className="w-full min-w-2 rounded-t bg-primary/75"
                                        style={{
                                            height: `${Math.max(6, (Number(day.count) / maximum) * 100)}px`,
                                        }}
                                        title={`${day.date}: ${day.count} alerts`}
                                    />
                                    <span className="max-w-full truncate text-[10px] text-muted-foreground">
                                        {day.date.slice(5)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function Metric({
    icon: Icon,
    label,
    value,
    help,
}: {
    icon: typeof BarChart3;
    label: string;
    value: string;
    help: string;
}) {
    return (
        <div className="rounded-xl border bg-muted/20 p-4">
            <div className="flex items-center gap-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                <Icon className="h-4 w-4" aria-hidden />
                {label}
            </div>
            <p className="mt-2 text-2xl font-bold tabular-nums">{value}</p>
            <p className="mt-1 text-xs text-muted-foreground">{help}</p>
        </div>
    );
}

function number(value: unknown): string {
    return typeof value === 'number' ? value.toLocaleString('en-NZ') : '—';
}

function percent(value: unknown): string {
    return typeof value === 'number' ? `${value}%` : '—';
}

function periodLabel(period: string): string {
    return (
        (
            {
                '24h': 'the last 24 hours',
                '7d': 'the last 7 days',
                '30d': 'the last 30 days',
                '90d': 'the last 90 days',
            } as Record<string, string>
        )[period] ?? period
    );
}
