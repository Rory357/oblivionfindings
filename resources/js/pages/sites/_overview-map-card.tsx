import LeafletMap, {
    type MapGeofence,
    type MapMarker,
} from '@/components/leaflet-map';
import { Button } from '@/components/ui/button';
import { MapPinned, Shield } from 'lucide-react';
import { useMemo } from 'react';

export type SiteOverviewGeofence = {
    id: number;
    name: string;
    type: 'circle' | 'polygon';
    shape: any;
    breach_type: 'enter' | 'exit' | 'both';
    is_active?: boolean;
    asset_id?: number | null;
    assigned_asset_ids?: number[];
};

export default function SiteOverviewMapCard({
    siteId,
    siteName,
    latitude,
    longitude,
    geofences,
    canManage,
    onEditGeofence,
}: {
    siteId: number;
    siteName: string;
    latitude?: string | number | null;
    longitude?: string | number | null;
    geofences: SiteOverviewGeofence[];
    canManage: boolean;
    onEditGeofence: () => void;
}) {
    const lat = latitude != null ? Number(latitude) : null;
    const lng = longitude != null ? Number(longitude) : null;

    const markers = useMemo<MapMarker[]>(() => {
        if (lat == null || lng == null || Number.isNaN(lat) || Number.isNaN(lng)) {
            return [];
        }
        return [
            {
                id: `site-${siteId}`,
                lat,
                lng,
                title: siteName,
                type: 'house',
                status: 'online',
            },
        ];
    }, [lat, lng, siteId, siteName]);

    const mapGeofences = useMemo<MapGeofence[]>(() => {
        return geofences
            .map((g): MapGeofence | null => {
                const shape = g.shape ?? {};
                if (g.type === 'circle' && shape.center && shape.radius_m) {
                    return {
                        id: g.id,
                        name: g.name,
                        type: 'circle',
                        center: { lat: Number(shape.center.lat), lng: Number(shape.center.lng) },
                        radius_m: Number(shape.radius_m),
                    };
                }
                if (g.type === 'polygon' && Array.isArray(shape.coordinates)) {
                    return {
                        id: g.id,
                        name: g.name,
                        type: 'polygon',
                        coordinates: shape.coordinates.map((c: any) => ({
                            lat: Number(c.lat),
                            lng: Number(c.lng),
                        })),
                    };
                }
                return null;
            })
            .filter((g): g is MapGeofence => g !== null);
    }, [geofences]);

    if (lat == null || lng == null || Number.isNaN(lat) || Number.isNaN(lng)) {
        return (
            <div className="rounded-lg border border-dashed border-border/60 bg-muted/20 p-6 text-center text-sm text-muted-foreground">
                Pick an address from the search box in <strong>Edit Location</strong> to
                drop a pin and enable geofencing.
            </div>
        );
    }

    return (
        <div className="space-y-2">
            <div className="overflow-hidden rounded-lg border border-border/60">
                <LeafletMap
                    center={{ lat, lng }}
                    zoom={16}
                    markers={markers}
                    geofences={mapGeofences}
                    height={240}
                />
            </div>
            <div className="flex items-center justify-between gap-2">
                <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Shield className="h-3.5 w-3.5" />
                    {geofences.length === 0
                        ? 'No geofences configured'
                        : `${geofences.length} active geofence${geofences.length === 1 ? '' : 's'}`}
                </div>
                {canManage && (
                    <Button
                        size="sm"
                        onClick={onEditGeofence}
                        data-test="site-map-geofence-button"
                    >
                        <MapPinned className="mr-1 h-3.5 w-3.5" />
                        {geofences.length === 0
                            ? 'Set Up Geofence'
                            : 'Edit Site Geofence'}
                    </Button>
                )}
            </div>
        </div>
    );
}
