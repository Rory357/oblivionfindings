import { useForm } from '@inertiajs/react';
import {
    BookOpen,
    Briefcase,
    CalendarClock,
    Check,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    FileText,
    HeartPulse,
    Landmark,
    Loader2,
    Lock,
    ShieldCheck,
    Stethoscope,
    Users,
    Wallet,
    Workflow,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import type { StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
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
import { formatDateOnly } from '@/lib/datetime';

/* ------------------------------------------------------------------ */
/*  Registries                                                         */
/* ------------------------------------------------------------------ */

export const POLICY_CATEGORIES = [
    {
        key: 'governance',
        label: 'Governance',
        description: 'Board, constitution and delegations',
        icon: Landmark,
    },
    {
        key: 'financial',
        label: 'Financial',
        description: 'Spend, reserves and controls',
        icon: Wallet,
    },
    {
        key: 'hr',
        label: 'Human Resources',
        description: 'People, conduct and employment',
        icon: Users,
    },
    {
        key: 'health_safety',
        label: 'Health & Safety',
        description: 'HSWA duties and safe work',
        icon: HeartPulse,
    },
    {
        key: 'privacy',
        label: 'Privacy',
        description: 'Privacy Act and information handling',
        icon: ShieldCheck,
    },
    {
        key: 'clinical',
        label: 'Clinical',
        description: 'Clinical quality and care standards',
        icon: Stethoscope,
    },
    {
        key: 'operational',
        label: 'Operational',
        description: 'Service delivery and operations',
        icon: Workflow,
    },
    {
        key: 'other',
        label: 'Other',
        description: 'Anything not covered above',
        icon: Briefcase,
    },
] as const;

export function policyCategoryLabel(value: string | null | undefined): string {
    return (
        POLICY_CATEGORIES.find((c) => c.key === value)?.label ??
        (value ? value.replace(/_/g, ' ') : '—')
    );
}

export const POLICY_STATUS_OPTIONS = [
    { value: 'draft', label: 'Draft' },
    { value: 'active', label: 'Active' },
    { value: 'under_review', label: 'Under review' },
    { value: 'archived', label: 'Archived' },
];

export const POLICY_STATUS_VARIANT: Record<string, StatusVariant> = {
    active: 'success',
    draft: 'neutral',
    under_review: 'warning',
    archived: 'neutral',
};

export function policyStatusLabel(status: string): string {
    return (
        POLICY_STATUS_OPTIONS.find((s) => s.value === status)?.label ??
        status.replace(/_/g, ' ')
    );
}

const FREQUENCY_OPTIONS = [
    { value: 'annual', label: 'Annually' },
    { value: 'biannual', label: 'Twice a year' },
    { value: 'quarterly', label: 'Quarterly' },
];

/** The editable shape of a policy, as presented by GovernancePolicyController. */
export interface PolicyWizardRecord {
    id: number;
    title: string;
    category: string;
    description: string | null;
    content: string;
    status: string;
    effective_date: string | null;
    review_date: string | null;
    requires_attestation: boolean;
    attestation_frequency?: string | null;
}

type StepKey = 'details' | 'content' | 'schedule' | 'review';

const STEPS: readonly (WizardStep & { key: StepKey })[] = [
    {
        key: 'details',
        label: 'Details',
        blurb: 'Title, category & purpose',
        icon: BookOpen,
    },
    {
        key: 'content',
        label: 'Policy content',
        blurb: 'The policy wording itself',
        icon: FileText,
    },
    {
        key: 'schedule',
        label: 'Dates & attestation',
        blurb: 'Effective, review & sign-off',
        icon: CalendarClock,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before saving',
        icon: ClipboardCheck,
    },
];

const FIELD_STEP: Record<string, StepKey> = {
    title: 'details',
    category: 'details',
    description: 'details',
    content: 'content',
    effective_date: 'schedule',
    review_date: 'schedule',
    requires_attestation: 'schedule',
    attestation_frequency: 'schedule',
    status: 'schedule',
};

interface PolicyFormValues {
    title: string;
    category: string;
    description: string;
    content: string;
    effective_date: string;
    review_date: string;
    requires_attestation: boolean;
    attestation_frequency: string;
    status: string;
}

function todayIso(): string {
    return new Date().toISOString().split('T')[0];
}

function inAYearIso(): string {
    return new Date(Date.now() + 365 * 24 * 60 * 60 * 1000)
        .toISOString()
        .split('T')[0];
}

function initialValues(policy: PolicyWizardRecord | null): PolicyFormValues {
    if (!policy) {
        return {
            title: '',
            category: 'governance',
            description: '',
            content: '',
            effective_date: todayIso(),
            review_date: inAYearIso(),
            requires_attestation: false,
            attestation_frequency: 'annual',
            status: 'draft',
        };
    }
    return {
        title: policy.title,
        category: policy.category,
        description: policy.description ?? '',
        content: policy.content ?? '',
        effective_date: policy.effective_date?.split('T')[0] ?? '',
        review_date: policy.review_date?.split('T')[0] ?? '',
        requires_attestation: Boolean(policy.requires_attestation),
        attestation_frequency: policy.attestation_frequency ?? '',
        status: policy.status,
    };
}

function validateStep(
    key: StepKey,
    data: PolicyFormValues,
    isEdit: boolean,
): Record<string, string> {
    const errors: Record<string, string> = {};
    if (key === 'details') {
        if (!data.title.trim()) errors.title = 'Give the policy a title.';
        if (!data.category) errors.category = 'Choose a category.';
    }
    if (key === 'content' && !data.content.trim()) {
        errors.content = 'Add the policy wording.';
    }
    if (key === 'schedule' && !isEdit) {
        if (!data.effective_date)
            errors.effective_date = 'Set the effective date.';
        if (!data.review_date) errors.review_date = 'Set the review date.';
        if (
            data.effective_date &&
            data.review_date &&
            data.review_date <= data.effective_date
        ) {
            errors.review_date = 'The review date must be after the effective date.';
        }
    }
    return errors;
}

/* ------------------------------------------------------------------ */
/*  Public dialog                                                      */
/* ------------------------------------------------------------------ */

export function PolicyWizardDialog({
    open,
    onClose,
    policy = null,
}: {
    open: boolean;
    onClose: () => void;
    /** Present = edit (prefilled); absent = add. */
    policy?: PolicyWizardRecord | null;
}) {
    // Re-mount the body per open so the form resets cleanly.
    return open ? <PolicyWizardBody onClose={onClose} policy={policy} /> : null;
}

function PolicyWizardBody({
    onClose,
    policy,
}: {
    onClose: () => void;
    policy: PolicyWizardRecord | null;
}) {
    const isEdit = policy !== null;
    // Approved policies are presented as "active"; their wording can only
    // change through a new version (GovernancePolicyController::update).
    const contentLocked = isEdit && policy?.status === 'active';

    const form = useForm<PolicyFormValues>(initialValues(policy));
    const { data, setData, processing } = form;
    const [stepIndex, setStepIndex] = useState(0);
    const [clientErrors, setClientErrors] = useState<Record<string, string>>(
        {},
    );
    const [done, setDone] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);

    const current = STEPS[stepIndex];
    const err = (name: keyof PolicyFormValues): string | undefined =>
        clientErrors[name] ??
        (form.errors as Record<string, string | undefined>)[name];

    const pct = useMemo(() => {
        const checks = [
            data.title.trim(),
            data.category,
            data.description.trim(),
            data.content.trim(),
            data.effective_date,
            data.review_date,
        ];
        return Math.round(
            (checks.filter(Boolean).length / checks.length) * 100,
        );
    }, [data]);

    const goTo = (key: StepKey) => {
        const idx = STEPS.findIndex((s) => s.key === key);
        if (idx >= 0) setStepIndex(idx);
    };

    const next = () => {
        const errors = validateStep(current.key, data, isEdit);
        setClientErrors(errors);
        if (Object.keys(errors).length > 0) return;
        setStepIndex((i) => Math.min(i + 1, STEPS.length - 1));
    };

    const requestClose = () => {
        if (form.isDirty && !done) {
            setConfirmClose(true);
            return;
        }
        onClose();
    };

    const submit = () => {
        const all: Record<string, string> = {};
        for (const step of STEPS) {
            if (contentLocked && step.key === 'content') continue;
            Object.assign(all, validateStep(step.key, data, isEdit));
        }
        if (Object.keys(all).length > 0) {
            setClientErrors(all);
            goTo(FIELD_STEP[Object.keys(all)[0]] ?? 'details');
            return;
        }
        setClientErrors({});

        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (response: { props: Record<string, unknown> }) => {
                // back()->with('error') still resolves as a success visit.
                const flash = response.props.flash as
                    | { error?: string | null }
                    | undefined;
                if (!flash?.error) setDone(true);
            },
            onError: (errors: Record<string, string>) => {
                const first = Object.keys(errors)[0];
                if (first) goTo(FIELD_STEP[first] ?? 'details');
            },
        };

        if (isEdit && policy) {
            form.transform((values) => {
                const payload: Record<string, unknown> = {
                    title: values.title,
                    category: values.category,
                    description: values.description,
                    requires_attestation: values.requires_attestation,
                    attestation_frequency: values.attestation_frequency || null,
                };
                if (!contentLocked) payload.content = values.content;
                if (values.review_date) payload.review_date = values.review_date;
                // Only send a status change — re-sending the presented status
                // of a superseded policy would archive it.
                if (values.status !== policy.status)
                    payload.status = values.status;
                return payload;
            });
            form.put(`/governance/policies/${policy.id}`, options);
        } else {
            form.transform((values) => ({
                title: values.title,
                category: values.category,
                description: values.description,
                content: values.content,
                effective_date: values.effective_date,
                review_date: values.review_date,
                requires_attestation: values.requires_attestation,
                attestation_frequency: values.attestation_frequency || null,
            }));
            form.post('/governance/policies', options);
        }
    };

    const isReview = current.key === 'review';

    return (
        <>
            <WizardShell
                open
                onClose={requestClose}
                title={isEdit ? 'Edit policy' : 'New policy'}
                description={
                    isEdit
                        ? 'Update the policy details, wording, review date and attestation.'
                        : 'Define a new board policy, its wording, review schedule and attestation requirement.'
                }
                railIcon={BookOpen}
                railTitle={isEdit ? 'Edit policy' : 'New policy'}
                railSub={isEdit ? (policy?.title ?? 'Policy') : 'Board policy'}
                steps={STEPS}
                stepIndex={stepIndex}
                onStepClick={setStepIndex}
                pct={pct}
                success={
                    done ? (
                        <WizardSuccessPane
                            title={isEdit ? 'Policy updated' : 'Policy created'}
                            blurb={`“${data.title}” has been saved.`}
                            actions={<Button onClick={onClose}>Done</Button>}
                        />
                    ) : undefined
                }
                footerStart={
                    stepIndex > 0 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setStepIndex((i) => Math.max(i - 1, 0))}
                        >
                            <ChevronLeft className="h-4 w-4" /> Back
                        </Button>
                    ) : null
                }
                footerEnd={
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={requestClose}
                        >
                            Cancel
                        </Button>
                        {isReview ? (
                            <Button
                                type="button"
                                onClick={submit}
                                disabled={processing}
                            >
                                {processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Check className="h-4 w-4" />
                                )}
                                {isEdit ? 'Save policy' : 'Create policy'}
                            </Button>
                        ) : (
                            <Button type="button" onClick={next}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </>
                }
            >
                <WizardStepPane key={current.key}>
                    {current.key === 'details' ? (
                        <div className="grid gap-4">
                            <Field label="Policy title" required error={err('title')}>
                                <Input
                                    id="policy-title"
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                    placeholder="e.g. Delegations of Authority Policy"
                                />
                            </Field>
                            <Field label="Category" required error={err('category')}>
                                <TilePicker
                                    cols={3}
                                    value={data.category}
                                    onChange={(v) => setData('category', v)}
                                    options={POLICY_CATEGORIES.map((c) => ({
                                        key: c.key,
                                        label: c.label,
                                        description: c.description,
                                        icon: c.icon,
                                    }))}
                                />
                            </Field>
                            <Field
                                label="Purpose"
                                hint="One or two sentences"
                                error={err('description')}
                            >
                                <Textarea
                                    id="policy-description"
                                    rows={3}
                                    value={data.description}
                                    onChange={(e) =>
                                        setData('description', e.target.value)
                                    }
                                    placeholder="Why the board holds this policy and who it applies to."
                                />
                            </Field>
                        </div>
                    ) : null}

                    {current.key === 'content' ? (
                        <div className="grid gap-4">
                            {contentLocked ? (
                                <InfoCard icon={Lock} tone="warn">
                                    This policy is approved, so its wording is
                                    locked. Changes to the wording need a new
                                    policy version.
                                </InfoCard>
                            ) : null}
                            <Field
                                label="Policy content"
                                required={!isEdit}
                                error={err('content')}
                            >
                                <Textarea
                                    id="policy-content"
                                    rows={16}
                                    value={data.content}
                                    disabled={contentLocked}
                                    onChange={(e) =>
                                        setData('content', e.target.value)
                                    }
                                    placeholder="Set out the policy statement, scope, responsibilities and procedures."
                                />
                            </Field>
                        </div>
                    ) : null}

                    {current.key === 'schedule' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            {isEdit ? (
                                <Field label="Effective date">
                                    <Input
                                        id="policy-effective"
                                        type="date"
                                        value={data.effective_date}
                                        disabled
                                    />
                                </Field>
                            ) : (
                                <Field
                                    label="Effective date"
                                    required
                                    error={err('effective_date')}
                                >
                                    <Input
                                        id="policy-effective"
                                        type="date"
                                        value={data.effective_date}
                                        onChange={(e) =>
                                            setData('effective_date', e.target.value)
                                        }
                                    />
                                </Field>
                            )}
                            <Field
                                label="Review date"
                                required={!isEdit}
                                error={err('review_date')}
                            >
                                <Input
                                    id="policy-review"
                                    type="date"
                                    value={data.review_date}
                                    onChange={(e) =>
                                        setData('review_date', e.target.value)
                                    }
                                />
                            </Field>
                            {isEdit ? (
                                <Field label="Status" error={err('status')}>
                                    <SelectInput
                                        ariaLabel="Status"
                                        placeholder="Status"
                                        value={data.status}
                                        onChange={(v) => setData('status', v)}
                                        options={POLICY_STATUS_OPTIONS}
                                    />
                                </Field>
                            ) : null}
                            <div className="flex items-start gap-2.5 rounded-lg border border-border p-3 sm:col-span-2">
                                <Checkbox
                                    id="policy-requires-attestation"
                                    checked={data.requires_attestation}
                                    onCheckedChange={(v) =>
                                        setData('requires_attestation', v === true)
                                    }
                                />
                                <label
                                    htmlFor="policy-requires-attestation"
                                    className="text-sm"
                                >
                                    <span className="block font-medium">
                                        Require board member attestation
                                    </span>
                                    <span className="text-caption">
                                        Members confirm they have read and
                                        understood the approved policy.
                                    </span>
                                </label>
                            </div>
                            {data.requires_attestation ? (
                                <Field
                                    label="Attestation frequency"
                                    error={err('attestation_frequency')}
                                >
                                    <SelectInput
                                        ariaLabel="Attestation frequency"
                                        placeholder="Choose a frequency"
                                        value={data.attestation_frequency}
                                        onChange={(v) =>
                                            setData('attestation_frequency', v)
                                        }
                                        options={FREQUENCY_OPTIONS}
                                    />
                                </Field>
                            ) : null}
                        </div>
                    ) : null}

                    {isReview ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <ReviewCard
                                icon={BookOpen}
                                title="Details"
                                onEdit={() => goTo('details')}
                            >
                                <ReviewRow label="Title" value={data.title} />
                                <ReviewRow
                                    label="Category"
                                    value={policyCategoryLabel(data.category)}
                                />
                                <ReviewRow
                                    label="Purpose"
                                    value={data.description}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={CalendarClock}
                                title="Dates & attestation"
                                onEdit={() => goTo('schedule')}
                            >
                                <ReviewRow
                                    label="Effective"
                                    value={formatDateOnly(data.effective_date, '')}
                                />
                                <ReviewRow
                                    label="Review"
                                    value={formatDateOnly(data.review_date, '')}
                                />
                                <ReviewRow
                                    label="Attestation"
                                    value={
                                        data.requires_attestation
                                            ? (FREQUENCY_OPTIONS.find(
                                                  (f) =>
                                                      f.value ===
                                                      data.attestation_frequency,
                                              )?.label ?? 'Required')
                                            : 'Not required'
                                    }
                                />
                                {isEdit ? (
                                    <ReviewRow
                                        label="Status"
                                        value={
                                            POLICY_STATUS_OPTIONS.find(
                                                (s) => s.value === data.status,
                                            )?.label ?? data.status
                                        }
                                    />
                                ) : null}
                            </ReviewCard>
                            <ReviewCard
                                icon={FileText}
                                title="Policy content"
                                onEdit={() => goTo('content')}
                                span
                            >
                                {data.content.trim() ? (
                                    <p className="line-clamp-6 text-[13px] whitespace-pre-wrap text-muted-foreground">
                                        {data.content}
                                    </p>
                                ) : (
                                    <ReviewRow label="Content" value="" />
                                )}
                            </ReviewCard>
                        </div>
                    ) : null}
                </WizardStepPane>
            </WizardShell>

            <ConfirmDialog
                open={confirmClose}
                onClose={() => setConfirmClose(false)}
                onConfirm={onClose}
                title="Discard this draft?"
                description="Any changes made in this policy wizard will be lost."
                confirmText="Discard"
            />
        </>
    );
}
