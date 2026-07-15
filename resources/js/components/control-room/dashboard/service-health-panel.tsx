import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import {
    Activity,
    CheckCircle2,
    Clock3,
    History,
    RefreshCw,
    TriangleAlert,
} from 'lucide-react';

export type FreshnessState = 'updated' | 'refreshing' | 'stale';
export type DeskActivity = {
    id: number;
    type: string;
    alert_id: number;
    occurred_at: string;
    actor_name: string | null;
    meta?: Record<string, unknown> | null;
};

export function FreshnessIndicator({
    state,
    updatedAt,
}: {
    state: FreshnessState;
    updatedAt: string;
}) {
    const meta = {
        updated: {
            label: `Updated ${formatRelative(updatedAt)}`,
            icon: CheckCircle2,
            className:
                'text-status-success-foreground bg-status-success/10 border-status-success/30',
        },
        refreshing: {
            label: 'Refreshing live desk…',
            icon: RefreshCw,
            className: 'text-primary bg-primary/10 border-primary/30',
        },
        stale: {
            label: `Stale · last updated ${formatRelative(updatedAt)}`,
            icon: TriangleAlert,
            className:
                'text-status-warning-foreground bg-status-warning/10 border-status-warning/40',
        },
    }[state];
    const Icon = meta.icon;

    return (
        <span
            role="status"
            title={formatDateTime(updatedAt)}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold',
                meta.className,
            )}
        >
            <Icon
                className={cn(
                    'h-3.5 w-3.5',
                    state === 'refreshing' &&
                        'animate-spin motion-reduce:animate-none',
                )}
                aria-hidden
            />
            {meta.label}
        </span>
    );
}

export function ServiceHealthPanel({
    activity,
    freshness,
    state,
}: {
    activity: DeskActivity[];
    freshness: { updated_at: string; stale_after_seconds: number };
    state: FreshnessState;
}) {
    return (
        <Card data-desk-section="service-health" className="gap-4 py-5">
            <CardHeader className="flex-row items-start justify-between gap-4 px-5">
                <div>
                    <CardTitle>Service health & recent activity</CardTitle>
                    <CardDescription className="mt-1">
                        A calm confirmation that the desk is current, plus the
                        latest operator actions.
                    </CardDescription>
                </div>
                <FreshnessIndicator
                    state={state}
                    updatedAt={freshness.updated_at}
                />
            </CardHeader>
            <CardContent className="grid gap-3 px-5 xl:grid-cols-[280px_1fr]">
                <div className="rounded-xl border bg-muted/30 p-4">
                    <div className="flex items-center gap-2 text-sm font-semibold">
                        <Activity
                            className="h-4 w-4 text-status-success-foreground"
                            aria-hidden
                        />
                        Live desk connection
                    </div>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Live operational data refreshes every 30 seconds while
                        this page is visible. Historical analytics stays
                        separate so it cannot slow response work.
                    </p>
                    <p className="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Clock3 className="h-3.5 w-3.5" aria-hidden />
                        Marked stale after {freshness.stale_after_seconds}{' '}
                        seconds
                    </p>
                </div>
                <div className="rounded-xl border">
                    {activity.length === 0 ? (
                        <div className="flex min-h-32 items-center justify-center gap-2 px-4 text-sm text-muted-foreground">
                            <History className="h-4 w-4" aria-hidden />
                            No recent operator activity in the visible alert
                            scope.
                        </div>
                    ) : (
                        <ol className="divide-y">
                            {activity.slice(0, 6).map((item) => (
                                <li
                                    key={item.id}
                                    className="flex items-center gap-3 px-4 py-3 text-sm"
                                >
                                    <span
                                        className="h-2 w-2 rounded-full bg-primary"
                                        aria-hidden
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="font-medium">
                                            {activityLabel(item.type)}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · alert #{item.alert_id}
                                        </span>
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {item.actor_name ?? 'System'} ·{' '}
                                        {formatRelative(item.occurred_at)}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function activityLabel(action: string): string {
    return action
        .replace(/^controlRoom\./, '')
        .replace(/\./g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}
