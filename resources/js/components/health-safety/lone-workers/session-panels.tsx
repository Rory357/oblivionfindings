/**
 * The safety session's sections as the approved drawings draw them (pg-hsn-82,
 * 82b–82f, Stephan 23 Oct 2026): the session as a short story, check-ins,
 * location readings and history as time lines, tracker and automatic-check
 * states as rings, connected work as a list. Every "doesn't confirm the worker
 * is safe" line keeps the code's wording.
 */
import { longDuration } from '@/components/control-room/live-response/response-clock';
import { dateTimeWithYear } from '@/components/incidents/incident-stages';
import {
    FactRows,
    Log,
    Note,
    PeopleCards,
    QuietLine,
    Ring,
    RingCard,
    RowList,
    Story,
    Surface,
    type CheckItem,
    type LogItem,
    type PersonCardItem,
    type RowItem,
    type SgTone,
    type StoryFact,
} from '@/components/signature/blocks';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    formatDateOnly,
    formatDateTimeLong,
    formatTime,
    toDateInput,
} from '@/lib/datetime';
import {
    AlertTriangle,
    CalendarClock,
    House,
    Info,
    MapPin,
    RotateCw,
    Send,
    ShieldAlert,
    Siren,
    User,
} from 'lucide-react';
import { humaniseHsValue } from '@/lib/health-safety/hs-labels';
import type { ReactNode } from 'react';
import type { LinkedRecord, SafetySession, SessionDelivery } from './types';

export const recordedAt = (value: string | null | undefined) =>
    formatDateTimeLong(value, 'Not recorded');
export const words = (value: string) =>
    value.replace(/[_-]/g, ' ').replace(/^./, (letter) => letter.toUpperCase());
export const deliveryLabel = (delivery: SessionDelivery | null) =>
    ({
        pending: 'Waiting to send',
        failed: 'Sending failed',
        delivered: 'Sent',
        suppressed: 'Not sent (switched off)',
        skipped: 'Not sent',
        unknown: 'Not available',
    })[delivery?.status ?? 'unknown'];
export const observationLabel = (state: string) =>
    ({
        no_check_in: 'No check-in received',
        received: 'Check-in received',
        stale: 'Check-in is old',
        unknown: 'Not known yet',
    })[state] ?? 'Not known yet';
export const monitorLabel = (state: string) =>
    ({
        current: 'Monitor checked recently',
        stale: 'Monitor check is old',
        failed: 'Monitor check failed',
        unknown: 'Monitor not confirmed',
    })[state] ?? 'Monitor not confirmed';

/** Plain words for a stored value; an unknown value is made readable, a missing one reads "Not available". */
const plain = (labels: Record<string, string>, value: unknown): string =>
    value == null || value === ''
        ? 'Not available'
        : (labels[String(value)] ?? humaniseHsValue(String(value)));
/** A control room response's status. */
export const responseStatusLabel = (status: string | null | undefined) =>
    plain(
        {
            open: 'Open',
            ack: 'Acknowledged',
            triaging: 'Being checked',
            confirmed: 'Confirmed as real',
            resolved: 'Resolved',
            closed: 'Closed',
            dismissed: 'Dismissed',
        },
        status,
    );
const checkInLabel = (status: unknown) =>
    plain({ ok: 'OK', concern: 'Concern', emergency: 'Emergency' }, status);
const sessionStatusLabel = (status: unknown) =>
    plain(
        {
            active: 'Active',
            overdue: 'Check-in overdue',
            emergency: 'Emergency',
            completed: 'Ended',
        },
        status,
    );
const LEGACY_ALERT_TYPES: Record<string, string> = {
    emergency: 'Emergency',
    overdue_check_in: 'Check-in overdue',
    no_response: 'No response',
};
const LEGACY_ALERT_STATES: Record<string, string> = {
    active: 'Active',
    acknowledged: 'Acknowledged',
    resolved: 'Resolved',
};
const COLLECTION_LABELS: Record<string, string> = {
    current: 'Sending',
    stale: 'Out of date',
    unknown: 'Not known',
};
/** A linked record's status (incident, safety review, action, control room response). */
const LINKED_STATUS: Record<string, string> = {
    open: 'Open',
    active: 'Active',
    overdue: 'Overdue',
    pending: 'Pending',
    draft: 'Draft',
    closed: 'Closed',
    resolved: 'Resolved',
    completed: 'Done',
    verified: 'Checked',
    archived: 'Archived',
    ack: 'Acknowledged',
    triaging: 'Being checked',
    confirmed: 'Confirmed as real',
    dismissed: 'Dismissed',
};

/**
 * A session time: the time alone on the same NZ day as `base` (the session's
 * start), otherwise with its date and year so a time is never ambiguous.
 */
export function sessionClock(
    value: string | null | undefined,
    base?: string | null,
    fallback = 'Not recorded',
): string {
    if (!value) return fallback;
    return base && toDateInput(value) === toDateInput(base)
        ? formatTime(value)
        : dateTimeWithYear(value, fallback);
}

export function SessionFact({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="min-w-0 space-y-1">
            <dt className="text-subtle">{label}</dt>
            <dd className="text-sm break-words whitespace-pre-wrap">
                {children === '' || children == null
                    ? 'Not recorded'
                    : children}
            </dd>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Overview                                                           */
/* ------------------------------------------------------------------ */

export function SessionOverview({ detail: d }: { detail: SafetySession }) {
    const plan = plannedPlace(d.planned_location);
    const facts: StoryFact[] = [
        {
            icon: MapPin,
            text: d.location ?? plan?.description ?? 'Location not recorded',
        },
        {
            icon: User,
            text: d.client?.name
                ? `With ${d.client.name}`
                : 'No client you can see',
        },
    ];
    return (
        <>
            <Story
                title="The session"
                facts={facts}
                paras={[
                    {
                        h: 'Activity',
                        t: d.activity_description || 'Not recorded',
                    },
                ]}
            />
            <Note icon={Info}>
                Starting the session sets when the first check-in is due. It
                isn’t a check-in and doesn’t confirm the worker is safe.
            </Note>
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Check-ins                                                          */
/* ------------------------------------------------------------------ */

const CHECK_TONE: Record<string, SgTone> = {
    ok: 'success',
    concern: 'warning',
    emergency: 'critical',
};

export function SessionCheckIns({ detail: d }: { detail: SafetySession }) {
    const o = d.observation;
    const base = d.started_at;
    const ordered = [...d.check_ins].sort((a, b) =>
        (a.checked_in_at ?? '').localeCompare(b.checked_in_at ?? ''),
    );
    const items: LogItem[] = [
        ...(!d.ended_at
            ? [
                  {
                      key: 'next',
                      time: sessionClock(o.next_due_at, base),
                      tone: (d.is_check_in_overdue
                          ? 'critical'
                          : 'none') as SgTone,
                      title: d.is_check_in_overdue
                          ? 'Check-in overdue'
                          : 'Next check-in due',
                      sub: observationLabel(o.state),
                  },
              ]
            : []),
        ...ordered
            .map((check, index) => ({
                key: check.id,
                time: sessionClock(check.checked_in_at, base),
                tone: CHECK_TONE[check.status] ?? ('info' as SgTone),
                title: `Check-in ${index + 1} · ${check.status === 'ok' ? 'Reported OK' : checkInLabel(check.status)}`,
                sub: (
                    <>
                        {check.receipt_source === 'legacy_created_at'
                            ? 'Saved (from an older record)'
                            : 'Received'}{' '}
                        {sessionClock(check.received_at, base)} ·{' '}
                        {check.recorded_by?.name ?? 'Name not recorded'}
                        {check.notes ? (
                            <span className="whitespace-pre-wrap">
                                {' '}
                                · {check.notes}
                            </span>
                        ) : null}
                        {check.location_lat != null &&
                        check.location_lng != null ? (
                            <span className="block">
                                Position recorded with this check-in:{' '}
                                {String(check.location_lat)},{' '}
                                {String(check.location_lng)}. This is where they
                                were at that check-in, not necessarily where
                                they are now.
                            </span>
                        ) : null}
                    </>
                ),
            }))
            .reverse(),
    ];
    return (
        <>
            {d.check_ins.length || !d.ended_at ? (
                <Log
                    title="Check-ins"
                    sub={`Every ${d.check_in_interval_minutes} minutes · newest first`}
                    items={items}
                    limit={items.length}
                />
            ) : null}
            {d.check_ins.length === 0 ? (
                <QuietLine text="No check-ins recorded for this session." />
            ) : null}
            {o.receipt_source === 'legacy_created_at' && (
                <Note icon={Info}>
                    For older check-ins, “received” is when the record was saved
                    — the exact arrival time wasn’t kept.
                </Note>
            )}
        </>
    );
}

/** The automatic check that marks a check-in overdue, read as a ring. */
export function SessionMonitorRing({ detail: d }: { detail: SafetySession }) {
    const m = d.observation.monitor;
    const checks: CheckItem[] = [
        {
            label: 'Last monitor check',
            state:
                m.state === 'current'
                    ? 'success'
                    : m.state === 'failed'
                      ? 'critical'
                      : 'warning',
            note: sessionClock(m.checked_at, d.started_at),
        },
        {
            label: 'Last failed monitor check',
            state: m.failed_at ? 'warning' : 'success',
            note: sessionClock(m.failed_at, d.started_at),
        },
    ];
    return (
        <RingCard
            value={checks.filter((c) => c.state === 'success').length}
            of={checks.length}
            label={monitorLabel(m.state)}
            checks={checks}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Response                                                           */
/* ------------------------------------------------------------------ */

function assignedName(alert: LinkedRecord): string | null {
    return typeof alert.assigned_to === 'object'
        ? (alert.assigned_to?.name ?? null)
        : (alert.assigned_to ?? null);
}

export function SessionResponse({ detail: d }: { detail: SafetySession }) {
    const delivery = d.source_delivery;
    const base = d.started_at;
    return (
        <>
            <Story
                title="Help request and response"
                sub={
                    delivery
                        ? `Help request ${delivery.episode_number} · ${delivery.attempts} ${delivery.attempts === 1 ? 'try' : 'tries'} to send. This shows whether it was sent, not that someone has picked it up.`
                        : undefined
                }
                facts={[
                    {
                        icon: Siren,
                        text: `Help requested: ${recordedAt(d.emergency_triggered_at)}`,
                    },
                    {
                        icon: Send,
                        text: `${deliveryLabel(delivery)}${delivery?.delivered_at ? ` · ${sessionClock(delivery.delivered_at, base)}` : ''}`,
                    },
                    {
                        icon: RotateCw,
                        text: `Next try to send: ${recordedAt(delivery?.next_attempt_at)}`,
                    },
                ]}
                paras={[
                    {
                        h: 'Help request notes',
                        t: d.emergency_notes || 'Not recorded',
                    },
                ]}
            />
            {d.connections.control_room.length ? (
                d.connections.control_room.map((alert) => (
                    <FactRows
                        key={alert.id}
                        title={alert.reference_number ?? 'Control room response'}
                        sub="Control room response"
                        action={{ label: 'Open in control room', href: alert.href }}
                        rows={[
                            [
                                'Response status',
                                responseStatusLabel(alert.status),
                            ],
                            [
                                'Assigned responder',
                                assignedName(alert) ?? 'Needs a responder',
                            ],
                            ['Acknowledged', recordedAt(alert.acknowledged_at)],
                            ['Resolved', recordedAt(alert.resolved_at)],
                        ]}
                    />
                ))
            ) : (
                <QuietLine
                    title="Control room response"
                    text="You can’t see a control room response here. That doesn’t mean no one is responding — check with the control room."
                />
            )}
            <Note tone="warning" icon={AlertTriangle}>
                Acknowledging a response or a tracker panic alert isn’t a
                check-in. It doesn’t confirm the worker is safe or close any
                linked incident or safety review.
            </Note>
            {d.legacy_alert_history.length > 0 && (
                <Log
                    title="Older alerts"
                    sub="Older alerts, kept for reference. They don’t show what’s happening now."
                    limit={d.legacy_alert_history.length}
                    items={d.legacy_alert_history.map((alert) => ({
                        key: alert.id,
                        time: sessionClock(alert.triggered_at, base),
                        tone: 'none' as SgTone,
                        title: `${plain(LEGACY_ALERT_TYPES, alert.type)} · ${plain(LEGACY_ALERT_STATES, alert.recorded_status)}`,
                        sub: recordedAt(alert.triggered_at),
                    }))}
                />
            )}
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Location                                                           */
/* ------------------------------------------------------------------ */

const TRACKER_STATES: Record<string, string> = {
    restricted: 'Location hidden — you don’t have access',
    session_ended: 'Live location isn’t shown after the session ends',
    not_assigned: 'No tracker assigned',
    integrity_error: 'Tracker setup needs checking — ask the device admin',
    collection_stopped: 'Tracker has stopped sending',
    available: 'Tracker location available',
    stale: 'Tracker location is out of date',
    unknown: 'No tracker location yet',
};
/** Device contact freshness (the access service's collection_state). */
const COLLECTION_TONE: Record<string, SgTone> = {
    current: 'success',
    stale: 'warning',
    unknown: 'none',
};
const PLAN_METHODS: Record<string, string> = {
    manual: 'Coordinates entered',
    selected_address: 'Address selected',
    site_address: 'Site address',
    shift_address: 'Shift address',
};

type PlannedPlace = {
    description: string | null;
    point: string;
    source: string;
    recordedAt: string | null;
    by: string;
};

/** A permitted planned place, or null when none is recorded / it can't be read. */
function plannedPlace(value: unknown): PlannedPlace | null {
    if (
        value == null ||
        typeof value !== 'object' ||
        !('kind' in value) ||
        value.kind !== 'planned'
    )
        return null;
    const place = value as Record<string, unknown>;
    return {
        description:
            typeof place.description === 'string' ? place.description : null,
        point:
            typeof place.lat === 'number' && typeof place.lng === 'number'
                ? `${place.lat}, ${place.lng}`
                : 'Address only; no map point recorded',
        source:
            typeof place.method === 'string'
                ? (PLAN_METHODS[place.method] ?? 'Not recorded')
                : 'Not recorded',
        recordedAt:
            typeof place.recorded_at === 'string' ? place.recorded_at : null,
        by:
            place.recorded_by &&
            typeof place.recorded_by === 'object' &&
            'name' in place.recorded_by &&
            typeof place.recorded_by.name === 'string'
                ? place.recorded_by.name
                : 'Name not recorded',
    };
}

/** The planned place as one line, or why it can't be shown. */
function PlannedPlaceText({ value }: { value: unknown }) {
    if (value == null) return <>No planned location recorded.</>;
    const place = plannedPlace(value);
    if (!place) return <>Planned location details aren’t available.</>;
    return (
        <>
            {place.description ?? 'Not recorded'} · {place.point} ·{' '}
            {place.source} · {place.by} · {recordedAt(place.recordedAt)}
        </>
    );
}

export function SessionLocation({ detail: d }: { detail: SafetySession }) {
    const tracker = d.tracker;
    const base = d.started_at;
    const plan = plannedPlace(d.planned_location);
    const items: LogItem[] = [];
    if (tracker?.last_seen_at)
        items.push({
            key: 'contact',
            time: sessionClock(tracker.last_seen_at, base),
            tone: COLLECTION_TONE[tracker.collection_state] ?? 'none',
            title: 'Tracker last heard from',
            sub: tracker.name ?? 'Tracker',
        });
    if (tracker && (tracker.observed_at || tracker.received_at))
        items.push({
            key: 'observed',
            time: sessionClock(tracker.observed_at, base),
            tone: d.tracker_state === 'available' ? 'success' : 'warning',
            title: 'Tracker location',
            sub: `Received ${sessionClock(tracker.received_at, base)} · ${
                tracker.latitude != null && tracker.longitude != null
                    ? `${tracker.latitude}, ${tracker.longitude}`
                    : 'Location not available'
            }`,
        });
    if (d.shift_gps) items.push(shiftGpsItem(d.shift_gps, base));
    items.push({
        key: 'planned',
        time: plan ? sessionClock(plan.recordedAt, base, '') : '',
        tone: 'info',
        title: plan?.description
            ? `Planned location — ${plan.description}`
            : 'Planned location',
        sub: (
            <span aria-label="Planned place" role="group" className="block">
                <span className="block">
                    Where the work is meant to happen — not where the worker
                    actually is.
                </span>
                <span className="block">
                    <PlannedPlaceText value={d.planned_location} />
                </span>
            </span>
        ),
    });
    items.push({
        key: 'recorded',
        tone: 'none',
        title: 'Location notes',
        sub: d.location || 'Not recorded',
    });
    return (
        <>
            <Log
                title="Location"
                sub={
                    TRACKER_STATES[d.tracker_state] ?? 'Tracker location unknown'
                }
                items={items}
                limit={items.length}
            />
            <Note icon={Info}>
                When the tracker last checked in and where it was are two
                separate things. Check the times — neither confirms where the
                worker is now or that they are safe.
            </Note>
        </>
    );
}

/**
 * The linked Shift's latest GPS ping, shown only to telemetry viewers (the
 * server sends nothing otherwise), always with its time. An old ping reads
 * "Old position", as on the Control Room map, and is never shown as current.
 */
function shiftGpsItem(
    gps: NonNullable<SafetySession['shift_gps']>,
    base: string | null | undefined,
): LogItem {
    const ms = Date.now() - new Date(gps.captured_at).getTime();
    const seen = !Number.isFinite(ms)
        ? 'time not recorded'
        : ms < 60_000
          ? 'last seen just now'
          : `last seen ${longDuration(ms)} ago`;
    return {
        key: 'shift-gps',
        time: sessionClock(gps.captured_at, base),
        tone: gps.is_old ? 'warning' : 'success',
        title: gps.is_old ? 'Old position · Shift GPS' : 'Shift GPS position',
        sub: `${gps.latitude}, ${gps.longitude} · ${seen}${
            gps.is_old ? ', so it may not be where the worker is now' : ''
        }. From the Shift’s own GPS, not a session check-in.`,
    };
}

/** The paired tracker read as a ring: collection, panic flag, battery. */
export function SessionTrackerRing({ detail: d }: { detail: SafetySession }) {
    const tracker = d.tracker;
    if (!tracker)
        return (
            <QuietLine
                title="Tracker"
                text={
                    TRACKER_STATES[d.tracker_state] ?? 'Tracker location unknown'
                }
            />
        );
    const checks: CheckItem[] = [
        {
            label: 'Tracker signal',
            state: COLLECTION_TONE[tracker.collection_state] ?? 'none',
            note: plain(COLLECTION_LABELS, tracker.collection_state),
        },
        {
            label: 'Panic button',
            state: tracker.panic_active ? 'critical' : 'success',
            note: tracker.panic_active ? 'Active' : 'Not active',
        },
        {
            label: 'Battery',
            state:
                tracker.battery_level == null
                    ? 'none'
                    : tracker.battery_level < 20
                      ? 'warning'
                      : 'success',
            note:
                tracker.battery_level == null
                    ? 'Not recorded'
                    : `${tracker.battery_level}%`,
        },
    ];
    return (
        <RingCard
            value={checks.filter((c) => c.state === 'success').length}
            of={checks.length}
            label={tracker.name ?? 'Tracker'}
            sub={
                TRACKER_STATES[d.tracker_state] ?? 'Tracker location unknown'
            }
            checks={checks}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  History                                                            */
/* ------------------------------------------------------------------ */

const historyLabels: Record<string, string> = {
    status: 'Status',
    location: 'Location',
    activity_description: 'Activity',
    emergency_notes: 'Help request notes',
    check_in_interval_minutes: 'Check-in interval in minutes',
    emergency_episode_number: 'Help request number',
    started_at: 'Started',
    expected_end_at: 'Expected end',
    ended_at: 'Ended',
    last_check_in_at: 'Last check-in',
    emergency_triggered_at: 'Help requested',
    from: 'Previous status',
    to: 'New status',
    check_in_id: 'Check-in',
    episode_id: 'Help request',
    alert_id: 'Response',
};
const CHECK_IN_FIELDS: Record<string, string> = {
    status: 'Check-in status',
    notes: 'Check-in notes',
    checked_in_at: 'Checked in',
    received_at: 'Check-in received',
};
const HISTORY_TONE: Record<string, SgTone> = {
    'healthSafety.loneWorker.session.start': 'info',
    'healthSafety.loneWorker.session.checkIn': 'success',
    'healthSafety.loneWorker.session.emergency': 'critical',
    'healthSafety.loneWorker.session.trackerPanicAcknowledged': 'warning',
    'healthSafety.loneWorker.session.responseAcknowledged': 'info',
    'healthSafety.loneWorker.session.responseResolved': 'success',
    'healthSafety.loneWorker.session.end': 'none',
    'healthSafety.loneWorker.session.delete': 'none',
};

function PlannedPlaceEvidence({ value }: { value: unknown }) {
    if (value == null)
        return <p className="text-subtle">No planned location recorded.</p>;
    const place = plannedPlace(value);
    if (!place)
        return (
            <p className="text-subtle">
                Planned location details aren’t available.
            </p>
        );
    return (
        <dl className="grid gap-3 sm:grid-cols-2">
            <SessionFact label="Planned address or description">
                {place.description}
            </SessionFact>
            <SessionFact label="Planned map point">{place.point}</SessionFact>
            <SessionFact label="How it was set">{place.source}</SessionFact>
            <SessionFact label="Plan recorded">
                {recordedAt(place.recordedAt)}
            </SessionFact>
            <SessionFact label="Plan recorded by">{place.by}</SessionFact>
        </dl>
    );
}

export function SessionHistory({ detail: d }: { detail: SafetySession }) {
    if (!d.history.length)
        return (
            <QuietLine
                title="History"
                text="No activity history is available for this session."
            />
        );
    // newest first; each entry keeps its own captured details
    const events = [...d.history].reverse();
    return (
        <Surface title="History" sub="Open an entry to see what was saved">
            <ol className="sg-log">
                {events.map((event) => (
                    <li key={event.id} className="sg-lr">
                        <time>{sessionClock(event.occurred_at, d.started_at)}</time>
                        <Ring tone={HISTORY_TONE[event.action] ?? 'info'} />
                        <div className="min-w-0">
                            <b>{event.title}</b>
                            <small>
                                {event.actor?.name ?? 'Name not recorded'}
                            </small>
                            {event.note && (
                                <p className="mt-1 text-sm whitespace-pre-wrap">
                                    {event.note}
                                </p>
                            )}
                            {event.legacy && (
                                <small>
                                    Some details weren’t saved at the time. We
                                    don’t fill the gaps with today’s values.
                                </small>
                            )}
                            <details className="mt-1">
                                <summary className="inline-flex cursor-pointer items-center rounded-sm text-sm font-medium text-primary outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                    What was saved
                                </summary>
                                <dl className="grid gap-3 pt-3 sm:grid-cols-2">
                                    {Object.entries(event.snapshot)
                                        .filter(
                                            ([key, value]) =>
                                                historyLabels[key] &&
                                                (value == null ||
                                                    typeof value !== 'object'),
                                        )
                                        .map(([key, value]) => (
                                            <SessionFact
                                                key={key}
                                                label={historyLabels[key]}
                                            >
                                                {typeof value === 'string' &&
                                                key.endsWith('_at')
                                                    ? recordedAt(value)
                                                    : value == null
                                                      ? 'Not recorded'
                                                      : ['status', 'from', 'to'].includes(key)
                                                        ? sessionStatusLabel(value)
                                                        : String(value)}
                                            </SessionFact>
                                        ))}
                                    {event.snapshot.check_in &&
                                    typeof event.snapshot.check_in === 'object'
                                        ? Object.entries(
                                              event.snapshot.check_in,
                                          )
                                              .filter(([key]) =>
                                                  [
                                                      'status',
                                                      'notes',
                                                      'checked_in_at',
                                                      'received_at',
                                                  ].includes(key),
                                              )
                                              .map(([key, value]) => (
                                                  <SessionFact
                                                      key={`check-${key}`}
                                                      label={CHECK_IN_FIELDS[key]}
                                                  >
                                                      {typeof value ===
                                                          'string' &&
                                                      key.endsWith('_at')
                                                          ? recordedAt(value)
                                                          : value == null
                                                            ? 'Not recorded'
                                                            : key === 'status'
                                                              ? checkInLabel(value)
                                                              : String(value)}
                                                  </SessionFact>
                                              ))
                                        : null}
                                </dl>
                                {Object.hasOwn(
                                    event.snapshot,
                                    'planned_location',
                                ) && (
                                    <section
                                        className="space-y-3 pt-4"
                                        aria-label="Captured planned location"
                                    >
                                        <h4 className="text-sm font-medium">
                                            Planned location at this change
                                        </h4>
                                        <PlannedPlaceEvidence
                                            value={
                                                event.snapshot.planned_location
                                            }
                                        />
                                    </section>
                                )}
                            </details>
                        </div>
                        <span />
                    </li>
                ))}
            </ol>
        </Surface>
    );
}

/* ------------------------------------------------------------------ */
/*  Connected work                                                     */
/* ------------------------------------------------------------------ */

function linkedName(record: LinkedRecord): string {
    return `${record.reference_number ?? record.name ?? `Record ${record.id}`}${record.title ? ` · ${record.title}` : ''}`;
}

function linkedBadge(record: LinkedRecord): ReactNode {
    if (!record.status) return null;
    const key = record.display_state ?? record.status;
    const variant: StatusVariant = ['open', 'active', 'overdue'].includes(key)
        ? 'warning'
        : ['closed', 'resolved', 'completed', 'verified'].includes(key)
          ? 'neutral'
          : 'info';
    return <StatusBadge variant={variant}>{plain(LINKED_STATUS, key)}</StatusBadge>;
}

function linkedRow(
    kind: string,
    record: LinkedRecord,
    leading: ReactNode,
    sub?: string,
): RowItem {
    return {
        key: `${kind}-${record.href}`,
        title: kind,
        sub: [
            sub ?? linkedName(record),
            record.due_date ? `Due ${formatDateOnly(record.due_date)}` : null,
        ]
            .filter(Boolean)
            .join(' · '),
        badge: linkedBadge(record),
        leading,
        href: record.href,
    };
}

export function SessionConnections({ detail: d }: { detail: SafetySession }) {
    const c = d.connections;
    const shift = c.shift;
    const rows: RowItem[] = [
        ...(shift
            ? [
                  linkedRow(
                      'Shift',
                      shift,
                      <CalendarClock className="size-4" />,
                      shift.starts_at
                          ? `${sessionClock(shift.starts_at, null)} – ${sessionClock(shift.ends_at, shift.starts_at)}`
                          : 'Time not recorded',
                  ),
              ]
            : []),
        ...(c.site
            ? [linkedRow('Site', c.site, <House className="size-4" />)]
            : []),
        ...(c.client
            ? [linkedRow('Client', c.client, <User className="size-4" />)]
            : []),
        ...c.control_room.map((record) =>
            linkedRow(
                'Control room response',
                record,
                <Siren className="size-4" />,
            ),
        ),
        ...c.incidents.map((record) =>
            linkedRow('Incident', record, <ShieldAlert className="size-4" />),
        ),
        ...c.safety_reviews.map((record) =>
            linkedRow(
                'Safety review',
                record,
                <ShieldAlert className="size-4" />,
            ),
        ),
        ...c.corrective_actions.map((record) =>
            linkedRow(
                'Action',
                record,
                <ShieldAlert className="size-4" />,
            ),
        ),
    ];
    const missing = [
        !shift && 'Shift',
        !c.site && !c.client && 'Site and client',
        !c.control_room.length && 'Control room responses',
        !c.incidents.length && 'Incidents',
        !c.safety_reviews.length && 'Safety reviews',
        !c.corrective_actions.length && 'Actions',
    ].filter((label): label is string => !!label);
    const missingTitle = missing.length
        ? missing.length === 1
            ? missing[0]
            : `${missing.slice(0, -1).map((label, i) => (i ? label.toLowerCase() : label)).join(', ')} and ${missing[missing.length - 1].toLowerCase()}`
        : null;
    return (
        <>
            <RowList
                title="Linked records"
                sub="Each linked record has its own owner. Records you can’t open aren’t shown."
                items={rows}
                empty="Nothing linked that you can see."
            />
            {rows.length && missingTitle ? (
                <QuietLine
                    title={missingTitle}
                    text="Nothing linked that you can see."
                />
            ) : null}
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  People (the side column)                                           */
/* ------------------------------------------------------------------ */

const START_ACTION = 'healthSafety.loneWorker.session.start';

/** Who started the session — the actor on its "Session started" history entry. */
export function sessionStarter(d: SafetySession): string | null {
    return (
        d.history.find((event) => event.action === START_ACTION)?.actor?.name ??
        null
    );
}

export function SessionPeople({ detail: d }: { detail: SafetySession }) {
    const starter = sessionStarter(d);
    const planner = plannedPlace(d.planned_location)?.by ?? null;
    const plannerName =
        planner && planner !== 'Name not recorded' ? planner : null;
    const people: PersonCardItem[] = [
        ...(d.worker?.name
            ? [
                  {
                      name: d.worker.name,
                      sub: `Working alone${d.site?.name ? ` · ${d.site.name}` : ''}`,
                      presence:
                          !d.ended_at && d.status !== 'completed'
                              ? ('on' as const)
                              : undefined,
                  },
              ]
            : []),
        ...(d.client?.name ? [{ name: d.client.name, sub: 'Client' }] : []),
        ...(starter && starter !== d.worker?.name
            ? [
                  {
                      name: starter,
                      sub:
                          plannerName === starter
                              ? 'Started the session and planned the location'
                              : 'Started the session',
                  },
              ]
            : []),
        ...(plannerName &&
        plannerName !== starter &&
        plannerName !== d.worker?.name
            ? [{ name: plannerName, sub: 'Planned the location' }]
            : []),
    ];
    if (!people.length) return null;
    return <PeopleCards title="People" cols={1} items={people} />;
}
