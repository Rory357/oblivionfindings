/* eslint-disable no-restricted-syntax -- These wizards reuse the shared
 * Add-Client wizard shell, whose footer nav and people/tile pickers are bespoke
 * selector surfaces (raw <button> with custom layout), not shadcn <Button> /
 * <Card> cases. Every colour stays a semantic design token. */
/* Quick-action wizards for the Documents hub — Upload · Generate · Send for
 * signature · New template. Each clones the shared Add-Client wizard shell
 * (resources/js/components/wizard/shell.tsx) so chrome (stepper rail, progress,
 * footer, success pane) matches every other workflow in the app. */
import { router } from '@inertiajs/react';
import {
    Bell,
    Check,
    Eye,
    FileText,
    Mail,
    Pencil,
    Scroll,
    Send,
    Shield,
    Sparkles,
    Upload,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';

import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    Segmented,
    StepHead,
    TilePicker,
    type IconType,
} from '@/components/wizard/primitives';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    type WizardStep,
} from '@/components/wizard/shell';

export type WizEmployee = {
    id: number;
    user_id: number | null;
    name: string | null;
    employee_number: string | null;
};

export type WizTemplate = {
    id: number;
    name: string;
    category: string;
    version: number;
    is_active: boolean;
    approval_required: boolean;
    merge_fields: string[];
};

export type WizDoc = {
    id: number;
    title: string;
    folder: string;
    category: string;
    signature: string | null;
};

/** Read Laravel's XSRF cookie for same-origin JSON POSTs (preview endpoint). */
function xsrfToken(): string {
    const m = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

const CATEGORY_TILES: { key: string; label: string; icon: IconType }[] = [
    { key: 'contract', label: 'Contract', icon: FileText },
    { key: 'certificate', label: 'Certificate', icon: Shield },
    { key: 'letter', label: 'Letter', icon: Mail },
    { key: 'policy', label: 'Policy', icon: Scroll },
    { key: 'offer', label: 'Offer', icon: Mail },
    { key: 'payslip', label: 'Payslip', icon: FileText },
];

function PeoplePicker({
    employees,
    value,
    multi,
    onPick,
}: {
    employees: WizEmployee[];
    value: number[] | number | null;
    multi?: boolean;
    onPick: (id: number) => void;
}) {
    const isSel = (id: number) =>
        multi
            ? Array.isArray(value) && value.includes(id)
            : value === id;
    return (
        <div className="flex max-h-[260px] flex-col gap-1.5 overflow-y-auto rounded-xl border border-border p-2">
            {employees.length === 0 ? (
                <p className="px-2 py-4 text-center text-sm text-muted-foreground">
                    No active staff available.
                </p>
            ) : (
                employees.map((e) => {
                    const sel = isSel(e.id);
                    return (
                        <button
                            key={e.id}
                            type="button"
                            onClick={() => onPick(e.id)}
                            className={
                                'flex items-center gap-3 rounded-lg border px-2.5 py-2 text-left transition-colors ' +
                                (sel
                                    ? 'border-primary bg-primary/[0.08]'
                                    : 'border-transparent hover:bg-accent')
                            }
                        >
                            <span className="grid h-[30px] w-[30px] flex-none place-items-center rounded-full bg-primary/15 text-[11px] font-bold text-primary">
                                {(e.name ?? '?')
                                    .split(/\s+/)
                                    .map((p) => p[0])
                                    .slice(0, 2)
                                    .join('')
                                    .toUpperCase()}
                            </span>
                            <span className="flex-1 text-[13px] font-semibold">
                                {e.name ?? `Employee #${e.id}`}
                            </span>
                            {sel ? (
                                <Check className="h-4 w-4 text-primary" />
                            ) : null}
                        </button>
                    );
                })
            )}
        </div>
    );
}

/* ================================================================== */
/*  Upload                                                            */
/* ================================================================== */

const UPLOAD_STEPS: WizardStep[] = [
    { key: 'who', label: 'Who & what', blurb: 'Employee, title, type', icon: Users },
    { key: 'file', label: 'File', blurb: 'Drag & drop', icon: Upload },
    { key: 'details', label: 'Details', blurb: 'Expiry, access, notes', icon: Shield },
    { key: 'review', label: 'Review & file', blurb: 'Confirm', icon: Check },
];

export function UploadWizard({
    open,
    onClose,
    employees,
}: {
    open: boolean;
    onClose: () => void;
    employees: WizEmployee[];
}) {
    const [step, setStep] = useState(0);
    const [employeeId, setEmployeeId] = useState<number | null>(null);
    const [title, setTitle] = useState('');
    const [category, setCategory] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const [expiresAt, setExpiresAt] = useState('');
    const [restricted, setRestricted] = useState(false);
    const [notes, setNotes] = useState('');
    const [processing, setProcessing] = useState(false);

    const reset = () => {
        setStep(0);
        setEmployeeId(null);
        setTitle('');
        setCategory('');
        setFiles([]);
        setExpiresAt('');
        setRestricted(false);
        setNotes('');
    };

    const close = () => {
        reset();
        onClose();
    };

    const canContinue =
        step === 0
            ? !!employeeId && title.trim() !== '' && category !== ''
            : step === 1
              ? files.length > 0
              : true;

    const emp = employees.find((e) => e.id === employeeId);
    const pct = Math.round(((step + 1) / UPLOAD_STEPS.length) * 100);

    const submit = () => {
        if (!employeeId || !files[0]) return;
        const data = new FormData();
        data.append('employee_profile_id', String(employeeId));
        data.append('title', title);
        data.append('category', category);
        if (expiresAt) data.append('expires_at', expiresAt);
        data.append('is_restricted', restricted ? '1' : '0');
        if (notes) data.append('notes', notes);
        data.append('file', files[0]);
        setProcessing(true);
        router.post('/hr/documents', data, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: close,
            onFinish: () => setProcessing(false),
        });
    };

    const last = step === UPLOAD_STEPS.length - 1;

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Upload document"
            description="File a document to an employee record"
            railIcon={Upload}
            railTitle="Upload document"
            railSub="File to employee record"
            steps={UPLOAD_STEPS}
            stepIndex={step}
            onStepClick={setStep}
            pct={pct}
            footerEnd={
                <>
                    {step > 0 ? (
                        <button
                            type="button"
                            onClick={() => setStep((s) => s - 1)}
                            className="rounded-md px-3 py-2 text-[13px] font-semibold text-foreground hover:bg-muted"
                        >
                            Back
                        </button>
                    ) : null}
                    <button
                        type="button"
                        disabled={!canContinue || processing}
                        onClick={() => (last ? submit() : setStep((s) => s + 1))}
                        className="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-[13px] font-semibold text-primary-foreground disabled:opacity-50"
                    >
                        {last ? 'File document' : 'Continue'}
                    </button>
                </>
            }
        >
            {step === 0 ? (
                <WizardStepPane>
                    <StepHead icon={Users} title="Who & what" blurb="Attach this document to an employee record." />
                    <div className="space-y-4">
                        <Field label="Employee" required>
                            <PeoplePicker
                                employees={employees}
                                value={employeeId}
                                onPick={(id) => setEmployeeId(id)}
                            />
                        </Field>
                        <Field label="Document title" required>
                            <Input
                                value={title}
                                onChange={(e) => setTitle(e.target.value)}
                                placeholder="e.g. First Aid Certificate"
                            />
                        </Field>
                        <Field label="Category" required>
                            <TilePicker
                                value={category}
                                onChange={setCategory}
                                options={CATEGORY_TILES}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 1 ? (
                <WizardStepPane>
                    <StepHead icon={Upload} title="File" blurb="Drag a PDF, image or Office file here." />
                    {files.length === 0 ? (
                        <FileDropzone
                            onFiles={(f) => setFiles(f.slice(0, 1))}
                            accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.jpg,.jpeg,.png,.gif,.txt,.rtf"
                            hint="PDF, JPG, PNG, DOCX · up to 20 MB"
                        />
                    ) : (
                        <StagedFileCard
                            file={files[0]}
                            onRemove={() => setFiles([])}
                        />
                    )}
                </WizardStepPane>
            ) : null}

            {step === 2 ? (
                <WizardStepPane>
                    <StepHead icon={Shield} title="Details" blurb="Expiry, access and notes." />
                    <div className="space-y-4">
                        <Field label="Expiry date" hint="optional">
                            <Input
                                type="date"
                                value={expiresAt}
                                onChange={(e) => setExpiresAt(e.target.value)}
                            />
                        </Field>
                        <div className="flex items-center justify-between rounded-lg border border-border p-3">
                            <div>
                                <Label className="text-[13px] font-semibold">
                                    Restricted access
                                </Label>
                                <p className="text-xs text-muted-foreground">
                                    Visible to managers only
                                </p>
                            </div>
                            <Switch
                                checked={restricted}
                                onCheckedChange={setRestricted}
                            />
                        </div>
                        <Field label="Notes" hint="optional">
                            <Textarea
                                rows={3}
                                value={notes}
                                onChange={(e) => setNotes(e.target.value)}
                                placeholder="Optional context…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 3 ? (
                <WizardStepPane>
                    <StepHead icon={Check} title="Review & file" blurb="Confirm the details below." />
                    <ReviewCard icon={FileText} title="Document" onEdit={() => setStep(0)}>
                        <ReviewRow label="Employee" value={emp?.name} />
                        <ReviewRow label="Title" value={title} />
                        <ReviewRow label="Category" value={category} />
                        <ReviewRow label="File" value={files[0]?.name} />
                        <ReviewRow label="Expiry" value={expiresAt || 'None'} />
                        <ReviewRow
                            label="Access"
                            value={restricted ? 'Restricted' : 'Standard'}
                        />
                    </ReviewCard>
                    <p className="mt-4 rounded-lg border border-primary/35 bg-primary/10 p-3 text-[13px] text-primary">
                        This document will appear in the employee's file and on
                        their My HR documents.
                    </p>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Generate                                                          */
/* ================================================================== */

const GENERATE_STEPS: WizardStep[] = [
    { key: 'tpl', label: 'Template', blurb: 'Pick a template', icon: FileText },
    { key: 'rec', label: 'Recipient', blurb: 'Choose employee', icon: Users },
    { key: 'merge', label: 'Merge & preview', blurb: 'Live preview', icon: Eye },
    { key: 'gen', label: 'Generate', blurb: 'Produce PDF', icon: Check },
];

type PreviewResult = {
    content: string;
    resolved: string[];
    unresolved: string[];
    field_count: number;
};

export function GenerateWizard({
    open,
    onClose,
    employees,
    templates,
    initialTemplateId,
}: {
    open: boolean;
    onClose: () => void;
    employees: WizEmployee[];
    templates: WizTemplate[];
    initialTemplateId?: number | null;
}) {
    const [step, setStep] = useState(0);
    const [templateId, setTemplateId] = useState<string>(
        initialTemplateId ? String(initialTemplateId) : '',
    );
    const [employeeId, setEmployeeId] = useState<number | null>(null);
    const [title, setTitle] = useState('');
    const [preview, setPreview] = useState<PreviewResult | null>(null);
    const [loadingPreview, setLoadingPreview] = useState(false);
    const [processing, setProcessing] = useState(false);

    const active = templates.filter((t) => t.is_active);
    const template = templates.find((t) => String(t.id) === templateId);
    const emp = employees.find((e) => e.id === employeeId);
    const pct = Math.round(((step + 1) / GENERATE_STEPS.length) * 100);

    const close = () => {
        setStep(0);
        setTemplateId(initialTemplateId ? String(initialTemplateId) : '');
        setEmployeeId(null);
        setTitle('');
        setPreview(null);
        onClose();
    };

    const loadPreview = () => {
        if (!templateId || !employeeId) return;
        setLoadingPreview(true);
        setPreview(null);
        fetch('/hr/documents/preview', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                template_id: Number(templateId),
                employee_profile_id: employeeId,
            }),
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
            .then((data: PreviewResult) => setPreview(data))
            .catch(() => setPreview(null))
            .finally(() => setLoadingPreview(false));
    };

    const goNext = () => {
        const next = step + 1;
        setStep(next);
        if (GENERATE_STEPS[next]?.key === 'merge') loadPreview();
    };

    const submit = () => {
        if (!templateId || !employeeId) return;
        setProcessing(true);
        router.post(
            '/hr/documents/generate',
            {
                template_id: Number(templateId),
                employee_profile_id: employeeId,
                title: title || undefined,
            },
            {
                preserveScroll: true,
                onSuccess: close,
                onFinish: () => setProcessing(false),
            },
        );
    };

    const canContinue =
        step === 0 ? !!templateId : step === 1 ? !!employeeId : true;
    const last = step === GENERATE_STEPS.length - 1;

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Generate from template"
            description="Merge a template into a PDF for an employee"
            railIcon={Sparkles}
            railTitle="Generate from template"
            railSub="Merge → PDF"
            steps={GENERATE_STEPS}
            stepIndex={step}
            onStepClick={(i) => {
                setStep(i);
                if (GENERATE_STEPS[i]?.key === 'merge') loadPreview();
            }}
            pct={pct}
            footerEnd={
                <>
                    {step > 0 ? (
                        <button
                            type="button"
                            onClick={() => setStep((s) => s - 1)}
                            className="rounded-md px-3 py-2 text-[13px] font-semibold text-foreground hover:bg-muted"
                        >
                            Back
                        </button>
                    ) : null}
                    <button
                        type="button"
                        disabled={!canContinue || processing}
                        onClick={() => (last ? submit() : goNext())}
                        className="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-[13px] font-semibold text-primary-foreground disabled:opacity-50"
                    >
                        {last ? 'Generate PDF' : 'Continue'}
                    </button>
                </>
            }
        >
            {step === 0 ? (
                <WizardStepPane>
                    <StepHead icon={FileText} title="Template" blurb="Pick a template to merge." />
                    {active.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No active templates. Create one first.
                        </p>
                    ) : (
                        <TilePicker
                            value={templateId}
                            onChange={setTemplateId}
                            options={active.map((t) => ({
                                key: String(t.id),
                                label: t.name,
                                icon: FileText,
                                description: `${t.category} · v${t.version}`,
                            }))}
                        />
                    )}
                </WizardStepPane>
            ) : null}

            {step === 1 ? (
                <WizardStepPane>
                    <StepHead icon={Users} title="Recipient" blurb="Merge fields resolve from this employee." />
                    <PeoplePicker
                        employees={employees}
                        value={employeeId}
                        onPick={setEmployeeId}
                    />
                    <Field label="Document title" hint="optional — defaults to template name">
                        <Input
                            value={title}
                            onChange={(e) => setTitle(e.target.value)}
                            placeholder={template?.name ?? ''}
                        />
                    </Field>
                </WizardStepPane>
            ) : null}

            {step === 2 ? (
                <WizardStepPane>
                    <StepHead icon={Eye} title="Merge & preview" blurb="Live preview with fields resolved." />
                    {loadingPreview ? (
                        <p className="text-sm text-muted-foreground">
                            Resolving merge fields…
                        </p>
                    ) : preview ? (
                        <div className="space-y-3">
                            <div
                                className="max-h-[320px] overflow-y-auto rounded-lg border border-border bg-white p-7 text-[12.5px] leading-[1.8] text-[#1a1523]"
                                dangerouslySetInnerHTML={{
                                    __html: preview.content || '<em>Empty template</em>',
                                }}
                            />
                            {preview.unresolved.length === 0 ? (
                                <p className="flex items-center gap-2 text-[12.5px] font-semibold text-status-success">
                                    <Check className="h-4 w-4" />
                                    All {preview.field_count} merge fields
                                    resolved
                                </p>
                            ) : (
                                <p className="text-[12.5px] font-semibold text-status-warning">
                                    {preview.unresolved.length} unresolved:{' '}
                                    {preview.unresolved.join(', ')}
                                </p>
                            )}
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Couldn't load preview.
                        </p>
                    )}
                </WizardStepPane>
            ) : null}

            {step === 3 ? (
                <WizardStepPane>
                    <StepHead icon={Check} title="Generate" blurb="Confirm and produce the PDF." />
                    <ReviewCard icon={Sparkles} title="Generation" onEdit={() => setStep(0)}>
                        <ReviewRow label="Template" value={template?.name} />
                        <ReviewRow label="Recipient" value={emp?.name} />
                        <ReviewRow label="Output" value="PDF" />
                        <ReviewRow label="Filed to" value="Library" />
                    </ReviewCard>
                    <p className="mt-4 rounded-lg border border-primary/35 bg-primary/10 p-3 text-[13px] text-primary">
                        A PDF will be generated and saved. You can send it for
                        signature next.
                    </p>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Send for signature                                               */
/* ================================================================== */

const SEND_STEPS: WizardStep[] = [
    { key: 'doc', label: 'Document & signers', blurb: 'Who signs', icon: Users },
    { key: 'opt', label: 'Options', blurb: 'Order, due, message', icon: Send },
    { key: 'rev', label: 'Review & send', blurb: 'Confirm', icon: Check },
];

export function SendWizard({
    open,
    onClose,
    employees,
    documents,
    initialDocId,
}: {
    open: boolean;
    onClose: () => void;
    employees: WizEmployee[];
    documents: WizDoc[];
    initialDocId?: number | null;
}) {
    const [step, setStep] = useState(0);
    const [docId, setDocId] = useState<string>(
        initialDocId ? String(initialDocId) : '',
    );
    const [signerIds, setSignerIds] = useState<number[]>([]);
    const [order, setOrder] = useState<'parallel' | 'sequential'>('parallel');
    const [dueAt, setDueAt] = useState('');
    const [message, setMessage] = useState('');
    const [processing, setProcessing] = useState(false);

    const signable = useMemo(
        () => employees.filter((e) => e.user_id),
        [employees],
    );
    const sendable = documents.filter((d) => d.signature !== 'signed');
    const doc = documents.find((d) => String(d.id) === docId);
    const pct = Math.round(((step + 1) / SEND_STEPS.length) * 100);

    const close = () => {
        setStep(0);
        setDocId(initialDocId ? String(initialDocId) : '');
        setSignerIds([]);
        setOrder('parallel');
        setDueAt('');
        setMessage('');
        onClose();
    };

    const toggleSigner = (profileId: number) => {
        const e = employees.find((x) => x.id === profileId);
        if (!e?.user_id) return;
        const uid = e.user_id;
        setSignerIds((prev) =>
            prev.includes(uid) ? prev.filter((x) => x !== uid) : [...prev, uid],
        );
    };

    const submit = () => {
        if (!docId || signerIds.length === 0) return;
        setProcessing(true);
        router.post(
            '/hr/signatures/request',
            {
                document_id: Number(docId),
                user_ids: signerIds,
                order,
                due_at: dueAt || undefined,
                message: message || undefined,
            },
            {
                preserveScroll: true,
                onSuccess: close,
                onFinish: () => setProcessing(false),
            },
        );
    };

    const canContinue =
        step === 0 ? !!docId && signerIds.length > 0 : true;
    const last = step === SEND_STEPS.length - 1;

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Send for signature"
            description="Request an e-signature on a document"
            railIcon={PenLineIcon}
            railTitle="Send for signature"
            railSub="Request e-signature"
            steps={SEND_STEPS}
            stepIndex={step}
            onStepClick={setStep}
            pct={pct}
            footerEnd={
                <>
                    {step > 0 ? (
                        <button
                            type="button"
                            onClick={() => setStep((s) => s - 1)}
                            className="rounded-md px-3 py-2 text-[13px] font-semibold text-foreground hover:bg-muted"
                        >
                            Back
                        </button>
                    ) : null}
                    <button
                        type="button"
                        disabled={!canContinue || processing}
                        onClick={() => (last ? submit() : setStep((s) => s + 1))}
                        className="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-[13px] font-semibold text-primary-foreground disabled:opacity-50"
                    >
                        {last ? 'Send request' : 'Continue'}
                    </button>
                </>
            }
        >
            {step === 0 ? (
                <WizardStepPane>
                    <StepHead icon={Users} title="Document & signers" blurb="Who needs to sign?" />
                    <div className="space-y-4">
                        <Field label="Document" required>
                            {sendable.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No documents available to send.
                                </p>
                            ) : (
                                <TilePicker
                                    value={docId}
                                    onChange={setDocId}
                                    options={sendable.slice(0, 12).map((d) => ({
                                        key: String(d.id),
                                        label: d.title,
                                        icon: FileText,
                                        description: d.folder,
                                    }))}
                                />
                            )}
                        </Field>
                        <Field label="Signers" required>
                            <PeoplePicker
                                employees={signable}
                                value={signable
                                    .filter(
                                        (e) =>
                                            e.user_id &&
                                            signerIds.includes(e.user_id),
                                    )
                                    .map((e) => e.id)}
                                multi
                                onPick={toggleSigner}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 1 ? (
                <WizardStepPane>
                    <StepHead icon={Send} title="Options" blurb="Signing order, due date and message." />
                    <div className="space-y-4">
                        <Field label="Signing order">
                            <Segmented
                                value={order}
                                onChange={setOrder}
                                options={[
                                    { value: 'parallel', label: 'Parallel' },
                                    { value: 'sequential', label: 'Sequential' },
                                ]}
                            />
                        </Field>
                        <Field label="Due date" hint="optional">
                            <Input
                                type="date"
                                value={dueAt}
                                onChange={(e) => setDueAt(e.target.value)}
                            />
                        </Field>
                        <Field label="Message to signer" hint="optional">
                            <Textarea
                                rows={3}
                                value={message}
                                onChange={(e) => setMessage(e.target.value)}
                                placeholder="Please review and sign by Friday."
                            />
                        </Field>
                        <p className="flex items-center gap-2 text-xs text-muted-foreground">
                            <Bell className="h-3.5 w-3.5" />
                            Auto-reminder 2 days before the due date.
                        </p>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 2 ? (
                <WizardStepPane>
                    <StepHead icon={Check} title="Review & send" blurb="Confirm the request." />
                    <ReviewCard icon={Send} title="Request" onEdit={() => setStep(0)}>
                        <ReviewRow label="Document" value={doc?.title} />
                        <ReviewRow
                            label="Signers"
                            value={
                                signable
                                    .filter(
                                        (e) =>
                                            e.user_id &&
                                            signerIds.includes(e.user_id),
                                    )
                                    .map((e) => e.name)
                                    .join(', ') || undefined
                            }
                        />
                        <ReviewRow label="Order" value={order} />
                        <ReviewRow label="Due" value={dueAt || 'Not set'} />
                    </ReviewCard>
                    <p className="mt-4 rounded-lg border border-primary/35 bg-primary/10 p-3 text-[13px] text-primary">
                        Each signer is notified and signs with a drawn signature.
                        A signed PDF + certificate is filed on completion.
                    </p>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

/* ================================================================== */
/*  New template                                                      */
/* ================================================================== */

const TEMPLATE_STEPS: WizardStep[] = [
    { key: 'basics', label: 'Basics', blurb: 'Name & category', icon: FileText },
    { key: 'content', label: 'Content', blurb: 'Editor + merge fields', icon: Pencil },
    { key: 'settings', label: 'Settings', blurb: 'Approval, active', icon: Shield },
    { key: 'review', label: 'Review', blurb: 'Confirm', icon: Check },
];

const MERGE_HINTS = [
    '{{employee_name}}',
    '{{start_date}}',
    '{{annual_salary}}',
    '{{site_name}}',
    '{{company_name}}',
    '{{current_date}}',
];

export function TemplateWizard({
    open,
    onClose,
}: {
    open: boolean;
    onClose: () => void;
}) {
    const [step, setStep] = useState(0);
    const [name, setName] = useState('');
    const [category, setCategory] = useState('');
    const [content, setContent] = useState(
        'This agreement is made between {{company_name}} and {{employee_name}}, commencing {{start_date}} at {{site_name}}…',
    );
    const [approval, setApproval] = useState(false);
    const [processing, setProcessing] = useState(false);

    const pct = Math.round(((step + 1) / TEMPLATE_STEPS.length) * 100);

    const close = () => {
        setStep(0);
        setName('');
        setCategory('');
        setContent(
            'This agreement is made between {{company_name}} and {{employee_name}}, commencing {{start_date}} at {{site_name}}…',
        );
        setApproval(false);
        onClose();
    };

    const insert = (token: string) =>
        setContent((c) => `${c}${c.endsWith(' ') || c === '' ? '' : ' '}${token}`);

    const submit = () => {
        if (!name || !category || !content) return;
        const fields = Array.from(
            new Set(content.match(/\{\{\s*[a-zA-Z0-9_.-]+\s*\}\}/g) ?? []),
        ).map((f) => f.replace(/[{}]/g, '').trim());
        setProcessing(true);
        router.post(
            '/hr/documents/templates',
            {
                name,
                category,
                content,
                merge_fields: fields,
                approval_required: approval,
            },
            {
                preserveScroll: true,
                onSuccess: close,
                onFinish: () => setProcessing(false),
            },
        );
    };

    const canContinue =
        step === 0 ? name.trim() !== '' && category !== '' : step === 1 ? content.trim() !== '' : true;
    const last = step === TEMPLATE_STEPS.length - 1;

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="New template"
            description="Create a reusable merge document"
            railIcon={FileText}
            railTitle="New template"
            railSub="Reusable merge doc"
            steps={TEMPLATE_STEPS}
            stepIndex={step}
            onStepClick={setStep}
            pct={pct}
            footerEnd={
                <>
                    {step > 0 ? (
                        <button
                            type="button"
                            onClick={() => setStep((s) => s - 1)}
                            className="rounded-md px-3 py-2 text-[13px] font-semibold text-foreground hover:bg-muted"
                        >
                            Back
                        </button>
                    ) : null}
                    <button
                        type="button"
                        disabled={!canContinue || processing}
                        onClick={() => (last ? submit() : setStep((s) => s + 1))}
                        className="inline-flex items-center gap-1.5 rounded-md bg-primary px-4 py-2 text-[13px] font-semibold text-primary-foreground disabled:opacity-50"
                    >
                        {last ? 'Create template' : 'Continue'}
                    </button>
                </>
            }
        >
            {step === 0 ? (
                <WizardStepPane>
                    <StepHead icon={FileText} title="Basics" blurb="Name and category." />
                    <div className="space-y-4">
                        <Field label="Template name" required>
                            <Input
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                placeholder="e.g. Fixed-Term Agreement"
                            />
                        </Field>
                        <Field label="Category" required>
                            <TilePicker
                                value={category}
                                onChange={setCategory}
                                options={CATEGORY_TILES.filter((c) =>
                                    ['contract', 'offer', 'letter', 'policy'].includes(
                                        c.key,
                                    ),
                                )}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 1 ? (
                <WizardStepPane>
                    <StepHead icon={Pencil} title="Content" blurb="Write the body and insert merge fields." />
                    <div className="mb-2 flex flex-wrap gap-1.5">
                        {MERGE_HINTS.map((f) => (
                            <button
                                key={f}
                                type="button"
                                onClick={() => insert(f)}
                                className="rounded-md border border-border bg-card px-2 py-1 font-mono text-[11px] text-primary hover:border-primary/50"
                            >
                                + {f}
                            </button>
                        ))}
                    </div>
                    <Textarea
                        rows={9}
                        value={content}
                        onChange={(e) => setContent(e.target.value)}
                        className="font-mono text-[13px] leading-[1.7]"
                    />
                </WizardStepPane>
            ) : null}

            {step === 2 ? (
                <WizardStepPane>
                    <StepHead icon={Shield} title="Settings" blurb="Workflow controls." />
                    <div className="flex items-center justify-between rounded-lg border border-border p-3">
                        <div>
                            <Label className="text-[13px] font-semibold">
                                Require approval before sending
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                Generated documents need a manager sign-off
                                before they can be sent.
                            </p>
                        </div>
                        <Switch checked={approval} onCheckedChange={setApproval} />
                    </div>
                </WizardStepPane>
            ) : null}

            {step === 3 ? (
                <WizardStepPane>
                    <StepHead icon={Check} title="Review" blurb="Confirm the template." />
                    <ReviewCard icon={FileText} title="Template" onEdit={() => setStep(0)}>
                        <ReviewRow label="Name" value={name} />
                        <ReviewRow label="Category" value={category} />
                        <ReviewRow
                            label="Approval required"
                            value={approval ? 'Yes' : 'No'}
                        />
                    </ReviewCard>
                </WizardStepPane>
            ) : null}
        </WizardShell>
    );
}

/* PenLine isn't a distinct enough import name from the page; alias here. */
function PenLineIcon({ className }: { className?: string }) {
    return <Pencil className={className} />;
}
