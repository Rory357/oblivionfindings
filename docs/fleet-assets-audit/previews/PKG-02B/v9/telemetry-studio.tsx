import { Button } from '@/components/ui/button';
import {
    ArrowUpRight,
    BatteryCharging,
    Bell,
    Gauge,
    Navigation,
    Radio,
    ShieldAlert,
    Signal,
    Wrench,
    Zap,
} from 'lucide-react';
import { useState } from 'react';
import { DrivingInsights } from './driving-insights';
import { confirmedDriver } from './driving-workflows';
import type { VehicleModel } from './operations';
import { alertKinds, type TrackerState } from './telemetry-state';
import './telemetry.css';
import { journeys } from './trip-data';
import { Badge, Modal, Notice, Picker, Row } from './ui';
type Props = {
    model: VehicleModel;
    sub: string;
    focusAlert?: string;
    onAlertClosed?: () => void;
    onNav: (view: string, sub?: string) => void;
    onWork: (id: string) => void;
    onTrip: (id: string, point?: number) => void;
};
const km = (n: number) => n.toLocaleString('en-NZ') + ' km';

export function MileageFeed({
    model: m,
    compact = false,
}: {
    model: VehicleModel;
    compact?: boolean;
}) {
    const t = m.tracker;
    if (!t.available)
        return (
            <Notice title="Manual mileage available">
                No current tracker is assigned in this example. Dashboard
                observations still support service planning.
            </Notice>
        );
    const discrepancy = Math.abs(t.difference) > m.data.tracker.tolerance;
    return (
        <section
            className={`studio-card mileage-feed ${compact ? 'compact' : ''}`}
        >
            <div className="feed-heading">
                <span className="feature-icon">
                    <Radio size={21} />
                </span>
                <div>
                    <span className="studio-eyebrow">
                        GV500CG · DISTANCE FEED
                    </span>
                    <h3>
                        {t.usable
                            ? 'Automatic planning is on'
                            : m.data.tracker.automatic
                              ? 'Planning feed needs review'
                              : 'Connect mileage to service planning'}
                    </h3>
                </div>
                <Badge tone={t.usable ? 'success' : 'warning'}>
                    {t.usable
                        ? 'Estimated'
                        : !t.fresh
                          ? 'Stale / unavailable'
                          : 'Cross-check'}
                </Badge>
            </div>
            <div className="feed-values">
                <div>
                    <small>Dashboard record</small>
                    <strong>{km(t.verified)}</strong>
                    <span>Retained original observation</span>
                </div>
                <div>
                    <small>Tracker estimate</small>
                    <strong>{km(t.estimate)}</strong>
                    <span>
                        {t.observed} · {t.fresh ? 'sample' : 'last known'}
                    </span>
                </div>
                <div>
                    <small>Difference since dashboard record</small>
                    <strong className={discrepancy ? 'text-warning' : ''}>
                        {t.difference >= 0 ? '+' : ''}
                        {km(t.difference)}
                    </strong>
                    <span>
                        {discrepancy
                            ? 'Cross-check threshold exceeded'
                            : 'May include travel since observation'}
                    </span>
                </div>
            </div>
            {!t.reconciled && (
                <p className="feed-warning">
                    A newer dashboard reading or correction needs
                    reconciliation. Automatic estimates are paused.
                </p>
            )}
            <div className="feed-footer">
                <p>
                    {t.usable
                        ? 'Service and RUC planning use ' +
                          km(t.planning) +
                          '. '
                        : 'Planning uses the recorded dashboard reading. '}
                    Tracker distance is an estimate; verify the dashboard and
                    source documents. WoF and registration dates come from their
                    own evidence.
                </p>
                <Button
                    variant="outline"
                    disabled={!m.canManage || !t.fresh}
                    onClick={m.telemetry.reconcile}
                >
                    Cross-check & configure
                </Button>
                {m.data.tracker.automatic && (
                    <Button
                        variant="ghost"
                        disabled={!m.canManage}
                        onClick={m.telemetry.pause}
                    >
                        Pause feed
                    </Button>
                )}
            </div>
        </section>
    );
}

export function TelemetryStudio({
    model: m,
    sub,
    focusAlert,
    onAlertClosed,
    onNav,
    onWork,
    onTrip,
}: Props) {
    const [selectedAlert, setSelectedAlert] = useState(focusAlert || ''),
        [kind, setKind] = useState(alertKinds[0]),
        [failed, setFailed] = useState(false),
        [filter, setFilter] = useState('Open');
    const t = m.data.tracker,
        facts = m.tracker;
    const selected = m.data.alerts.find((a) => a.id === selectedAlert);
    const isUnavailable = !facts.available;
    if (sub === 'driving')
        return <DrivingInsights model={m} onTrip={onTrip} onNav={onNav} />;
    if (sub === 'alerts')
        return (
            <div className="telemetry-studio">
                <div className="activity-title">
                    <div>
                        <span className="studio-eyebrow">
                            SAFETY & RESPONSE
                        </span>
                        <h2 className="text-section-title">
                            Vehicle alerts → Control Room
                        </h2>
                        <p className="muted">
                            One source event, one response record, linked
                            follow-up.
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        disabled={!m.canManage}
                        onClick={m.telemetry.rule}
                    >
                        Response plan
                    </Button>
                </div>
                <div className="alert-clock">
                    <p>
                        <strong>
                            Response clock +{m.data.driving.minutes} min
                        </strong>{' '}
                        · Potential collision: acknowledge within 2 min; other
                        preview alerts: 10 min. Overdue items escalate to{' '}
                        {m.data.alertPlan.backup}. Example deadlines.
                    </p>
                    <Button
                        variant="outline"
                        disabled={!m.canManage}
                        onClick={m.driving.advanceTime}
                    >
                        Advance preview clock 5 min
                    </Button>
                </div>
                <div className="alert-route">
                    <span>
                        <Radio size={20} />
                        GV500CG event
                    </span>
                    <b>→</b>
                    <span>
                        <ShieldAlert size={20} />
                        Correlate & route
                    </span>
                    <b>→</b>
                    <span>
                        <Bell size={20} />
                        Control Room owner
                    </span>
                    <b>→</b>
                    <span>
                        <Wrench size={20} />
                        Assessment & follow-up
                    </span>
                </div>
                <div className="telemetry-two">
                    <section className="studio-card">
                        <div className="activity-title">
                            <h3>
                                Response queue{' '}
                                <Badge>{m.data.alerts.length}</Badge>
                            </h3>
                            <select
                                aria-label="Alert status filter"
                                value={filter}
                                onChange={(e) => setFilter(e.target.value)}
                            >
                                <option>Open</option>
                                <option>All</option>
                                <option>Resolved</option>
                            </select>
                        </div>
                        {m.data.alerts
                            .filter(
                                (a) =>
                                    filter === 'All' ||
                                    (filter === 'Resolved'
                                        ? a.status === 'Resolved'
                                        : a.status !== 'Resolved'),
                            )
                            .map((a) => (
                                <button
                                    className="alert-record"
                                    key={a.id}
                                    onClick={() => setSelectedAlert(a.id)}
                                >
                                    <span className="event-symbol">
                                        <ShieldAlert size={21} />
                                    </span>
                                    <div>
                                        <strong>{a.kind}</strong>
                                        <small>
                                            {a.id} · {a.owner} · {a.observed}
                                        </small>
                                        <small>
                                            {a.duplicates
                                                ? `${a.duplicates} duplicate report(s) correlated`
                                                : 'Original signal retained'}
                                        </small>
                                        <small>
                                            {a.status === 'Delivery failed'
                                                ? 'Delivery retry required'
                                                : a.acknowledged
                                                  ? 'Acknowledged'
                                                  : a.autoEscalated
                                                    ? 'Acknowledgement overdue · backup notified in preview'
                                                    : `Acknowledge in ${Math.max(0, (a.ackWithin ?? 10) - (m.data.driving.minutes - (a.receivedMinute ?? 0)))} min`}{' '}
                                            · age{' '}
                                            {m.data.driving.minutes -
                                                (a.receivedMinute ?? 0)}{' '}
                                            min
                                        </small>
                                    </div>
                                    <Badge
                                        tone={
                                            a.status === 'Resolved'
                                                ? 'success'
                                                : a.status === 'Delivery failed'
                                                  ? 'critical'
                                                  : 'warning'
                                        }
                                    >
                                        {a.status}
                                    </Badge>
                                    <ArrowUpRight size={15} />
                                </button>
                            ))}
                        {!m.data.alerts.filter(
                            (a) =>
                                filter === 'All' ||
                                (filter === 'Resolved'
                                    ? a.status === 'Resolved'
                                    : a.status !== 'Resolved'),
                        ).length && (
                            <div className="telemetry-empty">
                                <ShieldAlert size={32} />
                                <h3>
                                    No{' '}
                                    {filter === 'Resolved'
                                        ? 'resolved'
                                        : 'matching'}{' '}
                                    alerts
                                </h3>
                                <p>
                                    Use the synthetic event preview to walk
                                    through the response.
                                </p>
                            </div>
                        )}
                    </section>
                    <section className="studio-card alert-preview">
                        <Badge tone="info">Preview controls</Badge>
                        <h3>Try an event</h3>
                        <Picker
                            label="Synthetic vehicle event"
                            value={kind}
                            onChange={setKind}
                            options={alertKinds.map((x) => ({
                                id: x,
                                name: x,
                                detail:
                                    x === 'Overspeed threshold'
                                        ? 'Sustained fleet-threshold event → Control Room; road limit unverified'
                                        : x === 'Potential collision'
                                          ? 'Sensor indication · human confirmation needed'
                                          : x === 'Vehicle diagnostic fault'
                                            ? 'Separate diagnostic source required · CG does not read ECU faults'
                                            : 'Context and response policy required',
                            }))}
                        />
                        {kind === 'Vehicle diagnostic fault' && (
                            <Notice title="External diagnostic source">
                                This previews a fault from a separately
                                integrated diagnostic provider. GV500CG's
                                power-only OBD connection cannot supply ECU
                                fault codes.
                            </Notice>
                        )}
                        <label className="check-line">
                            <input
                                type="checkbox"
                                checked={failed}
                                onChange={(e) => setFailed(e.target.checked)}
                            />
                            Simulate delivery failure
                        </label>
                        <Button
                            disabled={!m.canManage || isUnavailable}
                            onClick={() => {
                                m.telemetry.simulateAlert(kind, failed);
                                setFilter('Open');
                            }}
                        >
                            Preview event
                        </Button>
                        <p className="muted">
                            Repeating the same sample tests duplicate handling.
                            No notification, emergency call or device command is
                            sent.
                        </p>
                    </section>
                </div>
                <section className="studio-card routing-details">
                    <div>
                        <span className="studio-eyebrow">
                            DRAFT RESPONSE PLAN
                        </span>
                        <h3>
                            {m.data.alertPlan.owner} → {m.data.alertPlan.backup}
                        </h3>
                        <p>
                            Overspeed: above {m.data.alertPlan.speed} km/h plus{' '}
                            {m.data.alertPlan.speedTolerance} km/h tolerance for{' '}
                            {m.data.alertPlan.speedDuration} seconds → Control
                            Room. A new episode needs{' '}
                            {m.data.alertPlan.speedCooldown} seconds below the
                            trigger. Potential collision: urgent human review.
                            Low voltage: below {m.data.alertPlan.voltage} V for{' '}
                            {m.data.alertPlan.duration} minutes. Tracker
                            overdue: {m.data.alertPlan.offline} minutes after an
                            expected report.
                        </p>
                    </div>
                    <Badge tone="warning">Activation pending</Badge>
                    <p>
                        Geofence alerts require an active assignment and
                        schedule. Towing, power loss and motion during a
                        restriction need contextual review. Harsh driving
                        normally creates a coaching task unless an approved
                        escalation rule applies.
                    </p>
                </section>
                {selected && (
                    <Modal
                        title={selected.kind}
                        description={`${selected.id} · Control Room preview · KWH014`}
                        icon={ShieldAlert}
                        size="standard"
                        onClose={() => {
                            setSelectedAlert('');
                            onAlertClosed?.();
                        }}
                        footer={
                            <Button
                                variant="outline"
                                onClick={() => setSelectedAlert('')}
                            >
                                Back to vehicle alerts
                            </Button>
                        }
                    >
                        <div className="control-room-summary">
                            <Badge
                                tone={
                                    selected.status === 'Resolved'
                                        ? 'success'
                                        : 'warning'
                                }
                            >
                                {selected.status}
                            </Badge>
                            <strong>
                                {selected.priority} · {selected.owner}
                            </strong>
                        </div>
                        <Row title="Observation" value={selected.observed} />
                        <Row
                            title="Response deadline"
                            value={
                                selected.acknowledged
                                    ? 'Acknowledged'
                                    : selected.autoEscalated
                                      ? 'Overdue · escalated to backup'
                                      : `${Math.max(0, (selected.ackWithin ?? 10) - (m.data.driving.minutes - (selected.receivedMinute ?? 0)))} min remaining`
                            }
                        />
                        {selected.work && (
                            <Row
                                title="Maintenance outcome"
                                value={`${selected.work} · ${m.data.works.find((w) => w.id === selected.work)?.status || 'Unavailable'} · review independently before resolving alert`}
                            />
                        )}
                        <Row
                            title="Vehicle & device"
                            value={'VH-014 · ' + selected.device}
                        />
                        <Row
                            title="Recorded location"
                            value={
                                selected.lat.toFixed(5) +
                                ', ' +
                                selected.lng.toFixed(5) +
                                ' · synthetic'
                            }
                        />
                        <Row
                            title="Current driver evidence"
                            value={
                                journeys.find((t) => t.id === selected.source)
                                    ? confirmedDriver(
                                          journeys.find(
                                              (t) => t.id === selected.source,
                                          )!,
                                          m.data.driving,
                                      ) ||
                                      'Unconfirmed / handover needs point-specific review'
                                    : 'Unconfirmed · verify source identity'
                            }
                        />
                        <Row title="Source record" value={selected.source} />
                        <Row
                            title="Signal correlation"
                            value={selected.correlation}
                        />
                        <Row
                            title="Fault / event evidence"
                            value={selected.detail}
                        />
                        <Row
                            title="Triage decision"
                            value={selected.decision || 'Awaiting assessment'}
                        />
                        <Notice
                            title={
                                selected.kind === 'Potential collision'
                                    ? 'Potential collision, not a confirmed accident'
                                    : 'Assess context before concluding'
                            }
                        >
                            Confirm occupant welfare and circumstances through
                            the approved response process. Closing this alert
                            does not release the vehicle or close linked work.
                        </Notice>
                        <div className="alert-actions">
                            {selected.kind === 'Overspeed threshold' &&
                                journeys.some(
                                    (t) => t.id === selected.source,
                                ) && (
                                    <Button
                                        variant="outline"
                                        disabled={!m.canManage}
                                        onClick={() => {
                                            const trip = journeys.find(
                                                (t) => t.id === selected.source,
                                            )!;
                                            const index = trip.events.findIndex(
                                                (e) => e.kind === 'speed',
                                            );
                                            setSelectedAlert('');
                                            if (index >= 0)
                                                m.driving.review(trip, index);
                                        }}
                                    >
                                        Review source event & score
                                    </Button>
                                )}
                            {selected.source.startsWith('TRIP-') && (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setSelectedAlert('');
                                        onTrip(selected.source);
                                    }}
                                >
                                    Open source trip
                                </Button>
                            )}
                            {(selected.status === 'Delivery failed'
                                ? ['Retry delivery']
                                : selected.status === 'Resolved'
                                  ? []
                                  : selected.status === 'New'
                                    ? ['Acknowledge', 'Triage', 'Escalate']
                                    : ['Triage', 'Escalate', 'Resolve']
                            ).map((a) => (
                                <Button
                                    key={a}
                                    disabled={!m.canManage}
                                    variant={
                                        a === 'Resolve' ? 'outline' : 'default'
                                    }
                                    onClick={() => {
                                        setSelectedAlert('');
                                        m.telemetry.alertAction(
                                            selected.id,
                                            a as
                                                | 'Acknowledge'
                                                | 'Triage'
                                                | 'Escalate'
                                                | 'Resolve'
                                                | 'Retry delivery',
                                        );
                                    }}
                                >
                                    {a}
                                </Button>
                            ))}
                            {selected.work ? (
                                <Button
                                    variant="outline"
                                    onClick={() => {
                                        setSelectedAlert('');
                                        onWork(selected.work!);
                                    }}
                                >
                                    Open {selected.work}
                                </Button>
                            ) : (
                                <Button
                                    variant="outline"
                                    disabled={
                                        !m.canManage ||
                                        selected.status === 'Delivery failed' ||
                                        selected.decision !==
                                            'Maintenance assessment required'
                                    }
                                    onClick={() => m.linkAlertWork(selected.id)}
                                >
                                    Create Maintenance assessment
                                </Button>
                            )}
                            {!selected.work && (
                                <Button
                                    variant="outline"
                                    disabled={
                                        !m.canManage ||
                                        selected.decision !==
                                            'Maintenance assessment required' ||
                                        selected.status === 'Delivery failed'
                                    }
                                    onClick={() => {
                                        setSelectedAlert('');
                                        m.linkAlertExisting(selected.id);
                                    }}
                                >
                                    Link existing work
                                </Button>
                            )}
                            <Button
                                variant="ghost"
                                disabled={!m.canManage}
                                onClick={() => {
                                    setSelectedAlert('');
                                    m.followup(undefined, selected.id);
                                }}
                            >
                                Add follow-up reminder
                            </Button>
                        </div>
                        <h3>Response history</h3>
                        <ol className="alert-history">
                            {selected.history.map((h, i) => (
                                <li key={i}>{h}</li>
                            ))}
                        </ol>
                    </Modal>
                )}
            </div>
        );
    return (
        <div className="telemetry-studio">
            <div className="activity-title">
                <div>
                    <span className="studio-eyebrow">CONNECTED VEHICLE</span>
                    <h2 className="text-section-title">
                        GV500CG · vehicle telemetry
                    </h2>
                    <p className="muted">
                        Vehicle state, power and mileage with source confidence.
                    </p>
                </div>
                <Badge tone={facts.fresh ? 'success' : 'warning'}>
                    {isUnavailable
                        ? 'No tracker'
                        : facts.fresh
                          ? 'Current sample · DEMO'
                          : 'Last known sample'}
                </Badge>
            </div>
            {!isUnavailable && (
                <div className="telemetry-preview-bar">
                    <span>
                        <Radio size={15} />
                        Synthetic sample · {facts.observed}
                    </span>
                    <label>
                        State{' '}
                        <select
                            aria-label="Tracker preview state"
                            value={t.sample}
                            disabled={!m.canManage}
                            onChange={(e) =>
                                m.telemetry.setSample(
                                    e.target.value as TrackerState['sample'],
                                )
                            }
                        >
                            {['Parked', 'Moving', 'Offline', 'Unplugged'].map(
                                (s) => (
                                    <option key={s}>{s}</option>
                                ),
                            )}
                        </select>
                    </label>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={
                            !m.canManage || !facts.fresh || t.sequence >= 2
                        }
                        onClick={m.telemetry.nextSample}
                    >
                        Next sample · +12 km
                    </Button>
                </div>
            )}
            <div className="telemetry-grid">
                {[
                    [
                        Zap,
                        'Ignition',
                        isUnavailable
                            ? 'Unknown'
                            : !facts.fresh
                              ? 'Unknown'
                              : t.sample === 'Moving'
                                ? 'On'
                                : 'Off',
                        'Virtual ignition · inferred',
                        facts.fresh,
                    ],
                    [
                        Navigation,
                        'Motion',
                        isUnavailable
                            ? 'Unknown'
                            : !facts.fresh
                              ? 'Unknown'
                              : t.sample === 'Moving'
                                ? 'Moving'
                                : 'Stationary',
                        facts.fresh
                            ? t.sample === 'Moving'
                                ? '42 km/h · GNSS'
                                : '0 km/h · GNSS'
                            : 'Current movement not known',
                        facts.fresh,
                    ],
                    [
                        BatteryCharging,
                        'Vehicle voltage',
                        isUnavailable || !facts.fresh
                            ? '—'
                            : t.sample === 'Moving'
                              ? '14.2 V'
                              : '12.6 V',
                        'External supply · sample, not battery health',
                        facts.fresh,
                    ],
                    [
                        BatteryCharging,
                        'Tracker backup',
                        isUnavailable
                            ? '—'
                            : t.sample === 'Unplugged'
                              ? '68%'
                              : '92%',
                        'Internal tracker battery · separate from vehicle',
                        facts.fresh,
                    ],
                    [
                        Signal,
                        'Connection',
                        isUnavailable
                            ? 'Not assigned'
                            : t.sample === 'Offline'
                              ? 'Overdue'
                              : t.sample === 'Unplugged'
                                ? 'On backup'
                                : 'LTE Cat 1',
                        isUnavailable
                            ? 'Assign and validate a device'
                            : 'GV500CG-DEMO-14 · synthetic device',
                        facts.fresh,
                    ],
                    [
                        Gauge,
                        'Distance counter',
                        isUnavailable ? '—' : km(facts.counter),
                        'Tracker cumulative distance · not dashboard OBD',
                        facts.fresh,
                    ],
                ].map(([Icon, label, value, caption, current]) => {
                    const I = Icon as typeof Radio;
                    return (
                        <section
                            className="studio-card telemetry-metric"
                            key={String(label)}
                        >
                            <div>
                                <I size={21} />
                                <span>{String(label)}</span>
                                <span
                                    className={`signal-dot ${current ? '' : 'stale'}`}
                                />
                            </div>
                            <strong>{String(value)}</strong>
                            <p>{String(caption)}</p>
                            {!current && !isUnavailable && (
                                <small>
                                    Current state needs a fresh report
                                </small>
                            )}
                        </section>
                    );
                })}
            </div>
            <MileageFeed model={m} />
            <div className="telemetry-two">
                <section className="studio-card capability-card">
                    <span className="studio-eyebrow">MODEL CAPABILITIES</span>
                    <h3>What this unit can contribute</h3>
                    {[
                        [
                            'Location & trip reconstruction',
                            'GNSS points, speed and tracker distance',
                        ],
                        [
                            'Virtual ignition & motion',
                            'Derived state; preserve method and confidence',
                        ],
                        [
                            'Crash & driving events',
                            'Requires device configuration and verified decoding',
                        ],
                        [
                            'BLE 5.2 accessories',
                            'Additional supported hardware and pairing required',
                        ],
                        [
                            'Buffered reports & OTA',
                            'Late reports retain observed time; configuration needs device acknowledgement',
                        ],
                    ].map(([title, caption]) => (
                        <Row key={title} title={title} sub={caption} />
                    ))}
                    <Button
                        variant="outline"
                        onClick={() => onNav('map', 'alerts')}
                    >
                        Open vehicle alerts <ArrowUpRight size={15} />
                    </Button>
                </section>
                <section className="studio-card capability-card">
                    <span className="studio-eyebrow">VEHICLE IDENTITY</span>
                    <h3>VIN stays with the vehicle record</h3>
                    <p className="vin-value">
                        {m.data.profile.vin || 'Not recorded'}
                    </p>
                    <Badge>
                        {m.data.profile.vin
                            ? 'Manually recorded'
                            : 'Evidence needed'}
                    </Badge>
                    <p className="muted">
                        GV500CG uses the OBD connector for power only. It does
                        not read ECU VIN, dashboard odometer, diagnostic trouble
                        codes, engine RPM or fuel level directly.
                    </p>
                    <Button
                        variant="outline"
                        disabled={!m.canManage}
                        onClick={m.profile}
                    >
                        Review vehicle identity
                    </Button>
                    <details>
                        <summary>Sources and feature availability</summary>
                        <p>
                            Queclink GV500CG datasheet and User Manual
                            TRACGV500CGUM001, section 2.2. BLE accessories,
                            report fields and command support must be validated
                            for installed firmware. Public integration parameter
                            catalogues include other-family fields and do not
                            prove CG hardware capability.
                        </p>
                    </details>
                    <Button
                        variant="ghost"
                        onClick={() => onNav('compliance', 'mileage')}
                    >
                        Mileage history <ArrowUpRight size={15} />
                    </Button>
                </section>
            </div>
            {t.history.length > 0 && (
                <details className="studio-card">
                    <summary>Calibration history · {t.history.length}</summary>
                    {t.history.map((x, i) => (
                        <p key={i}>{x}</p>
                    ))}
                </details>
            )}
        </div>
    );
}
