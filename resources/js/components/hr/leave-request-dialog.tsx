/* eslint-disable no-restricted-syntax -- Wizard footer uses native buttons to
 * match the Add-Client modal chrome (see components/wizard/shell.tsx). */
import { useForm } from '@inertiajs/react';
import { CalendarRange, ClipboardCheck, FileText } from 'lucide-react';
import { useMemo } from 'react';

import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
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

const STEPS: readonly WizardStep[] = [
    { key: 'type', label: 'Type & dates', blurb: 'What & when', icon: CalendarRange },
    { key: 'reason', label: 'Reason', blurb: 'Context & docs', icon: FileText },
    { key: 'review', label: 'Review', blurb: 'Confirm & submit', icon: ClipboardCheck },
];

/**
 * Stepper-modal replacement for the page-based leave-request form
 * (pages/hr/leave/create.tsx). Posts to the existing hr.leave.store endpoint
 * (multipart, for the optional supporting document). Request-on-behalf uses the
 * staff member's user id (HrLeaveRequest.user_id → users.id).
 */
export function LeaveRequestDialog({
    open,
    onClose,
    staff,
    leaveTypes,
}: {
    open: boolean;
    onClose: () => void;
    staff: LeaveStaff[];
    leaveTypes: LeaveTypeOption[];
}) {
    const wizard = useWizard(STEPS.length);
    const form = useForm<{
        user_id: string;
        leave_type: string;
        starts_at: string;
        ends_at: string;
        hours_requested: string;
        reason: string;
        supporting_doc: File | null;
    }>({
        user_id: '',
        leave_type: '',
        starts_at: '',
        ends_at: '',
        hours_requested: '',
        reason: '',
        supporting_doc: null,
    });

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        onClose();
    };

    const people: PersonOption[] = useMemo(
        () =>
            staff.map((s) => ({
                value: String(s.id),
                label: s.name,
                sub: s.email,
            })),
        [staff],
    );

    const staffName =
        staff.find((s) => String(s.id) === form.data.user_id)?.name ?? '—';
    const typeLabel =
        leaveTypes.find((t) => t.value === form.data.leave_type)?.label ?? '—';

    const canSubmit =
        form.data.user_id !== '' &&
        form.data.leave_type !== '' &&
        form.data.starts_at !== '' &&
        form.data.ends_at !== '';

    const submit = () => {
        form.post('/hr/leave', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => {
                if (
                    form.errors.user_id ||
                    form.errors.leave_type ||
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
            title="New leave request"
            description="Submit a leave request for approval."
            railIcon={CalendarRange}
            railTitle="Leave request"
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
                        blurb="Who the leave is for, the type, and the dates."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
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
                        <Field
                            label="Start date"
                            required
                            error={form.errors.starts_at}
                        >
                            <Input
                                type="date"
                                value={form.data.starts_at}
                                onChange={(e) =>
                                    form.setData('starts_at', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="End date"
                            required
                            error={form.errors.ends_at}
                        >
                            <Input
                                type="date"
                                value={form.data.ends_at}
                                onChange={(e) =>
                                    form.setData('ends_at', e.target.value)
                                }
                            />
                        </Field>
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
                                    form.setData(
                                        'hours_requested',
                                        e.target.value,
                                    )
                                }
                                placeholder="e.g. 8"
                            />
                        </Field>
                    </div>
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
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
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
                        <ReviewRow
                            label="Hours"
                            value={form.data.hours_requested || 'Auto'}
                        />
                        <ReviewRow label="Reason" value={form.data.reason} />
                        <ReviewRow
                            label="Document"
                            value={form.data.supporting_doc?.name}
                        />
                    </ReviewCard>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default LeaveRequestDialog;
