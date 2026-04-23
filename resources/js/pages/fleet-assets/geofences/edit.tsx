import LeafletMap, { MapGeofence } from '@/components/leaflet-map';
import FleetHero from '@/components/fleet-hero';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Circle, Pencil, Save, Trash2, X } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

type GeofenceData = {
    id: number;
    name: string;
    type: 'circle' | 'polygon';
    scope: string;
    breach_type: string;
    is_active: boolean;
    shape: {
        center?: { lat: number; lng: number };
        radius_m?: number;
        coordinates?: { lat: number; lng: number }[];
    } | null;
    time_rules: Record<string, any> | null;
    alert_config: {
        on_enter?: boolean;
        on_exit?: boolean;
        on_speed?: boolean;
        severity?: string;
        notify_control_room?: boolean;
    } | null;
    asset_id: number | null;
    site_id: number | null;
};

type Props = {
    geofence: GeofenceData;
    assets: Array<{ id: number; name: string; asset_tag: string | null; category: string | null }>;
    sites: Array<{ id: number; name: string; latitude: number | null; longitude: number | null }>;
};

export default function GeofenceEdit({ geofence, assets, sites }: Props) {
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const [name, setName] = useState(geofence?.name ?? '');
    const [description, setDescription] = useState('');
    const [assetId, setAssetId] = useState(geofence?.asset_id ? String(geofence.asset_id) : '');
    const [siteId, setSiteId] = useState(geofence?.site_id ? String(geofence.site_id) : '');
    const [type, setType] = useState<'circle' | 'polygon'>(geofence?.type ?? 'circle');
    const [breachType, setBreachType] = useState(geofence?.breach_type ?? 'both');
    const [isActive, setIsActive] = useState(geofence?.is_active ?? true);

    // Alert config
    const [alertOnEnter, setAlertOnEnter] = useState(geofence?.alert_config?.on_enter ?? true);
    const [alertOnExit, setAlertOnExit] = useState(geofence?.alert_config?.on_exit ?? true);
    const [alertOnSpeed, setAlertOnSpeed] = useState(geofence?.alert_config?.on_speed ?? false);
    const [alertSeverity, setAlertSeverity] = useState(geofence?.alert_config?.severity ?? 'medium');
    const [notifyControlRoom, setNotifyControlRoom] = useState(geofence?.alert_config?.notify_control_room ?? false);

    // Circle fields
    const [centerLat, setCenterLat] = useState(
        geofence?.shape?.center?.lat != null ? String(geofence.shape.center.lat) : ''
    );
    const [centerLng, setCenterLng] = useState(
        geofence?.shape?.center?.lng != null ? String(geofence.shape.center.lng) : ''
    );
    const [radiusM, setRadiusM] = useState(
        geofence?.shape?.radius_m != null ? String(geofence.shape.radius_m) : '200'
    );

    // Polygon fields
    const [polygonPoints, setPolygonPoints] = useState<{ lat: number; lng: number }[]>(
        geofence?.shape?.coordinates ?? []
    );

    // Map state
    const initialCenter = useMemo(() => {
        if (geofence?.shape?.center) return geofence.shape.center;
        if (geofence?.shape?.coordinates?.length) {
            const coords = geofence.shape.coordinates;
            return {
                lat: coords.reduce((s, c) => s + c.lat, 0) / coords.length,
                lng: coords.reduce((s, c) => s + c.lng, 0) / coords.length,
            };
        }
        return { lat: -36.8485, lng: 174.7633 };
    }, [geofence]);

    const [mapCenter, setMapCenter] = useState(initialCenter);
    const [mapZoom, setMapZoom] = useState(14);

    const handleSiteQuickFill = useCallback((val: string) => {
        setSiteId(val);
        const site = (sites ?? []).find((s) => String(s.id) === val);
        if (site?.latitude && site?.longitude) {
            setCenterLat(String(site.latitude));
            setCenterLng(String(site.longitude));
            setRadiusM('200');
            setMapCenter({ lat: site.latitude, lng: site.longitude });
            setMapZoom(15);
        }
    }, [sites]);

    const handleMapClick = useCallback((latlng: { lat: number; lng: number }) => {
        if (type === 'circle') {
            setCenterLat(String(latlng.lat.toFixed(6)));
            setCenterLng(String(latlng.lng.toFixed(6)));
        } else {
            setPolygonPoints((prev) => [...prev, { lat: Number(latlng.lat.toFixed(6)), lng: Number(latlng.lng.toFixed(6)) }]);
        }
    }, [type]);

    const removePolygonPoint = useCallback((index: number) => {
        setPolygonPoints((prev) => prev.filter((_, i) => i !== index));
    }, []);

    const clearPolygon = useCallback(() => {
        setPolygonPoints([]);
    }, []);

    const previewGeofences = useMemo<MapGeofence[]>(() => {
        const result: MapGeofence[] = [];
        if (type === 'circle' && centerLat && centerLng && radiusM) {
            const lat = parseFloat(centerLat);
            const lng = parseFloat(centerLng);
            const radius = parseFloat(radiusM);
            if (!isNaN(lat) && !isNaN(lng) && !isNaN(radius) && radius > 0) {
                result.push({
                    id: 'preview-circle',
                    name: name || 'Geofence',
                    type: 'circle',
                    center: { lat, lng },
                    radius_m: radius,
                    color: '#3b82f6',
                });
            }
        } else if (type === 'polygon' && polygonPoints.length >= 3) {
            result.push({
                id: 'preview-polygon',
                name: name || 'Geofence',
                type: 'polygon',
                coordinates: polygonPoints,
                color: '#3b82f6',
            });
        }
        return result;
    }, [type, centerLat, centerLng, radiusM, polygonPoints, name]);

    const previewMarkers = useMemo(() => {
        if (type === 'circle' && centerLat && centerLng) {
            const lat = parseFloat(centerLat);
            const lng = parseFloat(centerLng);
            if (!isNaN(lat) && !isNaN(lng)) {
                return [{ id: 'center', lat, lng, title: 'Center', type: 'default' as const }];
            }
        }
        return [];
    }, [type, centerLat, centerLng]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!name.trim()) {
            setErrors({ name: 'Name is required.' });
            return;
        }
        if (type === 'polygon' && polygonPoints.length < 3) {
            setErrors({ shape: 'Polygon requires at least 3 points.' });
            return;
        }
        setProcessing(true);

        let shape: Record<string, any> = {};
        if (type === 'circle') {
            shape = {
                center: { lat: parseFloat(centerLat), lng: parseFloat(centerLng) },
                radius_m: parseFloat(radiusM),
            };
        } else {
            shape = { coordinates: polygonPoints };
        }

        const alertConfig = {
            on_enter: alertOnEnter,
            on_exit: alertOnExit,
            on_speed: alertOnSpeed,
            severity: alertSeverity,
            notify_control_room: notifyControlRoom,
        };

        router.put(`/fleet-assets/geofences/${geofence.id}`, {
            asset_id: assetId || null,
            site_id: siteId || null,
            name,
            type,
            shape,
            breach_type: breachType,
            alert_config: alertConfig,
            is_active: isActive,
        }, {
            onFinish: () => setProcessing(false),
            onError: (errs) => setErrors(errs),
        });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Geofences', href: '/fleet-assets/geofences' },
                { title: 'Edit', href: '#' },
            ]}
        >
            <Head title={`Edit Geofence - ${geofence?.name ?? ''}`} />
            <PageShell>
                <FleetHero
                    title={`Edit: ${geofence?.name ?? 'Geofence'}`}
                    description="Modify the geofence boundary and settings."
                    backHref="/fleet-assets/geofences"
                    backLabel="Back to Geofences"
                />

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* Map + Drawing */}
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between">
                                <CardTitle>Edit on Map</CardTitle>
                                <div className="flex items-center gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={type === 'circle' ? 'default' : 'outline'}
                                        onClick={() => { setType('circle'); setPolygonPoints([]); }}
                                    >
                                        <Circle className="mr-1.5 h-3.5 w-3.5" />
                                        Circle
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant={type === 'polygon' ? 'default' : 'outline'}
                                        onClick={() => { setType('polygon'); setCenterLat(''); setCenterLng(''); }}
                                    >
                                        <Pencil className="mr-1.5 h-3.5 w-3.5" />
                                        Polygon
                                    </Button>
                                </div>
                            </div>
                            <p className="text-xs text-muted-foreground mt-1">
                                {type === 'circle'
                                    ? 'Click on the map to reposition the center point. Adjust the radius below.'
                                    : 'Click on the map to add polygon points. You need at least 3 points.'}
                            </p>
                        </CardHeader>
                        <CardContent>
                            <LeafletMap
                                center={mapCenter}
                                zoom={mapZoom}
                                geofences={previewGeofences}
                                markers={previewMarkers}
                                height={450}
                                onMapClick={handleMapClick}
                            />
                            <div className="mt-3 flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                                {type === 'circle' && centerLat && centerLng && (
                                    <span>
                                        Center: {parseFloat(centerLat).toFixed(6)}, {parseFloat(centerLng).toFixed(6)}
                                        {radiusM && <> | Radius: {radiusM}m</>}
                                    </span>
                                )}
                                {type === 'polygon' && (
                                    <span>{polygonPoints.length} point{polygonPoints.length !== 1 ? 's' : ''} placed</span>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <div className="grid gap-6 lg:grid-cols-[2fr_3fr]">
                        {/* Geofence Details */}
                        <Card>
                            <CardHeader>
                                <CardTitle>Geofence Details</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4">
                                <div>
                                    <Label>Name *</Label>
                                    <Input
                                        value={name}
                                        onChange={(e) => setName(e.target.value)}
                                        placeholder="Geofence name"
                                    />
                                    {errors.name && <p className="mt-1 text-xs text-destructive">{errors.name}</p>}
                                </div>
                                <div>
                                    <Label>Description</Label>
                                    <Textarea
                                        value={description}
                                        onChange={(e) => setDescription(e.target.value)}
                                        placeholder="Optional description..."
                                        rows={2}
                                    />
                                </div>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label>Link to Site</Label>
                                        <Select value={siteId} onValueChange={handleSiteQuickFill}>
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select site (optional)" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {(sites ?? []).map((s) => (
                                                    <SelectItem key={s.id} value={String(s.id)}>{s.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div>
                                        <Label>Link to Asset</Label>
                                        <Select value={assetId} onValueChange={setAssetId}>
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select asset (optional)" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {(assets ?? []).map((a) => (
                                                    <SelectItem key={a.id} value={String(a.id)}>{a.name}</SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                </div>
                                <div className="flex items-center gap-3">
                                    <input
                                        type="checkbox"
                                        id="is_active"
                                        checked={isActive}
                                        onChange={(e) => setIsActive(e.target.checked)}
                                        className="h-4 w-4 rounded border-border"
                                    />
                                    <Label htmlFor="is_active">Active</Label>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Shape Configuration */}
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    {type === 'circle' ? 'Circle Configuration' : 'Polygon Configuration'}
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4">
                                {type === 'circle' ? (
                                    <>
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div>
                                                <Label>Center Latitude *</Label>
                                                <Input
                                                    type="number"
                                                    step="any"
                                                    value={centerLat}
                                                    onChange={(e) => setCenterLat(e.target.value)}
                                                    placeholder="-36.8485"
                                                />
                                            </div>
                                            <div>
                                                <Label>Center Longitude *</Label>
                                                <Input
                                                    type="number"
                                                    step="any"
                                                    value={centerLng}
                                                    onChange={(e) => setCenterLng(e.target.value)}
                                                    placeholder="174.7633"
                                                />
                                            </div>
                                        </div>
                                        <div>
                                            <Label>Radius (meters) *</Label>
                                            <Input
                                                type="number"
                                                min="10"
                                                max="50000"
                                                value={radiusM}
                                                onChange={(e) => setRadiusM(e.target.value)}
                                                placeholder="200"
                                            />
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                10m - 50,000m (50km)
                                            </p>
                                        </div>
                                    </>
                                ) : (
                                    <div className="space-y-2">
                                        <div className="flex items-center justify-between">
                                            <Label>Polygon Points ({polygonPoints.length})</Label>
                                            {polygonPoints.length > 0 && (
                                                <Button type="button" variant="ghost" size="sm" onClick={clearPolygon}>
                                                    <Trash2 className="mr-1 h-3.5 w-3.5" />
                                                    Clear All
                                                </Button>
                                            )}
                                        </div>
                                        {polygonPoints.length === 0 ? (
                                            <p className="text-sm text-muted-foreground py-4 text-center">
                                                Click on the map to add points. You need at least 3 to form a polygon.
                                            </p>
                                        ) : (
                                            <div className="max-h-[200px] overflow-y-auto space-y-1">
                                                {polygonPoints.map((pt, i) => (
                                                    <div key={i} className="flex items-center justify-between rounded border px-3 py-1.5 text-xs">
                                                        <span className="font-mono">
                                                            <Badge variant="outline" className="mr-2">{i + 1}</Badge>
                                                            {pt.lat.toFixed(6)}, {pt.lng.toFixed(6)}
                                                        </span>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="h-6 w-6 p-0"
                                                            onClick={() => removePolygonPoint(i)}
                                                        >
                                                            <X className="h-3 w-3" />
                                                        </Button>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                        {polygonPoints.length > 0 && polygonPoints.length < 3 && (
                                            <p className="text-xs text-amber-600">
                                                Need {3 - polygonPoints.length} more point{3 - polygonPoints.length > 1 ? 's' : ''} to complete polygon.
                                            </p>
                                        )}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>

                    {/* Alert Configuration */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Alert Configuration</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                                <div>
                                    <Label>Breach Type</Label>
                                    <Select value={breachType} onValueChange={setBreachType}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="enter">Enter Only</SelectItem>
                                            <SelectItem value="exit">Exit Only</SelectItem>
                                            <SelectItem value="both">Enter & Exit</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label>Severity</Label>
                                    <Select value={alertSeverity} onValueChange={setAlertSeverity}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="low">Low</SelectItem>
                                            <SelectItem value="medium">Medium</SelectItem>
                                            <SelectItem value="high">High</SelectItem>
                                            <SelectItem value="critical">Critical</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-3">
                                    <Label>Alert Triggers</Label>
                                    <div className="space-y-2">
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={alertOnEnter}
                                                onChange={(e) => setAlertOnEnter(e.target.checked)}
                                                className="h-4 w-4 rounded border-border"
                                            />
                                            Alert on Enter
                                        </label>
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={alertOnExit}
                                                onChange={(e) => setAlertOnExit(e.target.checked)}
                                                className="h-4 w-4 rounded border-border"
                                            />
                                            Alert on Exit
                                        </label>
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={alertOnSpeed}
                                                onChange={(e) => setAlertOnSpeed(e.target.checked)}
                                                className="h-4 w-4 rounded border-border"
                                            />
                                            Alert on Speed Violation
                                        </label>
                                    </div>
                                </div>
                                <div className="space-y-3">
                                    <Label>Notification</Label>
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            checked={notifyControlRoom}
                                            onChange={(e) => setNotifyControlRoom(e.target.checked)}
                                            className="h-4 w-4 rounded border-border"
                                        />
                                        Notify Control Room
                                    </label>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {errors.shape && (
                        <p className="text-xs text-destructive">{errors.shape}</p>
                    )}

                    <div className="flex items-center gap-2">
                        <Button type="submit" disabled={processing}>
                            <Save className="mr-2 h-4 w-4" />
                            Update Geofence
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/fleet-assets/geofences">Cancel</Link>
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}
