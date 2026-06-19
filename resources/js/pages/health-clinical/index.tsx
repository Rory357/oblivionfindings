import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    HealthClinicalShell,
    type HealthClinicalKpis,
} from '@/pages/health-clinical/components/health-clinical-shell';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ClipboardList,
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

type Props = {
    kpis: HealthClinicalKpis & { protocols_active: number };
    tab_counts?: Record<string, number>;
    overdue_items: OverdueItem[];
    recent_events: RecentEvent[];
    recent_observations: RecentObservation[];
};

function formatTimeAgo(iso: string): string {
    const diffH = Math.floor((Date.now() - new Date(iso).getTime()) / 3600000);
    if (diffH < 1) return 'just now';
    if (diffH < 24) return `${diffH}h ago`;
    const days = Math.floor(diffH / 24);
    if (days === 1) return 'yesterday';
    return `${days}d ago`;
}

const severityColor: Record<string, string> = {
    critical: 'bg-status-critical-bg text-status-critical border-status-critical/30',
    high: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    medium: 'bg-status-warning-bg text-status-warning border-status-warning/30',
    low: 'bg-muted text-muted-foreground border-border',
};

export default function HealthClinicalOverview({
    kpis,
    tab_counts,
    overdue_items,
    recent_events,
    recent_observations,
}: Props) {
    return (
        <HealthClinicalShell activeTab="overview" kpis={kpis} tabCounts={tab_counts}>
            <div className="grid gap-6 lg:grid-cols-2">
                {/* Overdue Observations */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <AlertTriangle className="h-4 w-4 text-status-critical" />
                            Overdue Observations
                            {overdue_items.length > 0 && (
                                <Badge variant="destructive" className="ml-auto text-xs">
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
                                    <div key={item.id} className="flex items-center justify-between py-2">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <Badge variant="outline" className="text-[10px]">
                                                    {item.observation_type_label}
                                                </Badge>
                                                <span className="text-xs font-medium text-status-critical">
                                                    {item.hours_overdue}h overdue
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
                                    <div key={event.id} className="flex items-center justify-between py-2">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium">{event.event_type_label}</span>
                                                <Badge
                                                    variant="outline"
                                                    className={`text-[10px] ${severityColor[event.severity] ?? ''}`}
                                                >
                                                    {event.severity}
                                                </Badge>
                                                <Badge variant="outline" className="text-[10px]">
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
                                                {event.reporter_name ? ` — ${event.reporter_name}` : ''}
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
                                <div key={obs.id} className="flex items-center justify-between py-2">
                                    <div className="flex items-center gap-3">
                                        <Badge variant="outline" className="text-[10px]">
                                            {obs.observation_type_label}
                                        </Badge>
                                        <span className="text-sm">{obs.client_name}</span>
                                    </div>
                                    <div className="text-right">
                                        <span className="text-xs text-muted-foreground">
                                            {formatTimeAgo(obs.recorded_at)}
                                        </span>
                                        {obs.recorder_name && (
                                            <p className="text-[10px] text-muted-foreground">{obs.recorder_name}</p>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            <p className="text-xs text-muted-foreground">
                {kpis.protocols_active} active protocol{kpis.protocols_active !== 1 ? 's' : ''} across all clients.
            </p>
        </HealthClinicalShell>
    );
}
