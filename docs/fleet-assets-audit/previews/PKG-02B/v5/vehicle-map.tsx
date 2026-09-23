import LeafletMap, { type MapMarker } from '@/components/leaflet-map';
import { Button } from '@/components/ui/button';
import {
    Home,
    Info,
    LocateFixed,
    MapPin,
    Route,
    Satellite,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { observations } from './observation-history';
import { Badge, Modal, Notice, Panel, Row } from './ui';

const point = { lat: -41.2865, lng: 174.7762 };
const site = { lat: -41.2838, lng: 174.7743 };
const observedPath = [
    { lat: -41.2842, lng: 174.7757 },
    { lat: -41.285, lng: 174.7755 },
    { lat: -41.2857, lng: 174.7759 },
    point,
];

export function VehicleMap({
    noTracker,
    dark,
    unavailable,
    onMileage,
    observation = 'latest',
}: {
    noTracker: boolean;
    dark: boolean;
    unavailable: boolean;
    onMileage: () => void;
    observation?: string;
}) {
    const selected =
        observations.find((o) => o.id === observation) ?? observations[0];
    const point = { lat: selected.lat, lng: selected.lng };
    const [center, setCenter] = useState(point),
        [trail, setTrail] = useState(false),
        [showSite, setShowSite] = useState(true),
        [detail, setDetail] = useState(false),
        [mapKey, setMapKey] = useState(0);
    useEffect(() => {
        setCenter(point);
        setMapKey((k) => k + 1);
    }, [observation]);
    const markers: MapMarker[] = [
        ...(noTracker
            ? []
            : [
                  {
                      id: 'vehicle',
                      ...point,
                      title: 'VH-014',
                      type: 'vehicle' as const,
                      status: 'historical',
                      color: 'var(--primary)',
                      popup: `${selected.date} · Synthetic recorded position. Not a live fix.`,
                  },
              ]),
        ...(showSite
            ? [
                  {
                      id: 'site',
                      ...site,
                      title: 'Kōwhai House · demo',
                      type: 'house' as const,
                      color: 'var(--category-sites)',
                      popup: 'Synthetic site marker for design review.',
                  },
              ]
            : []),
    ];
    return (
        <div className="vehicle-map-layout">
            <section className="vehicle-map-card">
                <div className="map-title">
                    <div>
                        <span className="eyebrow">VEHICLE LOCATION</span>
                        <h2 className="text-section-title">
                            {noTracker
                                ? 'Site context'
                                : 'Recorded vehicle position'}
                        </h2>
                        <p>
                            {noTracker
                                ? 'No tracker assigned · no vehicle position is shown'
                                : `${selected.date} · recorded observation`}
                        </p>
                    </div>
                    <Badge tone={noTracker ? 'neutral' : 'info'}>
                        {noTracker ? 'No tracker' : 'Historical observation'}
                    </Badge>
                </div>
                <div className="map-toolbar">
                    <Button
                        variant="outline"
                        onClick={() => {
                            setCenter({ ...(!noTracker ? point : site) });
                            setMapKey((key) => key + 1);
                        }}
                    >
                        <LocateFixed size={16} />
                        Reset map view
                    </Button>
                    <Button
                        variant="outline"
                        aria-pressed={showSite}
                        onClick={() => setShowSite((value) => !value)}
                    >
                        <Home size={16} />
                        {showSite ? 'Hide site' : 'Show site'}
                    </Button>
                    {!noTracker && (
                        <Button
                            variant="outline"
                            aria-pressed={trail}
                            onClick={() => setTrail((value) => !value)}
                        >
                            <Route size={16} />
                            {trail ? 'Hide observations' : 'Observation trail'}
                        </Button>
                    )}
                </div>
                {unavailable ? (
                    <div className="map-unavailable">
                        <MapPin size={32} />
                        <h3 className="text-section-title">
                            Map imagery is unavailable
                        </h3>
                        <p>
                            The recorded observation and its time remain
                            available beside the map.
                        </p>
                        <Badge tone="warning">Basemap unavailable</Badge>
                    </div>
                ) : (
                    <div
                        className="vehicle-map-canvas"
                        aria-label="Vehicle map with synthetic markers"
                    >
                        <LeafletMap
                            key={mapKey}
                            center={center}
                            zoom={16}
                            height="100%"
                            markers={markers}
                            polyline={
                                trail && !noTracker
                                    ? observation === 'latest'
                                        ? observedPath
                                        : [observations[0], point]
                                    : undefined
                            }
                            polylineOptions={{
                                color: 'var(--primary)',
                                showArrows: true,
                                showEndpoints: false,
                            }}
                            darkMode={dark}
                            onMarkerClick={(id) =>
                                id === 'vehicle' && setDetail(true)
                            }
                        />
                    </div>
                )}
                <div className="map-caption">
                    <Info size={15} />
                    <span>
                        OpenStreetMap basemap · Coloured overlays are fictional.
                        No live location request is made.
                    </span>
                </div>
            </section>
            <div className="stack">
                <Panel
                    title={
                        noTracker
                            ? 'Tracking not assigned'
                            : 'Observation details'
                    }
                    sub="Vehicle record VH-014"
                >
                    {noTracker ? (
                        <Notice title="Manual records remain available">
                            A tracker is optional. Mileage, inspections and
                            maintenance still work.
                        </Notice>
                    ) : (
                        <>
                            <div className="map-observation-icon">
                                <Satellite size={22} />
                                <div>
                                    <strong>Vehicle tracker · demo</strong>
                                    <small>{selected.ref}</small>
                                </div>
                            </div>
                            <Row title="Observed" value={selected.date} />
                            <Row title="Timezone" value="Pacific/Auckland" />
                            <Row
                                title="Age at profile snapshot"
                                value={
                                    observation === 'latest'
                                        ? '1 day 1 hour · historical'
                                        : '1 day 16 hours · historical'
                                }
                            />
                            <Row
                                title="Position"
                                value={`${point.lat.toFixed(6)}, ${point.lng.toFixed(6)}`}
                            />
                            <Row title="Reported movement" value="Stationary" />
                            <Button
                                variant="outline"
                                className="mt-4 w-full"
                                onClick={() => setDetail(true)}
                            >
                                View observation
                            </Button>
                        </>
                    )}
                </Panel>
                <Notice
                    tone="warning"
                    title="Location does not establish readiness"
                >
                    A recorded position does not confirm current custody,
                    vehicle condition or availability.
                </Notice>
                <Panel title="Source records">
                    <Button
                        variant="outline"
                        className="w-full"
                        onClick={onMileage}
                    >
                        View mileage evidence
                    </Button>
                    <p className="panel-footnote">
                        Position and odometer readings keep separate source
                        references and observation times.
                    </p>
                </Panel>
            </div>
            {detail && (
                <Modal
                    title="Recorded vehicle observation"
                    description={`VH-014 · ${selected.ref}`}
                    icon={Satellite}
                    onClose={() => setDetail(false)}
                >
                    <Row title="Observed" value={selected.date} />
                    <Row title="Source reference" value={selected.ref} />
                    <Row title="Source" value="Synthetic vehicle tracker" />
                    <Row
                        title="Coordinates"
                        value={`${point.lat.toFixed(6)}, ${point.lng.toFixed(6)}`}
                    />
                    <Notice title="Historical record">
                        This is a fixed fictional observation. It is not a live
                        location or a request sent to a tracker.
                    </Notice>
                </Modal>
            )}
        </div>
    );
}
