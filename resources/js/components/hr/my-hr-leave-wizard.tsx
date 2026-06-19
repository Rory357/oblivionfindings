/* eslint-disable no-restricted-syntax -- The wizard footer + half-day toggle are
 * bespoke controls sized to the MedsWizardDialog shell per the design handoff. */
import { router } from '@inertiajs/react';
import {
    Baby,
    CalendarRange,
    Flower2,
    NotebookPen,
    Palmtree,
    Send,
    Sparkles,
    Thermometer,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import { MedsWizardDialog, SummaryRow } from '@/components/meds/wizard-shell';
import {
    Field,
    InfoCard,
    StepHead,
    TilePicker,
    type IconType,
} from '@/components/wizard/primitives';
import { fireConfetti } from '@/lib/confetti';

export type LeaveBalanceLite = {
    leave_type: string;
    entitlement_hours: number;
    taken_hours: number;
    remaining_hours: number;
};

export type LeaveWizardInitial = {
    leave_type?: string;
    starts_at?: string;
    ends_at?: string;
};

const TYPES: {
    key: string;
    label: string;
    description: string;
    icon: IconType;
}[] = [
    { key: 'annual', label: 'Annual leave', description: 'Paid time off', icon: Palmtree },
    { key: 'sick', label: 'Sick leave', description: 'When you’re unwell', icon: Thermometer },
    { key: 'bereavement', label: 'Bereavement', description: 'Tangihanga & loss', icon: Flower2 },
    { key: 'parental', label: 'Parental', description: 'New addition to whānau', icon: Baby },
];

const TYPE_LABEL: Record<string, string> = Object.fromEntries(
    TYPES.map((t) => [t.key, t.label]),
);

const STEPS = [
    { key: 'type', label: 'Leave type', blurb: 'What kind of leave', icon: Palmtree },
    { key: 'dates', label: 'Dates', blurb: 'When you’re away', icon: CalendarRange },
    { key: 'notes', label: 'Notes', blurb: 'Anything to add', icon: NotebookPen },
    { key: 'review', label: 'Review', blurb: 'Check & submit', icon: Send },
];

function todayStr(): string {
    return new Date().toISOString().slice(0, 10);
}

function countWorkingDays(start: string, end: string, half: boolean): number {
    if (!start || !end) return 0;
    const s = new Date(`${start}T00:00:00`);
    const e = new Date(`${end}T00:00:00`);
    if (e < s) return 0;
    let days = 0;
    const d = new Date(s);
    while (d <= e) {
        const dow = d.getDay();
        if (dow !== 0 && dow !== 6) days += 1;
        d.setDate(d.getDate() + 1);
    }
    return half ? Math.max(0.5, days - 0.5) : days;
}

export function MyHrLeaveWizard({
    open,
    onClose,
    balances,
    initial,
}: {
    open: boolean;
    onClose: () => void;
    balances: LeaveBalanceLite[];
    initial?: LeaveWizardInitial;
}) {
    const [step, setStep] = useState(0);
    const [leaveType, setLeaveType] = useState('');
    const [start, setStart] = useState('');
    const [end, setEnd] = useState('');
    const [half, setHalf] = useState(false);
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);

    // Seed from `initial` each time the wizard opens (supports "Duplicate").
    useEffect(() => {
        if (!open) return;
        setStep(0);
        setLeaveType(initial?.leave_type ?? '');
        setStart(initial?.starts_at ?? '');
        setEnd(initial?.ends_at ?? '');
        setHalf(false);
        setReason('');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    function close() {
        onClose();
    }

    const days = countWorkingDays(start, end, half);
    const hours = days * 8;
    const balance = balances.find((b) => b.leave_type === leaveType);
    const remaining = balance?.remaining_hours ?? null;

    const canContinue =
        step === 0
            ? leaveType !== ''
            : step === 1
              ? !!start && !!end && new Date(end) >= new Date(start)
              : true;

    function submit() {
        setProcessing(true);
        router.post(
            '/hr/my/leave',
            {
                leave_type: leaveType,
                starts_at: start,
                ends_at: end,
                hours_requested: hours > 0 ? hours : undefined,
                reason: reason || undefined,
            },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const flash = (
                        page.props as { flash?: { error?: string } }
                    ).flash;
                    if (flash?.error) {
                        toast.error('Could not submit leave', {
                            description: flash.error,
                        });
                        return;
                    }
                    toast.success('Leave request sent 🌴', {
                        description: `${TYPE_LABEL[leaveType] ?? 'Leave'} submitted to your manager for approval.`,
                    });
                    fireConfetti();
                    close();
                },
                onError: () =>
                    toast.error('Please check the dates and try again'),
                onFinish: () => setProcessing(false),
            },
        );
    }

    function next() {
        if (step < STEPS.length - 1) {
            setStep((s) => s + 1);
            return;
        }
        submit();
    }

    return (
        <MedsWizardDialog
            open={open}
            onClose={close}
            title="Request leave"
            description="Submit a leave request"
            railIcon={CalendarRange}
            railTitle="Request leave"
            railSubtitle="Time off"
            railFooter={
                <p className="text-[11px] leading-relaxed text-muted-foreground">
                    Submitted straight to your manager for approval. You’ll get a
                    notification when it’s actioned.
                </p>
            }
            steps={STEPS}
            stepIndex={step}
            onStepClick={(i) => i <= step && setStep(i)}
            footer={
                <>
                    <button
                        type="button"
                        onClick={() => setStep((s) => Math.max(0, s - 1))}
                        disabled={step === 0}
                        className="rounded-[10px] border border-border bg-card px-4 py-2 text-[13px] font-semibold disabled:opacity-40"
                    >
                        Back
                    </button>
                    <div className="flex gap-2.5">
                        <button
                            type="button"
                            onClick={close}
                            className="rounded-[10px] border border-border bg-card px-4 py-2 text-[13px] font-semibold"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={next}
                            disabled={!canContinue || processing}
                            className="inline-flex items-center gap-1.5 rounded-[10px] bg-primary px-4 py-2 text-[13px] font-bold text-primary-foreground disabled:opacity-50"
                        >
                            {step === STEPS.length - 1 ? (
                                <>
                                    <Send className="h-3.5 w-3.5" /> Submit request
                                </>
                            ) : (
                                'Continue →'
                            )}
                        </button>
                    </div>
                </>
            }
        >
            {step === 0 ? (
                <div>
                    <StepHead
                        icon={CalendarRange}
                        title="What type of leave?"
                        blurb="Pick the category that fits."
                    />
                    <TilePicker
                        value={leaveType}
                        onChange={setLeaveType}
                        options={TYPES}
                    />
                </div>
            ) : null}

            {step === 1 ? (
                <div>
                    <StepHead
                        icon={CalendarRange}
                        title="When are you away?"
                        blurb="Choose the start and end dates."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Start date" required>
                            <input
                                type="date"
                                value={start}
                                min={todayStr()}
                                onChange={(e) => setStart(e.target.value)}
                                className="w-full rounded-[10px] border border-border bg-card px-3 py-2.5 text-sm outline-none focus:border-primary"
                            />
                        </Field>
                        <Field label="End date" required>
                            <input
                                type="date"
                                value={end}
                                min={start || todayStr()}
                                onChange={(e) => setEnd(e.target.value)}
                                className="w-full rounded-[10px] border border-border bg-card px-3 py-2.5 text-sm outline-none focus:border-primary"
                            />
                        </Field>
                    </div>
                    <label className="mt-1 flex cursor-pointer items-center gap-2.5 text-[13px]">
                        <input
                            type="checkbox"
                            checked={half}
                            onChange={(e) => setHalf(e.target.checked)}
                            className="h-4 w-4 accent-primary"
                        />
                        Last day is a half day
                    </label>
                    {days > 0 ? (
                        <div className="mt-4 flex items-center gap-3 rounded-xl bg-accent px-4 py-3">
                            <span className="text-2xl font-bold text-primary">
                                {days}
                            </span>
                            <div className="text-[12.5px]">
                                <div className="font-bold">
                                    working day{days === 1 ? '' : 's'} · ~{hours}h
                                </div>
                                {remaining != null ? (
                                    <div className="text-muted-foreground">
                                        Leaves {Math.max(0, remaining - hours).toFixed(0)}h{' '}
                                        {TYPE_LABEL[leaveType]?.toLowerCase()} balance (of{' '}
                                        {remaining.toFixed(0)}h)
                                    </div>
                                ) : null}
                            </div>
                        </div>
                    ) : null}
                </div>
            ) : null}

            {step === 2 ? (
                <div>
                    <StepHead
                        icon={NotebookPen}
                        title="Anything to add?"
                        blurb="Optional — give your manager some context."
                    />
                    <Field label="Note for your manager">
                        <textarea
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            rows={5}
                            placeholder="e.g. Family trip up north — cover arranged with Sione."
                            className="w-full resize-y rounded-[10px] border border-border bg-card px-3 py-3 text-[13.5px] leading-relaxed outline-none focus:border-primary"
                        />
                    </Field>
                    <InfoCard icon={Sparkles}>
                        Your team’s leave calendar is on the Leave page — worth a glance
                        to check cover before you go.
                    </InfoCard>
                </div>
            ) : null}

            {step === 3 ? (
                <div>
                    <StepHead
                        icon={Send}
                        title="Review & submit"
                        blurb="Check the details before sending."
                    />
                    <div className="overflow-hidden rounded-xl border border-border px-4">
                        <SummaryRow
                            label="Type"
                            value={TYPE_LABEL[leaveType] ?? leaveType}
                        />
                        <SummaryRow label="Dates" value={`${start} → ${end}`} />
                        <SummaryRow
                            label="Duration"
                            value={`${days} working day${days === 1 ? '' : 's'}${half ? ' (half last day)' : ''}`}
                        />
                        <SummaryRow label="Hours" value={`${hours}h`} />
                        <SummaryRow label="Note" value={reason || '—'} />
                        <SummaryRow label="Approver" value="Your manager" />
                    </div>
                </div>
            ) : null}
        </MedsWizardDialog>
    );
}

export default MyHrLeaveWizard;
