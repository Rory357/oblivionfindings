import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { PageHero } from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    Car,
    Clock,
    Download,
    Fuel,
    MapPin,
    User,
} from 'lucide-react';

interface Props {
    period: string;
    trip_stats: {
        total_trips: number;
        total_distance_km: number;
        total_hours: number;
        avg_distance_km: number;
        avg_duration_min: number;
    };
    fuel_stats: {
        total_fill_ups: number;
        total_litres: number;
        total_cost: number;
        avg_cost_per_litre: number;
    };
    signal_stats: Record<string, number>;
    trips_by_vehicle: { vehicle: string; trips: number; distance_km: number }[];
    fuel_by_vehicle: { vehicle: string; litres: number; cost: number }[];
    daily_trips: { date: string; trips: number; distance_km: number }[];
    driver_stats: { driver: string; sessions: number; hours: number }[];
    consent_stats: {
        total_events: number;
        blocked_events: number;
        blocked_by_vehicle: { vehicle: string; blocked: number }[];
    };
    driving_stats: {
        harsh_brake_count: number;
        accel_count: number;
        speeding_events: number;
        idle_minutes: number;
        avg_score: number;
    };
}

export default function FleetReports({
    period,
    trip_stats,
    fuel_stats,
    signal_stats,
    trips_by_vehicle,
    fuel_by_vehicle,
    daily_trips,
    driver_stats,
    consent_stats,
    driving_stats,
}: Props) {
    const handlePeriodChange = (newPeriod: string) => {
        router.get(
            '/fleet/reports',
            { period: newPeriod },
            { preserveState: true },
        );
    };

    const handleExport = () => {
        window.location.href = `/fleet/reports/export?period=${period}`;
    };

    const maxDailyTrips = Math.max(0, ...daily_trips.map((d) => d.trips)) || 1;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet Management', href: '/fleet-management' },
                { title: 'Reports', href: '#' },
            ]}
        >
            <Head title="Fleet Reports" />
            <PageShell>
                <PageHero variant="compact"
                    title="Fleet Reports"
                    description="Vehicle usage, trips, and fuel statistics"
                    actions={
                        <div className="flex items-center gap-2">
                            <Select
                                value={period}
                                onValueChange={handlePeriodChange}
                            >
                                <SelectTrigger className="w-32">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="7d">Last 7 days</SelectItem>
                                    <SelectItem value="30d">
                                        Last 30 days
                                    </SelectItem>
                                    <SelectItem value="90d">
                                        Last 90 days
                                    </SelectItem>
                                    <SelectItem value="1y">Last year</SelectItem>
                                </SelectContent>
                            </Select>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={handleExport}
                            >
                                <Download className="mr-2 h-4 w-4" />
                                Export CSV
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href="/fleet-management">
                                    <ArrowLeft className="mr-2 h-4 w-4" />
                                    Dashboard
                                </Link>
                            </Button>
                        </div>
                    }
                />

                {/* Key Metrics */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                <MapPin className="h-4 w-4" />
                                Total Trips
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">
                                {trip_stats.total_trips}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Avg {trip_stats.avg_distance_km} km per trip
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                <Car className="h-4 w-4" />
                                Distance Travelled
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">
                                {trip_stats.total_distance_km.toLocaleString()}{' '}
                                km
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {trip_stats.total_hours} hours driven
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                <Fuel className="h-4 w-4" />
                                Fuel Used
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">
                                {fuel_stats.total_litres.toLocaleString()} L
                            </div>
                            <p className="text-xs text-muted-foreground">
                                ${fuel_stats.total_cost.toLocaleString()} total
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                <Clock className="h-4 w-4" />
                                Avg Trip Duration
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="text-3xl font-bold">
                                {trip_stats.avg_duration_min} min
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {fuel_stats.total_fill_ups} fill-ups
                            </p>
                        </CardContent>
                    </Card>
                </div>

                {/* Charts Row */}
                <div className="mt-6 grid gap-4 lg:grid-cols-2">
                    {/* Daily Trip Trend */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Daily Trip Activity</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {daily_trips.length > 0 ? (
                                <div className="flex h-40 items-end gap-1">
                                    {daily_trips.slice(-30).map((day, i) => (
                                        <div
                                            key={i}
                                            className="group relative flex-1"
                                        >
                                            <div
                                                className="w-full rounded-t bg-primary transition-all hover:bg-primary/80"
                                                style={{
                                                    height: `${(day.trips / maxDailyTrips) * 100}%`,
                                                    minHeight:
                                                        day.trips > 0
                                                            ? '4px'
                                                            : '0',
                                                }}
                                            />
                                            <div className="absolute bottom-full left-1/2 mb-1 hidden -translate-x-1/2 rounded bg-popover px-2 py-1 text-xs shadow-md group-hover:block">
                                                <div>{day.date}</div>
                                                <div>
                                                    {day.trips} trips,{' '}
                                                    {day.distance_km} km
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No trip data available
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Signal Types */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Signal Types</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {Object.keys(signal_stats).length > 0 ? (
                                <div className="space-y-2">
                                    {Object.entries(signal_stats)
                                        .sort((a, b) => b[1] - a[1])
                                        .slice(0, 8)
                                        .map(([type, count]) => (
                                            <div
                                                key={type}
                                                className="flex items-center justify-between"
                                            >
                                                <span className="text-sm">
                                                    {type.replace(/_/g, ' ')}
                                                </span>
                                                <span className="text-sm font-medium">
                                                    {count}
                                                </span>
                                            </div>
                                        ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No signals recorded
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Vehicle Stats Row */}
                <div className="mt-4 grid gap-4 lg:grid-cols-3">
                    {/* Trips by Vehicle */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Top Vehicles by Distance</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {trips_by_vehicle.length > 0 ? (
                                <div className="space-y-3">
                                    {trips_by_vehicle.map((v, i) => (
                                        <div key={i}>
                                            <div className="flex justify-between text-sm">
                                                <span className="truncate">
                                                    {v.vehicle}
                                                </span>
                                                <span className="ml-2 font-medium">
                                                    {v.distance_km} km
                                                </span>
                                            </div>
                                            <div className="mt-1 h-2 rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-primary"
                                                    style={{
                                                        width: `${(v.distance_km / (trips_by_vehicle[0]?.distance_km || 1)) * 100}%`,
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No trip data
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Fuel by Vehicle */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Top Vehicles by Fuel Cost</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {fuel_by_vehicle.length > 0 ? (
                                <div className="space-y-3">
                                    {fuel_by_vehicle.map((v, i) => (
                                        <div key={i}>
                                            <div className="flex justify-between text-sm">
                                                <span className="truncate">
                                                    {v.vehicle}
                                                </span>
                                                <span className="ml-2 font-medium">
                                                    ${v.cost.toFixed(2)}
                                                </span>
                                            </div>
                                            <div className="mt-1 h-2 rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-status-warning"
                                                    style={{
                                                        width: `${(v.cost / (fuel_by_vehicle[0]?.cost || 1)) * 100}%`,
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No fuel data
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Driver Stats */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Top Drivers by Hours</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {driver_stats.length > 0 ? (
                                <div className="space-y-2">
                                    {driver_stats.map((d, i) => (
                                        <div
                                            key={i}
                                            className="flex items-center justify-between"
                                        >
                                            <div className="flex items-center gap-2">
                                                <User className="h-4 w-4 text-muted-foreground" />
                                                <span className="text-sm">
                                                    {d.driver}
                                                </span>
                                            </div>
                                            <div className="text-right text-sm">
                                                <span className="font-medium">
                                                    {d.hours}h
                                                </span>
                                                <span className="ml-2 text-muted-foreground">
                                                    ({d.sessions} sessions)
                                                </span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No driver data
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="mt-4 grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Driver Behaviour Summary</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <div className="text-sm text-muted-foreground">
                                        Avg Score
                                    </div>
                                    <div className="text-2xl font-bold">
                                        {driving_stats.avg_score}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-sm text-muted-foreground">
                                        Idle Minutes
                                    </div>
                                    <div className="text-2xl font-bold">
                                        {driving_stats.idle_minutes}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-sm text-muted-foreground">
                                        Harsh Brakes
                                    </div>
                                    <div className="text-2xl font-bold">
                                        {driving_stats.harsh_brake_count}
                                    </div>
                                </div>
                                <div>
                                    <div className="text-sm text-muted-foreground">
                                        Speeding Events
                                    </div>
                                    <div className="text-2xl font-bold">
                                        {driving_stats.speeding_events}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Consent Enforcement Audit</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">
                                    Blocked events
                                </span>
                                <span className="font-medium">
                                    {consent_stats.blocked_events} /{' '}
                                    {consent_stats.total_events}
                                </span>
                            </div>
                            <div className="mt-3 space-y-2">
                                {consent_stats.blocked_by_vehicle?.length ? (
                                    consent_stats.blocked_by_vehicle.map(
                                        (row, i) => (
                                            <div
                                                key={i}
                                                className="flex items-center justify-between text-sm"
                                            >
                                                <span className="truncate">
                                                    {row.vehicle}
                                                </span>
                                                <span className="font-medium">
                                                    {row.blocked}
                                                </span>
                                            </div>
                                        ),
                                    )
                                ) : (
                                    <div className="text-sm text-muted-foreground">
                                        No blocked telemetry events.
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Efficiency Summary */}
                {trip_stats.total_distance_km > 0 &&
                    fuel_stats.total_litres > 0 && (
                        <Card className="mt-4">
                            <CardHeader>
                                <CardTitle>Efficiency Summary</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div>
                                        <div className="text-sm text-muted-foreground">
                                            Avg Fuel Efficiency
                                        </div>
                                        <div className="text-2xl font-bold">
                                            {(
                                                trip_stats.total_distance_km /
                                                fuel_stats.total_litres
                                            ).toFixed(1)}{' '}
                                            km/L
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-sm text-muted-foreground">
                                            Cost per Kilometre
                                        </div>
                                        <div className="text-2xl font-bold">
                                            $
                                            {(
                                                fuel_stats.total_cost /
                                                trip_stats.total_distance_km
                                            ).toFixed(2)}
                                        </div>
                                    </div>
                                    <div>
                                        <div className="text-sm text-muted-foreground">
                                            Avg Fuel Price
                                        </div>
                                        <div className="text-2xl font-bold">
                                            ${fuel_stats.avg_cost_per_litre}/L
                                        </div>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    )}
            </PageShell>
        </AppLayout>
    );
}
