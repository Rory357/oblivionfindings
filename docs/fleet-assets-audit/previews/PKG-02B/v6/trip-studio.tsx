import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Activity,
    ArrowLeft,
    ArrowRight,
    ArrowUpRight,
    BatteryCharging,
    Clock3,
    Gauge,
    MapPin,
    Navigation,
    Route,
    ShieldAlert,
    UserRound,
    Zap,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { driverAt, reviewedScore } from './driving-workflows';
import LeafletMap from './interactive-map';
import type { VehicleModel } from './operations';
import { dateLabel } from './operations';
import { useSessionState } from './session-state';
import {
    drivingEventCount,
    journeys,
    speedEvidence,
    speedOrigin,
} from './trip-data';
import { TripExport } from './trip-export';
import { Badge } from './ui';
type Props = {
    model: VehicleModel;
    dark: boolean;
    noTracker: boolean;
    focusTrip?: string;
    focusPoint?: number;
    onNav: (view: string, sub?: string) => void;
};
export function TripStudio({
    model: m,
    dark,
    noTracker,
    focusTrip,
    focusPoint = 0,
    onNav,
}: Props) {
    const [exportOpen, setExportOpen] = useState(false);
    const tripScore = (t: (typeof journeys)[number]) =>
        reviewedScore(t, m.data.driving);
    const [selected, setSelected] = useSessionState(
            'trip-selection',
            focusTrip || journeys[1].id,
        ),
        [query, setQuery] = useSessionState('trip-query', ''),
        [day, setDay] = useSessionState('trip-day', 'all'),
        [point, setPoint] = useState(focusPoint),
        [eventsFilter, setEventsFilter] = useState('All events');
    const [from, setFrom] = useSessionState('trip-from', ''),
        [to, setTo] = useSessionState('trip-to', ''),
        [driverFilter, setDriverFilter] = useSessionState(
            'trip-driver',
            'All drivers',
        ),
        [eventFilter, setEventFilter] = useSessionState(
            'trip-event',
            'All types',
        ),
        [page, setPage] = useState(0),
        [playing, setPlaying] = useState(false),
        [playRate, setPlayRate] = useState(1);
    useEffect(() => {
        setPage(0);
        setPlaying(false);
    }, [query, day, from, to, driverFilter, eventFilter]);
    useEffect(() => {
        if (focusTrip) {
            setSelected(focusTrip);
            setPoint(focusPoint);
            setQuery('');
            setDay('all');
            setFrom('');
            setTo('');
            setDriverFilter('All drivers');
            setEventFilter('All types');
        }
    }, [focusTrip, focusPoint]);
    useEffect(() => {
        if (!playing) return;
        const id = window.setInterval(
            () =>
                setPoint((p) => {
                    if (p >= 4) {
                        setPlaying(false);
                        return p;
                    }
                    return p + 1;
                }),
            1500 / playRate,
        );
        return () => window.clearInterval(id);
    }, [playing, playRate]);
    const visible = noTracker
        ? []
        : journeys.filter(
              (t) =>
                  (day === 'all' || day === t.day) &&
                  (!from || t.day >= from) &&
                  (!to || t.day <= to) &&
                  (driverFilter === 'All drivers' ||
                      t.driver === driverFilter ||
                      (m.data.driving.assignments[t.id] || []).some(
                          (s) => s.driver === driverFilter,
                      )) &&
                  (eventFilter === 'All types' ||
                      (eventFilter === 'Overspeed'
                          ? !!t.overspeed
                          : eventFilter === 'Vehicle faults'
                            ? t.events.some((e) => e.kind === 'power')
                            : t.coverage < 90)) &&
                  `${t.driver} ${t.id} ${t.from} ${t.to}`
                      .toLowerCase()
                      .includes(query.toLowerCase()),
          );
    const trip = visible.find((t) => t.id === selected) || visible[0];
    const choose = (id: string) => {
        setSelected(id);
        setPlaying(false);
        setPoint(0);
        setEventsFilter('All events');
    };
    const sample = trip ? Math.min(point, trip.path.length - 1) : 0;
    return (
        <div className="journey-studio">
            {exportOpen && (
                <TripExport
                    trips={visible}
                    state={m.data.driving}
                    onClose={() => setExportOpen(false)}
                />
            )}
            <div className="activity-title">
                <div>
                    <span className="studio-eyebrow">
                        VH-014 · JOURNEY EXPLORER
                    </span>
                    <h2 className="text-section-title">Trip history</h2>
                    <p className="muted">
                        Replay recorded points. Understand behaviour. Follow the
                        source.
                    </p>
                </div>
                <div className="journey-search">
                    <Input
                        aria-label="Search trips"
                        placeholder="Driver, route or reference…"
                        value={query}
                        onChange={(e) => {
                            setQuery(e.target.value);
                            setPoint(0);
                        }}
                    />
                    <select
                        aria-label="Trip date filter"
                        value={day}
                        onChange={(e) => {
                            setDay(e.target.value);
                            setPoint(0);
                        }}
                    >
                        <option value="all">All recorded dates</option>
                        {journeys.map((t) => (
                            <option key={t.id} value={t.day}>
                                {dateLabel(t.day)}
                            </option>
                        ))}
                    </select>
                </div>
            </div>
            <div className="journey-refine">
                <label>
                    From
                    <DatePicker
                        id="trip-from"
                        label="Trip date from"
                        value={from}
                        onChange={setFrom}
                    />
                </label>
                <label>
                    To
                    <DatePicker
                        id="trip-to"
                        label="Trip date to"
                        value={to}
                        onChange={setTo}
                    />
                </label>
                <label>
                    Driver
                    <select
                        aria-label="Trip driver filter"
                        value={driverFilter}
                        onChange={(e) => setDriverFilter(e.target.value)}
                    >
                        {[
                            'All drivers',
                            'Jamie Taylor',
                            'Alex Morgan',
                            'Unassigned',
                        ].map((x) => (
                            <option key={x}>{x}</option>
                        ))}
                    </select>
                </label>
                <label>
                    Event type
                    <select
                        aria-label="Trip type filter"
                        value={eventFilter}
                        onChange={(e) => setEventFilter(e.target.value)}
                    >
                        {[
                            'All types',
                            'Overspeed',
                            'Vehicle faults',
                            'Partial coverage',
                        ].map((x) => (
                            <option key={x}>{x}</option>
                        ))}
                    </select>
                </label>
                <Button
                    variant="ghost"
                    onClick={() => {
                        setFrom('');
                        setTo('');
                        setDriverFilter('All drivers');
                        setEventFilter('All types');
                        setQuery('');
                        setDay('all');
                    }}
                >
                    Reset filters
                </Button>
                <Button
                    variant="outline"
                    onClick={() => setExportOpen(true)}
                    disabled={!visible.length}
                >
                    Export PDF / Excel
                </Button>
            </div>
            <div className="journey-totals">
                <span>
                    <Route />
                    {visible.length} trips
                </span>
                <span>
                    <Navigation />
                    {visible.reduce((s, t) => s + t.distance, 0).toFixed(1)} km
                    estimated
                </span>
                <span>
                    <Clock3 />
                    {visible.reduce((s, t) => s + t.minutes, 0)} min recorded
                </span>
                <span>
                    <Activity />
                    {visible.reduce((s, t) => s + drivingEventCount(t), 0)}{' '}
                    driving events
                </span>
                <Badge>Historical · synthetic</Badge>
            </div>
            {trip ? (
                <>
                    <div
                        className="journey-selector"
                        role="group"
                        aria-label="Select vehicle trip"
                    >
                        {visible.slice(page * 2, page * 2 + 2).map((t) => (
                            <button
                                key={t.id}
                                aria-pressed={t.id === trip.id}
                                onClick={() => choose(t.id)}
                            >
                                <div>
                                    <span>
                                        {dateLabel(t.day)} · {t.start}
                                    </span>
                                    <Badge
                                        tone={
                                            t.coverage < 90 ? 'warning' : 'info'
                                        }
                                    >
                                        {t.coverage < 90
                                            ? 'Partial trail'
                                            : t.distance + ' km'}
                                    </Badge>
                                </div>
                                <strong>
                                    {t.from} <ArrowRight size={13} /> {t.to}
                                </strong>
                                <small>
                                    <UserRound size={13} />
                                    {t.driver}{' '}
                                    <span>
                                        {t.minutes} min · {t.id}
                                    </span>
                                </small>
                            </button>
                        ))}
                    </div>
                    <div className="replay-controls">
                        <Button
                            variant="outline"
                            disabled={page === 0}
                            onClick={() => setPage((p) => p - 1)}
                        >
                            Previous trips
                        </Button>
                        <span>
                            Page {page + 1} of{' '}
                            {Math.max(1, Math.ceil(visible.length / 2))}
                        </span>
                        <Button
                            variant="outline"
                            disabled={(page + 1) * 2 >= visible.length}
                            onClick={() => setPage((p) => p + 1)}
                        >
                            Next trips
                        </Button>
                    </div>
                    <div className="journey-workspace">
                        <section className="studio-card journey-map-panel">
                            <div className="journey-map-title">
                                <div>
                                    <span className="studio-eyebrow">
                                        {trip.id}
                                    </span>
                                    <h3>Recorded journey</h3>
                                </div>
                                <Badge tone="info">
                                    Point {sample + 1} of {trip.path.length}
                                </Badge>
                            </div>
                            <div className="journey-route-map">
                                <LeafletMap
                                    key={trip.id}
                                    center={trip.path[2]}
                                    height="100%"
                                    zoom={14}
                                    autoFit
                                    darkMode={dark}
                                    polyline={trip.path}
                                    polylineOptions={{
                                        color: 'var(--primary)',
                                        ...(trip.coverage < 90
                                            ? { dashArray: '7 7' }
                                            : {}),
                                        showArrows: true,
                                        showEndpoints: true,
                                    }}
                                    markers={[
                                        {
                                            id: 'playback',
                                            ...trip.path[sample],
                                            title: 'Recorded point',
                                            type: 'vehicle',
                                            status: 'historical',
                                            popup: `${trip.id} · historical sample ${sample + 1}`,
                                            stats: [
                                                [
                                                    'Speed',
                                                    trip.speeds[sample] +
                                                        ' km/h',
                                                ],
                                                [
                                                    'Ignition',
                                                    sample ===
                                                    trip.path.length - 1
                                                        ? 'Off'
                                                        : 'On',
                                                ],
                                                [
                                                    'Motion',
                                                    trip.speeds[sample] > 0
                                                        ? 'Moving'
                                                        : 'Stopped',
                                                ],
                                                [
                                                    'Voltage',
                                                    trip.events.some(
                                                        (e) =>
                                                            e.point ===
                                                                sample &&
                                                            e.kind === 'power',
                                                    )
                                                        ? '11.6 V'
                                                        : sample ===
                                                            trip.path.length - 1
                                                          ? '12.6 V'
                                                          : '14.1 V',
                                                ],
                                            ],
                                        },
                                    ]}
                                />
                                <div className="journey-map-legend">
                                    <span className="legend-dot" />
                                    Recorded samples · illustrative route
                                </div>
                            </div>
                            <div className="journey-playback">
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        if (sample === 4) setPoint(0);
                                        setPlaying(!playing);
                                    }}
                                >
                                    {playing ? 'Pause replay' : 'Play samples'}
                                </Button>
                                <select
                                    aria-label="Replay speed"
                                    value={playRate}
                                    onChange={(e) =>
                                        setPlayRate(+e.target.value)
                                    }
                                >
                                    <option value="1">1×</option>
                                    <option value="2">2×</option>
                                </select>
                                <small>
                                    {trip.events.find(
                                        (e) => e.point === sample && e.at,
                                    )?.at ||
                                        (sample === 0
                                            ? trip.start
                                            : sample === 4
                                              ? trip.end
                                              : 'Time not recorded')}
                                </small>
                                <Button
                                    size="icon"
                                    variant="outline"
                                    aria-label="Previous recorded point"
                                    disabled={sample === 0}
                                    onClick={() => setPoint(sample - 1)}
                                >
                                    <ArrowLeft size={16} />
                                </Button>
                                <label>
                                    Recorded point{' '}
                                    <input
                                        type="range"
                                        aria-label="Journey recorded point"
                                        min="0"
                                        max={trip.path.length - 1}
                                        value={sample}
                                        onChange={(e) =>
                                            setPoint(+e.target.value)
                                        }
                                    />
                                </label>
                                <Button
                                    size="icon"
                                    variant="outline"
                                    aria-label="Next recorded point"
                                    disabled={sample === trip.path.length - 1}
                                    onClick={() => setPoint(sample + 1)}
                                >
                                    <ArrowRight size={16} />
                                </Button>
                                <strong>
                                    {trip.speeds[sample]} <small>km/h</small>
                                </strong>
                            </div>
                            <div className="journey-endpoints">
                                <div>
                                    <span>A</span>
                                    <div>
                                        <strong>{trip.from}</strong>
                                        <small>
                                            {trip.start} · virtual ignition on
                                        </small>
                                    </div>
                                </div>
                                <div>
                                    <span>B</span>
                                    <div>
                                        <strong>{trip.to}</strong>
                                        <small>
                                            {trip.end} · virtual ignition off
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </section>
                        <div className="journey-insights">
                            <section className="studio-card trip-score-card">
                                <div className="trip-score-number">
                                    {tripScore(trip) ?? '—'}
                                    <small>/100</small>
                                </div>
                                <div>
                                    <span className="studio-eyebrow">
                                        TRIP BEHAVIOUR
                                    </span>
                                    <h3>
                                        {tripScore(trip) === null
                                            ? 'Score withheld'
                                            : drivingEventCount(trip)
                                              ? 'Review driving events'
                                              : 'No harsh events recorded'}
                                    </h3>
                                    <p>
                                        {trip.coverage}% sample coverage ·{' '}
                                        {tripScore(trip) === null
                                            ? 'score withheld'
                                            : 'illustrative scoring'}
                                    </p>
                                </div>
                            </section>
                            <section className="studio-card trip-facts">
                                {[
                                    [
                                        Navigation,
                                        'Distance',
                                        trip.distance + ' km',
                                    ],
                                    [Clock3, 'Duration', trip.minutes + ' min'],
                                    [
                                        Gauge,
                                        'Maximum speed',
                                        trip.max + ' km/h',
                                    ],
                                    [Zap, 'Idle time', trip.idle + ' min'],
                                    [Activity, 'Braking', String(trip.braking)],
                                    [
                                        Gauge,
                                        'Overspeed episodes',
                                        String(trip.overspeed ? 1 : 0),
                                    ],
                                    [
                                        Clock3,
                                        'Over trigger',
                                        (trip.overspeed?.seconds || 0) + ' sec',
                                    ],
                                    [
                                        Activity,
                                        'Acceleration',
                                        String(trip.acceleration),
                                    ],
                                ].map(([Icon, label, value]) => {
                                    const I = Icon as typeof Gauge;
                                    return (
                                        <div key={String(label)}>
                                            <I size={18} />
                                            <small>{String(label)}</small>
                                            <strong>{String(value)}</strong>
                                        </div>
                                    );
                                })}
                            </section>
                            <Button
                                variant="outline"
                                disabled={!m.canManage}
                                onClick={() => m.driving.assign(trip)}
                            >
                                Confirm driver / handover
                            </Button>
                            <section className="studio-card trip-speed">
                                <div>
                                    <h3>Speed through the trip</h3>
                                    <small>Recorded samples · km/h</small>
                                </div>
                                <svg
                                    viewBox="0 0 320 105"
                                    role="img"
                                    aria-label={`Recorded speed samples: ${trip.speeds.join(', ')} kilometres per hour`}
                                >
                                    <path
                                        d="M15 85H305M15 45H305M15 5H305"
                                        stroke="var(--border)"
                                        fill="none"
                                    />
                                    <polyline
                                        points={trip.speeds
                                            .map(
                                                (v, i) =>
                                                    `${15 + i * 72},${85 - v * (75 / Math.max(60, trip.max + 10))}`,
                                            )
                                            .join(' ')}
                                        fill="none"
                                        stroke="var(--primary)"
                                        strokeWidth="3"
                                    />
                                    {trip.speeds.map((v, i) => (
                                        <circle
                                            key={i}
                                            cx={15 + i * 72}
                                            cy={
                                                85 -
                                                v *
                                                    (75 /
                                                        Math.max(
                                                            60,
                                                            trip.max + 10,
                                                        ))
                                            }
                                            r={i === sample ? 6 : 3}
                                            fill="var(--primary)"
                                        />
                                    ))}
                                    <text x="15" y="103">
                                        Departure
                                    </text>
                                    {trip.overspeed && (
                                        <>
                                            <line
                                                x1="15"
                                                x2="305"
                                                y1={
                                                    85 -
                                                    (trip.overspeed.threshold +
                                                        trip.overspeed
                                                            .tolerance) *
                                                        (75 /
                                                            Math.max(
                                                                60,
                                                                trip.max + 10,
                                                            ))
                                                }
                                                y2={
                                                    85 -
                                                    (trip.overspeed.threshold +
                                                        trip.overspeed
                                                            .tolerance) *
                                                        (75 /
                                                            Math.max(
                                                                60,
                                                                trip.max + 10,
                                                            ))
                                                }
                                                stroke="var(--status-warning)"
                                                strokeDasharray="4 3"
                                            />
                                            <text x="16" y="12">
                                                Trigger{' '}
                                                {trip.overspeed.threshold +
                                                    trip.overspeed
                                                        .tolerance}{' '}
                                                km/h
                                            </text>
                                        </>
                                    )}
                                    <text x="260" y="103">
                                        Arrival
                                    </text>
                                </svg>
                                <details>
                                    <summary>Speed source & threshold</summary>
                                    <p>
                                        Selected sample: {trip.speeds[sample]}{' '}
                                        km/h. No road speed limit is inferred.{' '}
                                        {trip.overspeed &&
                                            'Dashed line: fleet threshold + tolerance. Episode duration comes from the source event, not these five summary points.'}
                                    </p>
                                </details>
                            </section>
                            <section className="studio-card trip-driver">
                                <UserRound size={21} />
                                <div>
                                    <strong>
                                        {driverAt(
                                            trip,
                                            m.data.driving,
                                            sample,
                                        ) || trip.driver}
                                    </strong>
                                    <small>
                                        {driverAt(trip, m.data.driving, sample)
                                            ? 'Confirmed for this recorded point'
                                            : trip.driver === 'Unassigned'
                                              ? 'Driver confirmation needed'
                                              : 'Recorded assignment · actual driver unconfirmed'}
                                    </small>
                                </div>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        m.detail('Trip source record', [
                                            ['Vehicle', 'VH-014'],
                                            ['Trip', trip.id],
                                            ['Observation source', trip.source],
                                            [
                                                'Driver',
                                                trip.driver +
                                                    ' · confirm actual driver',
                                            ],
                                            ['Dashboard evidence', trip.odo],
                                            [
                                                'Route',
                                                'Illustrative synthetic samples. Lines between points are not verified roads.',
                                            ],
                                        ])
                                    }
                                >
                                    Source <ArrowUpRight size={13} />
                                </Button>
                            </section>
                        </div>
                    </div>
                    <div className="journey-bottom">
                        <section className="studio-card trip-event-list">
                            <div className="activity-title">
                                <h3>Journey timeline</h3>
                                <select
                                    aria-label="Trip event filter"
                                    value={eventsFilter}
                                    onChange={(e) =>
                                        setEventsFilter(e.target.value)
                                    }
                                >
                                    <option>All events</option>
                                    <option>Driving</option>
                                    <option>Vehicle faults</option>
                                </select>
                            </div>
                            {trip.events
                                .filter(
                                    (e) =>
                                        eventsFilter === 'All events' ||
                                        (eventsFilter === 'Driving'
                                            ? e.kind === 'driving' ||
                                              e.kind === 'speed'
                                            : e.kind === 'power'),
                                )
                                .map((e, i) => (
                                    <button
                                        key={i}
                                        className={
                                            sample === e.point ? 'selected' : ''
                                        }
                                        onClick={() => setPoint(e.point)}
                                    >
                                        <span className="event-symbol">
                                            {e.kind === 'power' ? (
                                                <BatteryCharging size={18} />
                                            ) : e.kind === 'driving' ? (
                                                <Activity size={18} />
                                            ) : (
                                                <Zap size={18} />
                                            )}
                                        </span>
                                        <div>
                                            <strong>{e.title}</strong>
                                            <small>{e.detail}</small>
                                        </div>
                                        <Badge>Point {e.point + 1}</Badge>
                                        <MapPin size={15} />
                                    </button>
                                ))}
                            {!trip.events.some(
                                (e) =>
                                    eventsFilter === 'All events' ||
                                    (eventsFilter === 'Driving'
                                        ? e.kind === 'driving' ||
                                          e.kind === 'speed'
                                        : e.kind === 'power'),
                            ) && (
                                <p className="muted">
                                    No matching events in this trip.
                                </p>
                            )}
                        </section>
                        <section className="studio-card trip-next">
                            <span className="studio-eyebrow">
                                ACT ON THE EVIDENCE
                            </span>
                            <h3>
                                {trip.events.some((e) => e.kind === 'power')
                                    ? 'Review the vehicle fault'
                                    : trip.overspeed
                                      ? 'Review the overspeed event'
                                      : 'Follow up this journey'}
                            </h3>
                            <p>
                                Keep the trip, sensor event and response
                                together. A fault goes to Control Room for
                                assessment before a Maintenance decision.
                            </p>
                            <Button
                                variant="outline"
                                disabled={!m.canManage}
                                onClick={() => m.driving.coach(trip)}
                            >
                                <Clock3 size={16} />
                                Create coaching follow-up
                            </Button>
                            {trip.events.some((e) => e.kind === 'power') && (
                                <Button
                                    disabled={!m.canManage}
                                    onClick={() => {
                                        m.telemetry.simulateAlert(
                                            'Low vehicle voltage',
                                            false,
                                            {
                                                id: trip.id + '-voltage',
                                                source: trip.id,
                                                observed:
                                                    dateLabel(trip.day) +
                                                    ' · ' +
                                                    (trip.events.find(
                                                        (e) =>
                                                            e.kind === 'power',
                                                    )?.at ||
                                                        'Time not recorded'),
                                                lat: trip.path[3].lat,
                                                lng: trip.path[3].lng,
                                            },
                                        );
                                        onNav('map', 'alerts');
                                    }}
                                >
                                    <ShieldAlert size={16} />
                                    Preview fault → Control Room
                                </Button>
                            )}
                            {trip.overspeed && (
                                <>
                                    <p>{speedEvidence(trip)}</p>
                                    <Button
                                        disabled={!m.canManage}
                                        onClick={() => {
                                            m.telemetry.simulateAlert(
                                                'Overspeed threshold',
                                                false,
                                                speedOrigin(trip),
                                            );
                                            onNav('map', 'alerts');
                                        }}
                                    >
                                        <ShieldAlert size={16} />
                                        Preview overspeed → Control Room
                                    </Button>
                                </>
                            )}
                            <details>
                                <summary>Score, coverage and distance</summary>
                                <p>
                                    Current policy: 100 − braking×
                                    {m.data.driving.policy.braking} −
                                    acceleration×
                                    {m.data.driving.policy.acceleration} −
                                    overspeed episodes×
                                    {m.data.driving.policy.speed} − round(idle
                                    minutes×{m.data.driving.policy.idle}).
                                    Scores are withheld below the illustrative{' '}
                                    {m.data.driving.policy.coverage}% coverage
                                    gate. These rules require approval. GPS
                                    distance and paired dashboard observations
                                    remain separate.
                                </p>
                            </details>
                        </section>
                    </div>
                </>
            ) : (
                <div className="studio-card telemetry-empty">
                    <Route size={35} />
                    <h3>
                        {noTracker
                            ? 'No tracker trip history'
                            : 'No matching trips'}
                    </h3>
                    <p>
                        {noTracker
                            ? 'Manual mileage and booking custody remain available.'
                            : 'Try another driver, route or date.'}
                    </p>
                </div>
            )}
        </div>
    );
}
