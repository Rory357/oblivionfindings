import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { ExternalLink, Mail, ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';

export interface MailboxHealth {
    viewer_user_id: number;
    can_view: boolean;
    available: boolean;
    checked_at: string;
    settings_url: string | null;
    connections: {
        provider: 'microsoft' | 'google';
        status: 'connected' | 'disconnected' | 'error' | 'unknown';
        last_completed_poll_at: string | null;
        last_attempt_at: string | null;
        failure_category: string | null;
        consecutive_failed_polls: number;
        next_poll_at: string | null;
        operation_active: boolean;
        scan_pending: boolean;
        pending: {
            processing: number;
            acknowledgement: number;
            oldest_received_at: string | null;
            processing_attempts: number;
            acknowledgement_attempts: number;
        };
        quarantine: { count: number; oldest_received_at: string | null };
    }[];
}

export interface DeliveryHealth {
    viewer_user_id: number;
    available: boolean;
    checked_at: string;
    queued: number | null;
    sending: number | null;
    accepted: number | null;
    oldest_queued_at: string | null;
    oldest_unconfirmed_at: string | null;
    last_confirmed_delivery_at: string | null;
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0 space-y-1">
            <dt className="text-caption">{label}</dt>
            <dd className="text-subtle text-foreground">{children}</dd>
        </div>
    );
}

function pendingSince(value: string | null, count: number, checkedAt: string) {
    if (count === 0) return 'None awaiting';
    if (!value) return 'Time not recorded';
    return `${formatDateTime(value)} · ${formatRelative(value, Date.parse(checkedAt))} at this check`;
}

export function ItChannelHealth({
    mailbox,
    delivery,
    viewerId,
}: {
    mailbox?: MailboxHealth;
    delivery?: DeliveryHealth;
    viewerId: number | null;
}) {
    const mailboxVisible =
        mailbox?.viewer_user_id === viewerId && mailbox.can_view;
    const deliveryVisible =
        delivery?.viewer_user_id === viewerId && delivery.available;

    return (
        <div className="space-y-5">
            {mailbox ? (
                <Card role="region" aria-label="Mailbox processing health">
                    <CardHeader>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <CardTitle className="text-section-title flex items-center gap-2">
                                <ShieldCheck className="size-5 text-primary" />{' '}
                                Mailbox processing
                            </CardTitle>
                            {mailboxVisible && mailbox.settings_url ? (
                                <Button variant="outline" asChild>
                                    <a href={mailbox.settings_url}>
                                        <ExternalLink className="size-4" />{' '}
                                        Review mailbox recovery
                                    </a>
                                </Button>
                            ) : null}
                        </div>
                        <p className="text-subtle">
                            {!mailboxVisible
                                ? 'Mailbox diagnostics and recovery require integration settings access.'
                                : 'Review pending processing, acknowledgement and quarantine in the existing mailbox settings.'}
                        </p>
                    </CardHeader>
                    {mailboxVisible ? (
                        <CardContent className="space-y-5">
                            {!mailbox.available ? (
                                <p className="text-subtle">
                                    Mailbox health is unavailable. No healthy
                                    result has been recorded.
                                </p>
                            ) : mailbox.connections.length === 0 ? (
                                <p className="text-subtle">
                                    No mailbox connections are configured.
                                </p>
                            ) : (
                                mailbox.connections.map((connection) => (
                                    <section
                                        key={connection.provider}
                                        className="space-y-3"
                                        aria-label={`${connection.provider === 'microsoft' ? 'Microsoft' : 'Google'} mailbox health`}
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h3 className="text-section-title">
                                                {connection.provider ===
                                                'microsoft'
                                                    ? 'Microsoft'
                                                    : 'Google'}
                                            </h3>
                                            <StatusBadge
                                                variant={
                                                    connection.status ===
                                                    'error'
                                                        ? 'critical'
                                                        : connection.status ===
                                                            'connected'
                                                          ? 'success'
                                                          : 'neutral'
                                                }
                                            >
                                                {connection.status ===
                                                'connected'
                                                    ? 'Connected'
                                                    : connection.status ===
                                                        'error'
                                                      ? 'Needs attention'
                                                      : connection.status ===
                                                          'disconnected'
                                                        ? 'Disconnected'
                                                        : 'State unknown'}
                                            </StatusBadge>
                                            {connection.operation_active ? (
                                                <StatusBadge variant="info">
                                                    Poll running
                                                </StatusBadge>
                                            ) : null}
                                            {connection.scan_pending ? (
                                                <StatusBadge variant="warning">
                                                    Scan incomplete
                                                </StatusBadge>
                                            ) : null}
                                        </div>
                                        <dl className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                            <Fact label="Last completed poll">
                                                {formatDateTime(
                                                    connection.last_completed_poll_at,
                                                    'No completed poll recorded',
                                                )}
                                            </Fact>
                                            <Fact label="Last poll attempt">
                                                {formatDateTime(
                                                    connection.last_attempt_at,
                                                    'No attempt recorded',
                                                )}
                                            </Fact>
                                            <Fact label="Failure category">
                                                {connection.failure_category?.replaceAll(
                                                    '_',
                                                    ' ',
                                                ) ?? 'No recorded failure'}
                                            </Fact>
                                            <Fact label="Consecutive failed polls">
                                                {
                                                    connection.consecutive_failed_polls
                                                }
                                            </Fact>
                                            <Fact label="Retry after">
                                                {formatDateTime(
                                                    connection.next_poll_at,
                                                    'No retry delay recorded',
                                                )}
                                            </Fact>
                                            <Fact label="Awaiting work">
                                                {connection.pending.processing}{' '}
                                                processing ·{' '}
                                                {
                                                    connection.pending
                                                        .acknowledgement
                                                }{' '}
                                                acknowledgement
                                            </Fact>
                                            <Fact label="Oldest pending receipt">
                                                {pendingSince(
                                                    connection.pending
                                                        .oldest_received_at,
                                                    connection.pending
                                                        .processing +
                                                        connection.pending
                                                            .acknowledgement,
                                                    mailbox.checked_at,
                                                )}
                                            </Fact>
                                            <Fact label="Attempts on pending receipts">
                                                {
                                                    connection.pending
                                                        .processing_attempts
                                                }{' '}
                                                processing ·{' '}
                                                {
                                                    connection.pending
                                                        .acknowledgement_attempts
                                                }{' '}
                                                acknowledgement
                                            </Fact>
                                            <Fact label="Quarantine review">
                                                {connection.quarantine.count}{' '}
                                                retained ·{' '}
                                                {pendingSince(
                                                    connection.quarantine
                                                        .oldest_received_at,
                                                    connection.quarantine.count,
                                                    mailbox.checked_at,
                                                )}
                                            </Fact>
                                        </dl>
                                    </section>
                                ))
                            )}
                            <p className="text-caption">
                                Checked {formatDateTime(mailbox.checked_at)}.
                                Connection status alone does not prove a
                                completed poll.
                            </p>
                        </CardContent>
                    ) : null}
                </Card>
            ) : null}
            {delivery ? (
                <Card role="region" aria-label="Delivery backlog health">
                    <CardHeader>
                        <CardTitle className="text-section-title flex items-center gap-2">
                            <Mail className="size-5 text-primary" /> Delivery
                            backlog
                        </CardTitle>
                        <p className="text-subtle">
                            Current work you can access, including deliveries
                            beyond the rows shown below.
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {deliveryVisible ? (
                            <>
                                <dl className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                    <Fact label="Queued">
                                        {delivery.queued}
                                    </Fact>
                                    <Fact label="Sending, outcome unconfirmed">
                                        {delivery.sending}
                                    </Fact>
                                    <Fact label="Provider accepted, delivery unconfirmed">
                                        {delivery.accepted}
                                    </Fact>
                                    <Fact label="Oldest queued">
                                        {pendingSince(
                                            delivery.oldest_queued_at,
                                            delivery.queued ?? 0,
                                            delivery.checked_at,
                                        )}
                                    </Fact>
                                    <Fact label="Oldest unconfirmed">
                                        {pendingSince(
                                            delivery.oldest_unconfirmed_at,
                                            (delivery.sending ?? 0) +
                                                (delivery.accepted ?? 0),
                                            delivery.checked_at,
                                        )}
                                    </Fact>
                                    <Fact label="Last confirmed delivery">
                                        {formatDateTime(
                                            delivery.last_confirmed_delivery_at,
                                            'No confirmed delivery recorded',
                                        )}
                                    </Fact>
                                </dl>
                                <p className="text-subtle">
                                    Sending and accepted messages need provider
                                    reconciliation before another submission.
                                    Use the eligible failed-delivery recovery
                                    controls below.
                                </p>
                                <p className="text-caption">
                                    Checked{' '}
                                    {formatDateTime(delivery.checked_at)}.
                                </p>
                            </>
                        ) : (
                            <p className="text-subtle">
                                Current delivery health is unavailable. Refresh
                                to check your permitted work.
                            </p>
                        )}
                    </CardContent>
                </Card>
            ) : null}
        </div>
    );
}
