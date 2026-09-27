import LeafletMap, {
    type MapContextPoint,
    type MapMarker,
} from '@/components/leaflet-map';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { LoadingState } from '@/components/ui/loading-state';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { StatusBadge } from '@/components/ui/status-badge';
import { Link } from '@inertiajs/react';
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
import {
    useCallback,
    useEffect,
    useMemo,
    useState,
    type MouseEvent,
} from 'react';
import {
    GeofenceAssignmentWizard,
    GeofenceSelectDialog,
} from './geofence-wizard';
import {
    geofenceOverlays,
    linkedSummary,
    mapCentre,
    observedLabel,
    reportedFacts,
    WITHHELD_TEXT,
    zoneAbbreviation,
} from './map-model';
import type {
    Coordinate,
    LinkedGeofence,
    LocationTrail,
    VehicleGeofences,
    VehicleLocation,
} from './map-types';
import './map.css';
import { ReminderDialog } from './service-reminders';
import { SourceRecordDialog } from './studio-kit';
import './studio.css';
import type { VehicleWorkspace } from './types';
import type { WorkspaceLocation } from './workspace-model';

type Load = 'loading' | 'ready' | 'error' | 'unavailable';

/** Refresh the reported state while the view is open and visible. */
const REFRESH_MS = 60_000;

async function getJson<T>(url: string, signal?: AbortSignal): Promise<T> {
    const response = await fetch(url, {
        signal,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
    });
    if (!response.ok)
        throw Object.assign(new Error('request failed'), {
            status: response.status,
        });
    return (await response.json()) as T;
}

function useVehicleLocation(vehicleId: number) {
    const [location, setLocation] = useState<VehicleLocation | null>(null);
    const [load, setLoad] = useState<Load>('loading');
    const [attempt, setAttempt] = useState(0);
    const reload = useCallback(() => setAttempt((value) => value + 1), []);
    useEffect(() => {
        const controller = new AbortController();
        getJson<VehicleLocation>(
            `/fleet-assets/vehicles/${vehicleId}/location`,
            controller.signal,
        )
            .then((data) => {
                setLocation(data);
                setLoad('ready');
            })
            .catch((error: unknown) => {
                if ((error as Error)?.name === 'AbortError') return;
                const status = (error as { status?: number })?.status;
                setLoad(
                    status === 403 || status === 404 ? 'unavailable' : 'error',
                );
            });
        return () => controller.abort();
    }, [vehicleId, attempt]);
    useEffect(() => {
        const timer = window.setInterval(() => {
            if (!document.hidden) reload();
        }, REFRESH_MS);
        return () => window.clearInterval(timer);
    }, [reload]);
    return { location, setLocation, load, reload };
}

/**
 * Map › Location & geofences, built to the approved PKG-02B v13 design
 * (MapStudio): the greyscale map with its toolbar and right-click actions,
 * the reported-state inspector and the vehicle's shared geofences. Positions
 * are recorded observations, never a live fix, and never establish custody
 * or readiness.
 */
export function VehicleLocationPanel({
    workspace,
    onNavigate,
    onChanged,
}: {
    workspace: VehicleWorkspace;
    onNavigate: (location: WorkspaceLocation) => void;
    /** A reminder was added: refresh the workspace. */
    onChanged: () => void;
}) {
    const vehicle = workspace.vehicle;
    const { location, setLocation, load, reload } = useVehicleLocation(
        vehicle.id,
    );
    const [observationId, setObservationId] = useState('current');
    const [showTrail, setShowTrail] = useState(false);
    const [zones, setZones] = useState(true);
    const [expanded, setExpanded] = useState(false);
    const [mapKey, setMapKey] = useState(0);
    const [override, setOverride] = useState<Coordinate | null>(null);
    const [context, setContext] = useState<MapContextPoint | null>(null);
    const [dialog, setDialog] = useState<
        'select' | 'position' | 'readiness' | 'reminder' | null
    >(null);
    const [wizard, setWizard] = useState<{
        initial?: LinkedGeofence;
        draftPoint?: Coordinate;
    } | null>(null);
    const [trail, setTrail] = useState<{
        key: string;
        status: 'loading' | 'ready' | 'error';
        data: LocationTrail | null;
    } | null>(null);
    const [tiles, setTiles] = useState({ loaded: false, failures: 0 });

    const observations = useMemo(
        () => location?.observations ?? [],
        [location],
    );
    const observation =
        observations.find((entry) => entry.id === observationId) ??
        observations[0] ??
        null;
    const facts = useMemo(
        () => (location ? reportedFacts(location, observation) : null),
        [location, observation],
    );
    const centre =
        override ?? (location && facts ? mapCentre(location, facts) : null);
    const unavailable = tiles.failures >= 3 && !tiles.loaded;
    const canManage = !!location?.geofences.can.manage;
    const restricted = workspace.readiness.restriction_ids.length > 0;
    const label =
        vehicle.registration_number ?? vehicle.asset_tag ?? vehicle.name;

    // The recorded route behind the selected observation, fetched on demand.
    const trailKey = observation ? `${observation.id}` : 'none';
    useEffect(() => {
        if (!showTrail || !location?.positions_visible || !observation) return;
        if (trail?.key === trailKey && trail.status !== 'error') return;
        const controller = new AbortController();
        setTrail({ key: trailKey, status: 'loading', data: null });
        const query = observation.trip_id ? `?trip=${observation.trip_id}` : '';
        getJson<LocationTrail>(
            `/fleet-assets/vehicles/${vehicle.id}/location/trail${query}`,
            controller.signal,
        )
            .then((data) => setTrail({ key: trailKey, status: 'ready', data }))
            .catch((error: unknown) => {
                if ((error as Error)?.name === 'AbortError') return;
                // 404: no trip is recorded for this observation.
                const missing = (error as { status?: number })?.status === 404;
                setTrail({
                    key: trailKey,
                    status: missing ? 'ready' : 'error',
                    data: null,
                });
            });
        return () => controller.abort();
        // eslint-disable-next-line react-hooks/exhaustive-deps -- refetch only when the observation or toggle changes
    }, [showTrail, trailKey, vehicle.id, location?.positions_visible]);

    // Esc leaves the expanded map, like closing a dialog.
    useEffect(() => {
        if (!expanded) return;
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setExpanded(false);
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [expanded]);

    const markers = useMemo<MapMarker[]>(() => {
        if (!location || !facts) return [];
        const site = location.vehicle.home_site;
        const list: MapMarker[] = [];
        if (site && site.lat !== null && site.lng !== null)
            list.push({
                id: 'home',
                lat: site.lat,
                lng: site.lng,
                title: site.name,
                type: 'house',
                color: 'var(--category-sites)',
                popup: `${site.name} · home site`,
            });
        if (!facts.noTracker && facts.position)
            list.push({
                id: 'vehicle',
                lat: facts.position.lat,
                lng: facts.position.lng,
                title: label,
                stats: [
                    ['Ignition', facts.ignition],
                    ['Speed', facts.speed],
                    ['Motion', facts.motion],
                    ['Voltage', facts.voltage],
                ],
                hint: 'Right-click vehicle for quick actions',
                type: 'vehicle',
                status:
                    facts.current && facts.motion === 'Moving'
                        ? 'moving'
                        : 'historical',
                speed:
                    facts.current &&
                    facts.motion === 'Moving' &&
                    location.state?.speed_kph !== null &&
                    location.state?.speed_kph !== undefined
                        ? Math.round(location.state.speed_kph)
                        : undefined,
                color: 'var(--primary)',
                popup: `${observedLabel(observation?.observed_at)} · ${facts.stateLabel}`,
            });
        return list;
    }, [location, facts, label, observation]);

    const overlays = useMemo(
        () =>
            location && zones ? geofenceOverlays(location.geofences.items) : [],
        [location, zones],
    );
    const trailPoints =
        showTrail && trail?.key === trailKey && trail.data?.points.length
            ? trail.data.points
            : undefined;

    if (load === 'loading' && !location)
        return <LoadingState message="Loading the vehicle’s location…" />;
    if (load === 'unavailable')
        return (
            <EmptyState
                icon={MapPin}
                title="Location isn’t available"
                description="You don’t have access to this vehicle’s location, or it is no longer in your sites."
            />
        );
    if (!location || !facts)
        return (
            <ErrorState
                title="Location couldn’t be loaded"
                message="The vehicle’s recorded position and geofences couldn’t be loaded. Try again."
                onRetry={reload}
            />
        );

    const noTracker = facts.noTracker;
    const replaceGeofences = (next: VehicleGeofences) =>
        setLocation((old) => (old ? { ...old, geofences: next } : old));
    const recenter = () => {
        setOverride(null);
        setTiles({ loaded: false, failures: 0 });
        setMapKey((key) => key + 1);
    };
    const openAt = (event: MouseEvent<HTMLButtonElement>) => {
        if (!centre) return;
        const rect = event.currentTarget.getBoundingClientRect();
        setContext({ ...centre, x: rect.left, y: rect.bottom });
    };
    const trailNote = !showTrail
        ? null
        : !observation
          ? 'No recorded position to trace.'
          : !trail || trail.key !== trailKey || trail.status === 'loading'
            ? 'Loading the recorded route…'
            : trail.status === 'error'
              ? 'The recorded route couldn’t be loaded.'
              : trail.data?.withheld
                ? `Route withheld · ${trail.data.withheld === 'personal' ? 'personal trip' : 'no tracking consent'}`
                : trail.data && trail.data.points.length > 1
                  ? `Trip #${trail.data.trip.id} route · ${trail.data.recorded_points} recorded points${trail.data.downsampled ? ' (simplified)' : ''} · not a verified road path`
                  : 'No recorded route for this observation.';
    const monitoredHref = (item: LinkedGeofence) =>
        item.geofence_id
            ? `/fleet-assets/geofences?edit=${item.geofence_id}`
            : null;

    return (
        <>
            <div className={`map-studio ${expanded ? 'map-expanded' : ''}`}>
                <section className="map-stage studio-card">
                    <div className="studio-section-heading">
                        <div>
                            <span className="studio-eyebrow">
                                LOCATION · {vehicle.asset_tag ?? label}
                            </span>
                            <h2 className="text-section-title">
                                {noTracker
                                    ? 'Home site context'
                                    : 'Vehicle location'}
                            </h2>
                        </div>
                        <StatusBadge
                            variant={
                                noTracker
                                    ? 'neutral'
                                    : facts.current && facts.fresh
                                      ? 'success'
                                      : 'warning'
                            }
                        >
                            {facts.stateLabel}
                        </StatusBadge>
                    </div>
                    <div
                        className="map-tools"
                        role="toolbar"
                        aria-label="Map tools"
                    >
                        <Button size="sm" variant="outline" onClick={recenter}>
                            <LocateFixed className="size-[15px]" />
                            Recenter
                        </Button>
                        <Button
                            size="sm"
                            variant={showTrail ? 'secondary' : 'ghost'}
                            disabled={noTracker || !location.positions_visible}
                            aria-pressed={showTrail}
                            onClick={() => setShowTrail(!showTrail)}
                        >
                            <Route className="size-[15px]" />
                            Historical trail
                        </Button>
                        <Button
                            size="sm"
                            variant={zones ? 'secondary' : 'ghost'}
                            aria-pressed={zones}
                            onClick={() => setZones(!zones)}
                        >
                            <Layers className="size-[15px]" />
                            Geofences
                        </Button>
                        <span className="muted">
                            Right-click for location actions
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!centre || unavailable}
                            onClick={openAt}
                        >
                            Location actions
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            aria-pressed={expanded}
                            onClick={() => setExpanded(!expanded)}
                        >
                            {expanded ? 'Exit expanded map' : 'Expand map'}
                        </Button>
                    </div>
                    <div className="studio-map-canvas">
                        {unavailable || !centre ? (
                            <div className="studio-empty">
                                <MapPin className="size-[35px]" aria-hidden />
                                <h3>
                                    {unavailable
                                        ? 'Map imagery unavailable'
                                        : 'No location to show'}
                                </h3>
                                <p>
                                    {unavailable
                                        ? 'Recorded coordinates and source times remain available.'
                                        : 'No position has been recorded and the home site has no map location. Add the site’s address to show it here.'}
                                </p>
                            </div>
                        ) : (
                            <LeafletMap
                                key={mapKey}
                                center={centre}
                                zoom={16}
                                height="100%"
                                fitMarkers={false}
                                observeResize
                                markers={markers}
                                polyline={trailPoints}
                                polylineOptions={{
                                    color: 'var(--primary)',
                                    showArrows: true,
                                    showEndpoints: false,
                                }}
                                geofences={overlays}
                                onContext={setContext}
                                onTileStatus={(status) =>
                                    setTiles((old) =>
                                        status === 'loaded'
                                            ? old.loaded
                                                ? old
                                                : { ...old, loaded: true }
                                            : {
                                                  ...old,
                                                  failures: old.failures + 1,
                                              },
                                    )
                                }
                                onMarkerClick={(id) =>
                                    id === 'vehicle' && setDialog('position')
                                }
                            />
                        )}
                    </div>
                    <div className="map-bottom">
                        <span>
                            <Radio className="size-3.5" aria-hidden />
                            {noTracker
                                ? 'No vehicle location reported'
                                : observation
                                  ? `${observedLabel(observation.observed_at)} · ${facts.reference}`
                                  : 'No recorded position to show'}
                            {trailNote && ` · ${trailNote}`}
                        </span>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => onNavigate({ tab: 'trips' })}
                        >
                            Trip history <ArrowUpRight className="size-3.5" />
                        </Button>
                    </div>
                </section>
                <aside className="map-inspector studio-card">
                    <div className="studio-section-heading">
                        <div>
                            <span className="studio-eyebrow">
                                REPORTED STATE
                            </span>
                            <h2 className="text-section-title">{label}</h2>
                        </div>
                        <Car className="size-[25px]" aria-hidden />
                    </div>
                    <div className="motion-indicators">
                        <Popover>
                            <PopoverTrigger asChild>
                                {/* eslint-disable-next-line no-restricted-syntax -- the design's motion tile (.motion-tile) opens its report details */}
                                <button
                                    type="button"
                                    className="motion-tile"
                                    aria-label={`Motion: ${facts.motion}. Show report details`}
                                >
                                    <span className="motion-icon">
                                        <Navigation
                                            className="size-[26px]"
                                            aria-hidden
                                        />
                                    </span>
                                    <span>
                                        <small>Motion</small>
                                        <strong>{facts.motion}</strong>
                                    </span>
                                    <ArrowUpRight
                                        className="size-3.5"
                                        aria-hidden
                                    />
                                </button>
                            </PopoverTrigger>
                            <PopoverContent align="end">
                                <strong>Motion at the last report</strong>
                                <p className="text-caption mt-2">
                                    {noTracker
                                        ? 'No tracker is assigned.'
                                        : `${facts.motion} · ${observedLabel(observation?.observed_at ?? location.state?.observed_at)}. ${
                                              facts.current
                                                  ? facts.fresh
                                                      ? 'Reported by the tracker; ignition and motion are derived states, not a continuous feed.'
                                                      : 'This report is stale, so the vehicle’s movement now is unknown.'
                                                  : 'This is a historical observation.'
                                          }`}
                                </p>
                            </PopoverContent>
                        </Popover>
                        <div className="tracker-small">
                            <Radio className="size-[17px]" aria-hidden />
                            <div>
                                <small>Tracker</small>
                                <strong>
                                    {noTracker
                                        ? 'Not assigned'
                                        : facts.stateLabel}
                                </strong>
                            </div>
                            <StatusBadge
                                size="sm"
                                variant={facts.trackerBadge.variant}
                            >
                                {facts.trackerBadge.label}
                            </StatusBadge>
                        </div>
                    </div>
                    <div className="reported-stats">
                        {(
                            [
                                [Zap, 'Ignition', facts.ignition],
                                [Gauge, 'Speed', facts.speed],
                                [BatteryCharging, 'Vehicle', facts.voltage],
                                [Signal, 'Signal', facts.signal],
                            ] as const
                        ).map(([Icon, name, value]) => (
                            <div key={name}>
                                <Icon className="size-[18px]" aria-hidden />
                                <small>{name}</small>
                                <strong>{value}</strong>
                            </div>
                        ))}
                    </div>
                    <div className="reported-health">
                        <span
                            className={
                                restricted ? 'health-critical' : 'health-ok'
                            }
                        >
                            <ShieldCheck className="size-4" aria-hidden />
                            {restricted ? 'Use restricted' : 'Check readiness'}
                        </span>
                        {location.alerts.open !== null && (
                            // eslint-disable-next-line no-restricted-syntax -- the design's .reported-health row link to the vehicle's alerts
                            <button
                                type="button"
                                onClick={() =>
                                    onNavigate({ tab: 'map', view: 'alerts' })
                                }
                            >
                                <ShieldAlert className="size-4" aria-hidden />
                                {location.alerts.open} open{' '}
                                {location.alerts.open === 1
                                    ? 'alert'
                                    : 'alerts'}{' '}
                                <ArrowUpRight
                                    className="size-[13px]"
                                    aria-hidden
                                />
                            </button>
                        )}
                    </div>
                    <p className="reported-source">{facts.source}</p>
                    {!noTracker && (
                        <>
                            {observations.length > 0 && (
                                <label className="map-observation-select">
                                    Observation
                                    <select
                                        aria-label="Select recorded observation"
                                        value={observation?.id ?? ''}
                                        onChange={(event) => {
                                            setObservationId(
                                                event.target.value,
                                            );
                                            setOverride(null);
                                        }}
                                    >
                                        {observations.map((entry) => (
                                            <option
                                                value={entry.id}
                                                key={entry.id}
                                            >
                                                {observedLabel(
                                                    entry.observed_at,
                                                )}
                                                {entry.kind === 'latest'
                                                    ? ' · latest report'
                                                    : ' · end of trip'}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}
                            <div className="map-coordinate">
                                <MapPin className="size-4" aria-hidden />
                                <div>
                                    {facts.position ? (
                                        <>
                                            <strong>
                                                {facts.position.lat.toFixed(5)},{' '}
                                                {facts.position.lng.toFixed(5)}
                                            </strong>
                                            <small>
                                                Recorded coordinates ·{' '}
                                                {zoneAbbreviation(
                                                    observation?.observed_at,
                                                )}
                                                {observation?.address
                                                    ? ` · ${observation.address}`
                                                    : ''}
                                            </small>
                                        </>
                                    ) : (
                                        <>
                                            <strong>No position shown</strong>
                                            <small>
                                                {!location.positions_visible
                                                    ? WITHHELD_TEXT.access
                                                    : facts.withheld
                                                      ? WITHHELD_TEXT[
                                                            facts.withheld
                                                        ]
                                                      : 'The tracker has not reported a position.'}
                                            </small>
                                        </>
                                    )}
                                </div>
                            </div>
                        </>
                    )}
                    <div className="map-zone-heading">
                        <h3>Linked geofences</h3>
                        <StatusBadge variant="neutral" size="sm">
                            {location.geofences.items.length}
                        </StatusBadge>
                    </div>
                    <div className="map-zones">
                        {location.geofences.items.map((item) => {
                            // Monitoring is managed in Fleet geofences, not here.
                            const href =
                                item.monitoring === 'on' && canManage
                                    ? monitoredHref(item)
                                    : null;
                            return (
                                <div key={item.key}>
                                    <ShieldCheck
                                        className="size-[18px]"
                                        aria-hidden
                                    />
                                    <span>
                                        <strong>{item.label}</strong>
                                        <small>{linkedSummary(item)}</small>
                                    </span>
                                    {href ? (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            asChild
                                            title="Monitoring is on: manage it in Fleet geofences"
                                        >
                                            <Link href={href}>Manage</Link>
                                        </Button>
                                    ) : (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            disabled={
                                                !canManage ||
                                                item.monitoring === 'on'
                                            }
                                            onClick={() =>
                                                setWizard({ initial: item })
                                            }
                                        >
                                            Manage
                                        </Button>
                                    )}
                                </div>
                            );
                        })}
                        {!location.geofences.items.length && (
                            <p className="muted">No geofences selected.</p>
                        )}
                    </div>
                    <div className="map-zone-actions">
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!canManage}
                            onClick={() => setDialog('select')}
                        >
                            Select existing
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!canManage}
                            onClick={() => setWizard({})}
                        >
                            <Plus className="size-3.5" />
                            Create
                        </Button>
                    </div>
                    <p className="studio-footnote">
                        One shared geofence record can be selected from vehicle,
                        client, site or house maps. Linking a boundary does not
                        change its monitoring settings.
                    </p>
                    <Button
                        variant="ghost"
                        className="w-full"
                        onClick={() => setDialog('readiness')}
                    >
                        Location & readiness{' '}
                        <ArrowUpRight className="size-3.5" />
                    </Button>
                </aside>
            </div>
            <DropdownMenu
                open={!!context}
                modal={false}
                onOpenChange={(open) => {
                    if (!open) setContext(null);
                }}
            >
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label="Map context anchor"
                        tabIndex={-1}
                        className="map-context-anchor"
                        style={{ left: context?.x ?? 0, top: context?.y ?? 0 }}
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="start"
                    sideOffset={2}
                    className="vehicle-map-context-menu"
                >
                    <DropdownMenuLabel>
                        {context?.markerId === 'vehicle'
                            ? `${label} · vehicle actions`
                            : 'Map location'}
                        <small>
                            {context?.lat.toFixed(5)}, {context?.lng.toFixed(5)}
                        </small>
                    </DropdownMenuLabel>
                    <DropdownMenuSeparator />
                    {context?.markerId === 'vehicle' && (
                        <>
                            {location.can.view_telemetry && (
                                <DropdownMenuItem
                                    onSelect={() =>
                                        onNavigate({
                                            tab: 'map',
                                            view: 'telemetry',
                                        })
                                    }
                                >
                                    <Gauge />
                                    Vehicle telemetry
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuItem
                                onSelect={() => onNavigate({ tab: 'trips' })}
                            >
                                <Route />
                                Trip history & insights
                            </DropdownMenuItem>
                            {location.can.view_alerts && (
                                <DropdownMenuItem
                                    onSelect={() =>
                                        onNavigate({
                                            tab: 'map',
                                            view: 'alerts',
                                        })
                                    }
                                >
                                    <ShieldAlert />
                                    Faults & Control Room alerts
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuItem
                                disabled={!location.can.add_reminder}
                                onSelect={() => setDialog('reminder')}
                            >
                                <Clock3 />
                                Add vehicle reminder
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                        </>
                    )}
                    <DropdownMenuItem
                        onSelect={() => {
                            if (!context) return;
                            setOverride({ lat: context.lat, lng: context.lng });
                            setMapKey((key) => key + 1);
                        }}
                    >
                        <LocateFixed />
                        Centre map here
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={!canManage}
                        onSelect={() => {
                            if (context)
                                setWizard({
                                    draftPoint: {
                                        lat: +context.lat.toFixed(6),
                                        lng: +context.lng.toFixed(6),
                                    },
                                });
                        }}
                    >
                        <Plus />
                        Create geofence here
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={!canManage}
                        onSelect={() => setDialog('select')}
                    >
                        <Layers />
                        Select existing geofence
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            {dialog === 'select' && (
                <GeofenceSelectDialog
                    vehicleId={vehicle.id}
                    vehicleName={vehicle.name}
                    geofences={location.geofences}
                    onClose={() => setDialog(null)}
                    onSaved={replaceGeofences}
                />
            )}
            {wizard && (
                <GeofenceAssignmentWizard
                    location={location}
                    initial={wizard.initial}
                    draftPoint={wizard.draftPoint}
                    onClose={() => setWizard(null)}
                    onSaved={replaceGeofences}
                    onStale={() => {
                        setWizard(null);
                        reload();
                    }}
                />
            )}
            {dialog === 'position' && observation && (
                <SourceRecordDialog
                    title="Recorded vehicle position"
                    description={`${label} · ${facts.reference}`}
                    rows={[
                        ['Source', facts.reference],
                        ['Observed', observedLabel(observation.observed_at)],
                        [
                            'Position',
                            facts.position
                                ? `${facts.position.lat.toFixed(6)}, ${facts.position.lng.toFixed(6)}`
                                : 'Not shown',
                        ],
                        ['Motion', facts.motion],
                        [
                            'Current location',
                            facts.current && facts.fresh
                                ? 'Current sample; not a continuous live fix'
                                : 'Unknown; this is a last-known report',
                        ],
                    ]}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'readiness' && (
                <SourceRecordDialog
                    title="Location & readiness"
                    description="What a recorded position can and can’t tell you"
                    rows={[
                        [
                            'Position',
                            noTracker
                                ? 'No tracker is assigned'
                                : 'Recorded tracker observation, not live custody',
                        ],
                        ['Custody', 'Booking checkout and return records'],
                        [
                            'Readiness',
                            'Checks, compliance and authorised release',
                        ],
                        [
                            'Automatic alerts',
                            'Linking a boundary does not enable monitoring; check its separate monitoring settings',
                        ],
                    ]}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'reminder' && (
                <ReminderDialog
                    workspace={workspace}
                    reminder={null}
                    onClose={() => setDialog(null)}
                    onSaved={onChanged}
                />
            )}
        </>
    );
}
