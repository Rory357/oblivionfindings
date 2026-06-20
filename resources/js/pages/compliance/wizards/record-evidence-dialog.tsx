import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import {
    Field,
    FieldErr,
    InfoCard,
    SelectInput,
    StepHead,
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
import { useForm } from '@inertiajs/react';
import {
    Award,
    BadgeCheck,
    Check,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Database,
    FileCheck2,
    FileText,
    FileUp,
    Image as ImageIcon,
    Loader2,
    Paperclip,
    Plus,
    ShieldCheck,
    Trash2,
    UploadCloud,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type DragEvent } from 'react';
import { toast } from 'sonner';

type ObligationOption = { id: number; title: string; framework: string; due_date?: string | null };

const MAX_BYTES = 10 * 1024 * 1024; // mirrors server file|max:10240
const ACCEPT = '.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.png,.jpg,.jpeg';

const EVIDENCE_TYPES = [
    { key: 'document', label: 'Document', description: 'Policy, record or report', icon: FileText },
    { key: 'audit_report', label: 'Audit report', description: 'Internal / external audit', icon: FileCheck2 },
    { key: 'certification', label: 'Certification', description: 'Certificate or accreditation', icon: Award },
    { key: 'system_export', label: 'System export', description: 'Generated data export', icon: Database },
    { key: 'attestation', label: 'Attestation', description: 'Signed declaration', icon: BadgeCheck },
];

export type RecordEvidenceForm = {
    _modal: boolean;
    evidence_type: string;
    title: string;
    description: string;
    file: File | null;
    valid_until: string;
};

const STEPS: readonly WizardStep[] = [
    { key: 'obligation', label: 'Obligation', blurb: 'Where it attaches', icon: ShieldCheck },
    { key: 'document', label: 'Document', blurb: 'Upload & describe', icon: FileUp },
    { key: 'review', label: 'Review & save', blurb: 'Confirm and attach', icon: CheckCircle2 },
];

function emptyForm(): RecordEvidenceForm {
    return {
        _modal: true,
        evidence_type: 'document',
        title: '',
        description: '',
        file: null,
        valid_until: '',
    };
}

function humanSize(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

function isImage(file: File): boolean {
    return file.type.startsWith('image/');
}

/** Premium drag-and-drop document picker: type + size guard, preview, remove. */
function DocumentDropzone({
    file,
    error,
    onPick,
    onClear,
}: {
    file: File | null;
    error?: string;
    onPick: (file: File | null) => void;
    onClear: () => void;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);
    const preview = useMemo(
        () => (file && isImage(file) ? URL.createObjectURL(file) : null),
        [file],
    );
    // Free the blob URL when the preview changes or the dropzone unmounts.
    useEffect(() => () => {
        if (preview) URL.revokeObjectURL(preview);
    }, [preview]);

    const accept = (f?: File | null) => {
        if (!f) return;
        if (f.size > MAX_BYTES) {
            setLocalError(`That file is ${humanSize(f.size)} — the limit is 10 MB.`);
            return;
        }
        setLocalError(null);
        onPick(f);
    };

    const onDrop = (e: DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        setDragging(false);
        accept(e.dataTransfer.files?.[0]);
    };

    if (file) {
        const FileIcon = isImage(file) ? ImageIcon : FileText;
        return (
            <div className="flex items-center gap-3 rounded-xl border border-border bg-card/70 p-3">
                <span className="grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-lg bg-primary/10 text-primary">
                    {preview ? (
                        <img src={preview} alt="" className="h-full w-full object-cover" />
                    ) : (
                        <FileIcon className="h-6 w-6" />
                    )}
                </span>
                <div className="min-w-0 flex-1">
                    <div className="truncate text-sm font-semibold">{file.name}</div>
                    <div className="text-xs text-muted-foreground">{humanSize(file.size)}</div>
                </div>
                <div className="flex items-center gap-1.5">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => inputRef.current?.click()}
                    >
                        Replace
                    </Button>
                    <button
                        type="button"
                        onClick={() => {
                            onClear();
                            if (inputRef.current) inputRef.current.value = '';
                        }}
                        aria-label="Remove file"
                        className="grid h-8 w-8 place-items-center rounded-md text-muted-foreground hover:bg-status-critical-bg hover:text-status-critical"
                    >
                        <Trash2 className="h-4 w-4" />
                    </button>
                </div>
                <input
                    ref={inputRef}
                    type="file"
                    accept={ACCEPT}
                    className="hidden"
                    onChange={(e) => accept(e.target.files?.[0])}
                />
            </div>
        );
    }

    return (
        <div>
            <div
                role="button"
                tabIndex={0}
                onClick={() => inputRef.current?.click()}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        inputRef.current?.click();
                    }
                }}
                onDragOver={(e) => {
                    e.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={onDrop}
                className={cn(
                    'flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed px-6 py-10 text-center transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-primary',
                    dragging
                        ? 'border-primary bg-primary/5'
                        : 'border-border bg-muted/30 hover:border-primary/50 hover:bg-muted/50',
                )}
            >
                <span className="grid h-12 w-12 place-items-center rounded-full bg-primary/10 text-primary">
                    <UploadCloud className="h-6 w-6" />
                </span>
                <div className="text-sm font-semibold">
                    Drag a file here, or <span className="text-primary">browse</span>
                </div>
                <p className="text-xs text-muted-foreground">
                    PDF, Word, Excel, CSV or image · up to 10&nbsp;MB
                </p>
            </div>
            <input
                ref={inputRef}
                type="file"
                accept={ACCEPT}
                className="hidden"
                onChange={(e) => accept(e.target.files?.[0])}
            />
            <FieldErr>{localError ?? error}</FieldErr>
        </div>
    );
}

export function RecordEvidenceDialog({
    open,
    onClose,
    obligations,
    initialObligationId = null,
}: {
    open: boolean;
    onClose: () => void;
    obligations: ObligationOption[];
    initialObligationId?: number | null;
}) {
    const form = useForm<RecordEvidenceForm>(emptyForm());
    const { data, setData, processing } = form;
    const [obligationId, setObligationId] = useState<string>(
        initialObligationId ? String(initialObligationId) : '',
    );
    const [stepIndex, setStepIndex] = useState(initialObligationId ? 1 : 0);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [done, setDone] = useState(false);

    const obligation = useMemo(
        () => obligations.find((o) => String(o.id) === obligationId) ?? null,
        [obligations, obligationId],
    );

    const set = <K extends keyof RecordEvidenceForm>(k: K, v: RecordEvidenceForm[K]) =>
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        setData(k, v as any);
    const fieldErr = (name: string) =>
        errors[name] ?? (form.errors as Record<string, string>)[name];

    const validateStep = (idx: number): Record<string, string> => {
        const e: Record<string, string> = {};
        if (idx === 0 && !obligationId) e.obligation_id = 'Choose the obligation this evidences';
        if (idx === 1) {
            if (!data.evidence_type) e.evidence_type = 'Choose an evidence type';
            if (!data.title.trim()) e.title = 'A title is required';
            if (!data.file) e.file = 'Attach a document';
            if (data.valid_until && data.valid_until <= new Date().toISOString().slice(0, 10))
                e.valid_until = 'Valid-until must be a future date';
        }
        return e;
    };

    const goTo = (idx: number) => setStepIndex(Math.max(0, Math.min(idx, STEPS.length - 1)));
    const next = () => {
        const e = validateStep(stepIndex);
        setErrors(e);
        if (Object.keys(e).length === 0) goTo(stepIndex + 1);
    };

    const reset = () => {
        form.reset();
        form.clearErrors();
        setData(emptyForm());
        setObligationId(initialObligationId ? String(initialObligationId) : '');
        setErrors({});
        setStepIndex(initialObligationId ? 1 : 0);
        setDone(false);
    };

    const submit = (addAnother: boolean) => {
        const all = { ...validateStep(0), ...validateStep(1) };
        if (Object.keys(all).length) {
            setErrors(all);
            goTo(all.obligation_id ? 0 : 1);
            return;
        }
        setErrors({});
        form.post(`/governance/compliance/${obligationId}/evidence`, {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                toast.success('Evidence attached');
                if (addAnother) reset();
                else setDone(true);
            },
            onError: (errs) => setErrors(errs as Record<string, string>),
        });
    };

    const cur = STEPS[stepIndex];
    const isReview = cur.key === 'review';
    const typeLabel = EVIDENCE_TYPES.find((t) => t.key === data.evidence_type)?.label;

    if (done) {
        return (
            <WizardShell
                open={open}
                onClose={onClose}
                title="Record compliance evidence"
                description="Attach a document as evidence for a compliance obligation."
                railIcon={Paperclip}
                railTitle="Record evidence"
                railSub="Audit trail"
                steps={STEPS}
                stepIndex={STEPS.length - 1}
                onStepClick={() => {}}
                success={
                    <WizardSuccessPane
                        title="Evidence attached"
                        blurb={
                            <>
                                <strong>{data.title}</strong> is now on the evidence trail for{' '}
                                <strong>{obligation?.title}</strong>.
                            </>
                        }
                        actions={
                            <>
                                <Button variant="outline" onClick={reset}>
                                    <Plus className="h-4 w-4" /> Record another
                                </Button>
                                <Button asChild>
                                    <a href={obligation ? `/governance/compliance/${obligation.id}` : '/governance/compliance'}>
                                        <ShieldCheck className="h-4 w-4" /> View obligation
                                    </a>
                                </Button>
                            </>
                        }
                    />
                }
            />
        );
    }

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title="Record compliance evidence"
            description="Attach a document as evidence for a compliance obligation."
            railIcon={Paperclip}
            railTitle="Record evidence"
            railSub="Audit trail"
            steps={STEPS}
            stepIndex={stepIndex}
            onStepClick={goTo}
            footerStart={
                stepIndex > 0 && !(initialObligationId && stepIndex === 1) ? (
                    <Button type="button" variant="ghost" onClick={() => goTo(stepIndex - 1)}>
                        <ChevronLeft className="h-4 w-4" /> Back
                    </Button>
                ) : null
            }
            footerEnd={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    {isReview ? (
                        <>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => submit(true)}
                                disabled={processing}
                            >
                                {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}
                                Save & add another
                            </Button>
                            <Button type="button" onClick={() => submit(false)} disabled={processing}>
                                {processing ? (
                                    <>
                                        <Loader2 className="h-4 w-4 animate-spin" /> Uploading…
                                    </>
                                ) : (
                                    <>
                                        <Check className="h-4 w-4" /> Attach evidence
                                    </>
                                )}
                            </Button>
                        </>
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
                        title="Which obligation does this evidence?"
                        blurb="Evidence is filed against an obligation so it shows up in audit packs."
                    />
                    <Field label="Obligation" required error={fieldErr('obligation_id')}>
                        <SelectInput
                            value={obligationId}
                            onChange={setObligationId}
                            placeholder="Choose an obligation"
                            options={obligations.map((o) => ({
                                value: String(o.id),
                                label: `${o.title} · ${o.framework}`,
                            }))}
                        />
                    </Field>
                    {obligations.length === 0 ? (
                        <InfoCard icon={ShieldCheck} tone="warn">
                            There are no open obligations to attach evidence to yet. Log an
                            obligation first.
                        </InfoCard>
                    ) : null}
                </WizardStepPane>
            ) : null}

            {cur.key === 'document' ? (
                <WizardStepPane>
                    <StepHead
                        icon={FileUp}
                        title="Upload the document"
                        blurb="Attach the file and tell us what kind of evidence it is."
                    />
                    <div className="grid gap-4">
                        <Field label="Evidence type" required error={fieldErr('evidence_type')}>
                            <TilePicker
                                value={data.evidence_type}
                                onChange={(v) => set('evidence_type', v)}
                                cols={3}
                                options={EVIDENCE_TYPES}
                            />
                        </Field>
                        <Field label="Document" required>
                            <DocumentDropzone
                                file={data.file}
                                error={fieldErr('file')}
                                onPick={(f) => set('file', f)}
                                onClear={() => set('file', null)}
                            />
                        </Field>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Title" required error={fieldErr('title')} span>
                                <Input
                                    value={data.title}
                                    onChange={(e) => set('title', e.target.value)}
                                    placeholder="e.g. 2026 Ngā Paerewa self-assessment (signed)"
                                    aria-invalid={!!fieldErr('title')}
                                />
                            </Field>
                            <Field
                                label="Valid until"
                                hint="for certs that expire (optional)"
                                error={fieldErr('valid_until')}
                            >
                                <Input
                                    type="date"
                                    value={data.valid_until}
                                    onChange={(e) => set('valid_until', e.target.value)}
                                />
                            </Field>
                            <Field label="Notes" hint="optional">
                                <Input
                                    value={data.description}
                                    onChange={(e) => set('description', e.target.value)}
                                    placeholder="Context for auditors"
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
                        title="Review & save"
                        blurb="Confirm the evidence before attaching it to the obligation."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={ShieldCheck} title="Obligation" onEdit={initialObligationId ? undefined : () => goTo(0)}>
                            <ReviewRow label="Obligation" value={obligation?.title} />
                            <ReviewRow label="Framework" value={obligation?.framework} />
                        </ReviewCard>
                        <ReviewCard icon={Paperclip} title="Evidence" onEdit={() => goTo(1)}>
                            <ReviewRow label="Type" value={typeLabel} />
                            <ReviewRow label="Title" value={data.title} />
                            <ReviewRow label="File" value={data.file?.name} />
                            <ReviewRow label="Valid until" value={data.valid_until} />
                            <ReviewRow label="Notes" value={data.description} />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

export default RecordEvidenceDialog;
