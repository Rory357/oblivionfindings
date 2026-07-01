/* Policy library wizards — New/Edit policy and Publish-new-version. Built on
 * the shared HR wizard kit (WizardShell + primitives) so they are visually
 * identical to the Asset / Leave-request modals. The create flow preserves the
 * full legacy form: title, category (existing or custom), attestation settings,
 * content mode (PDF only / PDF + summary), the PDF upload itself and the
 * effective-from date. Zero confirm(): destructive actions live in small
 * dialogs on the pages that own them. */
import { useForm } from '@inertiajs/react';
import {
    BookOpen,
    CheckCircle2,
    ClipboardCheck,
    FilePlus2,
    FileText,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import {
    Field,
    FieldErr,
    ReviewCard,
    ReviewRow,
    Segmented,
    SelectInput,
    StepHead,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/hr/wizard';
import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { fireConfetti } from '@/lib/confetti';

/* ------------------------------------------------------------------ */
/*  Public types                                                      */
/* ------------------------------------------------------------------ */

export interface EditablePolicy {
    id: number;
    title: string;
    category: string;
    is_active: boolean;
    requires_attestation: boolean;
    attestation_frequency_months: number | null;
}

export interface CategoryOption {
    value: string;
    label: string;
}

const MAX_PDF_BYTES = 8 * 1024 * 1024; // matches the 8MB server rule

const today = () => new Date().toISOString().slice(0, 10);

const FREQ_OPTS = [
    { value: '6', label: '6 months' },
    { value: '12', label: '12 months' },
    { value: '24', label: '24 months' },
    { value: '36', label: '36 months' },
];

/** Flash error carried by an Inertia redirect — `back()->with('error')` fires
 *  onSuccess, not onError (see reference_inertia_flash_error). */
function pageFlashError(page: { props: Record<string, unknown> }): string | null {
    const flash = page.props.flash as { error?: string } | undefined;
    return flash?.error ?? null;
}

/** Merge tenant-created categories with the seed list into select options. */
export function buildCategoryOptions(
    existing: string[],
    defaults: CategoryOption[],
): CategoryOption[] {
    const all = [...new Set([...existing, ...defaults.map((c) => c.value)])];
    return all
        .map((value) => ({
            value,
            label:
                defaults.find((c) => c.value === value)?.label ??
                value.replace(/_/g, ' '),
        }))
        .sort((a, b) => a.label.localeCompare(b.label));
}

/** Client-side guard mirroring the server's pdf/8MB rules. */
function acceptPdf(files: File[]): File | null {
    const file = files[0];
    if (!file) return null;
    if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
        toast.error('Please choose a PDF document.');
        return null;
    }
    if (file.size > MAX_PDF_BYTES) {
        toast.error('The PDF must be 8MB or smaller.');
        return null;
    }
    return file;
}

/* ================================================================== */
/*  New / Edit policy                                                 */
/* ================================================================== */

const CREATE_STEPS: readonly WizardStep[] = [
    { key: 'details', label: 'Details', blurb: 'Title & category', icon: BookOpen },
    { key: 'attestation', label: 'Attestation', blurb: 'Sign-off rules', icon: ShieldCheck },
    { key: 'document', label: 'Document', blurb: 'PDF & summary', icon: FileText },
    { key: 'review', label: 'Review', blurb: 'Confirm & create', icon: CheckCircle2 },
];

const EDIT_STEPS: readonly WizardStep[] = [
    { key: 'details', label: 'Details', blurb: 'Title & category', icon: BookOpen },
    { key: 'attestation', label: 'Attestation', blurb: 'Sign-off rules', icon: ShieldCheck },
    { key: 'review', label: 'Review', blurb: 'Confirm & save', icon: CheckCircle2 },
];

export function PolicyWizard({
    policy,
    categoryOptions,
    onClose,
}: {
    /** null = create mode; a policy = edit mode. */
    policy: EditablePolicy | null;
    categoryOptions: CategoryOption[];
    onClose: () => void;
}) {
    const isEdit = policy !== null;
    const steps = isEdit ? EDIT_STEPS : CREATE_STEPS;
    const wizard = useWizard(steps.length);
    const [done, setDone] = useState(false);

    const knownCategory =
        !isEdit || categoryOptions.some((c) => c.value === policy!.category);
    const [categoryMode, setCategoryMode] = useState<'existing' | 'custom'>(
        knownCategory ? 'existing' : 'custom',
    );

    const form = useForm({
        title: policy?.title ?? '',
        category: knownCategory ? (policy?.category ?? '') : '',
        custom_category: knownCategory ? '' : (policy?.category ?? ''),
        is_active: policy?.is_active ?? true,
        requires_attestation: policy?.requires_attestation ?? false,
        attestation_frequency_months: String(policy?.attestation_frequency_months ?? 12),
        content_mode: 'pdf_only' as 'pdf_only' | 'pdf_and_summary',
        content_summary: '',
        effective_from: today(),
        document: null as File | null,
    });

    const effectiveCategory =
        categoryMode === 'custom' ? form.data.custom_category.trim() : form.data.category;
    const categoryLabel =
        categoryOptions.find((c) => c.value === effectiveCategory)?.label ??
        effectiveCategory.replace(/_/g, ' ');

    const detailsValid = form.data.title.trim() !== '' && effectiveCategory !== '';
    const documentValid =
        isEdit ||
        (form.data.document !== null &&
            (form.data.content_mode === 'pdf_only' ||
                form.data.content_summary.trim() !== ''));
    const canSubmit = detailsValid && documentValid;

    const reviewIndex = steps.length - 1;
    const documentIndex = 2; // create mode only

    const submit = () => {
        const onResult = (page: { props: Record<string, unknown> }) => {
            const err = pageFlashError(page);
            if (err) {
                toast.error(err);
                return;
            }
            setDone(true);
            if (!isEdit) fireConfetti();
        };
        form.transform((data) => {
            const payload: Record<string, unknown> = {
                title: data.title,
                category:
                    categoryMode === 'custom'
                        ? data.custom_category.trim()
                        : data.category,
                requires_attestation: data.requires_attestation,
                attestation_frequency_months: data.requires_attestation
                    ? data.attestation_frequency_months
                    : null,
            };
            if (isEdit) {
                payload.is_active = data.is_active;
                return payload;
            }
            payload.content_mode = data.content_mode;
            payload.content_summary =
                data.content_mode === 'pdf_and_summary' ? data.content_summary : '';
            payload.effective_from = data.effective_from;
            payload.document = data.document;
            return payload;
        });
        const opts = { preserveScroll: true, onSuccess: onResult } as const;
        if (isEdit) form.put(`/hr/documents/policies/${policy!.id}`, opts);
        else form.post('/hr/documents/policies', { ...opts, forceFormData: true });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title={isEdit ? 'Edit policy' : 'New policy'}
            description={
                isEdit
                    ? 'Update the policy details and attestation rules.'
                    : 'Add a policy to the library with its published PDF.'
            }
            railIcon={BookOpen}
            railTitle={isEdit ? 'Edit policy' : 'New policy'}
            railSub="Policy library"
            steps={steps}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title={isEdit ? 'Policy updated' : 'Policy created'}
                        blurb={
                            <>
                                “{form.data.title || 'The policy'}”{' '}
                                {isEdit
                                    ? 'has been updated.'
                                    : 'is published as version 1 and live in the library.'}
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button onClick={submit} disabled={form.processing || !canSubmit}>
                            {form.processing
                                ? form.progress
                                    ? `Uploading… ${form.progress.percentage ?? 0}%`
                                    : 'Saving…'
                                : isEdit
                                  ? 'Save changes'
                                  : 'Create policy'}
                        </Button>
                    ) : (
                        <Button
                            onClick={wizard.next}
                            disabled={
                                (wizard.index === 0 && !detailsValid) ||
                                (!isEdit && wizard.index === documentIndex && !documentValid)
                            }
                        >
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={BookOpen}
                        title="Policy details"
                        blurb="What the policy is called and where it sits in the library."
                    />
                    <Field label="Policy title" required error={form.errors.title}>
                        <Input
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                            placeholder="e.g. Staff Code of Conduct"
                        />
                    </Field>
                    <div className="mt-4">
                        <Field label="Category" required>
                            <Segmented
                                value={categoryMode}
                                onChange={setCategoryMode}
                                options={[
                                    { value: 'existing', label: 'Pick a category' },
                                    { value: 'custom', label: 'New category' },
                                ]}
                            />
                        </Field>
                    </div>
                    <div className="mt-3">
                        {categoryMode === 'existing' ? (
                            <SelectInput
                                value={form.data.category}
                                onChange={(v) => form.setData('category', v)}
                                placeholder="Select a category"
                                options={categoryOptions}
                                ariaLabel="Policy category"
                            />
                        ) : (
                            <Input
                                value={form.data.custom_category}
                                onChange={(e) =>
                                    form.setData('custom_category', e.target.value)
                                }
                                placeholder="e.g. Vehicle use"
                            />
                        )}
                        <FieldErr>{form.errors.category}</FieldErr>
                    </div>
                    {isEdit ? (
                        <label className="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-card p-4">
                            <input
                                type="checkbox"
                                checked={form.data.is_active}
                                onChange={(e) => form.setData('is_active', e.target.checked)}
                                className="mt-0.5 h-4 w-4 accent-[var(--primary)]"
                            />
                            <span>
                                <span className="block text-[13px] font-semibold">
                                    Policy is active
                                </span>
                                <span className="block text-[12.5px] text-muted-foreground">
                                    Inactive policies stay on record but drop out of the
                                    default library view and stop prompting attestation.
                                </span>
                            </span>
                        </label>
                    ) : null}
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={ShieldCheck}
                        title="Attestation"
                        blurb="Whether staff must acknowledge they have read and understood it."
                    />
                    <label className="flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-card p-4">
                        <input
                            type="checkbox"
                            checked={form.data.requires_attestation}
                            onChange={(e) =>
                                form.setData('requires_attestation', e.target.checked)
                            }
                            className="mt-0.5 h-4 w-4 accent-[var(--primary)]"
                        />
                        <span>
                            <span className="block text-[13px] font-semibold">
                                Requires staff attestation
                            </span>
                            <span className="block text-[12.5px] text-muted-foreground">
                                Staff will be asked to attest that they have read and
                                understood this policy, with each sign-off audit-logged.
                            </span>
                        </span>
                    </label>
                    {form.data.requires_attestation ? (
                        <div className="mt-4">
                            <Field
                                label="Re-attestation frequency"
                                hint="how often staff must re-attest"
                                error={form.errors.attestation_frequency_months}
                            >
                                <Segmented
                                    value={form.data.attestation_frequency_months}
                                    onChange={(v) =>
                                        form.setData('attestation_frequency_months', v)
                                    }
                                    options={FREQ_OPTS}
                                />
                            </Field>
                        </div>
                    ) : null}
                </WizardStepPane>
            )}

            {!isEdit && wizard.index === documentIndex && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="Policy document"
                        blurb="Upload the published PDF — this becomes version 1."
                    />
                    <Field label="Content format">
                        <Segmented
                            value={form.data.content_mode}
                            onChange={(v) => form.setData('content_mode', v)}
                            options={[
                                { value: 'pdf_only', label: 'PDF only' },
                                { value: 'pdf_and_summary', label: 'PDF + summary' },
                            ]}
                        />
                    </Field>
                    <div className="mt-4">
                        <Field label="PDF document" required error={form.errors.document}>
                            {form.data.document ? (
                                <StagedFileCard
                                    file={form.data.document}
                                    onRemove={() => form.setData('document', null)}
                                />
                            ) : (
                                <FileDropzone
                                    onFiles={(files) => {
                                        const file = acceptPdf(files);
                                        if (file) form.setData('document', file);
                                    }}
                                    accept=".pdf,application/pdf"
                                    multiple={false}
                                    title="Drag & drop the policy PDF here"
                                    hint="PDF only, up to 8MB"
                                />
                            )}
                        </Field>
                    </div>
                    {form.data.content_mode === 'pdf_and_summary' ? (
                        <div className="mt-4">
                            <Field
                                label="Content summary"
                                required
                                hint="shown alongside the PDF for quick reference"
                                error={form.errors.content_summary}
                            >
                                <Textarea
                                    rows={5}
                                    value={form.data.content_summary}
                                    onChange={(e) =>
                                        form.setData('content_summary', e.target.value)
                                    }
                                    placeholder="Brief summary of the policy content…"
                                />
                            </Field>
                        </div>
                    ) : null}
                    <div className="mt-4">
                        <Field label="Effective from" error={form.errors.effective_from}>
                            <Input
                                type="date"
                                value={form.data.effective_from}
                                onChange={(e) =>
                                    form.setData('effective_from', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === reviewIndex && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title={isEdit ? 'Review the changes' : 'Review the policy'}
                        blurb="Check the details, then confirm below."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={BookOpen} title="Details" onEdit={() => wizard.goTo(0)}>
                            <ReviewRow label="Title" value={form.data.title || undefined} />
                            <ReviewRow label="Category" value={categoryLabel || undefined} />
                            {isEdit ? (
                                <ReviewRow
                                    label="Status"
                                    value={form.data.is_active ? 'Active' : 'Inactive'}
                                />
                            ) : null}
                        </ReviewCard>
                        <ReviewCard
                            icon={ShieldCheck}
                            title="Attestation"
                            onEdit={() => wizard.goTo(1)}
                        >
                            <ReviewRow
                                label="Required"
                                value={form.data.requires_attestation ? 'Yes' : 'No'}
                            />
                            {form.data.requires_attestation ? (
                                <ReviewRow
                                    label="Frequency"
                                    value={`Every ${form.data.attestation_frequency_months} months`}
                                />
                            ) : null}
                        </ReviewCard>
                        {!isEdit ? (
                            <ReviewCard
                                icon={FileText}
                                title="Document"
                                onEdit={() => wizard.goTo(documentIndex)}
                                span
                            >
                                <ReviewRow
                                    label="PDF"
                                    value={form.data.document?.name}
                                />
                                <ReviewRow
                                    label="Summary"
                                    value={
                                        form.data.content_mode === 'pdf_and_summary'
                                            ? 'Included'
                                            : 'PDF only'
                                    }
                                />
                                <ReviewRow
                                    label="Effective from"
                                    value={form.data.effective_from || undefined}
                                />
                            </ReviewCard>
                        ) : null}
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Publish a new version                                             */
/* ================================================================== */

const VERSION_STEPS: readonly WizardStep[] = [
    { key: 'document', label: 'New version', blurb: 'PDF, summary & date', icon: FilePlus2 },
    { key: 'review', label: 'Review', blurb: 'Confirm & publish', icon: CheckCircle2 },
];

export function NewVersionWizard({
    policyId,
    policyTitle,
    nextVersion,
    onClose,
}: {
    policyId: number;
    policyTitle: string;
    /** The version number this publish will create (latest + 1). */
    nextVersion: number;
    onClose: () => void;
}) {
    const wizard = useWizard(VERSION_STEPS.length);
    const [done, setDone] = useState(false);

    const form = useForm({
        document: null as File | null,
        content_summary: '',
        effective_from: today(),
    });

    const submit = () => {
        form.post(`/hr/documents/policies/${policyId}/versions`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
                fireConfetti();
            },
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Publish new version"
            description={`Publish a new version of ${policyTitle}.`}
            railIcon={FilePlus2}
            railTitle="New version"
            railSub={`Will publish as v${nextVersion}`}
            steps={VERSION_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Version published"
                        blurb={
                            <>
                                v{nextVersion} of “{policyTitle}” is now the current
                                version.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button
                            onClick={submit}
                            disabled={form.processing || !form.data.document}
                        >
                            {form.processing
                                ? form.progress
                                    ? `Uploading… ${form.progress.percentage ?? 0}%`
                                    : 'Publishing…'
                                : 'Publish version'}
                        </Button>
                    ) : (
                        <Button onClick={wizard.next} disabled={!form.data.document}>
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={FilePlus2}
                        title="New version"
                        blurb="Upload the updated PDF — earlier versions stay on record."
                    />
                    <Field label="PDF document" required error={form.errors.document}>
                        {form.data.document ? (
                            <StagedFileCard
                                file={form.data.document}
                                onRemove={() => form.setData('document', null)}
                            />
                        ) : (
                            <FileDropzone
                                onFiles={(files) => {
                                    const file = acceptPdf(files);
                                    if (file) form.setData('document', file);
                                }}
                                accept=".pdf,application/pdf"
                                multiple={false}
                                title="Drag & drop the updated PDF here"
                                hint="PDF only, up to 8MB"
                            />
                        )}
                    </Field>
                    <div className="mt-4">
                        <Field
                            label="What changed"
                            hint="optional"
                            error={form.errors.content_summary}
                        >
                            <Textarea
                                rows={3}
                                value={form.data.content_summary}
                                onChange={(e) =>
                                    form.setData('content_summary', e.target.value)
                                }
                                placeholder="Brief summary of changes in this version…"
                            />
                        </Field>
                    </div>
                    <div className="mt-4">
                        <Field label="Effective from" required error={form.errors.effective_from}>
                            <Input
                                type="date"
                                value={form.data.effective_from}
                                onChange={(e) =>
                                    form.setData('effective_from', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Confirm the publish"
                        blurb="Check the details, then publish."
                    />
                    <ReviewCard icon={FilePlus2} title="New version" onEdit={() => wizard.goTo(0)} span>
                        <ReviewRow label="Policy" value={policyTitle} />
                        <ReviewRow label="Publishes as" value={`v${nextVersion}`} />
                        <ReviewRow label="PDF" value={form.data.document?.name} />
                        <ReviewRow
                            label="Changes"
                            value={form.data.content_summary || undefined}
                        />
                        <ReviewRow label="Effective from" value={form.data.effective_from} />
                    </ReviewCard>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}
