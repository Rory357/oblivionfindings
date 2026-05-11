import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Car,
    Clock,
    ExternalLink,
    Gauge,
    Route,
    Shield,
    User,
} from 'lucide-react';
import { formatDate, formatDateTime } from '@/lib/fleet-utils';


type Props = {
    driver: {
        id: number;
        name: string;
        email: string | null;
        eligibility: {
            licence_class?: string | null;
            licence_number?: string | null;
            licence_expires_at?: string | null;
            status?: string | null;
            can_drive_clients?: boolean;
        } | null;
        hr_status?: string | null;
    };
    assigned_vehicles: Array<{
        id: number;
        name: string;
        asset_tag: string;
        status: string;
    }>;
    sessions: Array<{
        id: number;
        asset: { id: number; name: string } | null;
        started_at: string | null;
        ended_at: string | null;
        status: string;
    }>;
    driving_metrics: Array<{
        id: number;
        period_start: string | null;
        period_end: string | null;
        harsh_brake_count: number;
        accel_count: number;
        speeding_events: number;
        idle_minutes: number;
        score: number;
    }>;
    recent_trips: Array<{
        id: number;
        asset: { id: number; name: string } | null;
        started_at: string | null;
        ended_at: string | null;
        distance_km: number;
        status: string;
    }>;
};

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'eligible': return 'default';
        case 'suspended': return 'destructive';
        case 'expired': return 'destructive';
        default: return 'secondary';
    }
}

function computeDurationMinutes(startedAt: string | null, endedAt: string | null): number | null {
    if (!startedAt || !endedAt) return null;
    const diffMs = new Date(endedAt).getTime() - new Date(startedAt).getTime();
    return Math.round(diffMs / 60000);
}

function getLicenceExpiryDays(dateStr: string | null | undefined): number | null {
    if (!dateStr) return null;
    const diff = (new Date(dateStr).getTime() - new Date().getTime()) / (1000 * 60 * 60 * 24);
    return Math.ceil(diff);
}

const statusBannerColors: Record<string, string> = {
    eligible: 'bg-primary/10 border-primary text-primary dark:bg-primary/30 dark:border-primary/30 dark:text-primary/70',
    suspended: 'bg-status-critical-bg border-status-critical/30 text-status-critical dark:bg-status-critical-bg dark:border-status-critical/30 dark:text-status-critical',
    expired: 'bg-status-critical-bg border-status-critical/30 text-status-critical dark:bg-status-critical-bg dark:border-status-critical/30 dark:text-status-critical',
    unknown: 'bg-muted border-border text-foreground dark:bg-muted/30 dark:border-border dark:text-foreground',
};

export default function DriverShow({ driver, assigned_vehicles, sessions, driving_metrics, recent_trips }: Props) {
    const eligibility = driver?.eligibility ?? {};
    const driverStatus = eligibility.status ?? 'unknown';
    const safeAssignedVehicles = assigned_vehicles ?? [];
    const safeSessions = sessions ?? [];
    const safeDrivingMetrics = driving_metrics ?? [];
    const safeRecentTrips = recent_trips ?? [];

    const expiryDays = getLicenceExpiryDays(eligibility.licence_expires_at);
    const isExpired = expiryDays !== null && expiryDays < 0;
    const isExpiringSoon = expiryDays !== null && expiryDays >= 0 && expiryDays <= 60;

    // Aggregate metrics from the array
    const aggregatedMetrics = {
        score: safeDrivingMetrics.length > 0 ? safeDrivingMetrics[0]?.score ?? 0 : 0,
        harsh_brakes: safeDrivingMetrics.reduce((sum, m) => sum + (m.harsh_brake_count ?? 0), 0),
        speeding_events: safeDrivingMetrics.reduce((sum, m) => sum + (m.speeding_events ?? 0), 0),
        idle_minutes: safeDrivingMetrics.reduce((sum, m) => sum + (m.idle_minutes ?? 0), 0),
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Drivers', href: '/fleet-assets/drivers' },
                { title: driver?.name ?? 'Driver', href: '#' },
            ]}
        >
            <Head title={`Driver: ${driver?.name ?? 'Driver'}`} />
            <PageShell>
                {/* Header Banner */}
                <div className={cn('rounded-lg border px-5 py-4', statusBannerColors[driverStatus] ?? statusBannerColors.unknown)}>
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="flex items-center gap-4">
                            <div className="flex h-14 w-14 items-center justify-center rounded-full bg-white/80 dark:bg-black/20">
                                <User className="h-7 w-7" />
                            </div>
                            <div>
                                <h1 className="text-xl font-bold">{driver?.name ?? 'Driver'}</h1>
                                <div className="mt-1 flex items-center gap-2">
                                    <Badge variant={statusVariant(driverStatus)} className="text-xs">{driverStatus}</Badge>
                                    {driver?.hr_status && (
                                        <Badge variant={driver.hr_status === 'active' ? 'default' : 'secondary'} className="text-xs">
                                            {driver.hr_status}
                                        </Badge>
                                    )}
                                    {driver?.email && (
                                        <span className="text-xs opacity-70">{driver.email}</span>
                                    )}
                                </div>
                            </div>
                        </div>
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" asChild className="bg-white/50 dark:bg-black/20">
                                <Link href={`/fleet-assets/drivers/${driver?.id}/scorecard`}>
                                    <Gauge className="mr-2 h-4 w-4" />
                                    Scorecard
                                </Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild className="bg-white/50 dark:bg-black/20">
                                <Link href={`/hr/staff/${driver?.id}`}>
                                    <ExternalLink className="mr-2 h-4 w-4" />
                                    HR Profile
                                </Link>
                            </Button>
                        </div>
                    </div>
                </div>

                {/* License expiry warning banner */}
                {(isExpired || isExpiringSoon) && (
                    <div
                        className={cn(
                            'rounded-lg border p-4',
                            isExpired
                                ? 'border-status-critical/30 bg-status-critical-bg'
                                : 'border-status-warning/30 bg-status-warning-bg',
                        )}
                    >
                        <div className="flex items-center gap-3">
                            <AlertTriangle className={cn('h-5 w-5', isExpired ? 'text-status-critical' : 'text-status-warning')} />
                            <div>
                                <p className={cn('text-sm font-medium', isExpired ? 'text-status-critical dark:text-status-critical' : 'text-status-warning dark:text-status-warning')}>
                                    {isExpired
                                        ? `Driver licence expired ${Math.abs(expiryDays!)} days ago`
                                        : `Driver licence expires in ${expiryDays} days`
                                    }
                                </p>
                                <p className="text-xs text-muted-foreground mt-0.5">
                                    {isExpired
                                        ? 'This driver should not be assigned to any vehicles until their licence is renewed.'
                                        : 'Please ensure the driver renews their licence before the expiry date.'
                                    }
                                </p>
                            </div>
                        </div>
                    </div>
                )}

                {/* 2-Column Layout: License & Metrics */}
                <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                    <div className="space-y-4">
                        {/* License Details */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Shield className="h-4 w-4" />
                                    Licence Details
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="grid gap-3 text-sm sm:grid-cols-2">
                                    <div className="rounded-md bg-muted/40 p-3">
                                        <dt className="text-xs text-muted-foreground">Licence Class</dt>
                                        <dd className="mt-1 text-lg font-bold">{eligibility.licence_class ?? '---'}</dd>
                                    </div>
                                    <div className="rounded-md bg-muted/40 p-3">
                                        <dt className="text-xs text-muted-foreground">Licence Number</dt>
                                        <dd className="mt-1 font-mono text-lg font-bold">{eligibility.licence_number ?? '---'}</dd>
                                    </div>
                                    <div className="rounded-md bg-muted/40 p-3">
                                        <dt className="text-xs text-muted-foreground">Expiry Date</dt>
                                        <dd className="mt-1 font-medium">
                                            {eligibility.licence_expires_at ? (
                                                <span className="inline-flex items-center gap-2">
                                                    {formatDate(eligibility.licence_expires_at)}
                                                    {isExpired && (
                                                        <Badge variant="destructive" className="text-[10px]">Expired</Badge>
                                                    )}
                                                    {!isExpired && isExpiringSoon && expiryDays !== null && (
                                                        <Badge className={cn('text-[10px]', expiryDays <= 30 ? 'bg-status-warning text-white' : 'bg-status-warning text-white')}>
                                                            {expiryDays}d left
                                                        </Badge>
                                                    )}
                                                </span>
                                            ) : '---'}
                                        </dd>
                                    </div>
                                    <div className="rounded-md bg-muted/40 p-3">
                                        <dt className="text-xs text-muted-foreground">Can Drive Clients</dt>
                                        <dd className="mt-1 font-medium">
                                            <Badge variant={eligibility.can_drive_clients ? 'default' : 'secondary'}>
                                                {eligibility.can_drive_clients ? 'Yes' : 'No'}
                                            </Badge>
                                        </dd>
                                    </div>
                                </dl>
                            </CardContent>
                        </Card>

                        {/* Assigned Vehicles */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Car className="h-4 w-4" />
                                    Assigned Vehicles
                                    {safeAssignedVehicles.length > 0 && (
                                        <Badge variant="secondary" className="ml-auto text-xs">{safeAssignedVehicles.length}</Badge>
                                    )}
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {safeAssignedVehicles.length > 0 ? (
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {safeAssignedVehicles.map((v) => (
                                            <Link
                                                key={v.id}
                                                href={`/fleet-assets/vehicles/${v.id}`}
                                                className="flex items-center gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/50 hover:border-primary/30"
                                            >
                                                <div className="flex h-9 w-9 items-center justify-center rounded-md bg-primary/10 text-primary">
                                                    <Car className="h-4 w-4" />
                                                </div>
                                                <div>
                                                    <div className="text-sm font-medium">{v.name}</div>
                                                    <div className="text-xs text-muted-foreground">{v.asset_tag}</div>
                                                </div>
                                            </Link>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">No vehicles assigned.</p>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    <div className="space-y-4">
                        {/* Driving Metrics */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Gauge className="h-4 w-4" />
                                    Driving Metrics
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-2 gap-3">
                                    <div className="rounded-lg border-2 border-primary/20 bg-primary/5 p-3 text-center">
                                        <div className="text-3xl font-bold text-primary">{aggregatedMetrics.score}</div>
                                        <div className="mt-1 text-xs text-muted-foreground">Latest Score</div>
                                    </div>
                                    <div className="rounded-lg bg-muted/40 p-3 text-center">
                                        <div className="text-3xl font-bold">{safeSessions.length}</div>
                                        <div className="mt-1 text-xs text-muted-foreground">Sessions</div>
                                    </div>
                                    <div className="rounded-lg bg-status-warning-bg p-3 text-center">
                                        <div className="text-2xl font-bold text-status-warning">{aggregatedMetrics.harsh_brakes}</div>
                                        <div className="mt-1 text-xs text-muted-foreground">Harsh Brakes</div>
                                    </div>
                                    <div className="rounded-lg bg-status-critical-bg p-3 text-center">
                                        <div className="text-2xl font-bold text-status-critical">{aggregatedMetrics.speeding_events}</div>
                                        <div className="mt-1 text-xs text-muted-foreground">Speeding Events</div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Quick Stats */}
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">Quick Stats</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-3">
                                    <div className="flex items-center justify-between rounded-md bg-muted/30 px-3 py-2">
                                        <span className="text-sm text-muted-foreground">Total Trips</span>
                                        <span className="font-semibold">{safeRecentTrips.length}</span>
                                    </div>
                                    <div className="flex items-center justify-between rounded-md bg-muted/30 px-3 py-2">
                                        <span className="text-sm text-muted-foreground">Total Idle Minutes</span>
                                        <span className="font-semibold">{aggregatedMetrics.idle_minutes}</span>
                                    </div>
                                    <div className="flex items-center justify-between rounded-md bg-muted/30 px-3 py-2">
                                        <span className="text-sm text-muted-foreground">Vehicles Assigned</span>
                                        <span className="font-semibold">{safeAssignedVehicles.length}</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {/* Recent Trips */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Route className="h-4 w-4" />
                            Recent Trips
                            {safeRecentTrips.length > 0 && (
                                <Badge variant="secondary" className="ml-auto text-xs">{safeRecentTrips.length}</Badge>
                            )}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {safeRecentTrips.length > 0 ? (
                            <div className="overflow-x-auto rounded-md border">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="bg-muted/50 text-xs uppercase tracking-wider text-muted-foreground">
                                            <th className="px-4 py-3 text-left font-medium">Vehicle</th>
                                            <th className="px-4 py-3 text-left font-medium">Started</th>
                                            <th className="px-4 py-3 text-left font-medium">Ended</th>
                                            <th className="px-4 py-3 text-left font-medium">Distance</th>
                                            <th className="px-4 py-3 text-left font-medium">Duration</th>
                                            <th className="px-4 py-3 text-left font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {safeRecentTrips.map((trip) => {
                                            const durationMin = computeDurationMinutes(trip.started_at, trip.ended_at);
                                            return (
                                                <tr key={trip.id} className="border-b last:border-0 hover:bg-muted/30 transition-colors">
                                                    <td className="px-4 py-3 font-medium">{trip.asset?.name ?? '---'}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{trip.started_at ? formatDateTime(trip.started_at) : '---'}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{trip.ended_at ? formatDateTime(trip.ended_at) : '---'}</td>
                                                    <td className="px-4 py-3">{trip.distance_km ?? 0} km</td>
                                                    <td className="px-4 py-3">{durationMin != null ? `${durationMin} min` : '---'}</td>
                                                    <td className="px-4 py-3">
                                                        <Badge variant="secondary" className="text-xs">{trip.status ?? '---'}</Badge>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">No recent trips.</p>
                        )}
                    </CardContent>
                </Card>

                {/* Driver Sessions */}
                {safeSessions.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Clock className="h-4 w-4" />
                                Recent Sessions
                                <Badge variant="secondary" className="ml-auto text-xs">{safeSessions.length}</Badge>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto rounded-md border">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="bg-muted/50 text-xs uppercase tracking-wider text-muted-foreground">
                                            <th className="px-4 py-3 text-left font-medium">Vehicle</th>
                                            <th className="px-4 py-3 text-left font-medium">Started</th>
                                            <th className="px-4 py-3 text-left font-medium">Ended</th>
                                            <th className="px-4 py-3 text-left font-medium">Duration</th>
                                            <th className="px-4 py-3 text-left font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {safeSessions.map((session) => {
                                            const durationMin = computeDurationMinutes(session.started_at, session.ended_at);
                                            return (
                                                <tr key={session.id} className="border-b last:border-0 hover:bg-muted/30 transition-colors">
                                                    <td className="px-4 py-3 font-medium">{session.asset?.name ?? '---'}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{session.started_at ? formatDateTime(session.started_at) : '---'}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{session.ended_at ? formatDateTime(session.ended_at) : '---'}</td>
                                                    <td className="px-4 py-3">{durationMin != null ? `${durationMin} min` : '---'}</td>
                                                    <td className="px-4 py-3">
                                                        <Badge variant="secondary" className="text-xs">{session.status ?? '---'}</Badge>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </PageShell>
        </AppLayout>
    );
}
