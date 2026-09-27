import { EntityContextMenu } from '@/components/lists';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { Layers, Maximize2 } from 'lucide-react';
import { useEffect, useRef, useState, type MutableRefObject } from 'react';
import { labels, time, type Boundary, type Person } from './model';
import './workspace.css';

export type MapCamera = {
    center: L.LatLngTuple;
    zoom: number;
    focusSelection?: boolean;
};
type MarkerRecord = { marker: L.Marker; members: Person[] };
type Props = {
    people: Person[];
    selected: string;
    onSelect: (person: Person) => void;
    onContext: (x: number, y: number, person: Person) => void;
    camera: MutableRefObject<MapCamera | null>;
    boundaries: Boundary[];
    showBoundaries: boolean;
};
export default function PeopleMap({
    people,
    selected,
    onSelect,
    onContext,
    camera,
    boundaries,
    showBoundaries,
}: Props) {
    const root = useRef<HTMLDivElement>(null),
        map = useRef<L.Map | null>(null),
        group = useRef<L.LayerGroup | null>(null),
        geometry = useRef<L.LayerGroup | null>(null);
    const records = useRef(new Map<string, MarkerRecord>());
    const [revision, setRevision] = useState(0),
        [tileError, setTileError] = useState(false),
        [showLayers, setShowLayers] = useState(showBoundaries);
    const [mapMenu, setMapMenu] = useState<{ x: number; y: number } | null>(
        null,
    );
    const latest = useRef({ people, onSelect, onContext });
    useEffect(() => {
        latest.current = { people, onSelect, onContext };
    }, [people, onSelect, onContext]);
    const fit = () => {
        const points = latest.current.people.flatMap((p) =>
            p.position
                ? [[p.position.lat, p.position.lng] as L.LatLngTuple]
                : [],
        );
        if (points.length)
            map.current?.fitBounds(points, { padding: [50, 50], maxZoom: 16 });
    };
    useEffect(() => {
        if (!root.current) return;
        const activeRecords = records.current;
        const saved = camera.current;
        const instance = L.map(root.current, {
            scrollWheelZoom: false,
        }).setView(saved?.center ?? [-41.2865, 174.7762], saved?.zoom ?? 5);
        map.current = instance;
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors',
        })
            .on('tileerror', () => setTileError(true))
            .addTo(instance);
        group.current = L.layerGroup().addTo(instance);
        geometry.current = L.layerGroup().addTo(instance);
        instance.on('zoomend moveend', () => {
            const center = instance.getCenter();
            camera.current = {
                center: [center.lat, center.lng],
                zoom: instance.getZoom(),
            };
            setRevision((v) => v + 1);
        });
        instance.on('contextmenu', (event: L.LeafletMouseEvent) => {
            if (
                event.originalEvent.target instanceof Element &&
                event.originalEvent.target.closest('.leaflet-marker-icon')
            )
                return;
            L.DomEvent.preventDefault(event.originalEvent);
            setMapMenu({
                x: event.originalEvent.clientX,
                y: event.originalEvent.clientY,
            });
        });
        const resize = new ResizeObserver(() => instance.invalidateSize());
        resize.observe(root.current);
        if (!saved) fit();
        return () => {
            resize.disconnect();
            activeRecords.clear();
            instance.remove();
            map.current = null;
        };
    }, [camera]);
    useEffect(() => {
        const instance = map.current,
            layer = group.current;
        if (!instance || !layer) return;
        const clusters = new Map<string, Person[]>();
        people
            .filter((p) => p.position)
            .forEach((p) => {
                const pixel = instance.project([
                    p.position!.lat,
                    p.position!.lng,
                ]);
                const cell = `${Math.floor(pixel.x / 46)}:${Math.floor(pixel.y / 46)}`;
                clusters.set(cell, [...(clusters.get(cell) ?? []), p]);
            });
        const liveKeys = new Set<string>();
        clusters.forEach((members) => {
            const key = members
                .map((p) => p.id)
                .sort()
                .join('|');
            liveKeys.add(key);
            const person = members[0],
                point = person.position!;
            let record = records.current.get(key);
            if (!record) {
                const mark = document.createElement('span');
                const marker = L.marker([point.lat, point.lng], {
                    icon: L.divIcon({
                        html: mark,
                        className: 'pl-map-marker',
                        iconSize: [38, 38],
                    }),
                    keyboard: true,
                }).addTo(layer);
                record = { marker, members };
                records.current.set(key, record);
                const current = record;
                const choose = () => {
                    if (current.members.length === 1) {
                        latest.current.onSelect(current.members[0]);
                        return;
                    }
                    const list = document.createElement('div');
                    list.className = 'pl-map-chooser';
                    const title = document.createElement('strong');
                    title.textContent = 'Choose a person';
                    list.append(title);
                    current.members.forEach((p) => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.textContent = `${p.name} · ${p.site.name}`;
                        button.onclick = () => {
                            instance.closePopup();
                            latest.current.onSelect(p);
                        };
                        list.append(button);
                    });
                    marker.bindPopup(list).openPopup();
                };
                const menu = (x: number, y: number) =>
                    current.members.length > 1
                        ? choose()
                        : latest.current.onContext(x, y, current.members[0]);
                marker.on('click', choose);
                marker.on('contextmenu', (event: L.LeafletMouseEvent) => {
                    L.DomEvent.preventDefault(event.originalEvent);
                    menu(
                        event.originalEvent.clientX,
                        event.originalEvent.clientY,
                    );
                });
                const element = marker.getElement();
                element?.addEventListener('focus', () => marker.openTooltip());
                element?.addEventListener('blur', () => marker.closeTooltip());
                element?.addEventListener('keydown', (event) => {
                    if (
                        event.key === 'ContextMenu' ||
                        (event.key === 'F10' && event.shiftKey)
                    ) {
                        event.preventDefault();
                        const rect = element.getBoundingClientRect();
                        menu(rect.left, rect.bottom);
                    }
                });
            }
            record.members = members;
            const { marker } = record;
            marker.setLatLng([point.lat, point.lng]);
            const mark = marker.getElement()?.firstElementChild;
            if (mark) {
                mark.textContent =
                    members.length > 1
                        ? String(members.length)
                        : person.name
                              .split(/\s+/)
                              .map((s) => s[0])
                              .slice(0, 2)
                              .join('');
                mark.className = `pl-map-mark ${members.every((p) => p.positionState === 'stale') ? 'pl-map-stale' : ''} ${members.some((p) => p.id === selected) ? 'pl-map-selected' : ''}`;
            }
            const title =
                members.length > 1
                    ? `${members.length} nearby people · select to choose`
                    : person.name;
            const tooltip = document.createElement('div'),
                heading = document.createElement('strong');
            heading.textContent = title;
            tooltip.append(heading);
            const facts =
                members.length > 1
                    ? [
                          `${members.filter((p) => p.positionState === 'recent').length} recent · ${members.filter((p) => p.positionState === 'stale').length} stale`,
                      ]
                    : [
                          person.site.name,
                          labels[person.positionState],
                          `Position: ${time(point.timestamp)}`,
                          `Accuracy: ${point.accuracy == null ? 'Unknown' : `${point.accuracy} m`}`,
                          `Battery: ${person.battery == null ? 'Unknown' : `${person.battery}%`} · ${time(person.batteryAt)}`,
                          person.sources.map((s) => s.reference).join(' · '),
                      ];
            facts.forEach((text) => {
                const line = document.createElement('div');
                line.textContent = text;
                tooltip.append(line);
            });
            if (marker.getTooltip()) marker.setTooltipContent(tooltip);
            else
                marker.bindTooltip(tooltip, {
                    direction: 'top',
                    offset: [0, -14],
                });
            marker.getElement()?.setAttribute('aria-label', title);
            if (
                camera.current?.focusSelection &&
                members.some((p) => p.id === selected)
            ) {
                marker.getElement()?.focus();
                camera.current.focusSelection = false;
            }
        });
        records.current.forEach((record, key) => {
            if (!liveKeys.has(key)) {
                layer.removeLayer(record.marker);
                records.current.delete(key);
            }
        });
    }, [people, selected, revision, camera]);
    useEffect(() => {
        const layer = geometry.current;
        if (!layer) return;
        layer.clearLayers();
        if (!showLayers) return;
        boundaries.forEach((boundary) => {
            const shape = boundary.geometry;
            const item =
                shape.type === 'circle'
                    ? L.circle([shape.center.lat, shape.center.lng], {
                          radius: shape.radius_m,
                          color: 'var(--primary)',
                          dashArray: '6 6',
                          fillOpacity: 0.05,
                      })
                    : L.polygon(
                          shape.coordinates.map(
                              (p) => [p.lat, p.lng] as L.LatLngTuple,
                          ),
                          {
                              color: 'var(--primary)',
                              dashArray: '6 6',
                              fillOpacity: 0.05,
                          },
                      );
            const label = document.createElement('span');
            label.textContent = `${boundary.name} · revision ${boundary.revision} · shared geometry`;
            item.bindTooltip(label).addTo(layer);
        });
    }, [boundaries, showLayers]);
    return (
        <>
            <Card className="relative isolate z-0 gap-0 overflow-hidden bg-muted py-0">
                <div
                    ref={root}
                    className="pl-map h-[560px]"
                    aria-label="Permitted recorded person locations"
                />
                <div className="absolute top-3 right-3 z-[500] flex gap-2">
                    <Button
                        variant="secondary"
                        aria-pressed={showLayers}
                        onClick={() => setShowLayers((v) => !v)}
                    >
                        <Layers className="size-4" />
                        Boundaries · {boundaries.length}
                    </Button>
                    <Button variant="secondary" onClick={fit}>
                        <Maximize2 className="size-4" />
                        Fit people
                    </Button>
                </div>
                {tileError && (
                    <Card
                        role="status"
                        className="absolute right-3 bottom-10 left-3 z-[500] p-3 text-sm"
                    >
                        Map imagery could not load. Recorded markers remain
                        available; use People for all evidence.
                    </Card>
                )}
                <div className="space-y-1 border-t bg-background p-3 text-xs text-muted-foreground">
                    <p>
                        Solid marker: recent · Dashed marker: stale · Number:
                        nearby people · Missing positions are listed under
                        People.
                    </p>
                    <p>
                        Shared boundaries are reference geometry. They do not
                        activate monitoring or establish that a person is inside
                        an active safety zone.
                    </p>
                </div>
            </Card>
            {mapMenu && (
                <EntityContextMenu
                    {...mapMenu}
                    title="Map actions"
                    onClose={() => setMapMenu(null)}
                    items={[
                        { label: 'Fit permitted people', onClick: fit },
                        {
                            label: showLayers
                                ? 'Hide boundaries'
                                : 'Show boundaries',
                            onClick: () => setShowLayers((v) => !v),
                        },
                        {
                            label: 'Zoom in',
                            onClick: () => map.current?.zoomIn(),
                        },
                        {
                            label: 'Zoom out',
                            onClick: () => map.current?.zoomOut(),
                        },
                    ]}
                />
            )}
        </>
    );
}
