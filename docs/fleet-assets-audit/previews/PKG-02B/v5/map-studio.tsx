import { type MapGeofence } from '@/components/leaflet-map';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import L from 'leaflet';
import {
    ArrowUpRight,
    BatteryCharging,
    Car,
    Clock3,
    Gauge,
    Layers,
    LocateFixed,
    MapPin,
    Navigation,
    Plus,
    Radio,
    Route,
    ShieldAlert,
    ShieldCheck,
    Signal,
    Zap,
} from 'lucide-react';
import { useState } from 'react';
import { FenceWizard, type FenceAssignment } from './geofence-studio';
import LeafletMap from './interactive-map';
import { observations } from './observation-history';
import { type VehicleModel } from './operations';
import { Badge, Modal } from './ui';
// This static preview changes map panes frequently. Disable zoom transitions so
// Leaflet cannot finish a queued transition after its owning tab unmounts.
L.Map.mergeOptions({
    zoomAnimation: false,
    fadeAnimation: false,
    markerZoomAnimation: false,
});
const home = { lat: -41.2838, lng: 174.7743 };
const endpoint = { lat: -41.2865, lng: 174.7762 };
const trail = [
    home,
    { lat: -41.2842, lng: 174.7757 },
    { lat: -41.285, lng: 174.7755 },
    { lat: -41.2857, lng: 174.7759 },
    endpoint,
];
export type SharedFence = MapGeofence & {
    scope: string;
    revision?: string;
    assignment?: FenceAssignment;
};
export const seedFences: SharedFence[] = [
    {
        id: 'GEO-DEMO-01',
        name: 'Kōwhai House grounds',
        type: 'circle',
        center: home,
        radius_m: 160,
        color: 'var(--primary)',
        scope: 'Site · Kōwhai House',
    },
    {
        id: 'GEO-DEMO-02',
        name: 'Community pickup area',
        type: 'circle',
        center: endpoint,
        radius_m: 100,
        color: 'var(--status-info)',
        scope: 'Shared · vehicles & clients',
    },
];
export function MapStudio({
    model: m,
    dark,
    noTracker,
    unavailable,
    onTrips,
    onNav,
    fences,
    setFences,
    selectedFences,
    setSelectedFences,
}: {
    model: VehicleModel;
    dark: boolean;
    noTracker: boolean;
    unavailable: boolean;
    onTrips: () => void;
    onNav: (view: string, sub?: string) => void;
    fences: SharedFence[];
    setFences: (f: SharedFence[]) => void;
    selectedFences: (string | number)[];
    setSelectedFences: (ids: (string | number)[]) => void;
}) {
    const [context, setContext] = useState<{
        lat: number;
        lng: number;
        x?: number;
        y?: number;
        markerId?: string | number;
    } | null>(null);
    const [draftPoint, setDraftPoint] = useState<
        { lat: number; lng: number } | undefined
    >();
    const [expanded, setExpanded] = useState(false);
    const [observation, setObservation] = useState('live'),
        [showTrail, setShowTrail] = useState(false),
        [zones, setZones] = useState(true),
        [center, setCenter] = useState(endpoint),
        [mapKey, setMapKey] = useState(0),
        [create, setCreate] = useState(false),
        [editingFence, setEditingFence] = useState<SharedFence | undefined>(),
        [fenceQuery, setFenceQuery] = useState(''),
        [select, setSelect] = useState(false),
        [chosen, setChosen] = useState<(string | number)[]>(selectedFences);
    const reports = [
        {
            ...observations[0],
            id: 'live',
            date: m.tracker.observed,
            ref: 'GV500CG-DEMO-14 · SAMPLE-' + m.data.tracker.sequence,
        },
        ...observations,
    ];
    const o = reports.find((x) => x.id === observation)!;
    const current = observation === 'live';
    const motion =
        noTracker || (current && !m.tracker.fresh)
            ? 'Unknown'
            : current && m.data.tracker.sample === 'Moving'
              ? 'Moving'
              : 'Stationary';
    const stateLabel = noTracker
        ? 'No tracker'
        : current
          ? m.tracker.fresh
              ? 'Current sample · DEMO'
              : 'Last known · stale'
          : 'Historical position';
    const ignition =
        noTracker || !current || !m.tracker.fresh
            ? 'Unknown'
            : motion === 'Moving'
              ? 'On'
              : 'Off';
    const speed =
        noTracker || !current || !m.tracker.fresh
            ? '—'
            : motion === 'Moving'
              ? '42 km/h'
              : '0 km/h';
    const voltage =
        noTracker || !current || !m.tracker.fresh
            ? '—'
            : motion === 'Moving'
              ? '14.2 V'
              : '12.6 V';
    const locate = () => {
        setCenter(noTracker ? home : { lat: o.lat, lng: o.lng });
        setMapKey((k) => k + 1);
    };
    return (
        <>
            <div className={`map-studio ${expanded ? 'map-expanded' : ''}`}>
                <section className="map-stage studio-card">
                    <div className="studio-section-heading">
                        <div>
                            <span className="studio-eyebrow">
                                LOCATION · VH-014
                            </span>
                            <h2 className="text-section-title">
                                {noTracker
                                    ? 'Home site context'
                                    : 'Vehicle location'}
                            </h2>
                        </div>
                        <Badge tone={noTracker ? 'neutral' : 'warning'}>
                            {stateLabel}
                        </Badge>
                    </div>
                    <div className="map-tools">
                        <Button size="sm" variant="outline" onClick={locate}>
                            <LocateFixed size={15} />
                            Recenter
                        </Button>
                        <Button
                            size="sm"
                            variant={showTrail ? 'secondary' : 'ghost'}
                            disabled={noTracker}
                            aria-pressed={showTrail}
                            onClick={() => setShowTrail(!showTrail)}
                        >
                            <Route size={15} />
                            Historical trail
                        </Button>
                        <Button
                            size="sm"
                            variant={zones ? 'secondary' : 'ghost'}
                            aria-pressed={zones}
                            onClick={() => setZones(!zones)}
                        >
                            <Layers size={15} />
                            Geofences
                        </Button>
                        <span className="muted">
                            Right-click for location actions
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={(e) => {
                                const r =
                                    e.currentTarget.getBoundingClientRect();
                                setContext({
                                    ...center,
                                    x: r.left,
                                    y: r.bottom,
                                });
                            }}
                        >
                            Location actions
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setExpanded(!expanded)}
                        >
                            {expanded ? 'Exit expanded map' : 'Expand map'}
                        </Button>
                    </div>
                    <div className="studio-map-canvas">
                        {unavailable ? (
                            <div className="studio-empty">
                                <MapPin size={35} />
                                <h3>Map imagery unavailable</h3>
                                <p>
                                    Recorded coordinates and source times remain
                                    available.
                                </p>
                            </div>
                        ) : (
                            <LeafletMap
                                key={mapKey}
                                onContext={setContext}
                                center={center}
                                zoom={16}
                                height="100%"
                                darkMode={dark}
                                markers={[
                                    {
                                        id: 'home',
                                        ...home,
                                        title: 'Kōwhai House',
                                        type: 'house',
                                        color: 'var(--category-sites)',
                                        popup: 'Kōwhai House · synthetic home site',
                                    },
                                    ...(!noTracker
                                        ? [
                                              {
                                                  id: 'vehicle',
                                                  lat: o.lat,
                                                  lng: o.lng,
                                                  title: 'KWH014',
                                                  stats: [
                                                      ['Ignition', ignition],
                                                      ['Speed', speed],
                                                      ['Motion', motion],
                                                      ['Voltage', voltage],
                                                  ] as [string, string][],
                                                  type: 'vehicle' as const,
                                                  status:
                                                      current &&
                                                      motion === 'Moving'
                                                          ? 'moving'
                                                          : 'historical',
                                                  speed:
                                                      current &&
                                                      motion === 'Moving'
                                                          ? 42
                                                          : undefined,
                                                  color: 'var(--primary)',
                                                  popup:
                                                      o.date +
                                                      ' · ' +
                                                      stateLabel,
                                              },
                                          ]
                                        : []),
                                ]}
                                polyline={
                                    showTrail && !noTracker
                                        ? observation !== 'previous'
                                            ? trail
                                            : [...trail].reverse()
                                        : undefined
                                }
                                polylineOptions={{
                                    color: 'var(--primary)',
                                    showArrows: true,
                                    showEndpoints: false,
                                }}
                                geofences={
                                    zones
                                        ? fences.filter((f) =>
                                              selectedFences.includes(f.id),
                                          )
                                        : []
                                }
                                onMarkerClick={(id) =>
                                    id === 'vehicle' &&
                                    m.detail('Recorded vehicle position', [
                                        ['Source', o.ref],
                                        ['Observed', o.date],
                                        ['Position', o.lat + ', ' + o.lng],
                                        ['Motion', motion],
                                        [
                                            'Current location',
                                            current && m.tracker.fresh
                                                ? 'Synthetic current sample'
                                                : 'Unknown; this is a last-known report',
                                        ],
                                    ])
                                }
                            />
                        )}
                    </div>
                    <div className="map-bottom">
                        <span>
                            <Radio size={14} />
                            {noTracker
                                ? 'No vehicle location reported'
                                : o.date + ' · ' + o.ref}
                        </span>
                        <Button size="sm" variant="ghost" onClick={onTrips}>
                            Trip history <ArrowUpRight size={14} />
                        </Button>
                    </div>
                </section>
                <aside className="map-inspector studio-card">
                    <div className="studio-section-heading">
                        <div>
                            <span className="studio-eyebrow">
                                REPORTED STATE
                            </span>
                            <h2 className="text-section-title">KWH014</h2>
                        </div>
                        <Car size={25} />
                    </div>
                    <div className="motion-indicators">
                        <Popover>
                            <PopoverTrigger asChild>
                                <button
                                    className="motion-tile"
                                    aria-label={`Motion: ${motion}. Show report details`}
                                >
                                    <span className="motion-icon">
                                        <Navigation size={26} />
                                    </span>
                                    <span>
                                        <small>Motion</small>
                                        <strong>{motion}</strong>
                                    </span>
                                    <ArrowUpRight size={14} />
                                </button>
                            </PopoverTrigger>
                            <PopoverContent align="end">
                                <strong>Motion at the last report</strong>
                                <p className="text-caption mt-2">
                                    {noTracker
                                        ? 'No tracker is assigned.'
                                        : `${motion} · ${o.date}. ${current ? 'Synthetic GV500CG sample; virtual ignition and motion are derived states.' : 'This is a historical observation.'}`}
                                </p>
                            </PopoverContent>
                        </Popover>
                        <div className="tracker-small">
                            <Radio size={17} />
                            <div>
                                <small>Tracker</small>
                                <strong>
                                    {noTracker ? 'Not assigned' : stateLabel}
                                </strong>
                            </div>
                            <Badge tone="neutral">
                                {noTracker ? 'None' : 'DEMO'}
                            </Badge>
                        </div>
                    </div>
                    <div className="reported-stats">
                        {[
                            [Zap, 'Ignition', ignition],
                            [Gauge, 'Speed', speed],
                            [BatteryCharging, 'Vehicle', voltage],
                            [
                                Signal,
                                'Signal',
                                noTracker
                                    ? 'None'
                                    : current
                                      ? m.tracker.fresh
                                          ? 'LTE'
                                          : 'Stale'
                                      : 'Historic',
                            ],
                        ].map(([Icon, label, value]) => {
                            const I = Icon as typeof Zap;
                            return (
                                <div key={String(label)}>
                                    <I size={18} />
                                    <small>{String(label)}</small>
                                    <strong>{String(value)}</strong>
                                </div>
                            );
                        })}
                    </div>
                    <div className="reported-health">
                        <span
                            className={m.hold ? 'health-critical' : 'health-ok'}
                        >
                            <ShieldCheck size={16} />
                            {m.hold ? 'Use restricted' : 'Check readiness'}
                        </span>
                        <button onClick={() => onNav('map', 'alerts')}>
                            <ShieldAlert size={16} />
                            {
                                m.data.alerts.filter(
                                    (a) => a.status !== 'Resolved',
                                ).length
                            }{' '}
                            open alerts <ArrowUpRight size={13} />
                        </button>
                    </div>
                    <p className="reported-source">
                        {current
                            ? 'Virtual ignition · sample-based state'
                            : 'Historical observation · live state unknown'}
                    </p>
                    {!noTracker && (
                        <>
                            <label className="map-observation-select">
                                Observation
                                <select
                                    aria-label="Select recorded observation"
                                    value={observation}
                                    onChange={(e) => {
                                        const next = reports.find(
                                            (x) => x.id === e.target.value,
                                        )!;
                                        setObservation(next.id);
                                        setCenter({
                                            lat: next.lat,
                                            lng: next.lng,
                                        });
                                    }}
                                >
                                    {reports.map((x) => (
                                        <option value={x.id} key={x.id}>
                                            {x.date}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <div className="map-coordinate">
                                <MapPin size={16} />
                                <div>
                                    <strong>
                                        {o.lat.toFixed(5)}, {o.lng.toFixed(5)}
                                    </strong>
                                    <small>Recorded coordinates · NZST</small>
                                </div>
                            </div>
                        </>
                    )}
                    <div className="map-zone-heading">
                        <h3>Linked geofences</h3>
                        <Badge>{selectedFences.length}</Badge>
                    </div>
                    <div className="map-zones">
                        {fences
                            .filter((f) => selectedFences.includes(f.id))
                            .map((f) => (
                                <div key={f.id}>
                                    <ShieldCheck size={18} />
                                    <span>
                                        <strong>{f.name}</strong>
                                        <small>
                                            {f.assignment
                                                ? `${f.assignment.start}–${f.assignment.end} · Inactive`
                                                : f.scope + ' · Inactive'}
                                        </small>
                                    </span>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={!m.canManage}
                                        onClick={() => {
                                            setDraftPoint(undefined);
                                            setEditingFence(f);
                                            setCreate(true);
                                        }}
                                    >
                                        Manage
                                    </Button>
                                </div>
                            ))}
                        {!selectedFences.length && (
                            <p className="muted">No geofences selected.</p>
                        )}
                    </div>
                    <div className="map-zone-actions">
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!m.canManage}
                            onClick={() => {
                                setChosen(selectedFences);
                                setSelect(true);
                            }}
                        >
                            Select existing
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!m.canManage}
                            onClick={() => {
                                setDraftPoint(undefined);
                                setEditingFence(undefined);
                                setCreate(true);
                            }}
                        >
                            <Plus size={14} />
                            Create
                        </Button>
                    </div>
                    <p className="studio-footnote">
                        One shared geofence record can be selected from vehicle,
                        client, site or house maps. This preview shows the
                        proposed interaction.
                    </p>
                    <Button
                        variant="ghost"
                        className="w-full"
                        onClick={() =>
                            m.detail('Location & readiness', [
                                ['Position', 'Historical tracker observation'],
                                ['Custody', 'Booking checkout / return'],
                                [
                                    'Readiness',
                                    'Checks, compliance and authorised release',
                                ],
                                [
                                    'Automatic alerts',
                                    'Not enabled in this preview',
                                ],
                            ])
                        }
                    >
                        Location & readiness <ArrowUpRight size={14} />
                    </Button>
                </aside>
            </div>
            <DropdownMenu
                open={!!context}
                modal={false}
                onOpenChange={(v) => {
                    if (!v) setContext(null);
                }}
            >
                <DropdownMenuTrigger asChild>
                    <button
                        aria-label="Map context anchor"
                        tabIndex={-1}
                        className="map-context-anchor"
                        style={{ left: context?.x ?? 0, top: context?.y ?? 0 }}
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    sideOffset={2}
                    className="map-context-menu"
                >
                    <DropdownMenuLabel>
                        {context?.markerId
                            ? 'KWH014 · vehicle actions'
                            : 'Map location'}
                        <small>
                            {context?.lat.toFixed(5)}, {context?.lng.toFixed(5)}
                        </small>
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    {context?.markerId && (
                        <>
                            <DropdownMenuItem
                                onSelect={() => onNav('map', 'telemetry')}
                            >
                                <Gauge />
                                Vehicle telemetry
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={onTrips}>
                                <Route />
                                Trip history & insights
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                onSelect={() => onNav('map', 'alerts')}
                            >
                                <ShieldAlert />
                                Faults & Control Room alerts
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                disabled={!m.canManage}
                                onSelect={() => m.followup()}
                            >
                                <Clock3 />
                                Add vehicle reminder
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                        </>
                    )}
                    <DropdownMenuItem
                        onSelect={() => {
                            if (context) {
                                setCenter(context);
                                setMapKey((k) => k + 1);
                            }
                        }}
                    >
                        <LocateFixed />
                        Centre map here
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={!m.canManage}
                        onSelect={() => {
                            if (context) {
                                setDraftPoint(context);
                                setEditingFence(undefined);
                                setCreate(true);
                            }
                        }}
                    >
                        <Plus />
                        Create geofence here
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={!m.canManage}
                        onSelect={() => {
                            setChosen(selectedFences);
                            setSelect(true);
                        }}
                    >
                        <Layers />
                        Select existing geofence
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            {select && (
                <Modal
                    title="Select shared geofences"
                    description="Link existing boundaries to Kōwhai van"
                    icon={Layers}
                    size="standard"
                    onClose={() => setSelect(false)}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setSelect(false)}
                            >
                                Cancel
                            </Button>
                            <Button
                                onClick={() => {
                                    setSelectedFences(chosen);
                                    setSelect(false);
                                }}
                            >
                                Save selection
                            </Button>
                        </>
                    }
                >
                    <Input
                        aria-label="Search shared geofences"
                        placeholder="Search name, site or reference…"
                        value={fenceQuery}
                        onChange={(e) => setFenceQuery(e.target.value)}
                    />
                    <div className="geofence-select-list">
                        {fences
                            .filter((f) =>
                                `${f.name} ${f.scope} ${f.id}`
                                    .toLowerCase()
                                    .includes(fenceQuery.toLowerCase()),
                            )
                            .map((f) => (
                                <label key={f.id}>
                                    <input
                                        type="checkbox"
                                        checked={chosen.includes(f.id)}
                                        onChange={(e) =>
                                            setChosen(
                                                e.target.checked
                                                    ? [...chosen, f.id]
                                                    : chosen.filter(
                                                          (id) => id !== f.id,
                                                      ),
                                            )
                                        }
                                    />
                                    <span>
                                        <strong>{f.name}</strong>
                                        <small>
                                            {f.scope} · {f.id}
                                        </small>
                                    </span>
                                    <Badge>{f.type}</Badge>
                                </label>
                            ))}
                    </div>
                </Modal>
            )}
            {create && (
                <FenceWizard
                    fences={fences}
                    initial={editingFence}
                    draftPoint={draftPoint}
                    onClose={() => setCreate(false)}
                    onSave={(f) => {
                        setFences([...fences.filter((x) => x.id !== f.id), f]);
                        setSelectedFences([
                            ...new Set([...selectedFences, f.id]),
                        ]);
                    }}
                />
            )}
        </>
    );
}
const trips = [
    {
        id: 'TRIP-DEMO-12',
        day: '2026-09-21',
        start: '8:00 am',
        end: '8:12 am',
        minutes: 12,
        distance: 2.4,
        from: 'Kōwhai House',
        to: 'Community pickup area',
        driver: 'Jamie Taylor',
        source: 'OBS-DEMO-03',
        odo: '82,458 → 82,460 km',
        path: trail,
    },
    {
        id: 'TRIP-DEMO-11',
        day: '2026-09-20',
        start: '4:40 pm',
        end: '5:08 pm',
        minutes: 28,
        distance: 8.1,
        from: 'Community transport route',
        to: 'Kōwhai House',
        driver: 'Alex Morgan',
        source: 'OBS-DEMO-02',
        odo: '82,450 → 82,458 km',
        path: [...trail].reverse(),
    },
];
export function TripStudio({
    model: m,
    dark,
    noTracker,
}: {
    model: VehicleModel;
    dark: boolean;
    noTracker: boolean;
}) {
    const [day, setDay] = useState('all'),
        [selected, setSelected] = useState(trips[0].id),
        [query, setQuery] = useState('');
    const visible = noTracker
        ? []
        : trips.filter(
              (t) =>
                  (day === 'all' || t.day === day) &&
                  [t.id, t.driver, t.from, t.to]
                      .join(' ')
                      .toLowerCase()
                      .includes(query.toLowerCase()),
          );
    const trip = visible.find((t) => t.id === selected) ?? visible[0];
    return (
        <section className="trip-studio studio-card">
            <div className="studio-section-heading">
                <div>
                    <span className="studio-eyebrow">
                        DEDICATED VEHICLE HISTORY · VH-014
                    </span>
                    <h2 className="text-section-title">Trip history</h2>
                </div>
                <div className="studio-inline">
                    <Input
                        aria-label="Search trips"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Driver, journey or reference…"
                    />
                    <select
                        aria-label="Trip date filter"
                        value={day}
                        onChange={(e) => setDay(e.target.value)}
                    >
                        <option value="all">All recorded dates</option>
                        <option value="2026-09-21">21 Sep 2026</option>
                        <option value="2026-09-20">20 Sep 2026</option>
                    </select>
                </div>
            </div>
            <div className="trip-summary">
                <span>
                    <Route size={17} />
                    <strong>{visible.length}</strong> recorded trips
                </span>
                <span>
                    <Navigation size={17} />
                    <strong>
                        {visible
                            .reduce((sum, t) => sum + t.distance, 0)
                            .toFixed(1)}{' '}
                        km
                    </strong>{' '}
                    GPS estimate
                </span>
                <span>
                    <Clock3 size={17} />
                    <strong>
                        {visible.reduce((sum, t) => sum + t.minutes, 0)} min
                    </strong>{' '}
                    recorded travel
                </span>
                <Badge tone="neutral">Historical · synthetic</Badge>
            </div>
            {trip ? (
                <div className="trip-workspace">
                    <div className="trip-list">
                        {visible.map((t) => (
                            <button
                                key={t.id}
                                className={t.id === trip.id ? 'selected' : ''}
                                onClick={() => setSelected(t.id)}
                            >
                                <div>
                                    <strong>
                                        {new Date(
                                            t.day + 'T12:00',
                                        ).toLocaleDateString('en-NZ', {
                                            day: 'numeric',
                                            month: 'short',
                                        })}
                                    </strong>
                                    <Badge tone="info">{t.distance} km</Badge>
                                </div>
                                <p>
                                    {t.from} <span>→</span> {t.to}
                                </p>
                                <small>
                                    {t.start} – {t.end} · {t.driver}
                                </small>
                                <small>{t.id}</small>
                            </button>
                        ))}
                    </div>
                    <div className="trip-detail">
                        <div className="trip-map">
                            <LeafletMap
                                key={trip.id}
                                center={trip.path[2]}
                                height="100%"
                                zoom={16}
                                darkMode={dark}
                                polyline={trip.path}
                                polylineOptions={{
                                    color: 'var(--primary)',
                                    showArrows: true,
                                    showEndpoints: true,
                                }}
                            />
                        </div>
                        <div className="trip-stops">
                            <div>
                                <span className="stop-dot" />
                                <div>
                                    <strong>{trip.from}</strong>
                                    <small>Departure · {trip.start}</small>
                                </div>
                            </div>
                            <div>
                                <span className="stop-dot end" />
                                <div>
                                    <strong>{trip.to}</strong>
                                    <small>Arrival · {trip.end}</small>
                                </div>
                            </div>
                        </div>
                        <div className="trip-record-footer">
                            <div>
                                <strong>{trip.driver}</strong>
                                <small>{trip.odo} · recorded odometer</small>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    m.detail('Trip ' + trip.id, [
                                        ['Vehicle', 'Kōwhai van · VH-014'],
                                        ['Source', trip.source],
                                        [
                                            'Travel',
                                            trip.day +
                                                ' · ' +
                                                trip.start +
                                                ' – ' +
                                                trip.end +
                                                ' · Pacific/Auckland',
                                        ],
                                        ['Driver', trip.driver],
                                        [
                                            'GPS estimate',
                                            trip.distance +
                                                ' km · illustrative sparse observation trail',
                                        ],
                                        ['Recorded odometer', trip.odo],
                                        [
                                            'Booking link',
                                            'Not recorded in source',
                                        ],
                                        [
                                            'Privacy',
                                            'Permitted vehicle record; passenger details omitted',
                                        ],
                                        [
                                            'Custody',
                                            'Refer to the original booking checkout and return',
                                        ],
                                    ])
                                }
                            >
                                Source record <ArrowUpRight size={14} />
                            </Button>
                        </div>
                    </div>
                </div>
            ) : (
                <div className="studio-empty">
                    <Route size={38} />
                    <h3>
                        {noTracker
                            ? 'No tracker trip history'
                            : 'No matching trips'}
                    </h3>
                    <p>
                        {noTracker
                            ? 'Manual mileage and booking custody records remain available.'
                            : 'Try a different date or search.'}
                    </p>
                </div>
            )}
            <p className="studio-footnote">
                GPS distance is an estimate. Odometer readings retain their own
                evidence. The illustrative trail does not establish a continuous
                route or current location.
            </p>
        </section>
    );
}
