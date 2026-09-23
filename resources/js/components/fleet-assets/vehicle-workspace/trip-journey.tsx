import LeafletMap, { type MapMarker } from '@/components/leaflet-map';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatTime } from '@/lib/datetime';
import { ArrowLeft, ArrowRight, MapPin } from 'lucide-react';
import { useCallback, useMemo, type RefObject } from 'react';
import {
    coordinates,
    endLabel,
    endpointCaption,
    formatSpeed,
    pointLocation,
    startLabel,
} from './trip-model';
import type { TripDetail, TripEvent } from './trip-types';

/** At most this many recorded positions are drawn as dots on the route. */
const MAX_SAMPLE_DOTS = 150;

/** The approved "Recorded journey" panel: route map, location strip and playback. */
export function TripJourney({
    detail,
    panelRef,
    sample,
    onSample,
    playing,
    onTogglePlay,
    rate,
    onRate,
    focusedEvent,
    mapFocus,
}: {
    detail: TripDetail;
    panelRef: RefObject<HTMLElement | null>;
    sample: number;
    onSample: (index: number) => void;
    playing: boolean;
    onTogglePlay: () => void;
    rate: number;
    onRate: (rate: number) => void;
    focusedEvent: TripEvent | null;
    mapFocus?: { lat: number; lng: number; nonce: number };
}) {
    const { points, trip, behaviour } = detail;
    const count = points.length;
    const current = count ? Math.min(sample, count - 1) : 0;
    const point = points[current];

    const route = useMemo(
        () => points.map((p) => ({ lat: p.lat, lng: p.lng })),
        [points],
    );
    const center = useMemo(
        () =>
            points[0]
                ? { lat: points[0].lat, lng: points[0].lng }
                : {
                      lat: trip.start.lat ?? -41.2865,
                      lng: trip.start.lng ?? 174.7762,
                  },
        [points, trip.start.lat, trip.start.lng],
    );
    const polylineOptions = useMemo(
        () => ({
            color: 'var(--primary)',
            showArrows: true,
            showEndpoints: true,
            ...(behaviour.partial ? { dashArray: '7 7' } : {}),
        }),
        [behaviour.partial],
    );
    const step = Math.max(1, Math.ceil(count / MAX_SAMPLE_DOTS));
    const markers = useMemo<MapMarker[]>(() => {
        if (!point) return [];
        const dots: MapMarker[] = [];
        points.forEach((p, index) => {
            if (index === current) return;
            if (index % step !== 0 && index !== count - 1) return;
            // Selecting a dot moves the playback marker there; the location
            // strip above the map describes it, so the dot has no popup.
            dots.push({
                id: `sample-${index}`,
                lat: p.lat,
                lng: p.lng,
                type: 'point',
                color: 'var(--primary)',
            });
        });
        const moving = point.speed_kph === null ? null : point.speed_kph > 0;
        dots.push({
            id: 'playback',
            lat: point.lat,
            lng: point.lng,
            type: 'vehicle',
            status: 'historical',
            color: 'var(--primary)',
            heading: point.heading ?? undefined,
            title: `Point ${current + 1}`,
            popup: pointLocation(point),
            stats: [
                ['Location', pointLocation(point)],
                ['Time', formatTime(point.at)],
                ['Speed', formatSpeed(point.speed_kph)],
                [
                    'Ignition',
                    point.ignition === null
                        ? 'Not reported'
                        : point.ignition
                          ? 'On'
                          : 'Off',
                ],
                [
                    'Motion',
                    moving === null
                        ? 'Not reported'
                        : moving
                          ? 'Moving'
                          : 'Stopped',
                ],
            ],
        });
        return dots;
    }, [points, point, current, step, count]);

    const onMarkerClick = useCallback(
        (id: string | number) => {
            const key = String(id);
            if (key.startsWith('sample-')) onSample(Number(key.slice(7)));
        },
        [onSample],
    );
    const startTime = trip.started_at ? formatTime(trip.started_at) : '—';
    const endTime = trip.ended_at ? formatTime(trip.ended_at) : '—';

    return (
        <section
            ref={panelRef}
            className="studio-card journey-map-panel"
            aria-label="Recorded journey"
        >
            <div className="journey-map-title">
                <div>
                    <span className="studio-eyebrow">{trip.reference}</span>
                    <h3>Recorded journey</h3>
                </div>
                <StatusBadge variant="info">
                    {count
                        ? `Point ${current + 1} of ${count}`
                        : 'No recorded points'}
                </StatusBadge>
            </div>
            <div className="journey-location-strip" aria-live="polite">
                <MapPin size={16} aria-hidden="true" />
                <div>
                    <strong>{pointLocation(point)}</strong>
                    <small>
                        {point
                            ? `${focusedEvent?.title ?? `Recorded point ${current + 1}`} · ${coordinates(point)} · ${formatTime(point.at)}`
                            : trip.consent_blocked
                              ? 'Positions are withheld because tracking consent is not in place.'
                              : trip.is_personal
                                ? 'Positions are withheld for personal trips.'
                                : 'The tracker recorded no positions for this trip.'}
                    </small>
                </div>
                {point && (
                    <StatusBadge variant="neutral">
                        {point.address
                            ? 'Approximate address'
                            : 'Coordinates only'}
                    </StatusBadge>
                )}
            </div>
            <div className="journey-route-map">
                {count ? (
                    <>
                        <LeafletMap
                            center={center}
                            height="100%"
                            zoom={14}
                            autoFit
                            fitMarkers={false}
                            observeResize
                            focus={mapFocus}
                            onMarkerClick={onMarkerClick}
                            polyline={route}
                            polylineOptions={polylineOptions}
                            markers={markers}
                        />
                        <div className="journey-map-legend">
                            <span className="legend-dot" aria-hidden="true" />
                            Recorded positions · lines between them are not
                            verified roads
                            {detail.downsampled
                                ? ` · showing ${count.toLocaleString('en-NZ')} of ${detail.recorded_points.toLocaleString('en-NZ')}`
                                : ''}
                        </div>
                    </>
                ) : (
                    <div className="journey-map-empty">
                        <MapPin size={30} aria-hidden="true" />
                        <strong>
                            {trip.consent_blocked || trip.is_personal
                                ? 'Location withheld'
                                : 'No recorded positions'}
                        </strong>
                        <span>
                            {trip.consent_blocked
                                ? 'Tracking consent was not in place, so no positions were kept for this trip.'
                                : trip.is_personal
                                  ? 'This was a personal trip, so its route and places are not shown.'
                                  : 'The trip was detected, but no positions were recorded during it.'}
                        </span>
                    </div>
                )}
            </div>
            <div className="journey-playback">
                <Button
                    variant="outline"
                    size="sm"
                    disabled={count < 2}
                    onClick={onTogglePlay}
                >
                    {playing ? 'Pause replay' : 'Play samples'}
                </Button>
                <select
                    className="select"
                    aria-label="Replay speed"
                    value={rate}
                    onChange={(event) => onRate(Number(event.target.value))}
                >
                    <option value="1">1×</option>
                    <option value="2">2×</option>
                </select>
                <small>
                    {point ? formatTime(point.at) : 'Time not recorded'}
                </small>
                <Button
                    size="icon"
                    variant="outline"
                    aria-label="Previous recorded point"
                    disabled={current === 0}
                    onClick={() => onSample(current - 1)}
                >
                    <ArrowLeft size={16} />
                </Button>
                <label>
                    Recorded point
                    <input
                        type="range"
                        aria-label="Journey recorded point"
                        min={0}
                        max={Math.max(0, count - 1)}
                        value={current}
                        disabled={count < 2}
                        onChange={(event) =>
                            onSample(Number(event.target.value))
                        }
                    />
                </label>
                <Button
                    size="icon"
                    variant="outline"
                    aria-label="Next recorded point"
                    disabled={current >= count - 1}
                    onClick={() => onSample(current + 1)}
                >
                    <ArrowRight size={16} />
                </Button>
                <strong>
                    {point?.speed_kph !== null && point?.speed_kph !== undefined
                        ? Math.round(point.speed_kph)
                        : '—'}{' '}
                    <small>km/h</small>
                </strong>
            </div>
            <div className="journey-endpoints">
                <div>
                    <span aria-hidden="true">A</span>
                    <div>
                        <strong>{startLabel(trip)}</strong>
                        <small>
                            {endpointCaption(
                                trip.start,
                                startTime,
                                trip.stop_after_minutes,
                                'start',
                            )}
                        </small>
                    </div>
                </div>
                <div>
                    <span aria-hidden="true">B</span>
                    <div>
                        <strong>{endLabel(trip)}</strong>
                        <small>
                            {endpointCaption(
                                trip.end,
                                endTime,
                                trip.stop_after_minutes,
                                'end',
                            )}
                        </small>
                    </div>
                </div>
            </div>
        </section>
    );
}
