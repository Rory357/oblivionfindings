/* eslint-disable no-restricted-syntax -- Wizard footer uses native buttons to
 * match the Add-Client modal chrome (see components/wizard/shell.tsx). */
import { useForm } from '@inertiajs/react';
import { ClipboardCheck, UserPlus } from 'lucide-react';
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

export interface SuccessionEmployeeOption {
    id: number;
    user_id: number;
    position_title?: string | null;
    department?: string | null;
    user?: { id: number; name: string; email?: string } | null;
}

export interface ExistingSuccessionCandidate {
    id: number;
    employee?: { id: number; name: string } | null;
    readiness: string;
    strengths: string | null;
    development_needs: string | null;
    overall_rating: number | null;
}

const STEPS: readonly WizardStep[] = [
    { key: 'candidate', label: 'Candidate', blurb: 'Who & readiness', icon: UserPlus },
    { key: 'assessment', label: 'Assessment', blurb: 'Strengths & needs', icon: ClipboardCheck },
];

const READINESS_OPTIONS = [
    { value: 'ready_now', label: 'Ready now' },
    { value: 'ready_1_year', label: 'Ready in 1 year' },
    { value: 'ready_2_years', label: 'Ready in 2 years' },
    { value: 'developing', label: 'Developing' },
];

const NO_RATING = '__none__';
const RATING_OPTIONS = [
    { value: NO_RATING, label: 'Not rated' },
    { value: '1', label: '1' },
    { value: '2', label: '2' },
    { value: '3', label: '3' },
    { value: '4', label: '4' },
    { value: '5', label: '5' },
];

/**
 * Add / edit a succession-plan candidate in a WizardShell modal — the plan show
 * page previously listed candidates read-only with no way to add or update them.
 * Create POSTs hr.succession.candidates.store; edit PUTs .update. Employee is
 * fixed in edit (the update endpoint doesn't reassign it).
 */
export function SuccessionCandidateDialog({
    open,
    onClose,
    planId,
    employees,
    candidate,
}: {
    open: boolean;
    onClose: () => void;
    planId: number;
    employees: SuccessionEmployeeOption[];
    candidate?: ExistingSuccessionCandidate | null;
}) {
    const isEdit = !!candidate;
    const wizard = useWizard(STEPS.length);
    const form = useForm<{
        employee_profile_id: string;
        readiness: string;
        overall_rating: string;
        strengths: string;
        development_needs: string;
    }>({
        employee_profile_id: candidate?.employee?.id
            ? String(candidate.employee.id)
            : '',
        readiness: candidate?.readiness ?? '',
        overall_rating: candidate?.overall_rating ? String(candidate.overall_rating) : '',
        strengths: candidate?.strengths ?? '',
        development_needs: candidate?.development_needs ?? '',
    });

    const close = () => {
        form.reset();
        form.clearErrors();
        wizard.reset();
        onClose();
    };

    const people: PersonOption[] = useMemo(
        () =>
            employees.map((e) => ({
                value: String(e.id),
                label: e.user?.name ?? `Profile #${e.id}`,
                sub:
                    [e.position_title, e.department].filter(Boolean).join(' · ') ||
                    e.user?.email ||
                    undefined,
            })),
        [employees],
    );

    const employeeName =
        candidate?.employee?.name ??
        employees.find((e) => String(e.id) === form.data.employee_profile_id)?.user
            ?.name ??
        '—';

    const canSubmit =
        (isEdit || form.data.employee_profile_id !== '') &&
        form.data.readiness !== '';

    const submit = () => {
        form.transform((data) => ({
            ...data,
            overall_rating:
                data.overall_rating === '' || data.overall_rating === NO_RATING
                    ? null
                    : data.overall_rating,
        }));

        const opts = {
            preserveScroll: true,
            onSuccess: () => close(),
            onError: () => {
                if (form.errors.employee_profile_id || form.errors.readiness) {
                    wizard.goTo(0);
                }
            },
        };

        if (isEdit) {
            form.put(`/hr/succession/candidates/${candidate!.id}`, opts);
        } else {
            form.post(`/hr/succession/${planId}/candidates`, opts);
        }
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title={isEdit ? 'Edit candidate' : 'Add succession candidate'}
            description="Assess a candidate's readiness to step into this role."
            railIcon={UserPlus}
            railTitle={isEdit ? 'Edit candidate' : 'Add candidate'}
            railSub="Succession"
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
                                  : 'Add candidate'}
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
                        icon={UserPlus}
                        title="Candidate & readiness"
                        blurb="The employee and how ready they are to take on the role."
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Employee"
                            required
                            span
                            error={form.errors.employee_profile_id}
                        >
                            {isEdit ? (
                                <Input value={employeeName} disabled />
                            ) : (
                                <PeoplePicker
                                    value={form.data.employee_profile_id}
                                    onChange={(v) =>
                                        form.setData('employee_profile_id', v)
                                    }
                                    people={people}
                                    placeholder="Select an employee…"
                                />
                            )}
                        </Field>
                        <Field
                            label="Readiness"
                            required
                            error={form.errors.readiness}
                        >
                            <SelectInput
                                value={form.data.readiness}
                                onChange={(v) => form.setData('readiness', v)}
                                placeholder="Select readiness"
                                options={READINESS_OPTIONS}
                            />
                        </Field>
                        <Field
                            label="Overall rating"
                            hint="optional"
                            error={form.errors.overall_rating}
                        >
                            <SelectInput
                                value={form.data.overall_rating || NO_RATING}
                                onChange={(v) =>
                                    form.setData(
                                        'overall_rating',
                                        v === NO_RATING ? '' : v,
                                    )
                                }
                                placeholder="Not rated"
                                options={RATING_OPTIONS}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Assessment"
                        blurb="Strengths and development needs for this candidate."
                    />
                    <div className="space-y-4">
                        <Field label="Strengths" hint="optional" error={form.errors.strengths}>
                            <Textarea
                                rows={3}
                                value={form.data.strengths}
                                onChange={(e) =>
                                    form.setData('strengths', e.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Development needs"
                            hint="optional"
                            error={form.errors.development_needs}
                        >
                            <Textarea
                                rows={3}
                                value={form.data.development_needs}
                                onChange={(e) =>
                                    form.setData(
                                        'development_needs',
                                        e.target.value,
                                    )
                                }
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

export default SuccessionCandidateDialog;
