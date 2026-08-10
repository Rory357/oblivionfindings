import LeafletMap, {
    type MapGeofence,
    type MapMarker,
} from '@/components/leaflet-map';
import type { Geofence } from '@/components/resident-tracking/types';
import { Badge } from '@/components/ui/badge';
import { Button as GuardrailButton } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
import { formatRelativeTime } from '@/lib/fleet-utils';
import { ChevronRight, Layers } from 'lucide-react';
import { useMemo, useState } from 'react';

type Props = {
    center: { lat: number; lng: number };
    zoom?: number;
    markers: MapMarker[];
    geofences: Geofence[];
    polyline?: { lat: number; lng: number }[];
    polylineOptions?: {
        animated?: boolean;
        showArrows?: boolean;
        showEndpoints?: boolean;
        color?: string;
    };
    height?: number;
    clustering?: boolean;
    onMarkerClick?: (id: string | number) => void;
    updatedAt?: string | null;
    showLegend?: boolean;
};

function appliesToLabel(scope?: string | null): string {
    switch (scope) {
        case 'house':
            return 'House';
        case 'resident':
            return 'Resident';
        case 'asset':
            return 'Asset';
        case 'vehicle':
            return 'Vehicle';
        case 'perimeter':
            return 'Perimeter';
        default:
            return 'Custom';
    }
}

export default function ResidentMap({
    center,
    zoom,
    markers,
    geofences,
    polyline,
    polylineOptions,
    height = 520,
    clustering = false,
    onMarkerClick,
    updatedAt,
    showLegend = true,
}: Props) {
    const [legendOpen, setLegendOpen] = useState(false);

    const mapGeofences: MapGeofence[] = useMemo(() => {
        return geofences.map((gf) => ({
            id: gf.id,
            name: gf.name ?? undefined,
            type: gf.type,
            center: gf.center,
            radius_m: gf.radius_m,
            coordinates: gf.coordinates,
            color: gf.color ?? '#8b5cf6',
        }));
    }, [geofences]);

    return (
        <div className="relative">
            <LeafletMap
                center={center}
                zoom={zoom}
                markers={markers}
                geofences={mapGeofences}
                polyline={polyline}
                polylineOptions={polylineOptions}
                height={height}
                clustering={clustering}
                onMarkerClick={onMarkerClick}
            />

            {updatedAt && (
                <div className="pointer-events-none absolute top-3 right-3 z-[400]">
                    <Badge
                        variant="secondary"
                        className="bg-background/90 text-[10px] backdrop-blur"
                    >
                        Updated {formatRelativeTime(updatedAt)}
                    </Badge>
                </div>
            )}

            {showLegend && geofences.length > 0 && (
                <GuardrailCard
                    unstyled
                    className="absolute bottom-3 left-3 z-[400] max-w-[240px] rounded-md border border-border bg-background/95 shadow-sm backdrop-blur"
                >
                    <GuardrailButton
                        unstyled
                        type="button"
                        onClick={() => setLegendOpen((v) => !v)}
                        className="flex w-full items-center justify-between px-3 py-2 text-xs font-medium hover:bg-muted/50"
                    >
                        <span className="flex items-center gap-1.5">
                            <Layers className="h-3.5 w-3.5 text-muted-foreground" />
                            Geofences ({geofences.length})
                        </span>
                        <ChevronRight
                            className={`h-3.5 w-3.5 text-muted-foreground transition-transform ${legendOpen ? 'rotate-90' : ''}`}
                        />
                    </GuardrailButton>
                    {legendOpen && (
                        <div className="max-h-48 overflow-y-auto border-t px-3 py-2">
                            {geofences.map((gf) => (
                                <div
                                    key={gf.id}
                                    className="flex items-center gap-2 py-1 text-[11px]"
                                >
                                    <span
                                        className="h-2.5 w-2.5 shrink-0 rounded-full"
                                        style={{
                                            backgroundColor:
                                                gf.color ?? '#8b5cf6',
                                        }}
                                    />
                                    <span className="min-w-0 flex-1 truncate">
                                        {gf.name ?? 'Unnamed'}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {appliesToLabel(gf.applies_to)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </GuardrailCard>
            )}
        </div>
    );
}
