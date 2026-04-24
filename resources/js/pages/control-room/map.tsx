import 'leaflet/dist/leaflet.css';
import PageHeader from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    MapPin,
    Radio,
    RefreshCw,
    Wifi,
    WifiOff,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

// ── Types ────────────────────────────────────────────────────────────────────

interface MapDevice {
    id: number;
    device_uid: string;
    name: string;
    type: string;
    status: string;
    latitude: number;
    longitude: number;
    location_description: string | null;
    battery_level: number | null;
    last_seen_at: string | null;
    vendor: string | null;
    model: string | null;
    site_id: number | null;
    client_id: number | null;
    asset_id: number | null;
}

interface MapSite {
    id: number;
    name: string;
    address: string;
    latitude: number;
    longitude: number;
}

interface GeofenceShape {
    lat?: number;
    lon?: number;
    radius_m?: number;
    points?: { lat: number; lng: number }[];
}

interface MapGeofence {
    id: number;
    name: string;
    type: string;
    shape: GeofenceShape;
    breach_type: string;
    site_id: number | null;
}

interface MapAlert {
    id: number;
    alert_type: string;
    severity: string;
    status: string;
    triggered_at: string | null;
    device_id: number | null;
    site_id: number | null;
    latitude: number | null;
    longitude: number | null;
    asset_name: string | null;
    notes: string | null;
}

interface SiteOption {
    id: number;
    name: string;
}

interface Stats {
    total_devices: number;
    online: number;
    offline: number;
    active_alerts: number;
}

interface Props {
    devices: MapDevice[];
    sites: MapSite[];
    geofences: MapGeofence[];
    alerts: MapAlert[];
    all_sites: SiteOption[];
    stats: Stats;
    filters: Record<string, string>;
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function formatRelativeTime(isoString: string | null): string {
    if (!isoString) return 'Unknown';
    const date = new Date(isoString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    return `${diffDays}d ago`;
}

function isRecentlySeen(lastSeen: string | null, minutes: number = 5): boolean {
    if (!lastSeen) return false;
    const diff = Date.now() - new Date(lastSeen).getTime();
    return diff < minutes * 60 * 1000;
}

// ── Pulse animation CSS ─────────────────────────────────────────────────────

const pulseStyle = `
@keyframes pulse-ring {
    0% { transform: scale(0.5); opacity: 1; }
    100% { transform: scale(2.5); opacity: 0; }
}
.leaflet-alert-pulse {
    width: 20px;
    height: 20px;
    position: relative;
}
.leaflet-alert-pulse::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: rgba(239, 68, 68, 0.4);
    animation: pulse-ring 1.5s ease-out infinite;
}
.leaflet-alert-pulse-dot {
    width: 12px;
    height: 12px;
    background: #ef4444;
    border-radius: 50%;
    border: 2px solid #fff;
    position: absolute;
    top: 4px;
    left: 4px;
    box-shadow: 0 0 6px rgba(239, 68, 68, 0.6);
}
`;

// ── Component ────────────────────────────────────────────────────────────────

export default function ControlRoomMap({
    devices,
    sites,
    geofences,
    alerts,
    all_sites,
    stats,
    filters,
}: Props) {
    const mapRef = useRef<HTMLDivElement>(null);
    const mapInstanceRef = useRef<L.Map | null>(null);
    const layerGroupRef = useRef<L.LayerGroup | null>(null);
    const [isMapReady, setIsMapReady] = useState(false);
    const [lastRefreshed, setLastRefreshed] = useState<Date>(new Date());

    // ── Filters ──────────────────────────────────────────────────────────────

    const applyFilter = useCallback(
        (key: string, value: string) => {
            const newFilters = { ...filters, [key]: value === 'all' ? undefined : value };
            // Clean undefined values
            Object.keys(newFilters).forEach((k) => {
                if (!newFilters[k]) delete newFilters[k];
            });
            router.get('/control-room/map', newFilters, {
                preserveState: true,
                preserveScroll: true,
            });
        },
        [filters],
    );

    const clearFilters = useCallback(() => {
        router.get('/control-room/map', {}, { preserveState: true });
    }, []);

    const manualRefresh = useCallback(() => {
        router.reload({ only: ['devices', 'alerts', 'stats'] });
        setLastRefreshed(new Date());
    }, []);

    const hasFilters = Object.values(filters).some((v) => v);

    // ── Map initialization ───────────────────────────────────────────────────

    useEffect(() => {
        let mounted = true;

        async function initMap() {
            const leaflet = await import('leaflet');
            const L = (leaflet as any).default ?? leaflet;

            if (!mounted || !mapRef.current || mapInstanceRef.current) return;

            const map = L.map(mapRef.current, {
                center: [-41.2865, 174.7762],
                zoom: 6,
                zoomControl: true,
                attributionControl: true,
            });

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors',
                maxZoom: 19,
            }).addTo(map);

            const layerGroup = L.layerGroup().addTo(map);

            mapInstanceRef.current = map;
            layerGroupRef.current = layerGroup;

            if (mounted) {
                setIsMapReady(true);
            }
        }

        initMap();

        return () => {
            mounted = false;
            if (mapInstanceRef.current) {
                mapInstanceRef.current.remove();
                mapInstanceRef.current = null;
                layerGroupRef.current = null;
                setIsMapReady(false);
            }
        };
    }, []);

    // ── Render markers ───────────────────────────────────────────────────────

    useEffect(() => {
        if (!isMapReady || !mapInstanceRef.current || !layerGroupRef.current) return;

        async function renderMarkers() {
            const leaflet = await import('leaflet');
            const L = 'default' in leaflet ? (leaflet as Record<string, unknown>).default as typeof leaflet : leaflet;
            const map = mapInstanceRef.current!;
            const layers = layerGroupRef.current!;

            layers.clearLayers();

            const bounds: L.LatLngExpression[] = [];

            // ── Geofence overlays ────────────────────────────────────────────

            geofences.forEach((gf) => {
                if (!gf.shape) return;

                if (gf.type === 'circle' && gf.shape.lat && gf.shape.lon && gf.shape.radius_m) {
                    L.circle([gf.shape.lat, gf.shape.lon], {
                        radius: gf.shape.radius_m,
                        color: '#6366f1',
                        fillColor: '#6366f1',
                        fillOpacity: 0.08,
                        weight: 1.5,
                        dashArray: '5, 5',
                    })
                        .bindPopup(
                            `<div class="text-sm"><strong>${gf.name}</strong><br/>` +
                                `Type: ${gf.breach_type}<br/>` +
                                `Radius: ${gf.shape.radius_m}m</div>`,
                        )
                        .addTo(layers);
                } else if (gf.type === 'polygon' && gf.shape.points && gf.shape.points.length > 2) {
                    const points: L.LatLngExpression[] = gf.shape.points.map((p) => [p.lat, p.lng]);
                    L.polygon(points, {
                        color: '#6366f1',
                        fillColor: '#6366f1',
                        fillOpacity: 0.08,
                        weight: 1.5,
                        dashArray: '5, 5',
                    })
                        .bindPopup(
                            `<div class="text-sm"><strong>${gf.name}</strong><br/>` +
                                `Type: ${gf.breach_type}</div>`,
                        )
                        .addTo(layers);
                }
            });

            // ── Site markers ─────────────────────────────────────────────────

            sites.forEach((site) => {
                const siteIcon = L.divIcon({
                    className: '',
                    html: `<div style="
                        width: 28px; height: 28px; border-radius: 50%;
                        background: #f97316; border: 3px solid #fff;
                        box-shadow: 0 2px 6px rgba(0,0,0,0.3);
                        display: flex; align-items: center; justify-content: center;
                    "><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg></div>`,
                    iconSize: [28, 28],
                    iconAnchor: [14, 14],
                    popupAnchor: [0, -16],
                });

                const marker = L.marker([site.latitude, site.longitude], { icon: siteIcon })
                    .bindPopup(
                        `<div class="text-sm">` +
                            `<strong>${site.name}</strong><br/>` +
                            `<span style="color:#666">${site.address}</span>` +
                            `</div>`,
                    )
                    .addTo(layers);

                bounds.push([site.latitude, site.longitude]);
            });

            // ── Device markers ───────────────────────────────────────────────

            devices.forEach((device) => {
                let color = '#6b7280'; // gray default
                let label = device.status;

                if (device.type === 'personal_tracker') {
                    color = '#8b5cf6'; // purple
                    label = 'Resident tracker';
                } else if (device.status === 'online' && isRecentlySeen(device.last_seen_at, 5)) {
                    color = '#3b82f6'; // blue - moving/recently seen
                    label = 'Moving';
                } else if (device.status === 'online') {
                    color = '#22c55e'; // green
                    label = 'Online';
                } else {
                    color = '#ef4444'; // red offline
                    label = 'Offline';
                }

                const deviceIcon = L.divIcon({
                    className: '',
                    html: `<div style="
                        width: 22px; height: 22px; border-radius: 50%;
                        background: ${color}; border: 2.5px solid #fff;
                        box-shadow: 0 2px 6px rgba(0,0,0,0.3);
                    "></div>`,
                    iconSize: [22, 22],
                    iconAnchor: [11, 11],
                    popupAnchor: [0, -14],
                });

                const batteryStr =
                    device.battery_level !== null ? `${device.battery_level}%` : 'N/A';

                const popupContent =
                    `<div class="text-sm" style="min-width:180px">` +
                    `<strong>${device.name || device.device_uid}</strong><br/>` +
                    `<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:${color};margin-right:4px;vertical-align:middle"></span>` +
                    `<span style="color:#666">${label}</span><br/>` +
                    `<span style="color:#666">Type: ${device.type.replace(/_/g, ' ')}</span><br/>` +
                    `<span style="color:#666">Battery: ${batteryStr}</span><br/>` +
                    `<span style="color:#666">Last seen: ${formatRelativeTime(device.last_seen_at)}</span>` +
                    (device.location_description
                        ? `<br/><span style="color:#666">Location: ${device.location_description}</span>`
                        : '') +
                    (device.vendor
                        ? `<br/><span style="color:#888;font-size:11px">${device.vendor}${device.model ? ' ' + device.model : ''}</span>`
                        : '') +
                    `</div>`;

                L.marker([device.latitude, device.longitude], { icon: deviceIcon })
                    .bindPopup(popupContent)
                    .addTo(layers);

                bounds.push([device.latitude, device.longitude]);
            });

            // ── Alert markers (pulsing red) ──────────────────────────────────

            const alertsWithLocation = alerts.filter(
                (a) => a.latitude !== null && a.longitude !== null,
            );

            alertsWithLocation.forEach((alert) => {
                const alertIcon = L.divIcon({
                    className: '',
                    html: `<div class="leaflet-alert-pulse"><div class="leaflet-alert-pulse-dot"></div></div>`,
                    iconSize: [20, 20],
                    iconAnchor: [10, 10],
                    popupAnchor: [0, -12],
                });

                const popupContent =
                    `<div class="text-sm" style="min-width:180px">` +
                    `<strong style="color:#ef4444">${alert.alert_type}</strong><br/>` +
                    `<span style="color:#666">Severity: ${alert.severity}</span><br/>` +
                    `<span style="color:#666">Status: ${alert.status}</span><br/>` +
                    `<span style="color:#666">Triggered: ${formatRelativeTime(alert.triggered_at)}</span>` +
                    (alert.asset_name
                        ? `<br/><span style="color:#666">Asset: ${alert.asset_name}</span>`
                        : '') +
                    (alert.notes
                        ? `<br/><span style="color:#888;font-size:11px">${alert.notes}</span>`
                        : '') +
                    `</div>`;

                L.marker([alert.latitude!, alert.longitude!], { icon: alertIcon })
                    .bindPopup(popupContent)
                    .addTo(layers);

                bounds.push([alert.latitude!, alert.longitude!]);
            });

            // ── Fit bounds ───────────────────────────────────────────────────

            if (bounds.length > 1) {
                map.fitBounds(L.latLngBounds(bounds), { padding: [40, 40], maxZoom: 14 });
            } else if (bounds.length === 1) {
                map.setView(bounds[0] as L.LatLngExpression, 13);
            }
        }

        renderMarkers();
    }, [isMapReady, devices, sites, geofences, alerts]);

    // ── Auto-refresh every 30 seconds ────────────────────────────────────────

    useEffect(() => {
        const interval = setInterval(() => {
            if (document.hidden) return;
            router.reload({ only: ['devices', 'alerts', 'stats'] });
            setLastRefreshed(new Date());
        }, 30000);

        return () => clearInterval(interval);
    }, []);

    // ── Render ───────────────────────────────────────────────────────────────

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Control Room', href: '/control-room' },
                { title: 'Live Map', href: '#' },
            ]}
        >
            <Head title="Live Map — Control Room" />

            {/* Inject pulse animation CSS */}
            <style dangerouslySetInnerHTML={{ __html: pulseStyle }} />

            <div className="w-full space-y-4 p-4">
                <PageHeader
                    title="Live Map"
                    description="Real-time positions of tracked vehicles and residents."
                    backHref="/control-room"
                    backLabel="Control Room"
                    actions={
                        <Button variant="outline" size="sm" onClick={manualRefresh}>
                            <RefreshCw className="mr-2 h-4 w-4" />
                            Refresh
                        </Button>
                    }
                />

                {/* Stats Bar */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="rounded-lg bg-status-info-bg p-2 dark:bg-status-info">
                                <Radio className="h-5 w-5 text-status-info" />
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">Total Devices</p>
                                <p className="text-2xl font-bold">{stats.total_devices}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="rounded-lg bg-status-success-bg p-2 dark:bg-status-success">
                                <Wifi className="h-5 w-5 text-status-success" />
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">Online</p>
                                <p className="text-2xl font-bold text-status-success">{stats.online}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="rounded-lg bg-status-critical-bg p-2 dark:bg-status-critical">
                                <WifiOff className="h-5 w-5 text-status-critical" />
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">Offline</p>
                                <p className="text-2xl font-bold text-status-critical">{stats.offline}</p>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="flex items-center gap-3 p-4">
                            <div className="rounded-lg bg-status-warning-bg p-2 dark:bg-status-warning">
                                <AlertTriangle className="h-5 w-5 text-status-warning" />
                            </div>
                            <div>
                                <p className="text-xs text-muted-foreground">Active Alerts</p>
                                <p className="text-2xl font-bold text-status-warning">
                                    {stats.active_alerts}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Map + Sidebar Layout */}
                <div className="flex flex-col gap-4 lg:flex-row">
                    {/* Sidebar Filters */}
                    <div className="w-full shrink-0 space-y-4 lg:w-64">
                        <Card>
                            <CardContent className="space-y-4 p-4">
                                <h3 className="text-sm font-semibold">Filters</h3>

                                <div className="space-y-1">
                                    <label className="text-xs text-muted-foreground">Site</label>
                                    <Select
                                        value={filters.site_id || 'all'}
                                        onValueChange={(v) => applyFilter('site_id', v)}
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="All Sites" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Sites</SelectItem>
                                            {all_sites.map((s) => (
                                                <SelectItem key={s.id} value={s.id.toString()}>
                                                    {s.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-1">
                                    <label className="text-xs text-muted-foreground">
                                        Device Type
                                    </label>
                                    <Select
                                        value={filters.type || 'all'}
                                        onValueChange={(v) => applyFilter('type', v)}
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="All Types" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All Trackers</SelectItem>
                                            <SelectItem value="vehicle_tracker">
                                                Vehicle Tracker
                                            </SelectItem>
                                            <SelectItem value="personal_tracker">
                                                Resident Tracker
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-1">
                                    <label className="text-xs text-muted-foreground">Status</label>
                                    <Select
                                        value={filters.status || 'all'}
                                        onValueChange={(v) => applyFilter('status', v)}
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="All Statuses" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">All</SelectItem>
                                            <SelectItem value="online">Online</SelectItem>
                                            <SelectItem value="offline">Offline</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                <div className="space-y-1">
                                    <label className="text-xs text-muted-foreground">
                                        Alert Only
                                    </label>
                                    <Select
                                        value={filters.alert_only || '0'}
                                        onValueChange={(v) => applyFilter('alert_only', v)}
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="No" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="0">Show All</SelectItem>
                                            <SelectItem value="1">Alerts Only</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>

                                {hasFilters && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="w-full"
                                        onClick={clearFilters}
                                    >
                                        Clear Filters
                                    </Button>
                                )}
                            </CardContent>
                        </Card>

                        {/* Legend */}
                        <Card>
                            <CardContent className="space-y-2 p-4">
                                <h3 className="text-sm font-semibold">Legend</h3>
                                <div className="space-y-1.5 text-xs">
                                    <div className="flex items-center gap-2">
                                        <span className="inline-block h-3 w-3 rounded-full bg-status-success" />
                                        <span>Vehicle - Online</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="inline-block h-3 w-3 rounded-full bg-status-info" />
                                        <span>Vehicle - Moving</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="inline-block h-3 w-3 rounded-full bg-status-critical" />
                                        <span>Vehicle - Offline</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="inline-block h-3 w-3 rounded-full bg-primary" />
                                        <span>Resident Tracker</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="inline-block h-3 w-3 rounded-full bg-status-warning" />
                                        <span>Site</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span className="relative inline-block h-3 w-3">
                                            <span className="absolute inset-0 animate-ping rounded-full bg-status-critical opacity-75" />
                                            <span className="inline-block h-3 w-3 rounded-full bg-status-critical" />
                                        </span>
                                        <span>Active Alert</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <span
                                            className="inline-block h-3 w-6 rounded border border-primary bg-primary/10 opacity-60"
                                            style={{ borderStyle: 'dashed' }}
                                        />
                                        <span>Geofence</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Device List */}
                        <Card>
                            <CardContent className="p-4">
                                <h3 className="mb-2 text-sm font-semibold">
                                    Devices ({devices.length})
                                </h3>
                                <div className="max-h-64 space-y-1 overflow-y-auto">
                                    {devices.length === 0 && (
                                        <p className="py-2 text-xs text-muted-foreground">
                                            No devices with location data.
                                        </p>
                                    )}
                                    {devices.map((d) => (
                                        <button
                                            key={d.id}
                                            type="button"
                                            className="flex w-full items-center gap-2 rounded px-2 py-1.5 text-left text-xs transition-colors hover:bg-muted"
                                            onClick={() => {
                                                if (mapInstanceRef.current) {
                                                    mapInstanceRef.current.setView(
                                                        [d.latitude, d.longitude],
                                                        16,
                                                    );
                                                }
                                            }}
                                        >
                                            <span
                                                className={`inline-block h-2.5 w-2.5 shrink-0 rounded-full ${
                                                    d.type === 'personal_tracker'
                                                        ? 'bg-primary'
                                                        : d.status === 'online'
                                                          ? isRecentlySeen(d.last_seen_at, 5)
                                                              ? 'bg-status-info'
                                                              : 'bg-status-success'
                                                          : 'bg-status-critical'
                                                }`}
                                            />
                                            <span className="min-w-0 flex-1 truncate">
                                                {d.name || d.device_uid}
                                            </span>
                                            {d.battery_level !== null && d.battery_level <= 20 && (
                                                <Badge
                                                    variant="destructive"
                                                    className="shrink-0 px-1 text-[10px]"
                                                >
                                                    {d.battery_level}%
                                                </Badge>
                                            )}
                                        </button>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Map Container */}
                    <div className="flex-1">
                        <Card className="overflow-hidden">
                            <CardContent className="p-0">
                                <div
                                    ref={mapRef}
                                    className="min-h-[600px] w-full lg:min-h-[700px]"
                                    style={{ zIndex: 0 }}
                                />
                            </CardContent>
                        </Card>
                        <div className="mt-2 flex items-center justify-between text-xs text-muted-foreground">
                            <div className="flex items-center gap-1">
                                <Activity className="h-3 w-3" />
                                <span>
                                    Auto-refreshing every 30s | Last:{' '}
                                    {lastRefreshed.toLocaleTimeString()}
                                </span>
                            </div>
                            <div className="flex items-center gap-1">
                                <MapPin className="h-3 w-3" />
                                <span>
                                    {devices.length} devices, {sites.length} sites,{' '}
                                    {alerts.length} alerts
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
