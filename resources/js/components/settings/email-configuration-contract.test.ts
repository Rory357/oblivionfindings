import { describe, expect, it } from 'vitest';
import {
    emailTestLabel,
    validEmailSettings,
    validEmailTest,
} from './email-configuration-contract';

const test = {
    request_uuid: '8159d0c3-f1b9-45ed-9290-acd9b3d4f9d0',
    configuration_version: 2,
    capture_mode: null,
    status: 'accepted',
    recipient_email: 'owner@example.test',
    attempt_count: 1,
    created_at: '2026-09-11T09:00:00Z',
    accepted_at: '2026-09-11T09:00:01Z',
    delivered_at: null,
    failed_at: null,
} as const;

const settings = {
    actor_id: 17,
    can_manage: true,
    smtp_password_saved: false,
    capture_mode: null,
    delivery_issue: null,
    last_test: test,
    connections: [],
    settings: {
        configuration_version: 2,
        provider: 'smtp',
        smtp_host: 'smtp.example.test',
        smtp_port: 587,
        smtp_encryption: 'tls',
        smtp_username: 'mailer',
        from_address: 'support@example.test',
        from_name: 'Support',
        support_enabled: true,
        support_connection_id: null,
        support_connection_version: null,
        public_reply_mode: 'link_only',
    },
} as const;

describe('email configuration response contract', () => {
    it('accepts only a complete, non-secret settings state', () => {
        expect(validEmailSettings(settings)).toBe(true);
        expect(
            validEmailSettings({
                ...settings,
                smtp_password: 'must-not-be-returned',
            }),
        ).toBe(false);
        expect(
            validEmailSettings({
                ...settings,
                settings: { ...settings.settings, refresh_token: 'secret' },
            }),
        ).toBe(false);
    });

    it('requires a UUID test identity and labels captured acceptance accurately', () => {
        expect(validEmailTest(test)).toBe(true);
        expect(validEmailTest({ ...test, request_uuid: 'not-a-uuid' })).toBe(
            false,
        );
        expect(emailTestLabel({ ...test, capture_mode: 'array' })).toBe(
            'Captured locally',
        );
        expect(emailTestLabel({ ...test, status: 'sending' })).toBe(
            'Outcome uncertain',
        );
    });
});
