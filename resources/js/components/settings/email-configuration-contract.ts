export type EmailProvider = 'smtp' | 'microsoft' | 'google';
export type PublicReplyMode = 'link_only' | 'full_reply';
export type EmailConfiguration = {
    configuration_version: number;
    provider: EmailProvider;
    smtp_host: string;
    smtp_port: number;
    smtp_encryption: 'tls' | 'ssl' | 'none';
    smtp_username: string;
    from_address: string;
    from_name: string;
    support_enabled: boolean;
    support_connection_id: number | null;
    support_connection_version: number | null;
    public_reply_mode: PublicReplyMode;
};
export type EmailConnection = {
    id: number;
    provider: 'microsoft' | 'google';
    configuration_version: number;
    account_email: string | null;
    mailbox_email: string | null;
    connected: boolean;
    sending_issue: string | null;
};
export type EmailTest = {
    request_uuid: string;
    configuration_version: number;
    capture_mode: 'array' | 'log' | null;
    status:
        | 'queued'
        | 'sending'
        | 'accepted'
        | 'delivered'
        | 'failed'
        | 'bounced';
    recipient_email: string;
    attempt_count: number;
    created_at: string | null;
    accepted_at: string | null;
    delivered_at: string | null;
    failed_at: string | null;
};
export type EmailSettingsState = {
    actor_id: number;
    settings: EmailConfiguration;
    can_manage: boolean;
    connections: EmailConnection[];
    smtp_password_saved: boolean;
    capture_mode: 'array' | 'log' | null;
    delivery_issue: string | null;
    last_test: EmailTest | null;
};
export const EMAIL_PROVIDERS: Record<EmailProvider, string> = {
    smtp: 'SMTP',
    microsoft: 'Microsoft 365',
    google: 'Google Workspace',
};
export const REPLY_MODES: Record<PublicReplyMode, string> = {
    link_only: 'Link only',
    full_reply: 'Full public reply',
};
const object = (value: unknown): value is Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value);
const integer = (value: unknown, min = 0) =>
    Number.isSafeInteger(value) && (value as number) >= min;
const nullableText = (value: unknown) =>
    value === null || typeof value === 'string';
const capture = (value: unknown) =>
    value === null || value === 'array' || value === 'log';
const secretKeys = [
    'smtp_password',
    'access_token',
    'refresh_token',
    'support_connection_scope_hash',
];

export function validEmailTest(value: unknown): value is EmailTest {
    return (
        object(value) &&
        typeof value.request_uuid === 'string' &&
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
            value.request_uuid,
        ) &&
        integer(value.configuration_version, 1) &&
        capture(value.capture_mode) &&
        [
            'queued',
            'sending',
            'accepted',
            'delivered',
            'failed',
            'bounced',
        ].includes(value.status as string) &&
        typeof value.recipient_email === 'string' &&
        integer(value.attempt_count) &&
        ['created_at', 'accepted_at', 'delivered_at', 'failed_at'].every(
            (key) => nullableText(value[key]),
        )
    );
}

export function validEmailSettings(
    value: unknown,
): value is EmailSettingsState {
    if (!object(value) || !object(value.settings)) return false;
    const settings = value.settings;
    return (
        integer(value.actor_id, 1) &&
        !secretKeys.some((key) => key in settings || key in value) &&
        integer(settings.configuration_version) &&
        ['smtp', 'microsoft', 'google'].includes(settings.provider as string) &&
        ['smtp_host', 'smtp_username', 'from_address', 'from_name'].every(
            (key) => typeof settings[key] === 'string',
        ) &&
        integer(settings.smtp_port, 1) &&
        (settings.smtp_port as number) <= 65535 &&
        ['tls', 'ssl', 'none'].includes(settings.smtp_encryption as string) &&
        typeof settings.support_enabled === 'boolean' &&
        (settings.support_connection_id === null ||
            integer(settings.support_connection_id, 1)) &&
        (settings.support_connection_version === null ||
            integer(settings.support_connection_version, 1)) &&
        ['link_only', 'full_reply'].includes(
            settings.public_reply_mode as string,
        ) &&
        typeof value.can_manage === 'boolean' &&
        typeof value.smtp_password_saved === 'boolean' &&
        capture(value.capture_mode) &&
        nullableText(value.delivery_issue) &&
        (value.last_test === null || validEmailTest(value.last_test)) &&
        Array.isArray(value.connections) &&
        value.connections.every(
            (connection) =>
                object(connection) &&
                !secretKeys.some((key) => key in connection) &&
                integer(connection.id, 1) &&
                integer(connection.configuration_version, 1) &&
                ['microsoft', 'google'].includes(
                    connection.provider as string,
                ) &&
                nullableText(connection.account_email) &&
                nullableText(connection.mailbox_email) &&
                typeof connection.connected === 'boolean' &&
                nullableText(connection.sending_issue),
        )
    );
}

export function emailTestLabel(test: EmailTest | null): string {
    if (!test) return 'Not tested';
    if (test.status === 'accepted' && test.capture_mode !== null)
        return 'Captured locally';
    return (
        {
            queued: 'Queued',
            sending: 'Outcome uncertain',
            accepted: 'Accepted by provider',
            delivered: 'Delivered',
            failed: 'Failed',
            bounced: 'Bounced',
        } as const
    )[test.status];
}
