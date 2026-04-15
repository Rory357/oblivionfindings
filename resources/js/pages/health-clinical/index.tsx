import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import FleetHero from '@/components/fleet-hero';
import { Head, Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    ClipboardList,
    Clock,
    Heart,
    ShieldAlert,
    Stethoscope,
} from 'lucide-react';

type KpiData = {
    protocols_active: number;
    observations_today: number;
    observations_7d: number;
    schedules_due: number;
    schedules_overdue: number;
    events_30d: number;
    events_high_severity_30d: number;
    compliance_rate_30d: number;
};

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
    kpis: KpiData;
    overdue_items: OverdueItem[];
    recent_events: RecentEvent[];
    recent_observations: RecentObservation[];
};

function KpiCard({
    icon: Icon,
    label,
    value,
    subtext,
    variant = 'default',
}: {
    icon: typeof Heart;
    label: string;
    value: string | number;
    subtext?: string;
    variant?: 'default' | 'warning' | 'danger' | 'success';
}) {
    const colorMap = {
        default: 'from-slate-50 to-slate-100 text-slate-600',
        warning: 'from-amber-50 to-orange-50 text-amber-600',
        danger: 'from-red-50 to-rose-50 text-red-600',
        success: 'from-emerald-50 to-green-50 text-emerald-600',
    };
    const iconColorMap = {
        default: 'text-slate-500',
        warning: 'text-amber-500',
        danger: 'text-red-500',
        success: 'text-emerald-500',
    };

    return (
        <div
            className={`rounded-xl border bg-gradient-to-br p-4 ${colorMap[variant]}`}
        >
            <div className="flex items-center gap-2">
                <Icon className={`h-4 w-4 ${iconColorMap[variant]}`} />
                <p className="text-[10px] font-semibold uppercase tracking-wider opacity-70">
                    {label}
                </p>
            </div>
            <p className="mt-1 text-2xl font-bold">{value}</p>
            {subtext && <p className="mt-0.5 text-xs opacity-60">{subtext}</p>}
        </div>
    );
}

function formatTimeAgo(iso: string): string {
    const diffH = Math.floor(
        (Date.now() - new Date(iso).getTime()) / 3600000,
    );
    if (diffH < 1) return 'just now';
    if (diffH < 24) return `${diffH}h ago`;
    const days = Math.floor(diffH / 24);
    if (days === 1) return 'yesterday';
    return `${days}d ago`;
}

const severityColor: Record<string, string> = {
    critical: 'bg-red-100 text-red-700 border-red-200',
    high: 'bg-orange-100 text-orange-700 border-orange-200',
    medium: 'bg-amber-100 text-amber-700 border-amber-200',
    low: 'bg-slate-100 text-slate-600 border-slate-200',
};

export default function HealthClinicalDashboard({
    kpis,
    overdue_items,
    recent_events,
    recent_observations,
}: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Clinical', href: '/health-clinical' }]}>
            <Head title="Health & Clinical" />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <FleetHero
                    title="Health & Clinical"
                    description="Clinical observation compliance and event oversight"
                    icon={<Heart className="h-7 w-7 text-white" />}
                    stats={[
                        { label: 'Observations (7d)', value: kpis.observations_7d },
                        { label: 'Compliance', value: `${kpis.compliance_rate_30d}%` },
                        { label: 'Overdue', value: kpis.schedules_overdue },
                        { label: 'Events (30d)', value: kpis.events_30d },
                    ]}
                    actions={
                        <Link href="/health-clinical/observations">
                            <Button size="sm" className="gap-1.5">
                                <ClipboardList className="h-4 w-4" />
                                Observation Register
                            </Button>
                        </Link>
                    }
                />

                {/* KPI Cards */}
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <KpiCard
                        icon={Activity}
                        label="Observations (7d)"
                        value={kpis.observations_7d}
                        subtext={`${kpis.observations_today} today`}
                    />
                    <KpiCard
                        icon={CheckCircle2}
                        label="Compliance (30d)"
                        value={`${kpis.compliance_rate_30d}%`}
                        variant={
                            kpis.compliance_rate_30d >= 90
                                ? 'success'
                                : kpis.compliance_rate_30d >= 70
                                  ? 'warning'
                                  : 'danger'
                        }
                    />
                    <KpiCard
                        icon={Clock}
                        label="Overdue"
                        value={kpis.schedules_overdue}
                        subtext={`${kpis.schedules_due} total due`}
                        variant={
                            kpis.schedules_overdue > 0 ? 'danger' : 'default'
                        }
                    />
                    <KpiCard
                        icon={ShieldAlert}
                        label="Events (30d)"
                        value={kpis.events_30d}
                        subtext={
                            kpis.events_high_severity_30d > 0
                                ? `${kpis.events_high_severity_30d} high severity`
                                : undefined
                        }
                        variant={
                            kpis.events_high_severity_30d > 0
                                ? 'warning'
                                : 'default'
                        }
                    />
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Overdue Observations */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AlertTriangle className="h-4 w-4 text-red-500" />
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
                                    No overdue observations. All protocols on
                                    track.
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
                                                    <span className="text-xs font-medium text-red-600">
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
                                                    {' \u2014 '}
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
                                <Stethoscope className="h-4 w-4 text-amber-500" />
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
                                                        {
                                                            event.event_type_label
                                                        }
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
                                                        ? ` \u2014 ${event.reporter_name}`
                                                        : ''}
                                                </p>
                                            </div>
                                            <span className="ml-3 shrink-0 text-xs text-muted-foreground">
                                                {formatTimeAgo(
                                                    event.occurred_at,
                                                )}
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
                            <ClipboardList className="h-4 w-4 text-violet-500" />
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

                {/* Active Protocols count */}
                <p className="text-xs text-muted-foreground">
                    {kpis.protocols_active} active protocol
                    {kpis.protocols_active !== 1 ? 's' : ''} across all clients.
                </p>
            </div>
        </AppLayout>
    );
}
