import LeafletMap, { MapGeofence, MapMarker } from '@/components/leaflet-map';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    Car,
    Home,
    Layers,
    Search,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

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
};

export default function FleetAssetsMap({ vehicle_markers, house_markers, geofences }: Props) {
    const [searchTerm, setSearchTerm] = useState('');
    const [showVehicles, setShowVehicles] = useState(true);
    const [showHouses, setShowHouses] = useState(true);
    const [showGeofences, setShowGeofences] = useState(true);
    const [selectedId, setSelectedId] = useState<string | number | null>(null);

    useEffect(() => {
        const interval = window.setInterval(() => {
            if (document.hidden) return;
            router.reload({ only: ['vehicle_markers'] });
        }, 30000);
        return () => window.clearInterval(interval);
    }, []);

    const filteredVehicles = useMemo(() => {
        const term = searchTerm.trim().toLowerCase();
        return (vehicle_markers ?? []).filter((v) => {
            const name = (v.name ?? v.asset_tag ?? '').toLowerCase();
            return !term || name.includes(term);
        });
    }, [vehicle_markers, searchTerm]);

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
                    result.push({
                        id: `v-${v.id}`,
                        lat: Number(v.lat),
                        lng: Number(v.lng),
                        title: v.name ?? v.asset_tag ?? `Vehicle ${v.id}`,
                        type: 'vehicle',
                        status: v.status,
                        popup: `Speed: ${v.speed_kph ?? 0} kph`,
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
            <div className="relative" style={{ height: 'calc(100vh - 4rem)' }}>
                <LeafletMap
                    center={center}
                    zoom={12}
                    markers={markers}
                    geofences={mapGeofences}
                    height="100%"
                    onMarkerClick={handleMarkerClick}
                />

                {/* Sidebar Overlay */}
                <div className="absolute left-4 top-4 z-[1000] w-80 rounded-lg border bg-card p-4 shadow-lg" style={{ maxHeight: 'calc(100vh - 8rem)', overflowY: 'auto' }}>
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
                        <button
                            onClick={() => setShowVehicles(!showVehicles)}
                            className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium transition-colors ${
                                showVehicles
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-muted-foreground'
                            }`}
                        >
                            <Car className="h-3 w-3" /> Vehicles
                        </button>
                        <button
                            onClick={() => setShowHouses(!showHouses)}
                            className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium transition-colors ${
                                showHouses
                                    ? 'bg-purple-600 text-white'
                                    : 'bg-muted text-muted-foreground'
                            }`}
                        >
                            <Home className="h-3 w-3" /> Houses
                        </button>
                        <button
                            onClick={() => setShowGeofences(!showGeofences)}
                            className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium transition-colors ${
                                showGeofences
                                    ? 'bg-red-600 text-white'
                                    : 'bg-muted text-muted-foreground'
                            }`}
                        >
                            <Layers className="h-3 w-3" /> Geofences
                        </button>
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
                                                    ? 'bg-green-500'
                                                    : 'bg-gray-400'
                                            }`}
                                        />
                                        <span className="truncate font-medium">
                                            {v.name ?? v.asset_tag ?? `Vehicle ${v.id}`}
                                        </span>
                                        {v.speed_kph ? (
                                            <span className="ml-auto text-xs text-muted-foreground">
                                                {v.speed_kph} kph
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
                                        <Home className="h-3 w-3 shrink-0 text-purple-500" />
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
                </div>
            </div>
        </AppLayout>
    );
}
