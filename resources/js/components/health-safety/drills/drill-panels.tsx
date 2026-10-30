/**
 * The drill record's sections (pg-hsn-66c, 66d, 66f, 66g): participants as
 * people cards, findings as cards with who, when, the fix and whether it's
 * checked, history as a log whose entries open their recorded details, and
 * connected work as related drills on a line beside the records after it.
 * Attendance, competence, remedy and delivery wording stays word for word.
 */
import {
    FactRows,
    JourneyLine,
    Log,
    Note,
    PeopleCards,
    PlaceCards,
    QuietLine,
    Ring,
    Surface,
    type JourneyStep,
    type LogItem,
    type SgTone,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    formatDateOnly,
    formatDateTimeLong,
    formatTime,
    toDateInput,
} from '@/lib/datetime';
import type {
    DrillCommand,
    DrillSection,
} from '@/lib/health-safety/drill-navigation';
import {
    FINDING_STATUS_META,
    FINDING_TYPE_LABEL,
    PARTICIPANT_ROLE_OPTIONS,
    SEVERITY_TONE,
    outcomeMeta,
    titleCase,
} from '@/pages/health-safety/drills/shared';
import { Link } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { urgentFindings } from './drill-facts';
import type {
    DrillLinkedRecord,
    DrillSummary,
    DrillWorkspaceDetail,
    DrillWorkspaceFinding,
} from './types';

type Move = (
    section: DrillSection,
    command?: DrillCommand | null,
    finding?: number | null,
    participant?: number | null,
) => void;

/* ------------------------------------------------------------------ */
/*  Participants                                                       */
/* ------------------------------------------------------------------ */

export function DrillParticipants({
    detail: d,
    move,
}: {
    detail: DrillWorkspaceDetail;
    move: Move;
}) {
    const sub = `${d.counts.assigned} assigned · ${d.counts.attended} with attendance confirmed · ${d.counts.not_attended} not marked as attended. Assignment alone does not establish attendance or training competence.`;
    if (!d.participants.length)
        return (
            <QuietLine
                title="Participants"
                sub={sub}
                text="No participants are recorded for this drill."
            />
        );
    return (
        <PeopleCards
            title="Participants"
            sub={sub}
            cols={2}
            items={d.participants.map((person) => ({
                name: person.name,
                sub: person.role
                    ? (PARTICIPANT_ROLE_OPTIONS.find(
                          (option) => option.value === person.role,
                      )?.label ?? titleCase(person.role))
                    : 'Role not recorded',
                presence: person.attended ? ('on' as const) : undefined,
                flag: (
                    <StatusBadge
                        variant={person.attended ? 'success' : 'neutral'}
                    >
                        {person.attended
                            ? 'Attendance confirmed'
                            : 'Not marked as attended'}
                    </StatusBadge>
                ),
                foot:
                    person.notes || person.accommodations || person.can.edit ? (
                        <span className="grid gap-1">
                            {person.notes ? (
                                <span className="whitespace-pre-wrap">
                                    <b>Notes</b> {person.notes}
                                </span>
                            ) : null}
                            {person.accommodations ? (
                                <span>
                                    <b>Support or accommodations</b>{' '}
                                    <span className="whitespace-pre-wrap">
                                        {person.accommodations}
                                    </span>
                                </span>
                            ) : null}
                            {person.can.edit ? (
                                <span>
                                    <Button
                                        variant="link"
                                        className="h-auto p-0"
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
                                </span>
                            ) : null}
                        </span>
                    ) : undefined,
            }))}
        />
    );
}

/* ------------------------------------------------------------------ */
/*  Findings                                                           */
/* ------------------------------------------------------------------ */

function FindingCard({
    finding,
    detail: d,
    selected,
    move,
}: {
    finding: DrillWorkspaceFinding;
    detail: DrillWorkspaceDetail;
    selected: boolean;
    move: Move;
}) {
    const status = FINDING_STATUS_META[finding.status] ?? {
        label: titleCase(finding.status),
        tone: 'neutral' as const,
    };
    const resolved = finding.status === 'resolved' || finding.status === 'closed';
    const record = finding.corrective_action_record;
    const effectiveness =
        record?.effectiveness_confirmed === true
            ? 'Confirmed'
            : record?.effectiveness_confirmed === false
              ? 'Not confirmed'
              : 'Not recorded';
    const lines: { tone: SgTone; text: ReactNode }[] = [
        {
            tone: 'none',
            text: (
                <>
                    Responsible person{' '}
                    <b>{finding.assignee_name ?? 'Unassigned'}</b>
                </>
            ),
        },
        {
            tone: finding.is_overdue ? 'critical' : 'none',
            text: `Due ${formatDateOnly(finding.due_date, 'Not recorded')}`,
        },
        ...(finding.corrective_action
            ? [
                  {
                      tone: 'none' as SgTone,
                      text: `Proposed corrective work: ${finding.corrective_action}`,
                  },
              ]
            : []),
        ...(record
            ? [
                  {
                      tone: 'info' as SgTone,
                      text: (
                          <>
                              Required remedy:{' '}
                              <Link
                                  href={record.href}
                                  className="underline underline-offset-4"
                              >
                                  {record.reference_number ??
                                      'Corrective action'}{' '}
                                  · {record.title}
                              </Link>
                              {' · '}
                              {record.display_state ??
                                  titleCase(record.status)}{' '}
                              · {record.assigned_to?.name ?? 'Unassigned'}
                          </>
                      ),
                  },
                  {
                      tone: (record.effectiveness_confirmed === true
                          ? 'success'
                          : 'warning') as SgTone,
                      text: `Effectiveness: ${effectiveness}`,
                  },
              ]
            : []),
        ...(finding.resolved_at || finding.resolution_notes
            ? [
                  {
                      tone: 'success' as SgTone,
                      text: `Resolution recorded on ${formatDateOnly(finding.resolved_at, 'Date not recorded')}`,
                  },
              ]
            : []),
    ];
    return (
        <li
            id={`finding-${finding.id}`}
            className={`sg-surface sg-place ${selected ? 'ring-2 ring-primary' : ''}`}
        >
            <div className="sg-ph">
                <div>
                    <b className="whitespace-pre-wrap">{finding.description}</b>
                    <small>
                        {FINDING_TYPE_LABEL[finding.finding_type] ??
                            titleCase(finding.finding_type)}
                        {finding.severity
                            ? ` · ${titleCase(finding.severity)} severity`
                            : ''}
                    </small>
                    <span className="sg-flag flex flex-wrap gap-1.5">
                        {finding.is_overdue && !resolved ? (
                            <StatusBadge variant="critical">Overdue</StatusBadge>
                        ) : null}
                        <StatusBadge variant={status.tone}>
                            {status.label}
                        </StatusBadge>
                        {finding.severity ? (
                            <StatusBadge
                                variant={
                                    SEVERITY_TONE[finding.severity] ?? 'neutral'
                                }
                            >
                                {titleCase(finding.severity)} severity
                            </StatusBadge>
                        ) : null}
                    </span>
                    {selected ? (
                        <small className="text-subtle">Selected finding</small>
                    ) : null}
                </div>
            </div>
            <div className="sg-facts2 sg-facts2--lines">
                {lines.map((line, i) => (
                    <span key={i}>
                        <Ring tone={line.tone} />
                        <span>{line.text}</span>
                    </span>
                ))}
            </div>
            {finding.resolution_notes ? (
                <p className="text-sm whitespace-pre-wrap">
                    <b>Resolution notes</b> {finding.resolution_notes}
                </p>
            ) : null}
            {finding.resolution_evidence_ids.length > 0 && (
                <div className="grid gap-1 text-sm">
                    <b>Resolution evidence</b>
                    {finding.resolution_evidence_ids.map((id) => {
                        const file = d.attachments.find((item) => item.id === id);
                        return file ? (
                            <a
                                key={id}
                                href={file.url}
                                className="break-all text-primary underline"
                            >
                                {file.original_name}
                            </a>
                        ) : null;
                    })}
                </div>
            )}
            {finding.resolve_blocked_reason && (
                <p className="text-subtle">{finding.resolve_blocked_reason}</p>
            )}
            {finding.can.edit ||
            finding.can.create_action ||
            finding.can.link_action ||
            finding.can.resolve ? (
                <div className="flex flex-wrap gap-2">
                    {finding.can.edit && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                move('findings', 'edit-finding', finding.id)
                            }
                        >
                            Update finding
                        </Button>
                    )}
                    {(finding.can.create_action || finding.can.link_action) && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                move('findings', 'corrective-action', finding.id)
                            }
                        >
                            Connect corrective action
                        </Button>
                    )}
                    {finding.can.resolve && (
                        <Button
                            size="sm"
                            onClick={() =>
                                move('findings', 'resolve-finding', finding.id)
                            }
                        >
                            Record resolution
                        </Button>
                    )}
                </div>
            ) : null}
        </li>
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
    const sub = `${d.counts.findings_open} open · ${d.counts.findings_overdue} overdue. Each finding has its own responsible person and resolution evidence.`;
    return (
        <>
            {selected &&
                !d.findings.some((finding) => finding.id === selected) && (
                    <p role="status" className="sg-quiet">
                        <Ring tone="none" />
                        <span>
                            The selected finding is not available in this drill.
                        </span>
                    </p>
                )}
            {d.findings.length ? (
                <section>
                    <div className="sg-section-head">
                        <h3>Findings</h3>
                        <p>{sub}</p>
                    </div>
                    <ul className="sg-cards" style={{ ['--cols' as string]: 3 }}>
                        {urgentFindings(d.findings).map((finding) => (
                            <FindingCard
                                key={finding.id}
                                finding={finding}
                                detail={d}
                                selected={selected === finding.id}
                                move={move}
                            />
                        ))}
                    </ul>
                </section>
            ) : (
                <QuietLine
                    title="Findings"
                    sub={sub}
                    text="No findings are recorded for this drill."
                />
            )}
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  History                                                            */
/* ------------------------------------------------------------------ */

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

function historicalRows(snapshot: Record<string, unknown>): [string, string][] {
    return Object.entries(snapshot)
        .filter(
            ([key, value]) =>
                HISTORY_LABELS[key] &&
                (value == null ||
                    ['string', 'number', 'boolean'].includes(typeof value)),
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
                else if (['scheduled_at', 'started_at', 'completed_at'].includes(key))
                    display = formatDateTimeLong(value, 'Not recorded');
                else if (
                    ['drill_type', 'outcome', 'finding_type', 'severity', 'status', 'role'].includes(key)
                )
                    display = titleCase(value);
            }
            return [HISTORY_LABELS[key], display] as [string, string];
        });
}

const HISTORY_TONES: [RegExp, SgTone][] = [
    [/resolved|completed|confirmed/i, 'success'],
    [/cancel|removed/i, 'warning'],
    [/finding added|finding recorded/i, 'warning'],
    [/scheduled|created/i, 'none'],
];

export function DrillHistory({ detail: d }: { detail: DrillWorkspaceDetail }) {
    const [open, setOpen] = useState<string | number | null>(null);
    if (!d.history.length)
        return (
            <QuietLine
                title="History"
                text="No history entries are available for this drill."
            />
        );
    const shown = d.history.find((item) => item.id === open);
    const items: LogItem[] = d.history.map((item) => {
        const names = Array.isArray(item.snapshot.names)
            ? (item.snapshot.names as unknown[]).filter(
                  (name): name is string => typeof name === 'string',
              )
            : [];
        return {
            key: item.id,
            time: item.occurred_at
                ? formatDateOnly(toDateInput(item.occurred_at))
                : 'Time not recorded',
            tone:
                HISTORY_TONES.find(([pattern]) => pattern.test(item.title))?.[1] ??
                'info',
            title: item.title,
            sub: (
                <span className="whitespace-normal">
                    {[
                        item.actor?.name ?? 'Actor not recorded',
                        item.occurred_at ? formatTime(item.occurred_at) : null,
                        item.note,
                        names.length ? names.join(', ') : null,
                        item.legacy
                            ? 'Legacy entry. Its original details may be incomplete.'
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </span>
            ),
            action: {
                label: open === item.id ? 'Hide details' : 'Details',
                onClick: () =>
                    setOpen((current) => (current === item.id ? null : item.id)),
            },
        };
    });
    const child = (key: string) => {
        const value = shown?.snapshot[key];
        return value && typeof value === 'object' && !Array.isArray(value)
            ? historicalRows(value as Record<string, unknown>)
            : [];
    };
    const rows = shown
        ? [
              ...historicalRows(shown.snapshot),
              ...child('finding'),
              ...child('participant'),
          ]
        : [];
    return (
        <>
            <Log
                title="History"
                sub="Open an entry to see its recorded details"
                items={items}
                limit={items.length}
            />
            {shown ? (
                rows.length ? (
                    <FactRows
                        aria-label="Recorded details"
                        title={`Recorded details · ${shown.title}`}
                        sub={formatDateTimeLong(shown.occurred_at, 'Time not recorded')}
                        rows={rows}
                    />
                ) : (
                    <QuietLine
                        title={`Recorded details · ${shown.title}`}
                        text="No further details were recorded for this entry."
                    />
                )
            ) : null}
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Connected work                                                     */
/* ------------------------------------------------------------------ */

function recordState(record: DrillLinkedRecord): string {
    return record.display_state ?? titleCase(record.status);
}

function drillLine(item: DrillSummary, label: string, state: JourneyStep['state']): JourneyStep {
    const outcome = outcomeMeta(item.outcome);
    return {
        label: `${item.reference} · ${label}`,
        meta: [
            formatDateTimeLong(item.scheduled_at, 'Schedule not recorded'),
            outcome?.label ?? titleCase(item.status),
        ].join(' · '),
        state,
    };
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
    const previous = d.connections.previous_drill;
    const nextDrills = d.connections.next_drills;
    const line: JourneyStep[] = [
        ...(previous ? [drillLine(previous, previous.title, 'done')] : []),
        {
            label: `${d.reference} · this drill`,
            meta: [
                formatDateTimeLong(d.scheduled_at, 'Schedule not recorded'),
                outcomeMeta(d.outcome)?.label ?? titleCase(d.status ?? ''),
            ]
                .filter(Boolean)
                .join(' · '),
            state: 'now',
        },
        ...nextDrills.map((item) => drillLine(item, item.title, 'todo')),
    ];
    const records = [
        ...d.connections.safety_reviews.map((r) => ({ kind: 'Safety review', r })),
        ...d.connections.corrective_actions.map((r) => ({
            kind: 'Corrective action',
            r: r as DrillLinkedRecord,
        })),
        ...d.connections.control_room.map((r) => ({ kind: 'Control Room', r })),
    ];
    return (
        <>
            <Surface
                title="Related drills"
                sub={
                    previous || nextDrills.length
                        ? `${d.type_label} at ${d.site?.name ?? 'this Site'}`
                        : undefined
                }
                action={
                    d.can.schedule_next
                        ? {
                              label: 'Schedule next drill',
                              onClick: () =>
                                  move('connections', 'schedule-next'),
                          }
                        : null
                }
            >
                <div className="sg-journey">
                    <JourneyLine steps={line} label="Related drills" />
                    {!previous && !nextDrills.length ? (
                        <div className="sg-jcap">
                            No previous or next drill is available here.
                        </div>
                    ) : null}
                </div>
                {previous || nextDrills.length ? (
                    <ul className="sg-list">
                        {[...(previous ? [previous] : []), ...nextDrills].map(
                            (item) => (
                                <li key={item.id}>
                                    <Link href={item.href} className="sg-li">
                                        <span className="sg-li-t">
                                            <b>
                                                {item.reference} · {item.title}
                                            </b>
                                            <small>
                                                {item === previous
                                                    ? 'Previous drill'
                                                    : 'Next drill'}{' '}
                                                · {titleCase(item.status)}
                                            </small>
                                        </span>
                                    </Link>
                                </li>
                            ),
                        )}
                    </ul>
                ) : null}
            </Surface>
            {records.length ? (
                <PlaceCards
                    title="After the drill"
                    sub="Open each record to see its owner and progress"
                    cols={3}
                    items={records.map(({ kind, r }) => ({
                        name: kind,
                        sub: [r.reference_number, r.title]
                            .filter(Boolean)
                            .join(' · '),
                        href: r.href,
                        facts: [{ tone: 'info' as SgTone, text: recordState(r) }],
                    }))}
                />
            ) : (
                <QuietLine
                    title="After the drill"
                    text="No linked records are available here."
                />
            )}
            {delivery && (
                <>
                    <FactRows
                        aria-label="Completion follow-up"
                        title="Completion follow-up"
                        rows={[
                            [
                                'Safety review',
                                delivery.event_status
                                    ? eventStatus[delivery.event_status]
                                    : 'Not recorded',
                            ],
                            [
                                'Response signal',
                                delivery.signal_status
                                    ? signalStatus[delivery.signal_status]
                                    : 'Not recorded',
                            ],
                            ...(delivery.next_attempt_at
                                ? ([
                                      [
                                          'Next scheduled retry',
                                          formatDateTimeLong(
                                              delivery.next_attempt_at,
                                          ),
                                      ],
                                  ] as [string, string][])
                                : []),
                        ]}
                    />
                    <Note icon={Info}>
                        These stages show recorded follow-up processing. Open
                        the connected record to see its actual owner, response
                        and progress.
                    </Note>
                </>
            )}
        </>
    );
}
