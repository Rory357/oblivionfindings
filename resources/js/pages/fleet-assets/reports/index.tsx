import { FleetPageMenu } from '@/components/fleet-assets/fleet-page-menu';
import {
    FLEET_COLORS,
    HalfMoonGauge,
    HorizontalBarChart,
    MiniBarChart,
} from '@/components/fleet-charts';
import { FleetStatCard } from '@/components/fleet-stat-card';
import PageShell from '@/components/page-shell';
import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
} from '@/components/page/page-header';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate, formatDistance } from '@/lib/fleet-utils';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Car,
    ChevronRight,
    Clock,
    DollarSign,
    Download,
    FileBarChart,
    Globe,
    Route,
    Shield,
    TrendingDown,
    TrendingUp,
    User,
    Users,
} from 'lucide-react';
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
    vehicle_id?: number;
    vehicle_name?: string;
    name: string;
    asset_tag: string | null;
    type: string;
    expires_at: string | null;
    days_remaining?: number;
    status?: string;
};

type IncidentStats = {
    total: number;
    by_severity: Record<string, number>;
    by_type: Record<string, number>;
    open: number;
};

type VehicleUtilRow = {
    id: number;
    name: string;
    asset_tag: string;
    trips: number;
    km: number;
    trips_per_week: number;
    km_per_week: number;
    idle_days: number | null;
    cost_per_km: number | null;
    flag: 'underused' | 'overused' | 'normal';
};

type StaffRiskRow = {
    id: number;
    name: string;
    sessions: number;
    incidents: number;
    safety_score: number | null;
    risk_flag: 'high' | 'medium' | 'low';
};

type ResidentDemand = {
    residents: Array<{
        id: number;
        name: string;
        transport_count: number;
        per_week: number;
        last_transport: string | null;
    }>;
    purpose_breakdown: Record<string, number>;
};

type TrendRow = {
    month: string;
    trips: number;
    distance_km: number;
    fuel_cost: number;
    incidents: number;
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
    trip_distribution: Record<string, number>;
    incident_stats: IncidentStats;
    vehicle_utilisation?: VehicleUtilRow[];
    staff_risk?: StaffRiskRow[];
    resident_demand?: ResidentDemand;
    trends?: TrendRow[];
};

export default function FleetReports({
    period,
    utilization: rawUtilization,
    trip_stats: rawTripStats,
    fuel_stats: rawFuelStats,
    fuel_by_vehicle: rawFuelByVehicle,
    maintenance_stats: rawMaintenanceStats,
    compliance: rawCompliance,
    trip_distribution: rawTripDistribution,
    incident_stats: rawIncidentStats,
    vehicle_utilisation: rawVehicleUtil,
    staff_risk: rawStaffRisk,
    resident_demand: rawResidentDemand,
    trends: rawTrends,
}: Props) {
    const trip_stats = rawTripStats ?? {
        total_trips: 0,
        total_distance_km: 0,
        total_hours: 0,
    };
    const fuel_stats = rawFuelStats ?? {
        total_fill_ups: 0,
        total_litres: 0,
        total_cost: 0,
        avg_cost_per_litre: 0,
    };
    const utilization = rawUtilization ?? [];
    const fuel_by_vehicle = rawFuelByVehicle ?? [];
    const maintenance_stats = rawMaintenanceStats ?? {
        total_work_orders: 0,
        total_cost: 0,
        completed_count: 0,
        open_count: 0,
    };
    const compliance = rawCompliance ?? { expiring_items: [] };
    const expiring_items = compliance.expiring_items ?? [];
    const tripDistribution = rawTripDistribution ?? {};
    const incidentStats: IncidentStats = rawIncidentStats ?? {
        total: 0,
        by_severity: {},
        by_type: {},
        open: 0,
    };
    const vehicleUtil = rawVehicleUtil ?? [];
    const staffRisk = rawStaffRisk ?? [];
    const residentDemand = rawResidentDemand ?? {
        residents: [],
        purpose_breakdown: {},
    };
    const trends = rawTrends ?? [];

    const handlePeriodChange = (newPeriod: string) => {
        router.get(
            '/fleet-assets/reports/operating-summary',
            { period: newPeriod },
            { preserveState: true },
        );
    };

    const PERIOD_LABELS: Record<string, string> = {
        '7d': 'last 7 days',
        '30d': 'last 30 days',
        '90d': 'last 90 days',
        '1y': 'last year',
    };
    const periodLabel = PERIOD_LABELS[period] ?? 'last 30 days';
    // Fuel cost per km over the selected period (fuel spend ÷ distance driven).
    const costPerKm =
        (trip_stats.total_distance_km ?? 0) > 0
            ? (fuel_stats.total_cost ?? 0) / trip_stats.total_distance_km
            : null;

    // Compute fleet utilization % (ratio of vehicles with trips to total)
    const totalVehicles = utilization.length;
    const activeVehicles = utilization.filter((v) => (v.trips ?? 0) > 0).length;
    const fleetUtilPct =
        totalVehicles > 0
            ? Math.round((activeVehicles / totalVehicles) * 100)
            : 0;

    // Build horizontal bar items for top vehicles
    const maxDistance = Math.max(
        ...utilization.map((v) => v.distance_km ?? 0),
        1,
    );
    const topVehicleItems = utilization.slice(0, 8).map((v) => ({
        label: v.vehicle,
        value: v.distance_km ?? 0,
        color: FLEET_COLORS.primary,
        maxValue: maxDistance,
    }));

    // Map DAYOFWEEK (1=Sun, 2=Mon, ..., 7=Sat) to day labels using real data
    const dowLabels: Record<number, string> = {
        1: 'Sun',
        2: 'Mon',
        3: 'Tue',
        4: 'Wed',
        5: 'Thu',
        6: 'Fri',
        7: 'Sat',
    };
    const weeklyTrips = [2, 3, 4, 5, 6, 7, 1].map((dow) => ({
        label: dowLabels[dow],
        value: tripDistribution[String(dow)] ?? 0,
    }));

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Reports', href: '/fleet-assets/reports' },
                {
                    title: 'Operating summary & exports',
                    href: '/fleet-assets/reports/operating-summary',
                },
            ]}
        >
            <Head title="Operating summary & exports" />
            <PageShell>
                <PageHeader
                    icon={FileBarChart}
                    title="Operating summary & exports"
                    wrapTitle
                    subline={`Recorded fleet activity · ${periodLabel}`}
                    actions={
                        <>
                            <FleetPageMenu />
                            {
                                <>
                                    <PageHeaderGlassButton
                                        icon={Download}
                                        onClick={() => {
                                            window.location.href = `/fleet-assets/reports/export?period=${period}&type=trips`;
                                        }}
                                    >
                                        Trips CSV
                                    </PageHeaderGlassButton>
                                    <PageHeaderGlassButton
                                        icon={Download}
                                        onClick={() => {
                                            window.location.href = `/fleet-assets/reports/export?period=${period}&type=fuel`;
                                        }}
                                    >
                                        Fuel CSV
                                    </PageHeaderGlassButton>
                                    <PageHeaderPrimaryButton
                                        icon={FileBarChart}
                                        onClick={() =>
                                            router.visit(
                                                '/fleet-assets/reports/builder',
                                            )
                                        }
                                    >
                                        Build report
                                    </PageHeaderPrimaryButton>
                                </>
                            }
                        </>
                    }
                    meters={
                        <>
                            <PageHeaderMeterBlock
                                label="Trips"
                                onClick={() =>
                                    document
                                        .getElementById('recorded-activity')
                                        ?.scrollIntoView({ behavior: 'smooth' })
                                }
                            >
                                <PageHeaderMeterBig>
                                    {trip_stats.total_trips}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {periodLabel}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Distance"
                                onClick={() =>
                                    document
                                        .getElementById('recorded-activity')
                                        ?.scrollIntoView({ behavior: 'smooth' })
                                }
                            >
                                <PageHeaderMeterBig>
                                    {formatDistance(
                                        trip_stats.total_distance_km,
                                    )}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    Recorded travel
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Fuel spend"
                                onClick={() =>
                                    document
                                        .getElementById('recorded-activity')
                                        ?.scrollIntoView({ behavior: 'smooth' })
                                }
                            >
                                <PageHeaderMeterBig>
                                    {formatCurrency(fuel_stats.total_cost)}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {fuel_stats.total_fill_ups} fill-ups
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Fuel cost per km"
                                onClick={() =>
                                    document
                                        .getElementById('recorded-activity')
                                        ?.scrollIntoView({ behavior: 'smooth' })
                                }
                            >
                                <PageHeaderMeterBig>
                                    {costPerKm === null
                                        ? '—'
                                        : formatCurrency(costPerKm)}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {costPerKm === null
                                        ? 'No recorded distance'
                                        : 'Fuel spend ÷ distance'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    }
                    filters={
                        <PageHeaderFilterSelect
                            label="Period · Last 30 days"
                            value={period}
                            allValue="30d"
                            options={[
                                { value: '7d', label: 'Last 7 days' },
                                { value: '30d', label: 'Last 30 days' },
                                { value: '90d', label: 'Last 90 days' },
                                { value: '1y', label: 'Last year' },
                            ]}
                            onChange={handlePeriodChange}
                        />
                    }
                />

                {/* Report hub cross-link: geocoding & maps usage dashboard */}
                <Link
                    href="/fleet-management/maps-usage"
                    className="flex items-center justify-between gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/50"
                >
                    <div className="flex items-center gap-3">
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                            <Globe className="h-4.5 w-4.5 text-primary" />
                        </div>
                        <div className="min-w-0">
                            <p className="text-sm font-medium">
                                Geocoding & maps usage
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Provider quota, cache hit-rate and lookup volume
                                behind the fleet maps.
                            </p>
                        </div>
                    </div>
                    <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                </Link>

                {/* Recorded activity for the selected period. */}
                <div
                    id="recorded-activity"
                    className="grid scroll-mt-4 grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4"
                >
                    <FleetStatCard
                        label="Total Trips"
                        value={trip_stats.total_trips ?? 0}
                        icon={Route}
                    />
                    <FleetStatCard
                        label="Distance"
                        value={formatDistance(
                            trip_stats.total_distance_km ?? 0,
                        )}
                        icon={Car}
                        color="blue"
                    />
                    <FleetStatCard
                        label="Total Hours"
                        value={`${(trip_stats.total_hours ?? 0).toLocaleString()} hrs`}
                        icon={Clock}
                        color="cyan"
                    />
                    <FleetStatCard
                        label="Fuel Cost"
                        value={formatCurrency(fuel_stats.total_cost ?? 0)}
                        icon={DollarSign}
                        color="amber"
                    />
                    <FleetStatCard
                        label="Fuel cost per km"
                        value={
                            costPerKm === null ? '—' : formatCurrency(costPerKm)
                        }
                        icon={DollarSign}
                    />
                </div>

                {/* Row 2: Fleet Utilization Gauge + Top Vehicles Bar Chart */}
                <div className="grid gap-4 lg:grid-cols-[1fr,2fr]">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">
                                Fleet Utilization
                            </CardTitle>
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
                            <CardTitle className="text-base">
                                Top Vehicles by Distance (km)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {topVehicleItems.length > 0 ? (
                                <HorizontalBarChart
                                    items={topVehicleItems}
                                    color={FLEET_COLORS.primary}
                                />
                            ) : (
                                <p className="py-4 text-center text-sm text-muted-foreground">
                                    No trip data available.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Row 3: Weekly Trips + Fuel by Vehicle */}
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">
                                Trip Distribution
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <MiniBarChart
                                data={weeklyTrips}
                                color={FLEET_COLORS.primary}
                                height={140}
                            />
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Top Vehicles by Fuel Cost
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {fuel_by_vehicle.length > 0 ? (
                                <HorizontalBarChart
                                    items={fuel_by_vehicle
                                        .slice(0, 8)
                                        .map((v) => ({
                                            label: v.vehicle,
                                            value: v.cost ?? 0,
                                            color: FLEET_COLORS.warning,
                                        }))}
                                    color={FLEET_COLORS.warning}
                                />
                            ) : (
                                <p className="py-4 text-center text-sm text-muted-foreground">
                                    No fuel data available.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Row 4: Maintenance & Compliance */}
                <div className="grid gap-4 lg:grid-cols-2">
                    {/* Maintenance Stats */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Maintenance Summary
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="rounded-md border p-3 text-center">
                                    <div className="text-2xl font-bold">
                                        {maintenance_stats.total_work_orders ??
                                            0}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        Total Work Orders
                                    </div>
                                </div>
                                <div className="rounded-md border p-3 text-center">
                                    <div className="text-2xl font-bold">
                                        {formatCurrency(
                                            maintenance_stats.total_cost ?? 0,
                                        )}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        Total Cost
                                    </div>
                                </div>
                                <div className="rounded-md border p-3 text-center">
                                    <div className="text-2xl font-bold text-primary">
                                        {maintenance_stats.completed_count ?? 0}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        Completed
                                    </div>
                                </div>
                                <div className="rounded-md border p-3 text-center">
                                    <div className="text-2xl font-bold text-status-warning">
                                        {maintenance_stats.open_count ?? 0}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        Open
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Compliance - Expiring Items */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                Upcoming Expirations (90 days)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {expiring_items.length > 0 ? (
                                <div className="space-y-2">
                                    {expiring_items.map((item, i) => {
                                        const id = item.vehicle_id ?? item.id;
                                        const name =
                                            item.vehicle_name ?? item.name;
                                        return (
                                            <div
                                                key={`${id}-${item.type}-${i}`}
                                                className="flex items-center justify-between rounded-md border p-2 text-sm"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <AlertTriangle
                                                        className={`h-4 w-4 ${item.status === 'expired' ? 'text-status-critical' : item.status === 'critical' ? 'text-status-warning' : 'text-status-warning'}`}
                                                    />
                                                    <div>
                                                        <Link
                                                            href={`/fleet-assets/assets/${id}`}
                                                            className="font-medium text-primary hover:underline"
                                                        >
                                                            {name}
                                                        </Link>
                                                        {item.asset_tag && (
                                                            <span className="ml-1 text-xs text-muted-foreground">
                                                                (
                                                                {item.asset_tag}
                                                                )
                                                            </span>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Badge
                                                        variant={
                                                            item.status ===
                                                            'expired'
                                                                ? 'destructive'
                                                                : 'outline'
                                                        }
                                                    >
                                                        {item.type?.replace(
                                                            '_',
                                                            ' ',
                                                        )}
                                                    </Badge>
                                                    <span className="text-xs text-muted-foreground">
                                                        {item.expires_at
                                                            ? formatDate(
                                                                  item.expires_at,
                                                              )
                                                            : 'Expiry not recorded'}
                                                    </span>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No upcoming expirations.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Row 5: Incidents */}
                {(incidentStats.total > 0 || incidentStats.open > 0) && (
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Shield className="h-4 w-4" />
                                Incidents (This Month)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 lg:grid-cols-[auto,1fr]">
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="rounded-md border p-3 text-center">
                                        <div className="text-2xl font-bold">
                                            {incidentStats.total}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            Total Incidents
                                        </div>
                                    </div>
                                    <div className="rounded-md border p-3 text-center">
                                        <div className="text-2xl font-bold text-status-warning">
                                            {incidentStats.open}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            Open / Investigating
                                        </div>
                                    </div>
                                    {Object.keys(incidentStats.by_severity)
                                        .length > 0 && (
                                        <div className="flex flex-wrap gap-1.5 sm:col-span-2">
                                            {Object.entries(
                                                incidentStats.by_severity,
                                            ).map(([severity, count]) => (
                                                <Badge
                                                    key={severity}
                                                    variant={
                                                        severity ===
                                                            'critical' ||
                                                        severity === 'major'
                                                            ? 'destructive'
                                                            : 'secondary'
                                                    }
                                                >
                                                    {severity}: {count}
                                                </Badge>
                                            ))}
                                        </div>
                                    )}
                                </div>
                                {Object.keys(incidentStats.by_type).length >
                                    0 && (
                                    <div>
                                        <HorizontalBarChart
                                            items={Object.entries(
                                                incidentStats.by_type,
                                            ).map(([type, count]) => ({
                                                label: type.replace(/_/g, ' '),
                                                value: count,
                                                color: FLEET_COLORS.danger,
                                            }))}
                                            color={FLEET_COLORS.danger}
                                        />
                                    </div>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}
                {/* ============================================================ */}
                {/*  DECISION REPORTS                                              */}
                {/* ============================================================ */}

                {/* Trends Over Time */}
                {trends.length > 0 && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <TrendingUp className="h-4 w-4" /> Trends (Last
                                6 Months)
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <div className="mb-2 text-xs font-semibold text-muted-foreground uppercase">
                                        Trips
                                    </div>
                                    <MiniBarChart
                                        data={trends.map((t) => ({
                                            label: t.month.slice(0, 3),
                                            value: t.trips,
                                        }))}
                                        color={FLEET_COLORS.primary}
                                    />
                                </div>
                                <div>
                                    <div className="mb-2 text-xs font-semibold text-muted-foreground uppercase">
                                        Distance (km)
                                    </div>
                                    <MiniBarChart
                                        data={trends.map((t) => ({
                                            label: t.month.slice(0, 3),
                                            value: t.distance_km,
                                        }))}
                                        color={FLEET_COLORS.secondary}
                                    />
                                </div>
                                <div>
                                    <div className="mb-2 text-xs font-semibold text-muted-foreground uppercase">
                                        Fuel Cost
                                    </div>
                                    <MiniBarChart
                                        data={trends.map((t) => ({
                                            label: t.month.slice(0, 3),
                                            value: t.fuel_cost,
                                        }))}
                                        color={FLEET_COLORS.warning}
                                    />
                                </div>
                                <div>
                                    <div className="mb-2 text-xs font-semibold text-muted-foreground uppercase">
                                        Incidents
                                    </div>
                                    <MiniBarChart
                                        data={trends.map((t) => ({
                                            label: t.month.slice(0, 3),
                                            value: t.incidents,
                                        }))}
                                        color={FLEET_COLORS.danger}
                                    />
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Vehicle Utilisation */}
                {vehicleUtil.length > 0 && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Car className="h-4 w-4" /> Vehicle Utilisation
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div
                                data-fleet-narrow-strategy="horizontal-scroll"
                                className="overflow-x-auto"
                            >
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="pr-3 pb-2 font-medium">
                                                Vehicle
                                            </th>
                                            <th className="pr-3 pb-2 text-right font-medium">
                                                Trips
                                            </th>
                                            <th className="pr-3 pb-2 text-right font-medium">
                                                km
                                            </th>
                                            <th className="pr-3 pb-2 text-right font-medium">
                                                Trips/wk
                                            </th>
                                            <th className="pr-3 pb-2 text-right font-medium">
                                                km/wk
                                            </th>
                                            <th className="pr-3 pb-2 text-right font-medium">
                                                Idle Days
                                            </th>
                                            <th className="pr-3 pb-2 text-right font-medium">
                                                Cost/km
                                            </th>
                                            <th className="pb-2 font-medium">
                                                Status
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {vehicleUtil.map((v) => (
                                            <tr
                                                key={v.id}
                                                className="border-b border-border/50 last:border-0"
                                            >
                                                <td className="py-2 pr-3">
                                                    <span className="font-medium">
                                                        {v.name}
                                                    </span>
                                                    <span className="ml-1 text-muted-foreground">
                                                        {v.asset_tag}
                                                    </span>
                                                </td>
                                                <td className="py-2 pr-3 text-right">
                                                    {v.trips}
                                                </td>
                                                <td className="py-2 pr-3 text-right">
                                                    {v.km}
                                                </td>
                                                <td className="py-2 pr-3 text-right">
                                                    {v.trips_per_week}
                                                </td>
                                                <td className="py-2 pr-3 text-right">
                                                    {v.km_per_week}
                                                </td>
                                                <td className="py-2 pr-3 text-right">
                                                    {v.idle_days ?? '—'}
                                                </td>
                                                <td className="py-2 pr-3 text-right">
                                                    {v.cost_per_km != null
                                                        ? formatCurrency(
                                                              v.cost_per_km,
                                                          )
                                                        : '—'}
                                                </td>
                                                <td className="py-2">
                                                    {v.flag === 'underused' && (
                                                        <Badge className="border-status-warning/30 bg-status-warning-bg text-[9px] text-status-warning dark:bg-status-warning-bg dark:text-status-warning">
                                                            <TrendingDown className="mr-0.5 h-2.5 w-2.5" />{' '}
                                                            Underused
                                                        </Badge>
                                                    )}
                                                    {v.flag === 'overused' && (
                                                        <Badge className="border-status-critical/30 bg-status-critical-bg text-[9px] text-status-critical dark:bg-status-critical-bg dark:text-status-critical">
                                                            <TrendingUp className="mr-0.5 h-2.5 w-2.5" />{' '}
                                                            Overused
                                                        </Badge>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Staff Risk */}
                {staffRisk.length > 0 && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <User className="h-4 w-4" /> Staff Driving Risk
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div
                                data-fleet-narrow-strategy="horizontal-scroll"
                                className="overflow-x-auto"
                            >
                                <table className="w-full text-xs">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="pr-3 pb-2 font-medium">
                                                Driver
                                            </th>
                                            <th className="pr-3 pb-2 text-right font-medium">
                                                Sessions
                                            </th>
                                            <th className="pr-3 pb-2 text-right font-medium">
                                                Incidents
                                            </th>
                                            <th className="pr-3 pb-2 text-right font-medium">
                                                Safety Score
                                            </th>
                                            <th className="pb-2 font-medium">
                                                Risk
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {staffRisk.map((d) => (
                                            <tr
                                                key={d.id}
                                                className="border-b border-border/50 last:border-0"
                                            >
                                                <td className="py-2 pr-3 font-medium">
                                                    {d.name}
                                                </td>
                                                <td className="py-2 pr-3 text-right">
                                                    {d.sessions}
                                                </td>
                                                <td className="py-2 pr-3 text-right">
                                                    <span
                                                        className={
                                                            d.incidents > 0
                                                                ? 'font-semibold text-status-critical'
                                                                : ''
                                                        }
                                                    >
                                                        {d.incidents}
                                                    </span>
                                                </td>
                                                <td className="py-2 pr-3 text-right">
                                                    {d.safety_score != null ? (
                                                        <span
                                                            className={
                                                                d.safety_score <
                                                                60
                                                                    ? 'font-semibold text-status-critical'
                                                                    : d.safety_score <
                                                                        80
                                                                      ? 'text-status-warning'
                                                                      : 'text-status-success'
                                                            }
                                                        >
                                                            {d.safety_score}/100
                                                        </span>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                                <td className="py-2">
                                                    <Badge
                                                        variant={
                                                            d.risk_flag ===
                                                            'high'
                                                                ? 'destructive'
                                                                : d.risk_flag ===
                                                                    'medium'
                                                                  ? 'outline'
                                                                  : 'secondary'
                                                        }
                                                        className="text-[9px]"
                                                    >
                                                        {d.risk_flag}
                                                    </Badge>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <p className="mt-2 text-[10px] text-muted-foreground">
                                Safety scores are based on vehicle telematics
                                and may reflect shared vehicle usage.
                            </p>
                        </CardContent>
                    </Card>
                )}

                {/* Resident Transport Demand */}
                {residentDemand.residents.length > 0 && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Users className="h-4 w-4" /> Resident Transport
                                Demand
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {Object.keys(residentDemand.purpose_breakdown)
                                .length > 0 && (
                                <div>
                                    <div className="mb-2 text-xs font-semibold text-muted-foreground uppercase">
                                        By Purpose
                                    </div>
                                    <HorizontalBarChart
                                        items={Object.entries(
                                            residentDemand.purpose_breakdown,
                                        ).map(([label, value]) => ({
                                            label: label.replace(/_/g, ' '),
                                            value: value as number,
                                            color: FLEET_COLORS.primary,
                                        }))}
                                        color={FLEET_COLORS.primary}
                                    />
                                </div>
                            )}
                            <div>
                                <div className="mb-2 text-xs font-semibold text-muted-foreground uppercase">
                                    Top Residents by Transport Frequency
                                </div>
                                <div
                                    data-fleet-narrow-strategy="horizontal-scroll"
                                    className="overflow-x-auto"
                                >
                                    <table className="w-full text-xs">
                                        <thead>
                                            <tr className="border-b text-left text-muted-foreground">
                                                <th className="pr-3 pb-2 font-medium">
                                                    Resident
                                                </th>
                                                <th className="pr-3 pb-2 text-right font-medium">
                                                    Total
                                                </th>
                                                <th className="pr-3 pb-2 text-right font-medium">
                                                    Per Week
                                                </th>
                                                <th className="pb-2 text-right font-medium">
                                                    Last Transport
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {residentDemand.residents.map(
                                                (r) => (
                                                    <tr
                                                        key={r.id}
                                                        className="border-b border-border/50 last:border-0"
                                                    >
                                                        <td className="py-2 pr-3 font-medium">
                                                            {r.name}
                                                        </td>
                                                        <td className="py-2 pr-3 text-right">
                                                            {r.transport_count}
                                                        </td>
                                                        <td className="py-2 pr-3 text-right">
                                                            {r.per_week}
                                                        </td>
                                                        <td className="py-2 text-right text-muted-foreground">
                                                            {r.last_transport
                                                                ? formatDate(
                                                                      r.last_transport,
                                                                  )
                                                                : '—'}
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </PageShell>
        </AppLayout>
    );
}
