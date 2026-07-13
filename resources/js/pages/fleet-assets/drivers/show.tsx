import { FLEET_COLORS, HalfMoonGauge } from '@/components/fleet-charts';
import { CompactHeroStat, FleetCompactHero } from '@/pages/fleet-assets/components/fleet-compact-hero';
import { FleetHeroAction } from '@/pages/fleet-assets/components/fleet-hero-kit';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Car,
    Clock,
    ExternalLink,
    Gauge,
    Loader2,
    Minus,
    Route,
    Shield,
    Timer,
    TrendingDown,
    TrendingUp,
    Zap,
} from 'lucide-react';
import { useState } from 'react';
import { formatDate, formatDateTime, formatDistance } from '@/lib/fleet-utils';


type Scorecard = {
    period: string;
    score: number;
    previous_score: number;
    fleet_avg_score: number;
    metrics: {
        harsh_brakes: number;
        hard_accels: number;
        speeding_events: number;
        idle_minutes: number;
        total_distance_km: number;
    };
    recent_events: Array<{
        id: number;
        type: string;
        severity: string | null;
        occurred_at: string | null;
        asset_id: number;
    }>;
};

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
    /** Deferred — present on full loads with ?tab=scorecard, otherwise fetched
     *  via partial reload when the Scorecard tab is opened. */
    scorecard?: Scorecard | null;
};

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

function scoreColor(score: number): string {
    if (score >= 80) return FLEET_COLORS.primary;
    if (score >= 60) return FLEET_COLORS.warning;
    return FLEET_COLORS.danger;
}

function eventIcon(type: string) {
    switch (type) {
        case 'harsh_brake': return <AlertTriangle className="h-4 w-4 text-status-warning" />;
        case 'harsh_accel': return <Zap className="h-4 w-4 text-status-warning" />;
        case 'speeding': return <Gauge className="h-4 w-4 text-status-critical" />;
        case 'idle': return <Timer className="h-4 w-4 text-status-info" />;
        default: return <AlertTriangle className="h-4 w-4 text-muted-foreground" />;
    }
}

export default function DriverShow({ driver, assigned_vehicles, sessions, driving_metrics, recent_trips, scorecard }: Props) {
    const eligibility = driver?.eligibility ?? {};
    const driverStatus = eligibility.status ?? 'unknown';
    const safeAssignedVehicles = assigned_vehicles ?? [];
    const safeSessions = sessions ?? [];
    const safeDrivingMetrics = driving_metrics ?? [];
    const safeRecentTrips = recent_trips ?? [];

    // Honour ?tab=scorecard on arrival (the legacy /scorecard route redirects
    // here with it, and the controller eager-loads the payload for that case).
    const [activeTab, setActiveTab] = useState<string>(() => {
        if (typeof window === 'undefined') return 'overview';
        return new URLSearchParams(window.location.search).get('tab') === 'scorecard' ? 'scorecard' : 'overview';
    });

    const openTab = (tab: string) => {
        setActiveTab(tab);
        if (tab === 'scorecard' && !scorecard) {
            router.reload({ only: ['scorecard'], data: { tab: 'scorecard' } });
        }
    };

    const changeScorecardPeriod = (newPeriod: string) => {
        router.reload({ only: ['scorecard'], data: { tab: 'scorecard', period: newPeriod } });
    };

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
                <FleetCompactHero
                    pill={`Driver · ${driverStatus.replace(/_/g, ' ')}`}
                    title={driver?.name ?? 'Driver'}
                    backHref="/fleet-assets/drivers"
                    backLabel="Drivers"
                    stats={
                        <>
                            <CompactHeroStat
                                label="Safety score"
                                value={String(aggregatedMetrics.score)}
                                tone={aggregatedMetrics.score >= 80 ? 'success' : aggregatedMetrics.score >= 60 ? 'warning' : 'critical'}
                            />
                            {driver.hr_status ? (
                                <CompactHeroStat
                                    label="HR status"
                                    value={driver.hr_status.replace(/_/g, ' ')}
                                    tone={driver.hr_status === 'active' ? 'success' : 'warning'}
                                />
                            ) : null}
                        </>
                    }
                    actions={
                        <>
                            <FleetHeroAction icon={Gauge} onClick={() => openTab('scorecard')} emphasis>
                                Scorecard
                            </FleetHeroAction>
                            <FleetHeroAction icon={ExternalLink} href={`/hr/staff/${driver?.id}`}>
                                HR profile
                            </FleetHeroAction>
                        </>
                    }
                />

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

                {/* Tabs: Overview / Scorecard */}
                <Tabs value={activeTab} onValueChange={openTab}>
                    <TabsList className="h-auto flex-wrap gap-1 p-1">
                        <TabsTrigger value="overview">Overview</TabsTrigger>
                        <TabsTrigger value="scorecard">Scorecard</TabsTrigger>
                    </TabsList>

                    <TabsContent value="overview" className="space-y-6">

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
                            <div data-fleet-narrow-strategy="horizontal-scroll" className="overflow-x-auto rounded-md border">
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
                            <div data-fleet-narrow-strategy="horizontal-scroll" className="overflow-x-auto rounded-md border">
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
                    </TabsContent>

                    {/* Scorecard tab — folded in from the retired standalone scorecard page. */}
                    <TabsContent value="scorecard" className="space-y-6">
                        {!scorecard ? (
                            <div className="flex flex-col items-center justify-center gap-3 py-16 text-muted-foreground">
                                <Loader2 className="h-6 w-6 animate-spin" />
                                <p className="text-sm">Loading scorecard…</p>
                            </div>
                        ) : (
                            <>
                                <div className="flex items-center justify-between">
                                    <p className="text-sm text-muted-foreground">Safety score and driving behavior analysis.</p>
                                    <Select value={scorecard.period} onValueChange={changeScorecardPeriod}>
                                        <SelectTrigger className="w-36">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="7">Last 7 days</SelectItem>
                                            <SelectItem value="30">Last 30 days</SelectItem>
                                            <SelectItem value="90">Last 90 days</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="grid gap-4 lg:grid-cols-[auto,1fr]">
                                    {/* Score Gauge */}
                                    <Card className="flex flex-col items-center justify-center px-8 py-6">
                                        <CardHeader className="pt-0 pb-2">
                                            <CardTitle className="text-center text-base">Overall Safety Score</CardTitle>
                                        </CardHeader>
                                        <CardContent className="flex flex-col items-center justify-center pb-2">
                                            <HalfMoonGauge
                                                value={scorecard.score}
                                                label={`${scorecard.score} / 100`}
                                                size={200}
                                                color={scoreColor(scorecard.score)}
                                            />

                                            {/* Trend */}
                                            <div className="mt-4 flex items-center gap-2">
                                                {scorecard.score - scorecard.previous_score > 0 ? (
                                                    <Badge variant="default" className="bg-primary/10 text-primary dark:bg-primary dark:text-primary/70">
                                                        <TrendingUp className="mr-1 h-3 w-3" />
                                                        +{scorecard.score - scorecard.previous_score} vs last period
                                                    </Badge>
                                                ) : scorecard.score - scorecard.previous_score < 0 ? (
                                                    <Badge variant="destructive">
                                                        <TrendingDown className="mr-1 h-3 w-3" />
                                                        {scorecard.score - scorecard.previous_score} vs last period
                                                    </Badge>
                                                ) : (
                                                    <Badge variant="secondary">
                                                        <Minus className="mr-1 h-3 w-3" />
                                                        No change vs last period
                                                    </Badge>
                                                )}
                                            </div>

                                            {/* Fleet comparison */}
                                            <div className="mt-3 text-sm text-muted-foreground">
                                                Fleet average: <span className="font-medium">{scorecard.fleet_avg_score}</span>
                                                {scorecard.score - scorecard.fleet_avg_score > 0 && <span className="ml-1 text-primary">(+{scorecard.score - scorecard.fleet_avg_score} above)</span>}
                                                {scorecard.score - scorecard.fleet_avg_score < 0 && <span className="ml-1 text-status-critical">({scorecard.score - scorecard.fleet_avg_score} below)</span>}
                                                {scorecard.score - scorecard.fleet_avg_score === 0 && <span className="ml-1 text-muted-foreground">(at average)</span>}
                                            </div>
                                        </CardContent>
                                    </Card>

                                    {/* Metrics Grid */}
                                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                        <Card>
                                            <CardHeader className="pb-2">
                                                <CardTitle className="flex items-center gap-2 text-sm">
                                                    <AlertTriangle className="h-4 w-4 text-status-warning" />
                                                    Harsh Braking
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                <div className="text-3xl font-bold text-status-warning">{scorecard.metrics.harsh_brakes}</div>
                                                <p className="mt-1 text-xs text-muted-foreground">events this period</p>
                                            </CardContent>
                                        </Card>

                                        <Card>
                                            <CardHeader className="pb-2">
                                                <CardTitle className="flex items-center gap-2 text-sm">
                                                    <Zap className="h-4 w-4 text-status-warning" />
                                                    Hard Acceleration
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                <div className="text-3xl font-bold text-status-warning">{scorecard.metrics.hard_accels}</div>
                                                <p className="mt-1 text-xs text-muted-foreground">events this period</p>
                                            </CardContent>
                                        </Card>

                                        <Card>
                                            <CardHeader className="pb-2">
                                                <CardTitle className="flex items-center gap-2 text-sm">
                                                    <Gauge className="h-4 w-4 text-status-critical" />
                                                    Speeding Events
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                <div className="text-3xl font-bold text-status-critical">{scorecard.metrics.speeding_events}</div>
                                                <p className="mt-1 text-xs text-muted-foreground">events this period</p>
                                            </CardContent>
                                        </Card>

                                        <Card>
                                            <CardHeader className="pb-2">
                                                <CardTitle className="flex items-center gap-2 text-sm">
                                                    <Timer className="h-4 w-4 text-status-info" />
                                                    Idle Time
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                <div className="text-3xl font-bold">{Math.round(scorecard.metrics.idle_minutes / 60 * 10) / 10}h</div>
                                                <p className="mt-1 text-xs text-muted-foreground">{scorecard.metrics.idle_minutes} minutes total</p>
                                            </CardContent>
                                        </Card>

                                        <Card>
                                            <CardHeader className="pb-2">
                                                <CardTitle className="flex items-center gap-2 text-sm">
                                                    <Route className="h-4 w-4 text-primary" />
                                                    Total Distance
                                                </CardTitle>
                                            </CardHeader>
                                            <CardContent>
                                                <div className="text-3xl font-bold">{formatDistance(scorecard.metrics.total_distance_km)}</div>
                                                <p className="mt-1 text-xs text-muted-foreground">driven this period</p>
                                            </CardContent>
                                        </Card>
                                    </div>
                                </div>

                                {/* Recent Driving Events */}
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Recent Driving Events</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {(scorecard.recent_events ?? []).length > 0 ? (
                                            <div className="space-y-2">
                                                {scorecard.recent_events.map((event) => (
                                                    <div key={event.id} className="flex items-center justify-between rounded-md border p-3 text-sm">
                                                        <div className="flex items-center gap-3">
                                                            {eventIcon(event.type)}
                                                            <div>
                                                                <span className="font-medium capitalize">{(event.type ?? '').replace(/_/g, ' ')}</span>
                                                                {event.severity && (
                                                                    <Badge variant="outline" className="ml-2 text-[10px]">{event.severity}</Badge>
                                                                )}
                                                            </div>
                                                        </div>
                                                        <span className="text-xs text-muted-foreground">
                                                            {event.occurred_at ? formatDateTime(event.occurred_at) : '---'}
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        ) : (
                                            <div className="flex flex-col items-center justify-center py-8 text-center">
                                                <Shield className="mb-3 h-10 w-10 text-muted-foreground/50" />
                                                <p className="text-sm text-muted-foreground">No driving events recorded for this period.</p>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
                            </>
                        )}
                    </TabsContent>
                </Tabs>
            </PageShell>
        </AppLayout>
    );
}
