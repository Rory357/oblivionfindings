/* eslint-disable no-restricted-syntax -- Mirrors the Add-Client wizard chrome:
 * styled native toggles for the loading flags and a token-tinted summary card.
 * Every colour is a semantic design token. */
import { type Page } from '@inertiajs/core';
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    CalendarClock,
    Check,
    ClipboardCheck,
    Clock,
    FileText,
    Moon,
    Plus,
    Trash2,
    UserPlus,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { PeoplePicker, type PersonOption } from '@/components/hr/people-picker';
import {
    Field,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import {
    PAY_TYPE_OPTIONS,
    payTypeLabel,
    type Disturbance,
    type NamedOption,
    type TimeEntry,
} from './types';

export type TimeDialogMode = 'add' | 'behalf' | 'edit' | 'correct' | 'void';

type FormShape = {
    user_id: string;
    target_user_id: string;
    clock_in: string;
    clock_out: string;
    break_minutes: string;
    pay_type: string;
    is_sleepover: boolean;
    is_on_call: boolean;
    is_public_holiday: boolean;
    disturbances: Disturbance[];
    mileage_km: string;
    site_id: string;
    client_id: string;
    cost_centre: string;
    project_code: string;
    notes: string;
    reason: string;
    amendment_reason: string;
};

const EMPTY: FormShape = {
    user_id: '',
    target_user_id: '',
    clock_in: '',
    clock_out: '',
    break_minutes: '0',
    pay_type: 'standard',
    is_sleepover: false,
    is_on_call: false,
    is_public_holiday: false,
    disturbances: [],
    mileage_km: '',
    site_id: '',
    client_id: '',
    cost_centre: '',
    project_code: '',
    notes: '',
    reason: '',
    amendment_reason: '',
};

const MODE_META: Record<
    TimeDialogMode,
    { title: string; railTitle: string; railSub: string; icon: typeof Clock }
> = {
    add: {
        title: 'Add time entry',
        railTitle: 'New entry',
        railSub: 'HR · Timekeeping',
        icon: Clock,
    },
    behalf: {
        title: 'Clock on behalf',
        railTitle: 'On behalf',
        railSub: 'HR · Timekeeping',
        icon: UserPlus,
    },
    edit: {
        title: 'Edit time entry',
        railTitle: 'Amend entry',
        railSub: 'HR · Timekeeping',
        icon: FileText,
    },
    correct: {
        title: 'Correct clock-out',
        railTitle: 'Correct',
        railSub: 'HR · Timekeeping',
        icon: CalendarClock,
    },
    void: {
        title: 'Void entry',
        railTitle: 'Void',
        railSub: 'HR · Timekeeping',
        icon: Trash2,
    },
};

function stepsFor(mode: TimeDialogMode): WizardStep[] {
    switch (mode) {
        case 'add':
            return [
                {
                    key: 'staff',
                    label: 'Staff & date',
                    blurb: 'Who & when',
                    icon: UserPlus,
                },
                {
                    key: 'times',
                    label: 'Times & break',
                    blurb: 'Clock + break',
                    icon: Clock,
                },
                {
                    key: 'pay',
                    label: 'Pay & context',
                    blurb: 'Loadings & links',
                    icon: Moon,
                },
                {
                    key: 'review',
                    label: 'Review',
                    blurb: 'Confirm & save',
                    icon: ClipboardCheck,
                },
            ];
        case 'behalf':
            return [
                {
                    key: 'staff',
                    label: 'Staff',
                    blurb: 'Pick the person',
                    icon: UserPlus,
                },
                {
                    key: 'times',
                    label: 'Times & break',
                    blurb: 'Clock + break',
                    icon: Clock,
                },
                {
                    key: 'pay',
                    label: 'Pay & context',
                    blurb: 'Loadings & links',
                    icon: Moon,
                },
                {
                    key: 'review',
                    label: 'Reason & review',
                    blurb: 'Why & confirm',
                    icon: ClipboardCheck,
                },
            ];
        case 'edit':
            return [
                {
                    key: 'times',
                    label: 'Times & break',
                    blurb: 'Clock + break',
                    icon: Clock,
                },
                {
                    key: 'pay',
                    label: 'Pay & context',
                    blurb: 'Loadings & links',
                    icon: Moon,
                },
                {
                    key: 'reason',
                    label: 'Reason & diff',
                    blurb: 'Audit trail',
                    icon: ClipboardCheck,
                },
            ];
        case 'correct':
            return [
                {
                    key: 'finish',
                    label: 'Finish time',
                    blurb: 'Close the clock',
                    icon: Clock,
                },
                {
                    key: 'reason',
                    label: 'Reason & confirm',
                    blurb: 'Audit trail',
                    icon: ClipboardCheck,
                },
            ];
        case 'void':
            return [
                {
                    key: 'reason',
                    label: 'Void & confirm',
                    blurb: 'Required reason',
                    icon: Trash2,
                },
            ];
    }
}

function hoursBetween(a: string, b: string, breakMin: number): number | null {
    if (!a || !b) return null;
    const start = new Date(a).getTime();
    const end = new Date(b).getTime();
    if (Number.isNaN(start) || Number.isNaN(end) || end <= start) return null;
    const mins = (end - start) / 60000 - breakMin;
    return Math.max(0, Math.round((mins / 60) * 100) / 100);
}

/** NZ break rule: worked ≥4h → 30m, ≥2h → 10m. */
function requiredBreak(a: string, b: string): number {
    if (!a || !b) return 0;
    const worked = (new Date(b).getTime() - new Date(a).getTime()) / 3600000;
    return worked >= 4 ? 30 : worked >= 2 ? 10 : 0;
}

/** Minutes between two HH:MM strings (wraps past midnight). */
function disturbanceMinutes(start: string, end: string): number {
    if (!start || !end) return 0;
    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);
    if ([sh, sm, eh, em].some(Number.isNaN)) return 0;
    let mins = eh * 60 + em - (sh * 60 + sm);
    if (mins < 0) mins += 24 * 60;
    return mins;
}

/** Drop incomplete rows and stamp each with computed minutes for the server. */
function buildDisturbances(list: Disturbance[]): Disturbance[] {
    return list
        .filter((d) => d.start && d.end)
        .map((d) => ({
            start: d.start,
            end: d.end,
            minutes: disturbanceMinutes(d.start, d.end),
        }));
}

export function TimeEntryDialog({
    mode,
    entry,
    staff,
    sites,
    clients,
    onClose,
}: {
    mode: TimeDialogMode | null;
    entry?: TimeEntry | null;
    staff: NamedOption[];
    sites: NamedOption[];
    clients: NamedOption[];
    onClose: () => void;
}) {
    const open =
        mode != null &&
        !(entry?.is_attendance_backed && (mode === 'edit' || mode === 'void'));
    const activeMode: TimeDialogMode = mode ?? 'add';
    const steps = useMemo(() => stepsFor(activeMode), [activeMode]);
    const wizard = useWizard(steps.length);
    const form = useForm<FormShape>({ ...EMPTY });
    const [submitted, setSubmitted] = useState(false);
    const [savedAnother, setSavedAnother] = useState(false);

    const meta = MODE_META[activeMode];
    const RailIcon = meta.icon;
    const isShiftlessCreate = activeMode === 'add' || activeMode === 'behalf';

    // Seed the form each time the dialog opens for a given mode/entry.
    useEffect(() => {
        if (!open) return;
        wizard.reset();
        setSubmitted(false);
        if (
            (activeMode === 'edit' ||
                activeMode === 'correct' ||
                activeMode === 'void') &&
            entry
        ) {
            form.setData({
                ...EMPTY,
                clock_in: entry.clock_in,
                clock_out: entry.clock_out ?? '',
                break_minutes: String(entry.break_minutes ?? 0),
                pay_type: entry.pay_type ?? 'standard',
                is_sleepover: entry.is_sleepover,
                is_on_call: entry.is_on_call,
                is_public_holiday: entry.is_public_holiday,
                disturbances: entry.sleepover_disturbances ?? [],
                mileage_km:
                    entry.mileage_km != null ? String(entry.mileage_km) : '',
                cost_centre: entry.cost_centre ?? '',
                project_code: entry.project_code ?? '',
                notes: entry.notes ?? '',
            });
        } else {
            form.setData({ ...EMPTY });
        }
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, activeMode, entry?.id]);

    const people: PersonOption[] = useMemo(
        () => staff.map((s) => ({ value: String(s.id), label: s.name })),
        [staff],
    );

    const breakMin = Number(form.data.break_minutes) || 0;
    const totalHours = hoursBetween(
        form.data.clock_in,
        form.data.clock_out,
        breakMin,
    );
    const reqBreak = requiredBreak(form.data.clock_in, form.data.clock_out);
    const breakShort = form.data.clock_out !== '' && breakMin < reqBreak;

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        setSubmitted(false);
        setSavedAnother(false);
        onClose();
    };

    const staffName =
        activeMode === 'edit' ||
        activeMode === 'correct' ||
        activeMode === 'void'
            ? (entry?.user_name ?? '')
            : (staff.find((s) => String(s.id) === form.data.user_id)?.name ??
              '');

    /* ---- per-step validation ---- */
    function validateStep(idx: number): boolean {
        const key = steps[idx].key;
        const errs: Record<string, string> = {};
        if (key === 'staff') {
            // Staff step only owns the person — clock times live on the Times step.
            if (!form.data.user_id) errs.user_id = 'Pick a staff member.';
        }
        if (key === 'times' || key === 'finish') {
            if (!form.data.clock_in) errs.clock_in = 'Set a clock-in time.';
            if (
                (activeMode === 'add' || activeMode === 'correct') &&
                !form.data.clock_out
            )
                errs.clock_out = 'Set a clock-out time.';
            if (form.data.clock_out && totalHours == null)
                errs.clock_out = 'Clock-out must be after clock-in.';
        }
        if (key === 'pay' && isShiftlessCreate && !form.data.client_id) {
            errs.client_id = 'Pick a client.';
        }
        if (key === 'reason') {
            if (activeMode === 'edit' && !form.data.amendment_reason.trim())
                errs.amendment_reason =
                    'A reason is required for the audit trail.';
            if (
                (activeMode === 'behalf' ||
                    activeMode === 'correct' ||
                    activeMode === 'void') &&
                !form.data.reason.trim()
            )
                errs.reason = 'A reason is required.';
        }
        if (Object.keys(errs).length) {
            form.clearErrors();
            Object.entries(errs).forEach(([k, v]) =>
                form.setError(k as keyof FormShape, v),
            );
            return false;
        }
        form.clearErrors();
        return true;
    }

    function next() {
        if (validateStep(wizard.index)) wizard.next();
    }

    function submit(addAnother = false) {
        // last-step guard
        if (!validateStep(wizard.index)) return;

        // Domain rejections (approved-entry void, edit-after-approval, etc.) come
        // back as back()->with('error') — a 200 redirect that fires Inertia's
        // onSuccess. Gate the success pane/toast on the absence of flash.error so
        // we don't show a false "saved" while nothing persisted.
        const onOk = (kind: string) => (page: Page) => {
            const flashError = (page.props as { flash?: { error?: string } })
                .flash?.error;
            if (flashError) {
                toast.error('Could not save', { description: flashError });
                return;
            }
            toast.success(kind);
            if (
                addAnother &&
                (activeMode === 'add' || activeMode === 'behalf')
            ) {
                form.reset();
                form.clearErrors();
                wizard.reset();
                setSavedAnother(true);
            } else {
                setSubmitted(true);
            }
        };
        const common = {
            preserveScroll: true,
            onError: () => {
                // Jump to the step owning the first error.
                if (form.errors.clock_in || form.errors.clock_out)
                    wizard.goTo(
                        steps.findIndex(
                            (s) =>
                                s.key === 'times' ||
                                s.key === 'staff' ||
                                s.key === 'finish',
                        ),
                    );
            },
        };

        if (activeMode === 'add') {
            form.transform((d) => ({
                user_id: d.user_id || undefined,
                clock_in: d.clock_in,
                clock_out: d.clock_out,
                break_minutes: Number(d.break_minutes) || 0,
                pay_type: d.pay_type,
                is_sleepover: d.is_sleepover,
                is_on_call: d.is_on_call,
                is_public_holiday: d.is_public_holiday,
                sleepover_disturbances: d.is_sleepover
                    ? buildDisturbances(d.disturbances)
                    : [],
                mileage_km: d.mileage_km || undefined,
                site_id: d.site_id || undefined,
                client_id: d.client_id || undefined,
                cost_centre: d.cost_centre || undefined,
                project_code: d.project_code || undefined,
                notes: d.notes || undefined,
            }));
            form.post('/hr/time/entries', {
                ...common,
                onSuccess: onOk('Time entry created.'),
            });
        } else if (activeMode === 'behalf') {
            form.transform((d) => ({
                target_user_id: d.user_id,
                clock_in: d.clock_in,
                clock_out: d.clock_out || undefined,
                break_minutes: Number(d.break_minutes) || 0,
                pay_type: d.pay_type,
                is_sleepover: d.is_sleepover,
                is_on_call: d.is_on_call,
                is_public_holiday: d.is_public_holiday,
                sleepover_disturbances: d.is_sleepover
                    ? buildDisturbances(d.disturbances)
                    : [],
                mileage_km: d.mileage_km || undefined,
                site_id: d.site_id || undefined,
                client_id: d.client_id || undefined,
                reason: d.reason,
                notes: d.notes || undefined,
            }));
            form.post('/hr/time/clock-on-behalf', {
                ...common,
                onSuccess: onOk('Entry created on behalf.'),
            });
        } else if (activeMode === 'edit' && entry) {
            form.transform((d) => ({
                clock_in: d.clock_in,
                clock_out: d.clock_out || undefined,
                break_minutes: Number(d.break_minutes) || 0,
                pay_type: d.pay_type,
                is_sleepover: d.is_sleepover,
                is_on_call: d.is_on_call,
                is_public_holiday: d.is_public_holiday,
                sleepover_disturbances: d.is_sleepover
                    ? buildDisturbances(d.disturbances)
                    : [],
                mileage_km: d.mileage_km || undefined,
                cost_centre: d.cost_centre || undefined,
                project_code: d.project_code || undefined,
                notes: d.notes || undefined,
                amendment_reason: d.amendment_reason,
            }));
            form.put(`/hr/time/entries/${entry.id}`, {
                ...common,
                onSuccess: onOk('Time entry updated.'),
            });
        } else if (activeMode === 'correct' && entry) {
            form.transform((d) => ({
                clock_out: d.clock_out,
                break_minutes: Number(d.break_minutes) || 0,
                reason: d.reason,
            }));
            form.post(`/hr/time/entries/${entry.id}/correct`, {
                ...common,
                onSuccess: onOk('Clock-out corrected.'),
            });
        } else if (activeMode === 'void' && entry) {
            form.transform((d) => ({ reason: d.reason }));
            form.post(`/hr/time/entries/${entry.id}/void`, {
                ...common,
                onSuccess: onOk('Time entry voided.'),
            });
        }
    }

    const stepKey = steps[wizard.index]?.key;

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={meta.title}
            description={`${meta.title} workflow`}
            railIcon={RailIcon}
            railTitle={meta.railTitle}
            railSub={meta.railSub}
            steps={steps}
            stepIndex={wizard.index}
            onStepClick={(i) => i <= wizard.index && wizard.goTo(i)}
            pct={null}
            success={
                submitted ? (
                    <WizardSuccessPane
                        title="Done"
                        blurb={
                            activeMode === 'void'
                                ? 'The entry has been voided and recorded in the audit trail.'
                                : 'The entry is saved and the audit trail is up to date.'
                        }
                        actions={
                            <button
                                type="button"
                                onClick={close}
                                className="rounded-[10px] border border-border bg-card px-[18px] py-2.5 text-sm font-semibold hover:bg-muted"
                            >
                                Close
                            </button>
                        }
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? (
                    savedAnother ? (
                        <span className="inline-flex items-center gap-1.5 text-[12.5px] font-semibold text-status-success">
                            <Check className="h-4 w-4" /> Saved — add another
                        </span>
                    ) : null
                ) : (
                    <button
                        type="button"
                        onClick={wizard.back}
                        className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                    >
                        Back
                    </button>
                )
            }
            footerEnd={
                <>
                    <button
                        type="button"
                        onClick={close}
                        className="rounded-md px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted"
                    >
                        Cancel
                    </button>
                    {wizard.isLast ? (
                        <>
                            {activeMode === 'add' || activeMode === 'behalf' ? (
                                <button
                                    type="button"
                                    onClick={() => submit(true)}
                                    disabled={form.processing}
                                    className="rounded-[10px] border border-border bg-card px-3.5 py-2.5 text-sm font-semibold hover:bg-muted disabled:opacity-50"
                                >
                                    Save &amp; add another
                                </button>
                            ) : null}
                            <button
                                type="button"
                                onClick={() => submit(false)}
                                disabled={form.processing}
                                className={cn(
                                    'inline-flex items-center gap-2 rounded-[10px] px-[18px] py-2.5 text-sm font-semibold text-primary-foreground hover:brightness-95 disabled:opacity-50',
                                    activeMode === 'void'
                                        ? 'bg-status-critical'
                                        : 'bg-primary',
                                )}
                            >
                                {form.processing
                                    ? 'Saving…'
                                    : activeMode === 'void'
                                      ? 'Void entry'
                                      : activeMode === 'correct'
                                        ? 'Save correction'
                                        : 'Save entry'}
                                {!form.processing && activeMode !== 'void' ? (
                                    <ArrowRight className="h-[15px] w-[15px]" />
                                ) : null}
                            </button>
                        </>
                    ) : (
                        <button
                            type="button"
                            onClick={next}
                            className="inline-flex items-center gap-2 rounded-[10px] bg-primary px-[18px] py-2.5 text-sm font-semibold text-primary-foreground hover:brightness-95"
                        >
                            Continue
                            <ArrowRight className="h-[15px] w-[15px]" />
                        </button>
                    )}
                </>
            }
        >
            {/* ---- Staff & date ---- */}
            {stepKey === 'staff' ? (
                <WizardStepPane>
                    <StepHead
                        icon={UserPlus}
                        title={
                            activeMode === 'behalf'
                                ? "Who's this for?"
                                : 'Who is this for?'
                        }
                        blurb={
                            activeMode === 'behalf'
                                ? 'Pick the team member you are clocking for — you’ll set the times next.'
                                : 'Pick the staff member — you’ll set the clock times next.'
                        }
                    />
                    <div className="max-w-[560px] space-y-4">
                        <Field
                            label="Staff member"
                            required
                            error={form.errors.user_id}
                        >
                            <PeoplePicker
                                value={form.data.user_id}
                                onChange={(v) => form.setData('user_id', v)}
                                people={people}
                                placeholder="Select a staff member…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {/* ---- Times & break / Finish time ---- */}
            {stepKey === 'times' || stepKey === 'finish' ? (
                <WizardStepPane>
                    <StepHead
                        icon={Clock}
                        title={
                            stepKey === 'finish'
                                ? 'Finish the shift'
                                : 'Times & break'
                        }
                        blurb={
                            stepKey === 'finish'
                                ? 'Set when the staff member actually finished and the break taken.'
                                : 'Confirm the clock times, break and any mileage.'
                        }
                    />
                    <div className="max-w-[560px] space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Clock in"
                                required
                                error={form.errors.clock_in}
                            >
                                <DateTimeInput
                                    value={form.data.clock_in}
                                    onChange={(v) =>
                                        form.setData('clock_in', v)
                                    }
                                    disabled={stepKey === 'finish'}
                                />
                            </Field>
                            <Field
                                label="Clock out"
                                required={activeMode !== 'behalf'}
                                hint={
                                    activeMode === 'behalf'
                                        ? '— optional'
                                        : undefined
                                }
                                error={form.errors.clock_out}
                            >
                                <DateTimeInput
                                    value={form.data.clock_out}
                                    onChange={(v) =>
                                        form.setData('clock_out', v)
                                    }
                                />
                            </Field>
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Break (minutes)">
                                <input
                                    type="number"
                                    min={0}
                                    max={240}
                                    value={form.data.break_minutes}
                                    onChange={(e) =>
                                        form.setData(
                                            'break_minutes',
                                            e.target.value,
                                        )
                                    }
                                    className="h-10 w-full rounded-lg border border-border bg-card px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                />
                            </Field>
                            <Field label="Mileage (km)" hint="— optional">
                                <input
                                    type="number"
                                    min={0}
                                    step="0.1"
                                    value={form.data.mileage_km}
                                    onChange={(e) =>
                                        form.setData(
                                            'mileage_km',
                                            e.target.value,
                                        )
                                    }
                                    className="h-10 w-full rounded-lg border border-border bg-card px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                />
                            </Field>
                        </div>
                        {totalHours != null ? (
                            <div className="flex items-center gap-3 rounded-xl border border-primary/25 bg-primary/5 px-4 py-3">
                                <div className="text-center">
                                    <div className="text-[22px] leading-none font-bold text-primary">
                                        {totalHours}
                                    </div>
                                    <div className="text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">
                                        Hours
                                    </div>
                                </div>
                                <div className="h-9 w-px bg-border" />
                                <div className="text-[12.5px]">
                                    {breakShort ? (
                                        <span className="inline-flex items-center gap-1.5 font-semibold text-status-warning">
                                            <AlertTriangle className="h-3.5 w-3.5" />
                                            NZ rule: this shift needs at least{' '}
                                            {reqBreak}m break.
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            {breakMin}m break logged · meets the
                                            NZ break rule.
                                        </span>
                                    )}
                                </div>
                            </div>
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : null}

            {/* ---- Pay & context ---- */}
            {stepKey === 'pay' ? (
                <WizardStepPane>
                    <StepHead
                        icon={Moon}
                        title="Pay & context"
                        blurb="Set the pay type, loadings and where this time was worked."
                    />
                    <div className="max-w-[620px] space-y-5">
                        <Field label="Pay type">
                            <SelectInput
                                value={form.data.pay_type}
                                onChange={(v) => form.setData('pay_type', v)}
                                placeholder="Ordinary"
                                options={PAY_TYPE_OPTIONS}
                            />
                        </Field>

                        <div>
                            <div className="mb-2 text-[13px] font-semibold">
                                Loadings
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <ToggleChip
                                    label="Sleepover"
                                    active={form.data.is_sleepover}
                                    onClick={() =>
                                        form.setData(
                                            'is_sleepover',
                                            !form.data.is_sleepover,
                                        )
                                    }
                                />
                                <ToggleChip
                                    label="On-call"
                                    active={form.data.is_on_call}
                                    onClick={() =>
                                        form.setData(
                                            'is_on_call',
                                            !form.data.is_on_call,
                                        )
                                    }
                                />
                                <ToggleChip
                                    label="Public holiday"
                                    active={form.data.is_public_holiday}
                                    onClick={() =>
                                        form.setData(
                                            'is_public_holiday',
                                            !form.data.is_public_holiday,
                                        )
                                    }
                                />
                            </div>
                        </div>

                        {form.data.is_sleepover ? (
                            <div className="rounded-xl border border-border bg-muted/20 p-3.5">
                                <div className="mb-2 flex items-center justify-between">
                                    <div className="text-[13px] font-semibold">
                                        Sleepover disturbance log
                                    </div>
                                    <span className="text-[11.5px] text-muted-foreground">
                                        {
                                            form.data.disturbances.filter(
                                                (d) => d.start && d.end,
                                            ).length
                                        }{' '}
                                        {form.data.disturbances.filter(
                                            (d) => d.start && d.end,
                                        ).length === 1
                                            ? 'disturbance'
                                            : 'disturbances'}{' '}
                                        ·{' '}
                                        {form.data.disturbances.reduce(
                                            (sum, d) =>
                                                sum +
                                                disturbanceMinutes(
                                                    d.start,
                                                    d.end,
                                                ),
                                            0,
                                        )}{' '}
                                        min paid as active time
                                    </span>
                                </div>
                                <div className="flex flex-col gap-2">
                                    {form.data.disturbances.map((d, i) => (
                                        <div
                                            key={i}
                                            className="flex items-center gap-2"
                                        >
                                            <input
                                                type="time"
                                                aria-label={`Wake-up ${i + 1} start`}
                                                value={d.start}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'disturbances',
                                                        form.data.disturbances.map(
                                                            (x, idx) =>
                                                                idx === i
                                                                    ? {
                                                                          ...x,
                                                                          start: e
                                                                              .target
                                                                              .value,
                                                                      }
                                                                    : x,
                                                        ),
                                                    )
                                                }
                                                className="h-9 rounded-lg border border-border bg-card px-2.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            />
                                            <span className="text-muted-foreground">
                                                →
                                            </span>
                                            <input
                                                type="time"
                                                aria-label={`Wake-up ${i + 1} end`}
                                                value={d.end}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'disturbances',
                                                        form.data.disturbances.map(
                                                            (x, idx) =>
                                                                idx === i
                                                                    ? {
                                                                          ...x,
                                                                          end: e
                                                                              .target
                                                                              .value,
                                                                      }
                                                                    : x,
                                                        ),
                                                    )
                                                }
                                                className="h-9 rounded-lg border border-border bg-card px-2.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            />
                                            <span className="w-12 text-[12px] font-semibold text-muted-foreground tabular-nums">
                                                {disturbanceMinutes(
                                                    d.start,
                                                    d.end,
                                                )}
                                                m
                                            </span>
                                            <button
                                                type="button"
                                                aria-label={`Remove wake-up ${i + 1}`}
                                                onClick={() =>
                                                    form.setData(
                                                        'disturbances',
                                                        form.data.disturbances.filter(
                                                            (_, idx) =>
                                                                idx !== i,
                                                        ),
                                                    )
                                                }
                                                className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                                            >
                                                <X className="h-4 w-4" />
                                            </button>
                                        </div>
                                    ))}
                                    <button
                                        type="button"
                                        onClick={() =>
                                            form.setData('disturbances', [
                                                ...form.data.disturbances,
                                                { start: '', end: '' },
                                            ])
                                        }
                                        className="inline-flex w-fit items-center gap-1.5 rounded-lg border border-dashed border-border px-3 py-1.5 text-[12.5px] font-semibold text-muted-foreground hover:border-primary/50 hover:text-foreground"
                                    >
                                        <Plus className="h-3.5 w-3.5" /> Add
                                        wake-up
                                    </button>
                                </div>
                            </div>
                        ) : null}

                        {/* Site/Client are only persisted on create paths — the
                            edit/amend route doesn't accept them, so hide them in
                            edit mode rather than show controls that silently no-op. */}
                        {activeMode !== 'edit' ? (
                            <div className="grid gap-4 sm:grid-cols-2">
                                {sites.length > 0 ? (
                                    <Field label="Site" hint="— optional">
                                        <SelectInput
                                            value={form.data.site_id}
                                            onChange={(v) =>
                                                form.setData('site_id', v)
                                            }
                                            placeholder="No site"
                                            options={sites.map((s) => ({
                                                value: String(s.id),
                                                label: s.name,
                                            }))}
                                        />
                                    </Field>
                                ) : null}
                                <Field
                                    label="Client"
                                    required={isShiftlessCreate}
                                    error={form.errors.client_id}
                                >
                                    <SelectInput
                                        value={form.data.client_id}
                                        onChange={(v) =>
                                            form.setData('client_id', v)
                                        }
                                        placeholder={
                                            clients.length > 0
                                                ? 'Select a client…'
                                                : 'No clients available'
                                        }
                                        options={clients.map((c) => ({
                                            value: String(c.id),
                                            label: c.name,
                                        }))}
                                    />
                                </Field>
                            </div>
                        ) : null}

                        {isShiftlessCreate && clients.length === 0 ? (
                            <div
                                role="alert"
                                className="flex items-start gap-2.5 rounded-xl border border-status-warning/30 bg-status-warning-bg px-4 py-3 text-[12.5px] text-status-warning"
                            >
                                <AlertTriangle className="mt-0.5 h-4 w-4 flex-none" />
                                <span>
                                    No clients are available at your approved
                                    Sites. A client is required because this
                                    entry is not linked to a rostered shift.
                                </span>
                            </div>
                        ) : null}

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Cost centre" hint="— optional">
                                <input
                                    type="text"
                                    value={form.data.cost_centre}
                                    onChange={(e) =>
                                        form.setData(
                                            'cost_centre',
                                            e.target.value,
                                        )
                                    }
                                    className="h-10 w-full rounded-lg border border-border bg-card px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                />
                            </Field>
                            <Field label="Project code" hint="— optional">
                                <input
                                    type="text"
                                    value={form.data.project_code}
                                    onChange={(e) =>
                                        form.setData(
                                            'project_code',
                                            e.target.value,
                                        )
                                    }
                                    className="h-10 w-full rounded-lg border border-border bg-card px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                />
                            </Field>
                        </div>
                    </div>
                </WizardStepPane>
            ) : null}

            {/* ---- Review / Reason ---- */}
            {stepKey === 'review' || stepKey === 'reason' ? (
                <WizardStepPane>
                    <StepHead
                        icon={activeMode === 'void' ? Trash2 : ClipboardCheck}
                        title={
                            activeMode === 'void'
                                ? 'Void this entry?'
                                : activeMode === 'edit'
                                  ? 'Reason & diff'
                                  : 'Review & confirm'
                        }
                        blurb={
                            activeMode === 'void'
                                ? 'This soft-deletes the entry and records the reason in the audit trail.'
                                : 'Check the summary and add the required note.'
                        }
                    />
                    <div className="max-w-[560px] space-y-4">
                        {activeMode !== 'void' ? (
                            <div className="overflow-hidden rounded-2xl border border-border bg-card">
                                <div className="border-b border-border bg-muted/40 px-4 py-3">
                                    <div className="text-[14px] font-bold">
                                        {staffName || '—'}
                                    </div>
                                    <div className="text-[12.5px] text-muted-foreground">
                                        {form.data.clock_in.replace('T', ' ')}
                                        {form.data.clock_out
                                            ? ` → ${form.data.clock_out.replace('T', ' ')}`
                                            : ' → still on shift'}
                                    </div>
                                </div>
                                <div className="grid grid-cols-2 gap-x-4 gap-y-2 px-4 py-3 text-[12.5px]">
                                    <SummaryRow
                                        label="Hours"
                                        value={
                                            totalHours != null
                                                ? `${totalHours}h`
                                                : '—'
                                        }
                                    />
                                    <SummaryRow
                                        label="Break"
                                        value={`${breakMin}m`}
                                    />
                                    <SummaryRow
                                        label="Pay type"
                                        value={payTypeLabel(form.data.pay_type)}
                                    />
                                    <SummaryRow
                                        label="Loadings"
                                        value={
                                            [
                                                form.data.is_sleepover &&
                                                    'Sleepover',
                                                form.data.is_on_call &&
                                                    'On-call',
                                                form.data.is_public_holiday &&
                                                    'PH',
                                            ]
                                                .filter(Boolean)
                                                .join(', ') || 'None'
                                        }
                                    />
                                    {form.data.mileage_km ? (
                                        <SummaryRow
                                            label="Mileage"
                                            value={`${form.data.mileage_km} km`}
                                        />
                                    ) : null}
                                </div>
                            </div>
                        ) : (
                            <div className="flex items-start gap-2.5 rounded-xl border border-status-critical/30 bg-status-critical-bg px-4 py-3 text-[12.5px] text-status-critical">
                                <AlertTriangle className="mt-0.5 h-4 w-4 flex-none" />
                                <div>
                                    Voiding{' '}
                                    {staffName ? (
                                        <strong>{staffName}</strong>
                                    ) : (
                                        'this'
                                    )}
                                    &apos;s entry for {entry?.entry_date}{' '}
                                    removes it from the register. Approved
                                    entries cannot be voided.
                                </div>
                            </div>
                        )}

                        {activeMode === 'edit' && entry ? (
                            <EditDiff entry={entry} data={form.data} />
                        ) : null}

                        {activeMode === 'edit' ? (
                            <Field
                                label="Amendment reason"
                                required
                                error={form.errors.amendment_reason}
                            >
                                <Textarea
                                    rows={3}
                                    value={form.data.amendment_reason}
                                    onChange={(e) =>
                                        form.setData(
                                            'amendment_reason',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="Why is this entry being amended?"
                                />
                            </Field>
                        ) : (
                            <Field
                                label={
                                    activeMode === 'void'
                                        ? 'Reason for voiding'
                                        : 'Reason'
                                }
                                required={
                                    activeMode === 'behalf' ||
                                    activeMode === 'correct' ||
                                    activeMode === 'void'
                                }
                                error={form.errors.reason}
                            >
                                <Textarea
                                    rows={3}
                                    maxLength={
                                        activeMode === 'void' ? 255 : undefined
                                    }
                                    value={form.data.reason}
                                    onChange={(e) =>
                                        form.setData('reason', e.target.value)
                                    }
                                    placeholder={
                                        activeMode === 'behalf'
                                            ? 'e.g. Staff forgot to clock in during an emergency handover'
                                            : activeMode === 'correct'
                                              ? 'e.g. Confirmed finish time with the on-call supervisor'
                                              : 'Why is this entry being voided?'
                                    }
                                />
                            </Field>
                        )}

                        {activeMode !== 'void' && activeMode !== 'correct' ? (
                            <Field
                                label="Notes"
                                hint="— optional, team-visible"
                            >
                                <Textarea
                                    rows={2}
                                    value={form.data.notes}
                                    onChange={(e) =>
                                        form.setData('notes', e.target.value)
                                    }
                                    placeholder="Anything useful for the record…"
                                />
                            </Field>
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

function DateTimeInput({
    value,
    onChange,
    disabled,
}: {
    value: string;
    onChange: (v: string) => void;
    disabled?: boolean;
}) {
    return (
        <input
            type="datetime-local"
            value={value}
            disabled={disabled}
            onChange={(e) => onChange(e.target.value)}
            className="h-10 w-full rounded-lg border border-border bg-card px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-60"
        />
    );
}

function ToggleChip({
    label,
    active,
    onClick,
}: {
    label: string;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-[12.5px] font-semibold transition-colors',
                active
                    ? 'border-primary bg-primary/10 text-primary'
                    : 'border-border bg-card text-muted-foreground hover:border-primary/50',
            )}
        >
            {active ? <Check className="h-3 w-3" /> : null}
            {label}
        </button>
    );
}

/** Field-level old→new diff for the edit/amend review step. */
function EditDiff({ entry, data }: { entry: TimeEntry; data: FormShape }) {
    const boolLabel = (b: boolean) => (b ? 'Yes' : 'No');
    const rows: { label: string; from: string; to: string }[] = [];
    const push = (label: string, from: string, to: string) => {
        if (from !== to) rows.push({ label, from: from || '—', to: to || '—' });
    };
    push(
        'Clock in',
        entry.clock_in.replace('T', ' '),
        data.clock_in.replace('T', ' '),
    );
    push(
        'Clock out',
        (entry.clock_out ?? '').replace('T', ' '),
        data.clock_out.replace('T', ' '),
    );
    push('Break (min)', String(entry.break_minutes ?? 0), data.break_minutes);
    push('Pay type', payTypeLabel(entry.pay_type), payTypeLabel(data.pay_type));
    push(
        'Sleepover',
        boolLabel(entry.is_sleepover),
        boolLabel(data.is_sleepover),
    );
    push('On-call', boolLabel(entry.is_on_call), boolLabel(data.is_on_call));
    push(
        'Public holiday',
        boolLabel(entry.is_public_holiday),
        boolLabel(data.is_public_holiday),
    );
    push(
        'Mileage (km)',
        entry.mileage_km != null ? String(entry.mileage_km) : '',
        data.mileage_km,
    );
    push('Cost centre', entry.cost_centre ?? '', data.cost_centre);
    push('Project code', entry.project_code ?? '', data.project_code);
    push('Notes', entry.notes ?? '', data.notes);

    if (rows.length === 0) {
        return (
            <div className="rounded-xl border border-border bg-muted/30 px-4 py-3 text-[12.5px] text-muted-foreground">
                No field changes yet — adjust the times, pay or context to
                amend.
            </div>
        );
    }

    return (
        <div className="overflow-hidden rounded-xl border border-border">
            <div className="border-b border-border bg-muted/40 px-4 py-2 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                {rows.length} {rows.length === 1 ? 'change' : 'changes'}
            </div>
            <div className="divide-y divide-border">
                {rows.map((r) => (
                    <div
                        key={r.label}
                        className="flex flex-wrap items-center gap-2 px-4 py-2 text-[12.5px]"
                    >
                        <span className="w-28 shrink-0 text-muted-foreground">
                            {r.label}
                        </span>
                        <span className="rounded-md bg-status-critical-bg px-2 py-0.5 font-semibold text-status-critical line-through">
                            {r.from}
                        </span>
                        <ArrowRight className="h-3.5 w-3.5 text-muted-foreground" />
                        <span className="rounded-md bg-status-success-bg px-2 py-0.5 font-semibold text-status-success">
                            {r.to}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function SummaryRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-3 border-b border-border/60 py-1 last:border-0">
            <span className="text-muted-foreground">{label}</span>
            <span className="font-semibold">{value}</span>
        </div>
    );
}

export default TimeEntryDialog;
