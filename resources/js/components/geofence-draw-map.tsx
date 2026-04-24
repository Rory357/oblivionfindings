/**
 * GeofenceDrawMap - Custom geofence drawing with draggable handles.
 * Pure Leaflet + React state. No leaflet-draw.
 */
import { useEffect, useRef, useCallback, useState } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Circle, Pentagon, Square, Trash2, Undo2, Check } from 'lucide-react';

export interface GeofenceShape {
    type: 'circle' | 'polygon' | 'rectangle';
    center?: { lat: number; lng: number };
    radius_m?: number;
    coordinates?: { lat: number; lng: number }[];
}

interface Props {
    center?: { lat: number; lng: number };
    zoom?: number;
    height?: number;
    initialShape?: GeofenceShape | null;
    onShapeChange: (shape: GeofenceShape | null) => void;
}

const PURPLE = '#7c3aed';
const FILL = { color: PURPLE, fillColor: PURPLE, fillOpacity: 0.15, weight: 2 };

function coord(ll: L.LatLng) { return { lat: Number(ll.lat.toFixed(6)), lng: Number(ll.lng.toFixed(6)) }; }

function numberedIcon(n: number) {
    return L.divIcon({
        className: '',
        html: `<div style="display:flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:${PURPLE};color:#fff;font-size:11px;font-weight:700;border:2px solid #fff;box-shadow:0 2px 4px rgba(0,0,0,.3);cursor:grab">${n}</div>`,
        iconSize: [24, 24],
        iconAnchor: [12, 12],
    });
}

function dotIcon() {
    return L.divIcon({
        className: '',
        html: `<div style="width:14px;height:14px;border-radius:50%;background:#fff;border:3px solid ${PURPLE};box-shadow:0 2px 4px rgba(0,0,0,.3);cursor:grab"></div>`,
        iconSize: [14, 14],
        iconAnchor: [7, 7],
    });
}

export default function GeofenceDrawMap({ center = { lat: -36.8485, lng: 174.7633 }, zoom = 13, height = 480, initialShape, onShapeChange }: Props) {
    const containerRef = useRef<HTMLDivElement>(null);
    const mapRef = useRef<L.Map | null>(null);
    const shapeRef = useRef<L.Layer | null>(null);
    const markersRef = useRef<L.Marker[]>([]);
    const previewRef = useRef<L.Polyline | null>(null);

    const [mode, setMode] = useState<'circle' | 'rectangle' | 'polygon'>(initialShape?.type === 'rectangle' ? 'rectangle' : initialShape?.type === 'polygon' ? 'polygon' : 'circle');
    const [radius, setRadius] = useState(initialShape?.type === 'circle' ? String(initialShape.radius_m ?? 200) : '200');
    const [circleCenter, setCircleCenter] = useState<{ lat: number; lng: number } | null>(initialShape?.type === 'circle' ? initialShape.center ?? null : null);
    const [rectCorners, setRectCorners] = useState<{ lat: number; lng: number }[]>(() => {
        if (initialShape?.type === 'rectangle' && initialShape.coordinates && initialShape.coordinates.length >= 4) {
            return [initialShape.coordinates[0], initialShape.coordinates[1], initialShape.coordinates[2], initialShape.coordinates[3]];
        }
        return [];
    });
    const [polyPoints, setPolyPoints] = useState<{ lat: number; lng: number }[]>(initialShape?.type === 'polygon' ? initialShape.coordinates ?? [] : []);
    const [polyDone, setPolyDone] = useState(initialShape?.type === 'polygon' && (initialShape.coordinates?.length ?? 0) >= 3);

    const cbRef = useRef(onShapeChange);
    cbRef.current = onShapeChange;

    // Init map once
    useEffect(() => {
        if (!containerRef.current || mapRef.current) return;
        const map = L.map(containerRef.current, { center: [center.lat, center.lng], zoom });

        const streetLayer = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/">CARTO</a>',
            maxZoom: 19,
        });
        const satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            attribution: '&copy; <a href="https://www.esri.com/">Esri</a>, Earthstar Geographics',
            maxZoom: 19,
        });

        streetLayer.addTo(map);
        L.control.layers({ 'Street': streetLayer, 'Satellite': satelliteLayer }, {}, { position: 'topright' }).addTo(map);

        mapRef.current = map;
        // Force tile load after render
        setTimeout(() => map.invalidateSize(), 100);
        map.on('click', (e: L.LeafletMouseEvent) => {
            window.dispatchEvent(new CustomEvent('gf-click', { detail: coord(e.latlng) }));
        });
        return () => { map.remove(); mapRef.current = null; };
    }, []); // eslint-disable-line

    // Map click → state
    useEffect(() => {
        const h = (e: Event) => {
            const p = (e as CustomEvent).detail as { lat: number; lng: number };
            if (mode === 'circle') setCircleCenter(p);
            else if (mode === 'rectangle') {
                setRectCorners(prev => {
                    if (prev.length === 0) return [p]; // first corner
                    if (prev.length === 1) {
                        // Build 4 corners from 2 diagonals
                        const a = prev[0], b = p;
                        return [
                            { lat: Math.max(a.lat, b.lat), lng: Math.min(a.lng, b.lng) }, // NW
                            { lat: Math.max(a.lat, b.lat), lng: Math.max(a.lng, b.lng) }, // NE
                            { lat: Math.min(a.lat, b.lat), lng: Math.max(a.lng, b.lng) }, // SE
                            { lat: Math.min(a.lat, b.lat), lng: Math.min(a.lng, b.lng) }, // SW
                        ];
                    }
                    return [p]; // restart
                });
            } else if (mode === 'polygon' && !polyDone) setPolyPoints(prev => [...prev, p]);
        };
        window.addEventListener('gf-click', h);
        return () => window.removeEventListener('gf-click', h);
    }, [mode, polyDone]);

    // Helper: clear all layers
    const clearLayers = useCallback(() => {
        const map = mapRef.current;
        if (!map) return;
        if (shapeRef.current) { map.removeLayer(shapeRef.current); shapeRef.current = null; }
        if (previewRef.current) { map.removeLayer(previewRef.current); previewRef.current = null; }
        markersRef.current.forEach(m => map.removeLayer(m));
        markersRef.current = [];
    }, []);

    // Helper: add draggable marker — only updates state on dragend to avoid re-render loops
    const addDragMarker = useCallback((lat: number, lng: number, icon: L.DivIcon, onDragEnd: (ll: { lat: number; lng: number }) => void) => {
        const map = mapRef.current;
        if (!map) return;
        const m = L.marker([lat, lng], { icon, draggable: true, autoPan: true }).addTo(map);
        m.on('dragstart', () => { map.dragging.disable(); });
        m.on('dragend', () => { map.dragging.enable(); onDragEnd(coord(m.getLatLng())); });
        markersRef.current.push(m);
    }, []);

    // ── CIRCLE render ──
    useEffect(() => {
        if (mode !== 'circle') return;
        clearLayers();
        const map = mapRef.current;
        if (!map || !circleCenter) { cbRef.current(null); return; }
        const r = Math.max(Number(radius) || 25, 25);
        const circle = L.circle([circleCenter.lat, circleCenter.lng], { radius: r, ...FILL }).addTo(map);
        shapeRef.current = circle;
        addDragMarker(circleCenter.lat, circleCenter.lng, dotIcon(), (p) => setCircleCenter(p));
        cbRef.current({ type: 'circle', center: circleCenter, radius_m: r });
    }, [mode, circleCenter, radius, clearLayers, addDragMarker]);

    // ── RECTANGLE render ──
    useEffect(() => {
        if (mode !== 'rectangle') return;
        clearLayers();
        const map = mapRef.current;
        if (!map) return;

        if (rectCorners.length === 4) {
            const poly = L.polygon(rectCorners.map(c => [c.lat, c.lng] as L.LatLngTuple), FILL).addTo(map);
            shapeRef.current = poly;
            // 4 draggable corner markers
            rectCorners.forEach((c, i) => {
                addDragMarker(c.lat, c.lng, numberedIcon(i + 1), (newPos) => {
                    setRectCorners(prev => {
                        const updated = [...prev];
                        updated[i] = newPos;
                        return updated;
                    });
                });
            });
            cbRef.current({ type: 'rectangle', coordinates: rectCorners });
        } else if (rectCorners.length === 1) {
            addDragMarker(rectCorners[0].lat, rectCorners[0].lng, dotIcon(), (p) => setRectCorners([p]));
            cbRef.current(null);
        } else {
            cbRef.current(null);
        }
    }, [mode, rectCorners, clearLayers, addDragMarker]);

    // ── POLYGON render ──
    useEffect(() => {
        if (mode !== 'polygon') return;
        clearLayers();
        const map = mapRef.current;
        if (!map) return;
        if (polyPoints.length === 0) { cbRef.current(null); return; }

        // Draggable vertex markers
        polyPoints.forEach((pt, i) => {
            addDragMarker(pt.lat, pt.lng, numberedIcon(i + 1), (newPos) => {
                setPolyPoints(prev => { const u = [...prev]; u[i] = newPos; return u; });
            });
        });

        if (polyDone && polyPoints.length >= 3) {
            const poly = L.polygon(polyPoints.map(p => [p.lat, p.lng] as L.LatLngTuple), FILL).addTo(map);
            shapeRef.current = poly;
            cbRef.current({ type: 'polygon', coordinates: polyPoints });
        } else if (polyPoints.length >= 2) {
            const line = L.polyline(polyPoints.map(p => [p.lat, p.lng] as L.LatLngTuple), { color: PURPLE, weight: 2, dashArray: '6 4' }).addTo(map);
            previewRef.current = line;
            cbRef.current(null);
        } else {
            cbRef.current(null);
        }
    }, [mode, polyPoints, polyDone, clearLayers, addDragMarker]);

    // Mode switch
    const switchMode = useCallback((m: 'circle' | 'rectangle' | 'polygon') => {
        clearLayers();
        setCircleCenter(null); setRectCorners([]); setPolyPoints([]); setPolyDone(false);
        cbRef.current(null);
        setMode(m);
    }, [clearLayers]);

    const clearAll = useCallback(() => {
        clearLayers();
        setCircleCenter(null); setRectCorners([]); setPolyPoints([]); setPolyDone(false);
        cbRef.current(null);
    }, [clearLayers]);

    const status = (() => {
        if (mode === 'circle') {
            if (!circleCenter) return { text: 'Click on the map to place the center point', ok: false };
            return { text: `Center: ${circleCenter.lat}, ${circleCenter.lng} · Radius: ${radius}m`, ok: true };
        }
        if (mode === 'rectangle') {
            if (rectCorners.length === 0) return { text: 'Click to place the first corner', ok: false };
            if (rectCorners.length === 1) return { text: 'Click to place the opposite corner', ok: false };
            return { text: 'Rectangle ready — drag any corner to adjust', ok: true };
        }
        if (polyPoints.length === 0) return { text: 'Click to start adding vertices', ok: false };
        if (!polyDone) return { text: `${polyPoints.length} point${polyPoints.length > 1 ? 's' : ''}${polyPoints.length < 3 ? ` — need ${3 - polyPoints.length} more` : ' — click Complete or drag to adjust'}`, ok: false };
        return { text: `Polygon with ${polyPoints.length} vertices — drag any point to adjust`, ok: true };
    })();

    return (
        <div className="space-y-0" style={{ isolation: 'isolate', position: 'relative', zIndex: 0 }}>
            {/* Mode tabs */}
            <div className="flex items-center justify-between px-1 pb-2">
                <div className="flex rounded-lg border p-0.5 gap-0.5">
                    {([
                        { k: 'circle' as const, I: Circle, l: 'Circle' },
                        { k: 'rectangle' as const, I: Square, l: 'Rectangle' },
                        { k: 'polygon' as const, I: Pentagon, l: 'Polygon' },
                    ]).map(({ k, I, l }) => (
                        <button key={k} type="button" onClick={() => switchMode(k)}
                            className={cn('flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-all',
                                mode === k ? 'bg-primary text-white shadow-sm' : 'text-muted-foreground hover:text-foreground hover:bg-muted'
                            )}>
                            <I className="h-3.5 w-3.5" /> {l}
                        </button>
                    ))}
                </div>
                <Button type="button" variant="ghost" size="sm" onClick={clearAll} className="text-status-critical hover:text-status-critical">
                    <Trash2 className="mr-1 h-3.5 w-3.5" /> Clear
                </Button>
            </div>

            {/* Map */}
            <div ref={containerRef} style={{ height }} className="rounded-lg overflow-hidden border cursor-crosshair relative z-0" />

            {/* Mode controls */}
            <div className="rounded-b-lg border border-t-0 bg-muted/30 px-4 py-3">
                {mode === 'circle' && (
                    <div className="space-y-2">
                        <div className="flex items-center justify-between">
                            <span className="text-sm font-medium">Radius: <span className="text-primary font-bold">{radius}m</span></span>
                            {Number(radius) >= 1000 && <span className="text-xs text-muted-foreground">{(Number(radius) / 1000).toFixed(1)} km</span>}
                        </div>
                        <input type="range" min="25" max="5000" step="25" value={radius} onChange={e => setRadius(e.target.value)}
                            className="w-full h-2 bg-primary/10 rounded-full appearance-none cursor-pointer accent-purple-600" />
                        <div className="flex gap-1.5">
                            {[50, 100, 200, 500, 1000, 2000].map(r => (
                                <button key={r} type="button" onClick={() => setRadius(String(r))}
                                    className={cn('rounded-full px-2.5 py-1 text-[10px] font-medium transition-colors',
                                        String(r) === radius ? 'bg-primary text-white' : 'bg-primary/10 text-primary hover:bg-primary/10'
                                    )}>{r >= 1000 ? `${r / 1000}km` : `${r}m`}</button>
                            ))}
                        </div>
                    </div>
                )}
                {mode === 'rectangle' && (
                    <p className="text-sm text-muted-foreground">
                        {rectCorners.length === 0 && 'Click the map to place the first corner.'}
                        {rectCorners.length === 1 && <span className="text-primary font-medium">Corner 1 placed — click the opposite corner.</span>}
                        {rectCorners.length === 4 && <span className="text-primary font-medium">✓ Drag any numbered corner to resize. Click map to restart.</span>}
                    </p>
                )}
                {mode === 'polygon' && (
                    <div className="flex items-center justify-between">
                        <p className="text-sm text-muted-foreground">
                            {polyPoints.length === 0 && 'Click the map to add vertices.'}
                            {polyPoints.length > 0 && !polyDone && <><span className="text-primary font-medium">{polyPoints.length}</span> point{polyPoints.length > 1 ? 's' : ''}{polyPoints.length < 3 ? ` — need ${3 - polyPoints.length} more` : ''}</>}
                            {polyDone && <span className="text-primary font-medium">✓ Drag any point to reshape.</span>}
                        </p>
                        <div className="flex gap-1.5">
                            {polyPoints.length > 0 && !polyDone && (
                                <Button type="button" variant="outline" size="sm" onClick={() => { setPolyPoints(p => p.slice(0, -1)); setPolyDone(false); }}>
                                    <Undo2 className="mr-1 h-3 w-3" /> Undo
                                </Button>
                            )}
                            {polyPoints.length >= 3 && !polyDone && (
                                <Button type="button" size="sm" onClick={() => setPolyDone(true)} className="bg-primary hover:bg-primary">
                                    <Check className="mr-1 h-3 w-3" /> Complete
                                </Button>
                            )}
                            {polyDone && (
                                <Button type="button" variant="outline" size="sm" onClick={() => setPolyDone(false)}>Add Points</Button>
                            )}
                        </div>
                    </div>
                )}
            </div>

            {/* Status */}
            <div className={cn('rounded-lg border mt-2 px-3 py-2 text-xs flex items-center justify-between',
                status.ok ? 'bg-primary/10 border-primary dark:bg-primary/20' : 'bg-muted/50'
            )}>
                <span className={status.ok ? 'text-primary dark:text-primary/70' : 'text-muted-foreground'}>{status.text}</span>
                {status.ok && <span className="text-primary font-medium">✓ Ready</span>}
            </div>
        </div>
    );
}

export { GeofenceDrawMap };
