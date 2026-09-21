import type { ClientLocationData } from '@/components/client-location-tab';
import { GovernedLocationExportDialog } from '@/components/security-devices/governed-location-export-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { formatDateTime } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    Activity,
    CalendarDays,
    ChevronDown,
    Crosshair,
    Download,
    MapPin,
    Pencil,
    Radio,
    ShieldCheck,
    ShieldOff,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import ClientLocationMap from './client-location-map';
import LocateNowDialog from './locate-now-dialog';
import LocationAddress from './location-address';
import LocationHistory from './location-history';
import './location-workspace.css';
import TrackerBattery from './tracker-battery';
import TrackerIndicators from './tracker-indicators';
import TrackerModes from './tracker-modes';
import TrackerPanic from './tracker-panic';
import {
    privateHeaders,
    readJson,
    zoneMonitoringLabel,
    type Coordinate,
    type HistoryPoint,
    type ZoneDraft,
    type ZoneEnvelope,
} from './types';
import { useRecordedLocationRefresh } from './use-recorded-location-refresh';
import ZoneDraftDialog from './zone-draft-dialog';
import ZoneMonitoringDialog from './zone-monitoring-dialog';

export default function LocationWorkspace({
    clientId,
    clientName,
    location,
    endAccess,
}: {
    clientId: number;
    clientName: string;
    location: ClientLocationData;
    endAccess: (message: string) => void;
}) {
    const [tab, setTab] = useState<'today' | 'zones' | 'activity'>('today');
    const [envelope, setEnvelope] = useState<ZoneEnvelope | null>(null);
    const [zonesError, setZonesError] = useState('');
    const [loadingZones, setLoadingZones] = useState(false);
    const [editing, setEditing] = useState<{
        draft: ZoneDraft | null;
        center: Coordinate;
    } | null>(null);
    const [selectedZone, setSelectedZone] = useState<ZoneDraft | null>(null);
    const [monitoringZone, setMonitoringZone] = useState<ZoneDraft | null>(
        null,
    );
    const [historical, setHistorical] = useState<HistoryPoint | null>(null);
    const [focus, setFocus] = useState<Coordinate | null>(null);
    const [actionsOpen, setActionsOpen] = useState(false);
    const [actionPoint, setActionPoint] = useState<Coordinate | null>(null);
    const [exportOpen, setExportOpen] = useState(false);
    const [locateOpen, setLocateOpen] = useState(() =>
        new URLSearchParams(window.location.search).has('locate'),
    );
    const [located, setLocated] = useState<HistoryPoint | null>(null);
    const [notice, setNotice] = useState('');
    const [liveView, setLiveView] = useState(true);
    const request = useRef<AbortController | null>(null);
    const drawButton = useRef<HTMLButtonElement>(null);
    const tracker = location.tracker;
    const current =
        located &&
        Date.parse(located.timestamp) >
            Date.parse(tracker?.last_location_at ?? '1970-01-01')
            ? located
            : location.currentLocation;
    const measuredAt =
        current === located && located
            ? located.timestamp
            : tracker?.last_location_at;
    const receiveObservation = useCallback(
        (point: HistoryPoint) => setLocated(point),
        [],
    );
    const center = current ??
        location.geofences[0]?.center ?? { lat: -41.2865, lng: 174.7762 };
    const accessEnded = useCallback(
        () =>
            endAccess(
                'Location access or the tracking assignment changed. Reload this client to check current access.',
            ),
        [endAccess],
    );
    const loadZones = useCallback(async () => {
        if (!location.zonesUrl) return;
        request.current?.abort();
        const abort = new AbortController();
        request.current = abort;
        setLoadingZones(true);
        setZonesError('');
        try {
            const response = await fetch(location.zonesUrl, {
                signal: abort.signal,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: privateHeaders,
            });
            if (abort.signal.aborted) return;
            if (response.status === 403) {
                accessEnded();
                return;
            }
            const data = await readJson<ZoneEnvelope>(response);
            if (abort.signal.aborted) return;
            if (
                location.accessFingerprint &&
                data.access_fingerprint !== location.accessFingerprint
            ) {
                accessEnded();
                return;
            }
            setEnvelope(data);
            setSelectedZone((selected) =>
                selected
                    ? (data.zones.find((zone) => zone.id === selected.id) ??
                      null)
                    : null,
            );
        } catch (error) {
            if (!abort.signal.aborted)
                setZonesError(
                    error instanceof Error
                        ? error.message
                        : 'Zones could not be loaded.',
                );
        } finally {
            if (!abort.signal.aborted) setLoadingZones(false);
        }
    }, [location.zonesUrl, location.accessFingerprint, accessEnded]);
    useEffect(() => {
        void loadZones();
        return () => request.current?.abort();
    }, [loadZones, location]);
    useRecordedLocationRefresh(
        liveView,
        `${clientId}:${location.accessFingerprint}`,
    );
    const startDrawing = (point = center) => {
        setNotice('');
        setEditing({ draft: null, center: point });
    };
    const closeEditor = () => {
        setEditing(null);
        window.setTimeout(() => drawButton.current?.focus(), 0);
    };
    const viewZone = (zone: ZoneDraft) => {
        setHistorical(null);
        setSelectedZone(zone);
        setFocus(
            zone.geometry.type === 'circle'
                ? zone.geometry.center
                : zone.geometry.coordinates[0],
        );
    };

    return (
        <div className="client-location-workspace">
            <details className="location-access">
                <summary>
                    <strong>
                        <ShieldCheck className="mr-2 inline size-4" />
                        Current access checked
                    </strong>
                    <span>Collection authority recorded</span>
                    <span>Staff access permitted</span>
                    <span>Recipient sharing: separate review</span>
                </summary>
                <div className="location-access-body">
                    <div>
                        <strong>Collection</strong>
                        <p>
                            Current consent and tracker assignment are checked
                            on the server. Consent recorded{' '}
                            {formatDateTime(location.trackingConsent?.given_at)}
                            .
                        </p>
                        <Link
                            href={`/operations/clients/${clientId}?tab=consents`}
                        >
                            View consents
                        </Link>
                    </div>
                    <div>
                        <strong>Staff viewing</strong>
                        <p>
                            Your client, site and tracking permissions apply to
                            every read and save. Retention:{' '}
                            {location.retentionDays == null
                                ? 'not recorded'
                                : `${location.retentionDays} days`}
                            .
                        </p>
                    </div>
                    <div>
                        <strong>Family / whānau sharing</strong>
                        <p>
                            This workspace does not establish a named-recipient
                            sharing grant. Collection consent and staff access
                            do not grant recipient access.
                        </p>
                    </div>
                </div>
            </details>
            <div className="location-toolbar">
                <nav
                    className="location-tabs"
                    aria-label="Location workspace views"
                >
                    <Button
                        variant={tab === 'today' ? 'secondary' : 'ghost'}
                        aria-current={tab === 'today' ? 'page' : undefined}
                        onClick={() => setTab('today')}
                    >
                        <MapPin />
                        Today
                    </Button>
                    <Button
                        variant={tab === 'zones' ? 'secondary' : 'ghost'}
                        aria-current={tab === 'zones' ? 'page' : undefined}
                        onClick={() => setTab('zones')}
                    >
                        <ShieldCheck />
                        Safe zones & schedule
                    </Button>
                    <Button
                        variant={tab === 'activity' ? 'secondary' : 'ghost'}
                        aria-current={tab === 'activity' ? 'page' : undefined}
                        onClick={() => setTab('activity')}
                    >
                        <Activity />
                        Activity
                    </Button>
                </nav>
                <div className="location-actions">
                    {location.canExport && location.exportUrl && (
                        <Button
                            variant="outline"
                            onClick={() => setExportOpen(true)}
                        >
                            <Download />
                            Export observations
                        </Button>
                    )}
                    {location.canManage && location.zonesUrl && (
                        <Button
                            ref={drawButton}
                            disabled={!envelope}
                            onClick={() => startDrawing()}
                        >
                            <Pencil />
                            Draw safe zone
                        </Button>
                    )}
                </div>
            </div>
            {notice && (
                <p className="location-message" role="status">
                    <CheckMessage />
                    {notice}
                </p>
            )}
            <div className="location-metrics">
                <div>
                    <small>LATEST RECORDED LOCATION</small>
                    <LocationAddress point={current} />
                    <p>
                        {measuredAt
                            ? formatDateTime(measuredAt)
                            : 'Observation time not recorded'}{' '}
                        · Pacific/Auckland
                    </p>
                </div>
                <div>
                    <small>OBSERVATION QUALITY</small>
                    <strong>
                        {current?.accuracy == null
                            ? 'Accuracy not recorded'
                            : `Reported accuracy ${current.accuracy} m`}
                    </strong>
                    <p>
                        A recorded location does not confirm current wellbeing.
                    </p>
                </div>
                <div>
                    <small>ZONE PLANNING</small>
                    <strong>
                        {envelope
                            ? `${envelope.zones.filter((zone) => zone.monitoring?.status === 'active' && zone.monitoring.authority_current !== false).length} monitoring · ${envelope.zones.length} saved zones`
                            : loadingZones
                              ? 'Loading zones…'
                              : 'Zones unavailable'}
                    </strong>
                    <p>
                        Activated zones send scheduled alerts to Control Room.
                    </p>
                </div>
            </div>
            {tracker?.panic_active && (
                <div className="location-message location-error" role="alert">
                    <strong>Panic alert active</strong>
                    <span>Review the alert and follow the response plan.</span>
                    {location.canManage && tracker.acknowledge_panic_url && (
                        <Button
                            onClick={() =>
                                router.post(
                                    tracker.acknowledge_panic_url!,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Acknowledge panic
                        </Button>
                    )}
                </div>
            )}
            <div className="location-columns">
                <div className="space-y-4">
                    <section className="location-panel">
                        <div className="location-panel-heading">
                            <div>
                                <h2>
                                    {historical
                                        ? 'Selected historical observation'
                                        : selectedZone
                                          ? selectedZone.name
                                          : `${clientName} · location`}
                                </h2>
                                <p>
                                    {historical
                                        ? `${formatDateTime(historical.timestamp)} · Latest observation stays unchanged`
                                        : selectedZone
                                          ? zoneMonitoringLabel(selectedZone)
                                          : 'Recorded observation and saved planning boundaries'}
                                </p>
                            </div>
                            <DropdownMenu
                                open={actionsOpen}
                                onOpenChange={setActionsOpen}
                            >
                                <DropdownMenuTrigger asChild>
                                    <Button variant="outline">
                                        Map actions <ChevronDown />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {location.canManage && envelope && (
                                        <DropdownMenuItem
                                            onSelect={() =>
                                                startDrawing(
                                                    actionPoint ?? center,
                                                )
                                            }
                                        >
                                            <Pencil />
                                            Draw a zone here
                                        </DropdownMenuItem>
                                    )}
                                    {current && (
                                        <DropdownMenuItem
                                            onSelect={() => {
                                                setHistorical(null);
                                                setSelectedZone(null);
                                                setFocus({
                                                    lat: current.lat,
                                                    lng: current.lng,
                                                });
                                            }}
                                        >
                                            <Crosshair />
                                            Centre on latest observation
                                        </DropdownMenuItem>
                                    )}
                                    <DropdownMenuItem
                                        onSelect={() => setTab('activity')}
                                    >
                                        <Activity />
                                        View observation history
                                    </DropdownMenuItem>
                                    {selectedZone &&
                                        selectedZone.monitoring?.status !==
                                            'active' &&
                                        location.canManage && (
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    setEditing({
                                                        draft: selectedZone,
                                                        center,
                                                    })
                                                }
                                            >
                                                <Pencil />
                                                Edit selected draft
                                            </DropdownMenuItem>
                                        )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                        <div
                            className="location-map-wrap"
                            onKeyDown={(event) => {
                                if (event.key === 'F10' && event.shiftKey) {
                                    event.preventDefault();
                                    setActionPoint(center);
                                    setActionsOpen(true);
                                }
                            }}
                        >
                            <ClientLocationMap
                                center={center}
                                observation={historical ?? current}
                                focus={focus}
                                focusShape={selectedZone?.geometry}
                                zones={envelope?.zones}
                                references={location.geofences}
                                shape={selectedZone?.geometry}
                                onSelectZone={viewZone}
                                onContext={(point) => {
                                    setActionPoint(point);
                                    setActionsOpen(true);
                                }}
                            />
                        </div>
                        <div className="location-map-foot">
                            <span>● Recorded observation</span>
                            <span>┄ Saved zone boundary</span>
                            <span>
                                Right-click for actions · Shift+F10 from the map
                            </span>
                        </div>
                        {(historical || selectedZone) && (
                            <div className="location-panel-body">
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setHistorical(null);
                                        setSelectedZone(null);
                                        setFocus({
                                            lat: center.lat,
                                            lng: center.lng,
                                        });
                                    }}
                                >
                                    Return to latest observation
                                </Button>
                            </div>
                        )}
                    </section>
                    {tab === 'activity' ? (
                        <LocationHistory
                            key={`${clientId}:${location.accessFingerprint ?? ''}`}
                            url={`/operations/clients/${clientId}/location/history`}
                            fingerprint={location.accessFingerprint}
                            onAccessEnded={accessEnded}
                            onSelect={(point) => {
                                setHistorical(point);
                                setSelectedZone(null);
                                setFocus(point);
                            }}
                        />
                    ) : (
                        <section className="location-panel">
                            <div className="location-panel-heading">
                                <div>
                                    <h2>Safe zones & schedule</h2>
                                    <p>
                                        Agreed places and areas needing
                                        attention, beyond the home boundary.
                                    </p>
                                </div>
                                <span className="location-badge">
                                    <CalendarDays />
                                    Control Room alerts
                                </span>
                            </div>
                            <div className="location-panel-body">
                                {zonesError && (
                                    <div
                                        role="alert"
                                        className="location-message location-error"
                                    >
                                        {zonesError}
                                        <Button
                                            variant="outline"
                                            onClick={() => void loadZones()}
                                        >
                                            Retry zones
                                        </Button>
                                    </div>
                                )}
                                {loadingZones && (
                                    <p role="status">Loading saved zones…</p>
                                )}
                                {envelope?.zones.length === 0 && (
                                    <div className="location-empty">
                                        <MapPin />
                                        <strong>
                                            Start with an agreed place
                                        </strong>
                                        <p>
                                            Draw a boundary for a library, day
                                            programme, park or another place in
                                            the client’s plan.
                                        </p>
                                        {location.canManage && (
                                            <Button
                                                onClick={() => startDrawing()}
                                            >
                                                Draw the first zone
                                            </Button>
                                        )}
                                    </div>
                                )}
                                {!location.zonesUrl && (
                                    <p className="location-subtle">
                                        Zone draft storage is not available in
                                        this view.
                                    </p>
                                )}
                                <div className="location-zones">
                                    {envelope?.zones.map((zone) => (
                                        <article
                                            key={zone.id}
                                            className="location-zone"
                                        >
                                            <span className="location-badge">
                                                {zone.monitoring?.status ===
                                                'active' ? (
                                                    <ShieldCheck />
                                                ) : (
                                                    <Pencil />
                                                )}
                                                {zoneMonitoringLabel(zone)}
                                            </span>
                                            <h3>{zone.name}</h3>
                                            <p>{zone.purpose}</p>
                                            <p>
                                                {zone.classification ===
                                                'agreed'
                                                    ? 'Alerts when reported outside'
                                                    : 'Alerts when reported inside'}{' '}
                                                · High priority
                                            </p>
                                            <p>
                                                {zone.schedule.weekdays
                                                    .map(
                                                        (day) =>
                                                            [
                                                                'Mon',
                                                                'Tue',
                                                                'Wed',
                                                                'Thu',
                                                                'Fri',
                                                                'Sat',
                                                                'Sun',
                                                            ][day - 1],
                                                    )
                                                    .join(', ')}{' '}
                                                · {zone.schedule.start}–
                                                {zone.schedule.end}
                                                {zone.schedule.following_day
                                                    ? ' next day'
                                                    : ''}
                                            </p>
                                            <small>
                                                Revision {zone.revision} ·{' '}
                                                {formatDateTime(zone.saved_at)}
                                            </small>
                                            {zone.monitoring?.status ===
                                                'active' && (
                                                <p className="location-subtle">
                                                    {zone.monitoring
                                                        .last_observed_at
                                                        ? `Last checked ${formatDateTime(zone.monitoring.last_observed_at)}`
                                                        : 'Waiting for the first new tracker report.'}{' '}
                                                    ·{' '}
                                                    {
                                                        zone.monitoring
                                                            .breach_count
                                                    }{' '}
                                                    breach episodes
                                                </p>
                                            )}
                                            <div className="location-actions">
                                                <Button
                                                    variant="outline"
                                                    onClick={() =>
                                                        viewZone(zone)
                                                    }
                                                >
                                                    View boundary
                                                </Button>
                                                {location.canManage &&
                                                    zone.monitoring?.status !==
                                                        'active' && (
                                                        <Button
                                                            variant="ghost"
                                                            onClick={() =>
                                                                setEditing({
                                                                    draft: zone,
                                                                    center,
                                                                })
                                                            }
                                                        >
                                                            Edit draft
                                                        </Button>
                                                    )}
                                                {location.canManage && (
                                                    <Button
                                                        variant={
                                                            zone.monitoring
                                                                ?.status ===
                                                            'active'
                                                                ? 'outline'
                                                                : 'default'
                                                        }
                                                        onClick={() =>
                                                            setMonitoringZone(
                                                                zone,
                                                            )
                                                        }
                                                    >
                                                        {zone.monitoring
                                                            ?.status ===
                                                        'active'
                                                            ? 'Pause monitoring'
                                                            : 'Review & activate'}
                                                    </Button>
                                                )}
                                            </div>
                                        </article>
                                    ))}
                                </div>
                            </div>
                        </section>
                    )}
                </div>
                <aside className="location-panel">
                    <div className="location-panel-heading">
                        <h2>
                            <Radio className="mr-2 inline size-4" />
                            Tracking source
                        </h2>
                    </div>
                    <div className="location-panel-body location-source">
                        {tracker ? (
                            <>
                                <strong>{tracker.name}</strong>
                                <TrackerIndicators {...tracker} />
                                <TrackerPanic {...tracker} />
                                <TrackerBattery {...tracker} />
                                <dl>
                                    <dt>Last contact</dt>
                                    <dd>
                                        {formatDateTime(tracker.last_seen_at)}
                                    </dd>
                                    <dt>Location report</dt>
                                    <dd>{formatDateTime(measuredAt)}</dd>
                                    <dt>Last command</dt>
                                    <dd>
                                        {tracker.last_command_status === 'acked'
                                            ? 'Acknowledged'
                                            : tracker.last_command_status ||
                                              'Not recorded'}
                                    </dd>
                                </dl>
                                <p className="location-subtle">
                                    A command acknowledgement is separate from a
                                    new location report. Battery time is
                                    recorded independently.
                                </p>
                                {location.canManage &&
                                    tracker.locate_requests_url &&
                                    location.accessFingerprint && (
                                        <>
                                            <Button
                                                onClick={() =>
                                                    setLocateOpen(true)
                                                }
                                            >
                                                <Crosshair />
                                                Locate now
                                            </Button>
                                            <small>
                                                Request a location report and
                                                follow its progress here.
                                            </small>
                                        </>
                                    )}
                                <section
                                    className="tracker-status-card"
                                    aria-label="Live tracking view"
                                >
                                    <div className="tracker-battery-heading">
                                        <span>LIVE VIEW</span>
                                        <span>
                                            {liveView
                                                ? 'Auto refresh on'
                                                : 'Paused'}
                                        </span>
                                    </div>
                                    <div className="tracker-status-reading">
                                        <span
                                            className="tracker-status-icon"
                                            aria-hidden="true"
                                        >
                                            <Radio />
                                        </span>
                                        <div>
                                            <strong>
                                                {liveView
                                                    ? 'Following reports'
                                                    : 'Updates paused'}
                                            </strong>
                                            <p>Latest received locations</p>
                                        </div>
                                    </div>
                                    <p className="tracker-status-note">
                                        Checks for new reports every 30 seconds
                                        while this page is visible. Tracker
                                        reporting frequency stays unchanged.
                                    </p>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setLiveView(!liveView)}
                                    >
                                        {liveView
                                            ? 'Pause live view'
                                            : 'Resume live view'}
                                    </Button>
                                </section>
                                <TrackerModes
                                    url={tracker.tracker_modes_url}
                                    fingerprint={location.accessFingerprint}
                                    onAccessEnded={endAccess}
                                    deviceUrl={tracker.detail_url}
                                />
                                <hr />
                                {tracker.detail_url ? (
                                    <Link href={tracker.detail_url}>
                                        Open Device Profile
                                    </Link>
                                ) : (
                                    <p className="location-subtle">
                                        {tracker.detail_access?.label ||
                                            'Device Profile access required'}
                                    </p>
                                )}
                                {tracker.tracking_workspace_url ? (
                                    <Link href={tracker.tracking_workspace_url}>
                                        Open Tracking workspace
                                    </Link>
                                ) : (
                                    <p className="location-subtle">
                                        {tracker.tracking_workspace_access
                                            ?.label ||
                                            'Tracking workspace access required'}
                                    </p>
                                )}
                            </>
                        ) : (
                            <div className="location-empty">
                                <ShieldOff />
                                <strong>No personal tracker assigned</strong>
                                <p>
                                    Use the canonical device assignment workflow
                                    before collecting location.
                                </p>
                            </div>
                        )}
                    </div>
                </aside>
            </div>
            {monitoringZone && envelope && location.zonesUrl && (
                <ZoneMonitoringDialog
                    zone={monitoringZone}
                    url={location.zonesUrl}
                    fingerprint={envelope.access_fingerprint}
                    onClose={() => setMonitoringZone(null)}
                    onAccessEnded={accessEnded}
                    onSaved={(zone) => {
                        setEnvelope({
                            ...envelope,
                            zones: envelope.zones.map((item) =>
                                item.id === zone.id ? zone : item,
                            ),
                        });
                        if (selectedZone?.id === zone.id) setSelectedZone(zone);
                        setMonitoringZone(null);
                        setNotice(
                            zone.monitoring?.status === 'active'
                                ? `${zone.name} is set to send scheduled alerts to Control Room.`
                                : `${zone.name} monitoring is paused. Existing alerts remain in Control Room.`,
                        );
                    }}
                />
            )}
            {editing && envelope && location.zonesUrl && (
                <ZoneDraftDialog
                    center={editing.center}
                    draft={editing.draft}
                    boundaries={envelope.boundaries}
                    url={location.zonesUrl}
                    fingerprint={envelope.access_fingerprint}
                    onAccessEnded={accessEnded}
                    onClose={closeEditor}
                    onSaved={(zone) => {
                        setEnvelope({
                            ...envelope,
                            zones: [
                                zone,
                                ...envelope.zones.filter(
                                    (item) => item.id !== zone.id,
                                ),
                            ],
                        });
                        setTab('zones');
                        setSelectedZone(zone);
                        setNotice(`${zone.name} saved as an inactive draft.`);
                        closeEditor();
                    }}
                />
            )}
            {location.canExport && location.exportUrl && (
                <GovernedLocationExportDialog
                    open={exportOpen}
                    onOpenChange={setExportOpen}
                    exportUrl={location.exportUrl}
                    subjectLabel={clientName}
                    retentionDays={location.retentionDays ?? undefined}
                    onAccessEnded={accessEnded}
                />
            )}
            {location.canManage &&
                tracker?.locate_requests_url &&
                location.accessFingerprint && (
                    <LocateNowDialog
                        key={`${clientId}:${location.accessFingerprint}`}
                        open={locateOpen}
                        onOpenChange={setLocateOpen}
                        clientId={clientId}
                        trackerName={tracker.name}
                        lastMeasuredAt={measuredAt}
                        url={tracker.locate_requests_url}
                        fingerprint={location.accessFingerprint}
                        onAccessEnded={accessEnded}
                        onObservation={receiveObservation}
                    />
                )}
        </div>
    );
}

function CheckMessage() {
    return <ShieldCheck className="size-4" />;
}
