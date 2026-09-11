import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import type { ItApprovalRecord } from '@/hooks/it-approval-work';
import { formatDateTime } from '@/lib/datetime';
import { ShieldCheck } from 'lucide-react';

export const approvalStatus = (
    status?: string,
): { label: string; variant: StatusVariant } => {
    switch (status) {
        case 'approved':
            return { label: 'Approved', variant: 'success' };
        case 'rejected':
            return { label: 'Rejected', variant: 'critical' };
        case 'pending':
            return { label: 'Awaiting approval', variant: 'warning' };
        case 'expired':
            return { label: 'Expired', variant: 'warning' };
        case 'cancelled':
            return { label: 'Cancelled', variant: 'neutral' };
        default:
            return { label: 'Approval needed', variant: 'warning' };
    }
};
const basisLabel = (basis: string) =>
    ({
        primary: 'Primary approver',
        cover_primary_on_approved_leave:
            'Cover active while the primary approver is on approved leave',
        cover_primary_ineligible:
            'Cover active because the primary approver is no longer eligible',
        no_available_approver: 'No eligible approver is currently available',
        legacy_unassigned:
            'Historical request · no named assignment was recorded',
    })[basis] ?? 'Review current responsibility';
export function TicketApprovalRecord({
    record,
    anchor = true,
}: {
    record: ItApprovalRecord;
    anchor?: boolean;
}) {
    const badge = approvalStatus(record.status);
    return (
        <div
            id={anchor ? `approval-${record.id}` : undefined}
            tabIndex={anchor ? -1 : undefined}
            className="scroll-mt-24 space-y-3"
        >
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm font-semibold">
                    Request #{record.id}
                </span>
                <StatusBadge variant={badge.variant} size="sm">
                    {badge.label}
                </StatusBadge>
            </div>
            <div className="grid gap-4 xl:grid-cols-2">
                <ReviewCard
                    icon={ShieldCheck}
                    title="Request and responsibility"
                >
                    <ReviewRow
                        label="Requested by"
                        value={record.requested_by?.name ?? 'Not recorded'}
                    />
                    <ReviewRow
                        label="Requested at"
                        value={
                            record.requested_at
                                ? formatDateTime(record.requested_at)
                                : 'Not recorded'
                        }
                    />
                    <ReviewRow
                        label="Primary approver"
                        value={
                            record.primary?.name ??
                            'No named assignment recorded'
                        }
                    />
                    <ReviewRow
                        label="Absence cover"
                        value={record.cover?.name ?? 'No cover selected'}
                    />
                    {record.current_responsibility && (
                        <>
                            <ReviewRow
                                label="Responsible now"
                                value={
                                    record.current_responsibility.person
                                        ?.name ?? 'No named owner'
                                }
                            />
                            <p className="text-sm text-muted-foreground">
                                {basisLabel(
                                    record.current_responsibility.basis,
                                )}
                            </p>
                        </>
                    )}
                    <ReviewRow
                        label="Deadline"
                        value={
                            record.expires_at
                                ? formatDateTime(record.expires_at)
                                : 'No deadline set'
                        }
                    />
                    <ReviewRow
                        label="Reminder"
                        value={
                            record.remind_at
                                ? formatDateTime(record.remind_at)
                                : 'No reminder set'
                        }
                    />
                    {record.reminder_prepared_at && (
                        <p className="text-sm text-muted-foreground">
                            Reminder queued{' '}
                            {formatDateTime(record.reminder_prepared_at)}. This
                            does not confirm delivery.
                        </p>
                    )}
                </ReviewCard>
                <ReviewCard
                    icon={ShieldCheck}
                    title="Recorded reasons and outcome"
                >
                    <ReviewRow
                        label="Request reason"
                        value={
                            <span className="break-words whitespace-pre-wrap">
                                {record.reason_evidence.request.value ??
                                    (record.reason_evidence.request
                                        .provenance === 'legacy_unattributed'
                                        ? 'No separately attributed request reason'
                                        : 'No reason supplied')}
                            </span>
                        }
                    />
                    {record.reason_evidence.legacy_reason && (
                        <ReviewRow
                            label="Historical note · attribution unknown"
                            value={
                                <span className="break-words whitespace-pre-wrap">
                                    {record.reason_evidence.legacy_reason}
                                </span>
                            }
                        />
                    )}
                    {record.decided_at && (
                        <>
                            <ReviewRow
                                label="Decision by"
                                value={
                                    record.decided_by?.name ?? 'Not recorded'
                                }
                            />
                            <ReviewRow
                                label="Decision at"
                                value={formatDateTime(record.decided_at)}
                            />
                            <ReviewRow
                                label="Decision reason"
                                value={
                                    <span className="break-words whitespace-pre-wrap">
                                        {record.reason_evidence.decision
                                            .value ??
                                            'No separately attributed reason recorded'}
                                    </span>
                                }
                            />
                        </>
                    )}
                    {record.status === 'expired' && (
                        <p className="text-sm">
                            {record.expired_at
                                ? `Expiry recorded ${formatDateTime(record.expired_at)}.`
                                : 'The deadline has passed. The expiry job has not yet recorded the transition.'}{' '}
                            No human decision is implied.
                        </p>
                    )}
                    {record.status === 'cancelled' && (
                        <>
                            <ReviewRow
                                label="Cancelled at"
                                value={
                                    record.cancelled_at
                                        ? formatDateTime(record.cancelled_at)
                                        : 'Not recorded'
                                }
                            />
                            <ReviewRow
                                label="Cancellation reason"
                                value={
                                    <span className="break-words whitespace-pre-wrap">
                                        {record.cancellation_reason ??
                                            'Not recorded'}
                                    </span>
                                }
                            />
                        </>
                    )}
                    {['rejected', 'expired', 'cancelled'].includes(
                        record.status,
                    ) && (
                        <p className="text-sm text-muted-foreground">
                            This request is no longer awaiting a decision. Its
                            evidence is preserved.
                        </p>
                    )}
                </ReviewCard>
            </div>
        </div>
    );
}
