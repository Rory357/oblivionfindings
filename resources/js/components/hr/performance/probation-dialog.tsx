/* eslint-disable no-restricted-syntax -- Wizard footer uses native buttons to
 * match the Add-Client modal chrome (see components/wizard/shell.tsx). */
import { useForm } from '@inertiajs/react';
import { ClipboardCheck, ShieldCheck } from 'lucide-react';
import { useMemo } from 'react';

import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import { PeoplePicker, type PersonOption } from '../people-picker';
import {
    Field,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '../wizard';

export interface ProbationStaff {
    id: number;
    name: string;
    email?: string;
}

export interface ExistingProbationReview {
    id: number;
    employee?: { id: number; name: string } | null;
    employee_user_id?: number | null;
    review_number: number;
    review_date: string | null;
    status: string;
    recommendation: string | null;
    extension_weeks: number | null;
    concerns: string | null;
    areas_assessed: string[] | null;
    notes: string | null;
}

const STEPS: readonly WizardStep[] = [
    { key: 'review', label: 'Review', blurb: 'Who & when', icon: ShieldCheck },
    { key: 'outcome', label: 'Outcome', blurb: 'Assessment & recommendation', icon: ClipboardCheck },
];

const STATUS_OPTIONS = [
    { value: 'scheduled', label: 'Scheduled' },
    { value: 'completed', label: 'Completed' },
    { value: 'extended', label: 'Extended' },
    { value: 'passed', label: 'Passed' },
    { value: 'failed', label: 'Failed' },
];

const NO_REC = '__none__';
const RECOMMENDATION_OPTIONS = [
    { value: NO_REC, label: 'No recommendation yet' },
    { value: 'pass', label: 'Pass probation' },
    { value: 'extend', label: 'Extend probation' },
    { value: 'fail', label: 'Fail probation' },
];

const toLines = (v: string[] | null | undefined) => (v ?? []).join('\n');
const fromLines = (v: string) =>
    v
        .split('\n')
        .map((s) => s.trim())
        .filter((s) => s !== '');

/**
 * Record / edit a probation review in a WizardShell modal — the reviews hub
 * previously displayed probation rows read-only with no way to add one. Create
 * POSTs hr.performance.probation.store; edit PUTs .update. Employee + review
 * number are fixed in edit (the update endpoint doesn't change them).
 */
export function ProbationDialog({
    open,
    onClose,
    staff,
    review,
}: {
    open: boolean;
    onClose: () => void;
    staff: ProbationStaff[];
    review?: ExistingProbationReview | null;
}) {
    const isEdit = !!review;
    const wizard = useWizard(STEPS.length);
    const form = useForm<{
        employee_user_id: string;
        review_number: string;
        review_date: string;
        status: string;
        recommendation: string;
        extension_weeks: string;
        concerns: string;
        areas_text: string;
        notes: string;
    }>({
        employee_user_id: review?.employee?.id
            ? String(review.employee.id)
            : review?.employee_user_id
              ? String(review.employee_user_id)
              : '',
        review_number: review?.review_number ? String(review.review_number) : '1',
        review_date: review?.review_date ?? '',
        status: review?.status ?? 'scheduled',
        recommendation: review?.recommendation ?? '',
        extension_weeks: review?.extension_weeks ? String(review.extension_weeks) : '',
        concerns: review?.concerns ?? '',
        areas_text: toLines(review?.areas_assessed),
        notes: review?.notes ?? '',
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

    const employeeName =
        review?.employee?.name ??
        staff.find((s) => String(s.id) === form.data.employee_user_id)?.name ??
        '—';

    const canSubmit =
        form.data.employee_user_id !== '' &&
        form.data.review_number !== '' &&
        form.data.review_date !== '' &&
        form.data.status !== '';

    const submit = () => {
        form.transform((data) => ({
            ...data,
            recommendation:
                data.recommendation === '' || data.recommendation === NO_REC
                    ? null
                    : data.recommendation,
            extension_weeks: data.extension_weeks === '' ? null : data.extension_weeks,
            areas_assessed: fromLines(data.areas_text),
        }));

        const opts = {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => {
                if (
                    form.errors.employee_user_id ||
                    form.errors.review_number ||
                    form.errors.review_date ||
                    form.errors.status
                ) {
                    wizard.goTo(0);
                }
            },
        };

        if (isEdit) {
            form.put(`/hr/performance/probation/${review!.id}`, opts);
        } else {
            form.post('/hr/performance/probation', opts);
        }
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit probation review' : 'Record probation review'}
            description="Record a probation review checkpoint and its recommendation."
            railIcon={ShieldCheck}
            railTitle={isEdit ? 'Edit probation' : 'Probation review'}
            railSub="Performance"
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
                            {form.processing
                                ? 'Saving…'
                                : isEdit
                                  ? 'Save changes'
                                  : 'Record review'}
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
                        icon={ShieldCheck}
                        title="Probation review"
                        blurb="The employee, which review in the probation period, and its status."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Staff member"
                            required
                            span
                            error={form.errors.employee_user_id}
                        >
                            {isEdit ? (
                                <Input value={employeeName} disabled />
                            ) : (
                                <PeoplePicker
                                    value={form.data.employee_user_id}
                                    onChange={(v) =>
                                        form.setData('employee_user_id', v)
                                    }
                                    people={people}
                                    placeholder="Select a staff member…"
                                />
                            )}
                        </Field>
                        <Field
                            label="Review number"
                            required
                            error={form.errors.review_number}
                        >
                            <Input
                                type="number"
                                min="1"
                                value={form.data.review_number}
                                onChange={(e) =>
                                    form.setData('review_number', e.target.value)
                                }
                                disabled={isEdit}
                            />
                        </Field>
                        <Field
                            label="Review date"
                            required
                            error={form.errors.review_date}
                        >
                            <Input
                                type="date"
                                value={form.data.review_date}
                                onChange={(e) =>
                                    form.setData('review_date', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Status" required error={form.errors.status}>
                            <SelectInput
                                value={form.data.status}
                                onChange={(v) => form.setData('status', v)}
                                placeholder="Select a status"
                                options={STATUS_OPTIONS}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Assessment & recommendation"
                        blurb="Areas assessed (one per line), any concerns, and the recommendation."
                    />
                    <div className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Recommendation"
                                hint="optional"
                                error={form.errors.recommendation}
                            >
                                <SelectInput
                                    value={form.data.recommendation || NO_REC}
                                    onChange={(v) =>
                                        form.setData(
                                            'recommendation',
                                            v === NO_REC ? '' : v,
                                        )
                                    }
                                    placeholder="No recommendation yet"
                                    options={RECOMMENDATION_OPTIONS}
                                />
                            </Field>
                            <Field
                                label="Extension (weeks)"
                                hint="if extending"
                                error={form.errors.extension_weeks}
                            >
                                <Input
                                    type="number"
                                    min="1"
                                    max="52"
                                    value={form.data.extension_weeks}
                                    onChange={(e) =>
                                        form.setData(
                                            'extension_weeks',
                                            e.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </div>
                        <Field label="Areas assessed" hint="one per line">
                            <Textarea
                                rows={3}
                                value={form.data.areas_text}
                                onChange={(e) =>
                                    form.setData('areas_text', e.target.value)
                                }
                                placeholder={'Punctuality\nMedication competency\nTeamwork'}
                            />
                        </Field>
                        <Field label="Concerns" hint="optional" error={form.errors.concerns}>
                            <Textarea
                                rows={2}
                                value={form.data.concerns}
                                onChange={(e) =>
                                    form.setData('concerns', e.target.value)
                                }
                            />
                        </Field>
                        <Field label="Notes" hint="optional" error={form.errors.notes}>
                            <Textarea
                                rows={2}
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default ProbationDialog;
