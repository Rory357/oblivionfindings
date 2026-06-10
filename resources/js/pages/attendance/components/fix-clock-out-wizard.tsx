/* Fix-a-missed-clock-out wizard — 3 steps on the shared wizard shell: pick the
 * session (stale 16h+ ones flagged), set the real clock-out + breaks, give the
 * audit-log reason. Posts to POST /attendance/sessions/{id}/correct; the
 * linked timesheet is recalculated (submitted ones return to draft). */
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
import { formatDate, formatDateTime, formatTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
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
import { useEffect, useState } from 'react';

import {
    dateTimeFromInputs,
    minutesBetween,
    toHHMM,
    toYMD,
    type FixCandidate,
} from './shared';

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

export function FixClockOutWizard({
    open,
    onClose,
    sessions,
}: {
    open: boolean;
    onClose: () => void;
    sessions: FixCandidate[];
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [sessionId, setSessionId] = useState('');
    const [outDate, setOutDate] = useState('');
    const [outTime, setOutTime] = useState('17:00');
    const [breakMin, setBreakMin] = useState('0');
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    const [done, setDone] = useState<{ outAt: Date; workedH: string } | null>(
        null,
    );

    const seedTimes = (candidate: FixCandidate | undefined) => {
        if (!candidate) return;
        if (candidate.clock_out_at) {
            // Closed session being corrected — start from the recorded times.
            const out = new Date(candidate.clock_out_at);
            setOutDate(toYMD(out));
            setOutTime(toHHMM(out));
        } else {
            // Missed clock-out — suggest 8h after clock-in, never the future.
            const suggested = new Date(
                new Date(candidate.clock_in_at).getTime() + 8 * 3600000,
            );
            const capped = suggested.getTime() > Date.now() ? new Date() : suggested;
            setOutDate(toYMD(capped));
            setOutTime(toHHMM(capped));
        }
        setBreakMin(String(candidate.break_minutes ?? 0));
    };

    useEffect(() => {
        if (!open) return;
        setStepIndex(0);
        setErrors({});
        setProcessing(false);
        setDone(null);
        setReason('');
        const first = sessions[0];
        setSessionId(first ? String(first.id) : '');
        seedTimes(first);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- re-seed only on open
    }, [open]);

    const session = sessions.find((s) => String(s.id) === sessionId) ?? null;
    const outAt =
        outDate && outTime ? dateTimeFromInputs(outDate, outTime) : new Date();
    const workedM = session
        ? Math.max(
              0,
              minutesBetween(session.clock_in_at, outAt) -
                  (Number(breakMin) || 0),
          )
        : 0;
    const workedH = (workedM / 60).toFixed(2);
    const pct = Math.round(
        (((sessionId ? 1 : 0) + (outTime ? 1 : 0) + (reason.trim() ? 1 : 0)) /
            3) *
            100,
    );

    const pickSession = (key: string) => {
        setSessionId(key);
        seedTimes(sessions.find((s) => String(s.id) === key));
    };

    const validate = (key: string): Record<string, string> => {
        const e: Record<string, string> = {};
        if (key === 'session' && !sessionId) {
            e.session = 'Pick the session to correct';
        }
        if (key === 'times' && session) {
            if (outAt.getTime() <= new Date(session.clock_in_at).getTime()) {
                e.outTime = 'Clock-out must be after clock-in';
            } else if (outAt.getTime() > Date.now() + 2 * 60000) {
                e.outTime = 'Clock-out cannot be in the future';
            }
            if (
                (Number(breakMin) || 0) >=
                minutesBetween(session.clock_in_at, outAt)
            ) {
                e.breakMin = 'Breaks can’t exceed the session length';
            }
        }
        if (key === 'reason' && !reason.trim()) {
            e.reason = 'A reason is required — it’s recorded in the audit log';
        }
        return e;
    };

    const next = () => {
        const cur = STEPS[stepIndex];
        const e = validate(cur.key);
        setErrors(e);
        if (Object.keys(e).length) return;
        if (cur.key === 'reason') {
            if (!session) return;
            setProcessing(true);
            const submittedOutAt = outAt;
            const submittedWorkedH = workedH;
            router.post(
                `/attendance/sessions/${session.id}/correct`,
                {
                    clock_out_at: outAt.toISOString(),
                    break_minutes: Number(breakMin) || 0,
                    reason: reason.trim(),
                },
                {
                    preserveScroll: true,
                    onSuccess: () =>
                        setDone({
                            outAt: submittedOutAt,
                            workedH: submittedWorkedH,
                        }),
                    onError: (errs) =>
                        setErrors({
                            submit:
                                errs.correct_session ??
                                Object.values(errs)[0] ??
                                'Could not save the correction. Please retry.',
                        }),
                    onFinish: () => setProcessing(false),
                },
            );
            return;
        }
        setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };

    const stepKey = STEPS[stepIndex].key;

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="Fix a clock-out"
            description="A guided flow to correct a missed or wrong clock-out time."
            railIcon={Wrench}
            railTitle="Fix a clock-out"
            railSub="Correct a missed or wrong time"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={setStepIndex}
            pct={pct}
            pctLabel="Correction detail"
            footerStart={
                stepIndex > 0 ? (
                    <Button
                        variant="ghost"
                        onClick={() => setStepIndex((i) => Math.max(0, i - 1))}
                    >
                        <ChevronLeft className="h-4 w-4" /> Back
                    </Button>
                ) : null
            }
            footerEnd={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    {stepKey === 'reason' ? (
                        <Button
                            onClick={next}
                            disabled={processing}
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
                        <Button onClick={next}>
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
                                The session now reads{' '}
                                {session ? formatTime(session.clock_in_at) : ''}{' '}
                                → {formatTime(done.outAt)} ({done.workedH}h). The
                                linked timesheet was updated and your reason was
                                recorded in the audit log.
                            </>
                        }
                        actions={
                            <Button onClick={onClose}>
                                <Timer className="h-4 w-4" /> Back to attendance
                            </Button>
                        }
                    />
                ) : null
            }
        >
            {stepKey === 'session' ? (
                <WizardStepPane key="session">
                    <StepHead
                        icon={AlertTriangle}
                        title="Which session needs fixing?"
                        blurb="Open sessions over 16 hours are flagged as likely missed clock-outs."
                    />
                    <div className="grid gap-4">
                        <Field label="Sessions" required error={errors.session}>
                            <TilePicker
                                value={sessionId}
                                onChange={pickSession}
                                cols={2}
                                options={sessions.map((s) => ({
                                    key: String(s.id),
                                    label: `${s.user_name} · In ${formatDateTime(s.clock_in_at)}`,
                                    description: `${
                                        s.shift_id
                                            ? `Shift #${s.shift_id}${s.location ? ` · ${s.location}` : ''}`
                                            : 'No shift linked'
                                    } · ${s.clock_out_at ? `out ${formatDateTime(s.clock_out_at)}` : 'still open'}`,
                                    icon: s.is_stale ? AlertTriangle : Clock,
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
                                        ? formatDateTime(session.clock_in_at)
                                        : ''
                                }
                                disabled
                            />
                        </Field>
                        <div className="grid grid-cols-2 gap-2">
                            <Field label="Clock-out date" required>
                                <Input
                                    type="date"
                                    value={outDate}
                                    onChange={(e) => setOutDate(e.target.value)}
                                />
                            </Field>
                            <Field label="Time" required error={errors.outTime}>
                                <Input
                                    type="time"
                                    value={outTime}
                                    onChange={(e) => setOutTime(e.target.value)}
                                />
                            </Field>
                        </div>
                        <Field label="Break minutes" error={errors.breakMin}>
                            <Input
                                type="number"
                                min={0}
                                max={240}
                                value={breakMin}
                                onChange={(e) => setBreakMin(e.target.value)}
                            />
                        </Field>
                        <div className="flex items-end pb-1">
                            <Badge
                                variant="outline"
                                className="gap-1 text-primary"
                            >
                                <Timer className="h-3 w-3" /> {workedH}h worked
                            </Badge>
                        </div>
                        <InfoCard icon={AlertTriangle} tone="warn">
                            The linked draft timesheet is recalculated from these
                            times. If the timesheet was already submitted, it
                            returns to <strong>draft</strong> for re-approval.
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
                                onChange={(e) => setReason(e.target.value)}
                                placeholder="e.g. Missed clock-out on Monday — left at 5pm after sleepover shift"
                            />
                        </Field>
                        <ReviewCard
                            icon={Clock}
                            title="Corrected session"
                            onEdit={() => setStepIndex(1)}
                        >
                            <ReviewRow label="Staff" value={session?.user_name} />
                            <ReviewRow
                                label="Clock in"
                                value={
                                    session
                                        ? formatDateTime(session.clock_in_at)
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Clock out"
                                value={`${formatDate(outAt)}, ${formatTime(outAt)}`}
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
        </WizardShell>
    );
}
