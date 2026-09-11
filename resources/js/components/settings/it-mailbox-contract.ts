export type MailboxProvider = 'microsoft' | 'google';
export type MailboxConnection = {
    id: number | null;
    version: number | null;
    configured: boolean;
    status: 'connected' | 'disconnected' | 'error' | null;
    account_email: string | null;
    account_name: string | null;
    mailbox_email: string | null;
    effective_mailbox: string | null;
    last_polled_at: string | null;
    last_poll_attempt_at: string | null;
    next_poll_at: string | null;
    operation_active: boolean;
    can_poll: boolean;
    authorization_required: boolean;
    last_error: string | null;
    scan_pending: boolean;
    counts: {
        awaiting_processing: number;
        awaiting_acknowledgement: number;
        quarantined: number;
    };
};
export type MailboxConnections = Record<MailboxProvider, MailboxConnection>;
export const MAILBOX_LABELS: Record<MailboxProvider, string> = {
    microsoft: 'Microsoft 365',
    google: 'Google Workspace',
};

export function validMailboxConnection(
    value: unknown,
): value is MailboxConnection {
    if (!value || typeof value !== 'object') return false;
    const candidate = value as Record<string, unknown>;
    if (
        [
            'access_token',
            'refresh_token',
            'poll_claim_token',
            'inbox_scan_cursor',
            'remote_message_id',
        ].some((key) => key in candidate)
    )
        return false;
    const positive = (number: unknown) =>
        Number.isSafeInteger(number) && (number as number) > 0;
    if (
        !(candidate.id === null && candidate.version === null) &&
        !(positive(candidate.id) && positive(candidate.version))
    )
        return false;
    if (
        ![null, 'connected', 'disconnected', 'error'].includes(
            candidate.status as MailboxConnection['status'],
        )
    )
        return false;
    if (
        ![
            'configured',
            'operation_active',
            'can_poll',
            'authorization_required',
            'scan_pending',
        ].every((key) => typeof candidate[key] === 'boolean')
    )
        return false;
    if (
        ![
            'account_email',
            'account_name',
            'mailbox_email',
            'effective_mailbox',
            'last_polled_at',
            'last_poll_attempt_at',
            'next_poll_at',
            'last_error',
        ].every(
            (key) =>
                candidate[key] === null || typeof candidate[key] === 'string',
        )
    )
        return false;
    const counts = candidate.counts as Record<string, unknown> | null;
    return (
        !!counts &&
        [
            'awaiting_processing',
            'awaiting_acknowledgement',
            'quarantined',
        ].every(
            (key) =>
                Number.isSafeInteger(counts[key]) &&
                (counts[key] as number) >= 0,
        )
    );
}

export function validMailboxConnections(
    value: unknown,
): value is MailboxConnections {
    if (!value || typeof value !== 'object') return false;
    const connections = value as Record<string, unknown>;
    return (
        validMailboxConnection(connections.microsoft) &&
        validMailboxConnection(connections.google)
    );
}
