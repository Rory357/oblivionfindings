/**
 * Answer 20 (Stephan, 1 Nov 2026; board M5x): correcting agreed findings. "Correct the findings" sends a correction
 * with its reason for independent review and approval, like the original findings; the original stands until it is
 * approved and both stay in History. The review/approve/reject controls show only where the server says this viewer
 * may decide (`can_review` / `can_approve` / `can_reject`), and the server checks independence again.
 */
import { router, useForm } from '@inertiajs/react';
import { Check, PencilLine, X } from 'lucide-react';
import { useEffect, useState } from 'react';

import { PopupField, PopupForm } from '@/components/popup';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/datetime';

export type InvestigationCorrection = {
    id: number;
    status: 'proposed' | 'reviewed';
    before_value: string | null;
    after_value: string;
    reason: string;
    proposed_by_name: string;
    proposed_at: string | null;
    reviewed_by_name: string | null;
    can_review: boolean;
    can_approve: boolean;
    can_reject: boolean;
};

export function CorrectFindingsDialog({
    open,
    onClose,
    eventId,
    investigationId,
    context,
    current,
    agreedAt,
    ownerName,
    viewerName,
}: {
    open: boolean;
    onClose: () => void;
    eventId: number;
    investigationId: number;
    context: string;
    current: string;
    agreedAt: string | null;
    ownerName: string | null;
    viewerName: string | null;
}) {
    const form = useForm<{ after_value: string; reason: string }>({ after_value: current, reason: '' });
    useEffect(() => {
        if (open) {
            form.setData('after_value', current);
            form.setData('reason', '');
        } else form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, current]);

    const unchanged = form.data.after_value.trim() === current.trim() || !form.data.after_value.trim();
    const reasonShort = form.data.reason.trim().length < 10;

    return (
        <PopupForm
            open={open}
            onClose={onClose}
            size="md"
            icon={PencilLine}
            title="Correct the findings"
            description="Goes for independent review and approval, like the original findings"
            context={{ title: context, note: 'The original findings stay as they are until this is approved.' }}
            submitLabel="Send for review"
            processingLabel="Sending…"
            processing={form.processing}
            submitDisabled={unchanged || reasonShort}
            onSubmit={() =>
                form.post(`/health-safety/events/${eventId}/investigations/${investigationId}/corrections`, {
                    preserveScroll: true,
                    onSuccess: () => onClose(),
                })
            }
            dirty={form.isDirty}
            draftName="this correction"
            footerStart={viewerName ? <span className="text-caption">Saved as {viewerName}</span> : undefined}
            testId="correct-findings-dialog"
        >
            <PopupField label="Findings" htmlFor="correct-findings" required error={form.errors.after_value}>
                <Textarea
                    id="correct-findings"
                    rows={5}
                    value={form.data.after_value}
                    onChange={(event) => form.setData('after_value', event.target.value)}
                />
            </PopupField>
            <p className="rounded-lg bg-muted px-3 py-2 text-sm whitespace-pre-line text-muted-foreground">
                <span className="font-semibold">Original{agreedAt ? ` (${formatDate(agreedAt)})` : ''}:</span>{' '}
                {current || 'No findings were written.'}
            </p>
            <PopupField
                label="Why the change?"
                htmlFor="correct-findings-why"
                required
                hint={`Needed. The original stands until the correction is approved; both stay in History.${ownerName ? ` ${ownerName} (owner) is told.` : ''}`}
                error={form.errors.reason}
            >
                <Textarea
                    id="correct-findings-why"
                    rows={3}
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                />
            </PopupField>
        </PopupForm>
    );
}

/** A correction waiting for review or approval, with the decisions this viewer may make. */
export function PendingCorrection({ eventId, correction }: { eventId: number; correction: InvestigationCorrection }) {
    const [rejecting, setRejecting] = useState(false);
    const [busy, setBusy] = useState(false);
    const decide = (what: 'review' | 'approve') => {
        setBusy(true);
        router.post(
            `/health-safety/events/${eventId}/corrections/${correction.id}/${what}`,
            {},
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };
    const reviewed = correction.status === 'reviewed';

    return (
        <div className="grid gap-2 rounded-lg bg-muted px-3 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <b className="font-semibold">Correction to the findings</b>
                <StatusBadge variant="warning" size="sm" label={reviewed ? 'Waiting for approval' : 'Waiting for review'} />
                <span className="text-caption">
                    Proposed by {correction.proposed_by_name}
                    {correction.proposed_at ? `, ${formatDate(correction.proposed_at)}` : ''}
                    {correction.reviewed_by_name ? ` · reviewed by ${correction.reviewed_by_name}` : ''}
                </span>
            </div>
            <p className="whitespace-pre-line">
                <span className="font-semibold text-muted-foreground">Why: </span>
                {correction.reason}
            </p>
            <p className="whitespace-pre-line">
                <span className="font-semibold text-muted-foreground">After: </span>
                {correction.after_value}
            </p>
            {correction.can_review || correction.can_approve || correction.can_reject ? (
                <div className="flex flex-wrap gap-2">
                    {correction.can_review ? (
                        <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => decide('review')}>
                            <Check className="size-4" aria-hidden="true" />
                            Mark reviewed
                        </Button>
                    ) : null}
                    {correction.can_approve ? (
                        <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => decide('approve')}>
                            <Check className="size-4" aria-hidden="true" />
                            Approve correction
                        </Button>
                    ) : null}
                    {correction.can_reject ? (
                        <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => setRejecting(true)}>
                            <X className="size-4" aria-hidden="true" />
                            Don’t accept
                        </Button>
                    ) : null}
                </div>
            ) : (
                <p className="text-caption">
                    {reviewed
                        ? 'An approver who isn’t on the investigation and didn’t review it approves it next.'
                        : 'Someone who didn’t propose it or work on the investigation reviews it next.'}
                </p>
            )}
            <RejectCorrectionDialog
                open={rejecting}
                onClose={() => setRejecting(false)}
                eventId={eventId}
                correctionId={correction.id}
            />
        </div>
    );
}

function RejectCorrectionDialog({
    open,
    onClose,
    eventId,
    correctionId,
}: {
    open: boolean;
    onClose: () => void;
    eventId: number;
    correctionId: number;
}) {
    const form = useForm<{ reason: string }>({ reason: '' });
    return (
        <PopupForm
            open={open}
            onClose={onClose}
            icon={X}
            title="Don’t accept the correction"
            description="The findings stay as they are, and the reason is kept in History. The owner is told."
            submitLabel="Don’t accept"
            processing={form.processing}
            submitDisabled={form.data.reason.trim().length < 10}
            onSubmit={() =>
                form.post(`/health-safety/events/${eventId}/corrections/${correctionId}/reject`, {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset();
                        onClose();
                    },
                })
            }
            dirty={form.isDirty}
        >
            <PopupField label="Why not?" htmlFor="reject-correction-why" required hint="Needed, at least 10 characters." error={form.errors.reason}>
                <Textarea
                    id="reject-correction-why"
                    rows={3}
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                />
            </PopupField>
        </PopupForm>
    );
}
