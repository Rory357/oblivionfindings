/**
 * A treatment record's sections as the approved drawings draw them (pg-hsn-85,
 * 85b–85g, Stephan 23 Oct 2026): the treatment and its afternoon as one time
 * line, injury and treatment as a story, the path to a safety review as a
 * Journey line, follow-ups and evidence as tables (kebab and right-click from
 * one action list), history as a time line, connected work as a list. Every
 * privacy, "doesn't close / doesn't mean reviewed" and missing-file line keeps
 * the code's wording.
 */
import {
    dateTimeWithYear,
    dateWithYear,
} from '@/components/incidents/incident-stages';
import {
    RecordIdentity,
    RecordPerson,
    RecordTable,
} from '@/components/incidents/record-table';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import {
    FactRows,
    Journey,
    Log,
    PeopleCards,
    QuietLine,
    Ring,
    RingCard,
    RowList,
    Story,
    Surface,
    type CheckItem,
    type JourneyStep,
    type LogItem,
    type PersonCardItem,
    type RowItem,
    type SgTone,
} from '@/components/signature/blocks';
import { formatFileSize } from '@/components/ui/file-dropzone';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import {
    formatDate,
    formatDateTimeLong,
    formatTime,
    toDateInput,
} from '@/lib/datetime';
import {
    injuryLabel,
    outcomeLabel,
    outcomeTone,
    personTypeLabel,
} from '@/pages/health-safety/first-aid/options';
import {
    Activity,
    CheckCircle2,
    ClipboardCheck,
    Download,
    FileText,
    HardHat,
    House,
    Pencil,
    PersonStanding,
    Radio,
    ShieldAlert,
    Trash2,
    UserRound,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { humaniseHsValue } from '@/lib/health-safety/hs-labels';
import type { FirstAidFollowup, FirstAidLink, FirstAidRecord } from './types';

export const firstAidTime = (value: string | null | undefined) =>
    formatDateTimeLong(value, 'Not recorded');
const outcomeKinds: Record<string, string> = {
    action: 'Action',
    contact: 'Contact',
    claim_followup: 'ACC claim follow-up',
    observation: 'Observation',
};
const outcomeResults: Record<string, string> = {
    recorded: 'Recorded',
    contacted: 'Contact made',
    attempted: 'Attempt made',
    unable_to_contact: 'Unable to make contact',
    not_required: 'Not required',
    other: 'Other',
};
const firstAidOutcomeKind = (value: string | null) =>
    value
        ? (outcomeKinds[value] ?? humaniseHsValue(value))
        : 'Not recorded';
const firstAidOutcomeResult = (value: string | null) =>
    value
        ? (outcomeResults[value] ?? humaniseHsValue(value))
        : 'Not recorded';
const deliveryLabels: Record<string, string> = {
    pending: 'Waiting to send',
    failed: 'Delivery failed',
    delivered: 'Delivered',
    skipped: 'Not sent',
    suppressed: 'Not sent (switched off)',
};
export const firstAidDeliveryLabel = (status: string | null | undefined) =>
    deliveryLabels[status ?? ''] ?? 'Unknown';
/** Plain words for a linked record's stored status (incident, safety review, action, control room response). */
const linkStatusLabels: Record<string, string> = {
    open: 'Open',
    ack: 'Acknowledged',
    acknowledged: 'Acknowledged',
    triaging: 'Being checked',
    confirmed: 'Confirmed as real',
    resolved: 'Resolved',
    closed: 'Closed',
    dismissed: 'Dismissed',
    pending: 'Waiting',
    draft: 'Draft',
    completed: 'Done',
    verified: 'Checked',
    archived: 'Archived',
    investigating: 'Being investigated',
    in_progress: 'In progress',
};
const linkStatusLabel = (value: string) =>
    linkStatusLabels[value] ?? humaniseHsValue(value);
const attachmentKindLabels: Record<string, string> = {
    photo: 'Photo',
    document: 'Document',
    other: 'Other',
};

/** A record time: the time alone on the treatment's NZ day, otherwise with its date. */
export function firstAidClock(
    value: string | null | undefined,
    base?: string | null,
    fallback = 'Not recorded',
): string {
    if (!value) return fallback;
    return base && toDateInput(value) === toDateInput(base)
        ? formatTime(value)
        : dateTimeWithYear(value, fallback);
}

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

const OUTCOME_RING: Record<string, SgTone> = {
    critical: 'critical',
    warning: 'warning',
    success: 'success',
    neutral: 'info',
};

/* ------------------------------------------------------------------ */
/*  Overview                                                           */
/* ------------------------------------------------------------------ */

const HISTORY_SKIP = /created|recorded/i;

export function FirstAidOverview({ detail: d }: { detail: FirstAidRecord }) {
    const base = d.treatment_date;
    const delivery = d.source_delivery;
    type Step = LogItem & { at: string | null };
    const steps: Step[] = [
        {
            key: 'treated',
            at: d.treatment_date,
            tone: OUTCOME_RING[outcomeTone(d.treatment_outcome)],
            title: `First aid — ${injuryLabel(d.injury_illness_type).toLowerCase()}`,
            sub: [
                d.first_aider?.name ?? 'First aider not recorded',
                outcomeLabel(d.treatment_outcome),
                d.ambulance_called ? 'Ambulance called' : null,
            ]
                .filter(Boolean)
                .join(' · '),
        },
        ...(d.created_at &&
        !d.history.some((event) => HISTORY_SKIP.test(event.title))
            ? [
                  {
                      key: 'recorded',
                      at: d.created_at,
                      tone: 'info' as SgTone,
                      title: 'Treatment recorded',
                  },
              ]
            : []),
        ...d.history.map((event) => ({
            key: event.id,
            at: event.occurred_at,
            tone: 'info' as SgTone,
            title: event.title,
            sub: event.actor?.name ?? 'Name not recorded',
        })),
        ...(delivery?.delivered_at
            ? [
                  {
                      key: 'delivery',
                      at: delivery.delivered_at,
                      tone: 'info' as SgTone,
                      title: 'Sent for safety review',
                      sub: firstAidDeliveryLabel(delivery.status),
                  },
              ]
            : []),
        ...(d.archived_at
            ? [
                  {
                      key: 'archived',
                      at: d.archived_at,
                      tone: 'none' as SgTone,
                      title: 'Archived',
                  },
              ]
            : []),
    ];
    const ordered = steps
        .filter((step, index) => index === 0 || step.at)
        .sort((a, b) => (a.at ?? '').localeCompare(b.at ?? ''))
        .map(({ at, ...step }) => ({
            ...step,
            time: firstAidClock(at, base),
        }));
    return (
        <Log
            title={base ? `${formatDate(base)}, step by step` : 'Step by step'}
            sub="From the treatment record and its history"
            items={ordered}
            limit={ordered.length}
        />
    );
}

/** What stops archiving, read as a ring: each follow-up and whether its outcome is recorded. */
export function FirstAidArchiveRing({ detail: d }: { detail: FirstAidRecord }) {
    if (d.status === 'archived' || !d.followups.length) return null;
    const open = d.followups.filter((item) => !item.completed_at);
    const today = toDateInput(new Date());
    const checks: CheckItem[] = d.followups.map((item) => ({
        label: item.notes,
        state: item.completed_at ? 'success' : 'warning',
        note: item.completed_at
            ? 'Outcome recorded'
            : !item.assigned_to
              ? 'Needs an owner'
              : item.due_at
                ? toDateInput(item.due_at) === today
                    ? 'Today'
                    : dateWithYear(item.due_at)
                : undefined,
    }));
    return (
        <RingCard
            value={d.followups.length - open.length}
            of={d.followups.length}
            label={
                open.length
                    ? 'Not ready to archive'
                    : 'Every follow-up has an outcome'
            }
            sub={
                open.length
                    ? 'Record the outcome of each open follow-up before archiving this treatment.'
                    : undefined
            }
            checks={checks}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Injury and treatment                                               */
/* ------------------------------------------------------------------ */

export function FirstAidInjury({ detail: d }: { detail: FirstAidRecord }) {
    return (
        <Story
            title="Injury and treatment"
            facts={[
                { icon: Activity, text: injuryLabel(d.injury_illness_type) },
                {
                    icon: PersonStanding,
                    text: d.body_part || 'Body part not recorded',
                },
            ]}
            paras={[
                {
                    h: 'What happened',
                    t: d.injury_illness_description || 'Not recorded',
                },
                { h: 'Treatment given', t: d.treatment_given || 'Not recorded' },
                {
                    h: 'First aider notes',
                    t: d.first_aider_notes || 'Not recorded',
                },
            ]}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Linked records                                                     */
/* ------------------------------------------------------------------ */

function linkName(record: FirstAidLink): string {
    return `${record.reference ?? record.reference_number ?? record.name ?? `Record ${record.id}`}${record.title ? ` · ${record.title}` : ''}`;
}

function linkBadge(status: string | null | undefined): ReactNode {
    if (!status) return null;
    const variant: StatusVariant = ['open', 'pending', 'draft'].includes(status)
        ? 'warning'
        : ['closed', 'resolved', 'completed', 'verified', 'archived'].includes(
                status,
            )
          ? 'neutral'
          : 'info';
    return <StatusBadge variant={variant}>{linkStatusLabel(status)}</StatusBadge>;
}

function linkRow(
    record: FirstAidLink,
    kind?: string,
    leading?: ReactNode,
): RowItem {
    return {
        key: record.href,
        title: kind ?? linkName(record),
        sub: kind ? linkName(record) : undefined,
        badge: linkBadge(record.status),
        leading,
        href: record.href,
    };
}

/* ------------------------------------------------------------------ */
/*  Incident and review                                                */
/* ------------------------------------------------------------------ */

export function FirstAidIncident({ detail: d }: { detail: FirstAidRecord }) {
    const delivery = d.source_delivery;
    const incident = d.related_incident;
    const reviews = d.connections.safety_reviews;
    const steps: JourneyStep[] = [
        {
            label: 'Incident reported',
            meta: d.incident_reported
                ? incident
                    ? `Yes · ${incident.reference ?? incident.reference_number ?? `Incident report · Ref ${incident.id}`}`
                    : 'Yes'
                : 'No incident reported',
            state: d.incident_reported ? 'done' : 'todo',
        },
        {
            label: 'Sent for safety review',
            meta: delivery
                ? `${firstAidDeliveryLabel(delivery.status)}${delivery.delivered_at ? ` · ${dateTimeWithYear(delivery.delivered_at)}` : ''}`
                : 'Not sent from here',
            state:
                delivery?.status === 'delivered'
                    ? 'done'
                    : delivery
                      ? 'now'
                      : 'todo',
            tone:
                delivery?.status === 'failed'
                    ? 'critical'
                    : delivery && delivery.status !== 'delivered'
                      ? 'warning'
                      : undefined,
        },
        {
            label: 'Health and safety reviews it',
            meta: reviews[0]
                ? `${linkName(reviews[0])}${reviews[0].status ? ` · ${linkStatusLabel(reviews[0].status).toLowerCase()}` : ''}`
                : 'No review you can see',
            state:
                reviews[0] && reviews[0].status && reviews[0].status !== 'open'
                    ? 'done'
                    : delivery?.status === 'delivered'
                      ? 'now'
                      : 'todo',
        },
    ];
    return (
        <>
            <Journey title="How it reaches a safety review" steps={steps} />
            <RowList
                title="Linked incident"
                sub="The incident still needs its own review — recording first aid doesn’t close it."
                items={incident ? [linkRow(incident)] : []}
                empty="Nothing linked that you can see."
            />
            {delivery ? (
                <FactRows
                    aria-label="Safety review delivery"
                    title="Safety review delivery"
                    sub="This shows the report was sent to health and safety — not that someone has reviewed it yet."
                    rows={[
                        ['Sending', firstAidDeliveryLabel(delivery.status)],
                        ['Sent', firstAidTime(delivery.delivered_at)],
                        ['Next try', firstAidTime(delivery.next_attempt_at)],
                    ]}
                />
            ) : null}
            <RowList
                title="Safety reviews"
                items={reviews.map((record) => linkRow(record))}
                empty="Nothing linked that you can see."
            />
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Follow-ups                                                         */
/* ------------------------------------------------------------------ */

function dueCell(item: FirstAidFollowup) {
    if (!item.due_at)
        return (
            <span className="text-muted-foreground">No due time recorded</span>
        );
    const today = toDateInput(item.due_at) === toDateInput(new Date());
    return (
        <RecordIdentity
            title={today ? 'Today' : dateWithYear(item.due_at)}
            sub={formatTime(item.due_at)}
        />
    );
}

function RecordedOutcome({ item }: { item: FirstAidFollowup }) {
    return (
        <Surface
            aria-label={`Follow-up outcome · Ref ${item.id}`}
            title="Follow-up · outcome"
            sub={item.notes}
        >
            <div className="grid gap-3 px-[18px] pt-1 pb-4">
                <p className="text-sm whitespace-pre-wrap">
                    {item.completion_notes?.trim()
                        ? item.completion_notes
                        : 'No outcome was written down.'}
                </p>
                <dl className="grid gap-4 sm:grid-cols-2">
                    <FirstAidFact label="Marked done">
                        {firstAidTime(item.completed_at)}
                    </FirstAidFact>
                    <FirstAidFact label="Done by">
                        {item.completed_by?.name ?? 'Name not recorded'}
                    </FirstAidFact>
                    {item.completion_outcome && (
                        <>
                            <FirstAidFact label="Done on">
                                {firstAidTime(
                                    item.completion_outcome.occurred_at,
                                )}
                            </FirstAidFact>
                            <FirstAidFact label="Result">
                                {firstAidOutcomeResult(
                                    item.completion_outcome.result,
                                )}
                            </FirstAidFact>
                            <FirstAidFact label="Outcome type">
                                {firstAidOutcomeKind(
                                    item.completion_outcome.kind,
                                )}
                            </FirstAidFact>
                            {item.completion_outcome.note && (
                                <FirstAidFact label="Outcome note">
                                    {item.completion_outcome.note}
                                </FirstAidFact>
                            )}
                        </>
                    )}
                </dl>
                {item.completion_evidence.length > 0 && (
                    <ul className="grid gap-2">
                        {item.completion_evidence.map((file) => (
                            <li key={file.id}>
                                {file.availability === 'available' &&
                                file.download_href ? (
                                    <a
                                        className="inline-flex items-center gap-1.5 rounded-sm text-sm font-medium text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        href={file.download_href}
                                    >
                                        <Download
                                            aria-hidden="true"
                                            className="size-4"
                                        />
                                        Download {file.original_name}
                                    </a>
                                ) : (
                                    <span className="text-subtle">
                                        {file.original_name} · File missing
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </Surface>
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
    if (!d.followups.length)
        return (
            <QuietLine
                title="Follow-up work"
                text="No follow-ups yet."
            />
        );
    const actionsFor = (item: FirstAidFollowup): MenuItem[] =>
        compactMenu([
            item.can.complete && {
                label: 'Record outcome',
                icon: CheckCircle2,
                onClick: () => onComplete(item.id),
            },
            item.can.edit && {
                label: 'Edit follow-up',
                icon: Pencil,
                onClick: () => onEdit(item.id),
            },
        ]);
    const anyActions = d.followups.some(
        (item) => item.can.complete || item.can.edit,
    );
    // open work first (soonest due), then the recorded ones
    const rows = [...d.followups].sort((a, b) =>
        !!a.completed_at !== !!b.completed_at
            ? a.completed_at
                ? 1
                : -1
            : (a.due_at ?? '9999').localeCompare(b.due_at ?? '9999'),
    );
    const completed = rows.filter((item) => item.completed_at);
    return (
        <>
            <RecordTable
                title="Follow-up work"
                sub={
                    anyActions
                        ? 'Right-click a row to record its outcome or edit it'
                        : undefined
                }
                ariaLabel="Follow-up work"
                rows={rows}
                rowKey={(item) => item.id}
                actionsFor={actionsFor}
                menuTitle={(item) => `Follow-up · Ref ${item.id}`}
                menuIcon={ClipboardCheck}
                columns={[
                    {
                        key: 'followup',
                        label: 'Follow-up',
                        className: 'min-w-[16rem]',
                        cell: (item) => (
                            <span
                                id={`followup-${item.id}`}
                                tabIndex={-1}
                                aria-current={
                                    selected === item.id ? 'true' : undefined
                                }
                                className="block rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring aria-[current=true]:ring-2 aria-[current=true]:ring-ring/60"
                            >
                                <RecordIdentity
                                    title={`Follow-up · Ref ${item.id}`}
                                    sub={
                                        <span className="whitespace-pre-wrap">
                                            {item.notes}
                                        </span>
                                    }
                                />
                            </span>
                        ),
                    },
                    {
                        key: 'owner',
                        label: 'Owner',
                        nowrap: true,
                        cell: (item) =>
                            item.assigned_to ? (
                                <RecordPerson name={item.assigned_to.name} />
                            ) : (
                                <StatusBadge variant="warning">
                                    Needs an owner
                                </StatusBadge>
                            ),
                    },
                    {
                        key: 'due',
                        label: 'Due',
                        nowrap: true,
                        cell: dueCell,
                    },
                    {
                        key: 'status',
                        label: 'Status',
                        nowrap: true,
                        cell: (item) => (
                            <StatusBadge
                                variant={item.completed_at ? 'success' : 'info'}
                            >
                                {item.completed_at
                                    ? 'Done'
                                    : 'Open'}
                            </StatusBadge>
                        ),
                    },
                ]}
            />
            {completed.map((item) => (
                <RecordedOutcome key={item.id} item={item} />
            ))}
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Evidence                                                           */
/* ------------------------------------------------------------------ */

export function FirstAidEvidence({
    detail: d,
    onRemove,
}: {
    detail: FirstAidRecord;
    onRemove: (id: number) => void;
}) {
    if (!d.attachments.length)
        return <QuietLine title="Evidence" text="No files yet." />;
    return (
        <RecordTable
            title="Evidence"
            sub="Download from the row menu"
            ariaLabel="Evidence"
            rows={d.attachments}
            rowKey={(file) => file.id}
            actionsFor={(file) =>
                compactMenu([
                    file.availability === 'available' &&
                        !!file.download_href && {
                            label: 'Download',
                            icon: Download,
                            onClick: () => {
                                window.location.assign(file.download_href!);
                            },
                        },
                    file.can.remove && {
                        label: 'Remove file',
                        icon: Trash2,
                        danger: true,
                        onClick: () => onRemove(file.id),
                    },
                ])
            }
            menuTitle={(file) => file.original_name}
            menuIcon={FileText}
            columns={[
                {
                    key: 'file',
                    label: 'File',
                    className: 'min-w-[16rem] max-w-[26rem]',
                    cell: (file) => (
                        <RecordIdentity
                            title={
                                file.availability === 'available' &&
                                file.download_href ? (
                                    <a
                                        href={file.download_href}
                                        onClick={(event) =>
                                            event.stopPropagation()
                                        }
                                        className="rounded-sm break-words text-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        {file.original_name}
                                    </a>
                                ) : (
                                    <span className="break-words">
                                        {file.original_name}
                                    </span>
                                )
                            }
                            sub={
                                <>
                                    {file.notes ? (
                                        <span className="block whitespace-pre-wrap">
                                            {file.notes}
                                        </span>
                                    ) : null}
                                    {file.alt_text ? (
                                        <span className="block">
                                            Image description: {file.alt_text}
                                        </span>
                                    ) : null}
                                    {file.availability !== 'available' ||
                                    !file.download_href ? (
                                        <span className="block">
                                            {file.availability === 'missing'
                                                ? 'File missing'
                                                : 'This file can’t be opened right now'}
                                        </span>
                                    ) : null}
                                </>
                            }
                        />
                    ),
                },
                {
                    key: 'kind',
                    label: 'Type',
                    nowrap: true,
                    cell: (file) =>
                        file.kind ? (
                            (attachmentKindLabels[file.kind] ??
                            humaniseHsValue(file.kind))
                        ) : (
                            <span className="text-muted-foreground">
                                Not recorded
                            </span>
                        ),
                },
                {
                    key: 'uploaded',
                    label: 'Uploaded',
                    nowrap: true,
                    cell: (file) =>
                        file.created_at ? (
                            <RecordIdentity
                                title={dateWithYear(file.created_at)}
                                sub={formatTime(file.created_at)}
                            />
                        ) : (
                            <span className="text-muted-foreground">
                                Not recorded
                            </span>
                        ),
                },
                {
                    key: 'by',
                    label: 'Uploaded by',
                    nowrap: true,
                    cell: (file) =>
                        file.uploaded_by ? (
                            <RecordPerson name={file.uploaded_by.name} />
                        ) : (
                            <span className="text-muted-foreground">
                                Name not recorded
                            </span>
                        ),
                },
                {
                    key: 'size',
                    label: 'Size',
                    nowrap: true,
                    cell: (file) =>
                        file.size == null
                            ? 'Not recorded'
                            : formatFileSize(file.size),
                },
            ]}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  History                                                            */
/* ------------------------------------------------------------------ */

const treatmentHistoryFields: Record<string, string> = {
    treated_person_name: 'Person treated',
    treated_person_type: 'Person type',
    treatment_date: 'Treatment time',
    injury_illness_type: 'Injury or illness',
    injury_illness_description: 'What happened',
    body_part: 'Body part',
    treatment_given: 'Treatment given',
    treatment_outcome: 'Outcome',
    ambulance_called: 'Ambulance called',
    first_aider_notes: 'First aider notes',
    incident_reported: 'Incident reported',
    first_aider_id: 'First aider (ref)',
    treated_person_id: 'Staff member treated (ref)',
    site_id: 'Site (ref)',
    client_id: 'Client (ref)',
    incident_id: 'Incident (ref)',
};
const followupHistoryFields: Record<string, string> = {
    id: 'Follow-up (ref)',
    notes: 'Follow-up',
    due_at: 'Due',
    completed_at: 'Marked done',
    completion_notes: 'What happened',
    assigned_to_user_id: 'Owner (staff ref)',
    completed_by_user_id: 'Done by (staff ref)',
};
const followupOutcomeHistoryFields: Record<string, string> = {
    kind: 'Outcome type',
    result: 'Result',
    occurred_at: 'Done on',
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
        <section className="space-y-3" aria-label="Saved follow-up">
            <h4 className="text-sm font-medium">Follow-up at this change</h4>
            <CapturedFacts value={value} labels={followupHistoryFields} />
            {outcome && (
                <CapturedFacts
                    value={outcome}
                    labels={followupOutcomeHistoryFields}
                />
            )}
            {evidenceIds && (
                <dl>
                    <FirstAidFact label="Files used (refs)">
                        {evidenceIds.length
                            ? evidenceIds.join(', ')
                            : 'No files chosen'}
                    </FirstAidFact>
                </dl>
            )}
        </section>
    );
}

function historyTone(title: string): SgTone {
    const text = title.toLowerCase();
    if (/archiv|remov/.test(text)) return 'none';
    if (/outcome|complet/.test(text)) return 'success';
    return 'info';
}

export function FirstAidHistory({ detail: d }: { detail: FirstAidRecord }) {
    if (!d.history.length)
        return (
            <QuietLine
                title="History"
                text="No history yet for this record."
            />
        );
    // newest first; each entry keeps its own captured details
    const events = [...d.history].reverse();
    const base = d.treatment_date;
    return (
        <Surface title="History" sub="Open an entry to see what was saved">
            <ol className="sg-log">
                {events.map((event) => (
                    <li key={event.id} className="sg-lr">
                        <time>{firstAidClock(event.occurred_at, base)}</time>
                        <Ring tone={historyTone(event.title)} />
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
                                        <dl>
                                            <FirstAidFact label="File names">
                                                {event.snapshot.names
                                                    .filter(
                                                        (
                                                            name,
                                                        ): name is string =>
                                                            typeof name ===
                                                            'string',
                                                    )
                                                    .join(', ')}
                                            </FirstAidFact>
                                        </dl>
                                    )}
                                </div>
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

export function FirstAidConnections({ detail: d }: { detail: FirstAidRecord }) {
    const c = d.connections;
    const rows: RowItem[] = [
        ...(c.person
            ? [linkRow(c.person, 'Person', <UserRound className="size-4" />)]
            : []),
        ...(c.site
            ? [linkRow(c.site, 'Site', <House className="size-4" />)]
            : []),
        ...c.equipment.map((record) =>
            linkRow(record, 'Equipment', <HardHat className="size-4" />),
        ),
        ...(c.incident
            ? [
                  linkRow(
                      c.incident,
                      'Incident',
                      <ShieldAlert className="size-4" />,
                  ),
              ]
            : []),
        ...c.safety_reviews.map((record) =>
            linkRow(
                record,
                'Safety review',
                <ClipboardCheck className="size-4" />,
            ),
        ),
        ...c.corrective_actions.map((record) =>
            linkRow(
                record,
                'Action',
                <ClipboardCheck className="size-4" />,
            ),
        ),
        ...c.control_room.map((record) =>
            linkRow(
                record,
                'Control room response',
                <Radio className="size-4" />,
            ),
        ),
    ];
    const missing = [
        !c.person && !c.site && 'Person and site',
        !c.equipment.length && 'Equipment',
        !c.incident && 'Incident',
        !c.safety_reviews.length && 'Safety reviews',
        !c.corrective_actions.length && 'Actions',
        !c.control_room.length && 'Control room responses',
    ].filter((label): label is string => !!label);
    const missingTitle = missing.length
        ? missing.length === 1
            ? missing[0]
            : `${missing
                  .slice(0, -1)
                  .map((label, i) => (i ? label.toLowerCase() : label))
                  .join(', ')} and ${missing[missing.length - 1].toLowerCase()}`
        : null;
    return (
        <>
            <RowList
                title="Linked records"
                sub="Each linked record has its own owner and its own rules on who can see it."
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

export function FirstAidPeople({ detail: d }: { detail: FirstAidRecord }) {
    // name → what they did on this treatment, in order
    const roles = new Map<string, string[]>();
    const add = (name: string | null | undefined, role: string) => {
        if (!name) return;
        roles.set(name, [...(roles.get(name) ?? []), role]);
    };
    add(
        d.treated_person_name,
        `${personTypeLabel(d.treated_person_type)} · ${d.site?.name ?? 'Site not recorded'}`,
    );
    add(d.first_aider?.name, 'First aider');
    const owners = new Map<string, number[]>();
    for (const item of d.followups.filter((entry) => !entry.completed_at)) {
        if (!item.assigned_to) continue;
        owners.set(item.assigned_to.name, [
            ...(owners.get(item.assigned_to.name) ?? []),
            item.id,
        ]);
    }
    for (const [name, ids] of owners)
        add(
            name,
            ids.length === 1
                ? 'Owns a follow-up'
                : `Owns ${ids.length} follow-ups`,
        );
    const people: PersonCardItem[] = [...roles].map(([name, list]) => ({
        name,
        sub: list.join(' · '),
        flag:
            name === d.treated_person_name && d.ambulance_called ? (
                <StatusBadge variant="critical">Ambulance called</StatusBadge>
            ) : undefined,
    }));
    return <PeopleCards title="People" cols={1} items={people} />;
}

/** The open follow-up that comes next (soonest due; undated ones last). */
export function nextFollowup(d: FirstAidRecord): FirstAidFollowup | null {
    return (
        [...d.followups]
            .filter((item) => !item.completed_at)
            .sort((a, b) =>
                (a.due_at ?? '9999').localeCompare(b.due_at ?? '9999'),
            )[0] ?? null
    );
}

