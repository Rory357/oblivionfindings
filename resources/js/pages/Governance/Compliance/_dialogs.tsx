/**
 * Governance compliance dialogs (design_styles/POPUP_STYLE_GUIDE.md):
 *
 *   - ObligationWizardDialog — the entity add/edit wizard (WizardShell). Add
 *     opens from the register header, Edit from the obligation record; both
 *     use the SAME steps, prefilled on edit.
 *   - UploadEvidenceDialog / CompleteObligationDialog — simple dialogs opened
 *     from the obligation record.
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
import { cn } from '@/lib/utils';
import { complete as completeObligation, store as storeObligation } from '@/routes/governance/compliance';
import { upload as uploadEvidence } from '@/routes/governance/compliance/evidence';
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
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'annual', label: 'Annual' },
    { value: 'ad_hoc', label: 'Ad hoc' },
    { value: 'event_driven', label: 'Event driven' },
];

export const PRIORITY_OPTIONS = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Critical' },
];

/** Framework → icon for tile pickers and list marks (cosmetic grouping). */
export function frameworkIcon(value: string): LucideIcon {
    if (value.startsWith('funding')) return Banknote;
    if (value === 'hswa' || value === 'hdsa_safety') return HeartPulse;
    if (value === 'privacy_act' || value === 'hip_code') return ShieldCheck;
    if (value === 'employment') return Users;
    if (value === 'charities') return Scale;
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
/*  Obligation wizard (add + edit)                                     */
/* ------------------------------------------------------------------ */

export interface ObligationRecord {
    id: number;
    framework: string;
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
};

const OBLIGATION_STEPS: readonly WizardStep[] = [
    {
        key: 'obligation',
        label: 'Obligation',
        blurb: 'Framework, title & reference',
        icon: ShieldCheck,
    },
    {
        key: 'details',
        label: 'Details',
        blurb: 'What it requires',
        icon: ClipboardList,
    },
    {
        key: 'schedule',
        label: 'Schedule',
        blurb: 'Due date, owner & priority',
        icon: CalendarClock,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm before saving',
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
    };
}

function validateObligationStep(
    index: number,
    data: ObligationWizardForm,
    isEdit: boolean,
): Record<string, string> {
    const e: Record<string, string> = {};
    if (index === 0) {
        if (!isEdit && !data.framework) e.framework = 'Choose a framework';
        if (!data.title.trim()) e.title = 'A title is required';
        else if (data.title.length > 255)
            e.title = 'Keep the title to 255 characters or fewer';
        if (data.obligation_reference.length > 50)
            e.obligation_reference = 'Reference must be 50 characters or fewer';
    }
    if (index === 1) {
        if (!data.description.trim())
            e.description = 'Describe the obligation';
    }
    return e;
}

export interface ObligationWizardDialogProps {
    open: boolean;
    onClose: () => void;
    options: ObligationFormOptions;
    /** When set the wizard edits this obligation; otherwise it creates one. */
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

    const set = (key: keyof ObligationWizardForm, value: string) =>
        setData((prev) => ({ ...prev, [key]: value }));
    const err = (name: string): string | undefined =>
        errors[name] ??
        (form.errors as Record<string, string>)[name] ??
        (name === 'title'
            ? (form.errors as Record<string, string>).obligation_title
            : undefined);

    const cur = OBLIGATION_STEPS[stepIndex];
    const isReview = cur.key === 'review';

    const frameworkLabel =
        optionLabel(options.frameworks, data.framework) ?? data.framework;
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
            // ComplianceController::update accepts title, description,
            // due_date, owner_id and notes — framework, reference,
            // requirements, frequency and priority are fixed at creation.
            form.transform((current) => {
                const payload: Record<string, unknown> = {
                    title: current.title,
                    description: current.description,
                    notes: current.notes,
                };
                if (current.due_date) payload.due_date = current.due_date;
                if (current.owner_id && current.owner_id !== initial.owner_id) {
                    payload.owner_id = Number(current.owner_id);
                }
                return payload as ObligationWizardForm;
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
                    due_date: current.due_date,
                    priority: current.priority,
                    owner_id: current.owner_id
                        ? Number(current.owner_id)
                        : null,
                }) as unknown as ObligationWizardForm,
        );
        form.post(storeObligation.url(), visit);
    };

    const lockedHint = isEdit ? 'set at creation' : undefined;

    const success = done ? (
        <WizardSuccessPane
            title={isEdit ? 'Obligation updated' : 'Obligation added'}
            blurb={
                isEdit ? (
                    <>
                        <strong>{data.title}</strong> has been updated.
                    </>
                ) : (
                    <>
                        <strong>{data.title}</strong> ({frameworkLabel}) is now
                        tracked on the compliance register. Reminders have been
                        scheduled for its owner.
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
                title={
                    isEdit
                        ? 'Edit compliance obligation'
                        : 'Add compliance obligation'
                }
                description={
                    isEdit
                        ? 'Update the obligation title, description, due date, owner and notes.'
                        : 'A guided wizard to register a regulatory or framework obligation.'
                }
                railIcon={ShieldCheck}
                railTitle={isEdit ? 'Edit obligation' : 'New obligation'}
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
                                {isEdit ? 'Save changes' : 'Create obligation'}
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
                            title="Which obligation?"
                            blurb="Pick the regulatory framework and name the obligation you're tracking."
                        />
                        <div className="grid gap-4">
                            <Field
                                label="Framework"
                                required={!isEdit}
                                hint={lockedHint}
                                error={err('framework')}
                            >
                                {isEdit ? (
                                    <InfoCard
                                        icon={frameworkIcon(data.framework)}
                                    >
                                        <strong>{frameworkLabel}</strong> — the
                                        framework is fixed once an obligation
                                        is registered.
                                    </InfoCard>
                                ) : (
                                    <TilePicker
                                        value={data.framework}
                                        onChange={(v) => set('framework', v)}
                                        cols={2}
                                        options={options.frameworks.map(
                                            (f) => ({
                                                key: f.value,
                                                label: f.label,
                                                icon: frameworkIcon(f.value),
                                            }),
                                        )}
                                    />
                                )}
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Title"
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
                                    hint={lockedHint ?? 'clause / code (optional)'}
                                    error={err('obligation_reference')}
                                >
                                    {isEdit ? (
                                        <p className="text-sm text-muted-foreground">
                                            {data.obligation_reference || '—'}
                                        </p>
                                    ) : (
                                        <Input
                                            value={data.obligation_reference}
                                            onChange={(e) =>
                                                set(
                                                    'obligation_reference',
                                                    e.target.value.slice(0, 50),
                                                )
                                            }
                                            placeholder="e.g. HSWA-2015-SEC-36"
                                        />
                                    )}
                                </Field>
                            </div>
                        </div>
                    </WizardStepPane>
                ) : null}

                {cur.key === 'details' ? (
                    <WizardStepPane>
                        <StepHead
                            icon={ClipboardList}
                            title="What does it require?"
                            blurb="Describe the obligation and what must be done to comply."
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
                                    placeholder="What this obligation covers and why it matters."
                                    aria-invalid={!!err('description')}
                                />
                            </Field>
                            <Field
                                label="Requirements"
                                hint={
                                    lockedHint ??
                                    'what must be done / submitted (optional)'
                                }
                                error={err('requirements')}
                            >
                                {isEdit ? (
                                    <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                        {data.requirements || '—'}
                                    </p>
                                ) : (
                                    <Textarea
                                        rows={3}
                                        value={data.requirements}
                                        onChange={(e) =>
                                            set('requirements', e.target.value)
                                        }
                                        placeholder="e.g. Board-approved self-assessment uploaded as evidence each year."
                                    />
                                )}
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
                                        placeholder="Context for the board or the obligation owner."
                                    />
                                </Field>
                            ) : (
                                <InfoCard icon={FileText}>
                                    Evidence (documents, audit reports,
                                    attestations) is attached after creation
                                    from the obligation record — each
                                    obligation keeps its own evidence trail.
                                </InfoCard>
                            )}
                        </div>
                    </WizardStepPane>
                ) : null}

                {cur.key === 'schedule' ? (
                    <WizardStepPane>
                        <StepHead
                            icon={CalendarClock}
                            title="Due date, owner & priority"
                            blurb="When it's due, who is accountable, and how much it matters."
                        />
                        <div className="grid gap-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <SubHead icon={CalendarClock}>Schedule</SubHead>
                                <Field
                                    label="Frequency"
                                    hint={lockedHint}
                                    span
                                    error={err('frequency')}
                                >
                                    {isEdit ? (
                                        <p className="text-sm text-muted-foreground">
                                            {optionLabel(
                                                FREQUENCY_OPTIONS,
                                                data.frequency,
                                            ) ?? '—'}
                                        </p>
                                    ) : (
                                        <Segmented
                                            value={data.frequency}
                                            onChange={(v) =>
                                                set('frequency', v)
                                            }
                                            options={FREQUENCY_OPTIONS}
                                        />
                                    )}
                                </Field>
                                <Field
                                    label={isEdit ? 'Due date' : 'Next due date'}
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
                                <Field
                                    label="Priority"
                                    hint={lockedHint}
                                    error={err('priority')}
                                >
                                    {isEdit ? (
                                        <p className="text-sm text-muted-foreground">
                                            {optionLabel(
                                                PRIORITY_OPTIONS,
                                                data.priority,
                                            ) ?? '—'}
                                        </p>
                                    ) : (
                                        <Segmented
                                            value={data.priority}
                                            onChange={(v) => set('priority', v)}
                                            options={PRIORITY_OPTIONS}
                                        />
                                    )}
                                </Field>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <SubHead icon={Users}>Ownership</SubHead>
                                <Field
                                    label="Owner"
                                    span
                                    hint={
                                        isEdit
                                            ? 'accountable person'
                                            : 'accountable person — defaults to you'
                                    }
                                    error={err('owner_id')}
                                >
                                    <SelectInput
                                        value={data.owner_id}
                                        onChange={(v) => set('owner_id', v)}
                                        placeholder="Assign an owner"
                                        ariaLabel="Owner"
                                        options={options.owners.map((o) => ({
                                            value: String(o.id),
                                            label: o.name,
                                        }))}
                                    />
                                </Field>
                            </div>
                        </div>
                    </WizardStepPane>
                ) : null}

                {isReview ? (
                    <WizardStepPane>
                        <StepHead
                            icon={CheckCircle2}
                            title={
                                isEdit ? 'Review changes' : 'Review & create'
                            }
                            blurb="Confirm the obligation before saving it to the register."
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewCard
                                icon={ShieldCheck}
                                title="Obligation"
                                onEdit={() => goTo(0)}
                            >
                                <ReviewRow
                                    label="Framework"
                                    value={frameworkLabel}
                                />
                                <ReviewRow label="Title" value={data.title} />
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
                                    label="Frequency"
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
                                            : undefined
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
                                    label="Requirements"
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
                                ? 'Your unsaved changes to this obligation will be lost.'
                                : 'The details entered for this obligation will be lost.'}
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
        label: 'Document',
        description: 'Policy, filing or record.',
        icon: FileText,
    },
    {
        key: 'audit_report',
        label: 'Audit report',
        description: 'Internal or external audit findings.',
        icon: ClipboardList,
    },
    {
        key: 'certification',
        label: 'Certification',
        description: 'Certificate or accreditation.',
        icon: Award,
    },
    {
        key: 'system_export',
        label: 'System export',
        description: 'Report exported from a system.',
        icon: Database,
    },
    {
        key: 'attestation',
        label: 'Attestation',
        description: 'Signed statement of compliance.',
        icon: Signature,
    },
];

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
        form.post(uploadEvidence.url({ obligation: obligationId }), {
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
                    Attach proof that this obligation has been met. Files up to
                    10 MB.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <Label className="mb-1.5 block">Evidence type</Label>
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
                        Title <span className="text-status-critical">*</span>
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
                            title="Drag & drop the evidence file"
                            hint="PDF, Word, spreadsheets, images · max 10 MB"
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
/*  Complete obligation (simple dialog)                                */
/* ------------------------------------------------------------------ */

export interface EvidenceItem {
    id: number;
    evidence_type: string;
    title: string;
    valid_until: string | null;
    uploaded_by: { name: string } | null;
}

export function isEvidenceExpired(evidence: EvidenceItem): boolean {
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
            await axios.post(
                completeObligation.url({ obligation: obligation.id }),
                {
                    evidence_ids: selectedIds.length > 0 ? selectedIds : undefined,
                    completion_notes: notes || undefined,
                    expected_version: obligation.version_number ?? 1,
                },
            );
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
                    'Failed to mark obligation complete.',
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
                    Complete compliance obligation
                </DialogTitle>
                <DialogDescription>
                    Record how this obligation was fulfilled and the evidence
                    that supports it.
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
                            <span className="font-medium">Requirements:</span>{' '}
                            {obligation.requirements}
                        </p>
                    ) : null}
                </div>

                {obligation.evidence_required && !hasValidEvidence ? (
                    <InfoCard icon={AlertTriangle} tone="crit">
                        <p className="font-medium">Valid evidence required</p>
                        <p className="text-caption mt-0.5">
                            Evidence is mandatory for this obligation, but no
                            active, unexpired evidence is attached.
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
                    <p className="text-caption font-semibold uppercase">
                        Attached evidence ({evidenceItems.length})
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
                                                        Active
                                                    </StatusBadge>
                                                )}
                                            </span>
                                            <span className="mt-0.5 block text-muted-foreground capitalize">
                                                {ev.evidence_type.replace(
                                                    /_/g,
                                                    ' ',
                                                )}{' '}
                                                · Uploaded by{' '}
                                                {ev.uploaded_by?.name ||
                                                    'Unknown'}
                                            </span>
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                    ) : (
                        <p className="text-caption mt-2">
                            No evidence files uploaded yet.
                        </p>
                    )}
                </div>

                <div>
                    <Label htmlFor="completion-notes">Completion notes</Label>
                    <Textarea
                        id="completion-notes"
                        value={notes}
                        onChange={(e) => setNotes(e.target.value)}
                        placeholder="How this obligation was fulfilled, relevant findings, or actions taken…"
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
                    Complete obligation
                </Button>
            </DialogFooter>
        </>
    );
}
