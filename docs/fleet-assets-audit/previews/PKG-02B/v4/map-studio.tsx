import GeofenceDrawMap, {
    type GeofenceShape,
} from '@/components/geofence-draw-map';
import LeafletMap, { type MapGeofence } from '@/components/leaflet-map';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import L from 'leaflet';
import {
    ArrowUpRight,
    Car,
    Clock3,
    FileCheck2,
    Home,
    Layers,
    LocateFixed,
    MapPin,
    Navigation,
    Plus,
    Radio,
    Route,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import { observations } from './observation-history';
import { type VehicleModel } from './operations';
import { Badge, Modal, Notice } from './ui';
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
export type SharedFence = MapGeofence & { scope: string };
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
    fences: SharedFence[];
    setFences: (f: SharedFence[]) => void;
    selectedFences: (string | number)[];
    setSelectedFences: (ids: (string | number)[]) => void;
}) {
    const [observation, setObservation] = useState('latest'),
        [showTrail, setShowTrail] = useState(true),
        [zones, setZones] = useState(true),
        [center, setCenter] = useState(endpoint),
        [mapKey, setMapKey] = useState(0),
        [create, setCreate] = useState(false),
        [select, setSelect] = useState(false),
        [chosen, setChosen] = useState<(string | number)[]>(selectedFences);
    const o = observations.find((x) => x.id === observation)!;
    const locate = () => {
        setCenter(noTracker ? home : { lat: o.lat, lng: o.lng });
        setMapKey((k) => k + 1);
    };
    return (
        <>
            <div className="map-studio">
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
                            {noTracker ? 'No tracker' : 'Historical position'}
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
                            Trail
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
                            Pan · zoom · select a marker
                        </span>
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
                                                  type: 'vehicle' as const,
                                                  status: 'historical',
                                                  color: 'var(--primary)',
                                                  popup:
                                                      o.date +
                                                      ' · historical position',
                                              },
                                          ]
                                        : []),
                                ]}
                                polyline={
                                    showTrail && !noTracker
                                        ? observation === 'latest'
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
                                        [
                                            'Motion',
                                            'Stationary at this observation',
                                        ],
                                        [
                                            'Current location',
                                            'Unknown; this is a historical report',
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
                                    aria-label={`Motion: ${noTracker ? 'Unknown' : 'Stationary'}. Show report details`}
                                >
                                    <span className="motion-icon">
                                        <Navigation size={26} />
                                    </span>
                                    <span>
                                        <small>Motion</small>
                                        <strong>
                                            {noTracker
                                                ? 'Unknown'
                                                : 'Stationary'}
                                        </strong>
                                    </span>
                                    <ArrowUpRight size={14} />
                                </button>
                            </PopoverTrigger>
                            <PopoverContent align="end">
                                <strong>Motion at the last report</strong>
                                <p className="text-caption mt-2">
                                    {noTracker
                                        ? 'No tracker is assigned.'
                                        : `Stationary · ${o.date}. This describes the recorded observation, not continuous or current movement.`}
                                </p>
                            </PopoverContent>
                        </Popover>
                        <div className="tracker-small">
                            <Radio size={17} />
                            <div>
                                <small>Tracker</small>
                                <strong>
                                    {noTracker
                                        ? 'Not assigned'
                                        : 'Historical report'}
                                </strong>
                            </div>
                            <Badge tone="neutral">
                                {noTracker ? 'None' : 'DEMO'}
                            </Badge>
                        </div>
                    </div>
                    {!noTracker && (
                        <>
                            <label className="map-observation-select">
                                Observation
                                <select
                                    aria-label="Select recorded observation"
                                    value={observation}
                                    onChange={(e) => {
                                        const next = observations.find(
                                            (x) => x.id === e.target.value,
                                        )!;
                                        setObservation(next.id);
                                        setCenter({
                                            lat: next.lat,
                                            lng: next.lng,
                                        });
                                    }}
                                >
                                    {observations.map((x) => (
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
                                        <small>{f.scope}</small>
                                    </span>
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
                            onClick={() => setCreate(true)}
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
                    <div className="geofence-select-list">
                        {fences.map((f) => (
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
                    onClose={() => setCreate(false)}
                    onSave={(f) => {
                        setFences([...fences, f]);
                        setSelectedFences([...selectedFences, f.id]);
                    }}
                />
            )}
        </>
    );
}
function FenceWizard({
    onSave,
    onClose,
}: {
    onSave: (f: SharedFence) => void;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0),
        [name, setName] = useState(''),
        [shape, setShape] = useState<GeofenceShape | null>(null),
        [error, setError] = useState(''),
        [done, setDone] = useState(false),
        [discard, setDiscard] = useState(false);
    const close = () =>
        done || (!name && !shape) ? onClose() : setDiscard(true);
    const validate = () =>
        !name.trim()
            ? 'Name this geofence.'
            : !shape
              ? 'Draw a boundary on the map.'
              : '';
    return (
        <>
            <WizardShell
                open
                title="Create shared geofence"
                description="Create once · select from related profiles"
                railIcon={Layers}
                railTitle="Shared geofence"
                railSub="Link to Kōwhai van"
                maxWidth="min(92vw, 1100px)"
                steps={[
                    {
                        key: 'details',
                        label: 'Details',
                        blurb: 'Name and ownership',
                        icon: MapPin,
                    },
                    {
                        key: 'boundary',
                        label: 'Boundary',
                        blurb: 'Draw the area',
                        icon: Layers,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Create and link',
                        icon: FileCheck2,
                    },
                ]}
                stepIndex={step}
                onStepClick={setStep}
                pct={(Number(!!name) + Number(!!shape)) * 50}
                onClose={close}
                footerStart={
                    <Button variant="outline" onClick={close}>
                        Cancel
                    </Button>
                }
                footerEnd={
                    <>
                        <Button
                            variant="outline"
                            disabled={step === 0}
                            onClick={() => setStep(step - 1)}
                        >
                            Back
                        </Button>
                        <Button
                            onClick={() => {
                                if (step === 0 && !name.trim()) {
                                    setError('Name this geofence.');
                                    return;
                                }
                                if (step === 1 && !shape) {
                                    setError('Draw a boundary on the map.');
                                    return;
                                }
                                if (step < 2) {
                                    setError('');
                                    setStep(step + 1);
                                    return;
                                }
                                const e = validate();
                                if (e) {
                                    setError(e);
                                    return;
                                }
                                onSave({
                                    id:
                                        'GEO-DEMO-' +
                                        crypto.randomUUID().slice(0, 6),
                                    name,
                                    scope: 'Shared · Kōwhai House',
                                    type:
                                        shape!.type === 'circle'
                                            ? 'circle'
                                            : 'polygon',
                                    center: shape!.center,
                                    radius_m: shape!.radius_m,
                                    coordinates: shape!.coordinates,
                                    color: 'var(--primary)',
                                });
                                setDone(true);
                            }}
                        >
                            {step === 2 ? 'Create & link geofence' : 'Continue'}
                        </Button>
                    </>
                }
                success={
                    done ? (
                        <WizardSuccessPane
                            title="Geofence created and linked"
                            blurb="The shared boundary is now selected on this vehicle map. No monitoring or alerts have been activated."
                            actions={
                                <Button onClick={onClose}>Back to map</Button>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    {error && <Notice title={error} tone="critical" />}
                    {step === 0 ? (
                        <div className="flow-stack">
                            <div className="field">
                                <label htmlFor="fence-name">
                                    Geofence name
                                </label>
                                <Input
                                    id="fence-name"
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    placeholder="e.g. Community pickup area"
                                />
                            </div>
                            <ReviewCard icon={Home} title="Scope & ownership">
                                <ReviewRow
                                    label="Owner site"
                                    value="Kōwhai House"
                                />
                                <ReviewRow
                                    label="Available to"
                                    value="Permitted site, house, client and vehicle profiles"
                                />
                                <ReviewRow
                                    label="Selected here"
                                    value="Kōwhai van · VH-014"
                                />
                            </ReviewCard>
                            <Notice title="Shared boundary">
                                Creating a geofence defines an area. Monitoring
                                subscriptions and permissions are configured
                                separately.
                            </Notice>
                        </div>
                    ) : step === 1 ? (
                        <GeofenceDrawMap
                            center={home}
                            zoom={16}
                            height={360}
                            initialShape={shape}
                            onShapeChange={setShape}
                        />
                    ) : (
                        <ReviewCard icon={Layers} title="Shared geofence">
                            <ReviewRow label="Name" value={name} />
                            <ReviewRow
                                label="Boundary"
                                value={shape?.type ?? 'Missing'}
                            />
                            <ReviewRow
                                label="Radius / points"
                                value={
                                    shape?.type === 'circle'
                                        ? shape.radius_m + ' m'
                                        : String(
                                              shape?.coordinates?.length ?? 0,
                                          ) + ' points'
                                }
                            />
                            <ReviewRow
                                label="Linked profile"
                                value="Kōwhai van · VH-014"
                            />
                        </ReviewCard>
                    )}
                </WizardStepPane>
            </WizardShell>
            {discard && (
                <Modal
                    title="Discard geofence draft?"
                    description="Unsaved shared geofence"
                    onClose={() => setDiscard(false)}
                    footer={
                        <>
                            <Button
                                variant="outline"
                                onClick={() => setDiscard(false)}
                            >
                                Keep editing
                            </Button>
                            <Button variant="destructive" onClick={onClose}>
                                Discard draft
                            </Button>
                        </>
                    }
                >
                    <p>The name and drawn boundary have not been saved.</p>
                </Modal>
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
