import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
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

export function SessionOverview({ detail: d }: { detail: SafetySession }) {
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Session details</h2>
            <dl className="grid gap-4 sm:grid-cols-2">
                <SessionFact label="Worker">{d.worker?.name}</SessionFact>
                <SessionFact label="Site">{d.site?.name}</SessionFact>
                <SessionFact label="Client">
                    {d.client?.name ?? 'No client available to view'}
                </SessionFact>
                <SessionFact label="Started">
                    {recordedAt(d.started_at)}
                </SessionFact>
                <SessionFact label="Expected end">
                    {recordedAt(d.expected_end_at)}
                </SessionFact>
                <SessionFact label="Ended">
                    {d.ended_at ? recordedAt(d.ended_at) : 'Not ended'}
                </SessionFact>
                <SessionFact label="Check-in interval">
                    {d.check_in_interval_minutes} minutes
                </SessionFact>
                <SessionFact label="Location description">
                    {d.location}
                </SessionFact>
            </dl>
            <dl>
                <SessionFact label="Activity">
                    {d.activity_description}
                </SessionFact>
            </dl>
            <p className="text-subtle">
                Starting a session sets its first checkpoint. It does not record
                a worker check-in or confirm that the worker is safe.
            </p>
        </div>
    );
}

export function SessionCheckIns({ detail: d }: { detail: SafetySession }) {
    const o = d.observation;
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Check-ins</h2>
            <p className="font-medium">{observationLabel(o.state)}</p>
            <dl className="grid gap-4 sm:grid-cols-2">
                <SessionFact label="Last check-in occurred">
                    {recordedAt(o.real_check_in_at)}
                </SessionFact>
                <SessionFact label="Received">
                    {recordedAt(o.received_at)}
                </SessionFact>
                <SessionFact label="Next checkpoint">
                    {d.ended_at ? 'Session ended' : recordedAt(o.next_due_at)}
                </SessionFact>
                <SessionFact label="Monitor">
                    {monitorLabel(o.monitor.state)}
                </SessionFact>
                <SessionFact label="Last monitor check">
                    {recordedAt(o.monitor.checked_at)}
                </SessionFact>
                <SessionFact label="Last failed monitor check">
                    {recordedAt(o.monitor.failed_at)}
                </SessionFact>
            </dl>
            {o.receipt_source === 'legacy_created_at' && (
                <p className="text-subtle">
                    The last receipt time comes from the historical record
                    creation time. A separate delivery receipt was not recorded.
                </p>
            )}
            {d.check_ins.length === 0 ? (
                <p className="text-subtle">
                    No check-in records are available for this session.
                </p>
            ) : (
                <ol className="divide-y rounded-lg border">
                    {d.check_ins.map((check) => (
                        <li key={check.id} className="space-y-3 p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="font-medium">
                                    Check-in {check.id}
                                </h3>
                                <StatusBadge
                                    variant={
                                        check.status === 'emergency'
                                            ? 'critical'
                                            : check.status === 'concern'
                                              ? 'warning'
                                              : 'neutral'
                                    }
                                >
                                    {check.status === 'ok'
                                        ? 'Reported OK'
                                        : words(check.status)}
                                </StatusBadge>
                            </div>
                            <dl className="grid gap-3 sm:grid-cols-2">
                                <SessionFact label="Occurred">
                                    {recordedAt(check.checked_in_at)}
                                </SessionFact>
                                <SessionFact
                                    label={
                                        check.receipt_source ===
                                        'legacy_created_at'
                                            ? 'Historical record created'
                                            : 'Received'
                                    }
                                >
                                    {recordedAt(check.received_at)}
                                </SessionFact>
                                <SessionFact label="Recorded by">
                                    {check.recorded_by?.name ??
                                        'Operator not recorded'}
                                </SessionFact>
                                <SessionFact label="Notes">
                                    {check.notes}
                                </SessionFact>
                                {check.location_lat != null &&
                                    check.location_lng != null && (
                                        <SessionFact label="Position recorded with this check-in">
                                            {String(check.location_lat)},{' '}
                                            {String(check.location_lng)}
                                            <span className="text-subtle block">
                                                Historical check-in position;
                                                this does not establish the
                                                worker's current location.
                                            </span>
                                        </SessionFact>
                                    )}
                            </dl>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}

function RecordLinks({
    records,
    empty,
}: {
    records: LinkedRecord[];
    empty: string;
}) {
    if (!records.length) return <p className="text-subtle">{empty}</p>;
    return (
        <ul className="divide-y rounded-lg border">
            {records.map((record) => (
                <li
                    key={record.href}
                    className="flex flex-wrap items-center justify-between gap-3 p-4"
                >
                    <div className="min-w-0 space-y-1">
                        <p className="font-medium break-words">
                            {record.reference_number ??
                                record.name ??
                                `Record ${record.id}`}
                            {record.title ? ` · ${record.title}` : ''}
                        </p>
                        {record.status && (
                            <p className="text-sm">
                                {words(record.display_state ?? record.status)}
                            </p>
                        )}
                        {record.due_date && (
                            <p className="text-subtle">
                                Due {formatDateOnly(record.due_date)}
                            </p>
                        )}
                    </div>
                    <Button asChild variant="outline" className="frontline-tap">
                        <Link href={record.href}>
                            Open{' '}
                            {record.reference_number ??
                                record.name ??
                                `record ${record.id}`}
                        </Link>
                    </Button>
                </li>
            ))}
        </ul>
    );
}

export function SessionResponse({ detail: d }: { detail: SafetySession }) {
    const delivery = d.source_delivery;
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Help request and response</h2>
            <dl className="grid gap-4 sm:grid-cols-2">
                <SessionFact label="Help requested">
                    {recordedAt(d.emergency_triggered_at)}
                </SessionFact>
                <SessionFact label="Delivery">
                    {deliveryLabel(delivery)}
                </SessionFact>
                <SessionFact label="Delivered at">
                    {recordedAt(delivery?.delivered_at)}
                </SessionFact>
                <SessionFact label="Next delivery attempt">
                    {recordedAt(delivery?.next_attempt_at)}
                </SessionFact>
            </dl>
            <dl>
                <SessionFact label="Help request notes">
                    {d.emergency_notes}
                </SessionFact>
            </dl>
            {delivery && (
                <p className="text-subtle">
                    Request {delivery.episode_number} · {delivery.attempts}{' '}
                    delivery {delivery.attempts === 1 ? 'attempt' : 'attempts'}.
                    Delivery records processing of the request; it does not
                    confirm that a responder has accepted it.
                </p>
            )}
            <h3 className="font-medium">Operational response</h3>
            {!d.connections.control_room.length && (
                <p className="text-subtle">
                    No response record is available to view here. This does not
                    confirm whether a response exists or someone is attending.
                </p>
            )}
            {d.connections.control_room.map((alert) => (
                <div key={alert.id} className="space-y-3 rounded-lg border p-4">
                    <h4 className="font-medium">
                        {alert.reference_number ?? `Response ${alert.id}`}
                    </h4>
                    <dl className="grid gap-3 sm:grid-cols-2">
                        <SessionFact label="Response status">
                            {words(alert.status ?? 'unknown')}
                        </SessionFact>
                        <SessionFact label="Assigned responder">
                            {typeof alert.assigned_to === 'object'
                                ? (alert.assigned_to?.name ?? 'Needs an owner')
                                : (alert.assigned_to ?? 'Needs an owner')}
                        </SessionFact>
                        <SessionFact label="Acknowledged">
                            {recordedAt(alert.acknowledged_at)}
                        </SessionFact>
                        <SessionFact label="Resolved">
                            {recordedAt(alert.resolved_at)}
                        </SessionFact>
                    </dl>
                    <Button asChild variant="outline" className="frontline-tap">
                        <Link href={alert.href}>Continue response</Link>
                    </Button>
                </div>
            ))}
            <p className="text-subtle">
                Acknowledging a response or tracker panic does not record a
                check-in, confirm worker safety or close linked incident and
                review work.
            </p>
            {d.legacy_alert_history.length > 0 && (
                <div className="space-y-3">
                    <h3 className="font-medium">Historical alerts</h3>
                    <p className="text-subtle">
                        Read-only historical records. These do not confirm a
                        current operational response.
                    </p>
                    <ul className="divide-y rounded-lg border">
                        {d.legacy_alert_history.map((alert) => (
                            <li key={alert.id} className="space-y-1 p-4">
                                <p>
                                    {words(alert.type)} ·{' '}
                                    {words(alert.recorded_status)}
                                </p>
                                <p className="text-subtle">
                                    {recordedAt(alert.triggered_at)}
                                </p>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}

export function SessionLocation({ detail: d }: { detail: SafetySession }) {
    const tracker = d.tracker;
    const states: Record<string, string> = {
        restricted: 'Location is restricted',
        session_ended: 'Live location is unavailable after this session ended',
        not_assigned: 'No tracker assigned',
        integrity_error: 'Tracker assignment needs review',
        collection_stopped: 'Tracker collection stopped',
        available: 'Location observation available',
        stale: 'Location observation is old',
        unknown: 'No location observation confirmed',
    };
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Location evidence</h2>
            <section className="space-y-3" aria-label="Planned place">
                <h3 className="font-medium">Planned location</h3>
                <PlannedPlaceEvidence value={d.planned_location} />
                <p className="text-subtle">
                    This is the intended place for the work, not evidence of the
                    worker's position.
                </p>
            </section>
            <h3 className="font-medium">Tracker observations</h3>
            <p className="font-medium">
                {states[d.tracker_state] ??
                    'Location availability is not confirmed'}
            </p>
            <dl>
                <SessionFact label="Recorded location description">
                    {d.location}
                </SessionFact>
            </dl>
            {tracker && (
                <dl className="grid gap-4 sm:grid-cols-2">
                    <SessionFact label="Tracker">{tracker.name}</SessionFact>
                    <SessionFact label="Collection">
                        {words(tracker.collection_state)}
                    </SessionFact>
                    <SessionFact label="Location observed">
                        {recordedAt(tracker.observed_at)}
                    </SessionFact>
                    <SessionFact label="Location received">
                        {recordedAt(tracker.received_at)}
                    </SessionFact>
                    <SessionFact label="Coordinates">
                        {tracker.latitude != null && tracker.longitude != null
                            ? `${tracker.latitude}, ${tracker.longitude}`
                            : 'No permitted observation available'}
                    </SessionFact>
                    <SessionFact label="Last device contact">
                        {recordedAt(tracker.last_seen_at)}
                    </SessionFact>
                    <SessionFact label="Battery">
                        {tracker.battery_level == null
                            ? 'Not recorded'
                            : `${tracker.battery_level}%`}
                    </SessionFact>
                    <SessionFact label="Tracker panic flag">
                        {tracker.panic_active ? 'Active' : 'Not active'}
                    </SessionFact>
                </dl>
            )}
            <p className="text-subtle">
                Device contact and location observations are separate facts.
                Neither confirms the worker’s current location or safety without
                checking the observation time and response.
            </p>
        </div>
    );
}

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

function PlannedPlaceEvidence({ value }: { value: unknown }) {
    if (value == null)
        return <p className="text-subtle">No planned location recorded.</p>;
    if (
        typeof value !== 'object' ||
        !('kind' in value) ||
        value.kind !== 'planned'
    )
        return (
            <p className="text-subtle">
                Planned location details are unavailable.
            </p>
        );
    const place = value as Record<string, unknown>;
    const methods: Record<string, string> = {
        manual: 'Coordinates entered',
        selected_address: 'Address selected',
        site_address: 'Site address',
        shift_address: 'Shift address',
    };
    const actor =
        place.recorded_by &&
        typeof place.recorded_by === 'object' &&
        'name' in place.recorded_by &&
        typeof place.recorded_by.name === 'string'
            ? place.recorded_by.name
            : 'Operator not recorded';
    return (
        <dl className="grid gap-3 sm:grid-cols-2">
            <SessionFact label="Planned address or description">
                {typeof place.description === 'string'
                    ? place.description
                    : null}
            </SessionFact>
            <SessionFact label="Planned map point">
                {typeof place.lat === 'number' && typeof place.lng === 'number'
                    ? `${place.lat}, ${place.lng}`
                    : 'Address only; no map point recorded'}
            </SessionFact>
            <SessionFact label="Plan source">
                {typeof place.method === 'string'
                    ? (methods[place.method] ?? 'Source not recorded')
                    : 'Source not recorded'}
            </SessionFact>
            <SessionFact label="Plan recorded">
                {recordedAt(
                    typeof place.recorded_at === 'string'
                        ? place.recorded_at
                        : null,
                )}
            </SessionFact>
            <SessionFact label="Plan recorded by">{actor}</SessionFact>
        </dl>
    );
}
export function SessionHistory({ detail: d }: { detail: SafetySession }) {
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Recorded history</h2>
            {!d.history.length ? (
                <p className="text-subtle">
                    No activity history is available for this session.
                </p>
            ) : (
                <ol className="divide-y rounded-lg border">
                    {d.history.map((event) => (
                        <li key={event.id} className="space-y-3 p-4">
                            <h3 className="font-medium">{event.title}</h3>
                            <p className="text-subtle">
                                {recordedAt(event.occurred_at)} ·{' '}
                                {event.actor?.name ?? 'Operator not recorded'}
                            </p>
                            {event.note && (
                                <p className="text-sm whitespace-pre-wrap">
                                    {event.note}
                                </p>
                            )}
                            {event.legacy && (
                                <p className="text-subtle">
                                    Historical details are incomplete. Current
                                    values do not replace missing recorded
                                    facts.
                                </p>
                            )}
                            <details>
                                <summary className="frontline-tap flex cursor-pointer items-center text-sm font-medium">
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
                                        <h4 className="font-medium">
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
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}

export function SessionConnections({ detail: d }: { detail: SafetySession }) {
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Connected work</h2>
            <p className="text-subtle">
                Each source keeps its own permissions, owner and completion
                checks. Records you cannot access are not shown here.
            </p>
            {(
                [
                    [
                        'Workforce shift',
                        d.connections.shift
                            ? [
                                  {
                                      ...d.connections.shift,
                                      name: `Shift ${d.connections.shift.id}`,
                                  },
                              ]
                            : [],
                    ],
                    [
                        'Site and client',
                        [d.connections.site, d.connections.client].filter(
                            (item): item is LinkedRecord => item !== null,
                        ),
                    ],
                    ['Operational responses', d.connections.control_room],
                    ['Incidents', d.connections.incidents],
                    ['Safety reviews', d.connections.safety_reviews],
                    ['Corrective actions', d.connections.corrective_actions],
                ] as [string, LinkedRecord[]][]
            ).map(([label, records]) => (
                <section key={label} className="space-y-3">
                    <h3 className="font-medium">{label}</h3>
                    <RecordLinks
                        records={records}
                        empty="No linked record is available to view here."
                    />
                </section>
            ))}
        </div>
    );
}
