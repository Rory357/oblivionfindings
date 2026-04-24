import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ClipboardList,
    Clock,
    Eye,
    HeartPulse,
} from 'lucide-react';

type ObservationStats = {
    total_observations: number;
    observations_today: number;
    by_type: Record<string, number>;
};

type EventStats = {
    total_events: number;
    events_today: number;
    pending_follow_ups: number;
    unreviewed: number;
    by_type: Record<string, number>;
    by_severity: Record<string, number>;
};

type ProtocolStats = {
    active_protocols: number;
    overdue_protocols: number;
    by_type: Record<string, number>;
};

type Observation = {
    id: number;
    observation_type: string;
    recorded_at: string;
    notes: string | null;
    client: { id: number; first_name: string; last_name: string } | null;
    recorder: { id: number; name: string } | null;
};

type ClinicalEvent = {
    id: number;
    event_type: string;
    severity: string;
    occurred_at: string;
    description: string;
    follow_up_required: boolean;
    follow_up_completed_at: string | null;
    client: { id: number; first_name: string; last_name: string } | null;
    reporter: { id: number; name: string } | null;
};

type Props = {
    observation_stats: ObservationStats;
    event_stats: EventStats;
    protocol_stats: ProtocolStats;
    recent_observations: Observation[];
    recent_events: ClinicalEvent[];
    observation_types: Record<string, string>;
    event_types: Record<string, string>;
};

const severityColor: Record<string, string> = {
    low: 'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
    medium: 'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning',
    high: 'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
};

/* ------------------------------------------------------------------ */
/*  Stat Card                                                          */
/* ------------------------------------------------------------------ */

const STAT_COLORS = {
    blue: { bg: 'bg-status-info-bg dark:bg-status-info', icon: 'text-status-info dark:text-status-info', ring: 'ring-status-info dark:ring-status-info/20' },
    emerald: { bg: 'bg-status-success-bg dark:bg-status-success', icon: 'text-status-success dark:text-status-success', ring: 'ring-status-success dark:ring-status-success/20' },
    amber: { bg: 'bg-status-warning-bg dark:bg-status-warning', icon: 'text-status-warning dark:text-status-warning', ring: 'ring-status-warning dark:ring-status-warning/20' },
    red: { bg: 'bg-status-critical-bg dark:bg-status-critical', icon: 'text-status-critical dark:text-status-critical', ring: 'ring-status-critical dark:ring-status-critical/20' },
    purple: { bg: 'bg-primary/10 dark:bg-primary/10', icon: 'text-primary dark:text-primary', ring: 'ring-ring dark:ring-ring/20' },
};

function StatCard({ label, value, subtitle, icon: Icon, color }: { label: string; value: number | string; subtitle?: string; icon: React.ElementType; color: keyof typeof STAT_COLORS }) {
    const c = STAT_COLORS[color];
    return (
        <div className={`relative flex items-center gap-4 rounded-xl p-4 ring-1 ${c.bg} ${c.ring} transition-shadow hover:shadow-md`}>
            <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-lg ${c.bg} ${c.icon}`}>
                <Icon className="h-5 w-5" />
            </div>
            <div className="min-w-0">
                <p className="text-2xl font-bold tracking-tight">{value}</p>
                <p className="truncate text-xs font-medium text-muted-foreground">{label}</p>
                {subtitle && <p className="truncate text-[10px] text-muted-foreground/70">{subtitle}</p>}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function Dashboard({
    observation_stats,
    event_stats,
    protocol_stats,
    recent_observations,
    recent_events,
    observation_types,
    event_types,
}: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Clinical', href: '/health-clinical' }, { title: 'Dashboard', href: '/health-clinical' }]}>
            <Head title="Health & Clinical" />

            <div className="flex flex-col gap-6 p-6">
                {/* Header */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Health & Clinical</h1>
                        <p className="text-sm text-muted-foreground">
                            Clinical observation compliance and event oversight
                        </p>
                    </div>
                </div>

                {/* Quick Nav */}
                <div className="flex flex-wrap gap-2">
                    <Link href="/health-clinical/observations">
                        <Button variant="outline" size="sm" className="gap-1.5">
                            <Eye className="h-4 w-4" /> Observation Register
                        </Button>
                    </Link>
                    <Link href="/health-clinical/events">
                        <Button variant="outline" size="sm" className="gap-1.5">
                            <AlertTriangle className="h-4 w-4" /> Event Register
                        </Button>
                    </Link>
                    <Link href="/health-clinical/protocols">
                        <Button variant="outline" size="sm" className="gap-1.5">
                            <ClipboardList className="h-4 w-4" /> Protocols
                        </Button>
                    </Link>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-2 gap-4 lg:grid-cols-5">
                    <StatCard label="Observations (30d)" value={observation_stats.total_observations} subtitle={`${observation_stats.observations_today} today`} icon={Eye} color="blue" />
                    <StatCard label="Clinical Events (30d)" value={event_stats.total_events} subtitle={`${event_stats.events_today} today`} icon={Activity} color="purple" />
                    <StatCard label="Active Protocols" value={protocol_stats.active_protocols} icon={ClipboardList} color="emerald" />
                    <StatCard label="Overdue Protocols" value={protocol_stats.overdue_protocols} icon={Clock} color="amber" />
                    <StatCard label="Pending Follow-ups" value={event_stats.pending_follow_ups} icon={AlertTriangle} color="red" />
                </div>

                {/* Recent Activity */}
                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Recent Observations */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <HeartPulse className="h-4 w-4" />
                                Recent Observations
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {recent_observations.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No observations recorded yet.
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    {recent_observations.map((obs) => (
                                        <div
                                            key={obs.id}
                                            className="flex items-start justify-between rounded-lg border p-3"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <Badge variant="secondary" className="text-xs">
                                                        {observation_types[obs.observation_type] ?? obs.observation_type}
                                                    </Badge>
                                                    <span className="text-xs text-muted-foreground">
                                                        {new Date(obs.recorded_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}
                                                    </span>
                                                </div>
                                                <p className="mt-1 text-sm font-medium">
                                                    {obs.client ? `${obs.client.first_name} ${obs.client.last_name}` : 'Unknown'}
                                                </p>
                                                {obs.notes && (
                                                    <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                                                        {obs.notes}
                                                    </p>
                                                )}
                                            </div>
                                            <span className="text-xs text-muted-foreground">
                                                {obs.recorder?.name}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Recent Events */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Activity className="h-4 w-4" />
                                Recent Clinical Events
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {recent_events.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No clinical events recorded yet.
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    {recent_events.map((evt) => (
                                        <div
                                            key={evt.id}
                                            className="flex items-start justify-between rounded-lg border p-3"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-2">
                                                    <Badge className={`text-xs ${severityColor[evt.severity] ?? ''}`}>
                                                        {evt.severity}
                                                    </Badge>
                                                    <Badge variant="outline" className="text-xs">
                                                        {event_types[evt.event_type] ?? evt.event_type}
                                                    </Badge>
                                                    {evt.follow_up_required && !evt.follow_up_completed_at && (
                                                        <Badge variant="destructive" className="text-xs">
                                                            Follow-up needed
                                                        </Badge>
                                                    )}
                                                </div>
                                                <p className="mt-1 text-sm font-medium">
                                                    {evt.client ? `${evt.client.first_name} ${evt.client.last_name}` : 'Unknown'}
                                                </p>
                                                <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                                                    {evt.description}
                                                </p>
                                            </div>
                                            <span className="shrink-0 text-xs text-muted-foreground">
                                                {new Date(evt.occurred_at).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
