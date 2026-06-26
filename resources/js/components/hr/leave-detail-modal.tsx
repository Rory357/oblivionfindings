import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    AlertTriangle,
    ArrowUpCircle,
    CheckCircle2,
    Clock,
    ExternalLink,
    Lock,
    Paperclip,
    XCircle,
} from 'lucide-react';

import {
    InfoPill,
    LeaveAvatar,
    LeaveSlaChip,
    LeaveStatusChip,
    LeaveTypeChip,
    type ApprovalCardRequest,
} from './leave-hub-parts';

export type LeaveDetailRequest = ApprovalCardRequest & {
    period?: string;
    reviewed_at?: string | null;
    submitted_at?: string | null;
};

function fmtDateTime(value?: string | null): string {
    if (!value) return '—';
    const d = value.slice(0, 16).replace('T', ' ');
    return d;
}

function periodLabel(period?: string): string | null {
    if (!period || period === 'full_day') return null;
    if (period === 'half_day_am') return 'Half day (AM)';
    if (period === 'half_day_pm') return 'Half day (PM)';
    return period.replace(/_/g, ' ');
}

/**
 * In-page leave request detail — opens as a modal from the Overview list,
 * Approvals cards and the right-click menu (replaces navigating to the detail
 * page). Read-only facts + history, with the same approve / decline / extend /
 * escalate actions available inline for a pending request.
 */
export function LeaveDetailModal({
    request,
    can,
    processing,
    onClose,
    onApprove,
    onDecline,
    onExtendSla,
    onEscalate,
}: {
    request: LeaveDetailRequest | null;
    can: { approve?: boolean; manage?: boolean };
    processing?: boolean;
    onClose: () => void;
    onApprove: (id: number) => void;
    onDecline: (id: number) => void;
    onExtendSla: (id: number) => void;
    onEscalate: () => void;
}) {
    const r = request;
    const open = r !== null;
    const pending = r?.status === 'pending';
    const canAct = !!can.approve && pending;
    const conflict = r?.roster_conflict?.has_conflict
        ? r.roster_conflict.shifts[0]
        : null;
    const period = periodLabel(r?.period);

    return (
        <Dialog open={open} onOpenChange={(o) => (!o ? onClose() : undefined)}>
            <DialogContent className="max-w-lg">
                {r ? (
                    <>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-3">
                                <LeaveAvatar name={r.staff_name} size={40} />
                                <div className="min-w-0">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-[15px] font-bold">
                                            {r.staff_name}
                                        </span>
                                        <LeaveTypeChip type={r.leave_type} />
                                    </div>
                                    <div className="mt-0.5 text-xs font-normal text-muted-foreground">
                                        Request #{r.id} · submitted{' '}
                                        {fmtDateTime(r.submitted_at)}
                                    </div>
                                </div>
                                <span className="ml-auto">
                                    {pending ? (
                                        <LeaveSlaChip request={r} />
                                    ) : (
                                        <LeaveStatusChip status={r.status} />
                                    )}
                                </span>
                            </DialogTitle>
                        </DialogHeader>

                        <div className="space-y-3">
                            <div className="flex flex-wrap gap-2">
                                <InfoPill>
                                    {r.start_date} – {r.end_date}
                                </InfoPill>
                                <InfoPill>{r.hours}h</InfoPill>
                                {period ? <InfoPill>{period}</InfoPill> : null}
                                {r.balance_impact ? (
                                    <InfoPill
                                        tone={
                                            r.balance_impact.insufficient
                                                ? 'critical'
                                                : undefined
                                        }
                                    >
                                        <span className="text-muted-foreground">
                                            Balance
                                        </span>{' '}
                                        {r.balance_impact.remaining_before}h →{' '}
                                        {r.balance_impact.projected_after}h
                                        {r.balance_impact.insufficient
                                            ? ' ⚠ short'
                                            : ''}
                                    </InfoPill>
                                ) : null}
                            </div>

                            {conflict ? (
                                <div className="inline-flex items-center gap-2 rounded-lg border border-status-warning/30 bg-status-warning-bg px-2.5 py-1.5 text-xs font-semibold text-status-warning">
                                    <AlertTriangle className="h-3.5 w-3.5" />
                                    Roster conflict —{' '}
                                    {`${conflict.am_pm} ${conflict.site_name ?? ''} ${conflict.date ?? ''}`.trim()}
                                </div>
                            ) : null}

                            {r.reason ? (
                                <div className="rounded-lg border border-border bg-muted/40 p-3 text-[13px]">
                                    <div className="mb-1 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                                        Reason
                                    </div>
                                    <p className="text-foreground/80 italic">
                                        “{r.reason}”
                                    </p>
                                    {r.has_doc ? (
                                        <span className="mt-2 inline-flex items-center gap-1 rounded-md border border-border bg-accent px-2 py-0.5 text-[11px] font-bold text-accent-foreground">
                                            <Paperclip className="h-3 w-3" />{' '}
                                            Supporting document attached
                                        </span>
                                    ) : null}
                                </div>
                            ) : r.reason_restricted ? (
                                <div className="flex items-center gap-2 rounded-lg border border-border bg-muted/40 p-3 text-[12.5px] text-muted-foreground">
                                    <Lock className="h-3.5 w-3.5 flex-none" />
                                    Reason &amp; any supporting document are
                                    restricted — visible only to the employee
                                    and HR.
                                </div>
                            ) : null}

                            {!pending && (r.reviewed_by || r.reviewed_at) ? (
                                <div className="rounded-lg border border-border p-3 text-[13px]">
                                    <div className="mb-1 text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                                        Decision
                                    </div>
                                    <div className="text-foreground/80">
                                        {r.status === 'approved'
                                            ? 'Approved'
                                            : r.status === 'declined'
                                              ? 'Declined'
                                              : 'Reviewed'}
                                        {r.reviewed_by
                                            ? ` by ${r.reviewed_by}`
                                            : ''}
                                        {r.reviewed_at
                                            ? ` · ${fmtDateTime(r.reviewed_at)}`
                                            : ''}
                                    </div>
                                </div>
                            ) : null}

                            {r.escalated && r.escalated_from ? (
                                <p className="text-[11px] font-semibold text-muted-foreground">
                                    Escalated from {r.escalated_from}
                                </p>
                            ) : null}
                        </div>

                        <DialogFooter className="flex-wrap items-center gap-2">
                            <Button
                                asChild
                                variant="ghost"
                                size="sm"
                                className="mr-auto"
                            >
                                <a href={`/hr/leave/${r.id}`}>
                                    <ExternalLink className="mr-1.5 h-3.5 w-3.5" />
                                    Full page
                                </a>
                            </Button>
                            {canAct ? (
                                <>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={processing}
                                        onClick={() => onEscalate()}
                                    >
                                        <ArrowUpCircle className="mr-1 h-4 w-4" />{' '}
                                        Escalate
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={processing}
                                        onClick={() => onExtendSla(r.id)}
                                    >
                                        <Clock className="mr-1 h-4 w-4" /> +24h
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="border-status-critical/30 text-status-critical hover:bg-status-critical-bg"
                                        disabled={processing}
                                        onClick={() => onDecline(r.id)}
                                    >
                                        <XCircle className="mr-1 h-4 w-4" />{' '}
                                        Decline
                                    </Button>
                                    <Button
                                        size="sm"
                                        disabled={processing}
                                        onClick={() => onApprove(r.id)}
                                    >
                                        <CheckCircle2 className="mr-1 h-4 w-4" />{' '}
                                        Approve
                                    </Button>
                                </>
                            ) : (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={onClose}
                                >
                                    Close
                                </Button>
                            )}
                        </DialogFooter>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

export default LeaveDetailModal;
