/* eslint-disable no-restricted-syntax -- Wizard footer uses native buttons to
 * match the Add-Client modal chrome (see components/wizard/shell.tsx). */
import { useForm } from '@inertiajs/react';
import { ClipboardCheck, ClipboardList, Target } from 'lucide-react';
import { useMemo } from 'react';

import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import { PeoplePicker, type PersonOption } from '../people-picker';
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
} from '../wizard';

export interface ReviewStaff {
    id: number;
    name: string;
    email: string;
}

export interface ReviewTypeOption {
    value: string;
    label: string;
}

export interface ExistingReview {
    id: number;
    employee?: { id: number; name: string } | null;
    employee_user_id?: number | null;
    review_type: string;
    review_period_start: string | null;
    review_period_end: string | null;
    next_review_date: string | null;
    overall_rating: number | null;
    strengths: string | null;
    development_areas: string | null;
    goals: string[] | null;
    training_recommendations: string[] | null;
}

const STEPS: readonly WizardStep[] = [
    { key: 'details', label: 'Details', blurb: 'Who & when', icon: ClipboardList },
    { key: 'assessment', label: 'Assessment', blurb: 'Strengths & goals', icon: Target },
    { key: 'review', label: 'Review', blurb: 'Confirm & save', icon: ClipboardCheck },
];

const RATING_OPTIONS = [
    { value: '1', label: '1 — Needs improvement' },
    { value: '2', label: '2 — Below expectations' },
    { value: '3', label: '3 — Meets expectations' },
    { value: '4', label: '4 — Exceeds expectations' },
    { value: '5', label: '5 — Outstanding' },
];

const toLines = (v: string[] | null | undefined) => (v ?? []).join('\n');
const fromLines = (v: string) =>
    v
        .split('\n')
        .map((s) => s.trim())
        .filter((s) => s !== '');

/**
 * Create / edit a performance review in a WizardShell modal, replacing the
 * page-based create-review + edit-review forms. Posts to hr.performance.reviews
 * (store) or PUTs hr.performance.reviews.update. Employee is fixed in edit mode
 * (the update endpoint doesn't reassign it).
 */
export function ReviewWizardDialog({
    open,
    onClose,
    staff,
    reviewTypes,
    review,
}: {
    open: boolean;
    onClose: () => void;
    staff: ReviewStaff[];
    reviewTypes: ReviewTypeOption[];
    review?: ExistingReview | null;
}) {
    const isEdit = !!review;
    const wizard = useWizard(STEPS.length);
    const form = useForm<{
        employee_user_id: string;
        review_type: string;
        review_period_start: string;
        review_period_end: string;
        next_review_date: string;
        overall_rating: string;
        strengths: string;
        development_areas: string;
        goals_text: string;
        training_text: string;
    }>({
        employee_user_id: review?.employee?.id
            ? String(review.employee.id)
            : review?.employee_user_id
              ? String(review.employee_user_id)
              : '',
        review_type: review?.review_type ?? '',
        review_period_start: review?.review_period_start ?? '',
        review_period_end: review?.review_period_end ?? '',
        next_review_date: review?.next_review_date ?? '',
        overall_rating: review?.overall_rating ? String(review.overall_rating) : '',
        strengths: review?.strengths ?? '',
        development_areas: review?.development_areas ?? '',
        goals_text: toLines(review?.goals),
        training_text: toLines(review?.training_recommendations),
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
    const typeLabel =
        reviewTypes.find((t) => t.value === form.data.review_type)?.label ?? '—';

    const canSubmit =
        form.data.employee_user_id !== '' &&
        form.data.review_type !== '' &&
        form.data.review_period_start !== '' &&
        form.data.review_period_end !== '';

    const submit = () => {
        form.transform((data) => ({
            ...data,
            overall_rating: data.overall_rating || null,
            next_review_date: data.next_review_date || null,
            goals: fromLines(data.goals_text),
            training_recommendations: fromLines(data.training_text),
        }));

        const opts = {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => {
                if (
                    form.errors.employee_user_id ||
                    form.errors.review_type ||
                    form.errors.review_period_start ||
                    form.errors.review_period_end
                ) {
                    wizard.goTo(0);
                }
            },
        };

        if (isEdit) {
            form.put(`/hr/performance/reviews/${review!.id}`, opts);
        } else {
            form.post('/hr/performance/reviews', opts);
        }
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit performance review' : 'New performance review'}
            description="Schedule and record a staff performance review."
            railIcon={Target}
            railTitle={isEdit ? 'Edit review' : 'New review'}
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
                                  : 'Create review'}
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
                        icon={ClipboardList}
                        title="Review details"
                        blurb="The staff member, type, and period this review covers."
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
                            label="Review type"
                            required
                            error={form.errors.review_type}
                        >
                            <SelectInput
                                value={form.data.review_type}
                                onChange={(v) => form.setData('review_type', v)}
                                placeholder="Select a type"
                                options={reviewTypes}
                            />
                        </Field>
                        <Field
                            label="Overall rating"
                            hint="optional"
                            error={form.errors.overall_rating}
                        >
                            <SelectInput
                                value={form.data.overall_rating}
                                onChange={(v) =>
                                    form.setData('overall_rating', v)
                                }
                                placeholder="Not rated"
                                options={RATING_OPTIONS}
                            />
                        </Field>
                        <Field
                            label="Period start"
                            required
                            error={form.errors.review_period_start}
                        >
                            <Input
                                type="date"
                                value={form.data.review_period_start}
                                onChange={(e) =>
                                    form.setData(
                                        'review_period_start',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Period end"
                            required
                            error={form.errors.review_period_end}
                        >
                            <Input
                                type="date"
                                value={form.data.review_period_end}
                                onChange={(e) =>
                                    form.setData(
                                        'review_period_end',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                        <Field
                            label="Next review date"
                            hint="optional"
                            error={form.errors.next_review_date}
                        >
                            <Input
                                type="date"
                                value={form.data.next_review_date}
                                onChange={(e) =>
                                    form.setData(
                                        'next_review_date',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Target}
                        title="Assessment"
                        blurb="Strengths, development areas, goals and training (one per line)."
                    />
                    <div className="space-y-4">
                        <Field label="Strengths" hint="optional" error={form.errors.strengths}>
                            <Textarea
                                rows={3}
                                value={form.data.strengths}
                                onChange={(e) =>
                                    form.setData('strengths', e.target.value)
                                }
                                placeholder="What the employee does well…"
                            />
                        </Field>
                        <Field
                            label="Development areas"
                            hint="optional"
                            error={form.errors.development_areas}
                        >
                            <Textarea
                                rows={3}
                                value={form.data.development_areas}
                                onChange={(e) =>
                                    form.setData(
                                        'development_areas',
                                        e.target.value,
                                    )
                                }
                                placeholder="Where the employee can improve…"
                            />
                        </Field>
                        <Field label="Goals" hint="one per line">
                            <Textarea
                                rows={3}
                                value={form.data.goals_text}
                                onChange={(e) =>
                                    form.setData('goals_text', e.target.value)
                                }
                                placeholder={'Lead the new intake roster\nComplete medication competency'}
                            />
                        </Field>
                        <Field label="Training recommendations" hint="one per line">
                            <Textarea
                                rows={3}
                                value={form.data.training_text}
                                onChange={(e) =>
                                    form.setData('training_text', e.target.value)
                                }
                                placeholder={'First aid refresher\nDe-escalation workshop'}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Review & save"
                        blurb="The review is saved as a draft you can sign off later."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard
                            icon={ClipboardList}
                            title="Details"
                            onEdit={() => wizard.goTo(0)}
                        >
                            <ReviewRow label="Staff" value={employeeName} />
                            <ReviewRow label="Type" value={typeLabel} />
                            <ReviewRow
                                label="Period"
                                value={
                                    form.data.review_period_start &&
                                    form.data.review_period_end
                                        ? `${form.data.review_period_start} → ${form.data.review_period_end}`
                                        : undefined
                                }
                            />
                            <ReviewRow
                                label="Rating"
                                value={form.data.overall_rating || undefined}
                            />
                        </ReviewCard>
                        <ReviewCard
                            icon={Target}
                            title="Assessment"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow
                                label="Strengths"
                                value={form.data.strengths}
                            />
                            <ReviewRow
                                label="Dev areas"
                                value={form.data.development_areas}
                            />
                            <ReviewRow
                                label="Goals"
                                value={
                                    fromLines(form.data.goals_text).length
                                        ? `${fromLines(form.data.goals_text).length} goal(s)`
                                        : undefined
                                }
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default ReviewWizardDialog;
