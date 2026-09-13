/* eslint-disable no-restricted-syntax -- Test doubles use raw buttons as simple controls. */
import type {
    EmailSettingsState,
    EmailTest,
} from '@/components/settings/email-configuration-contract';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import axios from 'axios';
import type { PropsWithChildren, ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import EmailSettings from './email-settings';

const page = vi.hoisted(() => ({ props: {} as EmailSettingsState }));
vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        post: vi.fn(),
        isAxiosError: (value: unknown) =>
            !!value && typeof value === 'object' && 'response' in value,
    },
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => page,
    router: { visit: vi.fn() },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({
    PageHeader: ({
        title,
        actions,
        meters,
        rail,
    }: {
        title: string;
        actions: ReactNode;
        meters: ReactNode;
        rail: ReactNode;
    }) => (
        <header>
            <h1>{title}</h1>
            {actions}
            {meters}
            {rail}
        </header>
    ),
    PageHeaderGlassButton: ({
        children,
        onClick,
        disabled,
    }: PropsWithChildren<{ onClick: () => void; disabled?: boolean }>) => (
        <button disabled={disabled} onClick={onClick}>
            {children}
        </button>
    ),
    PageHeaderMeterBlock: ({
        children,
        onClick,
        label,
    }: PropsWithChildren<{ onClick: () => void; label: string }>) => (
        <button onClick={onClick}>
            {label}: {children}
        </button>
    ),
    PageHeaderMeterBig: ({ children }: PropsWithChildren) => <>{children}</>,
    PageHeaderMeterCaption: ({ children }: PropsWithChildren) => (
        <>{children}</>
    ),
    PageHeaderStatusChip: ({ children }: PropsWithChildren) => <>{children}</>,
    PageHeaderRail: ({
        items,
        onSelect,
    }: {
        items: { key: 'delivery' | 'testing'; label: string }[];
        onSelect: (key: 'delivery' | 'testing') => void;
    }) => (
        <nav>
            {items.map((item) => (
                <button key={item.key} onClick={() => onSelect(item.key)}>
                    {item.label}
                </button>
            ))}
        </nav>
    ),
}));
vi.mock('@/components/wizard/shell', () => ({
    ReviewRow: ({ label, value }: { label: string; value: ReactNode }) => (
        <p>
            {label}: {value}
        </p>
    ),
}));
vi.mock('@/components/settings/email-configuration-dialog', () => ({
    EmailConfigurationDialog: () => null,
}));

const uuid = '8159d0c3-f1b9-45ed-9290-acd9b3d4f9d0';
const test = (overrides: Partial<EmailTest> = {}): EmailTest => ({
    request_uuid: uuid,
    configuration_version: 2,
    capture_mode: null,
    status: 'queued',
    recipient_email: 'owner@example.test',
    attempt_count: 1,
    created_at: '2026-09-11T09:00:00Z',
    accepted_at: null,
    delivered_at: null,
    failed_at: null,
    ...overrides,
});
const settings = (
    overrides: Partial<EmailSettingsState> = {},
): EmailSettingsState => ({
    actor_id: 17,
    can_manage: true,
    smtp_password_saved: false,
    capture_mode: null,
    delivery_issue: null,
    last_test: null,
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
    ...overrides,
});
const error = (status: number) => ({ response: { status } });

function openTesting() {
    fireEvent.click(screen.getByRole('button', { name: 'Test and recovery' }));
}

describe('email settings delivery recovery', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        sessionStorage.clear();
        page.props = settings();
    });
    afterEach(() => vi.unstubAllGlobals());

    it('recovers the strict UUID saved for this actor instead of creating a second test request', async () => {
        sessionStorage.setItem(
            'it.email.test.17',
            JSON.stringify({ uuid, version: 2 }),
        );
        vi.mocked(axios.post).mockResolvedValue({
            data: { data: test() },
        });
        render(<EmailSettings />);
        openTesting();
        const recover = await screen.findByRole('button', {
            name: 'Recover same test request',
        });
        fireEvent.click(recover);
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
        expect(axios.post).toHaveBeenCalledWith(
            '/settings/email/test',
            {
                request_uuid: uuid,
                expected_version: 2,
                expected_actor_id: 17,
            },
            expect.anything(),
        );
    });

    it('clears a known-unsubmitted pointer and blocks another send until a fresh read', async () => {
        sessionStorage.setItem(
            'it.email.test.17',
            JSON.stringify({ uuid, version: 2 }),
        );
        vi.mocked(axios.post).mockRejectedValue(error(409));
        render(<EmailSettings />);
        openTesting();
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Recover same test request',
            }),
        );
        await screen.findByText(/configuration changed or an earlier test/);
        expect(sessionStorage.getItem('it.email.test.17')).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Send test to me' }),
        ).toBeDisabled();
    });

    it('blocks delivery tests after a failed saved-settings read', async () => {
        vi.mocked(axios.get).mockRejectedValue(error(500));
        render(<EmailSettings />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Read saved settings' }),
        );
        await screen.findByText(/Saved settings could not be loaded/);
        openTesting();
        expect(
            screen.getByRole('button', { name: 'Send test to me' }),
        ).toBeDisabled();
        expect(axios.post).not.toHaveBeenCalled();
    });

    it('conceals settings and request state when a refresh loses permission', async () => {
        sessionStorage.setItem(
            'it.email.test.17',
            JSON.stringify({ uuid, version: 2 }),
        );
        vi.mocked(axios.get).mockRejectedValue(error(403));
        render(<EmailSettings />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Read saved settings' }),
        );
        await screen.findByText(/sign-in or permission changed/);
        expect(
            screen.queryByText('Delivery and sender'),
        ).not.toBeInTheDocument();
        expect(sessionStorage.getItem('it.email.test.17')).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'Edit email settings' }),
        ).not.toBeInTheDocument();
    });

    it('does not expose a restricted viewer’s delivery-test history', () => {
        page.props = settings({ can_manage: false, last_test: test() });
        render(<EmailSettings />);
        openTesting();
        expect(
            screen.getByText('Test access is restricted'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('owner@example.test'),
        ).not.toBeInTheDocument();
        expect(screen.queryByText('Queued')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Send test to me' }),
        ).not.toBeInTheDocument();
    });
});
