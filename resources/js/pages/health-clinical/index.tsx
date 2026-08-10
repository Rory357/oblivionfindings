import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import {
    HealthClinicalShell,
    type HealthClinicalKpis,
} from '@/pages/health-clinical/components/health-clinical-shell';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronRight,
    ClipboardList,
    HeartPulse,
    Stethoscope,
} from 'lucide-react';

type OverdueItem = {
    id: number;
    protocol_name: string;
    observation_type: string;
    observation_type_label: string;
    client_name: string;
    client_id: number;
    due_at: string;
    hours_overdue: number;
};

type RecentEvent = {
    id: number;
    event_type: string;
    event_type_label: string;
    severity: string;
    client_name: string;
    client_id: number;
    occurred_at: string;
    status: string;
    reporter_name: string | null;
};

type RecentObservation = {
    id: number;
    observation_type: string;
    observation_type_label: string;
    client_name: string;
    recorder_name: string | null;
    recorded_at: string;
};

type WatchItem = {
    client_id: number;
    client_name: string;
    site: string | null;
    news2_score: number;
    news2_band: string;
    band_label: string;
    recorded_at: string;
    sparkline: number[];
};

type Props = {
    kpis: HealthClinicalKpis & { protocols_active: number };
    tab_counts?: Record<string, number>;
    deterioration_watch: WatchItem[];
    overdue_items: OverdueItem[];
    recent_events: RecentEvent[];
    recent_observations: RecentObservation[];
};

const BAND_TONE: Record<string, { pill: string; bar: string }> = {
    low: {
        pill: 'bg-status-success-bg text-status-success',
        bar: 'bg-status-success',
    },
    low_medium: { pill: 'bg-primary/10 text-primary', bar: 'bg-primary' },
    medium: {
        pill: 'bg-status-warning-bg text-status-warning',
        bar: 'bg-status-warning',
    },
    high: {
        pill: 'bg-status-critical-bg text-status-critical',
        bar: 'bg-status-critical',
    },
};

function initials(name: string): string {
    return name
        .split(' ')
        .map((n) => n[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

function DeteriorationWatchCard({ items }: { items: WatchItem[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <HeartPulse className="h-4 w-4 text-primary" />
                    Deterioration watch · NEWS2
                    {items.length > 0 ? (
                        <Badge
                            variant="outline"
                            className="ml-auto text-xs text-status-warning"
                        >
                            {items.length} on watch
                        </Badge>
                    ) : null}
                </CardTitle>
            </CardHeader>
            <CardContent>
                {items.length === 0 ? (
                    <p className="py-4 text-center text-sm text-muted-foreground">
                        All clients stable — no NEWS2 escalations in the last 7
                        days.
                    </p>
                ) : (
                    <div className="divide-y">
                        {items.map((item) => {
                            const tone =
                                BAND_TONE[item.news2_band] ?? BAND_TONE.low;
                            const peak = Math.max(...item.sparkline, 6);
                            return (
                                <Link
                                    key={item.client_id}
                                    href={`/operations/clients/${item.client_id}`}
                                    className="flex items-center gap-3 py-2.5 transition-colors hover:bg-muted/30"
                                >
                                    <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                                        {initials(item.client_name)}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {item.client_name}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {item.site ?? 'No site'}
                                        </p>
                                    </div>
                                    <div
                                        className="flex h-7 items-end gap-0.5"
                                        aria-hidden="true"
                                    >
                                        {item.sparkline.map((s, i) => (
                                            <div
                                                key={i}
                                                className={cn(
                                                    'w-1.5 rounded-sm',
                                                    tone.bar,
                                                )}
                                                style={{
                                                    height: `${Math.max(12, (s / peak) * 100)}%`,
                                                }}
                                            />
                                        ))}
                                    </div>
                                    <div className="flex w-[120px] items-center justify-end gap-2">
                                        <span
                                            className={cn(
                                                'rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                                tone.pill,
                                            )}
                                        >
                                            {item.band_label}
                                        </span>
                                        <span className="text-lg font-bold tabular-nums">
                                            {item.news2_score}
                                        </span>
                                    </div>
                                    <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                                </Link>
                            );
                        })}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function formatTimeAgo(iso: string): string {
    const diffH = Math.floor((Date.now() - new Date(iso).getTime()) / 3600000);
    if (diffH < 1) return 'just now';
    if (diffH < 24) return `${diffH}h ago`;
    const days = Math.floor(diffH / 24);
    if (days === 1) return 'yesterday';
    return `${days}d ago`;
}

const severityColor: Record<string, string> = {
    critical:
        'bg-status-critical-bg text-status-critical border-status-critical/30',
    high: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    medium: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    low: 'bg-muted text-muted-foreground border-border',
};

export default function HealthClinicalOverview({
    kpis,
    tab_counts,
    deterioration_watch,
    overdue_items,
    recent_events,
    recent_observations,
}: Props) {
    return (
        <HealthClinicalShell
            activeTab="overview"
            kpis={kpis}
            tabCounts={tab_counts}
        >
            <DeteriorationWatchCard items={deterioration_watch} />

            <div className="grid gap-6 lg:grid-cols-2">
                {/* Overdue Observations */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <AlertTriangle className="h-4 w-4 text-status-critical" />
                            Overdue Observations
                            {overdue_items.length > 0 && (
                                <Badge
                                    variant="destructive"
                                    className="ml-auto text-xs"
                                >
                                    {overdue_items.length}
                                </Badge>
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {overdue_items.length === 0 ? (
                            <p className="py-4 text-center text-sm text-muted-foreground">
                                No overdue observations. All protocols on track.
                            </p>
                        ) : (
                            <div className="divide-y">
                                {overdue_items.map((item) => (
                                    <div
                                        key={item.id}
                                        className="flex items-center justify-between py-2"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <Badge
                                                    variant="outline"
                                                    className="text-[10px]"
                                                >
                                                    {
                                                        item.observation_type_label
                                                    }
                                                </Badge>
                                                <span className="text-xs font-medium text-status-critical">
                                                    {item.hours_overdue}h
                                                    overdue
                                                </span>
                                            </div>
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                <Link
                                                    href={`/operations/clients/${item.client_id}?tab=observations`}
                                                    className="hover:underline"
                                                >
                                                    {item.client_name}
                                                </Link>
                                                {' — '}
                                                {item.protocol_name}
                                            </p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Recent Clinical Events */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Stethoscope className="h-4 w-4 text-status-warning" />
                            Recent Clinical Events
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {recent_events.length === 0 ? (
                            <p className="py-4 text-center text-sm text-muted-foreground">
                                No clinical events in the last 30 days.
                            </p>
                        ) : (
                            <div className="divide-y">
                                {recent_events.map((event) => (
                                    <div
                                        key={event.id}
                                        className="flex items-center justify-between py-2"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium">
                                                    {event.event_type_label}
                                                </span>
                                                <Badge
                                                    variant="outline"
                                                    className={`text-[10px] ${severityColor[event.severity] ?? ''}`}
                                                >
                                                    {event.severity}
                                                </Badge>
                                                <Badge
                                                    variant="outline"
                                                    className="text-[10px]"
                                                >
                                                    {event.status}
                                                </Badge>
                                            </div>
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                <Link
                                                    href={`/operations/clients/${event.client_id}?tab=observations`}
                                                    className="hover:underline"
                                                >
                                                    {event.client_name}
                                                </Link>
                                                {event.reporter_name
                                                    ? ` — ${event.reporter_name}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <span className="ml-3 shrink-0 text-xs text-muted-foreground">
                                            {formatTimeAgo(event.occurred_at)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Recent Observations */}
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <ClipboardList className="h-4 w-4 text-primary" />
                        Recent Observations
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    {recent_observations.length === 0 ? (
                        <p className="py-4 text-center text-sm text-muted-foreground">
                            No observations recorded yet.
                        </p>
                    ) : (
                        <div className="divide-y">
                            {recent_observations.map((obs) => (
                                <div
                                    key={obs.id}
                                    className="flex items-center justify-between py-2"
                                >
                                    <div className="flex items-center gap-3">
                                        <Badge
                                            variant="outline"
                                            className="text-[10px]"
                                        >
                                            {obs.observation_type_label}
                                        </Badge>
                                        <span className="text-sm">
                                            {obs.client_name}
                                        </span>
                                    </div>
                                    <div className="text-right">
                                        <span className="text-xs text-muted-foreground">
                                            {formatTimeAgo(obs.recorded_at)}
                                        </span>
                                        {obs.recorder_name && (
                                            <p className="text-[10px] text-muted-foreground">
                                                {obs.recorder_name}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            <p className="text-xs text-muted-foreground">
                {kpis.protocols_active} active protocol
                {kpis.protocols_active !== 1 ? 's' : ''} across all clients.
            </p>
        </HealthClinicalShell>
    );
}
