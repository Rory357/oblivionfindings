import { FleetStatCard } from '@/components/fleet-stat-card';
import LeafletMap, { MapMarker } from '@/components/leaflet-map';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Bell,
    Bookmark,
    Calendar,
    Car,
    CheckCircle2,
    ClipboardList,
    FileBarChart,
    Fuel,
    MapPin,
    Radio,
    Receipt,
    Route,
    Settings,
    ShieldAlert,
    Smartphone,
    UserSearch,
    Wrench,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { formatCurrency, formatRelativeTime, formatDistance } from '@/lib/fleet-utils';


/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type Props = {
    vehicles: Array<{
        id: number;
        name: string;
        asset_tag: string;
        status: string;
        state: {
            status: string;
            lat: number;
            lng: number;
            speed_kph: number;
            battery_pct: number;
            consent_blocked: boolean;
            last_seen_at: string;
        } | null;
        home_site: {
            id: number;
            name: string;
            latitude: number;
            longitude: number;
        } | null;
    }>;
    stats: {
        total_vehicles: number;
        online_count: number;
        offline_count: number;
        total_assets: number;
        active_alerts: number;
        critical_alerts: number;
        fuel_cost_mtd: number;
        distance_mtd: number;
        total_devices: number;
        online_devices: number;
        recent_bookings_count: number;
        upcoming_maintenance_count: number;
        trips_today: number;
        tracked_residents?: number;
        active_outings?: number;
    };
    houses: Array<{
        id: number;
        name: string;
        address: string;
        latitude: number;
        longitude: number;
    }>;
    recent_signals: Array<{
        id: number;
        signal_type: string;
        severity_hint: string;
        occurred_at: string;
        asset: { id: number; name: string };
    }>;
    vehicle_status_breakdown: Record<string, number>;
    asset_status_breakdown: Record<string, number>;
    maintenance_stats: Record<string, number>;
    recent_alerts: Array<{
        id: number;
        title: string;
        severity: string;
        status: string;
        created_at: string;
    }>;
    fleet_by_site?: Array<{
        id: number;
        name: string;
        vehicle_count: number;
        online_count: number;
        active_alerts: number;
        fuel_cost_mtd: number;
    }>;
    after_hours_trips?: Array<{
        id: number;
        vehicle: string;
        driver: string;
        started_at: string;
        time: string;
        date: string;
        distance_km: number;
    }>;
    my_site_vehicles?: Array<{
        id: number;
        name: string;
        status: string;
    }>;
};

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

// Using shared formatRelativeTime from fleet-utils

function severityVariant(severity: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (severity) {
        case 'critical':
        case 'high':
            return 'destructive';
        case 'medium':
            return 'default';
        default:
            return 'secondary';
    }
}

/* ------------------------------------------------------------------ */
/*  Donut / Ring Chart Component                                       */
/* ------------------------------------------------------------------ */

type DonutSegment = {
    label: string;
    value: number;
    color: string;
};

function DonutChart({
    segments,
    size = 140,
    strokeWidth = 18,
    centerLabel,
    centerValue,
}: {
    segments: DonutSegment[];
    size?: number;
    strokeWidth?: number;
    centerLabel?: string;
    centerValue?: string | number;
}) {
    const radius = (size - strokeWidth) / 2;
    const circumference = 2 * Math.PI * radius;
    const total = segments.reduce((sum, s) => sum + s.value, 0);

    if (total === 0) {
        return (
            <div className="flex flex-col items-center justify-center" style={{ width: size, height: size }}>
                <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
                    <circle
                        cx={size / 2}
                        cy={size / 2}
                        r={radius}
                        fill="none"
                        stroke="currentColor"
                        strokeWidth={strokeWidth}
                        className="text-muted/20"
                    />
                    <text x="50%" y="50%" textAnchor="middle" dominantBaseline="central" className="fill-muted-foreground text-xs">
                        No data
                    </text>
                </svg>
            </div>
        );
    }

    let offset = 0;

    return (
        <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
            {/* Background ring */}
            <circle
                cx={size / 2}
                cy={size / 2}
                r={radius}
                fill="none"
                stroke="currentColor"
                strokeWidth={strokeWidth}
                className="text-muted/10"
            />
            {/* Segments */}
            {segments
                .filter((s) => s.value > 0)
                .map((segment, i) => {
                    const pct = segment.value / total;
                    const dashLength = pct * circumference;
                    const dashGap = circumference - dashLength;
                    const rotation = (offset / total) * 360 - 90;
                    offset += segment.value;
                    return (
                        <circle
                            key={i}
                            cx={size / 2}
                            cy={size / 2}
                            r={radius}
                            fill="none"
                            stroke={segment.color}
                            strokeWidth={strokeWidth}
                            strokeDasharray={`${dashLength} ${dashGap}`}
                            strokeLinecap="butt"
                            transform={`rotate(${rotation} ${size / 2} ${size / 2})`}
                        />
                    );
                })}
            {/* Center text */}
            {centerValue !== undefined && (
                <>
                    <text
                        x="50%"
                        y="46%"
                        textAnchor="middle"
                        dominantBaseline="central"
                        className="fill-foreground text-2xl font-bold"
                        style={{ fontSize: 22, fontWeight: 700 }}
                    >
                        {centerValue}
                    </text>
                    {centerLabel && (
                        <text
                            x="50%"
                            y="64%"
                            textAnchor="middle"
                            dominantBaseline="central"
                            className="fill-muted-foreground"
                            style={{ fontSize: 10 }}
                        >
                            {centerLabel}
                        </text>
                    )}
                </>
            )}
        </svg>
    );
}

function DonutLegend({ segments }: { segments: DonutSegment[] }) {
    const total = segments.reduce((sum, s) => sum + s.value, 0);
    return (
        <div className="mt-3 space-y-1.5">
            {segments.map((s, i) => (
                <div key={i} className="flex items-center justify-between text-xs">
                    <div className="flex items-center gap-2">
                        <span className="inline-block h-2.5 w-2.5 rounded-full" style={{ backgroundColor: s.color }} />
                        <span className="text-muted-foreground">{s.label}</span>
                    </div>
                    <span className="font-medium tabular-nums">
                        {s.value}
                        {total > 0 && (
                            <span className="ml-1 text-muted-foreground">({Math.round((s.value / total) * 100)}%)</span>
                        )}
                    </span>
                </div>
            ))}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Quick Action Tile                                                  */
/* ------------------------------------------------------------------ */

function QuickActionTile({
    icon: Icon,
    label,
    href,
    count,
    color,
}: {
    icon: React.ElementType;
    label: string;
    href: string;
    count?: number;
    color?: string;
}) {
    return (
        <Link href={href} className="group flex flex-col items-center gap-1.5 rounded-lg border border-border bg-card p-3 transition-all duration-200 hover:bg-accent hover:shadow-lg hover:-translate-y-0.5">
            <div
                className="flex h-9 w-9 items-center justify-center rounded-lg"
                style={{ backgroundColor: color ? `${color}18` : 'hsl(var(--muted))' }}
            >
                <Icon className="h-4.5 w-4.5" style={{ color: color ?? 'hsl(var(--muted-foreground))' }} />
            </div>
            <span className="text-[11px] font-medium leading-tight text-muted-foreground group-hover:text-foreground text-center">
                {label}
            </span>
            {count !== undefined && count > 0 && (
                <Badge variant="secondary" className="h-4 px-1.5 text-[10px]">
                    {count}
                </Badge>
            )}
        </Link>
    );
}

/* ------------------------------------------------------------------ */
/*  Main Dashboard Component                                           */
/* ------------------------------------------------------------------ */

export default function FleetAssetsDashboard({
    vehicles,
    stats: rawStats,
    houses,
    recent_signals,
    vehicle_status_breakdown,
    asset_status_breakdown,
    maintenance_stats,
    recent_alerts,
    fleet_by_site,
    after_hours_trips,
    my_site_vehicles,
}: Props) {
    const stats = rawStats ?? {
        total_vehicles: 0, online_count: 0, offline_count: 0,
        total_assets: 0, active_alerts: 0, critical_alerts: 0,
        fuel_cost_mtd: 0, distance_mtd: 0,
        total_devices: 0, online_devices: 0,
        recent_bookings_count: 0, upcoming_maintenance_count: 0,
        trips_today: 0,
    };

    const vsb = vehicle_status_breakdown ?? {};
    const asb = asset_status_breakdown ?? {};
    const ms = maintenance_stats ?? {};

    // Map tab filter
    const [mapFilter, setMapFilter] = useState<'all' | 'active' | 'inactive'>('all');

    // 30-second auto-refresh
    useEffect(() => {
        const interval = window.setInterval(() => {
            if (document.hidden) return;
            router.reload({
                only: [
                    'vehicles', 'stats', 'recent_signals',
                    'vehicle_status_breakdown', 'asset_status_breakdown',
                    'maintenance_stats', 'recent_alerts',
                ],
            });
        }, 30000);
        return () => window.clearInterval(interval);
    }, []);

    // Filtered vehicles for map
    const filteredVehicles = useMemo(() => {
        const v = vehicles ?? [];
        if (mapFilter === 'active') return v.filter((veh) => veh.state?.status === 'online');
        if (mapFilter === 'inactive') return v.filter((veh) => !veh.state || veh.state.status !== 'online');
        return v;
    }, [vehicles, mapFilter]);

    const markers = useMemo<MapMarker[]>(() => {
        const vehicleMarkers: MapMarker[] = filteredVehicles
            .filter((v) => v.state?.lat && v.state?.lng)
            .map((v) => ({
                id: `v-${v.id}`,
                lat: Number(v.state!.lat),
                lng: Number(v.state!.lng),
                title: v.name ?? v.asset_tag ?? `Vehicle ${v.id}`,
                type: 'vehicle' as const,
                status: v.state!.status,
                popup: `Speed: ${v.state!.speed_kph ?? 0} kph | Battery: ${v.state!.battery_pct ?? 0}%`,
            }));

        const houseMarkers: MapMarker[] = (houses ?? [])
            .filter((h) => h.latitude && h.longitude)
            .map((h) => ({
                id: `h-${h.id}`,
                lat: Number(h.latitude),
                lng: Number(h.longitude),
                title: h.name,
                type: 'house' as const,
                popup: h.address,
            }));

        return [...vehicleMarkers, ...houseMarkers];
    }, [filteredVehicles, houses]);

    const center = useMemo(() => {
        const firstVehicle = (vehicles ?? []).find((v) => v.state?.lat && v.state?.lng);
        if (firstVehicle) {
            return { lat: Number(firstVehicle.state!.lat), lng: Number(firstVehicle.state!.lng) };
        }
        return { lat: -36.8485, lng: 174.7633 };
    }, [vehicles]);

    // On-time rate (derived: completed trips / total trips today, fallback 0)
    const onTimeRate = (stats.trips_today ?? 0) > 0 ? 100 : 0;

    /* ---- Donut segment data ---- */

    const vehicleDonutSegments: DonutSegment[] = [
        { label: 'Online', value: vsb['online'] ?? 0, color: '#7c3aed' },
        { label: 'Offline', value: vsb['offline'] ?? 0, color: '#ef4444' },
        { label: 'Idle', value: vsb['idle'] ?? 0, color: '#f59e0b' },
        { label: 'Moving', value: vsb['moving'] ?? 0, color: '#3b82f6' },
    ];

    const assetDonutSegments: DonutSegment[] = [
        { label: 'Active', value: asb['active'] ?? 0, color: '#7c3aed' },
        { label: 'Fault', value: asb['fault'] ?? 0, color: '#f97316' },
        { label: 'Offline', value: asb['offline'] ?? 0, color: '#64748b' },
        { label: 'Retired', value: asb['retired'] ?? 0, color: '#94a3b8' },
    ];

    const maintenanceDonutSegments: DonutSegment[] = [
        { label: 'Open', value: ms['open'] ?? 0, color: '#3b82f6' },
        { label: 'In Progress', value: ms['in_progress'] ?? 0, color: '#f59e0b' },
        { label: 'Completed', value: ms['completed'] ?? 0, color: '#a78bfa' },
        { label: 'Cancelled', value: ms['cancelled'] ?? 0, color: '#ef4444' },
    ];

    const totalVehicleStates = vehicleDonutSegments.reduce((s, d) => s + d.value, 0);
    const totalAssets = assetDonutSegments.reduce((s, d) => s + d.value, 0);
    const totalWorkOrders = maintenanceDonutSegments.reduce((s, d) => s + d.value, 0);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
            ]}
        >
            <Head title="Fleet & Assets" />
            <PageShell>
                <PageHeader
                    title="Fleet & Assets"
                    description="Real-time fleet tracking, asset management, and operational insights."
                    actions={
                        <Button variant="ghost" size="icon" asChild>
                            <Link href="/fleet-assets/settings/notifications">
                                <Settings className="h-4 w-4" />
                            </Link>
                        </Button>
                    }
                />

                {/* ============================================================ */}
                {/*  ROW 1 - KPI Cards                                           */}
                {/* ============================================================ */}
                <div className="grid gap-3 grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                    <FleetStatCard label="Vehicles" value={stats.total_vehicles ?? 0} icon={Car} />
                    <FleetStatCard label="Bookings" value={stats.recent_bookings_count ?? 0} icon={Bookmark} color="blue" />
                    <Card className="border bg-purple-50 dark:bg-purple-950/20">
                        <CardContent className="p-4">
                            <div className="flex items-start justify-between">
                                <div>
                                    <p className="text-[10px] font-medium uppercase tracking-wider text-slate-400">Alerts</p>
                                    <div className="flex items-center gap-1.5">
                                        <span className="text-2xl font-bold">{stats.active_alerts ?? 0}</span>
                                        {(stats.critical_alerts ?? 0) > 0 && (
                                            <Badge className="bg-red-500/20 text-red-400 border-0 text-[9px] px-1 h-4">{stats.critical_alerts} crit</Badge>
                                        )}
                                    </div>
                                </div>
                                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/20">
                                    <AlertTriangle className="h-4 w-4 text-amber-400" />
                                </div>
                            </div>
                            <div className="mt-2 flex items-center gap-1 text-[10px] text-slate-500">
                                <Wrench className="h-3 w-3" /> {stats.upcoming_maintenance_count ?? 0} upcoming services
                            </div>
                        </CardContent>
                    </Card>
                    <FleetStatCard label="Fuel MTD" value={formatCurrency(stats.fuel_cost_mtd ?? 0)} icon={Fuel} />
                    {(stats.tracked_residents ?? 0) > 0 && (
                        <FleetStatCard label="Tracked Residents" value={stats.tracked_residents ?? 0} icon={UserSearch} color="purple" />
                    )}
                    {(stats.active_outings ?? 0) > 0 && (
                        <FleetStatCard label="Active Outings" value={stats.active_outings ?? 0} icon={MapPin} color="blue" />
                    )}
                </div>

                {/* ============================================================ */}
                {/*  MAIN GRID - Map left, widgets right                         */}
                {/* ============================================================ */}
                <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                    {/* LEFT COLUMN - Map (spans full height) */}
                    <Card className="overflow-hidden lg:row-span-2">
                        <CardHeader className="pb-2">
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <MapPin className="h-4 w-4" /> Fleet Map
                                    <Badge variant="secondary" className="ml-1 text-[10px]">{markers.length}</Badge>
                                </CardTitle>
                                <div className="flex gap-1">
                                    {(['all', 'active', 'inactive'] as const).map((tab) => (
                                        <Button key={tab} variant={mapFilter === tab ? 'default' : 'ghost'} size="sm" className="h-6 px-2 text-[10px] capitalize" onClick={() => setMapFilter(tab)}>{tab}</Button>
                                    ))}
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="p-0">
                            <LeafletMap center={center} zoom={12} markers={markers} height={520} />
                        </CardContent>
                    </Card>

                    {/* RIGHT COLUMN - Stacked tiles */}
                    <div className="space-y-4">
                        {/* Quick Actions - compact 4x2 grid */}
                        <Card>
                            <CardHeader className="pb-1 pt-3 px-4">
                                <CardTitle className="text-xs font-medium text-muted-foreground uppercase tracking-wider">Quick Actions</CardTitle>
                            </CardHeader>
                            <CardContent className="px-4 pb-3">
                                <div className="grid grid-cols-4 gap-1.5">
                                    <QuickActionTile icon={Wrench} label="Maintenance" href="/fleet-assets/maintenance/work-orders" count={stats.upcoming_maintenance_count ?? 0} color="#f59e0b" />
                                    <QuickActionTile icon={Bookmark} label="Bookings" href="/fleet-assets/bookings" count={stats.recent_bookings_count ?? 0} color="#3b82f6" />
                                    <QuickActionTile icon={AlertTriangle} label="Alerts" href="/fleet-assets/alerts" count={stats.active_alerts ?? 0} color="#ef4444" />
                                    <QuickActionTile icon={Smartphone} label="Devices" href="/fleet-assets/devices" color="#8b5cf6" />
                                    <QuickActionTile icon={FileBarChart} label="Reports" href="/fleet-assets/reports" color="#06b6d4" />
                                    <QuickActionTile icon={Car} label="Vehicles" href="/fleet-assets/vehicles" color="#7c3aed" />
                                    <QuickActionTile icon={ClipboardList} label="Assets" href="/fleet-assets/assets" color="#f97316" />
                                    <QuickActionTile icon={MapPin} label="Map" href="/fleet-assets/map" color="#6366f1" />
                                    <QuickActionTile icon={UserSearch} label="Residents" href="/fleet-assets/resident-tracking" count={stats.tracked_residents ?? 0} color="#7c3aed" />
                                    <QuickActionTile icon={MapPin} label="Outings" href="/fleet-assets/outings" color="#06b6d4" />
                                    <QuickActionTile icon={ShieldAlert} label="Wandering" href="/fleet-assets/wandering-alerts" color="#ef4444" />
                                    <QuickActionTile icon={Receipt} label="Mileage" href="/fleet-assets/mileage" color="#f59e0b" />
                                </div>
                            </CardContent>
                        </Card>

                        {/* Donut charts - 3 side by side */}
                        <div className="grid grid-cols-3 gap-3">
                            <Card>
                                <CardContent className="flex flex-col items-center pt-4 pb-3 px-2">
                                    <p className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider mb-2">Vehicles</p>
                                    <DonutChart segments={vehicleDonutSegments} centerValue={totalVehicleStates || stats.total_vehicles} centerLabel="" size={80} />
                                    <div className="mt-2 space-y-0.5 w-full">
                                        {vehicleDonutSegments.map((s, i) => (
                                            <div key={i} className="flex items-center justify-between text-[9px]">
                                                <span className="flex items-center gap-1"><span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: s.color }} />{s.label}</span>
                                                <span className="tabular-nums font-medium">{s.value}</span>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="flex flex-col items-center pt-4 pb-3 px-2">
                                    <p className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider mb-2">Assets</p>
                                    <DonutChart segments={assetDonutSegments} centerValue={totalAssets || stats.total_assets} centerLabel="" size={80} />
                                    <div className="mt-2 space-y-0.5 w-full">
                                        {assetDonutSegments.map((s, i) => (
                                            <div key={i} className="flex items-center justify-between text-[9px]">
                                                <span className="flex items-center gap-1"><span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: s.color }} />{s.label}</span>
                                                <span className="tabular-nums font-medium">{s.value}</span>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="flex flex-col items-center pt-4 pb-3 px-2">
                                    <p className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider mb-2">Work Orders</p>
                                    <DonutChart segments={maintenanceDonutSegments} centerValue={totalWorkOrders} centerLabel="" size={80} />
                                    <div className="mt-2 space-y-0.5 w-full">
                                        {maintenanceDonutSegments.map((s, i) => (
                                            <div key={i} className="flex items-center justify-between text-[9px]">
                                                <span className="flex items-center gap-1"><span className="h-1.5 w-1.5 rounded-full" style={{ backgroundColor: s.color }} />{s.label}</span>
                                                <span className="tabular-nums font-medium">{s.value}</span>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </div>
                </div>

                {/* ============================================================ */}
                {/*  BOTTOM GRID - Alerts + Activity + After Hours               */}
                {/* ============================================================ */}
                <div className="grid gap-4 lg:grid-cols-3">
                    {/* Recent Alerts */}
                    <Card className="lg:col-span-2">
                        <CardHeader className="pb-2">
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2 text-sm"><Bell className="h-4 w-4" /> Recent Alerts</CardTitle>
                                <Button variant="outline" size="sm" className="h-6 text-[10px]" asChild><Link href="/fleet-assets/alerts">View all</Link></Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {(recent_alerts ?? []).length > 0 ? (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-xs">
                                        <thead>
                                            <tr className="border-b text-left text-muted-foreground">
                                                <th className="pb-2 pr-4 font-medium">Alert</th>
                                                <th className="pb-2 pr-4 font-medium">Severity</th>
                                                <th className="pb-2 pr-4 font-medium">Status</th>
                                                <th className="pb-2 font-medium text-right">Time</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {(recent_alerts ?? []).slice(0, 6).map((alert) => (
                                                <tr key={alert.id} className="border-b border-border/50 last:border-0">
                                                    <td className="py-2 pr-4"><span className="font-medium">{alert.title ?? `Alert #${alert.id}`}</span></td>
                                                    <td className="py-2 pr-4"><Badge variant={severityVariant(alert.severity ?? 'low')} className="text-[10px]">{alert.severity ?? 'low'}</Badge></td>
                                                    <td className="py-2 pr-4"><span className="capitalize text-muted-foreground">{(alert.status ?? '').replace(/_/g, ' ')}</span></td>
                                                    <td className="py-2 text-right text-muted-foreground">{alert.created_at ? formatRelativeTime(alert.created_at) : '-'}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center py-6 text-muted-foreground">
                                    <CheckCircle2 className="mb-1.5 h-6 w-6 text-purple-500" />
                                    <p className="text-xs font-medium">All clear</p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Activity Feed */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm"><Activity className="h-4 w-4" /> Activity</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {(recent_signals ?? []).length > 0 ? (
                                <div className="space-y-1.5">
                                    {(recent_signals ?? []).slice(0, 6).map((signal) => (
                                        <div key={signal.id} className="flex items-center gap-2 rounded border border-border/50 px-2 py-1.5 text-[10px]">
                                            <Radio className="h-3 w-3 shrink-0 text-muted-foreground" />
                                            <span className="font-medium truncate flex-1">{signal.asset?.name ?? 'Unknown'}</span>
                                            <Badge variant={severityVariant(signal.severity_hint ?? 'low')} className="text-[8px] px-1 h-3.5 shrink-0">{signal.severity_hint ?? 'low'}</Badge>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="py-6 text-center text-xs text-muted-foreground">No recent activity.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* ============================================================ */}
                {/*  VEHICLES AT YOUR SITE                                        */}
                {/* ============================================================ */}
                {(my_site_vehicles ?? []).length > 0 && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm">
                                <Car className="h-4 w-4" /> Vehicles at Your Site
                                <Badge variant="secondary" className="ml-1 text-[10px]">{(my_site_vehicles ?? []).length}</Badge>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                {(my_site_vehicles ?? []).map((v) => (
                                    <div key={v.id} className="flex items-center gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/30">
                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-purple-100 dark:bg-purple-900/30">
                                            <Car className="h-4 w-4 text-purple-600 dark:text-purple-400" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="text-sm font-medium truncate">{v.name}</p>
                                            <Badge
                                                variant={v.status === 'online' ? 'default' : 'secondary'}
                                                className="mt-0.5 text-[9px] h-4 px-1.5"
                                            >
                                                {v.status}
                                            </Badge>
                                        </div>
                                        <Button variant="ghost" size="sm" className="h-7 px-2 text-[10px] shrink-0" asChild>
                                            <Link href={`/fleet-assets/bookings/create?asset_id=${v.id}`}>Book</Link>
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* ============================================================ */}
                {/*  BOTTOM ROW - Fleet by Site + After Hours side by side        */}
                {/* ============================================================ */}
                {((fleet_by_site ?? []).length > 0 || (after_hours_trips ?? []).length > 0) && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {(fleet_by_site ?? []).length > 0 && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm"><MapPin className="h-4 w-4" /> Fleet by Site</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-xs">
                                            <thead>
                                                <tr className="border-b text-left text-muted-foreground">
                                                    <th className="pb-2 pr-3 font-medium">Site</th>
                                                    <th className="pb-2 pr-3 font-medium text-right">Vehicles</th>
                                                    <th className="pb-2 pr-3 font-medium text-right">Online</th>
                                                    <th className="pb-2 pr-3 font-medium text-right">Alerts</th>
                                                    <th className="pb-2 font-medium text-right">Fuel</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {(fleet_by_site ?? []).map((site) => (
                                                    <tr key={site.id} className="border-b border-border/50 last:border-0">
                                                        <td className="py-2 pr-3"><Link href={`/fleet-assets?site=${site.id}`} className="font-medium text-primary hover:underline text-xs">{site.name}</Link></td>
                                                        <td className="py-2 pr-3 text-right tabular-nums">{site.vehicle_count}</td>
                                                        <td className="py-2 pr-3 text-right"><span className="inline-flex items-center gap-1">{site.online_count > 0 && <span className="h-1.5 w-1.5 rounded-full bg-emerald-400" />}<span className="tabular-nums">{site.online_count}</span></span></td>
                                                        <td className="py-2 pr-3 text-right">{site.active_alerts > 0 ? <Badge variant="destructive" className="text-[9px] h-4 px-1">{site.active_alerts}</Badge> : <span className="text-muted-foreground">0</span>}</td>
                                                        <td className="py-2 text-right tabular-nums text-muted-foreground">${(site.fuel_cost_mtd ?? 0).toLocaleString('en-NZ', { minimumFractionDigits: 0, maximumFractionDigits: 0 })}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                        {(after_hours_trips ?? []).length > 0 && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm">
                                        <AlertTriangle className="h-4 w-4 text-amber-500" /> After-Hours Activity
                                        <Badge variant="outline" className="ml-auto text-[10px]">7 days</Badge>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-1.5">
                                        {(after_hours_trips ?? []).slice(0, 6).map((trip) => (
                                            <div key={trip.id} className="flex items-center gap-2 rounded border border-amber-200 bg-amber-50/50 px-2.5 py-1.5 text-xs dark:border-amber-900/30 dark:bg-amber-950/20">
                                                <AlertTriangle className="h-3.5 w-3.5 shrink-0 text-amber-500" />
                                                <span className="font-medium truncate">{trip.vehicle}</span>
                                                <span className="text-muted-foreground truncate">{trip.driver}</span>
                                                <span className="ml-auto shrink-0 tabular-nums text-[10px]">{trip.time}</span>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                )}
            </PageShell>
        </AppLayout>
    );
}
