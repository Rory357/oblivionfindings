import { Button } from '@/components/ui/button';
import { formatFileSize } from '@/components/ui/file-dropzone';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTimeLong } from '@/lib/datetime';
import {
    injuryLabel,
    outcomeLabel,
    personTypeLabel,
} from '@/pages/health-safety/first-aid/options';
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { FirstAidLink, FirstAidRecord } from './types';

export const firstAidTime = (value: string | null | undefined) =>
    formatDateTimeLong(value, 'Not recorded');
const outcomeKinds: Record<string, string> = {
    action: 'Action',
    contact: 'Contact',
    claim_followup: 'Claim follow-up',
    observation: 'Observation',
};
const outcomeResults: Record<string, string> = {
    recorded: 'Recorded',
    contacted: 'Contact recorded',
    attempted: 'Attempt recorded',
    unable_to_contact: 'Unable to make contact',
    not_required: 'Recorded as not required',
    other: 'Other',
};
const firstAidOutcomeKind = (value: string | null) =>
    value
        ? (outcomeKinds[value] ?? value.replaceAll('_', ' '))
        : 'Not recorded';
const firstAidOutcomeResult = (value: string | null) =>
    value
        ? (outcomeResults[value] ?? value.replaceAll('_', ' '))
        : 'Not recorded';

export function FirstAidFact({
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
                {children == null || children === ''
                    ? 'Not recorded'
                    : children}
            </dd>
        </div>
    );
}

export function FirstAidOverview({ detail: d }: { detail: FirstAidRecord }) {
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Treatment record</h2>
            <dl className="grid gap-4 sm:grid-cols-2">
                <FirstAidFact label="Person treated">
                    {d.treated_person_name}
                </FirstAidFact>
                <FirstAidFact label="Person type">
                    {personTypeLabel(d.treated_person_type)}
                </FirstAidFact>
                <FirstAidFact label="Site">{d.site?.name}</FirstAidFact>
                <FirstAidFact label="Recorded treatment time">
                    {firstAidTime(d.treatment_date)}
                </FirstAidFact>
                <FirstAidFact label="First aider">
                    {d.first_aider?.name}
                </FirstAidFact>
                <FirstAidFact label="Recorded outcome">
                    {outcomeLabel(d.treatment_outcome)}
                </FirstAidFact>
                <FirstAidFact label="Ambulance called">
                    {d.ambulance_called ? 'Yes' : 'No'}
                </FirstAidFact>
                <FirstAidFact label="Recorded">
                    {firstAidTime(d.created_at)}
                </FirstAidFact>
                {d.archived_at && (
                    <FirstAidFact label="Archived">
                        {firstAidTime(d.archived_at)}
                    </FirstAidFact>
                )}
            </dl>
            {d.status !== 'archived' &&
                d.followups.some((item) => !item.completed_at) && (
                    <p className="text-subtle">
                        Record the outcome of each open follow-up before
                        archiving this treatment.
                    </p>
                )}
        </div>
    );
}

export function FirstAidInjury({ detail: d }: { detail: FirstAidRecord }) {
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Injury and treatment</h2>
            <dl className="grid gap-4 sm:grid-cols-2">
                <FirstAidFact label="Injury or illness">
                    {injuryLabel(d.injury_illness_type)}
                </FirstAidFact>
                <FirstAidFact label="Body part">{d.body_part}</FirstAidFact>
            </dl>
            <dl className="space-y-5">
                <FirstAidFact label="What happened">
                    {d.injury_illness_description}
                </FirstAidFact>
                <FirstAidFact label="Treatment given">
                    {d.treatment_given}
                </FirstAidFact>
                <FirstAidFact label="First-aider notes">
                    {d.first_aider_notes}
                </FirstAidFact>
            </dl>
        </div>
    );
}

function FirstAidRecordLinks({ records }: { records: FirstAidLink[] }) {
    if (!records.length)
        return (
            <p className="text-subtle">
                No linked record is available to view here.
            </p>
        );
    return (
        <ul className="divide-y rounded-lg border">
            {records.map((record) => (
                <li
                    key={record.href}
                    className="flex flex-wrap items-center justify-between gap-3 p-4"
                >
                    <div className="min-w-0 space-y-1">
                        <p className="font-medium break-words">
                            {record.reference ??
                                record.reference_number ??
                                record.name ??
                                `Record ${record.id}`}
                            {record.title ? ` · ${record.title}` : ''}
                        </p>
                        {record.status && (
                            <p className="text-subtle">
                                {record.status.replace(/[_-]/g, ' ')}
                            </p>
                        )}
                    </div>
                    <Button asChild variant="outline" className="frontline-tap">
                        <Link href={record.href}>
                            Open{' '}
                            {record.reference ??
                                record.reference_number ??
                                record.name ??
                                `record ${record.id}`}
                        </Link>
                    </Button>
                </li>
            ))}
        </ul>
    );
}

export function FirstAidIncident({ detail: d }: { detail: FirstAidRecord }) {
    const delivery = d.source_delivery;
    const deliveryLabels: Record<string, string> = {
        pending: 'Pending delivery',
        failed: 'Delivery failed',
        delivered: 'Delivered',
        skipped: 'Not delivered',
        suppressed: 'Suppressed',
    };
    const deliveryLabel =
        deliveryLabels[delivery?.status ?? ''] ?? 'Not confirmed';
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Incident and review</h2>
            <dl>
                <FirstAidFact label="Incident report recorded">
                    {d.incident_reported ? 'Yes' : 'Not recorded here'}
                </FirstAidFact>
            </dl>
            <h3 className="font-medium">Linked incident</h3>
            <FirstAidRecordLinks
                records={d.related_incident ? [d.related_incident] : []}
            />
            <p className="text-subtle">
                The incident keeps its own owner, review and closure. Recording
                treatment does not complete that work.
            </p>
            {delivery && (
                <section
                    aria-label="Safety review delivery"
                    className="space-y-3"
                >
                    <h3 className="font-medium">Safety review delivery</h3>
                    <dl className="grid gap-4 sm:grid-cols-2">
                        <FirstAidFact label="Delivery">
                            {deliveryLabel}
                        </FirstAidFact>
                        <FirstAidFact label="Delivered at">
                            {firstAidTime(delivery.delivered_at)}
                        </FirstAidFact>
                        <FirstAidFact label="Next attempt">
                            {firstAidTime(delivery.next_attempt_at)}
                        </FirstAidFact>
                    </dl>
                    <p className="text-subtle">
                        Delivery records processing of the treatment report. It
                        does not confirm that someone has reviewed it.
                    </p>
                </section>
            )}
            <h3 className="font-medium">Safety reviews</h3>
            <FirstAidRecordLinks records={d.connections.safety_reviews} />
        </div>
    );
}

export function FirstAidFollowups({
    detail: d,
    selected,
    onEdit,
    onComplete,
}: {
    detail: FirstAidRecord;
    selected?: number | null;
    onEdit: (id: number) => void;
    onComplete: (id: number) => void;
}) {
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Follow-up work</h2>
            {!d.followups.length ? (
                <p className="text-subtle">
                    No follow-up work is recorded here.
                </p>
            ) : (
                <ol className="space-y-4">
                    {d.followups.map((item) => (
                        <li
                            key={item.id}
                            id={`followup-${item.id}`}
                            tabIndex={-1}
                            aria-current={
                                selected === item.id ? 'true' : undefined
                            }
                            className="frontline-focus space-y-4 rounded-lg border p-4"
                        >
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="font-medium">
                                    Follow-up {item.id}
                                </h3>
                                <StatusBadge variant="neutral">
                                    {item.completed_at
                                        ? 'Recorded as complete'
                                        : 'Open'}
                                </StatusBadge>
                            </div>
                            <p className="text-sm whitespace-pre-wrap">
                                {item.notes}
                            </p>
                            <dl className="grid gap-4 sm:grid-cols-2">
                                <FirstAidFact label="Assigned to">
                                    {item.assigned_to?.name ?? 'Needs an owner'}
                                </FirstAidFact>
                                <FirstAidFact label="Due">
                                    {item.due_at
                                        ? firstAidTime(item.due_at)
                                        : 'No due time recorded'}
                                </FirstAidFact>
                                {item.completed_at && (
                                    <>
                                        <FirstAidFact label="Completion recorded">
                                            {firstAidTime(item.completed_at)}
                                        </FirstAidFact>
                                        <FirstAidFact label="Completed by">
                                            {item.completed_by?.name ??
                                                'Operator not recorded'}
                                        </FirstAidFact>
                                    </>
                                )}
                            </dl>
                            {item.completed_at && (
                                <section
                                    aria-label={`Follow-up ${item.id} recorded outcome`}
                                    className="space-y-3"
                                >
                                    <h4 className="font-medium">
                                        Recorded outcome
                                    </h4>
                                    <p className="text-sm whitespace-pre-wrap">
                                        {item.completion_notes?.trim()
                                            ? item.completion_notes
                                            : 'No completion outcome was captured in this record.'}
                                    </p>
                                    {item.completion_outcome && (
                                        <dl className="grid gap-4 sm:grid-cols-2">
                                            <FirstAidFact label="Action occurred">
                                                {firstAidTime(
                                                    item.completion_outcome
                                                        .occurred_at,
                                                )}
                                            </FirstAidFact>
                                            <FirstAidFact label="Factual result">
                                                {firstAidOutcomeResult(
                                                    item.completion_outcome
                                                        .result,
                                                )}
                                            </FirstAidFact>
                                            <FirstAidFact label="Outcome type">
                                                {firstAidOutcomeKind(
                                                    item.completion_outcome
                                                        .kind,
                                                )}
                                            </FirstAidFact>
                                            {item.completion_outcome.note && (
                                                <FirstAidFact label="Outcome note">
                                                    {
                                                        item.completion_outcome
                                                            .note
                                                    }
                                                </FirstAidFact>
                                            )}
                                        </dl>
                                    )}
                                    {item.completion_evidence.length > 0 && (
                                        <ul className="space-y-2">
                                            {item.completion_evidence.map(
                                                (file) => (
                                                    <li key={file.id}>
                                                        {file.availability ===
                                                            'available' &&
                                                        file.download_href ? (
                                                            <a
                                                                className="frontline-tap frontline-focus inline-flex items-center text-sm text-primary underline"
                                                                href={
                                                                    file.download_href
                                                                }
                                                            >
                                                                Download{' '}
                                                                {
                                                                    file.original_name
                                                                }
                                                            </a>
                                                        ) : (
                                                            <span className="text-subtle">
                                                                {
                                                                    file.original_name
                                                                }{' '}
                                                                · File
                                                                unavailable
                                                            </span>
                                                        )}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    )}
                                </section>
                            )}
                            {(item.can.edit || item.can.complete) && (
                                <div className="flex flex-wrap gap-3">
                                    {item.can.complete && (
                                        <Button
                                            className="frontline-tap"
                                            onClick={() => onComplete(item.id)}
                                        >
                                            Record completion
                                        </Button>
                                    )}
                                    {item.can.edit && (
                                        <Button
                                            variant="outline"
                                            className="frontline-tap"
                                            onClick={() => onEdit(item.id)}
                                        >
                                            Edit follow-up
                                        </Button>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}

export function FirstAidConnections({ detail: d }: { detail: FirstAidRecord }) {
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Connected work</h2>
            <p className="text-subtle">
                Each record keeps its own access rules and completion checks.
            </p>
            {(
                [
                    [
                        'Person and Site',
                        [d.connections.person, d.connections.site].filter(
                            (record): record is FirstAidLink => record !== null,
                        ),
                    ],
                    ['Equipment context', d.connections.equipment],
                    [
                        'Incident',
                        d.connections.incident ? [d.connections.incident] : [],
                    ],
                    ['Safety reviews', d.connections.safety_reviews],
                    ['Corrective actions', d.connections.corrective_actions],
                    ['Operational responses', d.connections.control_room],
                ] as [string, FirstAidLink[]][]
            ).map(([label, records]) => (
                <section key={label} className="space-y-3">
                    <h3 className="font-medium">{label}</h3>
                    <FirstAidRecordLinks records={records} />
                </section>
            ))}
        </div>
    );
}

export function FirstAidEvidence({
    detail: d,
    onRemove,
}: {
    detail: FirstAidRecord;
    onRemove: (id: number) => void;
}) {
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Evidence</h2>
            {!d.attachments.length ? (
                <p className="text-subtle">No files are recorded here.</p>
            ) : (
                <ul className="space-y-4">
                    {d.attachments.map((file) => (
                        <li
                            key={file.id}
                            className="space-y-3 rounded-lg border p-4"
                        >
                            <h3 className="font-medium break-words">
                                {file.original_name}
                            </h3>
                            <dl className="grid gap-3 sm:grid-cols-2">
                                <FirstAidFact label="Uploaded">
                                    {firstAidTime(file.created_at)}
                                </FirstAidFact>
                                <FirstAidFact label="Uploaded by">
                                    {file.uploaded_by?.name ??
                                        'Operator not recorded'}
                                </FirstAidFact>
                                <FirstAidFact label="File size">
                                    {file.size == null
                                        ? 'Not recorded'
                                        : formatFileSize(file.size)}
                                </FirstAidFact>
                                <FirstAidFact label="Kind">
                                    {file.kind}
                                </FirstAidFact>
                                {file.notes && (
                                    <FirstAidFact label="Notes">
                                        {file.notes}
                                    </FirstAidFact>
                                )}
                                {file.alt_text && (
                                    <FirstAidFact label="Image description">
                                        {file.alt_text}
                                    </FirstAidFact>
                                )}
                            </dl>
                            <div className="flex flex-wrap items-center gap-3">
                                {file.availability === 'available' &&
                                file.download_href ? (
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="frontline-tap"
                                    >
                                        <a href={file.download_href}>
                                            Download {file.original_name}
                                        </a>
                                    </Button>
                                ) : (
                                    <p className="text-subtle">
                                        {file.availability === 'missing'
                                            ? 'File unavailable'
                                            : 'File availability is not confirmed'}
                                    </p>
                                )}
                                {file.can.remove && (
                                    <Button
                                        variant="outline"
                                        className="frontline-tap"
                                        onClick={() => onRemove(file.id)}
                                    >
                                        Remove file
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

const treatmentHistoryFields: Record<string, string> = {
    treated_person_name: 'Person treated',
    treated_person_type: 'Person type',
    treatment_date: 'Recorded treatment time',
    injury_illness_type: 'Injury or illness',
    injury_illness_description: 'What happened',
    body_part: 'Body part',
    treatment_given: 'Treatment given',
    treatment_outcome: 'Recorded outcome',
    ambulance_called: 'Ambulance called',
    first_aider_notes: 'First-aider notes',
    incident_reported: 'Incident report recorded',
    first_aider_id: 'First aider record',
    treated_person_id: 'Staff record',
    site_id: 'Site record',
    client_id: 'Client record',
    incident_id: 'Incident record',
};
const followupHistoryFields: Record<string, string> = {
    id: 'Follow-up record',
    notes: 'Follow-up',
    due_at: 'Due',
    completed_at: 'Completion recorded',
    completion_notes: 'Recorded completion outcome',
    assigned_to_user_id: 'Assigned staff record',
    completed_by_user_id: 'Completed by staff record',
};
const followupOutcomeHistoryFields: Record<string, string> = {
    kind: 'Outcome type',
    result: 'Recorded result',
    occurred_at: 'Outcome occurred',
    note: 'Outcome note',
};
function capturedObject(value: unknown): Record<string, unknown> | null {
    return value && typeof value === 'object' && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
}
function CapturedFacts({
    value,
    labels,
}: {
    value: Record<string, unknown>;
    labels: Record<string, string>;
}) {
    return (
        <dl className="grid gap-3 sm:grid-cols-2">
            {Object.entries(value)
                .filter(
                    ([key, item]) =>
                        labels[key] &&
                        (item == null ||
                            typeof item === 'string' ||
                            typeof item === 'number' ||
                            typeof item === 'boolean'),
                )
                .map(([key, item]) => (
                    <FirstAidFact key={key} label={labels[key]}>
                        {typeof item === 'boolean'
                            ? item
                                ? 'Yes'
                                : 'No'
                            : typeof item === 'string' &&
                                (key.endsWith('_at') ||
                                    key === 'treatment_date')
                              ? firstAidTime(item)
                              : key === 'injury_illness_type' &&
                                  typeof item === 'string'
                                ? injuryLabel(item)
                                : key === 'treatment_outcome' &&
                                    typeof item === 'string'
                                  ? outcomeLabel(item)
                                  : key === 'treated_person_type' &&
                                      typeof item === 'string'
                                    ? personTypeLabel(item)
                                    : key === 'kind' && typeof item === 'string'
                                      ? firstAidOutcomeKind(item)
                                      : key === 'result' &&
                                          typeof item === 'string'
                                        ? firstAidOutcomeResult(item)
                                        : item == null
                                          ? 'Not recorded'
                                          : String(item)}
                    </FirstAidFact>
                ))}
        </dl>
    );
}
function CapturedFollowup({ value }: { value: Record<string, unknown> }) {
    const outcome = capturedObject(value.completion_outcome);
    const evidenceIds = Array.isArray(value.completion_evidence_ids)
        ? value.completion_evidence_ids.filter(
              (id): id is number => Number.isSafeInteger(id) && Number(id) > 0,
          )
        : null;
    return (
        <section className="space-y-3" aria-label="Captured follow-up">
            <h4 className="font-medium">Follow-up at this change</h4>
            <CapturedFacts value={value} labels={followupHistoryFields} />
            {outcome && (
                <CapturedFacts
                    value={outcome}
                    labels={followupOutcomeHistoryFields}
                />
            )}
            {evidenceIds && (
                <dl>
                    <FirstAidFact label="Evidence records captured with completion">
                        {evidenceIds.length
                            ? evidenceIds.join(', ')
                            : 'No evidence selected'}
                    </FirstAidFact>
                </dl>
            )}
        </section>
    );
}
export function FirstAidHistory({ detail: d }: { detail: FirstAidRecord }) {
    return (
        <div className="space-y-5">
            <h2 className="text-section-title">Recorded history</h2>
            {!d.history.length ? (
                <p className="text-subtle">
                    No activity history is available for this record.
                </p>
            ) : (
                <ol className="divide-y rounded-lg border">
                    {d.history.map((event) => (
                        <li key={event.id} className="space-y-3 p-4">
                            <h3 className="font-medium">{event.title}</h3>
                            <p className="text-subtle">
                                {firstAidTime(event.occurred_at)} ·{' '}
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
                                    record values do not replace missing facts.
                                </p>
                            )}
                            <details>
                                <summary className="frontline-tap flex cursor-pointer items-center text-sm font-medium">
                                    Captured details
                                </summary>
                                <div className="space-y-4 pt-3">
                                    <CapturedFacts
                                        value={event.snapshot}
                                        labels={treatmentHistoryFields}
                                    />
                                    {capturedObject(event.snapshot.followup) ? (
                                        <CapturedFollowup
                                            value={
                                                event.snapshot
                                                    .followup as Record<
                                                    string,
                                                    unknown
                                                >
                                            }
                                        />
                                    ) : null}
                                    {Array.isArray(event.snapshot.names) && (
                                        <FirstAidFact label="Recorded file names">
                                            {event.snapshot.names
                                                .filter(
                                                    (name): name is string =>
                                                        typeof name ===
                                                        'string',
                                                )
                                                .join(', ')}
                                        </FirstAidFact>
                                    )}
                                </div>
                            </details>
                        </li>
                    ))}
                </ol>
            )}
        </div>
    );
}
