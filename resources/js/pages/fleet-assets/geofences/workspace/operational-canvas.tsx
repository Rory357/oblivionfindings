import { boundaryCentre } from '@/components/client-location/boundary-geometry';
import type { Coordinate } from '@/components/client-location/types';
import {
    EntityContextMenu,
    type MenuItem,
} from '@/components/lists/entity-menu';
import { formatDateTime } from '@/lib/datetime';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { Compass, MapPin, Minus, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import type { Boundary, Rule } from './data';
import type { MapResource } from './map-data';
import { clusterPoints, linkedIds, resourceStatus } from './map-model';
import { useMapProvider } from './provider';
import { Button } from './ui';

type Props = {
    boundaries: Boundary[];
    resources: MapResource[];
    statusBoundaries: Boundary[];
    rules: Rule[];
    selectedResource: string;
    selectedBoundary: string;
    fit: number;
    focus: number;
    clustering: boolean;
    accuracy: boolean;
    imagery: boolean;
    onResource: (id: string) => void;
    onBoundary: (id: string) => void;
    onCreate?: (p: Coordinate) => void;
    boundaryActions: (b: Boundary) => MenuItem[];
    resourceActions: (r: MapResource) => MenuItem[];
    onChooseBoundary: () => void;
    onFit: () => void;
};
// Constant line icons; user-provided labels are inserted with textContent below.
const svg = (body: string) =>
    `<svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${body}</svg>`;
const glyphs = {
    Vehicle: svg(
        '<path d="M10 17h4V5H2v12h3m10-9h4l3 4v5h-3"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
    ),
    Asset: svg(
        '<path d="m12 3 9 5v9l-9 5-9-5V8l9-5Zm0 10v9M3 8l9 5 9-5M7.5 5.5l9 5"/>',
    ),
};
const node = (tag: string, text: string, cls = '') => {
    const el = document.createElement(tag);
    el.textContent = text;
    el.className = cls;
    return el;
};
export function OperationalCanvas(p: Props) {
    const provider = useMapProvider();
    const [tileFailure, setTileFailure] = useState(false);
    const ref = useRef<HTMLDivElement>(null),
        map = useRef<L.Map | null>(null),
        zones = useRef<L.LayerGroup | null>(null),
        markers = useRef<L.LayerGroup | null>(null),
        backdrop = useRef<L.TileLayer | null>(null),
        latest = useRef(p);
    latest.current = p;
    const [camera, setCamera] = useState(0),
        [menu, setMenu] = useState<{
            x: number;
            y: number;
            title: string;
            items: MenuItem[];
        } | null>(null);
    const show = (event: MouseEvent, title: string, items: MenuItem[]) => {
        event.preventDefault();
        event.stopPropagation();
        setMenu({ x: event.clientX, y: event.clientY, title, items });
    };
    const blankItems = (point: Coordinate): MenuItem[] => [
        ...(latest.current.onCreate
            ? [
                  {
                      label: 'Draw boundary here',
                      icon: MapPin,
                      onClick: () => latest.current.onCreate?.(point),
                  },
              ]
            : []),
        {
            label: 'Centre here',
            icon: Compass,
            onClick: () => map.current?.panTo([point.lat, point.lng]),
        },
        {
            label: 'Select existing boundary',
            icon: MapPin,
            onClick: () => latest.current.onChooseBoundary(),
        },
        {
            label: 'Fit displayed results',
            icon: Compass,
            onClick: () => latest.current.onFit(),
        },
    ];
    const openAtCentre = () => {
        const m = map.current;
        if (!m || !ref.current) return;
        const c = m.getCenter(),
            rect = ref.current.getBoundingClientRect();
        setMenu({
            x: rect.left + 24,
            y: rect.top + 64,
            title: 'Map actions · centre point',
            items: blankItems({ lat: c.lat, lng: c.lng }),
        });
    };
    const keyboardMenu = (
        el: Element | null,
        title: string,
        items: () => MenuItem[],
    ) => {
        el?.addEventListener('keydown', (event: Event) => {
            const e = event as KeyboardEvent;
            if (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) {
                e.preventDefault();
                e.stopPropagation();
                const box = el.getBoundingClientRect();
                setMenu({
                    x: box.left + 12,
                    y: box.top + 12,
                    title,
                    items: items(),
                });
            }
        });
    };
    useEffect(() => {
        if (!ref.current) return;
        const m = L.map(ref.current, {
            zoomControl: false,
            attributionControl: true,
            center: [-41.294, 174.781],
            zoom: 14,
            keyboard: true,
            scrollWheelZoom: false,
        });
        map.current = m;
        if (provider.url)
            backdrop.current = L.tileLayer(provider.url, {
                attribution: provider.attribution,
                className: 'bnd-tiles',
            })
                .on('tileerror', () => setTileFailure(true))
                .addTo(m);
        zones.current = L.layerGroup().addTo(m);
        markers.current = L.layerGroup().addTo(m);
        L.control.scale({ imperial: false }).addTo(m);
        m.on('moveend zoomend', () => {
            setCamera((n) => n + 1);
            setMenu(null);
        });
        m.on('contextmenu', (e) =>
            show(
                e.originalEvent,
                'Map actions · clicked point',
                blankItems({ lat: e.latlng.lat, lng: e.latlng.lng }),
            ),
        );
        m.on('click', () => setMenu(null));
        const observer = new ResizeObserver(() => m.invalidateSize());
        observer.observe(ref.current);
        return () => {
            observer.disconnect();
            m.remove();
            map.current = null;
        };
    }, []);
    useEffect(() => {
        const m = map.current,
            b = backdrop.current;
        if (m && b) {
            if (p.imagery) b.addTo(m).bringToBack();
            else b.remove();
        }
    }, [p.imagery]);
    useEffect(() => {
        const m = map.current,
            g = zones.current;
        if (!m || !g) return;
        g.clearLayers();
        const css = getComputedStyle(document.documentElement),
            brand = css.getPropertyValue('--primary').trim(),
            muted = css.getPropertyValue('--muted-foreground').trim();
        const selected = p.resources.find((r) => r.id === p.selectedResource),
            related = selected ? linkedIds(selected, p.rules) : [];
        for (const b of p.boundaries) {
            const active = b.id === p.selectedBoundary,
                linked = related.includes(b.id);
            const options = {
                bubblingMouseEvents: false,
                // The basemap stays light/greyscale in both app themes. Keep
                // every available area legible, including unselected areas.
                color:
                    b.status === 'Retired' ? muted : 'var(--bnd-map-outline)',
                fillColor: brand,
                weight: active ? 3.5 : linked ? 3 : 2,
                opacity: 1,
                fillOpacity: active ? 0.2 : linked ? 0.13 : 0.07,
                dashArray: b.status === 'Retired' ? '3 6' : undefined,
            };
            const s = b.geometry;
            const layer =
                s.type === 'circle'
                    ? L.circle([s.center.lat, s.center.lng], {
                          ...options,
                          radius: s.radius_m,
                      })
                    : L.polygon(
                          s.coordinates.map((c) => [c.lat, c.lng]),
                          options,
                      );
            layer.addTo(g);
            const tip = node('div', '');
            tip.append(
                node('strong', b.name),
                node('small', `${b.id} · geometry v${b.version} · ${b.status}`),
            );
            layer
                .bindTooltip(tip, {
                    permanent: active,
                    direction: 'top',
                    className: 'ops-zone-label',
                })
                .on('click', () => latest.current.onBoundary(b.id))
                .on('contextmenu', (e) =>
                    show(
                        e.originalEvent,
                        b.name,
                        latest.current.boundaryActions(b),
                    ),
                );
            const element = layer.getElement();
            element?.setAttribute('tabindex', active ? '0' : '-1');
            element?.setAttribute('role', 'button');
            element?.setAttribute(
                'aria-label',
                'Inspect boundary ' + b.name + ' ' + b.id,
            );
            keyboardMenu(element ?? null, b.name, () =>
                latest.current.boundaryActions(b),
            );
            element?.addEventListener('keydown', (event: Event) => {
                const e = event as KeyboardEvent;
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    e.stopPropagation();
                    latest.current.onBoundary(b.id);
                }
            });
        }
    }, [
        p.boundaries,
        p.selectedBoundary,
        p.selectedResource,
        p.resources,
        p.rules,
    ]);
    useEffect(() => {
        const m = map.current,
            g = markers.current;
        if (!m || !g) return;
        g.clearLayers();
        const positioned = p.resources.filter((r) => r.position);
        const selected = positioned.find((r) => r.id === p.selectedResource);
        const ordinary = positioned.filter((r) => r.id !== p.selectedResource);
        const groups =
            p.clustering && m.getZoom() < 17
                ? clusterPoints(ordinary, (c) =>
                      m.latLngToContainerPoint([c.lat, c.lng]),
                  )
                : ordinary.map((r) => [r]);
        if (selected) groups.push([selected]);
        for (const group of groups) {
            if (group.length > 1) {
                const point = {
                    lat:
                        group.reduce((n, r) => n + r.position!.lat, 0) /
                        group.length,
                    lng:
                        group.reduce((n, r) => n + r.position!.lng, 0) /
                        group.length,
                };
                const content = node(
                    'div',
                    String(group.length),
                    'ops-cluster',
                );
                const marker = L.marker([point.lat, point.lng], {
                    icon: L.divIcon({
                        html: content,
                        className: 'ops-cluster-shell',
                        iconSize: [38, 38],
                    }),
                    keyboard: true,
                    title: `Expand cluster of ${group.length} records`,
                    bubblingMouseEvents: false,
                }).addTo(g);
                marker
                    .getElement()
                    ?.setAttribute(
                        'aria-label',
                        `Expand cluster of ${group.length} records`,
                    );
                const expand = () =>
                    m.fitBounds(
                        group.map((r) => [r.position!.lat, r.position!.lng]),
                        {
                            padding: [65, 65],
                            maxZoom: m.getZoom() + 2,
                            animate: false,
                        },
                    );
                const clusterItems = () => [
                    { label: 'Expand this group', icon: Plus, onClick: expand },
                    {
                        label: 'Centre on this group',
                        icon: Compass,
                        onClick: () => m.panTo([point.lat, point.lng]),
                    },
                ];
                marker.bindTooltip(
                    node(
                        'span',
                        group.length +
                            ' vehicles and assets · zoom to separate',
                    ),
                );
                marker
                    .on('click', expand)
                    .on('contextmenu', (e) =>
                        show(
                            e.originalEvent,
                            group.length + ' nearby records',
                            clusterItems(),
                        ),
                    );
                keyboardMenu(
                    marker.getElement() ?? null,
                    'Nearby records',
                    clusterItems,
                );
                continue;
            }
            const r = group[0],
                status = resourceStatus(r, p.statusBoundaries, p.rules),
                isSelected = r.id === p.selectedResource;
            const icon = node(
                'div',
                '',
                `ops-marker ${status.key} ${r.kind.toLowerCase()} ${isSelected ? 'selected' : ''}`,
            );
            icon.innerHTML = glyphs[r.kind];
            const marker = L.marker([r.position!.lat, r.position!.lng], {
                icon: L.divIcon({
                    html: icon,
                    className: 'ops-marker-shell',
                    iconSize: [34, 34],
                }),
                keyboard: true,
                zIndexOffset: isSelected ? 1000 : 100,
                title: `${r.name} · ${status.label}`,
                bubblingMouseEvents: false,
            }).addTo(g);
            const tip = node('div', '', 'ops-hover-card');
            tip.append(
                node('small', `${r.kind} · ${r.tag}`),
                node('strong', r.name),
                node('b', status.label, `ops-state ${status.key}`),
            );
            for (const rel of status.relations.slice(0, 3))
                tip.append(
                    node(
                        'span',
                        `${r.fresh ? '' : 'Last recorded: '}${rel.state} · ${rel.boundary.name}`,
                    ),
                );
            if (status.relations.length > 3)
                tip.append(
                    node(
                        'span',
                        `+${status.relations.length - 3} more linked areas`,
                    ),
                );
            tip.append(
                node(
                    'small',
                    `Observed ${formatDateTime(r.observed)} · ${r.accuracy === null ? 'accuracy unknown' : `±${r.accuracy} m`}`,
                ),
                node('small', status.detail),
                node(
                    'small',
                    r.kind === 'Vehicle'
                        ? 'Speed · ignition · voltage: not supplied'
                        : 'Location is not proof of availability',
                ),
                node(
                    'em',
                    'Click to pin · right-click or Shift+F10 for actions',
                ),
            );
            marker.bindTooltip(tip, {
                direction: 'top',
                offset: [0, -17],
                className: 'ops-resource-tooltip',
                opacity: 1,
            });
            const element = marker.getElement();
            element?.setAttribute(
                'aria-label',
                `Inspect ${r.name} ${r.tag}: ${status.label}`,
            );
            element?.addEventListener('focus', () => marker.openTooltip());
            element?.addEventListener('blur', () => marker.closeTooltip());
            marker
                .on('click', () => latest.current.onResource(r.id))
                .on('contextmenu', (e) =>
                    show(
                        e.originalEvent,
                        r.name,
                        latest.current.resourceActions(r),
                    ),
                );
            keyboardMenu(element ?? null, r.name, () =>
                latest.current.resourceActions(r),
            );
            if (isSelected && p.accuracy && r.accuracy !== null)
                L.circle([r.position!.lat, r.position!.lng], {
                    radius: r.accuracy,
                    color: '#64748b',
                    weight: 1,
                    dashArray: '3 4',
                    fillOpacity: 0.07,
                    interactive: false,
                })
                    .addTo(g)
                    .bringToBack();
        }
    }, [
        p.resources,
        p.statusBoundaries,
        p.rules,
        p.selectedResource,
        p.clustering,
        p.accuracy,
        camera,
    ]);
    const scopeKey =
        p.boundaries.map((b) => b.id).join('|') +
        ';' +
        p.resources
            .filter((r) => r.position)
            .map((r) => r.id)
            .join('|');
    useEffect(() => {
        const m = map.current;
        if (!m) return;
        const points: Coordinate[] = [
            ...p.resources.flatMap((r) => (r.position ? [r.position] : [])),
            ...p.boundaries.flatMap((b) =>
                b.geometry.type === 'circle'
                    ? [
                          {
                              lat:
                                  b.geometry.center.lat -
                                  b.geometry.radius_m / 111320,
                              lng:
                                  b.geometry.center.lng -
                                  b.geometry.radius_m /
                                      (111320 *
                                          Math.cos(
                                              (b.geometry.center.lat *
                                                  Math.PI) /
                                                  180,
                                          )),
                          },
                          {
                              lat:
                                  b.geometry.center.lat +
                                  b.geometry.radius_m / 111320,
                              lng:
                                  b.geometry.center.lng +
                                  b.geometry.radius_m /
                                      (111320 *
                                          Math.cos(
                                              (b.geometry.center.lat *
                                                  Math.PI) /
                                                  180,
                                          )),
                          },
                      ]
                    : b.geometry.coordinates,
            ),
        ];
        if (points.length)
            m.fitBounds(
                points.map((c) => [c.lat, c.lng]),
                { padding: [55, 55], maxZoom: 16, animate: false },
            );
    }, [p.fit, scopeKey]);
    useEffect(() => {
        if (!p.focus || !map.current) return;
        const r = p.resources.find((r) => r.id === p.selectedResource),
            b = p.boundaries.find((b) => b.id === p.selectedBoundary);
        const c =
            r?.position ??
            (!p.selectedResource && b ? boundaryCentre(b.geometry) : null);
        if (c) map.current.setView([c.lat, c.lng], 17, { animate: false });
    }, [p.focus]);
    return (
        <div className="ops-canvas">
            <div
                ref={ref}
                tabIndex={0}
                onKeyDown={(e) => {
                    if (
                        e.target === e.currentTarget &&
                        (e.key === 'ContextMenu' ||
                            (e.shiftKey && e.key === 'F10'))
                    ) {
                        e.preventDefault();
                        openAtCentre();
                    }
                }}
                className="ops-leaflet"
                aria-label="All permitted boundaries, vehicles and assets map. Use the record list or marker keyboard controls."
            />
            <div className="ops-map-tools">
                <Button variant="outline" size="sm" onClick={openAtCentre}>
                    Map actions
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Zoom map in"
                    onClick={() => map.current?.zoomIn()}
                >
                    <Plus />
                </Button>
                <Button
                    variant="outline"
                    size="icon"
                    aria-label="Zoom map out"
                    onClick={() => map.current?.zoomOut()}
                >
                    <Minus />
                </Button>
                {p.onCreate && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => {
                            const c = map.current?.getCenter();
                            if (c) p.onCreate?.({ lat: c.lat, lng: c.lng });
                        }}
                    >
                        <MapPin />
                        Build here
                    </Button>
                )}
            </div>
            {(!p.imagery || !provider.url || tileFailure) && (
                <div className="ops-map-unavailable">
                    Map imagery unavailable · authorised shapes and recorded
                    positions retained
                </div>
            )}
            {menu &&
                createPortal(
                    <EntityContextMenu
                        key={`${menu.x}:${menu.y}:${menu.title}`}
                        {...menu}
                        onClose={() => setMenu(null)}
                    />,
                    document.body,
                )}
            <div className="ops-attribution">
                Recorded locations · geometry does not confirm a crossing
            </div>
        </div>
    );
}
