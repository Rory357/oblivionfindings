import { FleetEmptyState } from '@/components/fleet-empty-state';
import { HalfMoonGauge, FLEET_COLORS } from '@/components/fleet-charts';
import { FleetStatCard } from '@/components/fleet-stat-card';
import LeafletMap, { type MapMarker, type MapGeofence } from '@/components/leaflet-map';
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatRelativeTime, severityColor } from '@/lib/fleet-utils';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Battery,
    BatteryLow,
    BatteryWarning,
    Bus,
    CheckCircle2,
    Clock,
    MapPin,
    Radio,
    Search,
    Shield,
    ShieldAlert,
    Signal,
    SignalZero,
    UserPlus,
    Users,
    Wifi,
    WifiOff,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type Resident = {
    id: number;
    client_id: number;
    name: string;
    preferred_name: string | null;
    house: string;
    site_id: number | null;
    photo: string | null;
    tracker_name: string | null;
    tracker_serial: string | null;
    status: string;
    last_seen_at: string | null;
    lat: number | null;
    lng: number | null;
    battery: number | null;
    speed: number | null;
    geofence_status: 'in_zone' | 'outside_zone' | 'unknown';
    on_outing: boolean;
};

type Props = {
    residents: Resident[];
    stats: {
        tracked: number;
        online: number;
        offline: number;
        untracked: number;
        online_percent: number;
        in_geofence: number;
        outside_geofence: number;
        low_battery: number;
        safety_score: number;
        avg_battery: number;
    };
    recent_alerts: Array<{
        id: number;
        title: string;
        severity: string;
        status: string;
        created_at: string;
        resident_name?: string;
    }>;
    active_outings: Array<{
        id: number;
        title: string;
        destination: string;
        resident_count: number;
        departed_at: string | null;
        vehicle_name: string | null;
    }>;
    geofences: Array<{
        id: string;
        name: string;
        type: 'circle' | 'polygon';
        center?: { lat: number; lng: number };
        radius_m?: number;
        coordinates?: { lat: number; lng: number }[];
        color?: string;
    }>;
    can: {
        manage: boolean;
    };
};

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

function getStatusDotColor(resident: Resident): string {
    if (resident.on_outing) return 'bg-blue-500';
    if (resident.geofence_status === 'outside_zone') return 'bg-red-500';
    if ((resident.battery ?? 100) < 20) return 'bg-amber-500';
    if (resident.status === 'online') return 'bg-green-500';
    if (resident.status === 'offline') return 'bg-red-400';
    return 'bg-slate-400';
}

function getMarkerStatus(resident: Resident): string {
    if (resident.on_outing) return 'moving';
    if (resident.geofence_status === 'outside_zone') return 'offline';
    if (resident.status === 'online') return 'online';
    return 'idle';
}

function getZoneBadge(resident: Resident): { text: string; className: string } {
    if (resident.on_outing) {
        return { text: 'On Outing', className: 'bg-blue-100 text-blue-700 border-blue-200' };
    }
    switch (resident.geofence_status) {
        case 'in_zone':
            return { text: 'In Zone', className: 'bg-primary/10 text-primary border-primary' };
        case 'outside_zone':
            return { text: 'Outside', className: 'bg-red-100 text-red-700 border-red-200' };
        default:
            return { text: 'Unknown', className: 'bg-muted text-muted-foreground' };
    }
}

function getBatteryBarColor(battery: number | null): string {
    if (battery == null) return 'bg-slate-300';
    if (battery < 20) return 'bg-red-500 animate-pulse';
    if (battery <= 40) return 'bg-amber-500';
    return 'bg-primary';
}

function formatAlertType(alertType: string): string {
    return alertType
        .replace(/[._]/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

/* ------------------------------------------------------------------ */
/*  Main Component                                                     */
/* ------------------------------------------------------------------ */

export default function ResidentTrackingIndex({
    residents,
    stats,
    recent_alerts,
    active_outings,
    geofences,
    can,
}: Props) {
    const [search, setSearch] = useState('');
    const [activeTab, setActiveTab] = useState<'all' | 'outside' | 'alerts'>('all');
    const refreshTimerRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const safeResidents = residents ?? [];
    const safeStats = stats ?? {} as Props['stats'];
    const safeAlerts = recent_alerts ?? [];
    const safeOutings = active_outings ?? [];
    const safeGeofences = geofences ?? [];

    // Auto-refresh every 30s
    useEffect(() => {
        refreshTimerRef.current = setInterval(() => {
            router.reload({
                only: ['residents', 'stats', 'recent_alerts', 'active_outings', 'geofences'],
                preserveState: true,
                preserveScroll: true,
            });
        }, 30000);
        return () => {
            if (refreshTimerRef.current) clearInterval(refreshTimerRef.current);
        };
    }, []);

    // Filter residents based on tab + search
    const filteredResidents = useMemo(() => {
        let list = safeResidents;

        // Tab filter
        if (activeTab === 'outside') {
            list = list.filter((r) => r.geofence_status === 'outside_zone');
        }

        // Search filter
        if (search.trim()) {
            const q = search.toLowerCase();
            list = list.filter(
                (r) =>
                    r.name?.toLowerCase().includes(q) ||
                    r.preferred_name?.toLowerCase().includes(q) ||
                    r.house?.toLowerCase().includes(q),
            );
        }

        return list;
    }, [safeResidents, search, activeTab]);

    const outsideCount = useMemo(
        () => safeResidents.filter((r) => r.geofence_status === 'outside_zone').length,
        [safeResidents],
    );

    // Map markers
    const mapMarkers: MapMarker[] = useMemo(() => {
        return safeResidents
            .filter((r) => r.lat != null && r.lng != null)
            .map((r) => ({
                id: r.client_id,
                lat: r.lat!,
                lng: r.lng!,
                title: r.preferred_name ?? r.name,
                type: 'default' as const,
                status: getMarkerStatus(r),
                popup: `${r.name}<br/>${r.house}<br/>Zone: ${getZoneBadge(r).text}<br/>Last seen: ${formatRelativeTime(r.last_seen_at)}`,
            }));
    }, [safeResidents]);

    // Map geofences
    const mapGeofences: MapGeofence[] = useMemo(() => {
        return safeGeofences.map((gf) => ({
            id: gf.id,
            name: gf.name,
            type: gf.type,
            center: gf.center,
            radius_m: gf.radius_m,
            coordinates: gf.coordinates,
            color: gf.color ?? '#8b5cf6',
        }));
    }, [safeGeofences]);

    // Default center: NZ
    const mapCenter = useMemo(() => {
        if (mapMarkers.length > 0) {
            const avgLat = mapMarkers.reduce((s, m) => s + m.lat, 0) / mapMarkers.length;
            const avgLng = mapMarkers.reduce((s, m) => s + m.lng, 0) / mapMarkers.length;
            return { lat: avgLat, lng: avgLng };
        }
        return { lat: -41.2865, lng: 174.7762 };
    }, [mapMarkers]);

    const handleMarkerClick = useCallback((id: string | number) => {
        router.visit(`/fleet-assets/resident-tracking/history/${id}`);
    }, []);

    // Safety score gauge color
    const safetyScoreColor = useMemo(() => {
        const score = safeStats.safety_score ?? 0;
        if (score >= 80) return FLEET_COLORS.primary;
        if (score >= 50) return FLEET_COLORS.warning;
        return FLEET_COLORS.danger;
    }, [safeStats.safety_score]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Resident Tracking', href: '#' },
            ]}
        >
            <Head title="Resident Tracking" />
            <PageShell>
                <FleetHero
                    title="Resident Tracking"
                    subtitle="Safety command center - monitor tracked residents in real-time"
                    actions={can.manage ? (
                        <Button asChild>
                            <Link href="/fleet-assets/resident-tracking/assign">
                                <UserPlus className="mr-2 h-4 w-4" />
                                Assign Tracker
                            </Link>
                        </Button>
                    ) : undefined}
                />

                {/* KPI Row */}
                <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
                    <FleetStatCard label="Tracked" value={safeStats.tracked ?? 0} icon={Users} color="purple" />
                    <FleetStatCard label="Online" value={safeStats.online ?? 0} icon={Wifi} color="blue" />
                    <FleetStatCard label="Offline" value={safeStats.offline ?? 0} icon={WifiOff} color="red" />
                    <FleetStatCard label="In Zone" value={safeStats.in_geofence ?? 0} icon={Shield} color="purple" />
                    <FleetStatCard label="Outside Zone" value={safeStats.outside_geofence ?? 0} icon={ShieldAlert} color="amber" />
                    <FleetStatCard label="Low Battery" value={safeStats.low_battery ?? 0} icon={BatteryLow} color="red" />
                </div>

                {/* Main Grid: Map + Sidebar */}
                <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                    {/* Map */}
                    <Card>
                        <CardContent className="p-0">
                            <LeafletMap
                                center={mapCenter}
                                zoom={mapMarkers.length > 0 ? 12 : 6}
                                markers={mapMarkers}
                                geofences={mapGeofences}
                                height={520}
                                clustering
                                onMarkerClick={handleMarkerClick}
                            />
                        </CardContent>
                    </Card>

                    {/* Sidebar: Tabbed Resident List */}
                    <Card className="flex flex-col">
                        <CardHeader className="pb-2">
                            <CardTitle className="text-base">Residents</CardTitle>
                            {/* Tab buttons */}
                            <div className="mt-2 flex gap-1">
                                <button
                                    onClick={() => setActiveTab('all')}
                                    className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                        activeTab === 'all'
                                            ? 'bg-primary/10 text-primary'
                                            : 'text-muted-foreground hover:bg-muted'
                                    }`}
                                >
                                    All ({safeResidents.length})
                                </button>
                                <button
                                    onClick={() => setActiveTab('outside')}
                                    className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                        activeTab === 'outside'
                                            ? 'bg-red-100 text-red-700'
                                            : 'text-muted-foreground hover:bg-muted'
                                    }`}
                                >
                                    Outside ({outsideCount})
                                </button>
                                <button
                                    onClick={() => setActiveTab('alerts')}
                                    className={`rounded-md px-3 py-1.5 text-xs font-medium transition-colors ${
                                        activeTab === 'alerts'
                                            ? 'bg-amber-100 text-amber-700'
                                            : 'text-muted-foreground hover:bg-muted'
                                    }`}
                                >
                                    Alerts ({safeAlerts.length})
                                </button>
                            </div>
                            {/* Search */}
                            {activeTab !== 'alerts' && (
                                <div className="relative mt-2">
                                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        placeholder="Search by name or house..."
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        className="pl-9"
                                    />
                                </div>
                            )}
                        </CardHeader>
                        <CardContent className="flex-1 overflow-y-auto p-0" style={{ maxHeight: '420px' }}>
                            {activeTab === 'alerts' ? (
                                /* Alerts tab content */
                                <div className="divide-y">
                                    {safeAlerts.length === 0 ? (
                                        <div className="flex flex-col items-center gap-2 py-10 text-center">
                                            <CheckCircle2 className="h-8 w-8 text-primary" />
                                            <p className="text-sm font-medium">All residents safe</p>
                                            <p className="text-xs text-muted-foreground">No recent alerts</p>
                                        </div>
                                    ) : (
                                        safeAlerts.map((alert) => (
                                            <div
                                                key={alert.id}
                                                className="flex items-center gap-3 px-4 py-3 hover:bg-muted/50"
                                            >
                                                <AlertTriangle className="h-4 w-4 shrink-0 text-amber-500" />
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-2">
                                                        <span className="truncate text-sm font-medium">
                                                            {alert.resident_name ?? formatAlertType(alert.title)}
                                                        </span>
                                                        <Badge className={`text-[10px] text-white ${severityColor(alert.severity)}`}>
                                                            {alert.severity}
                                                        </Badge>
                                                    </div>
                                                    <p className="text-xs text-muted-foreground">
                                                        {formatAlertType(alert.title)} &middot; {formatRelativeTime(alert.created_at)}
                                                    </p>
                                                </div>
                                            </div>
                                        ))
                                    )}
                                </div>
                            ) : (
                                /* Resident list */
                                <div className="divide-y">
                                    {filteredResidents.length === 0 ? (
                                        <div className="p-6 text-center text-sm text-muted-foreground">
                                            No residents found.
                                        </div>
                                    ) : (
                                        filteredResidents.map((resident) => {
                                            const zone = getZoneBadge(resident);
                                            const batteryColor = getBatteryBarColor(resident.battery);
                                            return (
                                                <Link
                                                    key={resident.id}
                                                    href={`/fleet-assets/resident-tracking/history/${resident.client_id}`}
                                                    className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/50"
                                                >
                                                    {/* Photo with status dot */}
                                                    <div className="relative shrink-0">
                                                        <img
                                                            src={resident.photo ?? '/images/avatar-placeholder.svg'}
                                                            alt={resident.name}
                                                            className="h-10 w-10 rounded-full border object-cover"
                                                        />
                                                        <div
                                                            className={`absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white ${getStatusDotColor(resident)}`}
                                                        />
                                                    </div>
                                                    {/* Info */}
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex items-center gap-2">
                                                            <span className="truncate text-sm font-medium">
                                                                {resident.preferred_name ?? resident.name}
                                                            </span>
                                                            <Badge
                                                                variant="outline"
                                                                className={`text-[10px] ${zone.className}`}
                                                            >
                                                                {zone.text}
                                                            </Badge>
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {resident.house} &middot; {formatRelativeTime(resident.last_seen_at)}
                                                        </div>
                                                    </div>
                                                    {/* Battery bar */}
                                                    <div className="flex shrink-0 flex-col items-end gap-0.5">
                                                        <span className="text-[10px] tabular-nums">
                                                            {resident.battery != null ? `${resident.battery}%` : '--'}
                                                        </span>
                                                        <div className="h-1.5 w-10 overflow-hidden rounded-full bg-muted">
                                                            <div
                                                                className={`h-full rounded-full ${batteryColor}`}
                                                                style={{ width: `${resident.battery ?? 0}%` }}
                                                            />
                                                        </div>
                                                    </div>
                                                </Link>
                                            );
                                        })
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                {/* Bottom Row: Alerts, Outings, Safety Analytics */}
                <div className="grid gap-4 lg:grid-cols-3">
                    {/* Recent Alerts Card */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <AlertTriangle className="h-4 w-4 text-amber-500" />
                                Recent Alerts
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {safeAlerts.length === 0 ? (
                                <div className="flex flex-col items-center gap-2 py-6 text-center">
                                    <CheckCircle2 className="h-8 w-8 text-primary" />
                                    <p className="text-sm font-medium">All residents safe</p>
                                    <p className="text-xs text-muted-foreground">No recent alerts to display</p>
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    {safeAlerts.map((alert) => (
                                        <div key={alert.id} className="flex items-start gap-2">
                                            <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-500" />
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-center gap-1.5">
                                                    <span className="truncate text-sm">
                                                        {alert.resident_name ?? formatAlertType(alert.title)}
                                                    </span>
                                                    <Badge
                                                        className={`text-[10px] text-white ${severityColor(alert.severity)}`}
                                                    >
                                                        {alert.severity}
                                                    </Badge>
                                                </div>
                                                <p className="text-xs text-muted-foreground">
                                                    {formatRelativeTime(alert.created_at)}
                                                </p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                            <div className="mt-4 border-t pt-3">
                                <Button variant="outline" size="sm" className="w-full" asChild>
                                    <Link href="/fleet-assets/wandering-alerts">View All Alerts</Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Active Outings Card */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Bus className="h-4 w-4 text-blue-500" />
                                Active Outings
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {safeOutings.length === 0 ? (
                                <FleetEmptyState
                                    icon={Bus}
                                    title="No active outings"
                                    description="No residents are currently on outings"
                                    compact
                                />
                            ) : (
                                <div className="space-y-3">
                                    {safeOutings.map((outing) => (
                                        <div key={outing.id} className="flex items-start gap-2">
                                            <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0 text-blue-500" />
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-medium">{outing.destination}</p>
                                                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                                    <span>{outing.resident_count} resident{outing.resident_count !== 1 ? 's' : ''}</span>
                                                    {outing.departed_at && (
                                                        <>
                                                            <span>&middot;</span>
                                                            <span>Departed {formatRelativeTime(outing.departed_at)}</span>
                                                        </>
                                                    )}
                                                </div>
                                                {outing.vehicle_name && (
                                                    <p className="text-xs text-muted-foreground">{outing.vehicle_name}</p>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                            <div className="mt-4 border-t pt-3">
                                <Button variant="outline" size="sm" className="w-full" asChild>
                                    <Link href="/fleet-assets/outings">View All Outings</Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Safety Analytics Card */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Shield className="h-4 w-4 text-primary" />
                                Safety Analytics
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-col items-center">
                                <HalfMoonGauge
                                    value={safeStats.safety_score ?? 0}
                                    label="Safety Score"
                                    sublabel={`${safeStats.in_geofence ?? 0} of ${safeStats.tracked ?? 0} residents within their zone`}
                                    size={160}
                                    color={safetyScoreColor}
                                />
                                <div className="mt-4 flex w-full items-center justify-center gap-2 rounded-lg bg-muted/30 px-4 py-2">
                                    <Battery className="h-4 w-4 text-muted-foreground" />
                                    <span className="text-sm text-muted-foreground">Avg Battery:</span>
                                    <span className="text-sm font-semibold tabular-nums">
                                        {safeStats.avg_battery ?? 0}%
                                    </span>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </PageShell>
        </AppLayout>
    );
}
