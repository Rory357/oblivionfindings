import {
    boundaryCentre,
    moveBoundary,
} from '@/components/client-location/boundary-geometry';
import type { Coordinate, Geometry } from '@/components/client-location/types';
import { Button } from '@/components/ui/button';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import {
    Crosshair,
    Layers,
    MapPin,
    Minus,
    Plus,
    Shapes,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { Boundary } from './data';

import { useMapProvider } from './provider';
export function BoundaryMap({
    boundaries = [],
    selected,
    onSelect,
    shape,
    onShape,
    draw = 'none',
    imagery = true,
    fit = 0,
    focus = 0,
    onCreate,
    onChooseBoundary,
    className = '',
}: {
    boundaries?: Boundary[];
    selected?: string;
    onSelect?: (id: string) => void;
    shape?: Geometry | null;
    onShape?: (shape: Geometry) => void;
    draw?: 'none' | 'circle' | 'polygon';
    imagery?: boolean;
    fit?: number;
    focus?: number;
    onCreate?: (point: Coordinate) => void;
    onChooseBoundary?: () => void;
    className?: string;
}) {
    const provider = useMapProvider();
    const [tileFailure, setTileFailure] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);
    const ref = useRef<HTMLDivElement>(null),
        map = useRef<L.Map | null>(null),
        overlay = useRef<L.TileLayer | null>(null),
        group = useRef<L.LayerGroup | null>(null);
    const latest = useRef({ shape, onShape, draw, onSelect });
    latest.current = { shape, onShape, draw, onSelect };
    const [menu, setMenu] = useState<{
            x: number;
            y: number;
            point: Coordinate;
        } | null>(null),
        [showLabels, setShowLabels] = useState(true);
    useEffect(() => {
        if (!menu) return;
        const key = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopImmediatePropagation();
                setMenu(null);
                ref.current?.focus();
            }
        };
        window.addEventListener('keydown', key, true);
        return () => window.removeEventListener('keydown', key, true);
    }, [!!menu]);
    useEffect(() => {
        if (!menu) return;
        menuRef.current
            ?.querySelector<HTMLButtonElement>('button:not(:disabled)')
            ?.focus();
        const outside = (e: PointerEvent) => {
            if (!menuRef.current?.contains(e.target as Node)) setMenu(null);
        };
        document.addEventListener('pointerdown', outside);
        return () => document.removeEventListener('pointerdown', outside);
    }, [menu]);
    useEffect(() => {
        if (!ref.current) return;
        const m = L.map(ref.current, {
            zoomControl: false,
            attributionControl: true,
            center: [-41.29, 174.781],
            zoom: 15,
            keyboard: true,
            scrollWheelZoom: false,
        });
        map.current = m;
        group.current = L.layerGroup().addTo(m);
        if (provider.url) {
            overlay.current = L.tileLayer(provider.url, {
                attribution: provider.attribution,
                className: 'bnd-tiles',
            })
                .on('tileerror', () => setTileFailure(true))
                .addTo(m);
        }
        L.control.scale({ imperial: false, position: 'bottomleft' }).addTo(m);
        m.on('click', (e) => {
            setMenu(null);
            const s = latest.current;
            const p = {
                lat: +e.latlng.lat.toFixed(6),
                lng: +e.latlng.lng.toFixed(6),
            };
            if (s.draw === 'circle')
                s.onShape?.({
                    type: 'circle',
                    center: p,
                    radius_m:
                        s.shape?.type === 'circle' ? s.shape.radius_m : 180,
                });
            if (s.draw === 'polygon')
                s.onShape?.({
                    type: 'polygon',
                    coordinates: [
                        ...(s.shape?.type === 'polygon'
                            ? s.shape.coordinates
                            : []),
                        p,
                    ],
                });
        });
        m.on('contextmenu', (e) => {
            setMenu({
                point: { lat: e.latlng.lat, lng: e.latlng.lng },
                x: Math.min(
                    e.containerPoint.x,
                    Math.max(0, m.getSize().x - 220),
                ),
                y: Math.min(
                    e.containerPoint.y,
                    Math.max(0, m.getSize().y - 210),
                ),
            });
        });
        const observer = new ResizeObserver(() => m.invalidateSize());
        observer.observe(ref.current);
        return () => {
            observer.disconnect();
            m.remove();
            map.current = null;
        };
    }, []);
    useEffect(() => {
        if (map.current && overlay.current) {
            if (imagery) overlay.current.addTo(map.current).bringToBack();
            else overlay.current.remove();
        }
    }, [imagery]);
    useEffect(() => {
        const m = map.current,
            g = group.current;
        if (!m || !g) return;
        g.clearLayers();
        const css = getComputedStyle(document.documentElement);
        const primary = css.getPropertyValue('--primary').trim(),
            neutral = css.getPropertyValue('--muted-foreground').trim();
        const render = (
            geometry: Geometry,
            active: boolean,
            name?: string,
            id?: string,
        ) => {
            const options = {
                color: active ? primary : neutral,
                weight: active ? 3 : 2,
                fillColor: active ? primary : neutral,
                fillOpacity: active ? 0.16 : 0.055,
                dashArray: active ? undefined : '6 5',
            };
            const layer =
                geometry.type === 'circle'
                    ? L.circle([geometry.center.lat, geometry.center.lng], {
                          ...options,
                          radius: geometry.radius_m,
                      })
                    : L.polygon(
                          geometry.coordinates.map((p) => [p.lat, p.lng]),
                          options,
                      );
            layer.addTo(g);
            if (id)
                layer.on('click', (e) => {
                    L.DomEvent.stopPropagation(e);
                    latest.current.onSelect?.(id);
                });
            if (name && showLabels) {
                const label = document.createElement('span');
                label.textContent = name;
                layer.bindTooltip(label, {
                    permanent: active || boundaries.length <= 12,
                    direction: 'top',
                    className: active ? 'map-label selected' : 'map-label',
                });
            }
        };
        boundaries.forEach((b) =>
            render(b.geometry, b.id === selected, b.name, b.id),
        );
        if (shape) {
            render(shape, true);
            if (
                onShape &&
                (shape.type === 'circle' || shape.coordinates.length > 0)
            ) {
                const c = boundaryCentre(shape);
                const centre = L.marker([c.lat, c.lng], {
                    draggable: true,
                    icon: L.divIcon({
                        className: 'map-handle centre-handle',
                        html: '<span aria-hidden="true">+</span>',
                        iconSize: [24, 24],
                    }),
                    title: 'Move whole boundary',
                }).addTo(g);
                centre.on('dragend', () => {
                    const next = centre.getLatLng();
                    latest.current.onShape?.(
                        moveBoundary(shape, c, {
                            lat: next.lat,
                            lng: next.lng,
                        }),
                    );
                });
                if (shape.type === 'polygon') {
                    shape.coordinates.forEach((p, i) => {
                        const h = L.marker([p.lat, p.lng], {
                            draggable: true,
                            icon: L.divIcon({
                                className: 'map-handle',
                                html: String(i + 1),
                                iconSize: [22, 22],
                            }),
                            title: 'Move corner ' + (i + 1),
                        }).addTo(g);
                        h.on('dragend', () => {
                            const n = h.getLatLng();
                            latest.current.onShape?.({
                                ...shape,
                                coordinates: shape.coordinates.map((v, j) =>
                                    i === j
                                        ? {
                                              lat: +n.lat.toFixed(6),
                                              lng: +n.lng.toFixed(6),
                                          }
                                        : v,
                                ),
                            });
                        });
                    });
                }
            }
        }
    }, [boundaries, selected, shape, onShape, showLabels]);
    useEffect(() => {
        const m = map.current;
        if (!m) return;
        const source = shape ? [shape] : boundaries.map((b) => b.geometry);
        if (source.length) {
            const points: Coordinate[] = source.flatMap((s) =>
                s.type === 'circle'
                    ? [
                          {
                              lat: s.center.lat - s.radius_m / 111320,
                              lng:
                                  s.center.lng -
                                  s.radius_m /
                                      (111320 *
                                          Math.max(
                                              0.01,
                                              Math.cos(
                                                  (s.center.lat * Math.PI) /
                                                      180,
                                              ),
                                          )),
                          },
                          {
                              lat: s.center.lat + s.radius_m / 111320,
                              lng:
                                  s.center.lng +
                                  s.radius_m /
                                      (111320 *
                                          Math.max(
                                              0.01,
                                              Math.cos(
                                                  (s.center.lat * Math.PI) /
                                                      180,
                                              ),
                                          )),
                          },
                      ]
                    : s.coordinates,
            );
            if (points.length)
                m.fitBounds(
                    points.map((p) => [p.lat, p.lng]),
                    { padding: [40, 40], maxZoom: 16, animate: false },
                );
        }
    }, [fit, boundaries.map((b) => b.id).join('|')]);
    useEffect(() => {
        const b = boundaries.find((b) => b.id === selected);
        if (focus > 0 && b && map.current) {
            const c = boundaryCentre(b.geometry);
            map.current.setView([c.lat, c.lng], 16, { animate: false });
        }
    }, [focus]);
    return (
        <div className={'map-wrap ' + className}>
            <div
                ref={ref}
                tabIndex={0}
                className="map-canvas"
                aria-label="Boundary map. Use the list to select a boundary. Press Shift+F10 for map actions."
                onKeyDown={(e) => {
                    if (
                        e.key === 'ContextMenu' ||
                        (e.shiftKey && e.key === 'F10')
                    ) {
                        e.preventDefault();
                        e.stopPropagation();
                        const m = map.current;
                        if (m) {
                            const c = m.getCenter();
                            setMenu({
                                point: { lat: c.lat, lng: c.lng },
                                x: Math.max(0, m.getSize().x / 2 - 100),
                                y: Math.max(0, m.getSize().y / 2 - 70),
                            });
                        }
                    }
                }}
            />
            <div className="map-tools">
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => {
                        const m = map.current;
                        if (m) {
                            const c = m.getCenter();
                            setMenu({
                                x: 10,
                                y: 50,
                                point: { lat: c.lat, lng: c.lng },
                            });
                        }
                    }}
                >
                    Map actions
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Zoom in"
                    onClick={() => map.current?.zoomIn()}
                >
                    <Plus />
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Zoom out"
                    onClick={() => map.current?.zoomOut()}
                >
                    <Minus />
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Centre selected boundary"
                    onClick={() => {
                        const s =
                            shape ??
                            boundaries.find((b) => b.id === selected)?.geometry;
                        if (
                            s &&
                            (s.type === 'circle' || s.coordinates.length > 0)
                        ) {
                            const c = boundaryCentre(s);
                            map.current?.setView([c.lat, c.lng], 16, {
                                animate: false,
                            });
                        }
                    }}
                >
                    <Crosshair />
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Toggle boundary labels"
                    aria-pressed={showLabels}
                    onClick={() => setShowLabels(!showLabels)}
                >
                    <Layers />
                </Button>
            </div>
            {(!imagery || !provider.url || tileFailure) && (
                <div className="imagery-state">
                    <MapPin size={17} />
                    <div>
                        <strong>Map imagery unavailable</strong>
                        <p>
                            Boundaries and selection are retained. Use the list
                            or coordinates.
                        </p>
                    </div>
                </div>
            )}
            {draw !== 'none' && (
                <div className="map-instruction">
                    {draw === 'circle'
                        ? 'Click to place the centre · adjust the radius below'
                        : 'Click to add corners · choose Finish polygon when ready'}
                </div>
            )}
            {menu && (
                <div
                    ref={menuRef}
                    className="map-context"
                    role="menu"
                    aria-label="Map actions"
                    style={{ left: menu.x, top: menu.y }}
                    onKeyDown={(e) => {
                        const items = Array.from(
                            menuRef.current?.querySelectorAll<HTMLButtonElement>(
                                'button:not(:disabled)',
                            ) ?? [],
                        );
                        const index = items.indexOf(
                            document.activeElement as HTMLButtonElement,
                        );
                        if (e.key === 'Escape') {
                            e.preventDefault();
                            e.stopPropagation();
                            setMenu(null);
                            ref.current?.focus();
                        } else if (e.key === 'Tab') {
                            setMenu(null);
                            ref.current?.focus();
                        } else if (
                            ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(
                                e.key,
                            )
                        ) {
                            e.preventDefault();
                            const next =
                                e.key === 'Home'
                                    ? 0
                                    : e.key === 'End'
                                      ? items.length - 1
                                      : (index +
                                            (e.key === 'ArrowDown' ? 1 : -1) +
                                            items.length) %
                                        items.length;
                            items[next]?.focus();
                        }
                    }}
                >
                    {onCreate && (
                        <Button
                            role="menuitem"
                            variant="ghost"
                            onClick={() => {
                                onCreate(menu.point);
                                setMenu(null);
                            }}
                        >
                            <Shapes />
                            Draw boundary here
                        </Button>
                    )}
                    {onShape && (
                        <Button
                            role="menuitem"
                            variant="ghost"
                            onClick={() => {
                                onShape(
                                    draw === 'polygon'
                                        ? {
                                              type: 'polygon',
                                              coordinates: [
                                                  ...(shape?.type === 'polygon'
                                                      ? shape.coordinates
                                                      : []),
                                                  menu.point,
                                              ],
                                          }
                                        : {
                                              type: 'circle',
                                              center: menu.point,
                                              radius_m:
                                                  shape?.type === 'circle'
                                                      ? shape.radius_m
                                                      : 180,
                                          },
                                );
                                setMenu(null);
                            }}
                        >
                            <MapPin />
                            {draw === 'polygon'
                                ? 'Add corner here'
                                : 'Place radius here'}
                        </Button>
                    )}
                    {onShape &&
                        shape &&
                        (shape.type === 'circle' ||
                            shape.coordinates.length > 0) && (
                            <Button
                                role="menuitem"
                                variant="ghost"
                                onClick={() => {
                                    onShape(
                                        moveBoundary(
                                            shape,
                                            boundaryCentre(shape),
                                            menu.point,
                                        ),
                                    );
                                    setMenu(null);
                                }}
                            >
                                <Crosshair />
                                Move whole area here
                            </Button>
                        )}
                    <Button
                        role="menuitem"
                        variant="ghost"
                        onClick={() => {
                            map.current?.panTo([
                                menu.point.lat,
                                menu.point.lng,
                            ]);
                            setMenu(null);
                        }}
                    >
                        <Crosshair />
                        Centre here
                    </Button>
                    {onChooseBoundary && (
                        <Button
                            role="menuitem"
                            variant="ghost"
                            onClick={() => {
                                setMenu(null);
                                onChooseBoundary();
                            }}
                        >
                            <Shapes />
                            Select existing boundary
                        </Button>
                    )}
                    <Button
                        role="menuitem"
                        variant="ghost"
                        onClick={() => setMenu(null)}
                    >
                        <X />
                        Close
                    </Button>
                </div>
            )}
            <div className="map-attribution">Shared boundary geometry</div>
        </div>
    );
}
