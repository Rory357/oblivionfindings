/* eslint-disable no-restricted-syntax -- Wizard footer + part-day control use native
 * buttons/inputs to match the Add-Client modal chrome (see components/wizard/shell.tsx). */
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarRange,
    ClipboardCheck,
    Clock,
    FileText,
    UserCheck,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { fireConfetti } from '@/lib/confetti';
import { cn } from '@/lib/utils';

import { PeoplePicker, type PersonOption } from './people-picker';
import {
    Field,
    ReviewCard,
    ReviewRow,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from './wizard';

export interface LeaveStaff {
    id: number;
    name: string;
    email: string;
}

export interface LeaveTypeOption {
    value: string;
    label: string;
}

type LeavePreview = {
    hours: number;
    period: string;
    available_before: number;
    projected_remaining: number;
    insufficient: boolean;
    has_roster_conflict: boolean;
    approver: string | null;
    approval_due_at: string | null;
};

const STEPS: readonly WizardStep[] = [
    { key: 'type', label: 'Type & dates', blurb: 'What & when', icon: CalendarRange },
    { key: 'reason', label: 'Reason', blurb: 'Context & docs', icon: FileText },
    { key: 'review', label: 'Review', blurb: 'Confirm & submit', icon: ClipboardCheck },
];

const PERIOD_OPTIONS: LeaveTypeOption[] = [
    { value: 'full_day', label: 'Full day' },
    { value: 'half_day_am', label: 'Half day — morning' },
    { value: 'half_day_pm', label: 'Half day — afternoon' },
];

/**
 * Single shared leave-request modal (handover §5). `mode="manager"` (default) picks a
 * recipient and posts to hr.leave.store; `mode="self"` locks the recipient to the current
 * user, posts to hr.my.leave.store and fires confetti. The review step pulls a server
 * preview (engine hours — PH-aware + part-day — balance impact, roster conflict, approver).
 */
export function LeaveRequestDialog({
    open,
    onClose,
    staff,
    leaveTypes,
    mode = 'manager',
    currentUser,
    initial,
    onSubmitted,
}: {
    open: boolean;
    onClose: () => void;
    staff: LeaveStaff[];
    leaveTypes: LeaveTypeOption[];
    mode?: 'self' | 'manager';
    currentUser?: { name: string };
    initial?: { leave_type?: string; starts_at?: string; ends_at?: string };
    onSubmitted?: () => void;
}) {
    const isSelf = mode === 'self';
    const postUrl = isSelf ? '/hr/my/leave' : '/hr/leave';
    const previewUrl = isSelf ? '/hr/my/leave/preview' : '/hr/leave/preview';

    const wizard = useWizard(STEPS.length);
    const form = useForm<{
        user_id: string;
        leave_type: string;
        period: string;
        starts_at: string;
        ends_at: string;
        hours_requested: string;
        reason: string;
        supporting_doc: File | null;
    }>({
        user_id: '',
        leave_type: '',
        period: 'full_day',
        starts_at: '',
        ends_at: '',
        hours_requested: '',
        reason: '',
        supporting_doc: null,
    });

    const [preview, setPreview] = useState<LeavePreview | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        setPreview(null);
        onClose();
    };

    const people: PersonOption[] = useMemo(
        () => staff.map((s) => ({ value: String(s.id), label: s.name, sub: s.email })),
        [staff],
    );

    // Seed from `initial` (the "Duplicate" action) each time the dialog opens.
    useEffect(() => {
        if (open && initial) {
            if (initial.leave_type) form.setData('leave_type', initial.leave_type);
            if (initial.starts_at) form.setData('starts_at', initial.starts_at);
            if (initial.ends_at) form.setData('ends_at', initial.ends_at);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const singleDay =
        form.data.starts_at !== '' && form.data.starts_at === form.data.ends_at;

    const staffName = isSelf
        ? (currentUser?.name ?? 'You')
        : (staff.find((s) => String(s.id) === form.data.user_id)?.name ?? '—');
    const typeLabel =
        leaveTypes.find((t) => t.value === form.data.leave_type)?.label ?? '—';

    const canSubmit =
        (isSelf || form.data.user_id !== '') &&
        form.data.leave_type !== '' &&
        form.data.starts_at !== '' &&
        form.data.ends_at !== '';

    // Fetch the server preview when the review step opens.
    useEffect(() => {
        if (!open || wizard.index !== 2 || !canSubmit) return;
        const params = new URLSearchParams({
            leave_type: form.data.leave_type,
            period: singleDay ? form.data.period : 'full_day',
            starts_at: form.data.starts_at,
            ends_at: form.data.ends_at,
        });
        if (!isSelf && form.data.user_id) params.set('user_id', form.data.user_id);

        let cancelled = false;
        setPreviewLoading(true);
        fetch(`${previewUrl}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((data) => {
                if (!cancelled) setPreview(data as LeavePreview | null);
            })
            .catch(() => {
                if (!cancelled) setPreview(null);
            })
            .finally(() => {
                if (!cancelled) setPreviewLoading(false);
            });
        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, wizard.index]);

    const submit = () => {
        form.transform((data) => ({
            ...data,
            period: singleDay ? data.period : 'full_day',
        }));
        form.post(postUrl, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const flash = (page.props as { flash?: { error?: string } }).flash;
                if (flash?.error) {
                    toast.error('Could not submit leave', { description: flash.error });
                    return;
                }
                if (isSelf) {
                    toast.success('Leave request sent 🌴', {
                        description: `${typeLabel} submitted for approval.`,
                    });
                    fireConfetti();
                }
                onSubmitted?.();
                close();
            },
            onError: () => {
                if (
                    form.errors.user_id ||
                    form.errors.leave_type ||
                    form.errors.period ||
                    form.errors.starts_at ||
                    form.errors.ends_at ||
                    form.errors.hours_requested
                ) {
                    wizard.goTo(0);
                }
            },
        });
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isSelf ? 'Request leave' : 'New leave request'}
            description={
                isSelf
                    ? 'Submit a leave request to your manager.'
                    : 'Submit a leave request for approval.'
            }
            railIcon={CalendarRange}
            railTitle={isSelf ? 'Request leave' : 'Leave request'}
            railSub="HR"
            steps={STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            footerStart={
                wizard.isFirst ? null : (
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
                        <button
                            type="button"
                            onClick={submit}
                            disabled={!canSubmit || form.processing}
                            className={cn(
                                'rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground transition-opacity',
                                (!canSubmit || form.processing) &&
                                    'cursor-not-allowed opacity-50',
                            )}
                        >
                            {form.processing ? 'Submitting…' : 'Submit request'}
                        </button>
                    ) : (
                        <button
                            type="button"
                            onClick={wizard.next}
                            className="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground"
                        >
                            Continue
                        </button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={CalendarRange}
                        title="Type & dates"
                        blurb={
                            isSelf
                                ? 'Choose the type of leave and the dates you’ll be away.'
                                : 'Who the leave is for, the type, and the dates.'
                        }
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        {isSelf ? (
                            <Field label="Staff member" span>
                                <div className="flex h-10 items-center rounded-md border border-border bg-muted px-3 text-sm font-medium">
                                    {currentUser?.name ?? 'You'}
                                </div>
                            </Field>
                        ) : (
                            <Field
                                label="Staff member"
                                required
                                span
                                error={form.errors.user_id}
                            >
                                <PeoplePicker
                                    value={form.data.user_id}
                                    onChange={(v) => form.setData('user_id', v)}
                                    people={people}
                                    placeholder="Select a staff member…"
                                />
                            </Field>
                        )}
                        <Field
                            label="Leave type"
                            required
                            span
                            error={form.errors.leave_type}
                        >
                            <SelectInput
                                value={form.data.leave_type}
                                onChange={(v) => form.setData('leave_type', v)}
                                placeholder="Select a leave type"
                                options={leaveTypes}
                            />
                        </Field>
                        <Field label="Start date" required error={form.errors.starts_at}>
                            <Input
                                type="date"
                                value={form.data.starts_at}
                                onChange={(e) =>
                                    form.setData('starts_at', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="End date" required error={form.errors.ends_at}>
                            <Input
                                type="date"
                                value={form.data.ends_at}
                                onChange={(e) => form.setData('ends_at', e.target.value)}
                            />
                        </Field>
                        {singleDay && (
                            <Field
                                label="Part-day"
                                hint="single-day leave only"
                                error={form.errors.period}
                            >
                                <SelectInput
                                    value={form.data.period}
                                    onChange={(v) => form.setData('period', v)}
                                    placeholder="Full day"
                                    options={PERIOD_OPTIONS}
                                />
                            </Field>
                        )}
                        {!isSelf && (
                            <Field
                                label="Hours requested"
                                hint="optional — auto-calculated if blank"
                                error={form.errors.hours_requested}
                            >
                                <Input
                                    type="number"
                                    min="0.5"
                                    max="999"
                                    step="0.5"
                                    value={form.data.hours_requested}
                                    onChange={(e) =>
                                        form.setData('hours_requested', e.target.value)
                                    }
                                    placeholder="e.g. 8"
                                />
                            </Field>
                        )}
                    </div>
                    <p className="mt-3 inline-flex items-center gap-2 text-xs text-muted-foreground">
                        <Clock className="h-3.5 w-3.5" />
                        Public holidays in range aren’t counted against the balance.
                    </p>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="Reason & documents"
                        blurb="Add context and any supporting document (e.g. a medical certificate)."
                    />
                    <Field label="Reason" hint="optional" error={form.errors.reason}>
                        <Textarea
                            rows={4}
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                            placeholder="Reason for the leave…"
                        />
                    </Field>
                    <Field
                        label="Supporting document"
                        hint="optional · PDF/JPG/PNG/DOC, max 5MB"
                        error={form.errors.supporting_doc}
                    >
                        <input
                            type="file"
                            accept=".pdf,.jpg,.jpeg,.png,.doc,.docx"
                            onChange={(e) =>
                                form.setData(
                                    'supporting_doc',
                                    e.target.files?.[0] ?? null,
                                )
                            }
                            className="block w-full text-sm text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary/10 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-primary"
                        />
                    </Field>
                    {form.data.supporting_doc ? (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Selected: {form.data.supporting_doc.name}
                        </p>
                    ) : null}
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & submit"
                        blurb="The request is submitted for approval."
                    />
                    <ReviewCard
                        icon={CalendarRange}
                        title="Leave request"
                        onEdit={() => wizard.goTo(0)}
                    >
                        <ReviewRow label="Staff" value={staffName} />
                        <ReviewRow label="Type" value={typeLabel} />
                        <ReviewRow label="From" value={form.data.starts_at} />
                        <ReviewRow label="To" value={form.data.ends_at} />
                        {singleDay && form.data.period !== 'full_day' ? (
                            <ReviewRow
                                label="Part-day"
                                value={
                                    PERIOD_OPTIONS.find(
                                        (p) => p.value === form.data.period,
                                    )?.label
                                }
                            />
                        ) : null}
                        <ReviewRow
                            label="Hours"
                            value={
                                previewLoading
                                    ? 'Calculating…'
                                    : preview
                                      ? `${preview.hours}h`
                                      : form.data.hours_requested || 'Auto'
                            }
                        />
                        {preview ? (
                            <ReviewRow
                                label="Balance"
                                value={`${preview.available_before}h → ${preview.projected_remaining}h`}
                            />
                        ) : null}
                        {preview?.approver ? (
                            <ReviewRow label="Approver" value={preview.approver} />
                        ) : null}
                        <ReviewRow label="Reason" value={form.data.reason} />
                        <ReviewRow
                            label="Document"
                            value={form.data.supporting_doc?.name}
                        />
                    </ReviewCard>

                    {preview?.insufficient ? (
                        <div className="mt-3 flex items-center gap-2 rounded-lg border border-destructive/30 bg-destructive/10 px-3 py-2.5 text-xs font-semibold text-destructive">
                            <AlertTriangle className="h-4 w-4" />
                            Not enough balance — this will go negative. A manager can still
                            approve with escalation.
                        </div>
                    ) : null}
                    {preview?.has_roster_conflict ? (
                        <div className="mt-2 flex items-center gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2.5 text-xs font-semibold text-amber-700 dark:text-amber-400">
                            <CalendarRange className="h-4 w-4" />
                            You’re rostered on a shift during these dates.
                        </div>
                    ) : null}
                    {preview?.approval_due_at ? (
                        <p className="mt-2 inline-flex items-center gap-2 text-xs text-muted-foreground">
                            <UserCheck className="h-3.5 w-3.5" />
                            Expected decision by{' '}
                            {new Date(preview.approval_due_at).toLocaleDateString('en-NZ', {
                                day: 'numeric',
                                month: 'short',
                            })}
                            .
                        </p>
                    ) : null}
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default LeaveRequestDialog;
