import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import { Check, ShieldCheck, XCircle } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { toast } from 'sonner';

export interface TicketApprovalSummary {
    id: number;
    status: string;
    requested_by_name: string | null;
    approver_name: string | null;
    reason: string | null;
    requested_at: string | null;
    decided_at: string | null;
}

interface Props {
    ticket: {
        id: number;
        reference: string | null;
        approval: TicketApprovalSummary | null;
    };
    canRequest: boolean;
    canDecide: boolean;
    formatDateTime: (iso: string | null) => string;
}

type ApprovalAction = 'request' | 'approve' | 'reject';

export function TicketApprovalControls({
    ticket,
    canRequest,
    canDecide,
    formatDateTime,
}: Props) {
    const [action, setAction] = useState<ApprovalAction | null>(null);
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const approvalBadge = ((): { label: string; variant: StatusVariant } => {
        switch (ticket.approval?.status) {
            case 'approved':
                return { label: 'Approved', variant: 'success' };
            case 'rejected':
                return { label: 'Rejected', variant: 'critical' };
            case 'pending':
                return { label: 'Awaiting approval', variant: 'warning' };
            default:
                return { label: 'Approval needed', variant: 'warning' };
        }
    })();

    const approvalMeta = (() => {
        const approval = ticket.approval;
        if (!approval) {
            return 'A manager must approve this before the ticket can be settled.';
        }
        if (approval.status === 'pending') {
            return `Requested by ${approval.requested_by_name ?? 'an IT technician'}${approval.requested_at ? ` · ${formatDateTime(approval.requested_at)}` : ''}${approval.reason ? ` — ${approval.reason}` : ''}.`;
        }

        const verb = approval.status === 'approved' ? 'Approved' : 'Rejected';
        return `${verb} by ${approval.approver_name ?? 'an IT manager'}${approval.decided_at ? ` · ${formatDateTime(approval.decided_at)}` : ''}${approval.reason ? ` — ${approval.reason}` : ''}.`;
    })();

    const begin = (nextAction: ApprovalAction) => {
        setReason('');
        setErrors({});
        setAction(nextAction);
    };

    const close = () => {
        if (!processing) {
            setAction(null);
            setReason('');
            setErrors({});
        }
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (!action) return;

        const requesting = action === 'request';
        const url = requesting
            ? `/it/tickets/${ticket.id}/approvals`
            : `/it/approvals/${ticket.approval?.id}/decide`;
        const payload = requesting
            ? { reason: reason.trim() || null }
            : { decision: action, reason: reason.trim() || null };

        router.post(url, payload, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onError: (nextErrors) => setErrors(nextErrors),
            onSuccess: () => {
                setAction(null);
                setReason('');
                setErrors({});
                toast.success(
                    requesting
                        ? 'Approval requested.'
                        : action === 'approve'
                          ? 'Approval granted.'
                          : 'Approval rejected.',
                );
            },
            onFinish: () => setProcessing(false),
        });
    };

    const dialogCopy = (() => {
        switch (action) {
            case 'request':
                return {
                    title: 'Request manager approval',
                    description:
                        'Send this ticket to another IT manager for a recorded decision before settlement.',
                    label: 'Why is approval needed? (optional)',
                    confirm: 'Request approval',
                };
            case 'approve':
                return {
                    title: 'Approve this request',
                    description:
                        'Confirm that the requested work may proceed. Your decision is written to the ticket timeline and audit history.',
                    label: 'Decision note (optional)',
                    confirm: 'Approve request',
                };
            case 'reject':
                return {
                    title: 'Reject this request',
                    description:
                        'Stop this request from being settled as approved. Explain what must change so the requester can act.',
                    label: 'Reason for rejection',
                    confirm: 'Reject request',
                };
            default:
                return null;
        }
    })();

    return (
        <>
            <div className="rounded-2xl border border-border bg-muted/30 px-4 py-3.5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <ShieldCheck
                            className="h-4 w-4 flex-none text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span className="text-[13px] font-semibold">
                            Manager approval
                        </span>
                        <StatusBadge variant={approvalBadge.variant} size="sm">
                            {approvalBadge.label}
                        </StatusBadge>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {canRequest ? (
                            <Button
                                size="sm"
                                variant="outline"
                                className="min-h-11"
                                onClick={() => begin('request')}
                            >
                                <ShieldCheck
                                    className="h-4 w-4"
                                    aria-hidden="true"
                                />
                                Request approval
                            </Button>
                        ) : null}
                        {canDecide ? (
                            <>
                                <Button
                                    size="sm"
                                    className="min-h-11"
                                    onClick={() => begin('approve')}
                                >
                                    <Check
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    Approve
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="min-h-11"
                                    onClick={() => begin('reject')}
                                >
                                    <XCircle
                                        className="h-4 w-4"
                                        aria-hidden="true"
                                    />
                                    Reject
                                </Button>
                            </>
                        ) : null}
                    </div>
                </div>
                <p className="mt-1.5 text-[12px] text-muted-foreground">
                    {approvalMeta}
                </p>
            </div>

            <Dialog
                open={action !== null}
                onOpenChange={(open) => !open && close()}
            >
                <DialogContent className="sm:max-w-lg">
                    {dialogCopy ? (
                        <form onSubmit={submit}>
                            <DialogHeader>
                                <DialogTitle>{dialogCopy.title}</DialogTitle>
                                <DialogDescription>
                                    {dialogCopy.description}
                                </DialogDescription>
                            </DialogHeader>
                            <div className="mt-5 space-y-2">
                                <label
                                    htmlFor="ticket-approval-reason"
                                    className="text-sm font-medium"
                                >
                                    {dialogCopy.label}
                                </label>
                                <Textarea
                                    id="ticket-approval-reason"
                                    value={reason}
                                    onChange={(event) =>
                                        setReason(event.target.value)
                                    }
                                    required={action === 'reject'}
                                    rows={4}
                                    maxLength={1000}
                                    aria-invalid={
                                        errors.reason ? true : undefined
                                    }
                                    aria-describedby={
                                        errors.reason
                                            ? 'ticket-approval-reason-error'
                                            : undefined
                                    }
                                />
                                {errors.reason ? (
                                    <p
                                        id="ticket-approval-reason-error"
                                        role="alert"
                                        className="text-sm text-destructive"
                                    >
                                        {errors.reason}
                                    </p>
                                ) : null}
                            </div>
                            <DialogFooter className="mt-6 gap-2 sm:gap-0">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="min-h-11"
                                    disabled={processing}
                                    onClick={close}
                                >
                                    Keep reviewing
                                </Button>
                                <Button
                                    type="submit"
                                    variant={
                                        action === 'reject'
                                            ? 'destructive'
                                            : 'default'
                                    }
                                    className="min-h-11"
                                    disabled={processing}
                                >
                                    {action === 'approve' ? (
                                        <Check
                                            className="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                    ) : action === 'reject' ? (
                                        <XCircle
                                            className="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                    ) : (
                                        <ShieldCheck
                                            className="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                    )}
                                    {processing
                                        ? 'Saving decision…'
                                        : dialogCopy.confirm}
                                </Button>
                            </DialogFooter>
                        </form>
                    ) : null}
                </DialogContent>
            </Dialog>
        </>
    );
}
