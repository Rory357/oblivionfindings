/* Fix-a-missed-clock-out wizard — 3 steps on the shared wizard shell: pick the
 * session (stale 16h+ ones flagged), set the real clock-out + breaks, give the
 * audit-log reason. Posts to POST /attendance/sessions/{id}/correct; the
 * linked timesheet is recalculated (submitted ones return to draft). */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    StepHead,
    TilePicker,
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
import {
    AlertTriangle,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock,
    Loader2,
    Timer,
    Wrench,
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
    correctionBreaks,
    wholeAttendanceMinutes,
} from './attendance-time';
import type { FixCandidate } from './shared';
import {
    attendanceTimesheetMessage,
    useAttendanceCommand,
} from './use-attendance-command';

const STEPS: readonly WizardStep[] = [
    {
        key: 'session',
        label: 'Session',
        blurb: 'Which one needs fixing',
        icon: AlertTriangle,
    },
    {
        key: 'times',
        label: 'Correct times',
        blurb: 'What actually happened',
        icon: Clock,
    },
    {
        key: 'reason',
        label: 'Reason & review',
        blurb: 'For the audit log',
        icon: CheckCircle2,
    },
] as const;

type FixClockOutProps = {
    open: boolean;
    onClose: () => void;
    sessions: FixCandidate[];
    timezone?: string;
};

function seedCorrection(candidate: FixCandidate | null, timezone: string) {
    if (candidate?.clock_out_at)
        return attendanceSeed(candidate.clock_out_at, timezone);
    if (!candidate) return { date: '', time: '', original: '' };
    const suggested = Math.min(
        Date.parse(candidate.clock_in_at) + 8 * 3600000,
        Date.now(),
    );
    return attendanceSeed(
        new Date(Math.floor(suggested / 60000) * 60000).toISOString(),
        timezone,
    );
}

export function FixClockOutWizard(props: FixClockOutProps) {
    return props.open ? <FixClockOutForm {...props} /> : null;
}

function FixClockOutForm({
    onClose,
    sessions,
    timezone = WORKER_TIMEZONE,
}: FixClockOutProps) {
    const [session, setSession] = useState<FixCandidate | null>(
        sessions[0] ?? null,
    );
    const [seed, setSeed] = useState(() =>
        seedCorrection(sessions[0] ?? null, timezone),
    );
    const [stepIndex, setStepIndex] = useState(0);
    const [outDate, setOutDate] = useState(seed.date);
    const [outTime, setOutTime] = useState(seed.time);
    const [occurrence, setOccurrence] = useState('');
    const [breakMin, setBreakMin] = useState(
        String(session?.break_minutes ?? 0),
    );
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    useAttendanceValidationFocus(
        errors,
        stepIndex,
        'fix-clock-out-wizard-fields',
    );
    const [switchTo, setSwitchTo] = useState<FixCandidate | null>(null);
    const command = useAttendanceCommand('correct:' + session?.id);
    const processing = command.pending;
    const done = command.receipt;
    const dirty =
        outDate !== seed.date ||
        outTime !== seed.time ||
        occurrence !== '' ||
        breakMin !== String(session?.break_minutes ?? 0) ||
        reason !== '';
    const close = useAttendanceClose({
        onClose,
        dirty,
        pending: processing,
        busy: command.busy,
        outcome: command.outcome,
    });
    const display = (value: string | Date | null) =>
        formatDateTimeInZone(value, timezone);
    const sessionId = session ? String(session.id) : '';
    const end = attendanceEnd(
        outDate + 'T' + outTime,
        timezone,
        seed.original,
        occurrence,
    );
    const outAt = end.instant;
    const breaks = correctionBreaks(
        session?.clock_in_at ?? '',
        outAt,
        breakMin,
    );
    const workedH =
        session && outAt && !breaks.error && !end.error
            ? (
                  Math.max(
                      0,
                      wholeAttendanceMinutes(session.clock_in_at, outAt) -
                          breaks.total,
                  ) / 60
              ).toFixed(2)
            : '—';
    const pct = Math.round(
        (((session ? 1 : 0) + (outAt ? 1 : 0) + (reason.trim() ? 1 : 0)) / 3) *
            100,
    );
    const selectSession = (candidate: FixCandidate) => {
        command.clearRejected();
        const nextSeed = seedCorrection(candidate, timezone);
        setSession(candidate);
        setSeed(nextSeed);
        setOutDate(nextSeed.date);
        setOutTime(nextSeed.time);
        setOccurrence('');
        setBreakMin(String(candidate.break_minutes));
        setReason('');
        setErrors({});
        setSwitchTo(null);
    };
    const pickSession = (key: string) => {
        if (command.busy.current || command.blocked || key === sessionId)
            return;
        const candidate = sessions.find((row) => String(row.id) === key);
        if (!candidate) return;
        if (dirty) setSwitchTo(candidate);
        else selectSession(candidate);
    };
    const validate = (key: string): Record<string, string> => {
        const next: Record<string, string> = {};
        if (!session || !sessions.some((row) => row.id === session.id))
            next.session = 'Choose a current session to correct.';
        if (key === 'times' && session) {
            const timeError =
                end.error ||
                attendanceDurationError(session.clock_in_at, outAt);
            if (timeError) next.outTime = timeError;
            if (!next.outTime && breaks.error) next.breakMin = breaks.error;
        }
        if (key === 'reason' && !reason.trim())
            next.reason =
                'A reason is required — it is recorded in the audit log.';
        return next;
    };
    const next = () => {
        if (command.busy.current || processing) return;
        if (command.outcome?.status === 'unknown') {
            setStepIndex((index) => Math.min(index + 1, STEPS.length - 1));
            return;
        }
        if (command.blocked) return;
        const reviewing = STEPS[stepIndex].key === 'reason';
        const nextErrors = reviewing
            ? {
                  ...validate('session'),
                  ...validate('times'),
                  ...validate('reason'),
              }
            : validate(STEPS[stepIndex].key);
        setErrors(nextErrors);
        if (Object.keys(nextErrors).length) {
            setStepIndex(
                nextErrors.session
                    ? 0
                    : nextErrors.outTime || nextErrors.breakMin
                      ? 1
                      : 2,
            );
            return;
        }
        if (!reviewing) {
            setStepIndex((index) => Math.min(index + 1, STEPS.length - 1));
            return;
        }
        if (!session || !outAt) return;
        command.submit(
            '/attendance/sessions/' + session.id + '/correct',
            {
                clock_out_at: outAt,
                break_minutes: breaks.total,
                reason: reason.trim(),
            },
            { action: 'correct', sessionId: session.id, end: outAt },
        );
    };

    const stepKey = STEPS[stepIndex].key;

    return (
        <>
            <WizardShell
                open
                onClose={close.requestClose}
                title="Fix a clock-out"
                description="A guided flow to correct a missed or wrong clock-out time."
                railIcon={Wrench}
                railTitle="Fix a clock-out"
                railSub="Correct a missed or wrong time"
                steps={STEPS.map((step) => ({
                    ...step,
                    disabled: processing,
                }))}
                stepIndex={stepIndex}
                onStepClick={(index) => {
                    if (!command.busy.current && !processing)
                        setStepIndex(index);
                }}
                pct={pct}
                pctLabel="Correction detail"
                footerStart={
                    stepIndex > 0 ? (
                        <Button
                            variant="ghost"
                            disabled={processing}
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
                        {stepKey === 'reason' ? (
                            <Button
                                onClick={next}
                                disabled={command.blocked}
                                data-test="attendance-save-correction"
                            >
                                {processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Wrench className="h-4 w-4" />
                                )}
                                Save correction
                            </Button>
                        ) : (
                            <Button onClick={next} disabled={processing}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </>
                }
                success={
                    done ? (
                        <WizardSuccessPane
                            title="Session corrected"
                            blurb={
                                <>
                                    Session #{done.session_id}:{' '}
                                    {display(done.clock_in_at)} →{' '}
                                    {display(done.clock_out_at)} ({timezone}).{' '}
                                    {done.worked_hours?.toFixed(2)}h attendance
                                    recorded with {done.break_minutes}m of
                                    breaks. {attendanceTimesheetMessage(done)}{' '}
                                    Your correction reason was recorded.
                                </>
                            }
                            actions={
                                <Button onClick={onClose}>
                                    <Timer className="h-4 w-4" /> Back to
                                    attendance
                                </Button>
                            }
                        />
                    ) : null
                }
            >
                <AttendanceCommandFeedback outcome={command.outcome} />
                <fieldset
                    disabled={command.blocked}
                    className="min-w-0"
                    id="fix-clock-out-wizard-fields"
                >
                    {stepKey === 'session' ? (
                        <WizardStepPane key="session">
                            <StepHead
                                icon={AlertTriangle}
                                title="Which session needs fixing?"
                                blurb="Open sessions over 16 hours are flagged as likely missed clock-outs."
                            />
                            <div className="grid gap-4">
                                <Field
                                    label="Sessions"
                                    required
                                    error={errors.session}
                                >
                                    <TilePicker
                                        value={sessionId}
                                        onChange={pickSession}
                                        cols={2}
                                        options={sessions.map((s) => ({
                                            key: String(s.id),
                                            label: `${s.user_name} · In ${display(s.clock_in_at)}`,
                                            description: `${
                                                s.shift_id
                                                    ? `Shift #${s.shift_id}${s.location ? ` · ${s.location}` : ''}`
                                                    : 'No shift linked'
                                            } · ${s.clock_out_at ? `out ${display(s.clock_out_at)}` : 'still open'}`,
                                            icon: s.is_stale
                                                ? AlertTriangle
                                                : Clock,
                                            meta: s.is_stale
                                                ? 'Open 16h+ — likely missed clock-out'
                                                : undefined,
                                        }))}
                                    />
                                </Field>
                            </div>
                        </WizardStepPane>
                    ) : null}

                    {stepKey === 'times' ? (
                        <WizardStepPane key="times">
                            <StepHead
                                icon={Clock}
                                title="What actually happened?"
                                blurb="Set the real clock-out time and any breaks taken."
                            />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Clock-in" hint="locked">
                                    <Input
                                        value={
                                            session
                                                ? display(session.clock_in_at)
                                                : ''
                                        }
                                        disabled
                                    />
                                </Field>
                                <AttendanceEndFields
                                    id="attendance-correction"
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
                                    label="Break minutes"
                                    error={errors.breakMin}
                                >
                                    <Input
                                        type="number"
                                        min={0}
                                        max={240}
                                        value={breakMin}
                                        aria-invalid={!!errors.breakMin}
                                        onChange={(e) =>
                                            setBreakMin(e.target.value)
                                        }
                                    />
                                </Field>
                                <div className="flex items-end pb-1">
                                    <Badge
                                        variant="outline"
                                        className="gap-1 text-primary"
                                    >
                                        <Timer className="h-3 w-3" /> {workedH}h
                                        attendance estimate
                                    </Badge>
                                </div>
                                <InfoCard icon={AlertTriangle} tone="warn">
                                    An eligible linked timesheet is recalculated
                                    and can return to draft for review.
                                    Protected payroll records cannot be changed
                                    here. If the original shift was reassigned
                                    or cancelled, attendance may save with a
                                    separate payroll follow-up.
                                </InfoCard>
                            </div>
                        </WizardStepPane>
                    ) : null}

                    {stepKey === 'reason' ? (
                        <WizardStepPane key="reason">
                            <StepHead
                                icon={CheckCircle2}
                                title="Reason & review"
                                blurb="Corrections always carry a reason — it lands in the audit log."
                            />
                            <div className="grid gap-4">
                                <Field
                                    label="Reason for correction"
                                    required
                                    error={errors.reason}
                                >
                                    <Textarea
                                        rows={3}
                                        value={reason}
                                        aria-invalid={!!errors.reason}
                                        onChange={(e) =>
                                            setReason(e.target.value)
                                        }
                                        placeholder="e.g. Missed clock-out on Monday — left at 5pm after sleepover shift"
                                    />
                                </Field>
                                <ReviewCard
                                    icon={Clock}
                                    title="Corrected session"
                                    onEdit={() => setStepIndex(1)}
                                >
                                    <ReviewRow
                                        label="Staff"
                                        value={session?.user_name}
                                    />
                                    <ReviewRow
                                        label="Clock in"
                                        value={
                                            session
                                                ? display(session.clock_in_at)
                                                : undefined
                                        }
                                    />
                                    <ReviewRow
                                        label="Clock out"
                                        value={
                                            display(outAt) + ' · ' + timezone
                                        }
                                    />
                                    <ReviewRow
                                        label="Breaks"
                                        value={`${Number(breakMin) || 0}m`}
                                    />
                                    <ReviewRow
                                        label="Worked"
                                        value={<strong>{workedH}h</strong>}
                                    />
                                </ReviewCard>
                                {errors.submit ? (
                                    <InfoCard icon={AlertTriangle} tone="crit">
                                        {errors.submit}
                                    </InfoCard>
                                ) : null}
                            </div>
                        </WizardStepPane>
                    ) : null}
                </fieldset>
            </WizardShell>
            {close.dialog}
            <ConfirmDialog
                open={switchTo !== null}
                onClose={() => setSwitchTo(null)}
                onConfirm={() => {
                    if (switchTo && !command.busy.current)
                        selectSession(switchTo);
                }}
                title="Change the session being corrected?"
                description="Your current time, break and reason entries will be discarded. The new session will open with its recorded values."
                confirmText="Change session"
                cancelText="Keep current session"
                processing={processing}
            />
        </>
    );
}
