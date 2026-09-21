import type { Geofence } from '@/components/resident-tracking/types';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { useEffect, useRef } from 'react';
import { boundaryCentre, moveBoundary } from './boundary-geometry';
import {
    geometryError,
    zoneMonitoringLabel,
    type Coordinate,
    type Geometry,
    type ZoneDraft,
} from './types';

type Props = {
    center: Coordinate;
    observation?: Coordinate | null;
    focus?: Coordinate | null;
    focusShape?: Geometry | null;
    searchPoint?: Coordinate | null;
    zones?: ZoneDraft[];
    references?: Geofence[];
    shape?: Geometry | null;
    editing?: boolean;
    drawing?: 'circle' | 'polygon' | null;
    onChange?: (shape: Geometry) => void;
    onMapPoint?: (point: Coordinate) => void;
    onContext?: (point: Coordinate) => void;
    onSelectZone?: (zone: ZoneDraft) => void;
};
const coord = (point: L.LatLng): Coordinate => ({
    lat: +point.lat.toFixed(6),
    lng: +point.lng.toFixed(6),
});

export default function ClientLocationMap(props: Props) {
    const element = useRef<HTMLDivElement>(null);
    const map = useRef<L.Map | null>(null);
    const layers = useRef<L.LayerGroup | null>(null);
    const latest = useRef(props);
    const ignoreClickUntil = useRef(0);
    useEffect(() => {
        latest.current = props;
    });
    useEffect(() => {
        if (!element.current) return;
        const instance = L.map(element.current, {
            scrollWheelZoom: false,
        }).setView([latest.current.center.lat, latest.current.center.lng], 16);
        map.current = instance;
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(instance);
        layers.current = L.layerGroup().addTo(instance);
        instance.on('click', (event: L.LeafletMouseEvent) => {
            if (Date.now() >= ignoreClickUntil.current)
                latest.current.onMapPoint?.(coord(event.latlng));
        });
        instance.on('contextmenu', (event: L.LeafletMouseEvent) => {
            L.DomEvent.preventDefault(event.originalEvent);
            latest.current.onContext?.(coord(event.latlng));
        });
        const resize = new ResizeObserver(() => instance.invalidateSize());
        resize.observe(element.current);
        return () => {
            resize.disconnect();
            instance.remove();
            map.current = null;
            layers.current = null;
        };
    }, []);
    useEffect(() => {
        if (props.focusShape && !geometryError(props.focusShape)) {
            const shape = props.focusShape;
            const bounds =
                shape.type === 'circle'
                    ? L.circle([shape.center.lat, shape.center.lng], {
                          radius: shape.radius_m,
                      }).getBounds()
                    : L.latLngBounds(
                          shape.coordinates.map(
                              (p) => [p.lat, p.lng] as L.LatLngTuple,
                          ),
                      );
            map.current?.fitBounds(bounds, { padding: [32, 32], maxZoom: 17 });
        } else if (props.focus)
            map.current?.setView([props.focus.lat, props.focus.lng], 17);
    }, [props.focus, props.focusShape]);
    useEffect(() => {
        const group = layers.current,
            instance = map.current;
        if (!group || !instance) return;
        const focusedHandle = element.current?.contains(document.activeElement)
            ? document.activeElement?.getAttribute('aria-label')
            : null;
        group.clearLayers();
        const draw = (shape: Geometry, selected: boolean) => {
            const style = {
                color: selected ? '#6d4aff' : '#718096',
                weight: selected ? 3 : 2,
                fillOpacity: selected ? 0.14 : 0.06,
                dashArray: '7 5',
            };
            if (shape.type === 'circle') {
                if (
                    !Number.isFinite(shape.center.lat) ||
                    !Number.isFinite(shape.center.lng) ||
                    !Number.isFinite(shape.radius_m) ||
                    shape.radius_m <= 0
                )
                    return null;
                return L.circle([shape.center.lat, shape.center.lng], {
                    ...style,
                    radius: shape.radius_m,
                }).addTo(group);
            }
            return L.polygon(
                shape.coordinates.map((p) => [p.lat, p.lng] as L.LatLngTuple),
                style,
            ).addTo(group);
        };
        props.references?.forEach((zone) => {
            const shape: Geometry | null =
                zone.type === 'circle' && zone.center && zone.radius_m
                    ? {
                          type: 'circle',
                          center: zone.center,
                          radius_m: zone.radius_m,
                      }
                    : zone.coordinates
                      ? { type: 'polygon', coordinates: zone.coordinates }
                      : null;
            if (!shape) return;
            const label = document.createElement('span');
            label.textContent = `${zone.name} · existing site boundary`;
            draw(shape, false)?.bindTooltip(label);
        });
        props.zones?.forEach((zone) => {
            const layer = draw(zone.geometry, false);
            const label = document.createElement('span');
            label.textContent = `${zone.name} · ${zoneMonitoringLabel(zone)}`;
            if (
                zone.monitoring?.status === 'active' &&
                zone.monitoring.authority_current !== false
            )
                layer?.setStyle({
                    color: '#7555d9',
                    dashArray: undefined,
                    fillOpacity: 0.1,
                });
            layer
                ?.bindTooltip(label)
                .on('click', () => latest.current.onSelectZone?.(zone));
        });
        if (props.observation)
            L.circleMarker([props.observation.lat, props.observation.lng], {
                radius: 8,
                color: '#fff',
                weight: 3,
                fillColor: '#6d4aff',
                fillOpacity: 1,
            })
                .addTo(group)
                .bindTooltip('Recorded observation');
        if (props.searchPoint)
            L.circleMarker(props.searchPoint, {
                radius: 6,
                color: '#51417d',
                weight: 2,
                fillColor: '#fff',
                fillOpacity: 1,
                interactive: false,
            })
                .addTo(group)
                .bindTooltip('Selected address');
        if (!props.shape) return;
        const outline = draw(props.shape, true);
        if (!props.editing) return;
        const shape = props.shape;
        const handles: {
            marker: L.Marker;
            position: (value: Geometry) => Coordinate;
        }[] = [];
        let preview = shape;
        const renderPreview = (next: Geometry, active?: L.Marker) => {
            preview = next;
            if (next.type === 'circle' && outline instanceof L.Circle)
                outline.setLatLng(next.center).setRadius(next.radius_m);
            else if (next.type === 'polygon' && outline instanceof L.Polygon)
                outline.setLatLngs(next.coordinates.map((p) => [p.lat, p.lng]));
            handles.forEach(({ marker, position }) => {
                if (marker !== active) marker.setLatLng(position(next));
            });
        };
        const commit = (next: Geometry) => {
            ignoreClickUntil.current = Date.now() + 350;
            latest.current.onChange?.(next);
        };
        const movePoint = (index: number, next: Coordinate): Geometry => {
            if (shape.type === 'circle') return { ...shape, center: next };
            return {
                ...shape,
                coordinates: shape.coordinates.map((p, i) =>
                    i === index ? next : p,
                ),
            };
        };
        const centre = boundaryCentre;
        const translate = (from: Coordinate, to: Coordinate) =>
            moveBoundary(shape, from, to);
        const handle = (
            position: (value: Geometry) => Coordinate,
            label: string,
            move: (next: Coordinate) => Geometry,
            content: string,
            wholeZone = false,
        ) => {
            const point = position(shape);
            const marker = L.marker([point.lat, point.lng], {
                draggable: true,
                keyboard: true,
                title: label,
                icon: L.divIcon({
                    className: `location-map-handle${wholeZone ? ' location-map-move' : ''}`,
                    html: content,
                    iconSize: [44, 44],
                    iconAnchor: [22, 22],
                }),
            }).addTo(group);
            handles.push({ marker, position });
            marker.on('drag', () =>
                renderPreview(move(coord(marker.getLatLng())), marker),
            );
            marker.on('dragend', () => commit(move(coord(marker.getLatLng()))));
            const node = marker.getElement();
            node?.setAttribute('role', 'button');
            node?.setAttribute(
                'aria-label',
                `${label}. Drag or use arrow keys to move.`,
            );
            if (focusedHandle === node?.getAttribute('aria-label'))
                node?.focus({ preventScroll: true });
            node?.addEventListener('keydown', (event) => {
                const delta: Record<string, [number, number]> = {
                    ArrowLeft: [-1, 0],
                    ArrowRight: [1, 0],
                    ArrowUp: [0, -1],
                    ArrowDown: [0, 1],
                };
                if (!delta[event.key]) return;
                event.preventDefault();
                event.stopPropagation();
                const [x, y] = delta[event.key],
                    step = event.shiftKey ? 1 : 8;
                const next = instance.layerPointToLatLng(
                    instance
                        .latLngToLayerPoint(marker.getLatLng())
                        .add(L.point(x * step, y * step)),
                );
                commit(move(coord(next)));
            });
        };
        (shape.type === 'circle' ? [shape.center] : shape.coordinates).forEach(
            (_point, i) =>
                handle(
                    (value) =>
                        value.type === 'circle'
                            ? value.center
                            : value.coordinates[i],
                    shape.type === 'circle'
                        ? 'Move entire safe zone'
                        : `Corner ${i + 1}`,
                    (next) => movePoint(i, next),
                    shape.type === 'circle' ? '✥' : String(i + 1),
                    shape.type === 'circle',
                ),
        );
        if (shape.type === 'polygon' && !geometryError(shape) && !props.drawing)
            handle(
                centre,
                'Move entire safe zone',
                (next) => translate(centre(shape), next),
                '✥',
                true,
            );
        if (shape.type === 'circle' && shape.radius_m > 0) {
            const edge = (value: Geometry): Coordinate => {
                if (value.type !== 'circle') return centre(value);
                return {
                    lat: value.center.lat,
                    lng:
                        value.center.lng +
                        value.radius_m /
                            (111320 *
                                Math.cos((value.center.lat * Math.PI) / 180)),
                };
            };
            handle(
                edge,
                'Circle radius',
                (next) => ({
                    ...shape,
                    radius_m: Math.round(instance.distance(shape.center, next)),
                }),
                '↔',
            );
        }
        // Commit once on release so a complete drag is a single undo step.
        // Pointer capture keeps touch/mouse movement working outside the shape.
        const path = outline?.getElement();
        if (
            !(path instanceof SVGElement) ||
            props.drawing ||
            geometryError(shape)
        )
            return;
        path.classList.add('location-map-movable');
        let cancelDrag: (() => void) | undefined;
        const startDrag = (event: PointerEvent) => {
            if (event.button !== 0 || !event.isPrimary) return;
            event.preventDefault();
            event.stopPropagation();
            cancelDrag?.();
            const start = instance.mouseEventToLatLng(event);
            const wasDraggable = instance.dragging.enabled();
            instance.dragging.disable();
            path.setPointerCapture(event.pointerId);
            element.current?.classList.add('location-map-dragging');
            let moved = false;
            const update = (moveEvent: PointerEvent) => {
                if (moveEvent.pointerId !== event.pointerId) return;
                moveEvent.preventDefault();
                if (
                    Math.hypot(
                        moveEvent.clientX - event.clientX,
                        moveEvent.clientY - event.clientY,
                    ) < 3 &&
                    !moved
                )
                    return;
                moved = true;
                renderPreview(
                    translate(
                        coord(start),
                        coord(instance.mouseEventToLatLng(moveEvent)),
                    ),
                );
            };
            const cleanup = () => {
                path.removeEventListener('pointermove', update);
                path.removeEventListener('pointerup', finish);
                path.removeEventListener('pointercancel', cancel);
                path.removeEventListener('lostpointercapture', cancel);
                document.removeEventListener('keydown', escape, true);
                if (path.hasPointerCapture(event.pointerId))
                    path.releasePointerCapture(event.pointerId);
                if (wasDraggable) instance.dragging.enable();
                element.current?.classList.remove('location-map-dragging');
                cancelDrag = undefined;
            };
            const cancel = () => {
                cleanup();
                renderPreview(shape);
                ignoreClickUntil.current = Date.now() + 350;
            };
            const finish = (up: PointerEvent) => {
                if (up.pointerId !== event.pointerId) return;
                update(up);
                cleanup();
                if (moved) commit(preview);
            };
            const escape = (key: KeyboardEvent) => {
                if (key.key !== 'Escape') return;
                key.preventDefault();
                key.stopPropagation();
                cancel();
            };
            cancelDrag = cancel;
            path.addEventListener('pointermove', update);
            path.addEventListener('pointerup', finish);
            path.addEventListener('pointercancel', cancel);
            path.addEventListener('lostpointercapture', cancel);
            document.addEventListener('keydown', escape, true);
        };
        path.addEventListener('pointerdown', startDrag);
        return () => {
            cancelDrag?.();
            path.removeEventListener('pointerdown', startDrag);
        };
    }, [
        props.zones,
        props.references,
        props.shape,
        props.editing,
        props.drawing,
        props.observation,
        props.searchPoint,
    ]);

    return (
        <div
            ref={element}
            className="client-location-map"
            aria-label={
                props.editing
                    ? 'Draw or edit the zone boundary'
                    : 'Client location map'
            }
            role="region"
        />
    );
}
