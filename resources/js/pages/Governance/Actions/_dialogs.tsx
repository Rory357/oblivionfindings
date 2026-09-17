import { ConfirmDialog } from '@/components/confirm-dialog';
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
    Flag,
    Gauge,
    Loader2,
    PauseCircle,
    UserRound,
} from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useState } from 'react';
import {
    ActionEvidencePanel,
    type ActionEvidenceFile,
    type EarlierEvidenceFile,
} from './_evidence';

export interface ActionDialogRecord {
    id: number;
    title?: string | null;
    action_reference: string;
    version_number?: number | null;
    progress_pct?: number | null;
    progress_notes?: string | null;
    evidence_required: boolean;
    evidence?: ActionEvidenceFile[];
    legacy_evidence?: EarlierEvidenceFile[];
    assigned_to?: { id: number; name: string } | null;
}

export interface AssigneeOption {
    id: number;
    name: string;
}

const NONE = '__none';

/** The action's name as members know it — its title, never its code. */
const nameOf = (action: ActionDialogRecord) =>
    action.title?.trim() || 'this action';

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
                className="max-h-[90vh] overflow-y-auto"
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
    children,
    onClick,
    type = 'submit',
}: {
    processing: boolean;
    disabled?: boolean;
    children: ReactNode;
    onClick?: () => void;
    type?: 'submit' | 'button';
}) {
    return (
        <Button type={type} onClick={onClick} disabled={processing || disabled}>
            {processing ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
            {children}
        </Button>
    );
}

/** A visible reason beside a disabled button — never only a tooltip. */
function BlockedReason({ children }: { children: ReactNode }) {
    return (
        <p className="text-caption mr-auto self-center" role="status">
            {children}
        </p>
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
                    Say how far along “{nameOf(action)}” is. Progress on its own
                    doesn&apos;t finish the action — use Mark as done when the
                    work is complete.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid gap-4">
                <Field
                    label={`How far along — ${form.data.progress_pct}%`}
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
                    label="What's happened so far"
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
                        placeholder="e.g. Quotes received from two suppliers; choosing one next week"
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
    const missingReason = !form.data.blocked_reason.trim();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (missingReason) return;
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
                    Mark as blocked
                </DialogTitle>
                <DialogDescription>
                    Say what&apos;s stopping “{nameOf(action)}”, so the owner and
                    the board can sort it out. You can remove the blocker later.
                </DialogDescription>
            </DialogHeader>
            <div className="mt-3">
                <Field
                    label="What's stopping the work?"
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
                        placeholder="e.g. Waiting for the lawyer to send the final contract"
                    />
                </Field>
            </div>
            <DialogFooter className="mt-4">
                {missingReason ? (
                    <BlockedReason>Add a reason to continue.</BlockedReason>
                ) : null}
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <SubmitButton
                    processing={form.processing}
                    disabled={missingReason}
                >
                    Mark as blocked
                </SubmitButton>
            </DialogFooter>
        </form>
    );
}

// ── Raise with the board (escalate) ──────────────────────────────────────

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
    const missingReason = !form.data.escalation_reason.trim();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (missingReason) return;
        form.post(escalateAction.url({ action: action.id }), {
            preserveScroll: true,
            onSuccess: closeOnSuccess(onClose),
        });
    };

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <Flag className="h-4 w-4 text-primary" />
                    Raise with the board
                </DialogTitle>
                <DialogDescription>
                    The board chair and secretary get an email and a
                    notification asking them to look at “{nameOf(action)}”. Its
                    priority goes up too.
                </DialogDescription>
            </DialogHeader>
            <div className="mt-3">
                <Field
                    label="Why does the board need to look at this?"
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
                        placeholder="e.g. We need a board decision on the extra cost before we can go ahead"
                    />
                </Field>
            </div>
            <DialogFooter className="mt-4">
                {missingReason ? (
                    <BlockedReason>Add a reason to continue.</BlockedReason>
                ) : null}
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <SubmitButton
                    processing={form.processing}
                    disabled={missingReason}
                >
                    Raise with the board
                </SubmitButton>
            </DialogFooter>
        </form>
    );
}

// ── Change owner (reassign) ──────────────────────────────────────────────

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
    const unchanged =
        form.data.assigned_to === NONE ||
        form.data.assigned_to === String(action.assigned_to?.id ?? '');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (unchanged) return;
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
                    Change owner
                </DialogTitle>
                <DialogDescription>
                    Choose who is responsible for “{nameOf(action)}”. The new
                    owner can update its progress and mark it as done.
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
                                    {user.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
            </div>
            <DialogFooter className="mt-4">
                {unchanged ? (
                    <BlockedReason>
                        Choose a different person to continue.
                    </BlockedReason>
                ) : null}
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <SubmitButton processing={form.processing} disabled={unchanged}>
                    Change owner
                </SubmitButton>
            </DialogFooter>
        </form>
    );
}

// ── Mark as done ─────────────────────────────────────────────────────────

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

/** Why "Mark as done" can't be used yet, or null when it can. */
export function completionBlockedReason(
    notes: string,
    evidenceRequired: boolean,
    evidenceCount: number,
): string | null {
    if (notes.trim().length < 3) {
        return 'Add a note about what was done to continue.';
    }
    if (evidenceRequired && evidenceCount === 0) {
        return 'Upload evidence to continue.';
    }
    return null;
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
    const evidence = action.evidence ?? [];
    const earlier = action.legacy_evidence ?? [];
    const form = useForm({
        completion_notes: '',
        expected_version: action.version_number ?? 1,
        return_to: returnTo ?? '',
    });
    const [confirming, setConfirming] = useState(false);
    const blocked = completionBlockedReason(
        form.data.completion_notes,
        action.evidence_required,
        evidence.length + earlier.length,
    );

    const send = () => {
        form.transform((data) => ({
            ...data,
            // Only files uploaded to THIS action; the server checks that too.
            evidence_ids: evidence.map((file) => file.id),
            return_to: data.return_to || undefined,
        }));
        form.post(completeAction.url({ action: action.id }), {
            preserveScroll: true,
            onSuccess: closeOnSuccess(onClose),
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (blocked) return;
        setConfirming(true);
    };

    return (
        <form onSubmit={submit}>
            <DialogHeader>
                <DialogTitle className="flex items-center gap-2">
                    <CheckCircle className="h-4 w-4 text-status-success" />
                    Mark as done
                </DialogTitle>
                <DialogDescription>
                    Say what was done for “{nameOf(action)}”
                    {action.evidence_required
                        ? ' and add the evidence the board asked for'
                        : ''}
                    . A receipt is saved with the action.
                </DialogDescription>
            </DialogHeader>

            <div className="mt-3 grid gap-4">
                <Field
                    label="What was done?"
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
                        placeholder="e.g. The revised contract was signed by both parties on 12 September and filed"
                    />
                </Field>

                <div className="grid gap-2">
                    <p className="text-sm font-medium">
                        {action.evidence_required
                            ? 'Evidence (needed)'
                            : 'Evidence (optional)'}
                    </p>
                    <ActionEvidencePanel
                        actionId={action.id}
                        evidence={evidence}
                        earlierEvidence={earlier}
                        canUpload
                        required={action.evidence_required}
                    />
                </div>

                {form.errors.expected_version ? (
                    <InfoCard icon={AlertTriangle} tone="crit">
                        {form.errors.expected_version}
                    </InfoCard>
                ) : null}
            </div>

            <DialogFooter className="mt-4">
                {blocked ? <BlockedReason>{blocked}</BlockedReason> : null}
                <Button type="button" variant="outline" onClick={onClose}>
                    Cancel
                </Button>
                <SubmitButton
                    processing={form.processing}
                    disabled={Boolean(blocked)}
                >
                    Mark as done
                </SubmitButton>
            </DialogFooter>

            <ConfirmDialog
                open={confirming}
                onClose={() => setConfirming(false)}
                onConfirm={send}
                title="Mark this action as done?"
                description={`“${nameOf(action)}” will be marked as done and can't be reopened or changed afterwards. Your note${evidence.length > 0 ? ' and evidence are' : ' is'} saved with a receipt.`}
                confirmText="Mark as done"
                variant="default"
            />
        </form>
    );
}
