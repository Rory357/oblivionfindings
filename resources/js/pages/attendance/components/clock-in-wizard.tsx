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
import { formatTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    Ban,
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
import { useEffect, useState } from 'react';

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

export function ClockInWizard({
    open,
    onClose,
    shifts,
}: {
    open: boolean;
    onClose: () => void;
    shifts: EligibleShift[];
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const [shiftKey, setShiftKey] = useState('none');
    const [locMode, setLocMode] = useState<LocMode>('site');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);
    // Snapshot for the success pane — eligibleShifts refresh (and empty out)
    // once the open session lands, so the pane can't derive from props.
    const [done, setDone] = useState<{
        shiftLabel: string;
        atLabel: string;
    } | null>(null);

    useEffect(() => {
        if (!open) return;
        setStepIndex(0);
        setErrors({});
        setProcessing(false);
        setDone(null);
        setNote('');
        setLocMode('site');
        setShiftKey(shifts.length ? String(shifts[0].id) : 'none');
        // eslint-disable-next-line react-hooks/exhaustive-deps -- re-seed only on open
    }, [open]);

    const shift = shifts.find((s) => String(s.id) === shiftKey) ?? null;
    const pct = Math.round(
        (((shiftKey ? 1 : 0) + (locMode ? 1 : 0) + (note.trim() ? 1 : 0)) / 3) *
            100,
    );

    const locationValue =
        locMode === 'site'
            ? shift?.location
                ? `On site · ${shift.location}`
                : 'On site'
            : LOC_LABEL[locMode];

    const next = () => {
        const cur = STEPS[stepIndex];
        if (cur.key === 'review') {
            setProcessing(true);
            const shiftLabel = shift
                ? ` on shift #${shift.id}${shift.client_name ? ` for ${shift.client_name}` : ''}`
                : ' with no linked shift';
            router.post(
                '/attendance/clock-in',
                {
                    shift_id: shift ? shift.id : null,
                    location: locationValue,
                    notes: note.trim() || null,
                },
                {
                    preserveScroll: true,
                    onSuccess: () =>
                        setDone({
                            shiftLabel,
                            atLabel: formatTime(new Date()),
                        }),
                    onError: (errs) =>
                        setErrors({
                            submit:
                                errs.clock_in ??
                                Object.values(errs)[0] ??
                                'Could not clock in. Please retry.',
                        }),
                    onFinish: () => setProcessing(false),
                },
            );
            return;
        }
        if (cur.key === 'shift' && !shiftKey) {
            setErrors({ shift: 'Choose a shift, or pick "No linked shift"' });
            return;
        }
        setErrors({});
        setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };

    const tiles = [
        ...shifts.map((s) => ({
            key: String(s.id),
            label: `${s.client_name ? `${s.client_name} — ` : ''}${formatTime(s.starts_at)}–${formatTime(s.ends_at)}`,
            description: `${s.location ? `${s.location} · ` : ''}Shift #${s.id}`,
            icon: Home,
            meta: 'In the clock-in window',
        })),
        {
            key: 'none',
            label: 'No linked shift',
            description:
                'Clock in without a rostered shift — a coordinator can link it later.',
            icon: Ban,
        },
    ];

    const stepKey = STEPS[stepIndex].key;

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="Clock in"
            description="A guided flow to start an attendance session."
            railIcon={LogIn}
            railTitle="Clock in"
            railSub="Start an attendance session"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={setStepIndex}
            pct={pct}
            pctLabel="Session detail"
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
                        <Button onClick={next}>
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
                                Clocked in at {done.atLabel}
                                {done.shiftLabel}. Breaks and handover are
                                captured when you clock out — the hours sync to
                                a draft timesheet.
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
                            Clocking in opens an attendance session; the hours
                            sync to a <strong>draft timesheet</strong>{' '}
                            automatically — the same record payroll sees.
                            Nothing to fill in twice.
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
                            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary text-primary-foreground">
                                <MapPin className="h-5 w-5" />
                            </span>
                            <div className="min-w-0">
                                <div className="text-sm font-bold">
                                    {shift?.location ??
                                        'No site location on file'}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    Your starting point is recorded with the
                                    session for the audit trail.
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
                                onChange={(e) => setNote(e.target.value)}
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
                                    shift ? `#${shift.id}` : 'No linked shift'
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
                                        ? `${formatTime(shift.starts_at)}–${formatTime(shift.ends_at)}`
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
                                value={`${formatTime(new Date())} (now)`}
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
                                Your hours sync to a draft timesheet for payroll
                                review — no separate entry needed.
                            </InfoCard>
                        )}
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}
