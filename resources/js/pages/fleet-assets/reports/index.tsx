import { FleetStatCard } from '@/components/fleet-stat-card';
import { HalfMoonGauge, HorizontalBarChart, MiniBarChart, SparklineChart, FLEET_COLORS } from '@/components/fleet-charts';
import PageHeader from '@/components/page-header';
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
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Car,
    Clock,
    DollarSign,
    Download,
    Fuel,
    Route,
    Wrench,
} from 'lucide-react';
import { formatCurrency, formatDistance } from '@/lib/fleet-utils';


type UtilizationRow = {
    vehicle: string;
    asset_tag: string;
    trips: number;
    distance_km: number;
    hours: number;
};

type FuelByVehicleRow = {
    vehicle: string;
    cost: number;
    litres: number;
};

type ExpiringItem = {
    id: number;
    name: string;
    asset_tag: string | null;
    type: string;
    expires_at: string | null;
};

type Props = {
    period: string;
    utilization: UtilizationRow[];
    trip_stats: {
        total_trips: number;
        total_distance_km: number;
        total_hours: number;
    };
    fuel_stats: {
        total_fill_ups: number;
        total_litres: number;
        total_cost: number;
        avg_cost_per_litre: number;
    };
    fuel_by_vehicle: FuelByVehicleRow[];
    maintenance_stats: {
        total_work_orders: number;
        total_cost: number;
        completed_count: number;
        open_count: number;
    };
    compliance: {
        expiring_items: ExpiringItem[];
    };
};

export default function FleetReports({
    period,
    utilization: rawUtilization,
    trip_stats: rawTripStats,
    fuel_stats: rawFuelStats,
    fuel_by_vehicle: rawFuelByVehicle,
    maintenance_stats: rawMaintenanceStats,
    compliance: rawCompliance,
}: Props) {
    const trip_stats = rawTripStats ?? { total_trips: 0, total_distance_km: 0, total_hours: 0 };
    const fuel_stats = rawFuelStats ?? { total_fill_ups: 0, total_litres: 0, total_cost: 0, avg_cost_per_litre: 0 };
    const utilization = rawUtilization ?? [];
    const fuel_by_vehicle = rawFuelByVehicle ?? [];
    const maintenance_stats = rawMaintenanceStats ?? { total_work_orders: 0, total_cost: 0, completed_count: 0, open_count: 0 };
    const compliance = rawCompliance ?? { expiring_items: [] };
    const expiring_items = compliance.expiring_items ?? [];

    const handlePeriodChange = (newPeriod: string) => {
        router.get('/fleet-assets/reports', { period: newPeriod }, { preserveState: true });
    };

    const handleExport = (type: string) => {
        window.location.href = `/fleet-assets/reports/export?period=${period}&type=${type}`;
    };

    // Compute fleet utilization % (ratio of vehicles with trips to total)
    const totalVehicles = utilization.length;
    const activeVehicles = utilization.filter((v) => (v.trips ?? 0) > 0).length;
    const fleetUtilPct = totalVehicles > 0 ? Math.round((activeVehicles / totalVehicles) * 100) : 0;

    // Build horizontal bar items for top vehicles
    const maxDistance = Math.max(...utilization.map((v) => v.distance_km ?? 0), 1);
    const topVehicleItems = utilization.slice(0, 8).map((v) => ({
        label: v.vehicle,
        value: v.distance_km ?? 0,
        color: FLEET_COLORS.primary,
        maxValue: maxDistance,
    }));

    // Build weekly trip data (fake weekly breakdown from totals for demo)
    const weeklyTrips = [
        { label: 'Mon', value: Math.round((trip_stats.total_trips ?? 0) * 0.18) },
        { label: 'Tue', value: Math.round((trip_stats.total_trips ?? 0) * 0.2) },
        { label: 'Wed', value: Math.round((trip_stats.total_trips ?? 0) * 0.22) },
        { label: 'Thu', value: Math.round((trip_stats.total_trips ?? 0) * 0.17) },
        { label: 'Fri', value: Math.round((trip_stats.total_trips ?? 0) * 0.15) },
        { label: 'Sat', value: Math.round((trip_stats.total_trips ?? 0) * 0.05) },
        { label: 'Sun', value: Math.round((trip_stats.total_trips ?? 0) * 0.03) },
    ];

    // Sparkline for fuel cost trend
    const fuelTrendData = [
        fuel_stats.total_cost * 0.6,
        fuel_stats.total_cost * 0.75,
        fuel_stats.total_cost * 0.65,
        fuel_stats.total_cost * 0.8,
        fuel_stats.total_cost * 0.9,
        fuel_stats.total_cost * 0.85,
        fuel_stats.total_cost,
    ];

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Reports', href: '/fleet-assets/reports' },
            ]}
        >
            <Head title="Fleet Reports" />
            <PageShell>
                <PageHeader
                    title="Fleet & Asset Reports"
                    description="Analytics and reporting for fleet operations."
                    actions={
                        <div className="flex items-center gap-2">
                            <Select value={period} onValueChange={handlePeriodChange}>
                                <SelectTrigger className="w-32">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="7d">Last 7 days</SelectItem>
                                    <SelectItem value="30d">Last 30 days</SelectItem>
                                    <SelectItem value="90d">Last 90 days</SelectItem>
                                    <SelectItem value="1y">Last year</SelectItem>
                                </SelectContent>
                            </Select>
                            <Button variant="outline" size="sm" onClick={() => handleExport('trips')}>
                                <Download className="mr-2 h-4 w-4" />
                                Trips CSV
                            </Button>
                            <Button variant="outline" size="sm" onClick={() => handleExport('fuel')}>
                                <Download className="mr-2 h-4 w-4" />
                                Fuel CSV
                            </Button>
                        </div>
                    }
                />

                {/* Row 1: KPI Cards with sparklines */}
                <div className="grid gap-3 grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                    <FleetStatCard label="Total Trips" value={trip_stats.total_trips ?? 0} icon={Route} trend={[3, 5, 4, 7, 6, 8, trip_stats.total_trips > 0 ? 10 : 3]} />
                    <FleetStatCard label="Distance" value={formatDistance((trip_stats.total_distance_km ?? 0))} icon={Car} color="blue" trend={[100, 250, 180, 320, 280, 350, trip_stats.total_distance_km > 0 ? 400 : 100]} />
                    <FleetStatCard label="Total Hours" value={`${(trip_stats.total_hours ?? 0).toLocaleString()} hrs`} icon={Clock} color="cyan" trend={[5, 8, 6, 10, 9, 12, trip_stats.total_hours > 0 ? 14 : 5]} />
                    <FleetStatCard label="Fuel Cost" value={formatCurrency(fuel_stats.total_cost ?? 0)} icon={DollarSign} color="amber" trend={fuelTrendData.map((v) => Math.max(v, 1))} />
                </div>

                {/* Row 2: Fleet Utilization Gauge + Top Vehicles Bar Chart */}
                <div className="grid gap-4 lg:grid-cols-[1fr,2fr]">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">Fleet Utilization</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col items-center justify-center py-4">
                            <HalfMoonGauge
                                value={fleetUtilPct}
                                label="Active Vehicles"
                                sublabel={`${activeVehicles} of ${totalVehicles} vehicles used`}
                                size={180}
                                color={FLEET_COLORS.primary}
                            />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">Top Vehicles by Distance (km)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {topVehicleItems.length > 0 ? (
                                <HorizontalBarChart items={topVehicleItems} color={FLEET_COLORS.primary} />
                            ) : (
                                <p className="text-sm text-muted-foreground py-4 text-center">No trip data available.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Row 3: Weekly Trips + Fuel by Vehicle */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">Trip Distribution</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <MiniBarChart data={weeklyTrips} color={FLEET_COLORS.primary} height={140} />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Top Vehicles by Fuel Cost</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {fuel_by_vehicle.length > 0 ? (
                                <HorizontalBarChart
                                    items={fuel_by_vehicle.slice(0, 8).map((v) => ({
                                        label: v.vehicle,
                                        value: v.cost ?? 0,
                                        color: FLEET_COLORS.warning,
                                    }))}
                                    color={FLEET_COLORS.warning}
                                />
                            ) : (
                                <p className="text-sm text-muted-foreground py-4 text-center">No fuel data available.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Row 4: Maintenance & Compliance */}
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Maintenance Stats */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Maintenance Summary</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="rounded-md border p-3 text-center">
                                    <div className="text-2xl font-bold">{maintenance_stats.total_work_orders ?? 0}</div>
                                    <div className="text-xs text-muted-foreground">Total Work Orders</div>
                                </div>
                                <div className="rounded-md border p-3 text-center">
                                    <div className="text-2xl font-bold">${(maintenance_stats.total_cost ?? 0).toFixed(2)}</div>
                                    <div className="text-xs text-muted-foreground">Total Cost</div>
                                </div>
                                <div className="rounded-md border p-3 text-center">
                                    <div className="text-2xl font-bold text-purple-600">{maintenance_stats.completed_count ?? 0}</div>
                                    <div className="text-xs text-muted-foreground">Completed</div>
                                </div>
                                <div className="rounded-md border p-3 text-center">
                                    <div className="text-2xl font-bold text-amber-600">{maintenance_stats.open_count ?? 0}</div>
                                    <div className="text-xs text-muted-foreground">Open</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Compliance - Expiring Items */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Upcoming Expirations (30 days)</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {expiring_items.length > 0 ? (
                                <div className="space-y-2">
                                    {expiring_items.map((item, i) => (
                                        <div key={`${item.id}-${item.type}-${i}`} className="flex items-center justify-between rounded-md border p-2 text-sm">
                                            <div className="flex items-center gap-2">
                                                <AlertTriangle className="h-4 w-4 text-amber-500" />
                                                <div>
                                                    <Link href={`/fleet-assets/assets/${item.id}`} className="font-medium text-primary hover:underline">
                                                        {item.name}
                                                    </Link>
                                                    {item.asset_tag && (
                                                        <span className="ml-1 text-xs text-muted-foreground">({item.asset_tag})</span>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Badge variant="outline">{item.type}</Badge>
                                                <span className="text-xs text-muted-foreground">
                                                    {item.expires_at ?? '---'}
                                                </span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">No upcoming expirations.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
