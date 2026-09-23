import {
    DateTimeField,
    localDateTimeLabel,
    validLocalDateTime,
} from '@/components/fleet-assets/maintenance/date-time-field';
import { Button } from '@/components/ui/button';
import {
    ArrowUpRight,
    ClipboardCheck,
    Gauge,
    MapPin,
    ShieldAlert,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import {
    confirmedDriver,
    driverAt,
    eventKey,
    resolveSpeedLimit,
    reviewedScore,
    type DrivingState,
} from './driving-workflows';
import type { VehicleModel } from './operations';
import { journeys } from './trip-data';
import { Badge, Notice, Row } from './ui';

export function ReviewWorkspace({
    model: m,
    onTrip,
}: {
    model: VehicleModel;
    onTrip: (id: string) => void;
}) {
    const [tripId, setTripId] = useState(journeys[1].id);
    const trip = journeys.find((t) => t.id === tripId)!;
    return (
        <div className="telemetry-studio">
            <div className="activity-title">
                <div>
                    <span className="studio-eyebrow">
                        DRIVER & EVENT REVIEW
                    </span>
                    <h2 className="text-section-title">
                        Close the evidence gaps
                    </h2>
                </div>
                <select
                    aria-label="Review trip"
                    value={tripId}
                    onChange={(e) => setTripId(e.target.value)}
                >
                    {journeys.map((t) => (
                        <option key={t.id} value={t.id}>
                            {t.id} · {t.driver}
                        </option>
                    ))}
                </select>
            </div>
            <div className="telemetry-two">
                <section className="studio-card">
                    <div className="activity-title">
                        <h3>
                            <UserRound size={18} /> Driver and handovers
                        </h3>
                        <Button
                            variant="outline"
                            onClick={() => onTrip(trip.id)}
                        >
                            View trip <ArrowUpRight size={14} />
                        </Button>
                    </div>
                    <Row title="Planned assignment" value={trip.driver} />
                    <Row
                        title="Confirmed whole-trip driver"
                        value={
                            confirmedDriver(trip, m.data.driving) ||
                            'Not confirmed / split journey'
                        }
                    />
                    {(m.data.driving.assignments[trip.id] || []).map((s) => (
                        <Row
                            key={s.point}
                            title={`From point ${s.point + 1}`}
                            value={s.driver}
                            sub={s.reason}
                        />
                    ))}
                    <Button
                        disabled={!m.canManage}
                        onClick={() => m.driving.assign(trip)}
                    >
                        Confirm driver / handover
                    </Button>
                    <details>
                        <summary>Assignment history</summary>
                        {(m.data.driving.assignmentHistory[trip.id] || []).map(
                            (h, i) => (
                                <p key={i}>{h}</p>
                            ),
                        )}
                    </details>
                </section>
                <section className="studio-card">
                    <span className="studio-eyebrow">
                        RECALCULATED TRIP SCORE
                    </span>
                    <strong className="review-score">
                        {reviewedScore(trip, m.data.driving) ?? '—'}
                        <small>/100</small>
                    </strong>
                    <p>
                        Dismissed events are excluded. A disputed event
                        withholds the score until resolved. The original
                        telemetry is retained.
                    </p>
                    <Badge>SCORE-DEMO-v{m.data.driving.policy.version}</Badge>
                    <Button
                        variant="outline"
                        disabled={!m.canManage}
                        onClick={m.driving.policy}
                    >
                        Review scoring policy
                    </Button>
                    <details>
                        <summary>Policy history</summary>
                        {m.data.driving.policy.history.length ? (
                            m.data.driving.policy.history.map((h, i) => (
                                <p key={i}>{h}</p>
                            ))
                        ) : (
                            <p>Current illustrative policy has no changes.</p>
                        )}
                    </details>
                </section>
            </div>
            <section className="studio-card review-event-list">
                <h3>Review individual events</h3>
                {trip.events
                    .map((e, i) => ({ e, i }))
                    .filter(
                        ({ e }) => e.kind === 'driving' || e.kind === 'speed',
                    )
                    .map(({ e, i }) => {
                        const r = m.data.driving.reviews[eventKey(trip, i)];
                        return (
                            <div key={i}>
                                <span className="event-symbol">
                                    <ClipboardCheck size={20} />
                                </span>
                                <div>
                                    <strong>
                                        {e.title} · {e.at}
                                    </strong>
                                    <p>{e.detail}</p>
                                    <small>
                                        Driver at event:{' '}
                                        {driverAt(
                                            trip,
                                            m.data.driving,
                                            e.point,
                                        ) || 'Unconfirmed'}{' '}
                                        · Original source retained
                                    </small>
                                    {r && (
                                        <p>
                                            {r.reviewer}: {r.reason}
                                        </p>
                                    )}
                                    {r && (
                                        <details>
                                            <summary>Review history</summary>
                                            {r.history.map((h, j) => (
                                                <p key={j}>{h}</p>
                                            ))}
                                        </details>
                                    )}
                                </div>
                                <Badge
                                    tone={
                                        r?.outcome === 'Dismissed'
                                            ? 'neutral'
                                            : r?.outcome === 'Confirmed'
                                              ? 'success'
                                              : 'warning'
                                    }
                                >
                                    {r?.outcome || 'Unreviewed'}
                                </Badge>
                                <Button
                                    variant="outline"
                                    disabled={!m.canManage}
                                    onClick={() => m.driving.review(trip, i)}
                                >
                                    Review event
                                </Button>
                            </div>
                        );
                    })}
                {!trip.events.some(
                    (e) => e.kind === 'driving' || e.kind === 'speed',
                ) && <p>No driving events to review for this journey.</p>}
            </section>
            <section className="studio-card coaching-workspace">
                <div className="activity-title">
                    <div>
                        <span className="studio-eyebrow">
                            COACHING & FOLLOW-THROUGH
                        </span>
                        <h3>From review to improvement</h3>
                    </div>
                    <Button
                        disabled={!m.canManage}
                        onClick={() => m.driving.coach(trip)}
                    >
                        Create coaching follow-up
                    </Button>
                </div>
                {m.data.driving.coaching.length ? (
                    m.data.driving.coaching.map((c) => (
                        <div className="coaching-record" key={c.id}>
                            <div>
                                <strong>
                                    {c.id} · {c.trip}
                                </strong>
                                <p>
                                    {c.owner} · review {c.due}
                                </p>
                                <p>{c.outcome}</p>
                                <details>
                                    <summary>Response history</summary>
                                    {c.history.map((h, i) => (
                                        <p key={i}>{h}</p>
                                    ))}
                                </details>
                            </div>
                            <Badge
                                tone={
                                    c.status === 'Completed'
                                        ? 'success'
                                        : 'info'
                                }
                            >
                                {c.status}
                            </Badge>
                            {c.status !== 'Completed' && (
                                <Button
                                    variant="outline"
                                    disabled={!m.canManage}
                                    onClick={() =>
                                        m.driving.coachAction(
                                            c.id,
                                            c.status === 'Assigned'
                                                ? 'Acknowledge'
                                                : 'Complete',
                                        )
                                    }
                                >
                                    {c.status === 'Assigned'
                                        ? 'Acknowledge'
                                        : 'Record outcome'}
                                </Button>
                            )}
                        </div>
                    ))
                ) : (
                    <p className="muted">
                        Create an owned follow-up from the selected trip.
                        Acknowledgement and completion retain the original
                        evidence.
                    </p>
                )}
            </section>
        </div>
    );
}

export function SpeedSources({
    model: m,
    onNav,
}: {
    model: VehicleModel;
    onNav: (view: string, sub?: string) => void;
}) {
    const [at, setAt] = useState('2026-09-21T08:07'),
        [segment, setSegment] = useState('SEG-DEMO-12'),
        [direction, setDirection] = useState('Outbound'),
        [speed, setSpeed] = useState(68),
        [duration, setDuration] = useState(42),
        [result, setResult] = useState('');
    const resolved = resolveSpeedLimit(
            m.data.driving,
            at,
            segment,
            direction,
            m.data.alertPlan.speed,
        ),
        trigger = resolved.limit + m.data.alertPlan.speedTolerance;
    const evaluate = () => {
        if (
            !validLocalDateTime(at) ||
            !Number.isFinite(speed) ||
            speed < 0 ||
            !Number.isFinite(duration) ||
            duration < 0
        ) {
            setResult('Enter a valid observation, speed and duration.');
            return;
        }
        if (speed <= trigger || duration < m.data.alertPlan.speedDuration) {
            setResult(
                'No qualifying overspeed episode for the selected evidence and rule. Nothing routed.',
            );
            return;
        }
        m.telemetry.simulateAlert('Overspeed threshold', false, {
            id: `LIMIT-EVAL-${at}-${segment}-${direction}`,
            source: segment === 'SEG-DEMO-12' ? 'TRIP-DEMO-12' : 'TRIP-DEMO-11',
            observed: localDateTimeLabel(at),
            lat: segment === 'SEG-DEMO-12' ? -41.281 : -41.296,
            lng: segment === 'SEG-DEMO-12' ? 174.778 : 174.779,
            detail: `${speed} km/h for ${duration}s above ${trigger} km/h trigger. ${resolved.road === null ? 'Fleet threshold only; posted limit unknown' : resolved.road <= m.data.alertPlan.speed ? 'Road-limit criterion' : 'Fleet threshold is lower than road limit'}. Road ${resolved.road ?? 'unknown'}; fleet ${m.data.alertPlan.speed}; tolerance ${m.data.alertPlan.speedTolerance}. ${resolved.source}; ${resolved.record}; ${segment} ${direction}; effective sample ${at}. Synthetic evaluation; source snapshot retained.`,
        });
        onNav('map', 'alerts');
    };
    return (
        <div className="telemetry-studio">
            <div className="activity-title">
                <div>
                    <span className="studio-eyebrow">SPEED LIMIT SOURCES</span>
                    <h2 className="text-section-title">
                        Mapped limits, with reviewed local evidence
                    </h2>
                    <p className="muted">
                        Road limits and fleet policy remain separate. Every
                        decision retains its source.
                    </p>
                </div>
                <Button disabled={!m.canManage} onClick={m.driving.manualLimit}>
                    Add manual speed limit
                </Button>
            </div>
            <Notice title="Provider integration preview">
                No live speed-limit provider is connected. These controls
                simulate a matched response or an outage; the map imagery alone
                cannot supply a verified speed limit.
            </Notice>
            <details className="speed-source-notes">
                <summary>OpenStreetMap, Google and temporary limits</summary>
                <p>
                    <strong>OpenStreetMap:</strong> road data can include
                    maxspeed, direction and conditional limits. Missing or
                    uncertain matches stay unknown. Map tiles do not contain
                    this usable road data; a road-matching service and dated
                    data feed are required.{' '}
                    <a
                        href="https://wiki.openstreetmap.org/wiki/Maxspeed"
                        target="_blank"
                        rel="noreferrer"
                    >
                        OSM speed data
                    </a>
                </p>
                <p>
                    <strong>Google Roads:</strong> speed limits require an Asset
                    Tracking licence, are not real-time and may be outdated.
                    Google restricts displaying Roads results on non-Google
                    maps. This OSM preview does not mix Google road data into
                    its map.{' '}
                    <a
                        href="https://developers.google.com/maps/documentation/roads/speed-limits"
                        target="_blank"
                        rel="noreferrer"
                    >
                        Google speed limits
                    </a>
                </p>
                <p>
                    <strong>New Zealand:</strong> validate the baseline against
                    NZTA's register. Temporary restrictions need a suitable feed
                    or reviewed manual evidence with road, direction, start and
                    expiry. If a road limit is unknown, the fleet threshold
                    still applies and the alert says so.
                </p>
            </details>
            <div className="telemetry-two">
                <section className="studio-card">
                    <div className="activity-title">
                        <h3>
                            <MapPin size={18} /> Mapped road data
                        </h3>
                        <Badge tone="warning">Synthetic source</Badge>
                    </div>
                    <label className="speed-field">
                        Preview provider state
                        <select
                            aria-label="Preview speed provider state"
                            value={m.data.driving.provider}
                            disabled={!m.canManage}
                            onChange={(e) =>
                                m.driving.setProvider(
                                    e.target.value as DrivingState['provider'],
                                )
                            }
                        >
                            {[
                                'Fresh matched response',
                                'Stale response',
                                'Ambiguous road match',
                                'Unavailable',
                            ].map((x) => (
                                <option key={x}>{x}</option>
                            ))}
                        </select>
                    </label>
                    <Row
                        title="Matched example"
                        value="60 km/h · segment + direction"
                    />
                    <Row
                        title="Example match confidence"
                        value="96% when fresh"
                    />
                    <Row title="Source record" value="MAP-DEMO-2026-09-21" />
                    <p>
                        Live implementation: road matching + speed-limit
                        provider, source freshness and effective-time checks.
                        Public NZTA data can support the baseline; temporary
                        limits need another source or reviewed local evidence.
                    </p>
                    <details>
                        <summary>Source selection rules</summary>
                        <ol>
                            <li>
                                Approved, unexpired manual entry for this road
                                and direction.
                            </li>
                            <li>Fresh provider limit for a confident match.</li>
                            <li>
                                Road limit unknown. Fleet threshold may still
                                create a fleet-policy alert.
                            </li>
                        </ol>
                        <p>
                            The lower applicable road/fleet threshold is used,
                            plus tolerance. Conflicting manual entries block
                            approval. New rules never rewrite prior alert
                            evidence.
                        </p>
                    </details>
                </section>
                <section className="studio-card speed-evaluation">
                    <div className="activity-title">
                        <h3>
                            <Gauge size={19} /> Evaluate a sample
                        </h3>
                        <Badge>Preview only</Badge>
                    </div>
                    <div className="speed-form-grid">
                        <label className="speed-field">
                            Road segment
                            <select
                                aria-label="Speed sample road segment"
                                value={segment}
                                onChange={(e) => setSegment(e.target.value)}
                            >
                                <option>SEG-DEMO-12</option>
                                <option>SEG-DEMO-11</option>
                            </select>
                        </label>
                        <label className="speed-field">
                            Direction
                            <select
                                aria-label="Speed sample direction"
                                value={direction}
                                onChange={(e) => setDirection(e.target.value)}
                            >
                                <option>Outbound</option>
                                <option>Inbound</option>
                            </select>
                        </label>
                        <DateTimeField
                            id="speed-sample-time"
                            label="Speed sample observed at"
                            value={at}
                            onChange={setAt}
                        />
                        <label className="speed-field">
                            Peak speed · km/h
                            <input
                                type="number"
                                aria-label="Speed sample peak"
                                value={speed}
                                onChange={(e) => setSpeed(+e.target.value)}
                            />
                        </label>
                        <label className="speed-field">
                            Continuous time above trigger · seconds
                            <input
                                type="number"
                                aria-label="Speed sample duration"
                                value={duration}
                                onChange={(e) => setDuration(+e.target.value)}
                            />
                        </label>
                    </div>
                    <Row
                        title="Road limit"
                        value={
                            resolved.road === null
                                ? 'Unknown'
                                : resolved.road + ' km/h'
                        }
                    />
                    <Row title="Applied trigger" value={trigger + ' km/h'} />
                    <Row title="Source" value={resolved.source} />
                    <p className="muted">
                        {resolved.confidence} · {resolved.record}. Duration is
                        explicitly supplied by this synthetic episode;
                        production must derive it from timestamped samples.
                    </p>
                    <Button
                        disabled={!m.canManage || !m.tracker.available}
                        onClick={evaluate}
                    >
                        <ShieldAlert size={16} />
                        Evaluate & route to Control Room
                    </Button>
                    {result && <Notice title={result} />}
                </section>
            </div>
            <section className="studio-card manual-limit-list">
                <h3>Manual limits & approvals</h3>
                {m.data.driving.overrides.length ? (
                    m.data.driving.overrides.map((l) => (
                        <div className="coaching-record" key={l.id}>
                            <div>
                                <strong>
                                    {l.limit} km/h · {l.segment} · {l.direction}
                                </strong>
                                <p>
                                    {l.from.replace('T', ' ')} →{' '}
                                    {l.until.replace('T', ' ')}
                                </p>
                                <p>{l.reason}</p>
                                <div className="source-files">
                                    {m.data.documents
                                        .filter(
                                            (f) => f.owner === l.id && f.url,
                                        )
                                        .map((f) => (
                                            <a
                                                key={f.id}
                                                href={f.url}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                View {f.name} ↗
                                            </a>
                                        ))}
                                </div>
                                <small>
                                    {
                                        m.data.documents.filter(
                                            (f) => f.owner === l.id,
                                        ).length
                                    }{' '}
                                    evidence file(s) · {l.id}
                                </small>
                                <details>
                                    <summary>Review history</summary>
                                    {l.history.map((h, i) => (
                                        <p key={i}>{h}</p>
                                    ))}
                                </details>
                            </div>
                            <Badge>
                                {l.status === 'Approved' && at >= l.until
                                    ? 'Expired at selected time'
                                    : l.status}
                            </Badge>
                            {l.status === 'Pending review' && (
                                <Button
                                    disabled={!m.canManage}
                                    onClick={() => m.driving.limitAction(l.id)}
                                >
                                    Review limit
                                </Button>
                            )}
                            {l.status === 'Approved' && (
                                <Button
                                    variant="outline"
                                    disabled={!m.canManage}
                                    onClick={() =>
                                        m.driving.limitAction(l.id, true)
                                    }
                                >
                                    Retire
                                </Button>
                            )}
                        </div>
                    ))
                ) : (
                    <p>
                        No manual limits. Add a temporary sign, authority update
                        or approved site-road limit with evidence and an expiry.
                    </p>
                )}
            </section>
        </div>
    );
}
