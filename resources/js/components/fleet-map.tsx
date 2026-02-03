import { useEffect, useRef } from 'react';

type FleetMapMarker = {
    id: string | number;
    lat: number;
    lng: number;
    title?: string;
};

type FleetMapProps = {
    apiKey?: string | null;
    center: { lat: number; lng: number };
    zoom?: number;
    markers?: FleetMapMarker[];
    polyline?: { lat: number; lng: number }[];
    height?: number;
    usageContext?: string;
    usageAssetId?: number;
};

let loaderPromise: Promise<void> | null = null;

const loadGoogleMaps = (apiKey?: string | null) => {
    if (typeof window === 'undefined') return Promise.resolve();
    if ((window as any).google?.maps) return Promise.resolve();
    if (!apiKey) return Promise.reject(new Error('Missing Google Maps API key'));
    if (loaderPromise) return loaderPromise;

    loaderPromise = new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${apiKey}`;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Google Maps failed to load'));
        document.head.appendChild(script);
    });

    return loaderPromise;
};

export default function FleetMap({
    apiKey,
    center,
    zoom = 13,
    markers = [],
    polyline,
    height = 360,
    usageContext,
    usageAssetId,
}: FleetMapProps) {
    const mapNode = useRef<HTMLDivElement | null>(null);
    const mapRef = useRef<any>(null);
    const markerRefs = useRef<any[]>([]);
    const polylineRef = useRef<any>(null);
    const loggedRef = useRef(false);

    useEffect(() => {
        loadGoogleMaps(apiKey)
            .then(() => {
                if (!mapNode.current) return;
                if (!mapRef.current) {
                    mapRef.current = new (window as any).google.maps.Map(
                        mapNode.current,
                        { center, zoom, disableDefaultUI: true },
                    );
                } else {
                    mapRef.current.setCenter(center);
                }

                markerRefs.current.forEach((m) => m.setMap(null));
                markerRefs.current = markers.map((marker) => {
                    return new (window as any).google.maps.Marker({
                        map: mapRef.current,
                        position: { lat: marker.lat, lng: marker.lng },
                        title: marker.title ?? '',
                    });
                });

                if (polylineRef.current) {
                    polylineRef.current.setMap(null);
                }

                if (polyline && polyline.length > 1) {
                    polylineRef.current = new (window as any).google.maps.Polyline(
                        {
                            path: polyline,
                            geodesic: true,
                            strokeColor: '#0ea5e9',
                            strokeOpacity: 0.8,
                            strokeWeight: 3,
                        },
                    );
                    polylineRef.current.setMap(mapRef.current);
                }

                if (!loggedRef.current) {
                    loggedRef.current = true;
                    const token = (
                        document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null
                    )?.content;

                    fetch('/fleet/maps/usage', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                        },
                        body: JSON.stringify({
                            context: usageContext,
                            asset_id: usageAssetId,
                        }),
                    }).catch(() => undefined);
                }
            })
            .catch(() => {
                // Intentionally swallow load errors; UI will show fallback.
            });
    }, [apiKey, center, zoom, markers, polyline, usageContext, usageAssetId]);

    return (
        <div className="w-full rounded-md border">
            <div
                ref={mapNode}
                className="w-full"
                style={{ height: `${height}px` }}
            />
        </div>
    );
}
