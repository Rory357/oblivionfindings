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
import { formatTime } from '@/lib/datetime';
import { router, usePage } from '@inertiajs/react';
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
import { useEffect, useState } from 'react';

import { minutesBetween, timeOnDate, toHHMM, type OpenSession } from './shared';

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

export function ClockOutWizard({
    open,
    onClose,
    session,
}: {
    open: boolean;
    onClose: () => void;
    session: OpenSession | null;
}) {
    const page = usePage().props as {
        flash?: { clock_out_blockers?: ClockOutBlocker[] | null };
    };

    const [stepIndex, setStepIndex] = useState(0);
    const [outTime, setOutTime] = useState(() => toHHMM(new Date()));
    const [extraBreak, setExtraBreak] = useState('0');
    const [skipHandover, setSkipHandover] = useState(false);
    const [narrative, setNarrative] = useState('');
    const [mood, setMood] = useState<Mood>('settled');
    const [medsCompleted, setMedsCompleted] = useState(true);
    const [tasks, setTasks] = useState<string[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    // Snapshot of everything the success pane needs — the page props refresh
    // on success and `session` becomes null, so the pane can't read from it.
    const [done, setDone] = useState<{
        workedH: string;
        breakM: number;
        outLabel: string;
        timesheetId: number | null;
        handoverSent: boolean;
    } | null>(null);

    useEffect(() => {
        if (!open) return;
        setStepIndex(0);
        setOutTime(toHHMM(new Date()));
        setExtraBreak('0');
        setSkipHandover(false);
        setNarrative('');
        setMood('settled');
        setMedsCompleted(true);
        setTasks([]);
        setErrors({});
        setProcessing(false);
        setDone(null);
    }, [open]);

    // Blockers arrive as flash alongside errors.clock_out — read at render so
    // the freshest props (post-error) are used, not a closure snapshot.
    const blockers: ClockOutBlocker[] = errors.submit
        ? (page.flash?.clock_out_blockers ?? [])
        : [];

    // After a successful clock-out the refreshed props null the open session;
    // keep rendering from the `done` snapshot so the success pane shows.
    if (!session) {
        if (!done) return null;
        return (
            <WizardShell
                open={open}
                onClose={onClose}
                title="Clock out"
                description="A guided flow to close your attendance session."
                railIcon={LogOut}
                railTitle="Clock out"
                railSub="Session closed"
                steps={STEPS}
                stepIndex={STEPS.length - 1}
                onStepClick={() => undefined}
                success={<ClockOutSuccess done={done} onClose={onClose} />}
            />
        );
    }

    const hasShift = session.shift_id != null;
    const now = new Date();

    // Tracked break events: closed ones carry minutes; a running one counts to
    // "now" (the server closes it at the clock-out time).
    const trackedBreakM = session.breaks.reduce(
        (acc, b) =>
            acc +
            (b.ended_at
                ? (b.minutes ??
                  minutesBetween(b.started_at ?? b.ended_at, b.ended_at))
                : b.started_at
                  ? minutesBetween(b.started_at, now)
                  : 0),
        0,
    );
    const totalBreakM = trackedBreakM + (Number(extraBreak) || 0);
    const outAt = timeOnDate(outTime);
    const workedM = Math.max(
        0,
        minutesBetween(session.clock_in_at, outAt) - totalBreakM,
    );
    const workedH = (workedM / 60).toFixed(2);

    const handoverDone =
        skipHandover || !hasShift || narrative.trim().length >= 10;
    const pct = Math.round(((1 + (handoverDone ? 2 : 0)) / 3) * 100);

    const validate = (key: string): Record<string, string> => {
        const e: Record<string, string> = {};
        if (key === 'time') {
            if (outAt.getTime() <= new Date(session.clock_in_at).getTime()) {
                e.outTime = `Clock-out must be after clock-in (${formatTime(session.clock_in_at)})`;
            }
            if (totalBreakM >= minutesBetween(session.clock_in_at, outAt)) {
                e.extraBreak = 'Breaks can’t exceed the session length';
            }
        }
        if (
            key === 'handover' &&
            hasShift &&
            !skipHandover &&
            narrative.trim().length < 10
        ) {
            e.narrative = 'Add a short narrative (or mark no handover needed)';
        }
        return e;
    };

    const submit = () => {
        setProcessing(true);
        router.post(
            '/attendance/clock-out',
            {
                session_id: session.id,
                clock_out_at: outAt.toISOString(),
                break_minutes: totalBreakM,
                ...(hasShift && !skipHandover
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
            {
                preserveScroll: true,
                onSuccess: () =>
                    setDone({
                        workedH,
                        breakM: totalBreakM,
                        outLabel: formatTime(outAt),
                        timesheetId: session.timesheet_id,
                        handoverSent: hasShift && !skipHandover,
                    }),
                onError: (errs) =>
                    setErrors({
                        submit:
                            errs.clock_out ??
                            Object.values(errs)[0] ??
                            'Could not clock out. Please retry.',
                    }),
                onFinish: () => setProcessing(false),
            },
        );
    };

    const next = () => {
        const cur = STEPS[stepIndex];
        if (cur.key === 'review') {
            submit();
            return;
        }
        const e = validate(cur.key);
        setErrors(e);
        if (Object.keys(e).length) return;
        setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };

    const stepKey = STEPS[stepIndex].key;

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="Clock out"
            description="A guided flow to close your attendance session."
            railIcon={LogOut}
            railTitle="Clock out"
            railSub={`Session since ${formatTime(session.clock_in_at)}`}
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={setStepIndex}
            pct={pct}
            pctLabel="Hand-back quality"
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
                    {stepKey === 'review' ? (
                        <Button
                            onClick={next}
                            disabled={processing}
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
                        <Button onClick={next}>
                            Continue <ChevronRight className="h-4 w-4" />
                        </Button>
                    )}
                </>
            }
            success={
                done ? <ClockOutSuccess done={done} onClose={onClose} /> : null
            }
        >
            {stepKey === 'time' ? (
                <WizardStepPane key="time">
                    <StepHead
                        icon={Clock}
                        title="Confirm your time"
                        blurb="Breaks tracked during the shift are already counted."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Clock-out time"
                            required
                            error={errors.outTime}
                        >
                            <Input
                                type="time"
                                value={outTime}
                                onChange={(e) => setOutTime(e.target.value)}
                            />
                        </Field>
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
                                onChange={(e) => setExtraBreak(e.target.value)}
                            />
                        </Field>
                        <div className="sm:col-span-2">
                            <SubHead icon={Coffee}>
                                Breaks tracked this session
                            </SubHead>
                            {session.breaks.length === 0 ? (
                                <p className="mt-2 text-[13px] text-muted-foreground">
                                    No breaks tracked — use “Start break” on the
                                    attendance page next time and this fills
                                    itself in.
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
                                                {formatTime(b.started_at)} –{' '}
                                                {b.ended_at
                                                    ? formatTime(b.ended_at)
                                                    : 'now'}
                                            </span>
                                            <span className="ml-auto text-muted-foreground tabular-nums">
                                                {b.ended_at
                                                    ? (b.minutes ?? 0)
                                                    : b.started_at
                                                      ? minutesBetween(
                                                            b.started_at,
                                                            now,
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
                            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary text-primary-foreground">
                                <Timer className="h-5 w-5" />
                            </span>
                            <div>
                                <div className="text-[15px] font-bold">
                                    {workedH}h worked
                                </div>
                                <div className="text-[13px] text-muted-foreground">
                                    {formatTime(session.clock_in_at)} →{' '}
                                    {formatTime(outAt)} minus {totalBreakM}m
                                    breaks
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
                        blurb="A short handover here saves a phone call later. Need the full wizard? Use Shift Handovers."
                    />
                    <div className="grid gap-4">
                        {!hasShift ? (
                            <InfoCard icon={ArrowLeftRight}>
                                This session has no linked shift, so there is no
                                shift record to hand over. File one from{' '}
                                <strong>Operations → Shift Handovers</strong> if
                                the next worker needs a brief.
                            </InfoCard>
                        ) : (
                            <>
                                {/* eslint-disable-next-line no-restricted-syntax -- compact switch row, not a Card surface */}
                                <div className="flex items-center justify-between rounded-lg border border-border bg-card px-4 py-3">
                                    <div>
                                        <div className="text-sm font-semibold">
                                            No handover needed
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            e.g. end of day, no incoming shift
                                            at this site
                                        </div>
                                    </div>
                                    <Switch
                                        checked={skipHandover}
                                        onCheckedChange={setSkipHandover}
                                        aria-label="No handover needed"
                                    />
                                </div>
                                {!skipHandover ? (
                                    <>
                                        <Field
                                            label="How the shift went"
                                            required
                                            error={errors.narrative}
                                        >
                                            <Textarea
                                                rows={4}
                                                value={narrative}
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
                                            This files the same record as the{' '}
                                            <strong>
                                                Shift Handover wizard
                                            </strong>{' '}
                                            — the incoming staff member
                                            acknowledges it from their
                                            attendance page.
                                        </InfoCard>
                                    </>
                                ) : null}
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
                        blurb="This closes the session and updates the draft timesheet."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <ReviewCard
                            icon={Clock}
                            title="Session"
                            onEdit={() => setStepIndex(0)}
                        >
                            <ReviewRow
                                label="Clock in"
                                value={formatTime(session.clock_in_at)}
                            />
                            <ReviewRow
                                label="Clock out"
                                value={formatTime(outAt)}
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
                                        ? `#${session.timesheet_id} · draft`
                                        : 'Synced on clock-out'
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
                                    No linked shift — nothing to hand over.
                                </p>
                            ) : skipHandover ? (
                                <p className="py-1.5 text-[13px] text-muted-foreground">
                                    Marked as not needed.
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
                        {errors.submit ? (
                            <InfoCard icon={AlertTriangle} tone="warn">
                                <p className="font-semibold">{errors.submit}</p>
                                {blockers.length > 0 ? (
                                    <ul className="mt-1.5 list-disc space-y-0.5 pl-4">
                                        {blockers.map((b) => (
                                            <li key={b.key}>
                                                <strong>{b.label}</strong> —{' '}
                                                {b.detail}
                                            </li>
                                        ))}
                                    </ul>
                                ) : null}
                            </InfoCard>
                        ) : (
                            <InfoCard icon={Link2}>
                                Hours land on{' '}
                                {session.timesheet_id ? (
                                    <>
                                        timesheet{' '}
                                        <strong>#{session.timesheet_id}</strong>
                                    </>
                                ) : (
                                    'a draft timesheet'
                                )}{' '}
                                for payroll review — no separate entry needed.
                            </InfoCard>
                        )}
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

function ClockOutSuccess({
    done,
    onClose,
}: {
    done: {
        workedH: string;
        breakM: number;
        outLabel: string;
        timesheetId: number | null;
        handoverSent: boolean;
    };
    onClose: () => void;
}) {
    return (
        <WizardSuccessPane
            title={`${done.workedH}h logged — session closed`}
            blurb={
                <>
                    Clocked out at {done.outLabel} with {done.breakM}m of
                    breaks.{' '}
                    {done.timesheetId
                        ? `The hours are synced to timesheet #${done.timesheetId} (draft)`
                        : 'The hours are synced to a draft timesheet'}
                    {done.handoverSent
                        ? ', and your handover is waiting for the incoming shift to acknowledge.'
                        : '.'}
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
