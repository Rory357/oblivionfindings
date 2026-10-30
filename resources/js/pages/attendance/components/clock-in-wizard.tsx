/* Clock-in wizard — 3 steps on the shared wizard shell (Add Client contract):
 * pick the shift, location check + note, review & clock in. Posts to the
 * existing POST /attendance/clock-in endpoint. */
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Field,
    InfoCard,
    Segmented,
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
    CalendarDays,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Footprints,
    Home,
    Info,
    Link2,
    Loader2,
    LogIn,
    MapPin,
    Timer,
} from 'lucide-react';
import { useState } from 'react';
import {
    AttendanceCommandFeedback,
    useAttendanceClose,
    useAttendanceValidationFocus,
} from './attendance-command-ui';
import { useAttendanceCommand } from './use-attendance-command';

import type { EligibleShift } from './shared';

const STEPS: readonly WizardStep[] = [
    {
        key: 'shift',
        label: 'Shift',
        blurb: 'What you’re clocking in to',
        icon: CalendarDays,
    },
    {
        key: 'location',
        label: 'Location check',
        blurb: 'Where you’re starting from',
        icon: MapPin,
    },
    {
        key: 'review',
        label: 'Review & clock in',
        blurb: 'Confirm and start',
        icon: CheckCircle2,
    },
] as const;

type LocMode = 'site' | 'community' | 'travel';

const LOC_LABEL: Record<LocMode, string> = {
    site: 'On site',
    community: 'In the community',
    travel: 'Travelling to client',
};

type ClockInProps = {
    open: boolean;
    onClose: () => void;
    shifts: EligibleShift[];
    timezone?: string;
};

export function ClockInWizard(props: ClockInProps) {
    return props.open ? <ClockInForm {...props} /> : null;
}

function ClockInForm({
    onClose,
    shifts,
    timezone = WORKER_TIMEZONE,
}: ClockInProps) {
    const [stepIndex, setStepIndex] = useState(0);
    const [initialShift] = useState(() =>
        shifts.length ? String(shifts[0].id) : 'none',
    );
    const [shiftKey, setShiftKey] = useState(initialShift);
    const [locMode, setLocMode] = useState<LocMode>('site');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    useAttendanceValidationFocus(errors, stepIndex, 'clock-in-wizard-fields');
    const command = useAttendanceCommand('clock_in');
    const processing = command.pending;
    const done = command.receipt;
    const close = useAttendanceClose({
        onClose,
        pending: processing,
        busy: command.busy,
        outcome: command.outcome,
        dirty:
            shiftKey !== initialShift || locMode !== 'site' || note.length > 0,
    });
    const display = (value: string | Date) =>
        formatDateTimeInZone(value, timezone);
    const shift = shifts.find((s) => String(s.id) === shiftKey) ?? null;
    const pct = stepIndex === 2 ? 100 : stepIndex === 1 ? 66 : 33;
    const locationValue =
        locMode === 'site'
            ? shift?.location
                ? 'On site · ' + shift.location
                : 'On site'
            : LOC_LABEL[locMode];

    const next = () => {
        if (command.busy.current || processing) return;
        if (command.outcome?.status === 'unknown') {
            setStepIndex((index) => Math.min(index + 1, STEPS.length - 1));
            return;
        }
        if (command.blocked) return;
        if (shiftKey !== 'none' && !shift) {
            setErrors({
                shift: 'The chosen shift is no longer available. Choose a current shift or automatic matching.',
            });
            setStepIndex(0);
            return;
        }
        setErrors({});
        if (STEPS[stepIndex].key === 'review') {
            command.submit(
                '/attendance/clock-in',
                {
                    shift_id: shift?.id ?? null,
                    location: locationValue,
                    notes: note.trim() || null,
                },
                { action: 'clock_in', explicitShiftId: shift?.id },
            );
            return;
        }
        setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };

    const tiles = [
        ...shifts.map((s) => ({
            key: String(s.id),
            label: `${s.client_name ? `${s.client_name} — ` : ''}${display(s.starts_at)}–${display(s.ends_at)}`,
            description: `${s.location ? `${s.location} · ` : ''}Shift #${s.id}`,
            icon: Home,
            meta: 'In the clock-in window',
        })),
        {
            key: 'none',
            label: 'Match an assigned shift if available',
            description:
                'The system checks your current assigned shifts. If there are none, it records an unlinked session.',
            icon: Link2,
        },
    ];

    const stepKey = STEPS[stepIndex].key;

    return (
        <>
            <WizardShell
                open
                onClose={close.requestClose}
                title="Clock in"
                description="A guided flow to start an attendance session."
                railIcon={LogIn}
                railTitle="Clock in"
                railSub="Start an attendance session"
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
                pctLabel="Session detail"
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
                        {stepKey === 'review' ? (
                            <Button
                                onClick={next}
                                disabled={command.blocked}
                                data-test="attendance-confirm-clock-in"
                            >
                                {processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <LogIn className="h-4 w-4" />
                                )}
                                Clock in
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
                            title="You're on the clock"
                            blurb={
                                <>
                                    Clocked in at {display(done.clock_in_at)} (
                                    {timezone}).
                                    {done.shift_id
                                        ? ' Linked to shift #' +
                                          done.shift_id +
                                          '.'
                                        : ' No shift was linked.'}{' '}
                                    Your attendance session is open. Clock out
                                    when you finish to record the end and check
                                    the timesheet outcome.
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
                    id="clock-in-wizard-fields"
                >
                    {stepKey === 'shift' ? (
                        <WizardStepPane key="shift">
                            <StepHead
                                icon={CalendarDays}
                                title="What are you clocking in to?"
                                blurb="Only shifts inside the clock-in window are shown."
                            />
                            <div className="grid gap-4">
                                <Field
                                    label="Eligible shifts"
                                    required
                                    error={errors.shift}
                                >
                                    <TilePicker
                                        value={shiftKey}
                                        onChange={setShiftKey}
                                        options={tiles}
                                        cols={2}
                                    />
                                </Field>
                                <InfoCard icon={Info}>
                                    Clocking in records the start of your
                                    attendance session. Clock out when you
                                    finish; the result will show whether a draft
                                    timesheet was created or needs follow-up.
                                </InfoCard>
                            </div>
                        </WizardStepPane>
                    ) : null}

                    {stepKey === 'location' ? (
                        <WizardStepPane key="location">
                            <StepHead
                                icon={MapPin}
                                title="Where are you starting from?"
                                blurb="A quick location note keeps the session audit-ready."
                            />
                            <div className="grid gap-4">
                                <div className="flex items-center gap-3 rounded-xl border border-primary/30 bg-primary/10 p-4">
                                    <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary-fill text-primary-fill-foreground">
                                        <MapPin className="h-5 w-5" />
                                    </span>
                                    <div className="min-w-0">
                                        <div className="text-sm font-bold">
                                            {shift?.location ??
                                                'No site location on file'}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            Your starting point is recorded with
                                            the session for the audit trail.
                                        </div>
                                    </div>
                                </div>
                                <Field label="Starting from">
                                    <Segmented<LocMode>
                                        value={locMode}
                                        onChange={setLocMode}
                                        options={[
                                            {
                                                value: 'site',
                                                label: 'On site',
                                                icon: Home,
                                            },
                                            {
                                                value: 'community',
                                                label: 'In the community',
                                                icon: Footprints,
                                            },
                                            {
                                                value: 'travel',
                                                label: 'Travelling to client',
                                                icon: MapPin,
                                            },
                                        ]}
                                    />
                                </Field>
                                <Field
                                    label="Note for the session"
                                    hint="optional — visible to coordinators"
                                >
                                    <Input
                                        value={note}
                                        onChange={(e) =>
                                            setNote(e.target.value)
                                        }
                                        placeholder="e.g. Starting early to prep breakfast meds"
                                    />
                                </Field>
                            </div>
                        </WizardStepPane>
                    ) : null}

                    {stepKey === 'review' ? (
                        <WizardStepPane key="review">
                            <StepHead
                                icon={CheckCircle2}
                                title="Review & clock in"
                                blurb="Quick check — you can correct times later if something's off."
                            />
                            <div className="grid gap-3.5 sm:grid-cols-2">
                                <ReviewCard
                                    icon={CalendarDays}
                                    title="Shift"
                                    onEdit={() => setStepIndex(0)}
                                >
                                    <ReviewRow
                                        label="Shift"
                                        value={
                                            shift
                                                ? '#' + shift.id
                                                : 'Automatically match if available'
                                        }
                                    />
                                    <ReviewRow
                                        label="Client"
                                        value={shift?.client_name}
                                    />
                                    <ReviewRow
                                        label="Scheduled"
                                        value={
                                            shift
                                                ? `${display(shift.starts_at)}–${display(shift.ends_at)}`
                                                : undefined
                                        }
                                    />
                                    <ReviewRow
                                        label="Location"
                                        value={shift?.location}
                                    />
                                </ReviewCard>
                                <ReviewCard
                                    icon={MapPin}
                                    title="Check-in"
                                    onEdit={() => setStepIndex(1)}
                                >
                                    <ReviewRow
                                        label="Clock-in time"
                                        value={
                                            'Recorded when the server accepts your clock-in · ' +
                                            timezone
                                        }
                                    />
                                    <ReviewRow
                                        label="Starting from"
                                        value={LOC_LABEL[locMode]}
                                    />
                                    <ReviewRow label="Note" value={note} />
                                </ReviewCard>
                                {errors.submit ? (
                                    <InfoCard icon={Info} tone="crit">
                                        {errors.submit}
                                    </InfoCard>
                                ) : (
                                    <InfoCard icon={Link2}>
                                        Clock-in starts attendance. It does not
                                        submit or approve a timesheet.
                                    </InfoCard>
                                )}
                            </div>
                        </WizardStepPane>
                    ) : null}
                </fieldset>
            </WizardShell>
            {close.dialog}
        </>
    );
}
