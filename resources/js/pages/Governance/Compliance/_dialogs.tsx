/**
 * Governance compliance dialogs (design_styles/POPUP_STYLE_GUIDE.md):
 *
 *   - ObligationWizardDialog — the requirement add/edit wizard (WizardShell).
 *     Add opens from the register header, Edit from the requirement record;
 *     both use the SAME steps, prefilled on edit, and every field can be
 *     corrected after it was added (changes are kept in the audit log).
 *   - UploadEvidenceDialog / CompleteObligationDialog — simple dialogs opened
 *     from the requirement record.
 *
 * Fields and rules mirror StoreComplianceObligationRequest and
 * ComplianceController::update / uploadEvidence / complete.
 */
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    Segmented,
    SelectInput,
    StepHead,
    SubHead,
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
    complianceEvidenceTypeLabel,
    frequencyLabel,
    priorityLabel,
} from '@/lib/governance-labels';
import { cn } from '@/lib/utils';
import { router, useForm } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertTriangle,
    Award,
    Banknote,
    CalendarClock,
    Check,
    CheckCircle,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    ClipboardList,
    Database,
    FileCheck,
    FileText,
    Gavel,
    HeartPulse,
    History,
    Loader2,
    Plus,
    Scale,
    ShieldCheck,
    Signature,
    Upload,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';

/* ------------------------------------------------------------------ */
/*  Registries                                                         */
/* ------------------------------------------------------------------ */

export interface FrameworkOption {
    value: string;
    label: string;
}

export interface ObligationOwnerOption {
    id: number;
    name: string;
}

export interface ObligationFormOptions {
    frameworks: FrameworkOption[];
    owners: ObligationOwnerOption[];
}

export const FREQUENCY_OPTIONS = [
    'monthly',
    'quarterly',
    'annual',
    'ad_hoc',
    'event_driven',
].map((value) => ({ value, label: frequencyLabel(value) }));

export const PRIORITY_OPTIONS = ['low', 'medium', 'high', 'critical'].map(
    (value) => ({ value, label: priorityLabel(value) }),
);

/** Framework → icon for tile pickers and list marks (cosmetic grouping). */
export function frameworkIcon(value: string): LucideIcon {
    if (value.startsWith('funding')) return Banknote;
    if (value === 'hswa' || value === 'hdsa_safety') return HeartPulse;
    if (value === 'privacy_act' || value === 'hip_code') return ShieldCheck;
    if (value === 'employment') return Users;
    if (value === 'charities' || value === 'code_of_rights') return Scale;
    return Gavel;
}

function optionLabel(
    options: { value: string; label: string }[],
    value: string | null | undefined,
): string | undefined {
    return options.find((o) => o.value === value)?.label;
}

type FlashPage = { props: { flash?: { error?: string | null } } };

function flashError(page: unknown): string | null {
    return (page as FlashPage | undefined)?.props?.flash?.error ?? null;
}

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return <p className="mt-1 text-xs text-status-critical">{message}</p>;
}

/* ------------------------------------------------------------------ */
/*  Requirement wizard (add + edit)                                    */
/* ------------------------------------------------------------------ */

export interface ObligationRecord {
    id: number;
    framework: string;
    framework_label?: string;
    obligation_code: string | null;
    obligation_title: string;
    description: string | null;
    requirements?: string | null;
    frequency: string | null;
    priority?: string | null;
    due_date: string | null;
    owner_id?: number | null;
    owner?: { id: number; name: string } | null;
    notes?: string | null;
    evidence_required?: boolean;
}

type ObligationWizardForm = {
    framework: string;
    obligation_reference: string;
    title: string;
    description: string;
    requirements: string;
    notes: string;
    frequency: string;
    due_date: string;
    priority: string;
    owner_id: string;
    evidence_required: boolean;
};

const OBLIGATION_STEPS: readonly WizardStep[] = [
    {
        key: 'obligation',
        label: 'Requirement',
        blurb: 'Where it comes from and its name',
        icon: ShieldCheck,
    },
    {
        key: 'details',
        label: 'Details',
        blurb: 'What has to be done',
        icon: ClipboardList,
    },
    {
        key: 'schedule',
        label: 'Schedule',
        blurb: 'When, who and how important',
        icon: CalendarClock,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Check before saving',
        icon: CheckCircle2,
    },
];

const OBLIGATION_STEP_FOR_FIELD: Record<string, number> = {
    framework: 0,
    obligation_reference: 0,
    title: 0,
    description: 1,
    requirements: 1,
    notes: 1,
    frequency: 2,
    due_date: 2,
    priority: 2,
    owner_id: 2,
    evidence_required: 2,
};

function initialObligationForm(
    obligation: ObligationRecord | null,
): ObligationWizardForm {
    return {
        framework: obligation?.framework ?? '',
        obligation_reference: obligation?.obligation_code ?? '',
        title: obligation?.obligation_title ?? '',
        description: obligation?.description ?? '',
        requirements: obligation?.requirements ?? '',
        notes: obligation?.notes ?? '',
        frequency: obligation?.frequency ?? 'annual',
        due_date: obligation?.due_date ? obligation.due_date.slice(0, 10) : '',
        priority: obligation?.priority ?? 'medium',
        owner_id:
            obligation?.owner_id != null
                ? String(obligation.owner_id)
                : obligation?.owner?.id != null
                  ? String(obligation.owner.id)
                  : '',
        evidence_required: obligation?.evidence_required ?? true,
    };
}

function validateObligationStep(
    index: number,
    data: ObligationWizardForm,
    isEdit: boolean,
): Record<string, string> {
    const e: Record<string, string> = {};
    if (index === 0) {
        if (!data.framework)
            e.framework =
                'Choose the law, standard or funding contract this requirement comes from.';
        if (!data.title.trim()) e.title = 'Give the requirement a name.';
        else if (data.title.length > 255)
            e.title = 'Keep the name to 255 characters or fewer.';
        if (data.obligation_reference.length > 50)
            e.obligation_reference = 'Keep the reference to 50 characters or fewer.';
    }
    if (index === 1) {
        if (!data.description.trim())
            e.description = 'Describe what this requirement covers.';
    }
    if (index === 2 && isEdit && !data.due_date) {
        e.due_date = 'Choose the due date.';
    }
    return e;
}

export interface ObligationWizardDialogProps {
    open: boolean;
    onClose: () => void;
    options: ObligationFormOptions;
    /** When set the wizard edits this requirement; otherwise it adds one. */
    obligation?: ObligationRecord | null;
}

export function ObligationWizardDialog(props: ObligationWizardDialogProps) {
    // Re-mount the body on every open so the form resets cleanly.
    return props.open ? <ObligationWizardBody {...props} /> : null;
}

function ObligationWizardBody({
    open,
    onClose,
    options,
    obligation = null,
}: ObligationWizardDialogProps) {
    const isEdit = obligation != null;
    const initial = useMemo(
        () => initialObligationForm(obligation),
        [obligation],
    );
    const form = useForm<ObligationWizardForm>(initial);
    const { data, setData, processing } = form;

    const [stepIndex, setStepIndex] = useState(0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitError, setSubmitError] = useState<string | null>(null);
    const [done, setDone] = useState(false);
    const [confirmClose, setConfirmClose] = useState(false);

    const set = <K extends keyof ObligationWizardForm>(
        key: K,
        value: ObligationWizardForm[K],
    ) => setData((prev) => ({ ...prev, [key]: value }));
    const err = (name: string): string | undefined =>
        errors[name] ??
        (form.errors as Record<string, string>)[name] ??
        (name === 'title'
            ? (form.errors as Record<string, string>).obligation_title
            : undefined);

    const cur = OBLIGATION_STEPS[stepIndex];
    const isReview = cur.key === 'review';

    // A requirement filed under an older framework keeps it as a choice.
    const frameworkOptions = useMemo(() => {
        if (
            obligation?.framework &&
            !options.frameworks.some((f) => f.value === obligation.framework)
        ) {
            return [
                ...options.frameworks,
                {
                    value: obligation.framework,
                    label: obligation.framework_label ?? obligation.framework,
                },
            ];
        }
        return options.frameworks;
    }, [obligation, options.frameworks]);

    const frameworkLabel =
        optionLabel(frameworkOptions, data.framework) ?? data.framework;
    const ownerName =
        options.owners.find((o) => String(o.id) === data.owner_id)?.name ??
        (isEdit ? obligation?.owner?.name : undefined);

    const pct = useMemo(() => {
        const checks = [
            !!data.framework,
            !!data.title.trim(),
            !!data.obligation_reference.trim(),
            !!data.description.trim(),
            !!data.requirements.trim(),
            !!data.frequency,
            !!data.due_date,
            !!data.priority,
            !!data.owner_id,
        ];
        return Math.round(
            (checks.filter(Boolean).length / checks.length) * 100,
        );
    }, [data]);

    const goTo = (index: number) =>
        setStepIndex(
            Math.max(0, Math.min(index, OBLIGATION_STEPS.length - 1)),
        );

    const next = () => {
        const e = validateObligationStep(stepIndex, data, isEdit);
        setErrors(e);
        if (Object.keys(e).length === 0) goTo(stepIndex + 1);
    };

    const requestClose = () => {
        if (form.isDirty && !done) {
            setConfirmClose(true);
            return;
        }
        onClose();
    };

    const resetAll = () => {
        form.clearErrors();
        setData(initialObligationForm(null));
        setErrors({});
        setSubmitError(null);
        setStepIndex(0);
        setDone(false);
    };

    const submit = () => {
        const all = {
            ...validateObligationStep(0, data, isEdit),
            ...validateObligationStep(1, data, isEdit),
            ...validateObligationStep(2, data, isEdit),
        };
        if (Object.keys(all).length) {
            setErrors(all);
            goTo(OBLIGATION_STEP_FOR_FIELD[Object.keys(all)[0]] ?? 0);
            return;
        }
        setErrors({});
        setSubmitError(null);

        const visit = {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page: unknown) => {
                const error = flashError(page);
                if (error) {
                    setSubmitError(error);
                    return;
                }
                setDone(true);
            },
            onError: (errs: Record<string, string>) => {
                const first = Object.keys(errs)[0];
                if (first) {
                    goTo(
                        OBLIGATION_STEP_FOR_FIELD[
                            first === 'obligation_title' ? 'title' : first
                        ] ?? 0,
                    );
                }
            },
        };

        if (isEdit && obligation) {
            form.transform((current) => {
                const payload: Record<string, unknown> = {
                    framework: current.framework,
                    obligation_reference: current.obligation_reference || null,
                    title: current.title,
                    description: current.description,
                    requirements: current.requirements || null,
                    frequency: current.frequency,
                    priority: current.priority,
                    evidence_required: current.evidence_required,
                    notes: current.notes,
                };
                if (current.due_date) payload.due_date = current.due_date;
                if (current.owner_id && current.owner_id !== initial.owner_id) {
                    payload.owner_id = Number(current.owner_id);
                }
                return payload as unknown as ObligationWizardForm;
            });
            form.put(`/governance/compliance/${obligation.id}`, visit);
            return;
        }

        form.transform(
            (current) =>
                ({
                    _modal: true,
                    framework: current.framework,
                    obligation_reference: current.obligation_reference,
                    title: current.title,
                    description: current.description,
                    requirements: current.requirements,
                    frequency: current.frequency,
                    due_date: current.due_date || null,
                    priority: current.priority,
                    evidence_required: current.evidence_required,
                    owner_id: current.owner_id
                        ? Number(current.owner_id)
                        : null,
                }) as unknown as ObligationWizardForm,
        );
        form.post('/governance/compliance', visit);
    };

    const success = done ? (
        <WizardSuccessPane
            title={isEdit ? 'Requirement saved' : 'Requirement added'}
            blurb={
                isEdit ? (
                    <>
                        <strong>{data.title}</strong> has been saved. The change
                        is kept in the audit log.
                    </>
                ) : (
                    <>
                        <strong>{data.title}</strong> ({frameworkLabel}) is now on
                        the compliance register. Its owner will be reminded
                        before it is due.
                    </>
                )
            }
            actions={
                isEdit ? (
                    <Button type="button" onClick={onClose}>
                        Done
                    </Button>
                ) : (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={resetAll}
                        >
                            <Plus className="h-4 w-4" /> Add another
                        </Button>
                        <Button type="button" onClick={onClose}>
                            Done
                        </Button>
                    </>
                )
            }
        />
    ) : undefined;

    return (
        <>
            <WizardShell
                open={open}
                onClose={requestClose}
                title={isEdit ? 'Edit requirement' : 'Add requirement'}
                description={
                    isEdit
                        ? 'Correct any detail of this requirement. Each change is kept in the audit log.'
                        : 'Add a legal or funding requirement the organisation must meet.'
                }
                railIcon={ShieldCheck}
                railTitle={isEdit ? 'Edit requirement' : 'New requirement'}
                railSub="Compliance register"
                steps={OBLIGATION_STEPS}
                stepIndex={stepIndex}
                onStepClick={goTo}
                pct={pct}
                success={success}
                footerStart={
                    stepIndex > 0 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => goTo(stepIndex - 1)}
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
                                {isEdit ? 'Save changes' : 'Add requirement'}
                            </Button>
                        ) : (
                            <Button type="button" onClick={next}>
                                Continue <ChevronRight className="h-4 w-4" />
                            </Button>
                        )}
                    </>
                }
            >
                {cur.key === 'obligation' ? (
                    <WizardStepPane>
                        <StepHead
                            icon={ShieldCheck}
                            title="Which requirement?"
                            blurb="Choose the law, standard or funding contract it comes from and give it a name."
                        />
                        <div className="grid gap-4">
                            {isEdit ? (
                                <InfoCard icon={History}>
                                    Every detail can be corrected here. The
                                    audit log keeps what it was before.
                                </InfoCard>
                            ) : null}
                            <Field
                                label="Comes from"
                                required
                                error={err('framework')}
                            >
                                <TilePicker
                                    value={data.framework}
                                    onChange={(v) => set('framework', v)}
                                    cols={2}
                                    options={frameworkOptions.map((f) => ({
                                        key: f.value,
                                        label: f.label,
                                        icon: frameworkIcon(f.value),
                                    }))}
                                />
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Name"
                                    required
                                    error={err('title')}
                                    span
                                >
                                    <Input
                                        value={data.title}
                                        onChange={(e) =>
                                            set('title', e.target.value)
                                        }
                                        maxLength={255}
                                        placeholder="e.g. Annual Ngā Paerewa self-assessment"
                                        aria-invalid={!!err('title')}
                                    />
                                </Field>
                                <Field
                                    label="Reference"
                                    hint="clause or code (optional)"
                                    error={err('obligation_reference')}
                                >
                                    <Input
                                        value={data.obligation_reference}
                                        onChange={(e) =>
                                            set(
                                                'obligation_reference',
                                                e.target.value.slice(0, 50),
                                            )
                                        }
                                        placeholder="e.g. HSWA s36"
                                    />
                                </Field>
                            </div>
                        </div>
                    </WizardStepPane>
                ) : null}

                {cur.key === 'details' ? (
                    <WizardStepPane>
                        <StepHead
                            icon={ClipboardList}
                            title="What has to be done?"
                            blurb="Describe the requirement and what must be done or sent to meet it."
                        />
                        <div className="grid gap-4">
                            <Field
                                label="Description"
                                required
                                error={err('description')}
                            >
                                <Textarea
                                    rows={3}
                                    value={data.description}
                                    onChange={(e) =>
                                        set('description', e.target.value)
                                    }
                                    placeholder="What this requirement covers and why it matters."
                                    aria-invalid={!!err('description')}
                                />
                            </Field>
                            <Field
                                label="What must be done"
                                hint="optional"
                                error={err('requirements')}
                            >
                                <Textarea
                                    rows={3}
                                    value={data.requirements}
                                    onChange={(e) =>
                                        set('requirements', e.target.value)
                                    }
                                    placeholder="e.g. Board-approved self-assessment uploaded as evidence each year."
                                />
                            </Field>
                            {isEdit ? (
                                <Field
                                    label="Notes"
                                    hint="optional"
                                    error={err('notes')}
                                >
                                    <Textarea
                                        rows={3}
                                        value={data.notes}
                                        onChange={(e) =>
                                            set('notes', e.target.value)
                                        }
                                        placeholder="Anything the board or the owner should know."
                                    />
                                </Field>
                            ) : (
                                <InfoCard icon={FileText}>
                                    Evidence (documents, audit reports,
                                    certificates) is added from the requirement
                                    once it is saved.
                                </InfoCard>
                            )}
                        </div>
                    </WizardStepPane>
                ) : null}

                {cur.key === 'schedule' ? (
                    <WizardStepPane>
                        <StepHead
                            icon={CalendarClock}
                            title="When, who and how important"
                            blurb="When it's due, who is responsible and how much it matters."
                        />
                        <div className="grid gap-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <SubHead icon={CalendarClock}>Schedule</SubHead>
                                <Field
                                    label="How often"
                                    span
                                    error={err('frequency')}
                                >
                                    <Segmented
                                        value={data.frequency}
                                        onChange={(v) => set('frequency', v)}
                                        options={FREQUENCY_OPTIONS}
                                    />
                                </Field>
                                <Field
                                    label={isEdit ? 'Due date' : 'Next due date'}
                                    required={isEdit}
                                    hint={
                                        isEdit
                                            ? undefined
                                            : 'Leave blank to set it from how often it is due'
                                    }
                                    error={err('due_date')}
                                >
                                    <Input
                                        type="date"
                                        value={data.due_date}
                                        onChange={(e) =>
                                            set('due_date', e.target.value)
                                        }
                                    />
                                </Field>
                                <Field label="Priority" error={err('priority')}>
                                    <Segmented
                                        value={data.priority}
                                        onChange={(v) => set('priority', v)}
                                        options={PRIORITY_OPTIONS}
                                    />
                                </Field>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <SubHead icon={Users}>Responsibility</SubHead>
                                <Field
                                    label="Owner"
                                    span
                                    hint={
                                        isEdit
                                            ? 'the person responsible'
                                            : 'the person responsible — you, if left blank'
                                    }
                                    error={err('owner_id')}
                                >
                                    <SelectInput
                                        value={data.owner_id}
                                        onChange={(v) => set('owner_id', v)}
                                        placeholder="Choose an owner"
                                        ariaLabel="Owner"
                                        options={options.owners.map((o) => ({
                                            value: String(o.id),
                                            label: o.name,
                                        }))}
                                    />
                                </Field>
                                <div className="flex items-start gap-2.5 rounded-lg border border-border p-3 sm:col-span-2">
                                    <Checkbox
                                        id="obligation-evidence-required"
                                        checked={data.evidence_required}
                                        onCheckedChange={(v) =>
                                            set('evidence_required', v === true)
                                        }
                                    />
                                    <label
                                        htmlFor="obligation-evidence-required"
                                        className="text-sm"
                                    >
                                        <span className="block font-medium">
                                            Evidence needed to mark it done
                                        </span>
                                        <span className="text-caption">
                                            It can only be marked done once a
                                            file that hasn&apos;t expired is
                                            attached.
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </WizardStepPane>
                ) : null}

                {isReview ? (
                    <WizardStepPane>
                        <StepHead
                            icon={CheckCircle2}
                            title={isEdit ? 'Review changes' : 'Review and add'}
                            blurb="Check the requirement before saving it to the register."
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewCard
                                icon={ShieldCheck}
                                title="Requirement"
                                onEdit={() => goTo(0)}
                            >
                                <ReviewRow
                                    label="Comes from"
                                    value={frameworkLabel}
                                />
                                <ReviewRow label="Name" value={data.title} />
                                <ReviewRow
                                    label="Reference"
                                    value={data.obligation_reference}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={CalendarClock}
                                title="Schedule"
                                onEdit={() => goTo(2)}
                            >
                                <ReviewRow
                                    label="How often"
                                    value={optionLabel(
                                        FREQUENCY_OPTIONS,
                                        data.frequency,
                                    )}
                                />
                                <ReviewRow
                                    label="Due"
                                    value={
                                        data.due_date
                                            ? formatDateOnly(data.due_date)
                                            : 'Set from how often it is due'
                                    }
                                />
                                <ReviewRow
                                    label="Priority"
                                    value={optionLabel(
                                        PRIORITY_OPTIONS,
                                        data.priority,
                                    )}
                                />
                                <ReviewRow
                                    label="Owner"
                                    value={
                                        ownerName ??
                                        (isEdit ? undefined : 'You')
                                    }
                                />
                                <ReviewRow
                                    label="Evidence needed"
                                    value={data.evidence_required ? 'Yes' : 'No'}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={ClipboardList}
                                title="Details"
                                span
                                onEdit={() => goTo(1)}
                            >
                                <ReviewRow
                                    label="Description"
                                    value={data.description}
                                />
                                <ReviewRow
                                    label="What must be done"
                                    value={data.requirements}
                                />
                                {isEdit ? (
                                    <ReviewRow label="Notes" value={data.notes} />
                                ) : null}
                            </ReviewCard>
                        </div>
                    </WizardStepPane>
                ) : null}
            </WizardShell>

            <Dialog open={confirmClose} onOpenChange={setConfirmClose}>
                <DialogContent style={{ maxWidth: 'min(92vw, 480px)' }}>
                    <DialogHeader>
                        <DialogTitle>Discard this draft?</DialogTitle>
                        <DialogDescription>
                            {isEdit
                                ? 'Your unsaved changes to this requirement will be lost.'
                                : 'The details entered for this requirement will be lost.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirmClose(false)}
                        >
                            Keep editing
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                setConfirmClose(false);
                                onClose();
                            }}
                        >
                            Discard
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Upload evidence (simple dialog)                                    */
/* ------------------------------------------------------------------ */

const EVIDENCE_TYPES = [
    {
        key: 'document',
        description: 'A policy, filing or record.',
        icon: FileText,
    },
    {
        key: 'audit_report',
        description: 'Findings from an internal or external audit.',
        icon: ClipboardList,
    },
    {
        key: 'certification',
        description: 'A certificate or accreditation.',
        icon: Award,
    },
    {
        key: 'system_export',
        description: 'A report downloaded from a system.',
        icon: Database,
    },
    {
        key: 'attestation',
        description: 'A signed statement that the requirement is met.',
        icon: Signature,
    },
].map((type) => ({ ...type, label: complianceEvidenceTypeLabel(type.key) }));

export function UploadEvidenceDialog({
    open,
    onClose,
    obligationId,
}: {
    open: boolean;
    onClose: () => void;
    obligationId: number;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' }}
            >
                {open ? (
                    <UploadEvidenceBody
                        onClose={onClose}
                        obligationId={obligationId}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function UploadEvidenceBody({
    onClose,
    obligationId,
}: {
    onClose: () => void;
    obligationId: number;
}) {
    const form = useForm<{
        evidence_type: string;
        title: string;
        description: string;
        valid_until: string;
        file: File | null;
    }>({
        evidence_type: 'document',
        title: '',
        description: '',
        valid_until: '',
        file: null,
    });
    const [submitError, setSubmitError] = useState<string | null>(null);

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        setSubmitError(null);
        form.post(`/governance/compliance/${obligationId}/evidence`, {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                const error = flashError(page);
                if (error) {
                    setSubmitError(error);
                    return;
                }
                onClose();
            },
        });
    };

    return (
        <form onSubmit={handleSubmit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Upload className="h-4 w-4 text-primary" />
                    Upload evidence
                </DialogTitle>
                <DialogDescription>
                    Add a file showing this requirement is met — for example the
                    filing receipt or audit report. Files can be up to 10 MB.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <Label className="mb-1.5 block">What kind of evidence</Label>
                    <TilePicker
                        value={form.data.evidence_type}
                        onChange={(v) => form.setData('evidence_type', v)}
                        cols={3}
                        options={EVIDENCE_TYPES}
                    />
                    <FieldError message={form.errors.evidence_type} />
                </div>
                <div>
                    <Label htmlFor="evidence-title">
                        Name <span className="text-status-critical">*</span>
                    </Label>
                    <Input
                        id="evidence-title"
                        value={form.data.title}
                        maxLength={255}
                        onChange={(e) => form.setData('title', e.target.value)}
                        placeholder="e.g. 2026 annual return filing receipt"
                    />
                    <FieldError message={form.errors.title} />
                </div>
                <div>
                    <Label htmlFor="evidence-valid-until">
                        Valid until{' '}
                        <span className="text-xs font-normal text-muted-foreground">
                            optional
                        </span>
                    </Label>
                    <Input
                        id="evidence-valid-until"
                        type="date"
                        min={toDateInput(Date.now() + 86_400_000)}
                        value={form.data.valid_until}
                        onChange={(e) =>
                            form.setData('valid_until', e.target.value)
                        }
                    />
                    <FieldError message={form.errors.valid_until} />
                </div>
                <div className="sm:col-span-2">
                    <Label htmlFor="evidence-description">
                        Description{' '}
                        <span className="text-xs font-normal text-muted-foreground">
                            optional
                        </span>
                    </Label>
                    <Textarea
                        id="evidence-description"
                        rows={2}
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData('description', e.target.value)
                        }
                        placeholder="What this evidence shows."
                    />
                    <FieldError message={form.errors.description} />
                </div>
                <div className="sm:col-span-2">
                    <Label className="mb-1.5 block">
                        File <span className="text-status-critical">*</span>
                    </Label>
                    {form.data.file ? (
                        <StagedFileCard
                            file={form.data.file}
                            onRemove={() => form.setData('file', null)}
                        />
                    ) : (
                        <FileDropzone
                            multiple={false}
                            title="Drag and drop the evidence file"
                            hint="PDF, Word, Excel, PowerPoint, images, CSV or text · up to 10 MB"
                            onFiles={(files) =>
                                form.setData('file', files[0] ?? null)
                            }
                        />
                    )}
                    <FieldError message={form.errors.file} />
                </div>
            </div>

            {submitError ? (
                <p role="alert" className="mt-3 text-sm text-status-critical">
                    {submitError}
                </p>
            ) : null}

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        !form.data.file ||
                        !form.data.title.trim()
                    }
                >
                    {form.processing && (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    )}
                    Upload evidence
                </Button>
            </DialogFooter>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Mark as done (simple dialog)                                       */
/* ------------------------------------------------------------------ */

export interface EvidenceItem {
    id: number;
    evidence_type: string;
    title: string;
    valid_until: string | null;
    expired?: boolean;
    uploaded_by: { name: string } | null;
}

export function isEvidenceExpired(evidence: EvidenceItem): boolean {
    if (typeof evidence.expired === 'boolean') return evidence.expired;
    if (!evidence.valid_until) return false;
    return evidence.valid_until.slice(0, 10) < toDateInput(new Date());
}

export function CompleteObligationDialog({
    open,
    onClose,
    onUploadFirst,
    obligation,
    frameworkLabel,
}: {
    open: boolean;
    onClose: () => void;
    onUploadFirst?: () => void;
    obligation: {
        id: number;
        obligation_title: string;
        due_date: string | null;
        requirements?: string | null;
        completion_notes?: string | null;
        evidence_required: boolean;
        version_number?: number;
        evidence: EvidenceItem[];
    };
    frameworkLabel: string;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{ maxWidth: 'min(92vw, 720px)', width: 'min(92vw, 720px)' }}
            >
                {open ? (
                    <CompleteObligationBody
                        onClose={onClose}
                        onUploadFirst={onUploadFirst}
                        obligation={obligation}
                        frameworkLabel={frameworkLabel}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function CompleteObligationBody({
    onClose,
    onUploadFirst,
    obligation,
    frameworkLabel,
}: {
    onClose: () => void;
    onUploadFirst?: () => void;
    obligation: Parameters<typeof CompleteObligationDialog>[0]['obligation'];
    frameworkLabel: string;
}) {
    const evidenceItems = obligation.evidence ?? [];
    const validEvidence = evidenceItems.filter((ev) => !isEvidenceExpired(ev));
    const hasValidEvidence = validEvidence.length > 0;
    const canComplete = !obligation.evidence_required || hasValidEvidence;

    const [selectedIds, setSelectedIds] = useState<number[]>(() =>
        validEvidence.map((ev) => ev.id),
    );
    const [notes, setNotes] = useState(obligation.completion_notes ?? '');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const handleComplete = async () => {
        setSubmitting(true);
        setError(null);
        try {
            // axios (not an Inertia visit) so a 409 version conflict or a
            // validation message renders inline instead of an error page.
            await axios.post(`/governance/compliance/${obligation.id}/complete`, {
                evidence_ids: selectedIds.length > 0 ? selectedIds : undefined,
                completion_notes: notes || undefined,
                expected_version: obligation.version_number ?? 1,
            });
            onClose();
            router.reload();
        } catch (e: unknown) {
            const response = (
                e as {
                    response?: {
                        data?: {
                            message?: string;
                            errors?: Record<string, string[]>;
                        };
                    };
                }
            ).response;
            const firstError = response?.data?.errors
                ? Object.values(response.data.errors)[0]?.[0]
                : undefined;
            setError(
                firstError ??
                    response?.data?.message ??
                    "The requirement wasn't marked as done. Try again.",
            );
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <CheckCircle className="h-4 w-4 text-primary" />
                    Mark this requirement as done?
                </DialogTitle>
                <DialogDescription>
                    Record how it was met and the evidence that shows it. Once
                    done, the next one is scheduled if it repeats.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid gap-4">
                {error ? (
                    <InfoCard icon={AlertTriangle} tone="crit">
                        {error}
                    </InfoCard>
                ) : null}

                <div className="rounded-lg border border-border bg-muted/40 p-3 text-sm">
                    <p className="font-semibold text-foreground">
                        {obligation.obligation_title}
                    </p>
                    <p className="text-muted-foreground">
                        {frameworkLabel} · Due{' '}
                        {formatDateOnly(obligation.due_date?.slice(0, 10))}
                    </p>
                    {obligation.requirements ? (
                        <p className="text-caption mt-1 border-t border-border pt-1">
                            <span className="font-medium">What must be done:</span>{' '}
                            {obligation.requirements}
                        </p>
                    ) : null}
                </div>

                {obligation.evidence_required && !hasValidEvidence ? (
                    <InfoCard icon={AlertTriangle} tone="crit">
                        <p className="font-medium">Current evidence needed</p>
                        <p className="text-caption mt-0.5">
                            This requirement needs evidence that hasn&apos;t
                            expired before it can be marked done.
                        </p>
                        {onUploadFirst ? (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="mt-2"
                                onClick={onUploadFirst}
                            >
                                <Upload className="h-4 w-4" />
                                Upload evidence first
                            </Button>
                        ) : null}
                    </InfoCard>
                ) : null}

                <div>
                    <p className="text-caption font-semibold">
                        Evidence ({evidenceItems.length})
                    </p>
                    {evidenceItems.length > 0 ? (
                        <div className="scrollbar-pretty mt-2 flex max-h-48 flex-col gap-2 overflow-y-auto pr-1">
                            {evidenceItems.map((ev) => {
                                const expired = isEvidenceExpired(ev);
                                const checked = selectedIds.includes(ev.id);
                                return (
                                    <label
                                        key={ev.id}
                                        className={cn(
                                            'flex cursor-pointer items-start gap-3 rounded-lg border border-border p-3 transition-colors',
                                            expired
                                                ? 'cursor-not-allowed bg-muted/30 opacity-60'
                                                : 'hover:bg-muted/40',
                                            checked &&
                                                !expired &&
                                                'border-primary/50 bg-primary/5',
                                        )}
                                    >
                                        <Checkbox
                                            checked={checked}
                                            disabled={expired}
                                            onCheckedChange={(value) =>
                                                setSelectedIds((ids) =>
                                                    value
                                                        ? [...ids, ev.id]
                                                        : ids.filter(
                                                              (id) =>
                                                                  id !== ev.id,
                                                          ),
                                                )
                                            }
                                            className="mt-0.5"
                                        />
                                        <span className="flex-1 text-xs">
                                            <span className="flex items-center justify-between gap-2">
                                                <span className="font-medium text-foreground">
                                                    {ev.title}
                                                </span>
                                                {expired ? (
                                                    <StatusBadge
                                                        variant="critical"
                                                        size="sm"
                                                    >
                                                        Expired{' '}
                                                        {formatDateOnly(
                                                            ev.valid_until?.slice(
                                                                0,
                                                                10,
                                                            ),
                                                        )}
                                                    </StatusBadge>
                                                ) : ev.valid_until ? (
                                                    <StatusBadge
                                                        variant="success"
                                                        size="sm"
                                                    >
                                                        Valid until{' '}
                                                        {formatDateOnly(
                                                            ev.valid_until.slice(
                                                                0,
                                                                10,
                                                            ),
                                                        )}
                                                    </StatusBadge>
                                                ) : (
                                                    <StatusBadge
                                                        variant="neutral"
                                                        size="sm"
                                                    >
                                                        No expiry date
                                                    </StatusBadge>
                                                )}
                                            </span>
                                            <span className="mt-0.5 block text-muted-foreground">
                                                {complianceEvidenceTypeLabel(
                                                    ev.evidence_type,
                                                )}{' '}
                                                · Uploaded by{' '}
                                                {ev.uploaded_by?.name ||
                                                    'someone no longer listed'}
                                            </span>
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                    ) : (
                        <p className="text-caption mt-2">
                            No evidence uploaded yet.
                        </p>
                    )}
                </div>

                <div>
                    <Label htmlFor="completion-notes">How it was met</Label>
                    <Textarea
                        id="completion-notes"
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="How the requirement was met, any findings, or actions taken…"
                        className="mt-1"
                        rows={3}
                        maxLength={2000}
                    />
                </div>
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <Button
                    type="button"
                    onClick={handleComplete}
                    disabled={submitting || !canComplete}
                    data-dusk="submit-complete-obligation-button"
                >
                    {submitting ? (
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    ) : (
                        <FileCheck className="mr-2 h-4 w-4" />
                    )}
                    Mark as done
                </Button>
            </DialogFooter>
        </>
    );
}
