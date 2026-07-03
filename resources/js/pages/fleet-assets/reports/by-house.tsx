import { FleetEmptyState } from '@/components/fleet-empty-state';
import { FleetStatCard } from '@/components/fleet-stat-card';
import { HorizontalBarChart, FLEET_COLORS } from '@/components/fleet-charts';
import PageShell from '@/components/page-shell';
import { FleetCompactHero } from '@/pages/fleet-assets/components/fleet-compact-hero';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Building2, Calendar, Car, Fuel, Route, Truck, Users } from 'lucide-react';
import { formatCurrency, formatDate, formatDistance } from '@/lib/fleet-utils';


type HouseSummary = {
    id: number; name: string; vehicles_count: number; trips_this_month: number;
    distance_this_month: number; fuel_cost_this_month: number; transport_logs: number;
};

type VehicleDetail = {
    id: number; name: string; asset_tag?: string; trips: number;
    distance_km: number; fuel_cost: number; avg_trip_duration_minutes: number; last_used: string | null;
};

type Props = {
    houses: Array<{ id: number; name: string }>;
    selected_house_id: number | null;
    selected_month: string;
    available_months: Array<{ value: string; label: string }>;
    house_summaries: HouseSummary[];
    vehicle_details: VehicleDetail[];
};

export default function ReportByHouse({
    houses: rawHouses, selected_house_id, selected_month, available_months, house_summaries: rawSummaries, vehicle_details: rawDetails,
}: Props) {
    const houses = rawHouses ?? [];
    const summaries = rawSummaries ?? [];
    const details = rawDetails ?? [];
    const months = available_months ?? [];
    const currentMonth = selected_month ?? new Date().toISOString().slice(0, 7);
    const selectedSummary = summaries.find((s) => s.id === selected_house_id);

    const handleHouseChange = (value: string) => {
        router.get('/fleet-assets/reports/by-house', { house_id: value === 'all' ? undefined : value, month: currentMonth }, { preserveState: true });
    };

    const handleMonthChange = (value: string) => {
        router.get('/fleet-assets/reports/by-house', { house_id: selected_house_id ?? undefined, month: value }, { preserveState: true });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Reports', href: '/fleet-assets/reports' },
                { title: 'Usage by House', href: '/fleet-assets/reports/by-house' },
            ]}
        >
            <Head title="Usage by House" />
            <PageShell>
                <FleetCompactHero
                    pill="Fleet reports · usage by house"
                    title="Vehicle Usage by House"
                    backHref="/fleet-assets/reports"
                    backLabel="Reports"
                />
                <p className="text-sm text-muted-foreground">
                    Compare vehicle usage, costs, and transport activity across houses.
                </p>

                {/* Month & House Selectors */}
                <div className="flex flex-wrap items-center gap-4">
                    <div className="flex items-center gap-2">
                        <Calendar className="h-5 w-5 text-muted-foreground" />
                        <Select value={currentMonth} onValueChange={handleMonthChange}>
                            <SelectTrigger className="w-52"><SelectValue placeholder="Select month" /></SelectTrigger>
                            <SelectContent>
                                {months.map((m) => (<SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex items-center gap-2">
                        <Building2 className="h-5 w-5 text-muted-foreground" />
                        <Select value={selected_house_id ? String(selected_house_id) : 'all'} onValueChange={handleHouseChange}>
                            <SelectTrigger className="w-64"><SelectValue placeholder="Select a house" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Houses (Comparison)</SelectItem>
                                {houses.map((h) => (<SelectItem key={h.id} value={String(h.id)}>{h.name}</SelectItem>))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {/* Selected House Dark KPI Cards */}
                {selectedSummary && (
                    <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5">
                        <FleetStatCard label="VEHICLES" value={selectedSummary.vehicles_count} icon={Truck} subtitle="Assigned to house" />
                        <FleetStatCard label="TRIPS (MTD)" value={selectedSummary.trips_this_month} icon={Route} subtitle="This month" />
                        <FleetStatCard label="DISTANCE (MTD)" value={formatDistance(selectedSummary.distance_this_month)} icon={Car} subtitle="Kilometres driven" />
                        <FleetStatCard label="FUEL COST (MTD)" value={formatCurrency(selectedSummary.fuel_cost_this_month)} icon={Fuel} subtitle="Fuel expenditure" />
                        <FleetStatCard label="TRANSPORTS" value={selectedSummary.transport_logs} icon={Users} subtitle="Transport logs" />
                    </div>
                )}

                {/* Vehicle Details Table */}
                {selected_house_id && details.length > 0 && (
                    <Card>
                        <CardHeader><CardTitle className="text-base">Vehicles at {selectedSummary?.name ?? 'Selected House'}</CardTitle></CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="bg-muted/50 text-xs uppercase tracking-wider text-muted-foreground">
                                            <th className="px-3 py-2 text-left font-medium text-muted-foreground">Vehicle</th>
                                            <th className="px-3 py-2 text-right font-medium text-muted-foreground">Trips</th>
                                            <th className="px-3 py-2 text-right font-medium text-muted-foreground">Distance (km)</th>
                                            <th className="px-3 py-2 text-right font-medium text-muted-foreground">Fuel Cost</th>
                                            <th className="px-3 py-2 text-right font-medium text-muted-foreground">Avg Duration</th>
                                            <th className="px-3 py-2 text-right font-medium text-muted-foreground">Last Used</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {details.map((v) => (
                                            <tr key={v.id} className="border-b last:border-b-0 transition-colors hover:bg-muted/30 transition-colors">
                                                <td className="px-3 py-2">
                                                    <div className="flex items-center gap-2"><Car className="h-3.5 w-3.5 text-muted-foreground" /><span className="font-medium">{v.name}</span>{v.asset_tag && <span className="text-xs text-muted-foreground">({v.asset_tag})</span>}</div>
                                                </td>
                                                <td className="px-3 py-2 text-right">{v.trips}</td>
                                                <td className="px-3 py-2 text-right">{v.distance_km.toLocaleString()}</td>
                                                <td className="px-3 py-2 text-right">{formatCurrency(v.fuel_cost)}</td>
                                                <td className="px-3 py-2 text-right">{v.avg_trip_duration_minutes > 0 ? `${v.avg_trip_duration_minutes} min` : '---'}</td>
                                                <td className="px-3 py-2 text-right text-muted-foreground">{v.last_used ? formatDate(v.last_used) : 'Never'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {selected_house_id && details.length === 0 && (
                    <FleetEmptyState icon={Truck} title="No vehicles assigned" description="No vehicles are assigned to this house. Assign vehicles via the home site field." />
                )}

                {/* Cross-House Comparison with HorizontalBarChart */}
                {!selected_house_id && summaries.length > 0 && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <Card>
                            <CardHeader><CardTitle className="text-sm">Trips by House</CardTitle></CardHeader>
                            <CardContent>
                                <HorizontalBarChart
                                    items={summaries.map((s) => ({ label: s.name, value: s.trips_this_month }))}
                                    color={FLEET_COLORS.primary}
                                />
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader><CardTitle className="text-sm">Distance by House (km)</CardTitle></CardHeader>
                            <CardContent>
                                <HorizontalBarChart
                                    items={summaries.map((s) => ({ label: s.name, value: s.distance_this_month }))}
                                    color={FLEET_COLORS.accent}
                                />
                            </CardContent>
                        </Card>
                    </div>
                )}

                {!selected_house_id && summaries.length > 0 && (
                    <Card>
                        <CardHeader><CardTitle className="text-base">Summary Table</CardTitle></CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="bg-muted/50 text-xs uppercase tracking-wider text-muted-foreground">
                                            <th className="px-3 py-2 text-left font-medium text-muted-foreground">House</th>
                                            <th className="px-3 py-2 text-right font-medium text-muted-foreground">Vehicles</th>
                                            <th className="px-3 py-2 text-right font-medium text-muted-foreground">Trips</th>
                                            <th className="px-3 py-2 text-right font-medium text-muted-foreground">Distance (km)</th>
                                            <th className="px-3 py-2 text-right font-medium text-muted-foreground">Fuel Cost</th>
                                            <th className="px-3 py-2 text-right font-medium text-muted-foreground">Transport Logs</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {summaries.map((s) => (
                                            <tr key={s.id} className="border-b last:border-b-0 cursor-pointer transition-colors hover:bg-muted/30 transition-colors" onClick={() => handleHouseChange(String(s.id))}>
                                                <td className="px-3 py-2"><div className="flex items-center gap-2"><Building2 className="h-3.5 w-3.5 text-muted-foreground" /><span className="font-medium">{s.name}</span></div></td>
                                                <td className="px-3 py-2 text-right">{s.vehicles_count}</td>
                                                <td className="px-3 py-2 text-right">{s.trips_this_month}</td>
                                                <td className="px-3 py-2 text-right">{s.distance_this_month.toLocaleString()}</td>
                                                <td className="px-3 py-2 text-right">{formatCurrency(s.fuel_cost_this_month)}</td>
                                                <td className="px-3 py-2 text-right">{s.transport_logs}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {summaries.length === 0 && (
                    <FleetEmptyState icon={Building2} title="No houses configured" description="Add houses in the Sites section to see vehicle usage by house." />
                )}
            </PageShell>
        </AppLayout>
    );
}
