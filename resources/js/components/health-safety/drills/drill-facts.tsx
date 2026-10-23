/**
 * The drill record's plan, run and checks (pg-hsn-66, 66b): the plan and the
 * run read as stories with their facts as chips, and the roll-call checks are
 * a ring whose counts caveat stays word for word.
 */
import {
    Log,
    Note,
    RingCard,
    Story,
    type CheckItem,
    type LogItem,
    type SgTone,
    type StoryFact,
} from '@/components/signature/blocks';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import {
    fmtEvacTime,
    FINDING_TYPE_LABEL,
    outcomeMeta,
    titleCase,
} from '@/pages/health-safety/drills/shared';
import {
    CalendarClock,
    CloudSun,
    FileText,
    Flag,
    MapPin,
    Play,
    Siren,
    Square,
    Timer,
    TimerOff,
    User,
} from 'lucide-react';
import type { ReactNode } from 'react';
import type { DrillWorkspaceDetail, DrillWorkspaceFinding } from './types';

export function DrillFact({
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

/** Open findings first (overdue, then by due date), resolved ones last. */
export function urgentFindings(
    findings: DrillWorkspaceFinding[],
): DrillWorkspaceFinding[] {
    const open = (f: DrillWorkspaceFinding) =>
        f.status !== 'resolved' && f.status !== 'closed';
    return [...findings].sort(
        (a, b) =>
            Number(open(b)) - Number(open(a)) ||
            Number(b.is_overdue) - Number(a.is_overdue) ||
            (a.due_date ?? '9999').localeCompare(b.due_date ?? '9999'),
    );
}

function findingTone(f: DrillWorkspaceFinding): SgTone {
    if (f.status === 'resolved' || f.status === 'closed') return 'success';
    if (f.is_overdue) return 'critical';
    return 'warning';
}

export function DrillOverview({
    detail: d,
    onFindings,
}: {
    detail: DrillWorkspaceDetail;
    onFindings?: () => void;
}) {
    const facts: StoryFact[] = [
        ...(d.site ? [{ icon: MapPin, text: d.site.name }] : []),
        {
            icon: CalendarClock,
            text: formatDateTimeLong(d.scheduled_at, 'Schedule not recorded'),
        },
        {
            icon: Siren,
            text: `${d.type_label} · ${d.is_unannounced ? 'unannounced' : 'announced'}`,
        },
        ...(d.coordinator
            ? [{ icon: User, text: `Coordinator ${d.coordinator.name}` }]
            : []),
        ...(d.evacuation_scheme
            ? [{ icon: FileText, text: d.evacuation_scheme }]
            : []),
    ];
    const findings: LogItem[] = urgentFindings(d.findings).map((f) => {
        const resolved = f.status === 'resolved' || f.status === 'closed';
        return {
            key: f.id,
            time: resolved
                ? formatDateOnly(f.resolved_at, 'Resolved')
                : f.due_date
                  ? `Due ${formatDateOnly(f.due_date)}`
                  : 'No due date',
            tone: findingTone(f),
            title: f.description,
            sub: [
                FINDING_TYPE_LABEL[f.finding_type] ?? titleCase(f.finding_type),
                f.severity ? `${titleCase(f.severity)} severity` : null,
                f.assignee_name ?? 'Unassigned',
                f.corrective_action_record
                    ? `${f.corrective_action_record.reference_number ?? 'Corrective action'} ${(f.corrective_action_record.display_state ?? titleCase(f.corrective_action_record.status)).toLowerCase()}`
                    : resolved
                      ? 'Resolved'
                      : null,
            ]
                .filter(Boolean)
                .join(' · '),
        };
    });
    return (
        <>
            {d.status === 'cancelled' && (
                <Note tone="warning" icon={Flag}>
                    <p>
                        <b>Cancelled on</b>{' '}
                        {formatDateTimeLong(d.cancelled_at, 'Time not recorded')}
                    </p>
                    <p>
                        <b>Reason for cancellation</b>{' '}
                        {d.cancellation_reason || 'Not recorded'}
                    </p>
                </Note>
            )}
            <Story
                title="The plan"
                sub={
                    d.created_by_name
                        ? `Scheduled by ${d.created_by_name}${d.created_at ? ` · ${formatDateTimeLong(d.created_at)}` : ''}`
                        : undefined
                }
                facts={facts}
                paras={[
                    {
                        h: 'Scenario',
                        t: (
                            <span className="whitespace-pre-wrap">
                                {d.scenario_description || 'Not recorded'}
                            </span>
                        ),
                    },
                    {
                        h: 'Assembly point',
                        t: d.assembly_point || 'Not recorded',
                    },
                ]}
            />
            {findings.length ? (
                <Log
                    title="What it found"
                    sub="Most urgent first"
                    action={
                        onFindings
                            ? { label: 'All findings', onClick: onFindings }
                            : null
                    }
                    items={findings}
                    limit={findings.length}
                />
            ) : null}
            <Note icon={FileText}>
                Drill completion records what happened. Findings and any linked
                corrective actions keep their own resolution and verification
                checks.
            </Note>
        </>
    );
}

type RunFacts = Pick<
    DrillWorkspaceDetail,
    | 'status'
    | 'started_at'
    | 'completed_at'
    | 'duration_minutes'
    | 'evacuation_time_seconds'
    | 'outcome'
    | 'all_areas_checked'
    | 'assembly_point_reached'
    | 'roll_call_completed'
    | 'total_participants'
    | 'residents_evacuated'
    | 'weather_conditions'
    | 'improvements_identified'
    | 'observer_notes'
>;

/** How the drill ran: its times, duration, outcome and weather as chips, then the notes. */
export function DrillRunFacts({ detail: d }: { detail: RunFacts }) {
    const outcome = outcomeMeta(d.outcome);
    return (
        <Story
            title="How it ran"
            sub={
                d.status === 'completed'
                    ? undefined
                    : 'The run is recorded when the drill is completed'
            }
            facts={[
                {
                    icon: Play,
                    text: `Started ${formatDateTimeLong(d.started_at, 'Not recorded')}`,
                },
                {
                    icon: Square,
                    text: `Completion recorded ${formatDateTimeLong(d.completed_at, 'Not recorded')}`,
                },
                {
                    icon: Timer,
                    text:
                        d.duration_minutes === null
                            ? 'Duration Not recorded'
                            : `Duration ${d.duration_minutes} min`,
                },
                {
                    icon: d.evacuation_time_seconds === null ? TimerOff : Timer,
                    text:
                        d.evacuation_time_seconds === null
                            ? 'Evacuation time Not recorded'
                            : `Evacuation time ${fmtEvacTime(d.evacuation_time_seconds)}`,
                },
                {
                    icon: Flag,
                    text: outcome?.label ?? d.outcome ?? 'Outcome Not recorded',
                },
                {
                    icon: CloudSun,
                    text: d.weather_conditions || 'Weather Not recorded',
                },
            ]}
            paras={[
                {
                    h: 'Improvements identified',
                    t: (
                        <span className="whitespace-pre-wrap">
                            {d.improvements_identified || 'Not recorded'}
                        </span>
                    ),
                },
                {
                    h: 'Observer notes',
                    t: (
                        <span className="whitespace-pre-wrap">
                            {d.observer_notes || 'Not recorded'}
                        </span>
                    ),
                },
            ]}
        />
    );
}

/**
 * The recorded roll-call as a ring: the three checks and the two counts, with
 * the counts caveat word for word. Before completion nothing is confirmed.
 */
export function DrillChecks({ detail: d }: { detail: RunFacts }) {
    const completed = d.status === 'completed';
    const checks: [string, boolean][] = [
        ['Assembly point reached', d.assembly_point_reached],
        ['Roll-call completed', d.roll_call_completed],
        ['All areas checked', d.all_areas_checked],
    ];
    const confirmed = completed ? checks.filter(([, v]) => v).length : 0;
    const items: CheckItem[] = checks.map(([label, value]) => ({
        label: `${label} · ${completed ? (value ? 'Confirmed' : 'Not confirmed') : 'Not recorded'}`,
        state: completed ? (value ? 'success' : 'critical') : 'none',
    }));
    return (
        <RingCard
            value={confirmed}
            of={3}
            label={
                completed
                    ? `Drill checks — ${confirmed} of 3 confirmed`
                    : 'Drill checks — not recorded yet'
            }
            sub={
                <>
                    Total participants recorded:{' '}
                    {d.total_participants ?? 'Not recorded'} · Residents
                    evacuated — recorded count:{' '}
                    {d.residents_evacuated ?? 'Not recorded'} · Participant
                    totals and the recorded evacuated count are separate. These
                    counts alone do not establish that every resident was
                    safely evacuated.
                </>
            }
            checks={items}
        />
    );
}
