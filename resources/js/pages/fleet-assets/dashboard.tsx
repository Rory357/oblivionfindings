import { FleetStatCard } from '@/components/fleet-stat-card';
import LeafletMap, { MapMarker } from '@/components/leaflet-map';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import {
    formatCurrency,
    formatRelativeTime,
    formatTime,
    severityVariant,
} from '@/lib/fleet-utils';
import {
    FleetAttentionStrip,
    FleetComplianceBadges,
    FleetHeroAction,
    fmt,
    HeroCluster,
    HeroClusterTile,
    HeroMedallion,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
    HeroSummaryMetric,
    HeroSummaryStrip,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Bell,
    Bookmark,
    Calendar,
    Car,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    FileBarChart,
    Fuel,
    MapPin,
    Radio,
    Receipt,
    RefreshCw,
    Route,
    Settings,
    ShieldAlert,
    Smartphone,
    Users,
    UserSearch,
    Wrench,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

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
        total_devices: number | null;
        online_devices: number | null;
        recent_bookings_count: number;
        checked_out_count: number;
        overdue_count: number;
        /** Overdue returns within the active scope lens — feeds the Bookings tile caption. */
        overdue_count_scoped: number;
        outings_past_return: number;
        /** Outings past return within the active scope lens — feeds the Outings tile caption. */
        outings_past_return_scoped: number;
        upcoming_maintenance_count: number;
        trips_today: number;
        vehicles_in_maintenance: number;
        wof_due_30: number;
        wof_expired: number;
        rego_due_30: number;
        rego_expired: number;
        cof_due: number;
        cof_expired: number;
        /** `null` when the schema has no insurance column — hides the chip. */
        insurance_expiring: number | null;
        insurance_expired: number | null;
        transports_today: number;
        open_wandering_alerts: number;
        tracked_residents?: number | null;
        active_outings?: number;
    };
    can: {
        view_technology: boolean;
    };
    /** Cluster scope lens — `mine` filters cluster counts to the user's site server-side. */
    scope: 'all' | 'mine';
    /** False when the user has no resolvable site — the scope lens is hidden. */
    has_site: boolean;
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
    today_outings?: Array<{
        id: number;
        title: string;
        destination: string;
        status: string;
        planned_departure: string | null;
        asset: { id: number; name: string } | null;
        driver: { id: number; name: string } | null;
        resident_count: number;
    }>;
};

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

// Using shared formatRelativeTime and severityVariant from fleet-utils

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
            <div
                className="flex flex-col items-center justify-center"
                style={{ width: size, height: size }}
            >
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
                    <text
                        x="50%"
                        y="50%"
                        textAnchor="middle"
                        dominantBaseline="central"
                        className="fill-muted-foreground text-xs"
                    >
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
                <div
                    key={i}
                    className="flex items-center justify-between text-xs"
                >
                    <div className="flex items-center gap-2">
                        <span
                            className="inline-block h-2.5 w-2.5 rounded-full"
                            style={{ backgroundColor: s.color }}
                        />
                        <span className="text-muted-foreground">{s.label}</span>
                    </div>
                    <span className="font-medium tabular-nums">
                        {s.value}
                        {total > 0 && (
                            <span className="ml-1 text-muted-foreground">
                                ({Math.round((s.value / total) * 100)}%)
                            </span>
                        )}
                    </span>
                </div>
            ))}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Explore link-row                                                   */
/* ------------------------------------------------------------------ */

/** The eight navigation-jobs. Action-jobs (book, fuel, incident, work order,
 *  daily check) live only in the hero footer — never duplicated here. */
const EXPLORE_LINKS: Array<{
    label: string;
    href: string;
    icon: React.ElementType;
}> = [
    { label: 'Vehicles', href: '/fleet-assets/vehicles', icon: Car },
    { label: 'Assets', href: '/fleet-assets/assets', icon: ClipboardList },
    { label: 'Devices', href: '/fleet-assets/devices', icon: Smartphone },
    { label: 'Reports', href: '/fleet-assets/reports', icon: FileBarChart },
    { label: 'Map', href: '/fleet-assets/map', icon: MapPin },
    {
        label: 'Residents',
        href: '/fleet-assets/resident-tracking',
        icon: UserSearch,
    },
    { label: 'Outings', href: '/fleet-assets/outings', icon: Route },
    { label: 'Mileage', href: '/fleet-assets/mileage', icon: Receipt },
];

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
    today_outings,
    scope,
    has_site,
    can,
}: Props) {
    const stats = rawStats ?? {
        total_vehicles: 0,
        online_count: 0,
        offline_count: 0,
        total_assets: 0,
        active_alerts: 0,
        critical_alerts: 0,
        fuel_cost_mtd: 0,
        distance_mtd: 0,
        total_devices: null,
        online_devices: null,
        recent_bookings_count: 0,
        checked_out_count: 0,
        overdue_count: 0,
        overdue_count_scoped: 0,
        outings_past_return: 0,
        outings_past_return_scoped: 0,
        upcoming_maintenance_count: 0,
        trips_today: 0,
        vehicles_in_maintenance: 0,
        wof_due_30: 0,
        wof_expired: 0,
        rego_due_30: 0,
        rego_expired: 0,
        cof_due: 0,
        cof_expired: 0,
        insurance_expiring: null,
        insurance_expired: null,
        transports_today: 0,
        open_wandering_alerts: 0,
        tracked_residents: null,
        active_outings: 0,
    };
    const canViewTechnology = can?.view_technology ?? false;

    const vsb = vehicle_status_breakdown ?? {};
    const asb = asset_status_breakdown ?? {};
    const ms = maintenance_stats ?? {};

    // Map tab filter
    const [mapFilter, setMapFilter] = useState<'all' | 'active' | 'inactive'>(
        'all',
    );

    // Live-sync timestamp + in-flight spinner for the hero status pill.
    const [lastUpdated, setLastUpdated] = useState<Date>(() => new Date());
    const [isRefreshing, setIsRefreshing] = useState(false);

    // 30-second auto-refresh (all hero stats travel inside `stats`; the current
    // URL query — including ?scope=mine — is preserved by router.reload).
    useEffect(() => {
        const interval = window.setInterval(() => {
            if (document.hidden) return;
            setIsRefreshing(true);
            router.reload({
                only: [
                    'vehicles',
                    'stats',
                    'recent_signals',
                    'vehicle_status_breakdown',
                    'asset_status_breakdown',
                    'maintenance_stats',
                    'recent_alerts',
                ],
                onFinish: () => {
                    setIsRefreshing(false);
                    setLastUpdated(new Date());
                },
            });
        }, 30000);
        return () => window.clearInterval(interval);
    }, []);

    // Scope lens — server-side cluster filter, same pattern as the maintenance
    // dashboard's period control.
    const handleScopeChange = (key: string) => {
        router.get('/fleet-assets', key === 'mine' ? { scope: 'mine' } : {}, {
            preserveState: true,
        });
    };

    // Filtered vehicles for map
    const filteredVehicles = useMemo(() => {
        const v = vehicles ?? [];
        if (mapFilter === 'active')
            return v.filter((veh) => veh.state?.status === 'online');
        if (mapFilter === 'inactive')
            return v.filter(
                (veh) => !veh.state || veh.state.status !== 'online',
            );
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
        const firstVehicle = (vehicles ?? []).find(
            (v) => v.state?.lat && v.state?.lng,
        );
        if (firstVehicle) {
            return {
                lat: Number(firstVehicle.state!.lat),
                lng: Number(firstVehicle.state!.lng),
            };
        }
        return { lat: -36.8485, lng: 174.7633 };
    }, [vehicles]);

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
        {
            label: 'In Progress',
            value: ms['in_progress'] ?? 0,
            color: '#f59e0b',
        },
        { label: 'Completed', value: ms['completed'] ?? 0, color: '#a78bfa' },
        { label: 'Cancelled', value: ms['cancelled'] ?? 0, color: '#ef4444' },
    ];

    const totalVehicleStates = vehicleDonutSegments.reduce(
        (s, d) => s + d.value,
        0,
    );
    const totalAssets = assetDonutSegments.reduce((s, d) => s + d.value, 0);
    const totalWorkOrders = maintenanceDonutSegments.reduce(
        (s, d) => s + d.value,
        0,
    );

    return (
        <AppLayout
            breadcrumbs={[{ title: 'Fleet & Assets', href: '/fleet-assets' }]}
        >
            <Head title="Fleet & Assets" />
            <PageShell>
                <HeroShell
                    footer={
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="mr-1 text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">
                                Quick actions
                            </span>
                            <FleetHeroAction
                                href="/fleet-assets/bookings?new=1"
                                icon={Bookmark}
                                emphasis
                            >
                                Book vehicle
                            </FleetHeroAction>
                            <FleetHeroAction
                                href="/fleet-assets/daily-check"
                                icon={ClipboardCheck}
                            >
                                Daily check
                            </FleetHeroAction>
                            <FleetHeroAction
                                href="/fleet-assets/fuel"
                                icon={Fuel}
                            >
                                Log fuel
                            </FleetHeroAction>
                            <FleetHeroAction
                                href="/fleet-assets/incidents?report=vehicle"
                                icon={ShieldAlert}
                            >
                                Report incident
                            </FleetHeroAction>
                            <FleetHeroAction
                                href="/fleet-assets/maintenance/work-orders?new=1"
                                icon={Wrench}
                            >
                                New work order
                            </FleetHeroAction>
                            <Link
                                href="/fleet-assets/settings/notifications"
                                className="ml-auto inline-flex h-[34px] w-[34px] items-center justify-center rounded-lg text-primary-foreground/70 transition-colors hover:bg-primary-foreground/10 hover:text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                                aria-label="Fleet notification settings"
                            >
                                <Settings className="h-4 w-4" />
                            </Link>
                        </div>
                    }
                >
                    <div className="flex flex-wrap items-center gap-4">
                        <HeroMedallion icon={Car} />
                        <div className="min-w-0 flex-1">
                            <HeroStatusPill>
                                Fleet command · updated{' '}
                                <span aria-live="polite">
                                    {formatTime(lastUpdated.toISOString())}
                                </span>
                                {isRefreshing && (
                                    <RefreshCw className="h-3 w-3 animate-spin motion-reduce:animate-none" />
                                )}
                            </HeroStatusPill>
                            <h1 className="mt-1.5 text-2xl font-bold tracking-tight md:text-[28px]">
                                Fleet & Assets
                            </h1>
                            <p className="mt-0.5 text-[13px] text-primary-foreground/75">
                                Real-time fleet tracking, asset management, and
                                operational insights.
                            </p>
                        </div>
                        {has_site && (
                            <div className="flex items-center gap-2 self-start">
                                <HeroSegmented
                                    variant="segmented"
                                    label="Scope"
                                    ariaLabel="Cluster scope"
                                    value={scope ?? 'all'}
                                    onChange={handleScopeChange}
                                    items={[
                                        { key: 'all', label: 'All sites' },
                                        { key: 'mine', label: 'My site' },
                                    ]}
                                />
                            </div>
                        )}
                    </div>

                    {/* Escalations across every accessible Site are never filtered by the scope lens. */}
                    <FleetAttentionStrip
                        overdueReturns={stats.overdue_count ?? 0}
                        outingsPastReturn={stats.outings_past_return ?? 0}
                        criticalAlerts={stats.critical_alerts ?? 0}
                    />

                    <div className="grid gap-3 lg:grid-cols-2 xl:grid-cols-[1.25fr_1fr_1fr]">
                        <HeroCluster title="Fleet status" icon={Car}>
                            <HeroClusterTile
                                href="/fleet-assets/vehicles?status=online"
                                label="Online"
                                value={fmt(stats.online_count)}
                                caption="reporting live"
                                tone={
                                    stats.online_count > 0
                                        ? 'success'
                                        : 'neutral'
                                }
                            />
                            <HeroClusterTile
                                href="/fleet-assets/bookings"
                                label="In use"
                                value={fmt(stats.checked_out_count)}
                                caption="checked out"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/fleet-assets/maintenance/work-orders"
                                label="Maintenance"
                                value={fmt(stats.vehicles_in_maintenance)}
                                caption="in the workshop"
                                tone={
                                    stats.vehicles_in_maintenance > 0
                                        ? 'warning'
                                        : 'success'
                                }
                            />
                            <HeroClusterTile
                                href="/fleet-assets/vehicles?status=offline"
                                label="Offline"
                                value={fmt(stats.offline_count)}
                                caption="no recent signal"
                                tone={
                                    (stats.offline_count ?? 0) > 0
                                        ? 'warning'
                                        : 'success'
                                }
                            />
                        </HeroCluster>

                        <HeroCluster title="Today" icon={Calendar} columns={3}>
                            <HeroClusterTile
                                href="/fleet-assets/trips"
                                label="Trips today"
                                value={fmt(stats.trips_today)}
                                caption="journeys logged"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/fleet-assets/bookings"
                                label="Bookings"
                                value={fmt(stats.recent_bookings_count)}
                                caption={
                                    (stats.overdue_count_scoped ?? 0) > 0
                                        ? `${stats.overdue_count_scoped} overdue return${stats.overdue_count_scoped === 1 ? '' : 's'}`
                                        : 'pending + approved'
                                }
                                tone={
                                    (stats.overdue_count_scoped ?? 0) > 0
                                        ? 'warning'
                                        : 'neutral'
                                }
                            />
                            <HeroClusterTile
                                href="/fleet-assets/outings"
                                label="Outings"
                                value={fmt(stats.active_outings)}
                                caption={
                                    (stats.outings_past_return_scoped ?? 0) > 0
                                        ? `${stats.outings_past_return_scoped} past return`
                                        : 'planned or underway'
                                }
                                tone={
                                    (stats.outings_past_return_scoped ?? 0) > 0
                                        ? 'critical'
                                        : 'neutral'
                                }
                            />
                        </HeroCluster>

                        <HeroCluster
                            title="Resident movement"
                            icon={Users}
                            columns={3}
                        >
                            <HeroClusterTile
                                href="/fleet-assets/transports"
                                label="Transports"
                                value={fmt(stats.transports_today)}
                                caption="resident journeys today"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href={
                                    canViewTechnology
                                        ? '/fleet-assets/resident-tracking'
                                        : undefined
                                }
                                label="Tracked"
                                value={
                                    canViewTechnology
                                        ? fmt(stats.tracked_residents)
                                        : 'Restricted'
                                }
                                caption={
                                    canViewTechnology
                                        ? 'residents with devices'
                                        : 'Security access required'
                                }
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/fleet-assets/resident-tracking?tab=wandering"
                                label="Wandering"
                                value={fmt(stats.open_wandering_alerts)}
                                caption={
                                    (stats.open_wandering_alerts ?? 0) > 0
                                        ? 'respond now'
                                        : 'none active'
                                }
                                tone={
                                    (stats.open_wandering_alerts ?? 0) > 0
                                        ? 'critical'
                                        : 'success'
                                }
                            />
                        </HeroCluster>
                    </div>

                    {/* Accessible-Site compliance horizon — successor of the old Compliance cluster;
                        identical composition to /fleet-assets/vehicles so the heroes read as siblings. */}
                    <FleetComplianceBadges
                        wofDue={stats.wof_due_30 ?? 0}
                        wofExpired={stats.wof_expired ?? 0}
                        regoDue={stats.rego_due_30 ?? 0}
                        regoExpired={stats.rego_expired ?? 0}
                        cofDue={stats.cof_due ?? 0}
                        cofExpired={stats.cof_expired ?? 0}
                        insuranceExpiring={stats.insurance_expiring ?? null}
                        insuranceExpired={stats.insurance_expired ?? null}
                        openAlerts={stats.active_alerts ?? 0}
                        criticalAlerts={stats.critical_alerts ?? 0}
                        hrefs={{
                            wof: '/fleet-assets/compliance',
                            rego: '/fleet-assets/compliance',
                            cof: '/fleet-assets/compliance',
                            insurance: '/fleet-assets/compliance',
                            alerts: '/fleet-assets/alerts',
                        }}
                    />

                    <HeroSummaryStrip label="This month">
                        <HeroSummaryMetric tone="neutral">
                            {formatCurrency(stats.fuel_cost_mtd ?? 0)} fuel
                        </HeroSummaryMetric>
                        <HeroSummaryMetric tone="neutral">
                            {fmt(stats.distance_mtd, ' km')} travelled
                        </HeroSummaryMetric>
                        <HeroSummaryMetric
                            tone={
                                !canViewTechnology
                                    ? 'neutral'
                                    : (stats.total_devices ?? 0) -
                                            (stats.online_devices ?? 0) >
                                        0
                                      ? 'warning'
                                      : 'success'
                            }
                        >
                            {canViewTechnology ? (
                                <>
                                    {fmt(stats.online_devices)} of{' '}
                                    {fmt(stats.total_devices)} devices online
                                </>
                            ) : (
                                <span className="inline-flex items-center gap-1.5">
                                    <ShieldAlert
                                        className="h-3.5 w-3.5"
                                        aria-hidden="true"
                                    />
                                    Device health access restricted
                                </span>
                            )}
                        </HeroSummaryMetric>
                        <HeroSummaryMetric tone="neutral">
                            {fmt(stats.upcoming_maintenance_count)} services due
                        </HeroSummaryMetric>
                    </HeroSummaryStrip>
                </HeroShell>

                {/* ============================================================ */}
                {/*  ROW 1 - KPI Cards                                           */}
                {/* ============================================================ */}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-4">
                    <FleetStatCard
                        label="Vehicles"
                        value={stats.total_vehicles ?? 0}
                        icon={Car}
                    />
                    {stats.overdue_count > 0 && (
                        <FleetStatCard
                            label="Overdue Returns"
                            value={stats.overdue_count}
                            icon={Car}
                            color="red"
                            href="/fleet-assets/bookings"
                        />
                    )}
                    <Card className="border bg-primary/10 dark:bg-primary/20">
                        <CardContent className="p-4">
                            <div className="flex items-start justify-between">
                                <div>
                                    <p className="text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                                        Alerts
                                    </p>
                                    <div className="flex items-center gap-1.5">
                                        <span className="text-2xl font-bold">
                                            {stats.active_alerts ?? 0}
                                        </span>
                                        {(stats.critical_alerts ?? 0) > 0 && (
                                            <Badge className="h-4 border-0 bg-status-critical-bg px-1 text-[9px] text-status-critical">
                                                {stats.critical_alerts} crit
                                            </Badge>
                                        )}
                                    </div>
                                </div>
                                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-status-warning">
                                    <AlertTriangle className="h-4 w-4 text-status-warning" />
                                </div>
                            </div>
                            <div className="mt-2 flex items-center gap-1 text-[10px] text-muted-foreground">
                                <Wrench className="h-3 w-3" />{' '}
                                {stats.upcoming_maintenance_count ?? 0} upcoming
                                services
                            </div>
                        </CardContent>
                    </Card>
                    <FleetStatCard
                        label="Fuel MTD"
                        value={formatCurrency(stats.fuel_cost_mtd ?? 0)}
                        icon={Fuel}
                    />
                    {canViewTechnology &&
                        (stats.tracked_residents ?? 0) > 0 && (
                            <FleetStatCard
                                label="Tracked Residents"
                                value={stats.tracked_residents ?? 0}
                                icon={UserSearch}
                                color="purple"
                            />
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
                                    <Badge
                                        variant="secondary"
                                        className="ml-1 text-[10px]"
                                    >
                                        {markers.length}
                                    </Badge>
                                </CardTitle>
                                <div className="flex gap-1">
                                    {(
                                        ['all', 'active', 'inactive'] as const
                                    ).map((tab) => (
                                        <Button
                                            key={tab}
                                            variant={
                                                mapFilter === tab
                                                    ? 'default'
                                                    : 'ghost'
                                            }
                                            size="sm"
                                            className="h-6 px-2 text-[10px] capitalize"
                                            onClick={() => setMapFilter(tab)}
                                        >
                                            {tab}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="p-0">
                            <LeafletMap
                                center={center}
                                zoom={12}
                                markers={markers}
                                height={520}
                            />
                        </CardContent>
                    </Card>

                    {/* RIGHT COLUMN - Stacked tiles */}
                    <div className="space-y-4">
                        {/* Explore — the navigation-jobs; action-jobs live in the hero footer only. */}
                        {/* eslint-disable-next-line no-restricted-syntax -- slim single-row link strip, not a Card surface */}
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-lg border border-border bg-card px-4 py-3">
                            <span className="text-[11px] font-semibold tracking-wider text-muted-foreground/80 uppercase">
                                Explore
                            </span>
                            {EXPLORE_LINKS.map((link) => (
                                <Link
                                    key={link.label}
                                    href={link.href}
                                    className="inline-flex items-center gap-1.5 text-[12.5px] font-medium text-muted-foreground transition-colors hover:text-primary"
                                >
                                    <link.icon className="h-3.5 w-3.5" />
                                    {link.label}
                                </Link>
                            ))}
                        </div>

                        {/* Donut charts - 3 side by side */}
                        <div className="grid grid-cols-3 gap-3">
                            <Card>
                                <CardContent className="flex flex-col items-center px-2 pt-4 pb-3">
                                    <p className="mb-2 text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                                        Vehicles
                                    </p>
                                    <DonutChart
                                        segments={vehicleDonutSegments}
                                        centerValue={
                                            totalVehicleStates ||
                                            stats.total_vehicles
                                        }
                                        centerLabel=""
                                        size={80}
                                    />
                                    <div className="mt-2 w-full space-y-0.5">
                                        {vehicleDonutSegments.map((s, i) => (
                                            <div
                                                key={i}
                                                className="flex items-center justify-between text-[9px]"
                                            >
                                                <span className="flex items-center gap-1">
                                                    <span
                                                        className="h-1.5 w-1.5 rounded-full"
                                                        style={{
                                                            backgroundColor:
                                                                s.color,
                                                        }}
                                                    />
                                                    {s.label}
                                                </span>
                                                <span className="font-medium tabular-nums">
                                                    {s.value}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="flex flex-col items-center px-2 pt-4 pb-3">
                                    <p className="mb-2 text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                                        Assets
                                    </p>
                                    <DonutChart
                                        segments={assetDonutSegments}
                                        centerValue={
                                            totalAssets || stats.total_assets
                                        }
                                        centerLabel=""
                                        size={80}
                                    />
                                    <div className="mt-2 w-full space-y-0.5">
                                        {assetDonutSegments.map((s, i) => (
                                            <div
                                                key={i}
                                                className="flex items-center justify-between text-[9px]"
                                            >
                                                <span className="flex items-center gap-1">
                                                    <span
                                                        className="h-1.5 w-1.5 rounded-full"
                                                        style={{
                                                            backgroundColor:
                                                                s.color,
                                                        }}
                                                    />
                                                    {s.label}
                                                </span>
                                                <span className="font-medium tabular-nums">
                                                    {s.value}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardContent className="flex flex-col items-center px-2 pt-4 pb-3">
                                    <p className="mb-2 text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                                        Work Orders
                                    </p>
                                    <DonutChart
                                        segments={maintenanceDonutSegments}
                                        centerValue={totalWorkOrders}
                                        centerLabel=""
                                        size={80}
                                    />
                                    <div className="mt-2 w-full space-y-0.5">
                                        {maintenanceDonutSegments.map(
                                            (s, i) => (
                                                <div
                                                    key={i}
                                                    className="flex items-center justify-between text-[9px]"
                                                >
                                                    <span className="flex items-center gap-1">
                                                        <span
                                                            className="h-1.5 w-1.5 rounded-full"
                                                            style={{
                                                                backgroundColor:
                                                                    s.color,
                                                            }}
                                                        />
                                                        {s.label}
                                                    </span>
                                                    <span className="font-medium tabular-nums">
                                                        {s.value}
                                                    </span>
                                                </div>
                                            ),
                                        )}
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
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <Bell className="h-4 w-4" /> Recent Alerts
                                </CardTitle>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-6 text-[10px]"
                                    asChild
                                >
                                    <Link href="/fleet-assets/alerts">
                                        View all
                                    </Link>
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {(recent_alerts ?? []).length > 0 ? (
                                <div
                                    data-fleet-narrow-strategy="horizontal-scroll"
                                    className="overflow-x-auto"
                                >
                                    <table className="w-full text-xs">
                                        <thead>
                                            <tr className="border-b text-left text-muted-foreground">
                                                <th className="pr-4 pb-2 font-medium">
                                                    Alert
                                                </th>
                                                <th className="pr-4 pb-2 font-medium">
                                                    Severity
                                                </th>
                                                <th className="pr-4 pb-2 font-medium">
                                                    Status
                                                </th>
                                                <th className="pb-2 text-right font-medium">
                                                    Time
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {(recent_alerts ?? [])
                                                .slice(0, 6)
                                                .map((alert) => (
                                                    <tr
                                                        key={alert.id}
                                                        className="border-b border-border/50 last:border-0"
                                                    >
                                                        <td className="py-2 pr-4">
                                                            <span className="font-medium">
                                                                {alert.title ??
                                                                    `Alert #${alert.id}`}
                                                            </span>
                                                        </td>
                                                        <td className="py-2 pr-4">
                                                            <Badge
                                                                variant={severityVariant(
                                                                    alert.severity ??
                                                                        'low',
                                                                )}
                                                                className="text-[10px]"
                                                            >
                                                                {alert.severity ??
                                                                    'low'}
                                                            </Badge>
                                                        </td>
                                                        <td className="py-2 pr-4">
                                                            <span className="text-muted-foreground capitalize">
                                                                {(
                                                                    alert.status ??
                                                                    ''
                                                                ).replace(
                                                                    /_/g,
                                                                    ' ',
                                                                )}
                                                            </span>
                                                        </td>
                                                        <td className="py-2 text-right text-muted-foreground">
                                                            {alert.created_at
                                                                ? formatRelativeTime(
                                                                      alert.created_at,
                                                                  )
                                                                : '-'}
                                                        </td>
                                                    </tr>
                                                ))}
                                        </tbody>
                                    </table>
                                </div>
                            ) : (
                                <div className="flex flex-col items-center justify-center py-6 text-muted-foreground">
                                    <CheckCircle2 className="mb-1.5 h-6 w-6 text-primary" />
                                    <p className="text-xs font-medium">
                                        All clear
                                    </p>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Activity Feed */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm">
                                <Activity className="h-4 w-4" /> Activity
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {(recent_signals ?? []).length > 0 ? (
                                <div className="space-y-1.5">
                                    {(recent_signals ?? [])
                                        .slice(0, 6)
                                        .map((signal) => (
                                            <div
                                                key={signal.id}
                                                className="flex items-center gap-2 rounded border border-border/50 px-2 py-1.5 text-[10px]"
                                            >
                                                <Radio className="h-3 w-3 shrink-0 text-muted-foreground" />
                                                <span className="flex-1 truncate font-medium">
                                                    {signal.asset?.name ??
                                                        'Unknown'}
                                                </span>
                                                <Badge
                                                    variant={severityVariant(
                                                        signal.severity_hint ??
                                                            'low',
                                                    )}
                                                    className="h-3.5 shrink-0 px-1 text-[8px]"
                                                >
                                                    {signal.severity_hint ??
                                                        'low'}
                                                </Badge>
                                            </div>
                                        ))}
                                </div>
                            ) : (
                                <p className="py-6 text-center text-xs text-muted-foreground">
                                    No recent activity.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* ============================================================ */}
                {/*  TODAY'S OUTINGS                                               */}
                {/* ============================================================ */}
                {(today_outings ?? []).length > 0 && (
                    <Card>
                        <CardHeader className="pb-2">
                            <div className="flex items-center justify-between">
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <MapPin className="h-4 w-4" /> Today's
                                    Outings
                                </CardTitle>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-6 text-[10px]"
                                    asChild
                                >
                                    <Link href="/fleet-assets/outings">
                                        View all
                                    </Link>
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                {(today_outings ?? []).map((outing) => (
                                    <Link
                                        key={outing.id}
                                        href={`/fleet-assets/outings/${outing.id}`}
                                        className="flex flex-col gap-1 rounded-lg border p-3 text-xs transition-colors hover:bg-muted/50"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="truncate font-semibold">
                                                {outing.title}
                                            </span>
                                            <Badge
                                                variant={
                                                    outing.status === 'active'
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                className="shrink-0 text-[9px]"
                                            >
                                                {outing.status}
                                            </Badge>
                                        </div>
                                        <span className="truncate text-muted-foreground">
                                            {outing.destination}
                                        </span>
                                        <div className="flex items-center gap-2 text-muted-foreground">
                                            {outing.asset && (
                                                <span>{outing.asset.name}</span>
                                            )}
                                            {outing.resident_count > 0 && (
                                                <span>
                                                    {outing.resident_count}{' '}
                                                    resident
                                                    {outing.resident_count !== 1
                                                        ? 's'
                                                        : ''}
                                                </span>
                                            )}
                                            {outing.planned_departure && (
                                                <span>
                                                    {formatTime(
                                                        outing.planned_departure,
                                                    )}
                                                </span>
                                            )}
                                        </div>
                                    </Link>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* ============================================================ */}
                {/*  VEHICLES AT YOUR SITE                                        */}
                {/* ============================================================ */}
                {(my_site_vehicles ?? []).length > 0 && (
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm">
                                <Car className="h-4 w-4" /> Vehicles at Your
                                Site
                                <Badge
                                    variant="secondary"
                                    className="ml-1 text-[10px]"
                                >
                                    {(my_site_vehicles ?? []).length}
                                </Badge>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                {(my_site_vehicles ?? []).map((v) => (
                                    <div
                                        key={v.id}
                                        className="flex items-center gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/30"
                                    >
                                        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 dark:bg-primary/30">
                                            <Car className="h-4 w-4 text-primary dark:text-primary" />
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">
                                                {v.name}
                                            </p>
                                            <Badge
                                                variant={
                                                    v.status === 'online'
                                                        ? 'default'
                                                        : 'secondary'
                                                }
                                                className="mt-0.5 h-4 px-1.5 text-[9px]"
                                            >
                                                {v.status}
                                            </Badge>
                                        </div>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="h-7 shrink-0 px-2 text-[10px]"
                                            asChild
                                        >
                                            <Link
                                                href={`/fleet-assets/bookings?new=1&asset_id=${v.id}`}
                                            >
                                                Book
                                            </Link>
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
                {((fleet_by_site ?? []).length > 0 ||
                    (after_hours_trips ?? []).length > 0) && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {(fleet_by_site ?? []).length > 0 && (
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="flex items-center gap-2 text-sm">
                                        <MapPin className="h-4 w-4" /> Fleet by
                                        Site
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
                                                        Site
                                                    </th>
                                                    <th className="pr-3 pb-2 text-right font-medium">
                                                        Vehicles
                                                    </th>
                                                    <th className="pr-3 pb-2 text-right font-medium">
                                                        Online
                                                    </th>
                                                    <th className="pr-3 pb-2 text-right font-medium">
                                                        Alerts
                                                    </th>
                                                    <th className="pb-2 text-right font-medium">
                                                        Fuel
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {(fleet_by_site ?? []).map(
                                                    (site) => (
                                                        <tr
                                                            key={site.id}
                                                            className="border-b border-border/50 last:border-0"
                                                        >
                                                            <td className="py-2 pr-3">
                                                                <Link
                                                                    href={`/fleet-assets?site=${site.id}`}
                                                                    className="text-xs font-medium text-primary hover:underline"
                                                                >
                                                                    {site.name}
                                                                </Link>
                                                            </td>
                                                            <td className="py-2 pr-3 text-right tabular-nums">
                                                                {
                                                                    site.vehicle_count
                                                                }
                                                            </td>
                                                            <td className="py-2 pr-3 text-right">
                                                                <span className="inline-flex items-center gap-1">
                                                                    {site.online_count >
                                                                        0 && (
                                                                        <span className="h-1.5 w-1.5 rounded-full bg-status-success" />
                                                                    )}
                                                                    <span className="tabular-nums">
                                                                        {
                                                                            site.online_count
                                                                        }
                                                                    </span>
                                                                </span>
                                                            </td>
                                                            <td className="py-2 pr-3 text-right">
                                                                {site.active_alerts >
                                                                0 ? (
                                                                    <Badge
                                                                        variant="destructive"
                                                                        className="h-4 px-1 text-[9px]"
                                                                    >
                                                                        {
                                                                            site.active_alerts
                                                                        }
                                                                    </Badge>
                                                                ) : (
                                                                    <span className="text-muted-foreground">
                                                                        0
                                                                    </span>
                                                                )}
                                                            </td>
                                                            <td className="py-2 text-right text-muted-foreground tabular-nums">
                                                                $
                                                                {(
                                                                    site.fuel_cost_mtd ??
                                                                    0
                                                                ).toLocaleString(
                                                                    'en-NZ',
                                                                    {
                                                                        minimumFractionDigits: 0,
                                                                        maximumFractionDigits: 0,
                                                                    },
                                                                )}
                                                            </td>
                                                        </tr>
                                                    ),
                                                )}
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
                                        <AlertTriangle className="h-4 w-4 text-status-warning" />{' '}
                                        After-Hours Activity
                                        <Badge
                                            variant="outline"
                                            className="ml-auto text-[10px]"
                                        >
                                            7 days
                                        </Badge>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-1.5">
                                        {(after_hours_trips ?? [])
                                            .slice(0, 6)
                                            .map((trip) => (
                                                <div
                                                    key={trip.id}
                                                    className="flex items-center gap-2 rounded border border-status-warning/30 bg-status-warning-bg px-2.5 py-1.5 text-xs dark:border-status-warning/30"
                                                >
                                                    <AlertTriangle className="h-3.5 w-3.5 shrink-0 text-status-warning" />
                                                    <span className="truncate font-medium">
                                                        {trip.vehicle}
                                                    </span>
                                                    <span className="truncate text-muted-foreground">
                                                        {trip.driver}
                                                    </span>
                                                    <span className="ml-auto shrink-0 text-[10px] tabular-nums">
                                                        {trip.time}
                                                    </span>
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
