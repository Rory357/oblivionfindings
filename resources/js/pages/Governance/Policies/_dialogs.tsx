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
import { pageHasFlashError } from '@/components/governance/governance-dialog-deep-link';
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
import { formatDateOnly, toDateInput } from '@/lib/datetime';
import {
    frequencyLabel,
    governanceStatus,
    policyCategoryLabel as foundationPolicyCategoryLabel,
    policyStatusLabel as foundationPolicyStatusLabel,
} from '@/lib/governance-labels';

/* ------------------------------------------------------------------ */
/*  Registries                                                         */
/* ------------------------------------------------------------------ */

export const POLICY_CATEGORIES = [
    {
        key: 'governance',
        description: 'Board, constitution and delegations',
        icon: Landmark,
    },
    {
        key: 'financial',
        description: 'Spending, reserves and controls',
        icon: Wallet,
    },
    {
        key: 'hr',
        description: 'People, conduct and employment',
        icon: Users,
    },
    {
        key: 'health_safety',
        description: 'Health and safety duties and safe work',
        icon: HeartPulse,
    },
    {
        key: 'privacy',
        description: 'Privacy Act and handling information',
        icon: ShieldCheck,
    },
    {
        key: 'clinical',
        description: 'Care quality and clinical standards',
        icon: Stethoscope,
    },
    {
        key: 'operational',
        description: 'Service delivery and day-to-day operations',
        icon: Workflow,
    },
    {
        key: 'other',
        description: 'Anything not covered above',
        icon: Briefcase,
    },
].map((category) => ({
    ...category,
    label: foundationPolicyCategoryLabel(category.key),
}));

export function policyCategoryLabel(value: string | null | undefined): string {
    return foundationPolicyCategoryLabel(value);
}

/** Presented statuses: "active" is an approved policy in the register. */
export const POLICY_STATUS_OPTIONS = [
    { value: 'draft', label: foundationPolicyStatusLabel('draft') },
    { value: 'under_review', label: foundationPolicyStatusLabel('under_review') },
    { value: 'active', label: foundationPolicyStatusLabel('active') },
    { value: 'archived', label: foundationPolicyStatusLabel('archived') },
];

export const POLICY_STATUS_VARIANT: Record<string, StatusVariant> = {
    active: governanceStatus('policy_status', 'active').variant,
    draft: governanceStatus('policy_status', 'draft').variant,
    under_review: governanceStatus('policy_status', 'under_review').variant,
    archived: governanceStatus('policy_status', 'archived').variant,
    superseded: governanceStatus('policy_status', 'superseded').variant,
};

export function policyStatusLabel(status: string): string {
    return foundationPolicyStatusLabel(status);
}

/** How often members are asked to confirm the same version again. */
export const CONFIRMATION_FREQUENCY_OPTIONS = ['annual', 'biannual', 'quarterly'].map(
    (value) => ({ value, label: frequencyLabel(value) }),
);

/**
 * Status choices in Edit. Approval only happens through Approve, so Edit can
 * never make a policy "Approved"; an approved policy can only be archived
 * (wording changes go through "Start new version").
 */
export function editStatusOptions(
    currentStatus: string,
): { value: string; label: string }[] {
    if (currentStatus === 'active') {
        return [
            { value: 'active', label: 'Approved — keep it in effect' },
            { value: 'archived', label: foundationPolicyStatusLabel('archived') },
        ];
    }
    return ['draft', 'under_review', 'archived'].map((value) => ({
        value,
        label: foundationPolicyStatusLabel(value),
    }));
}

/** The editable shape of a policy, as presented by GovernancePolicyController. */
export interface PolicyWizardRecord {
    id: number;
    title: string;
    category: string;
    description: string | null;
    content: string;
    status: string;
    version?: number;
    effective_date: string | null;
    review_date: string | null;
    requires_attestation: boolean;
    attestation_frequency?: string | null;
}

export type PolicyWizardMode = 'create' | 'edit' | 'version';

type StepKey = 'details' | 'content' | 'schedule' | 'review';

const STEPS: readonly (WizardStep & { key: StepKey })[] = [
    {
        key: 'details',
        label: 'Details',
        blurb: 'Title, category and purpose',
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
        label: 'Dates and confirming',
        blurb: 'When it applies and who confirms',
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
    change_summary: 'content',
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
    change_summary: string;
    effective_date: string;
    review_date: string;
    requires_attestation: boolean;
    attestation_frequency: string;
    status: string;
}

function todayNz(): string {
    return toDateInput(new Date());
}

function inAYearNz(): string {
    return toDateInput(Date.now() + 365 * 24 * 60 * 60 * 1000);
}

function initialValues(
    policy: PolicyWizardRecord | null,
    mode: PolicyWizardMode,
): PolicyFormValues {
    if (!policy || mode === 'create') {
        return {
            title: '',
            category: 'governance',
            description: '',
            content: '',
            change_summary: '',
            effective_date: todayNz(),
            review_date: inAYearNz(),
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
        change_summary: '',
        // A new version comes into effect when it is approved unless a date is chosen.
        effective_date:
            mode === 'version' ? '' : (policy.effective_date?.split('T')[0] ?? ''),
        review_date: policy.review_date?.split('T')[0] ?? '',
        requires_attestation: Boolean(policy.requires_attestation),
        attestation_frequency: policy.attestation_frequency ?? '',
        status: policy.status,
    };
}

function validateStep(
    key: StepKey,
    data: PolicyFormValues,
    mode: PolicyWizardMode,
): Record<string, string> {
    const errors: Record<string, string> = {};
    if (key === 'details') {
        if (!data.title.trim()) errors.title = 'Give the policy a title.';
        if (!data.category) errors.category = 'Choose a category.';
    }
    if (key === 'content') {
        if (!data.content.trim()) errors.content = 'Add the policy wording.';
        if (mode === 'version' && !data.change_summary.trim()) {
            errors.change_summary = 'Say what changed in this version.';
        }
    }
    if (key === 'schedule' && mode === 'create') {
        if (!data.effective_date)
            errors.effective_date = 'Choose the date the policy comes into effect.';
        if (!data.review_date)
            errors.review_date = 'Choose when the policy should next be reviewed.';
        if (
            data.effective_date &&
            data.review_date &&
            data.review_date <= data.effective_date
        ) {
            errors.review_date =
                'The review date must be after the date the policy comes into effect.';
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
    mode,
}: {
    open: boolean;
    onClose: () => void;
    /** Present = edit (prefilled) or start a new version; absent = add. */
    policy?: PolicyWizardRecord | null;
    mode?: PolicyWizardMode;
}) {
    const resolvedMode: PolicyWizardMode = mode ?? (policy ? 'edit' : 'create');
    // Re-mount the body per open so the form resets cleanly.
    return open ? (
        <PolicyWizardBody onClose={onClose} policy={policy} mode={resolvedMode} />
    ) : null;
}

function PolicyWizardBody({
    onClose,
    policy,
    mode,
}: {
    onClose: () => void;
    policy: PolicyWizardRecord | null;
    mode: PolicyWizardMode;
}) {
    const isEdit = mode === 'edit' && policy !== null;
    const isVersion = mode === 'version' && policy !== null;
    const nextVersion = (policy?.version ?? 1) + 1;
    // Approved policies are presented as "active"; their wording can only
    // change through a new version (GovernancePolicyController::update).
    const contentLocked = isEdit && policy?.status === 'active';

    const form = useForm<PolicyFormValues>(initialValues(policy, mode));
    const { data, setData, processing } = form;
    const [stepIndex, setStepIndex] = useState(0);
    const [clientErrors, setClientErrors] = useState<Record<string, string>>(
        {},
    );
    const [submitError, setSubmitError] = useState<string | null>(null);
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
            isVersion ? data.change_summary.trim() : data.effective_date,
            data.review_date,
        ];
        return Math.round(
            (checks.filter(Boolean).length / checks.length) * 100,
        );
    }, [data, isVersion]);

    const goTo = (key: StepKey) => {
        const idx = STEPS.findIndex((s) => s.key === key);
        if (idx >= 0) setStepIndex(idx);
    };

    const next = () => {
        const errors = validateStep(current.key, data, mode);
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
            Object.assign(all, validateStep(step.key, data, mode));
        }
        if (Object.keys(all).length > 0) {
            setClientErrors(all);
            goTo(FIELD_STEP[Object.keys(all)[0]] ?? 'details');
            return;
        }
        setClientErrors({});
        setSubmitError(null);

        const options = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page: unknown) => {
                // back()->with('error') still resolves as a success visit.
                if (pageHasFlashError(page)) {
                    const flash = (page as { props: { flash?: { error?: string } } })
                        .props.flash;
                    setSubmitError(flash?.error ?? 'The policy was not saved.');
                    return;
                }
                setDone(true);
            },
            onError: (errors: Record<string, string>) => {
                const first = Object.keys(errors)[0];
                if (first) goTo(FIELD_STEP[first] ?? 'details');
            },
        };

        if (isVersion && policy) {
            form.transform((values) => ({
                title: values.title,
                category: values.category,
                description: values.description,
                content: values.content,
                change_summary: values.change_summary,
                effective_date: values.effective_date || null,
                review_date: values.review_date || null,
                requires_attestation: values.requires_attestation,
                attestation_frequency: values.requires_attestation
                    ? values.attestation_frequency || null
                    : null,
            }));
            form.post(`/governance/policies/${policy.id}/version`, options);
            return;
        }

        if (isEdit && policy) {
            form.transform((values) => {
                const payload: Record<string, unknown> = {
                    title: values.title,
                    category: values.category,
                    description: values.description,
                    requires_attestation: values.requires_attestation,
                    attestation_frequency: values.requires_attestation
                        ? values.attestation_frequency || null
                        : null,
                };
                if (!contentLocked) payload.content = values.content;
                if (values.review_date) payload.review_date = values.review_date;
                // Only send a status change.
                if (values.status !== policy.status)
                    payload.status = values.status;
                return payload;
            });
            form.put(`/governance/policies/${policy.id}`, options);
            return;
        }

        form.transform((values) => ({
            title: values.title,
            category: values.category,
            description: values.description,
            content: values.content,
            effective_date: values.effective_date,
            review_date: values.review_date,
            requires_attestation: values.requires_attestation,
            attestation_frequency: values.requires_attestation
                ? values.attestation_frequency || null
                : null,
        }));
        form.post('/governance/policies', options);
    };

    const isReview = current.key === 'review';
    const title = isVersion
        ? `Start version ${nextVersion}`
        : isEdit
          ? 'Edit policy'
          : 'New policy';

    return (
        <>
            <WizardShell
                open
                onClose={requestClose}
                title={title}
                description={
                    isVersion
                        ? `Draft version ${nextVersion} of this policy. The current version stays in effect until the new one is approved.`
                        : isEdit
                          ? 'Update the policy details, review date and whether members confirm they have read it.'
                          : 'Write a new board policy, set its review date and choose whether members confirm they have read it.'
                }
                railIcon={BookOpen}
                railTitle={title}
                railSub={policy?.title ?? 'Board policy'}
                steps={STEPS}
                stepIndex={stepIndex}
                onStepClick={setStepIndex}
                pct={pct}
                success={
                    done ? (
                        <WizardSuccessPane
                            title={
                                isVersion
                                    ? `Version ${nextVersion} saved as a draft`
                                    : isEdit
                                      ? 'Policy saved'
                                      : 'Policy saved as a draft'
                            }
                            blurb={
                                isVersion
                                    ? `“${data.title}” version ${nextVersion} is ready for approval. The current version stays in effect until then.`
                                    : isEdit
                                      ? `“${data.title}” has been saved.`
                                      : `“${data.title}” is saved as a draft. Approve it to put it into effect.`
                            }
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
                        {submitError ? (
                            <span
                                role="alert"
                                className="max-w-xs text-xs text-status-critical"
                            >
                                {submitError}
                            </span>
                        ) : null}
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
                                {isVersion
                                    ? `Save version ${nextVersion} as a draft`
                                    : isEdit
                                      ? 'Save policy'
                                      : 'Create policy'}
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
                                    placeholder="e.g. Delegations of authority policy"
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
                                    locked. Use “Start new version” on the
                                    policy page to change the wording.
                                </InfoCard>
                            ) : null}
                            {isVersion ? (
                                <Field
                                    label="What changed"
                                    required
                                    hint="Shown to members with the new version"
                                    error={err('change_summary')}
                                >
                                    <Textarea
                                        id="policy-change-summary"
                                        rows={3}
                                        maxLength={500}
                                        value={data.change_summary}
                                        onChange={(e) =>
                                            setData('change_summary', e.target.value)
                                        }
                                        placeholder="e.g. Updated the spending limits in section 4 to match the new budget."
                                    />
                                </Field>
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
                                    placeholder="Set out the policy statement, who it applies to, responsibilities and procedures."
                                />
                            </Field>
                        </div>
                    ) : null}

                    {current.key === 'schedule' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            {isEdit ? (
                                <Field label="Comes into effect">
                                    <Input
                                        id="policy-effective"
                                        type="date"
                                        value={data.effective_date}
                                        disabled
                                    />
                                </Field>
                            ) : (
                                <Field
                                    label="Comes into effect"
                                    required={!isVersion}
                                    hint={
                                        isVersion
                                            ? 'Leave blank to put it into effect when it is approved'
                                            : undefined
                                    }
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
                                label="Next review"
                                required={mode === 'create'}
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
                            {isEdit && policy ? (
                                <Field label="Status" error={err('status')}>
                                    <SelectInput
                                        ariaLabel="Status"
                                        placeholder="Status"
                                        value={data.status}
                                        onChange={(v) => setData('status', v)}
                                        options={editStatusOptions(policy.status)}
                                    />
                                </Field>
                            ) : null}
                            {isEdit ? (
                                <p className="text-caption sm:col-span-2">
                                    A policy is put into effect with Approve on
                                    the policy page, never from here.
                                </p>
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
                                        Ask board members to read and confirm it
                                    </span>
                                    <span className="text-caption">
                                        Each member confirms they have read the
                                        approved version. Their confirmation is
                                        recorded with the version and date.
                                    </span>
                                </label>
                            </div>
                            {data.requires_attestation ? (
                                <Field
                                    label="Ask them to confirm again"
                                    error={err('attestation_frequency')}
                                >
                                    <SelectInput
                                        ariaLabel="Ask them to confirm again"
                                        placeholder="Only once"
                                        value={data.attestation_frequency}
                                        onChange={(v) =>
                                            setData('attestation_frequency', v)
                                        }
                                        options={CONFIRMATION_FREQUENCY_OPTIONS}
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
                                title="Dates and confirming"
                                onEdit={() => goTo('schedule')}
                            >
                                <ReviewRow
                                    label="Comes into effect"
                                    value={
                                        data.effective_date
                                            ? formatDateOnly(data.effective_date, '')
                                            : isVersion
                                              ? 'When approved'
                                              : ''
                                    }
                                />
                                <ReviewRow
                                    label="Next review"
                                    value={formatDateOnly(data.review_date, '')}
                                />
                                <ReviewRow
                                    label="Read and confirm"
                                    value={
                                        data.requires_attestation
                                            ? data.attestation_frequency
                                                ? `Yes — again ${frequencyLabel(data.attestation_frequency).toLowerCase()}`
                                                : 'Yes — once for each version'
                                            : 'Not needed'
                                    }
                                />
                                {isEdit && policy ? (
                                    <ReviewRow
                                        label="Status"
                                        value={
                                            editStatusOptions(policy.status).find(
                                                (s) => s.value === data.status,
                                            )?.label ?? policyStatusLabel(data.status)
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
                                {isVersion ? (
                                    <ReviewRow
                                        label="What changed"
                                        value={data.change_summary}
                                    />
                                ) : null}
                                {data.content.trim() ? (
                                    <p className="line-clamp-6 text-sm whitespace-pre-wrap text-muted-foreground">
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
