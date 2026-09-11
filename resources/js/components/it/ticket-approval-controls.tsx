import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import type { ItApprovalWork } from '@/hooks/it-approval-work';
import type {
    ItApprovalCommitted,
    ItApprovalOperation,
} from '@/hooks/it-ticket-approval-contract';
import { pendingItApprovalCommands } from '@/hooks/use-it-ticket-approval-command';
import {
    purgeItApprovalMemory,
    useItApprovalMemoryNotices,
} from '@/hooks/use-it-ticket-draft-memory';
import { ShieldCheck } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { TicketApprovalDialog } from './ticket-approval-dialog';
import { TicketApprovalHistory } from './ticket-approval-history';
import { approvalStatus, TicketApprovalRecord } from './ticket-approval-record';

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
    actorId: number;
    ticket: {
        id: number;
        reference: string | null;
        lock_version: number;
        approval: TicketApprovalSummary | null;
    };
    work: ItApprovalWork | null;
    onCommitted: (result: ItApprovalCommitted) => void;
    onAccessLost: () => void;
    onSessionExpired?: () => void;
}
type Action = {
    operation: ItApprovalOperation;
    approvalId: number | null;
    decision?: 'approve' | 'reject';
};
export function TicketApprovalControls({
    actorId,
    ticket,
    work,
    onCommitted,
    onAccessLost,
    onSessionExpired,
}: Props) {
    const [action, setAction] = useState<Action | null>(null);
    const [denied, setDenied] = useState(false);
    const [scope] = useState(`${actorId}:${ticket.id}`);
    const previous = useRef({ actorId, ticketId: ticket.id });
    const sameScope = scope === `${actorId}:${ticket.id}`;
    const notices = useItApprovalMemoryNotices(actorId, ticket.id);
    const canView = work !== null && sameScope && !denied;
    let pending: ReturnType<typeof pendingItApprovalCommands> = [];
    let journalUnavailable = false;
    if (canView) {
        try {
            pending = pendingItApprovalCommands(actorId, ticket.id);
        } catch {
            journalUnavailable = true;
        }
    }
    useEffect(() => {
        if (!canView) {
            purgeItApprovalMemory(
                previous.current.actorId,
                previous.current.ticketId,
            );
            setAction(null);
        }
    }, [canView]);
    useEffect(() => {
        if (!canView || (!notices.length && !pending.length)) return;
        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', warn);
        return () => window.removeEventListener('beforeunload', warn);
    }, [canView, notices.length, pending.length]);
    const deny = () => {
        purgeItApprovalMemory(actorId, ticket.id);
        setDenied(true);
        setAction(null);
        onAccessLost();
    };
    const badge = approvalStatus(
        canView ? work?.current?.status : ticket.approval?.status,
    );
    if (!sameScope || denied)
        return (
            <p role="status" className="text-sm">
                Current access to approval work is unavailable. Refresh this
                ticket before continuing.
            </p>
        );
    return (
        <div className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2">
                    <ShieldCheck
                        aria-hidden="true"
                        className="h-4 w-4 text-muted-foreground"
                    />
                    <span className="text-sm font-semibold">
                        Manager approval
                    </span>
                    <StatusBadge variant={badge.variant} size="sm">
                        {badge.label}
                    </StatusBadge>
                </div>
                {canView && (
                    <div className="flex flex-wrap gap-2">
                        {work.can_request && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setAction({
                                        operation: 'request',
                                        approvalId: null,
                                    })
                                }
                            >
                                Request approval
                            </Button>
                        )}
                        {work.can_decide && work.current && (
                            <>
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        setAction({
                                            operation: 'decide',
                                            approvalId: work.current!.id,
                                            decision: 'approve',
                                        })
                                    }
                                >
                                    Approve
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setAction({
                                            operation: 'decide',
                                            approvalId: work.current!.id,
                                            decision: 'reject',
                                        })
                                    }
                                >
                                    Reject
                                </Button>
                            </>
                        )}
                        {work.can_withdraw && work.current && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setAction({
                                        operation: 'withdraw',
                                        approvalId: work.current!.id,
                                    })
                                }
                            >
                                Cancel request
                            </Button>
                        )}
                    </div>
                )}
            </div>
            {!canView ? (
                <p className="text-sm text-muted-foreground">
                    An authorized IT manager records the decision before this
                    ticket can be settled.
                </p>
            ) : (
                <>
                    {!work.storage_ready && (
                        <p role="status" className="text-sm">
                            Approval history storage is not ready. Approval
                            changes are unavailable until setup is complete.
                        </p>
                    )}
                    {journalUnavailable && (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            Pending approval references could not be read.
                            Restore access to browser session storage before
                            sending another command.
                        </p>
                    )}
                    {!action &&
                        notices.map((notice, index) => (
                            <div
                                key={notice.bufferId}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border p-3"
                            >
                                <span className="text-sm">
                                    Retained approval draft {index + 1} ·{' '}
                                    {notice.context.operation}
                                </span>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setAction({
                                            operation: notice.context.operation,
                                            approvalId:
                                                notice.context.approvalId,
                                        })
                                    }
                                >
                                    Review retained approval draft {index + 1}
                                </Button>
                            </div>
                        ))}
                    {!action &&
                        pending.map((reference, index) => (
                            <div
                                key={reference.requestUuid}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border p-3"
                            >
                                <span className="text-sm">
                                    Pending approval command {index + 1} ·{' '}
                                    {reference.operation}
                                </span>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setAction({
                                            operation: reference.operation,
                                            approvalId: reference.approvalId,
                                        })
                                    }
                                >
                                    Review pending approval command {index + 1}
                                </Button>
                            </div>
                        ))}
                    {work.current ? (
                        <TicketApprovalRecord record={work.current} />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No approval request has been recorded. Choose the
                            responsible approver to begin.
                        </p>
                    )}
                    {work.storage_ready && (
                        <TicketApprovalHistory
                            actorId={actorId}
                            ticketId={ticket.id}
                            version={ticket.lock_version}
                            total={work.total}
                            currentApprovalId={work.current?.id ?? null}
                            onAccessLost={deny}
                            onSessionExpired={onSessionExpired}
                        />
                    )}
                    {action && (
                        <TicketApprovalDialog
                            key={`${actorId}:${ticket.id}:${action.operation}:${action.approvalId}`}
                            actorId={actorId}
                            ticketId={ticket.id}
                            version={ticket.lock_version}
                            work={work}
                            approvalId={action.approvalId}
                            operation={action.operation}
                            initialDecision={action.decision}
                            onClose={() => setAction(null)}
                            onCommitted={onCommitted}
                            onAccessLost={deny}
                            onSessionExpired={onSessionExpired}
                        />
                    )}
                </>
            )}
        </div>
    );
}
