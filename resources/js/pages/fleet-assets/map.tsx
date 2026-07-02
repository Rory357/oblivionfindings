import LeafletMap, { MapGeofence, MapMarker } from '@/components/leaflet-map';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatRelativeTime } from '@/lib/fleet-utils';
import { cn } from '@/lib/utils';
import {
    DOT_CLASS,
    fmt,
    HeroStatusPill,
    type Tone,
} from '@/pages/fleet-assets/components/fleet-hero-kit';
import { Head, Link, router } from '@inertiajs/react';
import {
    Car,
    Home,
    Layers,
    Search,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';

type VehicleMarker = {
    id: number;
    name: string;
    asset_tag: string;
    type: string;
    lat: number;
    lng: number;
    status: string;
    speed_kph: number;
    heading_deg: number | null;
    last_seen_at: string | null;
    home_site: string | null;
    consent_blocked?: boolean;
};

type HouseMarker = {
    id: number;
    name: string;
    type: string;
    address: string | null;
    lat: number;
    lng: number;
};

type GeofenceData = {
    id: number;
    name: string;
    type: string;
    breach_type: string | null;
    shape: unknown;
    asset: { id: number; name: string } | null;
};

type Props = {
    vehicle_markers: VehicleMarker[];
    house_markers: HouseMarker[];
    geofences: GeofenceData[];
    open_alerts: number;
};

/** Slim variant of the hero shell — same app-primary gradient chrome as HeroShell but a
 *  single compact band (~80px) so the map keeps the vertical space. Local to the map page;
 *  the shared HeroShell stays the one full-height shell. */
function SlimHeroShell({ children }: { children: ReactNode }) {
    return (
        <div className="relative shrink-0 overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 text-primary-foreground shadow-[0_24px_60px_-28px_color-mix(in_oklch,var(--primary)_55%,transparent)]">
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl">
                <div className="absolute -top-20 -right-16 h-48 w-48 rounded-full bg-primary-foreground/5" />
                <div className="absolute -bottom-24 left-1/3 h-40 w-40 rounded-full bg-primary-foreground/5" />
            </div>
            <div className="relative px-5 py-3.5">{children}</div>
        </div>
    );
}

/** Compact dot-led metric for the slim map hero — the map stays dominant, so no tiles. */
function SlimStat({
    label,
    value,
    tone,
    href,
}: {
    label: string;
    value: string;
    tone: Tone;
    href?: string;
}) {
    const inner = (
        <>
            <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', DOT_CLASS[tone])} />
            <span className="text-lg leading-none font-bold tabular-nums text-primary-foreground">{value}</span>
            <span className="text-[10.5px] font-semibold tracking-wide text-primary-foreground/70 uppercase">{label}</span>
        </>
    );
    const base = 'inline-flex items-center gap-1.5 rounded-lg px-2 py-1';
    return href ? (
        <Link href={href} className={cn(base, 'transition-colors hover:bg-primary-foreground/15')}>
            {inner}
        </Link>
    ) : (
        <span className={base}>{inner}</span>
    );
}

export default function FleetAssetsMap({ vehicle_markers, house_markers, geofences, open_alerts }: Props) {
    const [searchTerm, setSearchTerm] = useState('');
    const [showVehicles, setShowVehicles] = useState(true);
    const [showHouses, setShowHouses] = useState(true);
    const [showGeofences, setShowGeofences] = useState(true);
    const [selectedId, setSelectedId] = useState<string | number | null>(null);
    // Real-time position overrides from WebSocket broadcasts
    const [realtimePositions, setRealtimePositions] = useState<Record<number, Partial<VehicleMarker>>>({});

    // Fallback: 30-second polling for when Echo/WebSocket is not configured
    useEffect(() => {
        const interval = window.setInterval(() => {
            if (document.hidden) return;
            router.reload({ only: ['vehicle_markers'] });
            // Clear real-time overrides on full reload to avoid stale data
            setRealtimePositions({});
        }, 30000);
        return () => window.clearInterval(interval);
    }, []);

    // Real-time WebSocket listener for vehicle position updates
    // NOTE: Requires Laravel Echo + Reverb/Pusher to be installed and configured.
    // When Echo is not available, the 30-second polling above acts as fallback.
    useEffect(() => {
        if (typeof window !== 'undefined' && (window as any).Echo) {
            const channel = (window as any).Echo.channel('fleet.vehicles');
            channel.listen('.position.updated', (data: any) => {
                setRealtimePositions((prev) => ({
                    ...prev,
                    [data.assetId]: {
                        lat: data.latitude,
                        lng: data.longitude,
                        speed_kph: data.speed_kph ?? 0,
                        heading_deg: data.heading_deg ?? null,
                        status: data.status ?? 'online',
                    },
                }));
            });
            return () => {
                channel.stopListening('.position.updated');
            };
        }
    }, []);

    // Fleet-wide hero counts — unfiltered (search only narrows the list/map, not the truth),
    // merged with any live WebSocket position overrides.
    const heroStats = useMemo(() => {
        const merged = (vehicle_markers ?? []).map((v) => {
            const rt = realtimePositions[v.id];
            return rt ? { ...v, ...rt } : v;
        });
        return {
            online: merged.filter((v) => v.status === 'online').length,
            moving: merged.filter((v) => (v.speed_kph ?? 0) > 0).length,
            idle: merged.filter((v) => v.status === 'idle').length,
        };
    }, [vehicle_markers, realtimePositions]);

    const filteredVehicles = useMemo(() => {
        const term = searchTerm.trim().toLowerCase();
        return (vehicle_markers ?? [])
            .filter((v) => {
                const name = (v.name ?? v.asset_tag ?? '').toLowerCase();
                return !term || name.includes(term);
            })
            .map((v) => {
                // Merge any real-time position updates from WebSocket
                const rt = realtimePositions[v.id];
                if (rt) {
                    return { ...v, ...rt };
                }
                return v;
            });
    }, [vehicle_markers, searchTerm, realtimePositions]);

    const filteredHouses = useMemo(() => {
        const term = searchTerm.trim().toLowerCase();
        return (house_markers ?? []).filter((h) => {
            const name = (h.name ?? '').toLowerCase();
            return !term || name.includes(term);
        });
    }, [house_markers, searchTerm]);

    const markers = useMemo<MapMarker[]>(() => {
        const result: MapMarker[] = [];

        if (showVehicles) {
            filteredVehicles
                .filter((v) => v.lat && v.lng)
                .forEach((v) => {
                    const lastSeen = v.last_seen_at ? formatRelativeTime(v.last_seen_at) : 'unknown';
                    const staleMinutes = v.last_seen_at
                        ? Math.floor((Date.now() - new Date(v.last_seen_at).getTime()) / 60000)
                        : null;
                    const staleWarning = staleMinutes !== null && staleMinutes > 5
                        ? `<div style="color:#ef4444;font-weight:600;font-size:11px;">⚠ Last seen ${lastSeen}</div>`
                        : `<div style="font-size:11px;color:#6b7280;">Seen ${lastSeen}</div>`;

                    result.push({
                        id: `v-${v.id}`,
                        lat: Number(v.lat),
                        lng: Number(v.lng),
                        title: v.name ?? v.asset_tag ?? `Vehicle ${v.id}`,
                        type: 'vehicle',
                        status: staleMinutes !== null && staleMinutes > 5 ? 'offline' : v.status,
                        heading: v.heading_deg ?? undefined,
                        speed: v.speed_kph ?? 0,
                        popup: `Speed: ${v.speed_kph ?? 0} km/h${staleWarning}`,
                    });
                });
        }

        if (showHouses) {
            filteredHouses
                .filter((h) => h.lat && h.lng)
                .forEach((h) => {
                    result.push({
                        id: `h-${h.id}`,
                        lat: Number(h.lat),
                        lng: Number(h.lng),
                        title: h.name,
                        type: 'house',
                        popup: h.address ?? '',
                    });
                });
        }

        return result;
    }, [filteredVehicles, filteredHouses, showVehicles, showHouses]);

    const mapGeofences = useMemo<MapGeofence[]>(() => {
        if (!showGeofences) return [];
        return (geofences ?? []).map((gf) => {
            const shape = (gf.shape ?? {}) as Record<string, unknown>;
            return {
                id: gf.id,
                name: gf.name,
                type: (gf.type ?? 'polygon') as 'circle' | 'polygon',
                center: shape.center as { lat: number; lng: number } | undefined,
                radius_m: shape.radius_m as number | undefined,
                coordinates: shape.coordinates as { lat: number; lng: number }[] | undefined,
            };
        });
    }, [geofences, showGeofences]);

    const center = useMemo(() => {
        const firstVehicle = (vehicle_markers ?? []).find((v) => v.lat && v.lng);
        if (firstVehicle) {
            return { lat: Number(firstVehicle.lat), lng: Number(firstVehicle.lng) };
        }
        return { lat: -36.8485, lng: 174.7633 };
    }, [vehicle_markers]);

    const handleMarkerClick = useCallback((id: string | number) => {
        setSelectedId(id);
    }, []);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Live Map', href: '/fleet-assets/map' },
            ]}
        >
            <Head title="Live Map" />
            <div className="flex flex-col gap-3 p-3" style={{ height: 'calc(100vh - 4rem)' }}>
                {/* Slim command band — the map keeps the vertical space below. */}
                <SlimHeroShell>
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <HeroStatusPill>Live map · real-time</HeroStatusPill>
                        <h1 className="text-lg leading-none font-bold tracking-tight">Live Map</h1>
                        <div className="ml-auto flex flex-wrap items-center gap-1">
                            <SlimStat
                                label="Online now"
                                value={fmt(heroStats.online)}
                                tone={heroStats.online > 0 ? 'success' : 'neutral'}
                            />
                            <SlimStat label="Moving" value={fmt(heroStats.moving)} tone="neutral" />
                            <SlimStat
                                label="Idle"
                                value={fmt(heroStats.idle)}
                                tone={heroStats.idle > 0 ? 'warning' : 'neutral'}
                            />
                            <SlimStat
                                label="Alerts"
                                value={fmt(open_alerts)}
                                tone={open_alerts > 0 ? 'critical' : 'success'}
                                href="/fleet-assets/alerts"
                            />
                        </div>
                    </div>
                </SlimHeroShell>

                <div className="relative min-h-0 flex-1">
                    <LeafletMap
                        center={center}
                        zoom={12}
                        markers={markers}
                        geofences={mapGeofences}
                        height="100%"
                        onMarkerClick={handleMarkerClick}
                    />

                    {/* Sidebar Overlay */}
                    <aside className="absolute left-4 top-4 z-[1000] w-80 rounded-lg border bg-card p-4 shadow-lg" style={{ maxHeight: 'calc(100vh - 14rem)', overflowY: 'auto' }}>
                    <div className="mb-4">
                        <div className="relative">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                placeholder="Search vehicles, houses..."
                                value={searchTerm}
                                onChange={(e) => setSearchTerm(e.target.value)}
                                className="pl-9"
                            />
                        </div>
                    </div>

                    {/* Filter Toggles */}
                    <div className="mb-4 flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant={showVehicles ? 'default' : 'secondary'}
                            size="xs"
                            onClick={() => setShowVehicles(!showVehicles)}
                            className="h-auto rounded-full px-3 py-1"
                        >
                            <Car className="h-3 w-3" /> Vehicles
                        </Button>
                        <Button
                            type="button"
                            variant={showHouses ? 'default' : 'secondary'}
                            size="xs"
                            onClick={() => setShowHouses(!showHouses)}
                            className="h-auto rounded-full px-3 py-1"
                        >
                            <Home className="h-3 w-3" /> Houses
                        </Button>
                        <Button
                            type="button"
                            variant={showGeofences ? 'destructive' : 'secondary'}
                            size="xs"
                            onClick={() => setShowGeofences(!showGeofences)}
                            className="h-auto rounded-full px-3 py-1"
                        >
                            <Layers className="h-3 w-3" /> Geofences
                        </Button>
                    </div>

                    {/* Vehicle List */}
                    {showVehicles && (
                        <div className="mb-4">
                            <div className="mb-2 text-xs font-semibold uppercase text-muted-foreground">
                                Vehicles ({filteredVehicles.length})
                            </div>
                            <div className="space-y-1">
                                {filteredVehicles.map((v) => (
                                    <Link
                                        key={v.id}
                                        href={`/fleet-assets/vehicles/${v.id}`}
                                        className={`flex items-center gap-2 rounded-md p-2 text-sm hover:bg-muted transition-colors ${
                                            selectedId === `v-${v.id}` ? 'bg-muted' : ''
                                        }`}
                                    >
                                        <span
                                            className={`h-2 w-2 shrink-0 rounded-full ${
                                                v.status === 'online'
                                                    ? 'bg-status-success'
                                                    : v.status === 'idle'
                                                    ? 'bg-status-warning'
                                                    : 'bg-muted'
                                            }`}
                                        />
                                        <div className="flex-1 min-w-0">
                                            <span className="truncate font-medium block">
                                                {v.name ?? v.asset_tag ?? `Vehicle ${v.id}`}
                                            </span>
                                            {v.consent_blocked ? (
                                                <span className="text-[10px] text-status-warning dark:text-status-warning">Location hidden (consent)</span>
                                            ) : v.last_seen_at ? (
                                                <span className="text-[10px] text-muted-foreground">
                                                    {formatRelativeTime(v.last_seen_at)}
                                                </span>
                                            ) : null}
                                        </div>
                                        {v.speed_kph ? (
                                            <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                                                {v.speed_kph} km/h
                                            </span>
                                        ) : null}
                                    </Link>
                                ))}
                                {filteredVehicles.length === 0 && (
                                    <p className="text-xs text-muted-foreground p-2">No vehicles found.</p>
                                )}
                            </div>
                        </div>
                    )}

                    {/* House List */}
                    {showHouses && (
                        <div>
                            <div className="mb-2 text-xs font-semibold uppercase text-muted-foreground">
                                Houses ({filteredHouses.length})
                            </div>
                            <div className="space-y-1">
                                {filteredHouses.map((h) => (
                                    <div
                                        key={h.id}
                                        className={`flex items-center gap-2 rounded-md p-2 text-sm hover:bg-muted transition-colors cursor-pointer ${
                                            selectedId === `h-${h.id}` ? 'bg-muted' : ''
                                        }`}
                                    >
                                        <Home className="h-3 w-3 shrink-0 text-primary" />
                                        <div className="min-w-0">
                                            <div className="truncate font-medium">{h.name}</div>
                                            <div className="truncate text-xs text-muted-foreground">{h.address}</div>
                                        </div>
                                    </div>
                                ))}
                                {filteredHouses.length === 0 && (
                                    <p className="text-xs text-muted-foreground p-2">No houses found.</p>
                                )}
                            </div>
                        </div>
                    )}
                    </aside>
                </div>
            </div>
        </AppLayout>
    );
}
