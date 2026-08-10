/* eslint-disable no-restricted-syntax -- Bulk reject: a single-form dialog
 * posting to the bulk endpoint, mirroring the single-reject wizard's
 * reason chips + optional decline-email toggle. Native inputs; semantic
 * tokens. */
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

const REJECT_REASONS = [
    'Not enough experience',
    'Values mismatch',
    'Failed safety check',
    'Position filled',
    'Withdrew',
    'Other',
];

export function BulkRejectDialog({
    open,
    onClose,
    candidateIds,
    onDone,
}: {
    open: boolean;
    onClose: () => void;
    candidateIds: number[];
    onDone: () => void;
}) {
    const [reason, setReason] = useState('');
    const [sendDecline, setSendDecline] = useState(false);
    const [message, setMessage] = useState('');
    const form = useForm({});

    const count = candidateIds.length;
    const canSubmit = reason !== '' && count > 0;

    const submit = () => {
        form.transform(() => ({
            action: 'reject',
            candidate_ids: candidateIds,
            reason,
            send_decline_email: sendDecline,
            decline_message:
                sendDecline && message.trim() !== '' ? message.trim() : null,
        }));
        form.post('/hr/recruitment/applications/bulk', {
            preserveScroll: true,
            onSuccess: (page) => {
                const f = (
                    page.props as {
                        flash?: { error?: string; success?: string };
                    }
                ).flash;
                if (f?.error) {
                    toast.error('Could not reject', { description: f.error });
                    return;
                }
                toast.success(f?.success ?? `${count} candidate(s) rejected`);
                setReason('');
                setSendDecline(false);
                setMessage('');
                onDone();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Reject {count} candidate{count === 1 ? '' : 's'}
                    </DialogTitle>
                    <DialogDescription>
                        Closes out every selected candidate's active
                        applications. The reason is recorded to the audit trail.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-4">
                    <div>
                        <Label className="mb-1.5 block text-sm font-semibold">
                            Reason
                        </Label>
                        <div className="flex flex-wrap gap-2">
                            {REJECT_REASONS.map((r) => {
                                const on = reason === r;
                                return (
                                    <button
                                        key={r}
                                        type="button"
                                        aria-pressed={on}
                                        onClick={() => setReason(r)}
                                        className={cn(
                                            'rounded-full border px-3 py-1.5 text-[13px] font-medium transition-colors',
                                            on
                                                ? 'border-status-critical bg-status-critical-bg text-status-critical'
                                                : 'border-border bg-card hover:border-status-critical/50',
                                        )}
                                    >
                                        {r}
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    <label className="flex cursor-pointer items-start gap-2.5 rounded-lg border border-border bg-card p-3">
                        <input
                            type="checkbox"
                            checked={sendDecline}
                            onChange={(e) => setSendDecline(e.target.checked)}
                            className="mt-0.5 h-4 w-4 accent-[var(--primary)]"
                        />
                        <span>
                            <span className="block text-[13px] font-semibold">
                                Send respectful decline email
                            </span>
                            <span className="block text-[12px] text-muted-foreground">
                                Optional — a warm, brand-consistent decline to
                                each candidate with an email on file.
                            </span>
                        </span>
                    </label>

                    {sendDecline ? (
                        <div>
                            <Label className="mb-1.5 block text-sm font-semibold">
                                Personal note{' '}
                                <span className="font-normal text-muted-foreground">
                                    (optional, included in the email)
                                </span>
                            </Label>
                            <textarea
                                value={message}
                                onChange={(e) => setMessage(e.target.value)}
                                rows={4}
                                maxLength={2000}
                                placeholder="e.g. We were impressed with your experience and encourage you to apply for future roles."
                                className="w-full resize-y rounded-md border border-border bg-card p-2.5 text-[13px] outline-none focus:border-primary"
                            />
                        </div>
                    ) : null}
                </div>

                <DialogFooter>
                    <button
                        type="button"
                        onClick={onClose}
                        className="h-9 rounded-md border border-border bg-card px-4 text-[13px] font-semibold hover:bg-muted"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={!canSubmit || form.processing}
                        className="h-9 rounded-md bg-status-critical px-4 text-[13px] font-bold text-white disabled:opacity-50"
                    >
                        Reject {count}
                    </button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default BulkRejectDialog;
