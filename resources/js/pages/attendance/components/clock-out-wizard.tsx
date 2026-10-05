/* Clock-out wizard — 3 steps on the shared wizard shell: time & breaks
 * (tracked break events pre-filled), a light handover (files the same record
 * as the Shift Handover wizard via the clock-out payload), review & clock out.
 * Posts to the existing POST /attendance/clock-out endpoint; end-of-shift
 * blockers come back as errors.clock_out + flash.clock_out_blockers. */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    ChipMulti,
    Field,
    InfoCard,
    Segmented,
    StepHead,
    SubHead,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { formatDateTimeInZone, WORKER_TIMEZONE } from '@/lib/datetime';
import { usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeftRight,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock,
    Coffee,
    Link2,
    Loader2,
    LogOut,
    Smile,
    Timer,
} from 'lucide-react';
import { useState } from 'react';

import {
    AttendanceCommandFeedback,
    useAttendanceClose,
    useAttendanceValidationFocus,
} from './attendance-command-ui';
import { AttendanceEndFields } from './attendance-end-fields';
import {
    attendanceDurationError,
    attendanceEnd,
    attendanceSeed,
    clockOutBreaks,
    wholeAttendanceMinutes,
} from './attendance-time';
import type { OpenSession } from './shared';
import {
    attendanceTimesheetMessage,
    useAttendanceCommand,
    type AttendanceResult,
} from './use-attendance-command';

const STEPS: readonly WizardStep[] = [
    {
        key: 'time',
        label: 'Time & breaks',
        blurb: 'Confirm what you worked',
        icon: Clock,
    },
    {
        key: 'handover',
        label: 'Handover',
        blurb: 'Brief the next shift',
        icon: ArrowLeftRight,
    },
    {
        key: 'review',
        label: 'Review & clock out',
        blurb: 'Confirm and close',
        icon: CheckCircle2,
    },
] as const;

const HANDOVER_TASKS = [
    'Evening meds due',
    'Dinner prep',
    'Laundry cycle running',
    'GP callback expected',
    'Transport booked',
    'Incident report to finish',
];

type Mood = 'settled' | 'mixed' | 'unsettled';

const MOOD_LABEL: Record<Mood, string> = {
    settled: 'Settled',
    mixed: 'Up and down',
    unsettled: 'Unsettled',
};

/** Wizard mood → the clock-out payload's shift_rating enum. */
const MOOD_RATING: Record<Mood, string> = {
    settled: 'calm',
    mixed: 'mixed',
    unsettled: 'challenging',
};

type ClockOutBlocker = {
    key: string;
    label: string;
    detail: string;
    count: number;
};

type ClockOutProps = {
    open: boolean;
    onClose: () => void;
    session: OpenSession | null;
    timezone?: string;
};

export function ClockOutWizard(props: ClockOutProps) {
    return props.open ? <ClockOutForm {...props} /> : null;
}

function ClockOutForm({
    onClose,
    session: currentSession,
    timezone = WORKER_TIMEZONE,
}: ClockOutProps) {
    const page = usePage().props as {
        flash?: { clock_out_blockers?: ClockOutBlocker[] | null };
    };
    // Keep this command's original session when refreshed page props remove the now-closed session.
    const [session] = useState(currentSession);
    const [seed] = useState(() =>
        attendanceSeed(
            new Date(Math.floor(Date.now() / 60000) * 60000).toISOString(),
            timezone,
        ),
    );
    const [stepIndex, setStepIndex] = useState(0);
    const [outDate, setOutDate] = useState(seed.date);
    const [outTime, setOutTime] = useState(seed.time);
    const [occurrence, setOccurrence] = useState('');
    const [extraBreak, setExtraBreak] = useState('0');
    const [narrative, setNarrative] = useState('');
    const [mood, setMood] = useState<Mood>('settled');
    const [medsCompleted, setMedsCompleted] = useState(true);
    const [tasks, setTasks] = useState<string[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    useAttendanceValidationFocus(errors, stepIndex, 'clock-out-wizard-fields');
    const command = useAttendanceCommand(
        'clock_out:' + (currentSession?.id ?? session?.id),
    );
    const processing = command.pending;
    const done = command.receipt;
    const contextChanged =
        !!currentSession && currentSession.id !== session?.id;
    const blocked = command.blocked || contextChanged;
    const close = useAttendanceClose({
        onClose,
        pending: processing,
        busy: command.busy,
        outcome: command.outcome,
        dirty:
            outDate !== seed.date ||
            outTime !== seed.time ||
            occurrence !== '' ||
            extraBreak !== '0' ||
            narrative !== '' ||
            mood !== 'settled' ||
            !medsCompleted ||
            tasks.length > 0,
    });
    const display = (value: string | Date | null) =>
        formatDateTimeInZone(value, timezone);
    if (!session) return null;

    const blockers =
        command.outcome?.status === 'rejected'
            ? (page.flash?.clock_out_blockers ?? [])
            : [];
    const hasShift = session.shift_id != null;
    const end = attendanceEnd(
        outDate + 'T' + outTime,
        timezone,
        seed.original,
        occurrence,
    );
    const outAt = end.instant;
    const breaks = clockOutBreaks(session, outAt, extraBreak);
    const trackedBreakM = breaks.tracked;
    const totalBreakM = breaks.total;
    const workedH =
        outAt && !breaks.error && !end.error
            ? (
                  Math.max(
                      0,
                      wholeAttendanceMinutes(session.clock_in_at, outAt) -
                          totalBreakM,
                  ) / 60
              ).toFixed(2)
            : '—';
    const handoverDone = !hasShift || narrative.trim().length >= 10;
    const pct = Math.round(((1 + (handoverDone ? 2 : 0)) / 3) * 100);
    const validate = (key: string): Record<string, string> => {
        const next: Record<string, string> = {};
        if (key === 'time') {
            const timeError =
                end.error ||
                attendanceDurationError(session.clock_in_at, outAt);
            if (timeError) next.outTime = timeError;
            if (!next.outTime && breaks.error) next.extraBreak = breaks.error;
        }
        if (key === 'handover' && hasShift && narrative.trim().length < 10)
            next.narrative =
                'Add a short handover narrative (at least 10 characters).';
        return next;
    };
    const next = () => {
        if (command.busy.current || processing || contextChanged) return;
        if (command.outcome?.status === 'unknown') {
            setStepIndex((index) => Math.min(index + 1, STEPS.length - 1));
            return;
        }
        if (command.blocked) return;
        const reviewing = STEPS[stepIndex].key === 'review';
        const nextErrors = reviewing
            ? { ...validate('time'), ...validate('handover') }
            : validate(STEPS[stepIndex].key);
        setErrors(nextErrors);
        if (Object.keys(nextErrors).length) {
            setStepIndex(nextErrors.outTime || nextErrors.extraBreak ? 0 : 1);
            return;
        }
        if (!reviewing) {
            setStepIndex((index) => Math.min(index + 1, STEPS.length - 1));
            return;
        }
        if (!outAt) return;
        command.submit(
            '/attendance/clock-out',
            {
                session_id: session.id,
                clock_out_at: outAt,
                ...(breaks.payload === undefined
                    ? {}
                    : { break_minutes: breaks.payload }),
                ...(hasShift
                    ? {
                          handover: {
                              meds_completed: medsCompleted,
                              shift_rating: MOOD_RATING[mood],
                              handover_notes: narrative.trim(),
                              follow_up_needed: false,
                              tasks_pending: tasks,
                          },
                      }
                    : {}),
            },
            { action: 'clock_out', sessionId: session.id, end: outAt },
        );
    };

    const stepKey = STEPS[stepIndex].key;

    return (
        <>
            <WizardShell
                open
                onClose={close.requestClose}
                title="Clock out"
                description="A guided flow to close your attendance session."
                railIcon={LogOut}
                railTitle="Clock out"
                railSub={`Session since ${display(session.clock_in_at)}`}
                steps={STEPS.map((step) => ({ ...step, disabled: blocked }))}
                stepIndex={stepIndex}
                onStepClick={(index) => {
                    if (!command.busy.current && !blocked) setStepIndex(index);
                }}
                pct={pct}
                pctLabel="Hand-back quality"
                footerStart={
                    stepIndex > 0 ? (
                        <Button
                            variant="ghost"
                            disabled={processing || contextChanged}
                            onClick={() =>
                                setStepIndex((i) => Math.max(0, i - 1))
                            }
                        >
                            <ChevronLeft className="h-4 w-4" /> Back
                        </Button>
                    ) : null
                }
                footerEnd={
                    <>
                        <Button
                            variant="outline"
                            onClick={close.requestClose}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        {stepKey === 'review' ? (
                            <Button
                                onClick={next}
                                disabled={blocked}
                                data-test="attendance-confirm-clock-out"
                            >
                                {processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <LogOut className="h-4 w-4" />
                                )}
                                Clock out
                            </Button>
                        ) : (
                            <Button
                                onClick={next}
                                disabled={processing || contextChanged}
                            >
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </>
                }
                success={
                    done ? (
                        <ClockOutSuccess
                            done={done}
                            onClose={onClose}
                            timezone={timezone}
                        />
                    ) : null
                }
            >
                <AttendanceCommandFeedback outcome={command.outcome} />
                {contextChanged && (
                    <InfoCard icon={AlertTriangle} tone="warn">
                        The open session changed. Close this form and check the
                        current attendance record.
                    </InfoCard>
                )}
                {blockers.length > 0 && (
                    <InfoCard icon={AlertTriangle} tone="warn">
                        <ul className="list-disc pl-4">
                            {blockers.map((blocker) => (
                                <li key={blocker.key}>
                                    <strong>{blocker.label}</strong> —{' '}
                                    {blocker.detail}
                                </li>
                            ))}
                        </ul>
                    </InfoCard>
                )}
                <fieldset
                    disabled={blocked}
                    className="min-w-0"
                    id="clock-out-wizard-fields"
                >
                    {stepKey === 'time' ? (
                        <WizardStepPane key="time">
                            <StepHead
                                icon={Clock}
                                title="Confirm your time"
                                blurb="Breaks tracked during the shift are already counted."
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <AttendanceEndFields
                                    id="attendance-clock-out"
                                    date={outDate}
                                    time={outTime}
                                    zone={timezone}
                                    choice={occurrence}
                                    onDate={setOutDate}
                                    onTime={setOutTime}
                                    onChoice={setOccurrence}
                                    error={errors.outTime}
                                />
                                <Field
                                    label="Extra break minutes"
                                    hint="on top of tracked breaks"
                                    error={errors.extraBreak}
                                >
                                    <Input
                                        type="number"
                                        min={0}
                                        max={240}
                                        value={extraBreak}
                                        aria-invalid={!!errors.extraBreak}
                                        onChange={(e) =>
                                            setExtraBreak(e.target.value)
                                        }
                                    />
                                </Field>
                                <div className="sm:col-span-2">
                                    <SubHead icon={Coffee}>
                                        Breaks tracked this session
                                    </SubHead>
                                    {session.breaks.length === 0 ? (
                                        <p className="mt-2 text-[13px] text-muted-foreground">
                                            No breaks tracked — use “Start
                                            break” on the attendance page next
                                            time and this fills itself in.
                                        </p>
                                    ) : (
                                        <ul className="mt-2 space-y-1.5">
                                            {session.breaks.map((b, i) => (
                                                <li
                                                    key={b.id ?? i}
                                                    className="flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 text-[13px]"
                                                >
                                                    <Coffee className="h-3.5 w-3.5 text-muted-foreground" />
                                                    <span className="font-medium">
                                                        {display(b.started_at)}{' '}
                                                        –{' '}
                                                        {b.ended_at
                                                            ? display(
                                                                  b.ended_at,
                                                              )
                                                            : 'entered clock-out'}
                                                    </span>
                                                    <span className="ml-auto text-muted-foreground tabular-nums">
                                                        {b.ended_at
                                                            ? (b.minutes ?? 0)
                                                            : b.started_at
                                                              ? wholeAttendanceMinutes(
                                                                    b.started_at,
                                                                    outAt ??
                                                                        b.started_at,
                                                                )
                                                              : 0}
                                                        m
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                                <div className="flex items-center gap-4 rounded-xl border border-primary/30 bg-primary/10 p-4 sm:col-span-2">
                                    <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary-fill text-primary-fill-foreground">
                                        <Timer className="h-5 w-5" />
                                    </span>
                                    <div>
                                        <div className="text-[15px] font-bold">
                                            {workedH}h attendance estimate
                                        </div>
                                        <div className="text-[13px] text-muted-foreground">
                                            {display(session.clock_in_at)} →{' '}
                                            {display(outAt)} minus {totalBreakM}
                                            m breaks
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </WizardStepPane>
                    ) : null}

                    {stepKey === 'handover' ? (
                        <WizardStepPane key="handover">
                            <StepHead
                                icon={ArrowLeftRight}
                                title="Brief the next shift"
                                blurb="Save the essentials as a draft, then assign and submit it from Shift Handovers."
                            />
                            <div className="grid gap-4">
                                {!hasShift ? (
                                    <InfoCard icon={ArrowLeftRight}>
                                        This session has no linked shift, so
                                        there is no shift record to hand over.
                                        File one from{' '}
                                        <strong>
                                            Operations → Shift Handovers
                                        </strong>{' '}
                                        if the next worker needs a brief.
                                    </InfoCard>
                                ) : (
                                    <>
                                        <Field
                                            label="How the shift went"
                                            required
                                            error={errors.narrative}
                                        >
                                            <Textarea
                                                rows={4}
                                                value={narrative}
                                                aria-invalid={
                                                    !!errors.narrative
                                                }
                                                onChange={(e) =>
                                                    setNarrative(e.target.value)
                                                }
                                                placeholder="e.g. Settled after lunch; physio exercises done; fluids slightly under target — encourage water this evening."
                                            />
                                        </Field>
                                        <Field label="Client mood">
                                            <Segmented<Mood>
                                                value={mood}
                                                onChange={setMood}
                                                options={[
                                                    {
                                                        value: 'settled',
                                                        label: 'Settled',
                                                        icon: Smile,
                                                    },
                                                    {
                                                        value: 'mixed',
                                                        label: 'Up and down',
                                                    },
                                                    {
                                                        value: 'unsettled',
                                                        label: 'Unsettled',
                                                        icon: AlertTriangle,
                                                    },
                                                ]}
                                            />
                                        </Field>
                                        {/* eslint-disable-next-line no-restricted-syntax -- compact switch row, not a Card surface */}
                                        <div className="flex items-center justify-between rounded-lg border border-border bg-card px-4 py-3">
                                            <div>
                                                <div className="text-sm font-semibold">
                                                    All medications given and
                                                    signed
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    turn off to flag a meds
                                                    review for the next shift
                                                </div>
                                            </div>
                                            <Switch
                                                checked={medsCompleted}
                                                onCheckedChange={
                                                    setMedsCompleted
                                                }
                                                aria-label="All medications given and signed"
                                            />
                                        </div>
                                        <Field label="Tasks for the incoming shift">
                                            <ChipMulti
                                                values={tasks}
                                                onChange={setTasks}
                                                options={HANDOVER_TASKS}
                                            />
                                        </Field>
                                        <InfoCard icon={ArrowLeftRight}>
                                            Clock-out saves this as a draft. If
                                            a submitted or acknowledged handover
                                            already exists, that record is kept
                                            and these notes are not added to it.
                                            Open{' '}
                                            <strong>Shift Handovers</strong> to
                                            assign the exact incoming shift,
                                            review it, and submit it. If an
                                            incoming Shift is due, submission
                                            unblocks completion but does not
                                            complete the Shift automatically.
                                            Clock-out still checks required care
                                            tasks. After saving, check the Shift
                                            status and complete it separately if
                                            still required.
                                        </InfoCard>
                                    </>
                                )}
                            </div>
                        </WizardStepPane>
                    ) : null}

                    {stepKey === 'review' ? (
                        <WizardStepPane key="review-step">
                            <StepHead
                                icon={CheckCircle2}
                                title="Review & clock out"
                                blurb="Check the full date, time and break total. The saved result will show any timesheet follow-up."
                            />
                            <div className="grid gap-3.5 sm:grid-cols-2">
                                <ReviewCard
                                    icon={Clock}
                                    title="Session"
                                    onEdit={() => setStepIndex(0)}
                                >
                                    <ReviewRow
                                        label="Clock in"
                                        value={display(session.clock_in_at)}
                                    />
                                    <ReviewRow
                                        label="Clock out"
                                        value={display(outAt)}
                                    />
                                    <ReviewRow
                                        label="Breaks"
                                        value={`${totalBreakM}m (${trackedBreakM}m tracked)`}
                                    />
                                    <ReviewRow
                                        label="Worked"
                                        value={<strong>{workedH}h</strong>}
                                    />
                                    <ReviewRow
                                        label="Timesheet"
                                        value={
                                            session.timesheet_id
                                                ? `#${session.timesheet_id} · checked when saving`
                                                : 'Outcome confirmed after clock-out'
                                        }
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={ArrowLeftRight}
                                    title="Handover"
                                    onEdit={() => setStepIndex(1)}
                                >
                                    {!hasShift ? (
                                        <p className="py-1.5 text-[13px] text-muted-foreground">
                                            No shift is linked to this session.
                                            Use Shift Handovers if the next
                                            worker needs a brief.
                                        </p>
                                    ) : (
                                        <>
                                            <ReviewRow
                                                label="Mood"
                                                value={MOOD_LABEL[mood]}
                                            />
                                            <ReviewRow
                                                label="Meds"
                                                value={
                                                    medsCompleted
                                                        ? 'All given and signed'
                                                        : 'Review flagged'
                                                }
                                            />
                                            <ReviewRow
                                                label="Tasks"
                                                value={
                                                    tasks.length
                                                        ? `${tasks.length} flagged`
                                                        : 'None'
                                                }
                                            />
                                            <ReviewRow
                                                label="Narrative"
                                                value={
                                                    narrative
                                                        ? `${narrative.slice(0, 60)}${narrative.length > 60 ? '…' : ''}`
                                                        : undefined
                                                }
                                            />
                                        </>
                                    )}
                                </ReviewCard>
                                <InfoCard icon={Link2}>
                                    Attendance hours are separate from timesheet
                                    approval and pay. Saving will confirm
                                    whether the timesheet was updated or needs
                                    follow-up.
                                </InfoCard>
                            </div>
                        </WizardStepPane>
                    ) : null}
                </fieldset>
            </WizardShell>
            {close.dialog}
        </>
    );
}

function ClockOutSuccess({
    done,
    onClose,
    timezone,
}: {
    done: AttendanceResult;
    onClose: () => void;
    timezone: string;
}) {
    return (
        <WizardSuccessPane
            title={
                done.worked_hours?.toFixed(2) + 'h recorded — session closed'
            }
            blurb={
                <>
                    Clocked out at{' '}
                    {formatDateTimeInZone(done.clock_out_at, timezone)} (
                    {timezone}) with {done.break_minutes}m of breaks.{' '}
                    {attendanceTimesheetMessage(done)}{' '}
                    {done.handover_outcome === 'draft_saved'
                        ? 'Your handover was saved as a draft. If an incoming Shift is due, assign and submit it from Shift Handovers, then check its status and complete the Shift separately if still required. Attendance is already clocked out.'
                        : done.handover_outcome ===
                            'existing_submitted_or_acknowledged'
                          ? 'An existing submitted or acknowledged handover was kept. The notes entered here were not added to it. Check Shift Handovers and the Shift status; complete the Shift separately if still required.'
                          : done.handover_outcome === 'no_shift'
                            ? 'No shift was linked, so no handover draft was created.'
                            : 'No handover draft was saved by this action. Check the Shift record for any remaining care and completion tasks.'}
                </>
            }
            actions={
                <Button onClick={onClose}>
                    <Timer className="h-4 w-4" /> Back to attendance
                </Button>
            }
        />
    );
}
