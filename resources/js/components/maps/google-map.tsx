import {
    clusterMarkers,
    getMapMarkerColor,
    type LeafletMapProps,
} from '@/components/leaflet-map';
import { useEffect, useRef, useState } from 'react';
import {
    loadGoogleMaps,
    onGoogleFailure,
    type GoogleOverlay,
    type GoogleShape,
    type GoogleMap as MapInstance,
    type MapsSDK,
    type Point,
} from './google-sdk';

const pointIsValid = (point: Point) =>
    Number.isFinite(point.lat) &&
    Number.isFinite(point.lng) &&
    Math.abs(point.lat) <= 90 &&
    Math.abs(point.lng) <= 180;
function markerOverlay(sdk: MapsSDK, button: HTMLButtonElement, point: Point) {
    class MarkerOverlay extends sdk.OverlayView {
        onAdd() {
            this.getPanes()?.overlayMouseTarget.appendChild(button);
        }
        draw() {
            const pixel = this.getProjection().fromLatLngToDivPixel(
                new sdk.LatLng(point),
            );
            if (pixel) {
                button.style.position = 'absolute';
                button.style.left = `${pixel.x}px`;
                button.style.top = `${pixel.y}px`;
                button.style.transform = 'translate(-50%, -100%)';
            }
        }
        onRemove() {
            button.remove();
        }
    }
    return new MarkerOverlay();
}
function colour(value: string) {
    const probe = document.createElement('span');
    probe.style.color = value;
    probe.hidden = true;
    document.body.append(probe);
    const result = getComputedStyle(probe).color;
    probe.remove();
    return result;
}
export function GoogleMap({
    apiKey,
    onFailure,
    onViewport,
    ...props
}: LeafletMapProps & {
    apiKey: string;
    onFailure: () => void;
    onViewport: (center: Point, zoom: number) => void;
}) {
    const element = useRef<HTMLDivElement>(null),
        map = useRef<MapInstance | null>(null),
        latest = useRef(props),
        overlay = useRef<(GoogleOverlay | GoogleShape)[]>([]);
    const [sdk, setSDK] = useState<MapsSDK | null>(null),
        [zoom, setZoom] = useState(props.zoom ?? 13);
    const failure = useRef(onFailure),
        viewport = useRef(onViewport),
        fitted = useRef(false),
        initialFitDone = useRef(false);
    useEffect(() => {
        latest.current = props;
        failure.current = onFailure;
        viewport.current = onViewport;
    });
    useEffect(() => {
        let active = true;
        let loadedSDK: MapsSDK | null = null;
        const unsubscribe = onGoogleFailure(() => {
            if (active) failure.current();
        });
        loadGoogleMaps(apiKey)
            .then((google) => {
                if (!active || !element.current) return;
                const instance = new google.Map(element.current, {
                    center: latest.current.center,
                    zoom: latest.current.zoom ?? 13,
                    mapTypeControl: true,
                    streetViewControl: false,
                    fullscreenControl: false,
                    clickableIcons: false,
                    styles: [{ stylers: [{ saturation: -100 }] }],
                });
                map.current = instance;
                setSDK(google);
                loadedSDK = google;
                instance.addListener('idle', () => {
                    const center = instance.getCenter()?.toJSON();
                    if (center)
                        viewport.current(center, instance.getZoom() ?? 13);
                });
                instance.addListener('zoom_changed', () =>
                    setZoom(instance.getZoom() ?? 13),
                );
                instance.addListener('tilesloaded', () =>
                    latest.current.onTileStatus?.('loaded'),
                );
                instance.addListener('click', (event) => {
                    if (event.latLng)
                        latest.current.onMapClick?.(event.latLng.toJSON());
                });
                instance.addListener('rightclick', (event) => {
                    if (event.latLng)
                        latest.current.onContext?.({
                            ...event.latLng.toJSON(),
                            x: event.domEvent?.clientX ?? 0,
                            y: event.domEvent?.clientY ?? 0,
                        });
                });
            })
            .catch(() => {
                if (active) failure.current();
            });
        return () => {
            active = false;
            unsubscribe();
            overlay.current.forEach((item) => item.setMap(null));
            overlay.current = [];
            if (map.current)
                loadedSDK?.event.clearInstanceListeners(map.current);
            map.current = null;
        };
    }, [apiKey]);
    useEffect(() => {
        if (!sdk || !map.current) return;
        const instance = map.current;
        overlay.current.forEach((item) => item.setMap(null));
        overlay.current = [];
        const popups: InstanceType<MapsSDK['InfoWindow']>[] = [];
        const markers = (props.markers ?? []).filter(pointIsValid);
        const groups =
            props.clustering && markers.length >= (props.clusterThreshold ?? 20)
                ? clusterMarkers(markers, zoom)
                : markers.map((marker) => ({
                      lat: marker.lat,
                      lng: marker.lng,
                      markers: [marker],
                  }));
        for (const group of groups) {
            const marker = group.markers[0],
                cluster = group.markers.length > 1;
            const button = document.createElement('button');
            button.type = 'button';
            button.className =
                'rounded-full border-2 border-card bg-card px-2 py-1 text-caption font-semibold shadow-sm focus-visible:ring-2 focus-visible:ring-ring';
            button.style.color = getMapMarkerColor(marker);
            button.style.borderColor = getMapMarkerColor(marker);
            button.textContent = cluster
                ? String(group.markers.length)
                : marker.type === 'point'
                  ? '•'
                  : '●';
            button.setAttribute(
                'aria-label',
                cluster
                    ? `${group.markers.length} map markers; zoom in`
                    : (marker.title ?? 'Map marker'),
            );
            button.title = [
                marker.title,
                ...(marker.stats ?? []).map(
                    ([label, value]) => `${label}: ${value}`,
                ),
                marker.hint,
            ]
                .filter(Boolean)
                .join('\n');
            button.onclick = (event) => {
                event.stopPropagation();
                if (cluster) {
                    const bounds = new sdk.LatLngBounds();
                    group.markers.forEach((item) => bounds.extend(item));
                    instance.fitBounds(bounds, 50);
                    return;
                }
                latest.current.onMarkerClick?.(marker.id);
                if (latest.current.showMarkerPopups !== false) {
                    popups.forEach((window) => window.close());
                    const content = document.createElement('div'),
                        title = document.createElement('strong');
                    title.textContent = marker.title ?? 'Map marker';
                    content.append(title);
                    [
                        marker.popup,
                        ...(marker.stats ?? []).map(
                            ([label, value]) => `${label}: ${value}`,
                        ),
                    ]
                        .filter(Boolean)
                        .forEach((value) => {
                            const line = document.createElement('p');
                            line.textContent = value!;
                            content.append(line);
                        });
                    const info = new sdk.InfoWindow({
                        content,
                        position: group,
                    });
                    popups.push(info);
                    info.open({ map: instance });
                }
            };
            button.oncontextmenu = (event) => {
                if (latest.current.onContext) {
                    event.preventDefault();
                    event.stopPropagation();
                    latest.current.onContext({
                        lat: marker.lat,
                        lng: marker.lng,
                        x: event.clientX,
                        y: event.clientY,
                        markerId: marker.id,
                    });
                }
            };
            const item = markerOverlay(sdk, button, group);
            item.setMap(instance);
            overlay.current.push(item);
        }
        for (const boundary of props.geofences ?? []) {
            const color = colour(boundary.color ?? 'var(--primary)');
            const options = {
                map: instance,
                strokeColor: color,
                strokeOpacity: 0.8,
                strokeWeight: 2,
                fillColor: color,
                fillOpacity: 0.1,
                clickable: false,
            };
            if (
                boundary.type === 'circle' &&
                boundary.center &&
                pointIsValid(boundary.center)
            )
                overlay.current.push(
                    new sdk.Circle({
                        ...options,
                        center: boundary.center,
                        radius: boundary.radius_m ?? 0,
                    }),
                );
            if (boundary.type === 'polygon')
                overlay.current.push(
                    new sdk.Polygon({
                        ...options,
                        paths: (boundary.coordinates ?? []).filter(
                            pointIsValid,
                        ),
                    }),
                );
        }
        if (props.polyline?.length) {
            const path = props.polyline.filter(pointIsValid),
                strokeColor = colour(
                    props.polylineOptions?.color ?? 'var(--primary)',
                );
            const icons: {
                icon: {
                    path: string;
                    strokeOpacity: number;
                    strokeColor: string;
                    scale: number;
                };
                offset: string;
                repeat: string;
            }[] = [];
            if (props.polylineOptions?.dashArray)
                icons.push({
                    icon: {
                        path: 'M 0,-1 0,1',
                        strokeOpacity: 0.85,
                        strokeColor,
                        scale: 3,
                    },
                    offset: '0',
                    repeat: '14px',
                });
            if (props.polylineOptions?.showArrows)
                icons.push({
                    icon: {
                        path: 'M -2,-2 0,0 -2,2',
                        strokeOpacity: 1,
                        strokeColor,
                        scale: 3,
                    },
                    offset: '50%',
                    repeat: '100px',
                });
            overlay.current.push(
                new sdk.Polyline({
                    map: instance,
                    path,
                    strokeColor,
                    strokeWeight: 3,
                    strokeOpacity: props.polylineOptions?.dashArray ? 0 : 0.85,
                    icons,
                    clickable: false,
                }),
            );
            if (props.polylineOptions?.showEndpoints !== false && path.length) {
                for (const [point, label] of [
                    [path[0], 'Start'],
                    [path[path.length - 1], 'End'],
                ] as const) {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = label;
                    button.className =
                        'rounded-md border bg-card px-2 py-1 text-caption text-foreground shadow-sm';
                    const item = markerOverlay(sdk, button, point);
                    item.setMap(instance);
                    overlay.current.push(item);
                }
            }
        }
        return () => {
            popups.forEach((window) => window.close());
            overlay.current.forEach((item) => item.setMap(null));
            overlay.current = [];
        };
    }, [
        sdk,
        props.markers,
        props.polyline,
        props.polylineOptions,
        props.geofences,
        props.clustering,
        props.clusterThreshold,
        props.showMarkerPopups,
        props.fitMarkers,
        props.autoFit,
        zoom,
    ]);
    useEffect(() => {
        if (!sdk || !map.current) return;
        if (!initialFitDone.current) {
            initialFitDone.current = true;
            if (props.preserveViewport) {
                fitted.current = true;
                return;
            }
        }
        const markers = (props.markers ?? []).filter(pointIsValid);
        const points = props.polyline?.length
            ? props.polyline.filter(pointIsValid)
            : markers;
        if (
            points.length &&
            ((!fitted.current && props.autoFit) ||
                (props.fitMarkers !== false &&
                    markers.length &&
                    !props.polyline?.length))
        ) {
            const bounds = new sdk.LatLngBounds();
            points.forEach((point) => bounds.extend(point));
            map.current.fitBounds(bounds, 40);
            fitted.current = true;
        }
    }, [
        sdk,
        props.markers,
        props.polyline,
        props.fitMarkers,
        props.autoFit,
        props.preserveViewport,
    ]);
    const { lat, lng } = props.center;
    useEffect(() => {
        if (map.current) {
            map.current.setCenter({ lat, lng });
            if (props.zoom !== undefined) map.current.setZoom(props.zoom);
        }
    }, [lat, lng, props.zoom]);
    useEffect(() => {
        if (map.current && props.focus) map.current.panTo(props.focus);
    }, [props.focus]);
    useEffect(() => {
        if (!sdk || !map.current || !element.current || !props.observeResize)
            return;
        const observer = new ResizeObserver(() => {
            if (map.current) sdk.event.trigger(map.current, 'resize');
        });
        observer.observe(element.current);
        return () => observer.disconnect();
    }, [sdk, props.observeResize]);
    return (
        <div
            ref={element}
            aria-label="Google map"
            className={props.className}
            style={{ height: props.height ?? 400, width: '100%' }}
        />
    );
}
