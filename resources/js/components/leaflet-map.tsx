import { cn } from '@/lib/utils';
import 'leaflet/dist/leaflet.css';
import { useEffect, useRef } from 'react';

// Lazy-load leaflet to avoid SSR issues
let L: typeof import('leaflet') | null = null;

export type MapMarker = {
    id: string | number;
    lat: number;
    lng: number;
    title?: string;
    type?: 'vehicle' | 'house' | 'asset' | 'default';
    status?: 'online' | 'offline' | 'idle' | 'moving' | string;
    heading?: number;
    popup?: string;
    speed?: number;
};

export type MapGeofence = {
    id: string | number;
    name?: string;
    type: 'circle' | 'polygon';
    center?: { lat: number; lng: number };
    radius_m?: number;
    coordinates?: { lat: number; lng: number }[];
    color?: string;
};

type PolylineOptions = {
    animated?: boolean;
    showArrows?: boolean;
    showEndpoints?: boolean;
    color?: string;
};

type LeafletMapProps = {
    center: { lat: number; lng: number };
    zoom?: number;
    markers?: MapMarker[];
    polyline?: { lat: number; lng: number }[];
    polylineOptions?: PolylineOptions;
    geofences?: MapGeofence[];
    height?: number | string;
    className?: string;
    clustering?: boolean;
    darkMode?: boolean;
    onMarkerClick?: (id: string | number) => void;
    onMapClick?: (latlng: { lat: number; lng: number }) => void;
};

const STREET_TILE_URL =
    'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png';
const STREET_TILE_ATTRIBUTION =
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/">CARTO</a>';

const DARK_TILE_URL =
    'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png';
const DARK_TILE_ATTRIBUTION =
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/">CARTO</a>';

const SATELLITE_TILE_URL =
    'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
const SATELLITE_TILE_ATTRIBUTION =
    '&copy; <a href="https://www.esri.com/">Esri</a>, Earthstar Geographics';

// ── Marker styling helpers ──────────────────────────────────────────────────

function getMarkerColor(marker: MapMarker): string {
    if (marker.type === 'house') return '#8b5cf6'; // purple
    if (marker.type === 'asset') return '#f59e0b'; // amber
    if (marker.status === 'moving') return '#3b82f6'; // blue
    if (marker.status === 'online') return '#22c55e'; // green
    if (marker.status === 'offline') return '#ef4444'; // red
    if (marker.status === 'idle') return '#eab308'; // yellow
    return '#3b82f6'; // blue default
}

const CAR_SVG = `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>`;

function createVehicleDivIcon(
    leaflet: typeof import('leaflet'),
    marker: MapMarker,
) {
    const color = getMarkerColor(marker);
    const rotation = marker.heading ?? 0;
    const isMoving = marker.status === 'moving';
    const label = marker.title ?? '';
    const speedBadge =
        isMoving && marker.speed != null
            ? `<div style="position:absolute;top:-8px;right:-8px;background:#3b82f6;color:white;font-size:9px;font-weight:700;padding:1px 4px;border-radius:8px;white-space:nowrap;">${marker.speed} km/h</div>`
            : '';
    const pulse = isMoving
        ? `<div style="position:absolute;inset:-6px;border-radius:50%;border:2px solid ${color};animation:leaflet-pulse 1.5s ease-out infinite;"></div>`
        : '';

    return leaflet.divIcon({
        className: 'leaflet-marker-custom',
        html: `<div style="position:relative;display:flex;flex-direction:column;align-items:center;">
            ${pulse}
            <div style="
                display:flex;align-items:center;justify-content:center;
                width:36px;height:36px;border-radius:50%;
                background:${color};border:3px solid white;
                box-shadow:0 2px 8px rgba(0,0,0,0.3);
                transform:rotate(${rotation}deg);
                cursor:pointer;position:relative;
            ">${CAR_SVG}${speedBadge}</div>
            ${label ? `<div style="margin-top:2px;font-size:10px;font-weight:600;color:#374151;background:rgba(255,255,255,0.9);padding:0 4px;border-radius:3px;white-space:nowrap;max-width:90px;overflow:hidden;text-overflow:ellipsis;">${label}</div>` : ''}
        </div>`,
        iconSize: [36, label ? 52 : 36],
        iconAnchor: [18, 18],
        popupAnchor: [0, -20],
    });
}

function createDefaultDivIcon(
    leaflet: typeof import('leaflet'),
    marker: MapMarker,
) {
    const color = getMarkerColor(marker);
    const icons: Record<string, string> = {
        house: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        asset: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/></svg>',
    };
    const svgIcon =
        icons[marker.type ?? ''] ??
        '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>';
    const rotation = marker.heading ?? 0;

    return leaflet.divIcon({
        className: 'leaflet-marker-custom',
        html: `<div style="
            display:flex;align-items:center;justify-content:center;
            width:36px;height:36px;border-radius:50%;
            background:${color};border:3px solid white;
            box-shadow:0 2px 8px rgba(0,0,0,0.3);
            transform:rotate(${rotation}deg);cursor:pointer;
        ">${svgIcon}</div>`,
        iconSize: [36, 36],
        iconAnchor: [18, 18],
        popupAnchor: [0, -20],
    });
}

function createDivIcon(leaflet: typeof import('leaflet'), marker: MapMarker) {
    if (marker.type === 'vehicle') {
        return createVehicleDivIcon(leaflet, marker);
    }
    return createDefaultDivIcon(leaflet, marker);
}

// ── Clustering helpers ──────────────────────────────────────────────────────

type ClusterGroup = {
    lat: number;
    lng: number;
    markers: MapMarker[];
};

function clusterMarkers(markers: MapMarker[], zoom: number): ClusterGroup[] {
    if (markers.length <= 20) {
        return markers.map((m) => ({ lat: m.lat, lng: m.lng, markers: [m] }));
    }

    // Grid cell size shrinks as zoom increases
    const gridSize = 360 / Math.pow(2, zoom);
    const buckets: Record<string, ClusterGroup> = {};

    for (const m of markers) {
        const cellX = Math.floor(m.lng / gridSize);
        const cellY = Math.floor(m.lat / gridSize);
        const key = `${cellX}_${cellY}`;
        if (!buckets[key]) {
            buckets[key] = { lat: 0, lng: 0, markers: [] };
        }
        buckets[key].markers.push(m);
    }

    return Object.values(buckets).map((group) => {
        const avgLat =
            group.markers.reduce((s, m) => s + m.lat, 0) / group.markers.length;
        const avgLng =
            group.markers.reduce((s, m) => s + m.lng, 0) / group.markers.length;
        return { lat: avgLat, lng: avgLng, markers: group.markers };
    });
}

function createClusterIcon(leaflet: typeof import('leaflet'), count: number) {
    const size = count < 10 ? 36 : count < 50 ? 42 : 48;
    const bg = count < 10 ? '#3b82f6' : count < 50 ? '#f59e0b' : '#ef4444';

    return leaflet.divIcon({
        className: 'leaflet-marker-cluster',
        html: `<div style="
            display:flex;align-items:center;justify-content:center;
            width:${size}px;height:${size}px;border-radius:50%;
            background:${bg};color:white;font-weight:700;font-size:${size < 42 ? 13 : 15}px;
            border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.35);
            cursor:pointer;
        ">${count}</div>`,
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2],
    });
}

// ── Polyline direction arrows ───────────────────────────────────────────────

function addDirectionArrows(
    leaflet: typeof import('leaflet'),
    layer: any,
    points: { lat: number; lng: number }[],
    color: string,
) {
    const ARROW_INTERVAL = 5; // every 5th segment
    for (let i = ARROW_INTERVAL; i < points.length; i += ARROW_INTERVAL) {
        const prev = points[i - 1];
        const curr = points[i];
        if (!prev || !curr) continue;
        const angle =
            (Math.atan2(curr.lng - prev.lng, curr.lat - prev.lat) * 180) /
            Math.PI;
        const midLat = (prev.lat + curr.lat) / 2;
        const midLng = (prev.lng + curr.lng) / 2;

        const arrowIcon = leaflet.divIcon({
            className: 'leaflet-arrow-icon',
            html: `<div style="
                transform:rotate(${90 - angle}deg);
                color:${color};font-size:16px;font-weight:bold;line-height:1;
            ">&#9654;</div>`,
            iconSize: [16, 16],
            iconAnchor: [8, 8],
        });
        layer.addLayer(
            leaflet.marker([midLat, midLng], {
                icon: arrowIcon,
                interactive: false,
            }),
        );
    }
}

function createEndpointIcon(
    leaflet: typeof import('leaflet'),
    type: 'start' | 'end',
) {
    const bg = type === 'start' ? '#22c55e' : '#ef4444';
    const label = type === 'start' ? 'A' : 'B';
    return leaflet.divIcon({
        className: 'leaflet-endpoint-icon',
        html: `<div style="
            display:flex;align-items:center;justify-content:center;
            width:28px;height:28px;border-radius:50%;
            background:${bg};color:white;font-weight:700;font-size:13px;
            border:2px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.3);
        ">${label}</div>`,
        iconSize: [28, 28],
        iconAnchor: [14, 14],
    });
}

// ── Inject global styles ────────────────────────────────────────────────────

let stylesInjected = false;
function injectMapStyles() {
    if (stylesInjected || typeof document === 'undefined') return;
    stylesInjected = true;
    const style = document.createElement('style');
    style.textContent = `
        @keyframes leaflet-pulse {
            0% { transform:scale(1); opacity:1; }
            100% { transform:scale(2.2); opacity:0; }
        }
        @keyframes leaflet-dash-flow {
            to { stroke-dashoffset: -20; }
        }
        .leaflet-animated-polyline {
            stroke-dasharray: 10 10;
            animation: leaflet-dash-flow 1s linear infinite;
        }
        .leaflet-marker-custom, .leaflet-marker-cluster,
        .leaflet-arrow-icon, .leaflet-endpoint-icon {
            background: transparent !important;
            border: none !important;
        }
    `;
    document.head.appendChild(style);
}

// ── Main component ──────────────────────────────────────────────────────────

export default function LeafletMap({
    center,
    zoom = 13,
    markers = [],
    polyline,
    polylineOptions,
    geofences = [],
    height = 400,
    className,
    clustering = false,
    darkMode,
    onMarkerClick,
    onMapClick,
}: LeafletMapProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<any>(null);
    const markersLayerRef = useRef<any>(null);
    const polylineLayerRef = useRef<any>(null);
    const geofenceLayerRef = useRef<any>(null);
    const tileLayerRef = useRef<any>(null);
    const satelliteLayerRef = useRef<any>(null);
    const layerControlRef = useRef<any>(null);
    const initRef = useRef(false);
    const onMapClickRef = useRef(onMapClick);

    // Resolve dark mode: explicit prop or auto-detect from html element
    const resolvedDark =
        darkMode ??
        (typeof document !== 'undefined' &&
            document.documentElement.classList.contains('dark'));

    // Initialize map
    useEffect(() => {
        if (typeof window === 'undefined' || !containerRef.current) return;
        injectMapStyles();

        let cancelled = false;

        (async () => {
            if (!L) {
                const leaflet = await import('leaflet');
                L = leaflet.default ?? leaflet;

                // Fix default marker icon paths
                delete (L.Icon.Default.prototype as any)._getIconUrl;
                L.Icon.Default.mergeOptions({
                    iconRetinaUrl:
                        'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png',
                    iconUrl:
                        'https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png',
                    shadowUrl:
                        'https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png',
                });
            }

            if (cancelled || !containerRef.current) return;

            if (!mapRef.current) {
                mapRef.current = L.map(containerRef.current, {
                    center: [center.lat, center.lng],
                    zoom,
                    zoomControl: true,
                    attributionControl: true,
                });

                const isDark = resolvedDark;
                const streetUrl = isDark ? DARK_TILE_URL : STREET_TILE_URL;
                const streetAttr = isDark
                    ? DARK_TILE_ATTRIBUTION
                    : STREET_TILE_ATTRIBUTION;

                const streetLayer = L.tileLayer(streetUrl, {
                    attribution: streetAttr,
                    maxZoom: 19,
                });
                const satelliteLayer = L.tileLayer(SATELLITE_TILE_URL, {
                    attribution: SATELLITE_TILE_ATTRIBUTION,
                    maxZoom: 19,
                });

                streetLayer.addTo(mapRef.current);
                tileLayerRef.current = streetLayer;
                satelliteLayerRef.current = satelliteLayer;

                layerControlRef.current = L.control
                    .layers(
                        { Street: streetLayer, Satellite: satelliteLayer },
                        {},
                        { position: 'topright' },
                    )
                    .addTo(mapRef.current);

                markersLayerRef.current = L.layerGroup().addTo(mapRef.current);
                polylineLayerRef.current = L.layerGroup().addTo(mapRef.current);
                geofenceLayerRef.current = L.layerGroup().addTo(mapRef.current);

                // Map click handler
                mapRef.current.on('click', (e: any) => {
                    if (onMapClickRef.current) {
                        onMapClickRef.current({
                            lat: e.latlng.lat,
                            lng: e.latlng.lng,
                        });
                    }
                });

                initRef.current = true;
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [center.lat, center.lng, resolvedDark, zoom]);

    // Keep onMapClick ref current
    useEffect(() => {
        onMapClickRef.current = onMapClick;
    }, [onMapClick]);

    // Swap tile layers when dark mode changes
    useEffect(() => {
        if (!mapRef.current || !tileLayerRef.current || !L || !initRef.current)
            return;
        const isDark = resolvedDark;

        // Remove existing layers and control
        if (layerControlRef.current)
            mapRef.current.removeControl(layerControlRef.current);
        mapRef.current.removeLayer(tileLayerRef.current);
        if (
            satelliteLayerRef.current &&
            mapRef.current.hasLayer(satelliteLayerRef.current)
        ) {
            mapRef.current.removeLayer(satelliteLayerRef.current);
        }

        const streetUrl = isDark ? DARK_TILE_URL : STREET_TILE_URL;
        const streetAttr = isDark
            ? DARK_TILE_ATTRIBUTION
            : STREET_TILE_ATTRIBUTION;

        const streetLayer = L.tileLayer(streetUrl, {
            attribution: streetAttr,
            maxZoom: 19,
        });
        const satelliteLayer = L.tileLayer(SATELLITE_TILE_URL, {
            attribution: SATELLITE_TILE_ATTRIBUTION,
            maxZoom: 19,
        });

        streetLayer.addTo(mapRef.current);
        tileLayerRef.current = streetLayer;
        satelliteLayerRef.current = satelliteLayer;

        layerControlRef.current = L.control
            .layers(
                { Street: streetLayer, Satellite: satelliteLayer },
                {},
                { position: 'topright' },
            )
            .addTo(mapRef.current);
    }, [resolvedDark]);

    // Update center/zoom
    useEffect(() => {
        if (!mapRef.current || !initRef.current) return;
        mapRef.current.setView([center.lat, center.lng], zoom);
    }, [center.lat, center.lng, zoom]);

    // Update markers (with optional clustering)
    useEffect(() => {
        if (!markersLayerRef.current || !L || !initRef.current) return;
        markersLayerRef.current.clearLayers();

        const map = mapRef.current;

        const renderMarkers = () => {
            if (!markersLayerRef.current || !L) return;
            markersLayerRef.current.clearLayers();

            const currentZoom = map?.getZoom() ?? zoom;

            if (clustering && markers.length > 20) {
                const clusters = clusterMarkers(markers, currentZoom);

                clusters.forEach((group) => {
                    if (group.markers.length === 1) {
                        const m = group.markers[0];
                        const icon = createDivIcon(L!, m);
                        const leafletMarker = L!.marker([m.lat, m.lng], {
                            icon,
                        });
                        if (m.popup || m.title) {
                            leafletMarker.bindPopup(
                                `<div class="text-sm font-medium">${m.title ?? ''}</div>${m.popup ? `<div class="text-xs text-muted-foreground mt-1">${m.popup}</div>` : ''}`,
                            );
                        }
                        if (onMarkerClick)
                            leafletMarker.on('click', () =>
                                onMarkerClick(m.id),
                            );
                        markersLayerRef.current.addLayer(leafletMarker);
                    } else {
                        const clusterIcon = createClusterIcon(
                            L!,
                            group.markers.length,
                        );
                        const clusterMarker = L!.marker(
                            [group.lat, group.lng],
                            { icon: clusterIcon },
                        );
                        clusterMarker.on('click', () => {
                            const bounds = L!.latLngBounds(
                                group.markers.map(
                                    (m) => [m.lat, m.lng] as [number, number],
                                ),
                            );
                            map?.fitBounds(bounds, { padding: [40, 40] });
                        });
                        markersLayerRef.current.addLayer(clusterMarker);
                    }
                });
            } else {
                markers.forEach((m) => {
                    const icon = createDivIcon(L!, m);
                    const leafletMarker = L!.marker([m.lat, m.lng], { icon });

                    if (m.popup || m.title) {
                        leafletMarker.bindPopup(
                            `<div class="text-sm font-medium">${m.title ?? ''}</div>${m.popup ? `<div class="text-xs text-muted-foreground mt-1">${m.popup}</div>` : ''}`,
                        );
                    }

                    if (onMarkerClick)
                        leafletMarker.on('click', () => onMarkerClick(m.id));
                    markersLayerRef.current.addLayer(leafletMarker);
                });
            }
        };

        renderMarkers();

        // Re-cluster on zoom change when clustering is enabled
        if (clustering && markers.length > 20) {
            const onZoom = () => renderMarkers();
            map?.on('zoomend', onZoom);
            return () => {
                map?.off('zoomend', onZoom);
            };
        }

        // Auto-fit bounds if multiple markers
        if (markers.length > 1) {
            const bounds = L.latLngBounds(
                markers.map((m) => [m.lat, m.lng] as [number, number]),
            );
            map?.fitBounds(bounds, { padding: [40, 40] });
        }
    }, [markers, onMarkerClick, clustering, zoom]);

    // Update polyline with enhancements (animated dash, direction arrows, endpoints)
    useEffect(() => {
        if (!polylineLayerRef.current || !L || !initRef.current) return;
        polylineLayerRef.current.clearLayers();

        if (!polyline || polyline.length < 2) return;

        const opts = polylineOptions ?? {};
        const color = opts.color ?? '#3b82f6';

        const line = L.polyline(
            polyline.map((p) => [p.lat, p.lng] as [number, number]),
            {
                color,
                weight: 4,
                opacity: 0.8,
                className: opts.animated
                    ? 'leaflet-animated-polyline'
                    : undefined,
            },
        );
        polylineLayerRef.current.addLayer(line);

        // Direction arrows along the polyline
        if (opts.showArrows !== false && polyline.length > 2) {
            addDirectionArrows(L, polylineLayerRef.current, polyline, color);
        }

        // Start (green A) / End (red B) markers
        if (opts.showEndpoints !== false) {
            const startPt = polyline[0];
            const endPt = polyline[polyline.length - 1];
            if (startPt) {
                const startIcon = createEndpointIcon(L, 'start');
                polylineLayerRef.current.addLayer(
                    L.marker([startPt.lat, startPt.lng], {
                        icon: startIcon,
                        interactive: false,
                    }),
                );
            }
            if (endPt) {
                const endIcon = createEndpointIcon(L, 'end');
                polylineLayerRef.current.addLayer(
                    L.marker([endPt.lat, endPt.lng], {
                        icon: endIcon,
                        interactive: false,
                    }),
                );
            }
        }
    }, [polyline, polylineOptions]);

    // Update geofences
    useEffect(() => {
        if (!geofenceLayerRef.current || !L || !initRef.current) return;
        geofenceLayerRef.current.clearLayers();

        geofences.forEach((gf) => {
            const color = gf.color ?? '#ef4444';
            if (gf.type === 'circle' && gf.center && gf.radius_m) {
                const circle = L!.circle([gf.center.lat, gf.center.lng], {
                    radius: gf.radius_m,
                    color,
                    fillColor: color,
                    fillOpacity: 0.1,
                    weight: 2,
                });
                if (gf.name) circle.bindPopup(gf.name);
                geofenceLayerRef.current.addLayer(circle);
            } else if (gf.type === 'polygon' && gf.coordinates?.length) {
                const polygon = L!.polygon(
                    gf.coordinates.map(
                        (c) => [c.lat, c.lng] as [number, number],
                    ),
                    { color, fillColor: color, fillOpacity: 0.1, weight: 2 },
                );
                if (gf.name) polygon.bindPopup(gf.name);
                geofenceLayerRef.current.addLayer(polygon);
            }
        });
    }, [geofences]);

    // Clean up
    useEffect(() => {
        return () => {
            if (mapRef.current) {
                mapRef.current.remove();
                mapRef.current = null;
                initRef.current = false;
            }
        };
    }, []);

    const heightStyle = typeof height === 'number' ? `${height}px` : height;

    return (
        <div
            className={cn(
                'relative w-full overflow-hidden rounded-lg border border-border',
                className,
            )}
            style={{ zIndex: 0, isolation: 'isolate' }}
        >
            <div
                ref={containerRef}
                className="w-full"
                style={{ height: heightStyle }}
            />
        </div>
    );
}
