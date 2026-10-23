import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import type {
    DrillCommand,
    DrillSection,
} from '@/lib/health-safety/drill-navigation';
import {
    FINDING_STATUS_META,
    FINDING_TYPE_LABEL,
    SEVERITY_TONE,
    titleCase,
} from '@/pages/health-safety/drills/shared';
import { Link } from '@inertiajs/react';
import { DrillFact } from './drill-facts';
import type { DrillLinkedRecord, DrillWorkspaceDetail } from './types';

type Move = (
    section: DrillSection,
    command?: DrillCommand | null,
    finding?: number | null,
    participant?: number | null,
) => void;
export function DrillParticipants({
    detail: d,
    move,
}: {
    detail: DrillWorkspaceDetail;
    move: Move;
}) {
    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-section-title">Participants</h2>
                <div className="flex flex-wrap gap-3">
                    {d.exports.participants_href && (
                        <a
                            href={d.exports.participants_href}
                            className="frontline-tap frontline-focus inline-flex items-center text-primary underline"
                        >
                            Export participants
                        </a>
                    )}
                    {d.can.add_participant && (
                        <Button
                            className="frontline-tap"
                            onClick={() =>
                                move('participants', 'add-participant')
                            }
                        >
                            Add participant
                        </Button>
                    )}
                </div>
            </div>
            <p className="text-subtle">
                {d.counts.assigned} assigned · {d.counts.attended} with
                attendance confirmed · {d.counts.not_attended} not marked as
                attended. Assignment alone does not establish attendance or
                training competence.
            </p>
            {d.participants.length ? (
                <ul className="space-y-3">
                    {d.participants.map((person) => (
                        <li
                            key={person.id}
                            className="space-y-3 rounded-lg border border-border p-4"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h3 className="font-semibold">{person.name}</h3>
                                <StatusBadge
                                    variant={
                                        person.attended ? 'success' : 'neutral'
                                    }
                                >
                                    {person.attended
                                        ? 'Attendance confirmed'
                                        : 'Not marked as attended'}
                                </StatusBadge>
                            </div>
                            <dl className="space-y-3">
                                <DrillFact label="Role">
                                    {person.role
                                        ? titleCase(person.role)
                                        : null}
                                </DrillFact>
                                <DrillFact label="Notes">
                                    {person.notes}
                                </DrillFact>
                                <DrillFact label="Support or accommodations">
                                    {person.accommodations}
                                </DrillFact>
                            </dl>
                            {person.can.edit && (
                                <Button
                                    className="frontline-tap"
                                    variant="outline"
                                    onClick={() =>
                                        move(
                                            'participants',
                                            'edit-participant',
                                            null,
                                            person.id,
                                        )
                                    }
                                >
                                    Update {person.name}
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-subtle">
                    No participants are recorded for this drill.
                </p>
            )}
        </div>
    );
}

export function DrillFindings({
    detail: d,
    selected,
    move,
}: {
    detail: DrillWorkspaceDetail;
    selected: number | null;
    move: Move;
}) {
    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="text-section-title">Findings</h2>
                {d.can.add_finding && (
                    <Button
                        className="frontline-tap"
                        onClick={() => move('findings', 'add-finding')}
                    >
                        Record finding
                    </Button>
                )}
            </div>
            <p className="text-subtle">
                {d.counts.findings_open} open · {d.counts.findings_overdue}{' '}
                overdue. Each finding has its own responsible person and
                resolution evidence.
            </p>
            {selected &&
                !d.findings.some((finding) => finding.id === selected) && (
                    <p role="status">
                        The selected finding is not available in this drill.
                    </p>
                )}
            {d.findings.length ? (
                <ul className="space-y-4">
                    {d.findings.map((finding) => {
                        const status = FINDING_STATUS_META[finding.status] ?? {
                            label: titleCase(finding.status),
                            tone: 'neutral' as const,
                        };
                        return (
                            <li
                                key={finding.id}
                                id={`finding-${finding.id}`}
                                className={`space-y-4 rounded-lg border p-4 ${selected === finding.id ? 'border-primary bg-muted/20' : 'border-border'}`}
                            >
                                <div className="flex flex-wrap items-center gap-3">
                                    <h3 className="font-semibold">
                                        {FINDING_TYPE_LABEL[
                                            finding.finding_type
                                        ] ?? titleCase(finding.finding_type)}
                                    </h3>
                                    <StatusBadge variant={status.tone}>
                                        {status.label}
                                    </StatusBadge>
                                    {finding.severity && (
                                        <StatusBadge
                                            variant={
                                                SEVERITY_TONE[
                                                    finding.severity
                                                ] ?? 'neutral'
                                            }
                                        >
                                            {titleCase(finding.severity)}{' '}
                                            severity
                                        </StatusBadge>
                                    )}
                                    {finding.is_overdue && (
                                        <StatusBadge variant="critical">
                                            Overdue
                                        </StatusBadge>
                                    )}
                                </div>
                                {selected === finding.id && (
                                    <p className="text-subtle">
                                        Selected finding
                                    </p>
                                )}
                                <p className="text-sm whitespace-pre-wrap">
                                    {finding.description}
                                </p>
                                <dl className="grid gap-4 sm:grid-cols-2">
                                    <DrillFact label="Responsible person">
                                        {finding.assignee_name ?? 'Unassigned'}
                                    </DrillFact>
                                    <DrillFact label="Due date">
                                        {formatDateOnly(
                                            finding.due_date,
                                            'Not recorded',
                                        )}
                                    </DrillFact>
                                    <DrillFact label="Proposed corrective work">
                                        {finding.corrective_action}
                                    </DrillFact>
                                </dl>
                                {finding.corrective_action_record && (
                                    <div className="space-y-2 rounded-lg border border-border p-3">
                                        <h4 className="font-medium">
                                            Required remedy
                                        </h4>
                                        <Link
                                            href={
                                                finding.corrective_action_record
                                                    .href
                                            }
                                            className="frontline-tap frontline-focus inline-flex items-center text-primary underline"
                                        >
                                            {finding.corrective_action_record
                                                .reference_number ??
                                                'Corrective action'}{' '}
                                            ·{' '}
                                            {
                                                finding.corrective_action_record
                                                    .title
                                            }
                                        </Link>
                                        <p className="text-subtle">
                                            {finding.corrective_action_record
                                                .display_state ??
                                                titleCase(
                                                    finding
                                                        .corrective_action_record
                                                        .status,
                                                )}{' '}
                                            ·{' '}
                                            {finding.corrective_action_record
                                                .assigned_to?.name ??
                                                'Unassigned'}
                                        </p>
                                        <p className="text-subtle">
                                            Effectiveness:{' '}
                                            {finding.corrective_action_record
                                                .effectiveness_confirmed ===
                                            true
                                                ? 'Confirmed'
                                                : finding
                                                        .corrective_action_record
                                                        .effectiveness_confirmed ===
                                                    false
                                                  ? 'Not confirmed'
                                                  : 'Not recorded'}
                                        </p>
                                    </div>
                                )}
                                {finding.resolve_blocked_reason && (
                                    <p className="text-subtle">
                                        {finding.resolve_blocked_reason}
                                    </p>
                                )}
                                {(finding.resolution_notes ||
                                    finding.resolved_at) && (
                                    <dl className="space-y-3">
                                        <DrillFact label="Resolution recorded on">
                                            {formatDateOnly(
                                                finding.resolved_at,
                                                'Date not recorded',
                                            )}
                                        </DrillFact>
                                        <DrillFact label="Resolution notes">
                                            {finding.resolution_notes}
                                        </DrillFact>
                                    </dl>
                                )}
                                {finding.resolution_evidence_ids.length > 0 && (
                                    <div className="space-y-2">
                                        <h4 className="font-medium">
                                            Resolution evidence
                                        </h4>
                                        {finding.resolution_evidence_ids.map(
                                            (id) => {
                                                const file = d.attachments.find(
                                                    (item) => item.id === id,
                                                );
                                                return file ? (
                                                    <a
                                                        key={id}
                                                        href={file.url}
                                                        className="frontline-tap frontline-focus flex items-center break-all text-primary underline"
                                                    >
                                                        {file.original_name}
                                                    </a>
                                                ) : null;
                                            },
                                        )}
                                    </div>
                                )}
                                <div className="flex flex-wrap gap-3">
                                    {finding.can.edit && (
                                        <Button
                                            className="frontline-tap"
                                            variant="outline"
                                            onClick={() =>
                                                move(
                                                    'findings',
                                                    'edit-finding',
                                                    finding.id,
                                                )
                                            }
                                        >
                                            Update finding
                                        </Button>
                                    )}
                                    {(finding.can.create_action ||
                                        finding.can.link_action) && (
                                        <Button
                                            className="frontline-tap"
                                            variant="outline"
                                            onClick={() =>
                                                move(
                                                    'findings',
                                                    'corrective-action',
                                                    finding.id,
                                                )
                                            }
                                        >
                                            Connect corrective action
                                        </Button>
                                    )}
                                    {finding.can.resolve && (
                                        <Button
                                            className="frontline-tap"
                                            onClick={() =>
                                                move(
                                                    'findings',
                                                    'resolve-finding',
                                                    finding.id,
                                                )
                                            }
                                        >
                                            Record resolution
                                        </Button>
                                    )}
                                </div>
                            </li>
                        );
                    })}
                </ul>
            ) : (
                <p className="text-subtle">
                    No findings are recorded for this drill.
                </p>
            )}
        </div>
    );
}

const HISTORY_LABELS: Record<string, string> = {
    title: 'Title',
    drill_type: 'Drill type',
    outcome: 'Outcome',
    scenario_description: 'Scenario',
    assembly_point: 'Assembly point',
    evacuation_scheme: 'Evacuation scheme',
    weather_conditions: 'Weather',
    observer_notes: 'Observer notes',
    improvements_identified: 'Improvements identified',
    scheduled_at: 'Scheduled for',
    started_at: 'Started',
    completed_at: 'Completed',
    duration_minutes: 'Duration (minutes)',
    evacuation_time_seconds: 'Evacuation time (seconds)',
    total_participants: 'Participant count',
    residents_evacuated: 'Recorded residents evacuated',
    is_unannounced: 'Unannounced',
    all_areas_checked: 'All areas checked',
    assembly_point_reached: 'Assembly point reached',
    roll_call_completed: 'Roll-call completed',
    finding_type: 'Finding type',
    description: 'Description',
    severity: 'Severity',
    status: 'Status',
    corrective_action: 'Proposed corrective work',
    due_date: 'Due date',
    resolved_at: 'Resolution date',
    resolution_notes: 'Resolution notes',
    role: 'Role',
    attended: 'Attendance confirmed',
    notes: 'Notes',
    accommodations: 'Support or accommodations',
};
function HistoricalFacts({ snapshot }: { snapshot: Record<string, unknown> }) {
    return (
        <dl className="grid gap-3 sm:grid-cols-2">
            {Object.entries(snapshot)
                .filter(
                    ([key, value]) =>
                        HISTORY_LABELS[key] &&
                        (value == null ||
                            ['string', 'number', 'boolean'].includes(
                                typeof value,
                            )),
                )
                .map(([key, value]) => {
                    let display =
                        value == null
                            ? 'Not recorded'
                            : typeof value === 'boolean'
                              ? value
                                  ? 'Yes'
                                  : 'No'
                              : String(value);
                    if (typeof value === 'string') {
                        if (['due_date', 'resolved_at'].includes(key))
                            display = formatDateOnly(value, 'Not recorded');
                        else if (
                            [
                                'scheduled_at',
                                'started_at',
                                'completed_at',
                            ].includes(key)
                        )
                            display = formatDateTimeLong(value, 'Not recorded');
                        else if (
                            [
                                'drill_type',
                                'outcome',
                                'finding_type',
                                'severity',
                                'status',
                                'role',
                            ].includes(key)
                        )
                            display = titleCase(value);
                    }
                    return (
                        <DrillFact key={key} label={HISTORY_LABELS[key]}>
                            {display}
                        </DrillFact>
                    );
                })}
        </dl>
    );
}
export function DrillHistory({ detail: d }: { detail: DrillWorkspaceDetail }) {
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">History</h2>
            {d.history.length ? (
                <ol className="space-y-4">
                    {d.history.map((item) => (
                        <li
                            key={item.id}
                            className="space-y-3 rounded-lg border border-border p-4"
                        >
                            <h3 className="font-semibold">{item.title}</h3>
                            {Array.isArray(item.snapshot.names) && (
                                <ul className="space-y-1 text-sm">
                                    {item.snapshot.names
                                        .filter(
                                            (name): name is string =>
                                                typeof name === 'string',
                                        )
                                        .map((name, index) => (
                                            <li
                                                key={`${index}-${name}`}
                                                className="break-all"
                                            >
                                                {name}
                                            </li>
                                        ))}
                                </ul>
                            )}
                            <p className="text-subtle">
                                {formatDateTimeLong(
                                    item.occurred_at,
                                    'Time not recorded',
                                )}{' '}
                                · {item.actor?.name ?? 'Actor not recorded'}
                            </p>
                            {item.legacy && (
                                <p className="text-subtle">
                                    Legacy entry. Its original details may be
                                    incomplete.
                                </p>
                            )}
                            {item.note && (
                                <p className="text-sm whitespace-pre-wrap">
                                    {item.note}
                                </p>
                            )}
                            <details className="rounded-lg border border-border px-3">
                                <summary className="frontline-tap frontline-focus flex cursor-pointer items-center text-sm font-medium">
                                    Recorded details
                                </summary>
                                <div className="space-y-4 pb-3">
                                    <HistoricalFacts snapshot={item.snapshot} />
                                    {['finding', 'participant'].map((key) => {
                                        const child = item.snapshot[key];
                                        return child &&
                                            typeof child === 'object' &&
                                            !Array.isArray(child) ? (
                                            <HistoricalFacts
                                                key={key}
                                                snapshot={
                                                    child as Record<
                                                        string,
                                                        unknown
                                                    >
                                                }
                                            />
                                        ) : null;
                                    })}
                                </div>
                            </details>
                        </li>
                    ))}
                </ol>
            ) : (
                <p className="text-subtle">
                    No history entries are available for this drill.
                </p>
            )}
        </div>
    );
}

function ConnectedRecords({
    title,
    records,
}: {
    title: string;
    records: DrillLinkedRecord[];
}) {
    return (
        <div className="space-y-3">
            <h3 className="font-semibold">{title}</h3>
            {records.length ? (
                <ul className="space-y-2">
                    {records.map((record) => (
                        <li
                            key={record.id}
                            className="rounded-lg border border-border p-3"
                        >
                            <Link
                                href={record.href}
                                className="frontline-tap frontline-focus inline-flex items-center text-primary underline"
                            >
                                {record.reference_number ?? title}{' '}
                                {record.title ? `· ${record.title}` : ''}
                            </Link>
                            <p className="text-subtle">
                                {record.display_state ??
                                    titleCase(record.status)}
                            </p>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-subtle">
                    No linked records are available here.
                </p>
            )}
        </div>
    );
}
export function DrillConnections({
    detail: d,
    move,
}: {
    detail: DrillWorkspaceDetail;
    move: Move;
}) {
    const delivery = d.source_delivery;
    const eventStatus = {
        pending: 'Waiting to record safety review',
        failed: 'Safety review recording failed',
        delivered: 'Safety review recorded',
        skipped: 'Safety review recording skipped',
    };
    const signalStatus = {
        pending: 'Waiting to process response signal',
        failed: 'Response signal processing failed',
        delivered: 'Response signal processed',
        suppressed: 'Response signal suppressed by response rules',
        skipped: 'Response signal skipped',
    };
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Connected work</h2>
            {delivery && (
                <div className="space-y-3 rounded-lg border border-border p-4">
                    <h3 className="font-semibold">Completion follow-up</h3>
                    <dl className="space-y-3">
                        <DrillFact label="Safety review">
                            {delivery.event_status
                                ? eventStatus[delivery.event_status]
                                : 'Not recorded'}
                        </DrillFact>
                        <DrillFact label="Response signal">
                            {delivery.signal_status
                                ? signalStatus[delivery.signal_status]
                                : 'Not recorded'}
                        </DrillFact>
                        {delivery.next_attempt_at && (
                            <DrillFact label="Next scheduled retry">
                                {formatDateTimeLong(delivery.next_attempt_at)}
                            </DrillFact>
                        )}
                    </dl>
                    <p className="text-subtle">
                        These stages show recorded follow-up processing. Open
                        the connected record to see its actual owner, response
                        and progress.
                    </p>
                </div>
            )}
            <ConnectedRecords
                title="Safety reviews"
                records={d.connections.safety_reviews}
            />
            <ConnectedRecords
                title="Corrective actions"
                records={d.connections.corrective_actions}
            />
            <ConnectedRecords
                title="Control Room"
                records={d.connections.control_room}
            />
            <div className="space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h3 className="font-semibold">Related drills</h3>
                    {d.can.schedule_next && (
                        <Button
                            className="frontline-tap"
                            onClick={() => move('connections', 'schedule-next')}
                        >
                            Schedule next drill
                        </Button>
                    )}
                </div>
                {[
                    ...(d.connections.previous_drill
                        ? [
                              {
                                  ...d.connections.previous_drill,
                                  relation: 'Previous drill',
                              },
                          ]
                        : []),
                    ...d.connections.next_drills.map((item) => ({
                        ...item,
                        relation: 'Next drill',
                    })),
                ].map((item) => (
                    <div
                        key={`${item.relation}-${item.id}`}
                        className="space-y-2 rounded-lg border border-border p-3"
                    >
                        <p className="text-subtle">{item.relation}</p>
                        <Link
                            href={item.href}
                            className="frontline-tap frontline-focus inline-flex items-center text-primary underline"
                        >
                            {item.reference} · {item.title}
                        </Link>
                        <p className="text-subtle">
                            {titleCase(item.status)} ·{' '}
                            {formatDateTimeLong(
                                item.scheduled_at,
                                'Schedule not recorded',
                            )}
                        </p>
                    </div>
                ))}
                {!d.connections.previous_drill &&
                    !d.connections.next_drills.length && (
                        <p className="text-subtle">
                            No previous or next drill is available here.
                        </p>
                    )}
            </div>
        </div>
    );
}
