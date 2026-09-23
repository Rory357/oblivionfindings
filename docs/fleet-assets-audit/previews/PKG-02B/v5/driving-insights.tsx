import { Button } from '@/components/ui/button';
import {
    Activity,
    ArrowUpRight,
    BarChart3,
    Clock3,
    Gauge,
    Navigation,
    ShieldAlert,
} from 'lucide-react';
import { useState } from 'react';
import type { VehicleModel } from './operations';
import { dateLabel } from './operations';
import {
    drivingEventCount,
    journeys,
    speedOrigin,
    tripScore,
} from './trip-data';
import { Badge, Modal, Notice, Row } from './ui';

export function DrivingInsights({
    model: m,
    onTrip,
    onNav,
}: {
    model: VehicleModel;
    onTrip: (id: string) => void;
    onNav: (view: string, sub?: string) => void;
}) {
    const [period, setPeriod] = useState('Last 7 days'),
        [driver, setDriver] = useState('All assignments'),
        [eventType, setEventType] = useState('All driving events'),
        [rules, setRules] = useState(false);
    const trips = journeys.filter(
        (t) =>
            (period !== 'Today' || t.day === '2026-09-22') &&
            (driver === 'All assignments' || t.driver === driver),
    );
    const eligible = trips.filter((t) => tripScore(t) !== null);
    const distance = trips.reduce((n, t) => n + t.distance, 0),
        eligibleDistance = eligible.reduce((n, t) => n + t.distance, 0);
    const score = eligibleDistance
        ? Math.round(
              eligible.reduce((n, t) => n + tripScore(t)! * t.distance, 0) /
                  eligibleDistance,
          )
        : null;
    const events = trips.flatMap((t) =>
        t.events
            .filter((e) => e.kind === 'driving' || e.kind === 'speed')
            .map((e) => ({ ...e, trip: t })),
    );
    const shownEvents = events.filter(
        (e) => eventType === 'All driving events' || e.title === eventType,
    );
    const speeding = trips.filter((t) => t.overspeed),
        seconds = speeding.reduce((n, t) => n + t.overspeed!.seconds, 0);
    const idle = trips.reduce((n, t) => n + t.idle, 0),
        minutes = trips.reduce((n, t) => n + t.minutes, 0);
    const rate = eligibleDistance
        ? (eligible.reduce((n, t) => n + drivingEventCount(t), 0) /
              eligibleDistance) *
          100
        : null;
    const days = [...new Set(trips.map((t) => t.day))].sort().map((day) => {
        const dayTrips = eligible.filter((t) => t.day === day),
            km = dayTrips.reduce((n, t) => n + t.distance, 0);
        return {
            day,
            score: km
                ? Math.round(
                      dayTrips.reduce(
                          (n, t) => n + tripScore(t)! * t.distance,
                          0,
                      ) / km,
                  )
                : null,
        };
    });
    const previewSpeed = (id: string) => {
        const trip = journeys.find((t) => t.id === id)!;
        m.telemetry.simulateAlert(
            'Overspeed threshold',
            false,
            speedOrigin(trip),
        );
        onNav('map', 'alerts');
    };
    return (
        <div className="telemetry-studio analytics-studio">
            <div className="activity-title">
                <div>
                    <span className="studio-eyebrow">DRIVING INSIGHTS</span>
                    <h2 className="text-section-title">Driving analytics</h2>
                    <p className="muted">
                        Trip trends, driving events and response workflows.
                    </p>
                </div>
                <div className="telemetry-filters">
                    <select
                        aria-label="Driving period"
                        value={period}
                        onChange={(e) => setPeriod(e.target.value)}
                    >
                        <option>Last 7 days</option>
                        <option>Today</option>
                    </select>
                    <select
                        aria-label="Driver assignment"
                        value={driver}
                        onChange={(e) => setDriver(e.target.value)}
                    >
                        <option>All assignments</option>
                        <option>Jamie Taylor</option>
                        <option>Alex Morgan</option>
                        <option>Unassigned</option>
                    </select>
                    <Button variant="outline" onClick={() => setRules(true)}>
                        <BarChart3 size={16} />
                        Score rules
                    </Button>
                </div>
            </div>
            {!m.tracker.available ? (
                <Notice title="No driving data">
                    A tracker and sufficient journey coverage are needed.
                    Missing data does not produce a perfect score.
                </Notice>
            ) : (
                <>
                    <div className="analytics-summary">
                        <section className="studio-card driving-score">
                            <div
                                className="score-ring"
                                style={
                                    {
                                        '--score': `${(driver === 'All assignments' ? (score ?? 0) : 0) * 3.6}deg`,
                                    } as React.CSSProperties
                                }
                            >
                                <strong>
                                    {driver === 'All assignments'
                                        ? (score ?? '—')
                                        : '—'}
                                    <small>/100</small>
                                </strong>
                            </div>
                            <div>
                                <span className="studio-eyebrow">
                                    {driver === 'All assignments'
                                        ? 'VEHICLE PERIOD SCORE'
                                        : 'DRIVER SCORE'}
                                </span>
                                <h3>
                                    {driver !== 'All assignments'
                                        ? 'Identity & sample needed'
                                        : score === null
                                          ? 'More coverage needed'
                                          : 'Know what shaped the score'}
                                </h3>
                                <p>
                                    {driver === 'All assignments'
                                        ? `${eligible.length} of ${trips.length} trips scored · ${eligibleDistance.toFixed(1)} km eligible. Partial trips excluded.`
                                        : 'Assignment is not confirmed driving identity. No personal score or ranking is assigned.'}
                                </p>
                                <Badge tone="info">
                                    Illustrative · SCORE-DEMO-v2
                                </Badge>
                                <button
                                    className="score-link"
                                    onClick={() => setRules(true)}
                                >
                                    See calculation <ArrowUpRight size={13} />
                                </button>
                            </div>
                        </section>
                        <section className="studio-card analytics-kpis">
                            {[
                                [
                                    Navigation,
                                    'Recorded distance',
                                    `${distance.toFixed(1)} km`,
                                    `${trips.length} trips · estimated`,
                                ],
                                [
                                    Gauge,
                                    'Overspeed episodes',
                                    String(speeding.length),
                                    `${seconds}s over trigger`,
                                ],
                                [
                                    Clock3,
                                    'Idle time',
                                    `${idle} min`,
                                    `${minutes ? Math.round((idle / minutes) * 100) : 0}% of recorded time`,
                                ],
                                [
                                    Activity,
                                    'Driving events / 100 km',
                                    rate === null ? '—' : rate.toFixed(1),
                                    `${eligibleDistance.toFixed(1)} km eligible · small sample`,
                                ],
                            ].map(([Icon, label, value, hint]) => {
                                const I = Icon as typeof Gauge;
                                return (
                                    <div key={String(label)}>
                                        <I size={19} />
                                        <small>{String(label)}</small>
                                        <strong>{String(value)}</strong>
                                        <span>{String(hint)}</span>
                                    </div>
                                );
                            })}
                        </section>
                    </div>
                    <div className="analytics-secondary">
                        <section className="studio-card analytics-trend">
                            <div className="activity-title">
                                <div>
                                    <span className="studio-eyebrow">
                                        DAILY VEHICLE SCORE
                                    </span>
                                    <h3>See change over time</h3>
                                </div>
                                <Badge>0–100 points</Badge>
                            </div>
                            <div className="trend-axis">
                                <span>0</span>
                                <span>50</span>
                                <span>100</span>
                            </div>
                            {days.map((d) => (
                                <div className="trend-row" key={d.day}>
                                    <span>{dateLabel(d.day)}</span>
                                    <div>
                                        {d.score !== null ? (
                                            <i
                                                style={{ width: `${d.score}%` }}
                                            />
                                        ) : (
                                            <em>Insufficient coverage</em>
                                        )}
                                    </div>
                                    <strong>{d.score ?? '—'}</strong>
                                </div>
                            ))}
                            <p className="muted">
                                Distance-weighted eligible trips per day ·
                                synthetic samples. Gaps are not zero scores. No
                                previous-period comparison is available.
                            </p>
                        </section>
                        <section className="studio-card speed-focus">
                            <div className="activity-title">
                                <div>
                                    <span className="studio-eyebrow">
                                        OVERSPEED → CONTROL ROOM
                                    </span>
                                    <h3>
                                        {speeding.length
                                            ? 'Review the threshold event'
                                            : 'No recorded overspeed events'}
                                    </h3>
                                </div>
                                <Gauge size={24} />
                            </div>
                            {speeding.map((t) => (
                                <div key={t.id}>
                                    <div className="speed-values">
                                        <strong>
                                            {t.overspeed!.peak}
                                            <small>km/h peak</small>
                                        </strong>
                                        <span>
                                            +
                                            {t.overspeed!.peak -
                                                t.overspeed!.threshold}{' '}
                                            km/h over fleet threshold
                                            <br />
                                            {t.overspeed!.seconds} seconds above
                                            trigger
                                        </span>
                                    </div>
                                    <p>
                                        {dateLabel(t.day)} · {t.overspeed!.at} ·{' '}
                                        {t.driver}
                                    </p>
                                    <Badge tone="warning">
                                        Road speed limit unverified
                                    </Badge>
                                    <div className="analytics-actions">
                                        <Button
                                            variant="outline"
                                            onClick={() => onTrip(t.id)}
                                        >
                                            Review trip
                                        </Button>
                                        <Button
                                            disabled={!m.canManage}
                                            onClick={() => previewSpeed(t.id)}
                                        >
                                            <ShieldAlert size={15} />
                                            Preview overspeed → Control Room
                                        </Button>
                                    </div>
                                </div>
                            ))}
                            {!speeding.length && (
                                <p>
                                    Missing samples can hide events. This does
                                    not establish that every road limit was
                                    followed.
                                </p>
                            )}
                            <p className="muted">
                                One sustained episode creates one response
                                record. Repeated reports are correlated; the
                                alert is routed even when the driver is
                                unconfirmed.
                            </p>
                        </section>
                    </div>
                    <section className="studio-card insights-journey-list">
                        <div className="activity-title">
                            <h3>Insights by trip</h3>
                            <Badge>
                                {eligible.length} scored ·{' '}
                                {trips.length - eligible.length} partial
                            </Badge>
                        </div>
                        {trips.map((t) => (
                            <button key={t.id} onClick={() => onTrip(t.id)}>
                                <span className="insight-mini-score">
                                    {tripScore(t) ?? '—'}
                                    <small>/100</small>
                                </span>
                                <div>
                                    <strong>
                                        {t.from} → {t.to}
                                    </strong>
                                    <small>
                                        {dateLabel(t.day)} · {t.driver} ·{' '}
                                        {t.distance} km · {t.coverage}% coverage
                                    </small>
                                </div>
                                <span className="insight-flags">
                                    {drivingEventCount(t)} driving events
                                    <br />
                                    {t.overspeed
                                        ? '1 overspeed episode'
                                        : `${t.events.filter((e) => e.kind === 'power').length} vehicle faults`}
                                </span>
                                <ArrowUpRight size={18} />
                            </button>
                        ))}
                    </section>
                    <div className="telemetry-two">
                        <section className="studio-card">
                            <div className="activity-title">
                                <h3>Events to review</h3>
                                <select
                                    aria-label="Driving event type"
                                    value={eventType}
                                    onChange={(e) =>
                                        setEventType(e.target.value)
                                    }
                                >
                                    <option>All driving events</option>
                                    <option>Overspeed threshold</option>
                                    <option>Harsh braking</option>
                                    <option>Harsh acceleration</option>
                                </select>
                            </div>
                            {shownEvents.map((e, i) => (
                                <button
                                    className="driving-event"
                                    key={e.trip.id + i}
                                    onClick={() => onTrip(e.trip.id)}
                                >
                                    <span className="event-symbol">
                                        {e.kind === 'speed' ? (
                                            <Gauge size={19} />
                                        ) : (
                                            <Activity size={19} />
                                        )}
                                    </span>
                                    <div>
                                        <strong>{e.title}</strong>
                                        <small>
                                            {dateLabel(e.trip.day)} · {e.at} ·{' '}
                                            {e.trip.driver}
                                        </small>
                                    </div>
                                    <ArrowUpRight size={16} />
                                </button>
                            ))}
                            {!shownEvents.length && (
                                <p className="muted">
                                    No matching recorded events.
                                </p>
                            )}
                        </section>
                        <section className="studio-card analytics-quality">
                            <span className="studio-eyebrow">
                                MAKE THE SCORE TRUSTWORTHY
                            </span>
                            <h3>Evidence before attribution</h3>
                            <Row
                                title="Driver identity"
                                value="0 confirmed assignments"
                            />
                            <Row
                                title="Coverage"
                                value={`${trips.length - eligible.length} partial trip(s) excluded`}
                            />
                            <Row
                                title="Harsh cornering"
                                value="Not decoded in this preview"
                            />
                            <Row
                                title="Road speed limits"
                                value="No verified limit source connected"
                            />
                            <p className="muted">
                                The proposed driver score needs confirmed
                                checkout / driver handover, at least 5 eligible
                                trips and 100 km in the period. These minimums
                                and weights need operational approval.
                            </p>
                            <Button
                                variant="outline"
                                disabled={!m.canManage}
                                onClick={() => m.followup()}
                            >
                                Create coaching follow-up
                            </Button>
                        </section>
                    </div>
                </>
            )}
            {rules && (
                <Modal
                    title="How the score is calculated"
                    description="SCORE-DEMO-v2 · illustrative policy · not activated"
                    icon={BarChart3}
                    size="standard"
                    onClose={() => setRules(false)}
                    footer={
                        <Button
                            variant="outline"
                            onClick={() => setRules(false)}
                        >
                            Done
                        </Button>
                    }
                >
                    <Notice title="Vehicle insight, not a confirmed driver rating">
                        Sensor events and driver identity need review. An alert
                        still goes to Control Room when a score is withheld.
                    </Notice>
                    <h3>1. Calculate each eligible trip</h3>
                    <p className="score-formula">
                        100 − braking×5 − acceleration×3 − overspeed episodes×8
                        − round(idle minutes×0.5)
                    </p>
                    <p>
                        Clamp at zero. One continuous overspeed episode counts
                        once, including duplicate reports. The preview uses the
                        recorded episode; production must validate sample
                        cadence, duration and rule version.
                    </p>
                    {eligible.map((t) => (
                        <Row
                            key={t.id}
                            title={t.id}
                            sub={`${t.distance} km · ${t.coverage}% coverage`}
                            value={`100 − ${t.braking * 5} − ${t.acceleration * 3} − ${t.overspeed ? 8 : 0} − ${Math.round(t.idle * 0.5)} = ${tripScore(t)}`}
                        />
                    ))}
                    <h3>2. Weight by distance</h3>
                    <p>
                        Sum each eligible trip score × trip kilometres, then
                        divide by eligible kilometres. Current selection:{' '}
                        {score ?? '—'}/100 over {eligibleDistance.toFixed(1)}{' '}
                        km. Trips below 90% coverage do not contribute.
                    </p>
                    <h3>3. Attribute only with evidence</h3>
                    <p>
                        A person needs confirmed driving identity and enough
                        eligible journeys. Missing data, unassigned trips,
                        vehicle faults and potential collisions do not silently
                        become driving deductions. Reviewed false events need an
                        auditable correction and recalculation; that review
                        workflow is a remaining gap.
                    </p>
                    <Row
                        title="Overspeed trigger example"
                        value="50 km/h fleet threshold + 5 tolerance, continuously for 15 seconds"
                    />
                    <Row
                        title="Road-limit overspeeding"
                        value="Requires verified road, direction, limit source and effective time"
                    />
                    <p className="muted">
                        Severity weighting, exposure normalization, minimum
                        samples and weights must be validated before a driver
                        score is used operationally. The illustrative score is
                        not an employment decision.
                    </p>
                </Modal>
            )}
        </div>
    );
}
