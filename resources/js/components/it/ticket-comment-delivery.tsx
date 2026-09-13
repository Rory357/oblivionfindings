import { Button } from '@/components/ui/button';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';

export type TicketCommentDeliveryStatus =
    | 'queued'
    | 'sending'
    | 'accepted'
    | 'delivered'
    | 'failed'
    | 'bounced'
    | 'retried';

/** Current leaf-attempt counts, already filtered by the canonical presenter. */
export interface TicketCommentDeliveryData {
    tracking: 'recorded' | 'unrecorded';
    requested: boolean;
    attempt_statuses: Partial<Record<TicketCommentDeliveryStatus, number>>;
    checked_at: string;
    review_url: string | null;
}

const statuses: Record<
    TicketCommentDeliveryStatus,
    { label: string; variant: StatusVariant }
> = {
    queued: { label: 'Queued', variant: 'neutral' },
    sending: { label: 'Sending', variant: 'info' },
    accepted: { label: 'Accepted by provider', variant: 'info' },
    delivered: { label: 'Delivered', variant: 'success' },
    failed: { label: 'Failed', variant: 'critical' },
    bounced: { label: 'Bounced', variant: 'critical' },
    retried: { label: 'Retry recorded', variant: 'neutral' },
};

function deliveryLogUrl(value: string | null): string | null {
    if (!value || !/^\/it\/setup(?:[?#]|$)/.test(value) || /[\s\\]/.test(value))
        return null;
    try {
        const url = new URL(value, 'https://local.invalid');
        return url.origin === 'https://local.invalid' &&
            url.pathname === '/it/setup'
            ? value
            : null;
    } catch {
        return null;
    }
}

/** No transport or recipient data: the host owns refresh and current access. */
export function TicketCommentDelivery({
    delivery,
    onRefresh,
    refreshing = false,
}: {
    delivery: TicketCommentDeliveryData;
    onRefresh?: () => void;
    refreshing?: boolean;
}) {
    const entries = Object.entries(delivery.attempt_statuses);
    const validCounts = entries.every(
        ([status, count]) =>
            Object.hasOwn(statuses, status) &&
            Number.isSafeInteger(count) &&
            count >= 0,
    );
    const counts = validCounts
        ? (Object.keys(statuses) as TicketCommentDeliveryStatus[]).flatMap(
              (status) => {
                  const count = delivery.attempt_statuses[status] ?? 0;
                  return count > 0 ? [[status, count] as const] : [];
              },
          )
        : [];
    const recorded = delivery.tracking === 'recorded';
    const countsUnavailable =
        recorded &&
        (!validCounts || (!delivery.requested && counts.length > 0));
    const showCounts = recorded && delivery.requested && !countsUnavailable;
    const reviewUrl = deliveryLogUrl(delivery.review_url);
    const checked = formatDateTime(delivery.checked_at, '');
    const message = !recorded
        ? 'Delivery tracking was not recorded for this message.'
        : countsUnavailable
          ? onRefresh
              ? 'Delivery counts are unavailable. Refresh to check again.'
              : 'Delivery counts are unavailable.'
          : !delivery.requested
            ? 'No email notification was requested.'
            : counts.length === 0
              ? 'Email was requested; no delivery attempt is recorded yet.'
              : 'Email notification attempts';

    return (
        <div
            role="group"
            aria-label="Email delivery"
            aria-busy={refreshing}
            className="space-y-2 border-t border-border pt-3 text-xs"
        >
            <div className="space-y-2" aria-live="polite">
                <p className="text-muted-foreground">{message}</p>
                {showCounts && counts.length > 0 && (
                    <ul
                        aria-label="Current delivery attempts"
                        className="flex flex-wrap gap-2"
                    >
                        {counts.map(([status, count]) => {
                            const item = statuses[status];
                            return (
                                <li key={status}>
                                    <StatusBadge
                                        variant={item.variant}
                                        size="sm"
                                    >
                                        {item.label}: {count}
                                    </StatusBadge>
                                </li>
                            );
                        })}
                    </ul>
                )}
                {showCounts &&
                    (delivery.attempt_statuses.accepted ?? 0) > 0 && (
                        <p className="text-muted-foreground">
                            Provider acceptance does not confirm delivery.
                        </p>
                    )}
                {showCounts && (delivery.attempt_statuses.sending ?? 0) > 0 && (
                    <p className="text-muted-foreground">
                        The sending outcome is not yet confirmed.
                    </p>
                )}
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-muted-foreground">
                    {checked ? (
                        <>
                            <span>Checked </span>
                            <time dateTime={delivery.checked_at}>
                                {checked}
                            </time>
                        </>
                    ) : (
                        'Check time unavailable.'
                    )}
                </span>
                {onRefresh && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="xs"
                        disabled={refreshing}
                        onClick={onRefresh}
                    >
                        {refreshing
                            ? 'Refreshing delivery status…'
                            : 'Refresh delivery status'}
                    </Button>
                )}
                {reviewUrl && (
                    <Button asChild variant="link" size="xs">
                        <a href={reviewUrl}>Review delivery log</a>
                    </Button>
                )}
            </div>
        </div>
    );
}
