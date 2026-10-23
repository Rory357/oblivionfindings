/**
 * The safety session's sections as the approved drawings draw them (pg-hsn-82,
 * 82b–82f, Stephan 23 Oct 2026): the session as a short story, check-ins,
 * location readings and history as time lines, tracker and automatic-check
 * states as rings, connected work as a list. Every "doesn't confirm the worker
 * is safe" line keeps the code's wording.
 */
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
import type { ReactNode } from 'react';
import type { LinkedRecord, SafetySession, SessionDelivery } from './types';

export const recordedAt = (value: string | null | undefined) =>
    formatDateTimeLong(value, 'Not recorded');
export const words = (value: string) =>
    value.replace(/[_-]/g, ' ').replace(/^./, (letter) => letter.toUpperCase());
export const deliveryLabel = (delivery: SessionDelivery | null) =>
    ({
        pending: 'Pending delivery',
        failed: 'Delivery failed',
        delivered: 'Delivered',
        suppressed: 'Suppressed',
        skipped: 'Not delivered',
        unknown: 'Not confirmed',
    })[delivery?.status ?? 'unknown'];
export const observationLabel = (state: string) =>
    ({
        no_check_in: 'No check-in received',
        received: 'Check-in received',
        stale: 'Check-in is old',
        unknown: 'Timing not confirmed',
    })[state] ?? 'Timing not confirmed';
export const monitorLabel = (state: string) =>
    ({
        current: 'Monitor checked recently',
        stale: 'Monitor check is old',
        failed: 'Monitor check failed',
        unknown: 'Monitor not confirmed',
    })[state] ?? 'Monitor not confirmed';

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
                : 'No client available to view',
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
                Starting a session sets its first checkpoint. It does not record
                a worker check-in or confirm that the worker is safe.
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
                          : 'Next checkpoint',
                      sub: observationLabel(o.state),
                  },
              ]
            : []),
        ...ordered
            .map((check, index) => ({
                key: check.id,
                time: sessionClock(check.checked_in_at, base),
                tone: CHECK_TONE[check.status] ?? ('info' as SgTone),
                title: `Check-in ${index + 1} · ${check.status === 'ok' ? 'Reported OK' : words(check.status)}`,
                sub: (
                    <>
                        {check.receipt_source === 'legacy_created_at'
                            ? 'Historical record created'
                            : 'Received'}{' '}
                        {sessionClock(check.received_at, base)} ·{' '}
                        {check.recorded_by?.name ?? 'Operator not recorded'}
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
                                {String(check.location_lng)}. Historical
                                check-in position; this does not establish the
                                worker's current location.
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
                <QuietLine text="No check-in records are available for this session." />
            ) : null}
            {o.receipt_source === 'legacy_created_at' && (
                <Note icon={Info}>
                    The last receipt time comes from the historical record
                    creation time. A separate delivery receipt was not recorded.
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
                        ? `Request ${delivery.episode_number} · ${delivery.attempts} delivery ${delivery.attempts === 1 ? 'attempt' : 'attempts'}. Delivery records processing of the request; it does not confirm that a responder has accepted it.`
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
                        text: `Next delivery attempt: ${recordedAt(delivery?.next_attempt_at)}`,
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
                        title={alert.reference_number ?? `Response ${alert.id}`}
                        sub="Operational response"
                        action={{ label: 'Continue response', href: alert.href }}
                        rows={[
                            [
                                'Response status',
                                words(alert.status ?? 'unknown'),
                            ],
                            [
                                'Assigned responder',
                                assignedName(alert) ?? 'Needs an owner',
                            ],
                            ['Acknowledged', recordedAt(alert.acknowledged_at)],
                            ['Resolved', recordedAt(alert.resolved_at)],
                        ]}
                    />
                ))
            ) : (
                <QuietLine
                    title="Operational response"
                    text="No response record is available to view here. This does not confirm whether a response exists or someone is attending."
                />
            )}
            <Note tone="warning" icon={AlertTriangle}>
                Acknowledging a response or tracker panic does not record a
                check-in, confirm worker safety or close linked incident and
                review work.
            </Note>
            {d.legacy_alert_history.length > 0 && (
                <Log
                    title="Historical alerts"
                    sub="Read-only historical records. These do not confirm a current operational response."
                    limit={d.legacy_alert_history.length}
                    items={d.legacy_alert_history.map((alert) => ({
                        key: alert.id,
                        time: sessionClock(alert.triggered_at, base),
                        tone: 'none' as SgTone,
                        title: `${words(alert.type)} · ${words(alert.recorded_status)}`,
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
    restricted: 'Location is restricted',
    session_ended: 'Live location is unavailable after this session ended',
    not_assigned: 'No tracker assigned',
    integrity_error: 'Tracker assignment needs review',
    collection_stopped: 'Tracker collection stopped',
    available: 'Location observation available',
    stale: 'Location observation is old',
    unknown: 'No location observation confirmed',
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
                ? (PLAN_METHODS[place.method] ?? 'Source not recorded')
                : 'Source not recorded',
        recordedAt:
            typeof place.recorded_at === 'string' ? place.recorded_at : null,
        by:
            place.recorded_by &&
            typeof place.recorded_by === 'object' &&
            'name' in place.recorded_by &&
            typeof place.recorded_by.name === 'string'
                ? place.recorded_by.name
                : 'Operator not recorded',
    };
}

/** The planned place as one line, or why it can't be shown. */
function PlannedPlaceText({ value }: { value: unknown }) {
    if (value == null) return <>No planned location recorded.</>;
    const place = plannedPlace(value);
    if (!place) return <>Planned location details are unavailable.</>;
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
            title: 'Last device contact',
            sub: tracker.name ?? 'Tracker',
        });
    if (tracker && (tracker.observed_at || tracker.received_at))
        items.push({
            key: 'observed',
            time: sessionClock(tracker.observed_at, base),
            tone: d.tracker_state === 'available' ? 'success' : 'warning',
            title: 'Location observed',
            sub: `Received ${sessionClock(tracker.received_at, base)} · ${
                tracker.latitude != null && tracker.longitude != null
                    ? `${tracker.latitude}, ${tracker.longitude}`
                    : 'No permitted observation available'
            }`,
        });
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
                    This is the intended place for the work, not evidence of
                    the worker's position.
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
        title: 'Recorded location description',
        sub: d.location || 'Not recorded',
    });
    return (
        <>
            <Log
                title="Location"
                sub={
                    TRACKER_STATES[d.tracker_state] ??
                    'Location availability is not confirmed'
                }
                items={items}
                limit={items.length}
            />
            <Note icon={Info}>
                Device contact and location observations are separate facts.
                Neither confirms the worker’s current location or safety without
                checking the observation time and response.
            </Note>
        </>
    );
}

/** The paired tracker read as a ring: collection, panic flag, battery. */
export function SessionTrackerRing({ detail: d }: { detail: SafetySession }) {
    const tracker = d.tracker;
    if (!tracker)
        return (
            <QuietLine
                title="Tracker"
                text={
                    TRACKER_STATES[d.tracker_state] ??
                    'Location availability is not confirmed'
                }
            />
        );
    const checks: CheckItem[] = [
        {
            label: 'Collection',
            state: COLLECTION_TONE[tracker.collection_state] ?? 'none',
            note: words(tracker.collection_state),
        },
        {
            label: 'Tracker panic flag',
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
                TRACKER_STATES[d.tracker_state] ??
                'Location availability is not confirmed'
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
    last_check_in_at: 'Last check-in occurred',
    emergency_triggered_at: 'Help requested',
    from: 'Previous status',
    to: 'New status',
    check_in_id: 'Check-in',
    episode_id: 'Help request',
    alert_id: 'Response',
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
                Planned location details are unavailable.
            </p>
        );
    return (
        <dl className="grid gap-3 sm:grid-cols-2">
            <SessionFact label="Planned address or description">
                {place.description}
            </SessionFact>
            <SessionFact label="Planned map point">{place.point}</SessionFact>
            <SessionFact label="Plan source">{place.source}</SessionFact>
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
        <Surface title="History" sub="Open an entry to see its captured details">
            <ol className="sg-log">
                {events.map((event) => (
                    <li key={event.id} className="sg-lr">
                        <time>{sessionClock(event.occurred_at, d.started_at)}</time>
                        <Ring tone={HISTORY_TONE[event.action] ?? 'info'} />
                        <div className="min-w-0">
                            <b>{event.title}</b>
                            <small>
                                {event.actor?.name ?? 'Operator not recorded'}
                            </small>
                            {event.note && (
                                <p className="mt-1 text-sm whitespace-pre-wrap">
                                    {event.note}
                                </p>
                            )}
                            {event.legacy && (
                                <small>
                                    Historical details are incomplete. Current
                                    values do not replace missing recorded
                                    facts.
                                </small>
                            )}
                            <details className="mt-1">
                                <summary className="inline-flex cursor-pointer items-center rounded-sm text-sm font-medium text-primary outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                    Captured details
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
                                                      label={`Check-in ${words(key).toLowerCase()}`}
                                                  >
                                                      {typeof value ===
                                                          'string' &&
                                                      key.endsWith('_at')
                                                          ? recordedAt(value)
                                                          : value == null
                                                            ? 'Not recorded'
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
    return <StatusBadge variant={variant}>{words(key)}</StatusBadge>;
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
                      'Workforce shift',
                      shift,
                      <CalendarClock className="size-4" />,
                      [
                          `Shift ${shift.id}`,
                          shift.starts_at
                              ? `${sessionClock(shift.starts_at, null)} – ${sessionClock(shift.ends_at, shift.starts_at)}`
                              : null,
                      ]
                          .filter(Boolean)
                          .join(' · '),
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
                'Operational response',
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
                'Corrective action',
                record,
                <ShieldAlert className="size-4" />,
            ),
        ),
    ];
    const missing = [
        !shift && 'Workforce shift',
        !c.site && !c.client && 'Site and client',
        !c.control_room.length && 'Operational responses',
        !c.incidents.length && 'Incidents',
        !c.safety_reviews.length && 'Safety reviews',
        !c.corrective_actions.length && 'Corrective actions',
    ].filter((label): label is string => !!label);
    const missingTitle = missing.length
        ? missing.length === 1
            ? missing[0]
            : `${missing.slice(0, -1).map((label, i) => (i ? label.toLowerCase() : label)).join(', ')} and ${missing[missing.length - 1].toLowerCase()}`
        : null;
    return (
        <>
            <RowList
                title="Connected work"
                sub="Each source keeps its own permissions, owner and completion checks. Records you cannot access are not shown here."
                items={rows}
                empty="No linked record is available to view here."
            />
            {rows.length && missingTitle ? (
                <QuietLine
                    title={missingTitle}
                    text="No linked record is available to view here."
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
        planner && planner !== 'Operator not recorded' ? planner : null;
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
