import { pageHasFlashError } from '@/components/governance/governance-dialog-deep-link';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard } from '@/components/wizard/primitives';
import {
    block as blockAction,
    complete as completeAction,
    escalate as escalateAction,
    progress as progressAction,
} from '@/routes/governance/actions';
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle,
    FileText,
    Flag,
    Gauge,
    Loader2,
    PauseCircle,
    Plus,
    Trash2,
    UserRound,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';

export interface ActionDialogRecord {
    id: number;
    action_reference: string;
    version_number?: number | null;
    progress_pct?: number | null;
    progress_notes?: string | null;
    evidence_required: boolean;
    evidence_attachments?: string[] | null;
    assigned_to?: { id: number; name: string } | null;
}

export interface AssigneeOption {
    id: number;
    name: string;
    email?: string | null;
}

const NONE = '__none';

/** Standard simple-dialog shell (POPUP_STYLE_GUIDE.md): body mounts only while open. */
function ActionDialogShell({
    open,
    onClose,
    children,
}: {
    open: boolean;
    onClose: () => void;
    children: ReactNode;
}) {
    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 720px)',
                    width: 'min(92vw, 720px)',
                }}
            >
                {open ? children : null}
            </DialogContent>
        </Dialog>
    );
}

function SubmitButton({
    processing,
    disabled,
    variant,
    children,
}: {
    processing: boolean;
    disabled?: boolean;
    variant?: 'default' | 'destructive';
    children: ReactNode;
}) {
    return (
        <Button
            type="submit"
            variant={variant}
            disabled={processing || disabled}
        >
            {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
            {children}
        </Button>
    );
}

/** Close only when the request truly succeeded (a flash error still fires onSuccess). */
const closeOnSuccess = (onClose: () => void) => (page: unknown) => {
    if (!pageHasFlashError(page)) onClose();
};

// ── Update progress ──────────────────────────────────────────────────────

export function UpdateProgressDialog({
    open,
    onClose,
    action,
    returnTo,
}: {
    open: boolean;
    onClose: () => void;
    action: ActionDialogRecord;
    returnTo?: string | null;
}) {
    return (
        <ActionDialogShell open={open} onClose={onClose}>
            <UpdateProgressBody
                onClose={onClose}
                action={action}
                returnTo={returnTo}
            />
        </ActionDialogShell>
    );
}

function UpdateProgressBody({
    onClose,
    action,
    returnTo,
}: {
    onClose: () => void;
    action: ActionDialogRecord;
    returnTo?: string | null;
}) {
    const form = useForm({
        progress_pct: action.progress_pct ?? 0,
        progress_notes: action.progress_notes ?? '',
        expected_version: action.version_number ?? 1,
        return_to: returnTo ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            return_to: data.return_to || undefined,
        }));
        form.post(progressAction.url({ action: action.id }), {
            preserveScroll: true,
            onSuccess: closeOnSuccess(onClose),
        });
    };

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Gauge className="h-4 w-4 text-primary" />
                    Update progress
                </DialogTitle>
                <DialogDescription>
                    Record how far the deliverable has come. Reaching 100% does
                    not close the action — completion needs sign-off notes
                    {action.evidence_required ? ' and evidence' : ''}.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid gap-4">
                <Field
                    label={`Completion — ${form.data.progress_pct}%`}
                    error={form.errors.progress_pct}
                >
                    <input
                        id="action-progress"
                        type="range"
                        min={0}
                        max={100}
                        step={5}
                        className="w-full accent-primary"
                        value={form.data.progress_pct}
                        onChange={(e) =>
                            form.setData('progress_pct', Number(e.target.value))
                        }
                    />
                </Field>
                <Field
                    label="Progress notes"
                    error={form.errors.progress_notes}
                >
                    <Textarea
                        id="action-progress-notes"
                        rows={3}
                        maxLength={1000}
                        value={form.data.progress_notes}
                        onChange={(e) =>
                            form.setData('progress_notes', e.target.value)
                        }
                        placeholder="What was achieved, and what happens next…"
                    />
                </Field>
                {form.errors.expected_version ? (
                    <InfoCard icon={AlertTriangle} tone="crit">
                        {form.errors.expected_version}
                    </InfoCard>
                ) : null}
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <SubmitButton processing={form.processing}>
                    Save progress
                </SubmitButton>
            </DialogFooter>
        </form>
    );
}

// ── Mark as blocked ──────────────────────────────────────────────────────

export function BlockActionDialog({
    open,
    onClose,
    action,
}: {
    open: boolean;
    onClose: () => void;
    action: ActionDialogRecord;
}) {
    return (
        <ActionDialogShell open={open} onClose={onClose}>
            <BlockActionBody onClose={onClose} action={action} />
        </ActionDialogShell>
    );
}

function BlockActionBody({
    onClose,
    action,
}: {
    onClose: () => void;
    action: ActionDialogRecord;
}) {
    const form = useForm({
        blocked_reason: '',
        expected_version: action.version_number ?? 1,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(blockAction.url({ action: action.id }), {
            preserveScroll: true,
            onSuccess: closeOnSuccess(onClose),
        });
    };

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <PauseCircle className="h-4 w-4 text-status-warning" />
                    Mark action as blocked
                </DialogTitle>
                <DialogDescription>
                    Explain the dependency, missing information or roadblock
                    stopping progress.
                </DialogDescription>
            </DialogHeader>
            <div className="mt-3">
                <Field
                    label="Blocker"
                    required
                    error={form.errors.blocked_reason}
                >
                    <Textarea
                        id="action-block-reason"
                        rows={3}
                        maxLength={500}
                        value={form.data.blocked_reason}
                        onChange={(e) =>
                            form.setData('blocked_reason', e.target.value)
                        }
                        placeholder="What specifically is preventing completion…"
                    />
                </Field>
            </div>
            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <SubmitButton
                    processing={form.processing}
                    disabled={!form.data.blocked_reason.trim()}
                    variant="destructive"
                >
                    Mark as blocked
                </SubmitButton>
            </DialogFooter>
        </form>
    );
}

// ── Escalate ─────────────────────────────────────────────────────────────

export function EscalateActionDialog({
    open,
    onClose,
    action,
}: {
    open: boolean;
    onClose: () => void;
    action: ActionDialogRecord;
}) {
    return (
        <ActionDialogShell open={open} onClose={onClose}>
            <EscalateActionBody onClose={onClose} action={action} />
        </ActionDialogShell>
    );
}

function EscalateActionBody({
    onClose,
    action,
}: {
    onClose: () => void;
    action: ActionDialogRecord;
}) {
    const form = useForm({
        escalation_reason: '',
        expected_version: action.version_number ?? 1,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(escalateAction.url({ action: action.id }), {
            preserveScroll: true,
            onSuccess: closeOnSuccess(onClose),
        });
    };

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Flag className="h-4 w-4 text-status-critical" />
                    Escalate action
                </DialogTitle>
                <DialogDescription>
                    Alert the board chair and secretariat that this action needs
                    governance intervention.
                </DialogDescription>
            </DialogHeader>
            <div className="mt-3">
                <Field
                    label="Escalation reason"
                    required
                    error={form.errors.escalation_reason}
                >
                    <Textarea
                        id="action-escalation-reason"
                        rows={3}
                        maxLength={500}
                        value={form.data.escalation_reason}
                        onChange={(e) =>
                            form.setData('escalation_reason', e.target.value)
                        }
                        placeholder="Why governance intervention is required…"
                    />
                </Field>
            </div>
            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <SubmitButton
                    processing={form.processing}
                    disabled={!form.data.escalation_reason.trim()}
                    variant="destructive"
                >
                    Escalate action
                </SubmitButton>
            </DialogFooter>
        </form>
    );
}

// ── Reassign ─────────────────────────────────────────────────────────────

export function ReassignActionDialog({
    open,
    onClose,
    action,
    assignees,
}: {
    open: boolean;
    onClose: () => void;
    action: ActionDialogRecord;
    assignees: AssigneeOption[];
}) {
    return (
        <ActionDialogShell open={open} onClose={onClose}>
            <ReassignActionBody
                onClose={onClose}
                action={action}
                assignees={assignees}
            />
        </ActionDialogShell>
    );
}

function ReassignActionBody({
    onClose,
    action,
    assignees,
}: {
    onClose: () => void;
    action: ActionDialogRecord;
    assignees: AssigneeOption[];
}) {
    const form = useForm({
        assigned_to: action.assigned_to?.id
            ? String(action.assigned_to.id)
            : NONE,
        expected_version: action.version_number ?? 1,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (form.data.assigned_to === NONE) return;
        form.post(`/governance/actions/${action.id}/reassign`, {
            preserveScroll: true,
            onSuccess: closeOnSuccess(onClose),
        });
    };

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <UserRound className="h-4 w-4 text-primary" />
                    Reassign action
                </DialogTitle>
                <DialogDescription>
                    Transfer accountability for {action.action_reference} to
                    another approved person.
                </DialogDescription>
            </DialogHeader>
            <div className="mt-3">
                <Field
                    label="New owner"
                    required
                    error={form.errors.assigned_to}
                >
                    <Select
                        value={form.data.assigned_to}
                        onValueChange={(value) =>
                            form.setData('assigned_to', value)
                        }
                    >
                        <SelectTrigger
                            id="action-reassign"
                            aria-label="New owner"
                        >
                            <SelectValue placeholder="Choose a person" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE} disabled>
                                Choose a person
                            </SelectItem>
                            {assignees.map((user) => (
                                <SelectItem
                                    key={user.id}
                                    value={String(user.id)}
                                >
                                    {user.email
                                        ? `${user.name} (${user.email})`
                                        : user.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
            </div>
            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <SubmitButton
                    processing={form.processing}
                    disabled={
                        form.data.assigned_to === NONE ||
                        form.data.assigned_to ===
                            String(action.assigned_to?.id ?? '')
                    }
                >
                    Reassign action
                </SubmitButton>
            </DialogFooter>
        </form>
    );
}

// ── Complete ─────────────────────────────────────────────────────────────

export function CompleteActionDialog({
    open,
    onClose,
    action,
    returnTo,
}: {
    open: boolean;
    onClose: () => void;
    action: ActionDialogRecord;
    returnTo?: string | null;
}) {
    return (
        <ActionDialogShell open={open} onClose={onClose}>
            <CompleteActionBody
                onClose={onClose}
                action={action}
                returnTo={returnTo}
            />
        </ActionDialogShell>
    );
}

function CompleteActionBody({
    onClose,
    action,
    returnTo,
}: {
    onClose: () => void;
    action: ActionDialogRecord;
    returnTo?: string | null;
}) {
    const form = useForm({
        completion_notes: '',
        evidence_files: [] as string[],
        expected_version: action.version_number ?? 1,
        return_to: returnTo ?? '',
    });
    const [evidenceDraft, setEvidenceDraft] = useState('');
    const errors = form.errors as Record<string, string | undefined>;
    const existingEvidence = action.evidence_attachments ?? [];
    const evidenceMissing =
        action.evidence_required &&
        form.data.evidence_files.length === 0 &&
        existingEvidence.length === 0;
    const notesTooShort = form.data.completion_notes.trim().length < 3;

    const addEvidence = () => {
        const value = evidenceDraft.trim();
        if (!value || form.data.evidence_files.includes(value)) return;
        form.setData('evidence_files', [...form.data.evidence_files, value]);
        setEvidenceDraft('');
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (notesTooShort || evidenceMissing) return;
        form.transform((data) => ({
            ...data,
            return_to: data.return_to || undefined,
        }));
        form.post(completeAction.url({ action: action.id }), {
            preserveScroll: true,
            onSuccess: closeOnSuccess(onClose),
        });
    };

    const evidenceError =
        errors.evidence_files ??
        Object.entries(errors).find(([key]) =>
            key.startsWith('evidence_files.'),
        )?.[1];

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <CheckCircle className="h-4 w-4 text-status-success" />
                    Complete action
                </DialogTitle>
                <DialogDescription>
                    Sign off {action.action_reference} with completion notes
                    {action.evidence_required
                        ? ' and the required verification evidence'
                        : ''}
                    . A completion receipt is issued.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid gap-4">
                <Field
                    label="Completion notes"
                    required
                    error={form.errors.completion_notes}
                >
                    <Textarea
                        id="action-completion-notes"
                        rows={4}
                        maxLength={2000}
                        value={form.data.completion_notes}
                        onChange={(e) =>
                            form.setData('completion_notes', e.target.value)
                        }
                        placeholder="The work completed, approvals obtained and outcomes realised…"
                    />
                </Field>

                <Field
                    label="Evidence documents"
                    required={
                        action.evidence_required &&
                        existingEvidence.length === 0
                    }
                    hint="Managed storage paths"
                    error={evidenceError}
                >
                    <div className="grid gap-2">
                        <div className="flex gap-2">
                            <Input
                                id="action-evidence"
                                value={evidenceDraft}
                                onChange={(e) =>
                                    setEvidenceDraft(e.target.value)
                                }
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        addEvidence();
                                    }
                                }}
                                placeholder="e.g. governance/evidence/signed-policy-v2.pdf"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                onClick={addEvidence}
                                disabled={!evidenceDraft.trim()}
                            >
                                <Plus className="h-4 w-4" /> Add
                            </Button>
                        </div>
                        {form.data.evidence_files.map((file, index) => (
                            <div
                                key={file}
                                className="flex items-center justify-between gap-2 rounded-md bg-muted px-2.5 py-1.5 text-xs"
                            >
                                <span className="flex min-w-0 items-center gap-1.5">
                                    <FileText className="size-3.5 shrink-0 text-muted-foreground" />
                                    <span className="truncate font-mono">
                                        {file}
                                    </span>
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-7"
                                    aria-label={`Remove evidence ${file}`}
                                    onClick={() =>
                                        form.setData(
                                            'evidence_files',
                                            form.data.evidence_files.filter(
                                                (_, i) => i !== index,
                                            ),
                                        )
                                    }
                                >
                                    <Trash2 className="size-3.5 text-status-critical" />
                                </Button>
                            </div>
                        ))}
                        {existingEvidence.length > 0 ? (
                            <p className="text-caption">
                                {existingEvidence.length} evidence file
                                {existingEvidence.length === 1
                                    ? ' is'
                                    : 's are'}{' '}
                                already attached.
                            </p>
                        ) : null}
                    </div>
                </Field>

                {action.evidence_required ? (
                    <InfoCard icon={AlertTriangle} tone="warn">
                        This action requires verification evidence. Reference
                        documents already held in managed Governance storage;
                        public website files, missing files and evidence
                        belonging to another action are rejected.
                    </InfoCard>
                ) : null}
                {form.errors.expected_version ? (
                    <InfoCard icon={AlertTriangle} tone="crit">
                        {form.errors.expected_version}
                    </InfoCard>
                ) : null}
            </div>

            <DialogFooter className="mt-4">
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <SubmitButton
                    processing={form.processing}
                    disabled={notesTooShort || evidenceMissing}
                >
                    Complete action
                </SubmitButton>
            </DialogFooter>
        </form>
    );
}
