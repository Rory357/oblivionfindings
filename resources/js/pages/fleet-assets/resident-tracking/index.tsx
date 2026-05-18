import { FleetEmptyState } from '@/components/fleet-empty-state';
import { HalfMoonGauge, FLEET_COLORS } from '@/components/fleet-charts';
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
    UserPlus,
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
    address?: string | null;
    coordinates?: string | null;
    display_location?: string | null;
    battery: number | null;
    battery_status?: 'low' | 'normal' | 'unknown' | string | null;
    battery_voltage_mv?: number | null;
    battery_low_threshold?: number | null;
    charging_status?: string | null;
    external_power?: boolean | null;
    last_power_event?: string | null;
    last_safety_event?: string | null;
    speed: number | null;
    geofence_status: 'in_zone' | 'outside_zone' | 'unknown';
    on_outing: boolean;
    locate_now_url?: string;
    last_command_status?: 'queued' | 'sent' | 'acked' | 'failed' | 'expired' | null;
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
    if (resident.on_outing) return 'bg-status-info';
    if (resident.geofence_status === 'outside_zone') return 'bg-status-critical';
    if (getBatteryState(resident).tone === 'warning') return 'bg-status-warning';
    if (getBatteryState(resident).tone === 'critical') return 'bg-status-critical';
    if (resident.status === 'online') return 'bg-status-success';
    if (resident.status === 'offline') return 'bg-status-critical';
    return 'bg-muted';
}

function getMarkerStatus(resident: Resident): string {
    if (resident.on_outing) return 'moving';
    if (resident.geofence_status === 'outside_zone') return 'offline';
    if (resident.status === 'online') return 'online';
    return 'idle';
}

function getZoneBadge(resident: Resident): { text: string; className: string } {
    if (resident.on_outing) {
        return { text: 'On Outing', className: 'bg-status-info-bg text-status-info border-status-info/30' };
    }
    switch (resident.geofence_status) {
        case 'in_zone':
            return { text: 'In Zone', className: 'bg-primary/10 text-primary border-primary' };
        case 'outside_zone':
            return { text: 'Outside', className: 'bg-status-critical-bg text-status-critical border-status-critical/30' };
        default:
            return { text: 'Unknown', className: 'bg-muted text-muted-foreground' };
    }
}

function getBatteryBarColor(battery: number | null): string {
    if (battery == null) return 'bg-muted';
    if (battery < 20) return 'bg-status-critical animate-pulse';
    if (battery <= 40) return 'bg-status-warning';
    return 'bg-primary';
}

function getBatteryState(resident: Resident) {
    const threshold = resident.battery_low_threshold ?? 20;
    const battery = resident.battery;
    const isCharging =
        resident.charging_status === 'charging' || resident.external_power === true;

    if (isCharging) {
        return {
            label: 'Charging',
            detail: battery != null ? `${battery}%` : undefined,
            icon: Battery,
            tone: 'success' as const,
            textClass: 'text-status-success',
            barClass: 'bg-status-success',
            barWidth: battery ?? 100,
        };
    }

    if (battery == null) {
        return {
            label: 'Battery not reported',
            detail: undefined,
            icon: BatteryWarning,
            tone: 'warning' as const,
            textClass: 'text-status-warning',
            barClass: 'bg-status-warning',
            barWidth: 100,
        };
    }

    if (resident.battery_status === 'low' || battery <= threshold) {
        return {
            label: 'Low battery',
            detail: `${battery}%`,
            icon: BatteryLow,
            tone: 'critical' as const,
            textClass: 'text-status-critical',
            barClass: 'bg-status-critical animate-pulse',
            barWidth: battery,
        };
    }

    return {
        label: `${battery}%`,
        detail: undefined,
        icon: Battery,
        tone: 'normal' as const,
        textClass: 'text-muted-foreground',
        barClass: getBatteryBarColor(battery),
        barWidth: battery,
    };
}

function safetyEventLabel(event?: string | null): string | null {
    switch (event) {
        case 'vehicle_sos':
        case 'sos':
            return 'SOS received';
        case 'man_down':
            return 'Man down alert';
        default:
            return null;
    }
}

function coordinateText(lat: number, lng: number): string {
    return `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
}

function displayLocation(resident: Resident): string | null {
    if (resident.display_location) return resident.display_location;
    if (resident.coordinates) return resident.coordinates;
    if (resident.lat != null && resident.lng != null) {
        return coordinateText(resident.lat, resident.lng);
    }

    return null;
}

function formatAlertType(alertType: string): string {
    return alertType
        .replace(/[._]/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

function commandStatusLabel(status?: Resident['last_command_status']): string | null {
    switch (status) {
        case 'queued':
            return 'Queued';
        case 'sent':
            return 'Sent';
        case 'acked':
            return 'Acknowledged';
        case 'failed':
            return 'Failed';
        case 'expired':
            return 'Expired';
        default:
            return null;
    }
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

    const safeResidents = useMemo(() => residents ?? [], [residents]);
    const safeStats = stats ?? {} as Props['stats'];
    const safeAlerts = recent_alerts ?? [];
    const safeOutings = active_outings ?? [];
    const safeGeofences = useMemo(() => geofences ?? [], [geofences]);
    const heroStats = useMemo(() => [
        { label: 'Tracked', value: safeStats.tracked ?? 0 },
        { label: 'Online', value: safeStats.online ?? 0 },
        { label: 'Offline', value: safeStats.offline ?? 0 },
        { label: 'In Zone', value: safeStats.in_geofence ?? 0 },
        { label: 'Outside Zone', value: safeStats.outside_geofence ?? 0 },
        { label: 'Low Battery', value: safeStats.low_battery ?? 0 },
    ], [
        safeStats.in_geofence,
        safeStats.low_battery,
        safeStats.offline,
        safeStats.online,
        safeStats.outside_geofence,
        safeStats.tracked,
    ]);

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
            .map((r) => {
                const battery = getBatteryState(r);
                const safety = safetyEventLabel(r.last_safety_event);
                const location = displayLocation(r);

                return {
                    id: r.client_id,
                    lat: r.lat!,
                    lng: r.lng!,
                    title: r.preferred_name ?? r.name,
                    type: 'default' as const,
                    status: getMarkerStatus(r),
                    popup: `${r.name}<br/>${r.house}${location ? `<br/>Location: ${location}` : ''}${r.address && r.coordinates ? `<br/>Coordinates: ${r.coordinates}` : ''}<br/>Zone: ${getZoneBadge(r).text}<br/>Battery: ${battery.label}${battery.detail ? ` (${battery.detail})` : ''}${safety ? `<br/>Safety: ${safety}` : ''}<br/>Last seen: ${formatRelativeTime(r.last_seen_at)}`,
                };
            });
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

    const handleLocateNow = useCallback((resident: Resident) => {
        router.post(
            resident.locate_now_url ?? `/fleet-assets/resident-tracking/${resident.client_id}/locate-now`,
            {},
            { preserveScroll: true },
        );
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
                    stats={heroStats}
                    actions={can.manage ? (
                        <Button asChild>
                            <Link href="/fleet-assets/resident-tracking/assign">
                                <UserPlus className="mr-2 h-4 w-4" />
                                Assign Tracker
                            </Link>
                        </Button>
                    ) : undefined}
                />

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
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setActiveTab('all')}
                                    className={`h-auto rounded-md px-3 py-1.5 text-xs ${
                                        activeTab === 'all'
                                            ? 'bg-primary/10 text-primary'
                                            : 'text-muted-foreground hover:bg-muted'
                                    }`}
                                >
                                    All ({safeResidents.length})
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setActiveTab('outside')}
                                    className={`h-auto rounded-md px-3 py-1.5 text-xs ${
                                        activeTab === 'outside'
                                            ? 'bg-status-critical-bg text-status-critical'
                                            : 'text-muted-foreground hover:bg-muted'
                                    }`}
                                >
                                    Outside ({outsideCount})
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => setActiveTab('alerts')}
                                    className={`h-auto rounded-md px-3 py-1.5 text-xs ${
                                        activeTab === 'alerts'
                                            ? 'bg-status-warning-bg text-status-warning'
                                            : 'text-muted-foreground hover:bg-muted'
                                    }`}
                                >
                                    Alerts ({safeAlerts.length})
                                </Button>
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
                                                <AlertTriangle className="h-4 w-4 shrink-0 text-status-warning" />
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
                                            const battery = getBatteryState(resident);
                                            const BatteryIcon = battery.icon;
                                            const commandLabel = commandStatusLabel(resident.last_command_status);
                                            const safety = safetyEventLabel(resident.last_safety_event);
                                            const location = displayLocation(resident);

                                            return (
                                                <div
                                                    key={resident.id}
                                                    className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/50"
                                                >
                                                    <Link
                                                        href={`/fleet-assets/resident-tracking/history/${resident.client_id}`}
                                                        className="flex min-w-0 flex-1 items-center gap-3"
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
                                                            {location && (
                                                                <div className="mt-0.5 truncate text-xs text-muted-foreground">
                                                                    {location}
                                                                </div>
                                                            )}
                                                            {safety && (
                                                                <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                                                    <Badge
                                                                        variant="outline"
                                                                        className="gap-1 border-status-critical/30 bg-status-critical-bg text-[10px] text-status-critical"
                                                                    >
                                                                        <ShieldAlert className="h-3 w-3" />
                                                                        {safety}
                                                                    </Badge>
                                                                </div>
                                                            )}
                                                        </div>
                                                    </Link>
                                                    <div className="flex shrink-0 flex-col items-end gap-1">
                                                        <div className="flex items-center gap-2">
                                                            {commandLabel && (
                                                                <Badge variant="secondary" className="text-[10px]">
                                                                    {commandLabel}
                                                                </Badge>
                                                            )}
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                className="h-9"
                                                                onClick={() => handleLocateNow(resident)}
                                                            >
                                                                <MapPin className="mr-1 h-4 w-4" />
                                                                Locate Now
                                                            </Button>
                                                        </div>
                                                        {/* Battery bar */}
                                                        <div className="flex flex-col items-end gap-0.5">
                                                            <span className={`flex max-w-32 items-center gap-1 truncate text-[10px] tabular-nums ${battery.textClass}`}>
                                                                <BatteryIcon className="h-3 w-3 shrink-0" />
                                                                <span className="truncate">{battery.label}</span>
                                                                {battery.detail && (
                                                                    <span className="shrink-0 text-muted-foreground">
                                                                        {battery.detail}
                                                                    </span>
                                                                )}
                                                            </span>
                                                            <div className="h-1.5 w-10 overflow-hidden rounded-full bg-muted">
                                                                <div
                                                                    className={`h-full rounded-full ${battery.barClass}`}
                                                                    style={{ width: `${battery.barWidth}%` }}
                                                                />
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
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
                                <AlertTriangle className="h-4 w-4 text-status-warning" />
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
                                            <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0 text-status-warning" />
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
                                <Bus className="h-4 w-4 text-status-info" />
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
                                            <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0 text-status-info" />
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
