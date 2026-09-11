import ItMailboxSettings from '@/pages/settings/it-mailbox';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type {
    MailboxConnection,
    MailboxConnections,
} from '../it-mailbox-contract';
import { MailboxProviderPanel } from '../it-mailbox-provider';

const http = vi.hoisted(() => ({ request: vi.fn(), get: vi.fn() }));
const page = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
vi.mock('axios', () => ({
    default: {
        ...http,
        isAxiosError: (value: unknown) =>
            !!value && typeof value === 'object' && 'response' in value,
    },
}));
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => page,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { on: () => () => undefined, visit: vi.fn() },
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

function connection(
    overrides: Partial<MailboxConnection> = {},
): MailboxConnection {
    return {
        id: 4,
        version: 1,
        configured: true,
        status: 'connected',
        account_email: 'synthetic-admin@example.test',
        account_name: 'Synthetic administrator',
        mailbox_email: 'support@example.test',
        effective_mailbox: 'support@example.test',
        last_polled_at: null,
        last_poll_attempt_at: null,
        next_poll_at: null,
        operation_active: false,
        can_poll: true,
        authorization_required: false,
        last_error: null,
        scan_pending: false,
        counts: {
            awaiting_processing: 2,
            awaiting_acknowledgement: 1,
            quarantined: 0,
        },
        ...overrides,
    };
}
function connections(microsoft = connection()): MailboxConnections {
    return {
        microsoft,
        google: connection({
            id: 5,
            mailbox_email: null,
            effective_mailbox: 'gmail@example.test',
            account_email: 'gmail@example.test',
        }),
    };
}
function panel(initial = connection()) {
    const saved = vi.fn();
    const accessLost = vi.fn();
    const dirty = vi.fn();
    render(
        <MailboxProviderPanel
            provider="microsoft"
            initial={initial}
            onSaved={saved}
            onAccessLost={accessLost}
            onDirtyChange={dirty}
        />,
    );
    return { saved, accessLost, dirty };
}
beforeEach(() => {
    vi.clearAllMocks();
    page.props = { connections: connections() };
});
afterEach(cleanup);

describe('mailbox configuration recovery', () => {
    it('saves only after a valid committed connection version is acknowledged', async () => {
        const next = connection({
            version: 2,
            mailbox_email: 'verified@example.test',
            effective_mailbox: 'verified@example.test',
        });
        http.request.mockResolvedValueOnce({
            data: { status: 'saved', connection: next },
        });
        const callbacks = panel();
        expect(screen.getByText('Authorization stored')).toBeTruthy();
        fireEvent.change(screen.getByLabelText('Support mailbox'), {
            target: { value: 'verified@example.test' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Verify and save mailbox' }),
        );
        await screen.findByText(
            'Mailbox saved after a provider read-access check. A completed poll remains unverified.',
        );
        expect(http.request).toHaveBeenCalledWith(
            expect.objectContaining({
                method: 'put',
                data: {
                    connection_id: 4,
                    expected_version: 1,
                    mailbox_email: 'verified@example.test',
                },
            }),
        );
        expect(callbacks.saved).toHaveBeenCalledWith(next);
    });

    it('retains a rejected mailbox entry and does not show a success acknowledgement', async () => {
        http.request.mockRejectedValueOnce({
            response: {
                status: 422,
                data: {
                    errors: {
                        mailbox_email: [
                            'Provider denied mailbox access. The saved mailbox has not changed.',
                        ],
                    },
                },
            },
        });
        const callbacks = panel();
        fireEvent.change(screen.getByLabelText('Support mailbox'), {
            target: { value: 'denied@example.test' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Verify and save mailbox' }),
        );
        await screen.findByText(
            'Provider denied mailbox access. The saved mailbox has not changed.',
        );
        expect(
            (screen.getByLabelText('Support mailbox') as HTMLInputElement)
                .value,
        ).toBe('denied@example.test');
        expect(callbacks.saved).not.toHaveBeenCalled();
        expect(
            (
                screen.getByRole('button', {
                    name: 'Verify and save mailbox',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(false);
    });

    it('requires an explicit review after conflict and uses the recovered version for the next save', async () => {
        http.request.mockRejectedValueOnce({ response: { status: 409 } });
        const recovered = connection({
            version: 3,
            mailbox_email: 'saved@example.test',
            effective_mailbox: 'saved@example.test',
        });
        http.get.mockResolvedValueOnce({
            data: { connections: connections(recovered) },
        });
        panel();
        fireEvent.change(screen.getByLabelText('Support mailbox'), {
            target: { value: 'draft@example.test' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Verify and save mailbox' }),
        );
        await screen.findByText(
            'The connection changed or is busy. Review its current saved state before trying again.',
        );
        expect(
            (screen.getByLabelText('Support mailbox') as HTMLInputElement)
                .disabled,
        ).toBe(true);
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload current state' }),
        );
        await screen.findByRole('button', { name: 'Use saved mailbox' });
        expect(
            (screen.getByLabelText('Support mailbox') as HTMLInputElement)
                .value,
        ).toBe('draft@example.test');
        fireEvent.click(
            screen.getByRole('button', { name: 'Use saved mailbox' }),
        );
        expect(
            (screen.getByLabelText('Support mailbox') as HTMLInputElement)
                .value,
        ).toBe('saved@example.test');
        http.request.mockResolvedValueOnce({
            data: {
                status: 'saved',
                connection: connection({
                    version: 4,
                    mailbox_email: 'reviewed@example.test',
                    effective_mailbox: 'reviewed@example.test',
                }),
            },
        });
        fireEvent.change(screen.getByLabelText('Support mailbox'), {
            target: { value: 'reviewed@example.test' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Verify and save mailbox' }),
        );
        await screen.findByText(
            'Mailbox saved after a provider read-access check. A completed poll remains unverified.',
        );
        expect(http.request).toHaveBeenLastCalledWith(
            expect.objectContaining({
                data: expect.objectContaining({ expected_version: 3 }),
            }),
        );
    });

    it('treats malformed success as unknown and blocks unsafe retries', async () => {
        http.request.mockResolvedValueOnce({
            data: { status: 'saved', connection: connection({ version: 1 }) },
        });
        const callbacks = panel();
        fireEvent.change(screen.getByLabelText('Support mailbox'), {
            target: { value: 'draft@example.test' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Verify and save mailbox' }),
        );
        await screen.findByText(/The operation outcome is unknown/);
        expect(callbacks.saved).not.toHaveBeenCalled();
        expect(
            (
                screen.getByRole('button', {
                    name: 'Verify and save mailbox',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
    });

    it('stops waiting without claiming that a pending operation was undone', async () => {
        http.request.mockImplementationOnce(
            ({ signal }: { signal: AbortSignal }) =>
                new Promise((_resolve, reject) =>
                    signal.addEventListener('abort', () =>
                        reject(new Error('Cancelled wait')),
                    ),
                ),
        );
        const callbacks = panel();
        fireEvent.change(screen.getByLabelText('Support mailbox'), {
            target: { value: 'pending@example.test' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Verify and save mailbox' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Verify and save mailbox' }),
        );
        expect(http.request).toHaveBeenCalledTimes(1);
        fireEvent.click(screen.getByRole('button', { name: 'Stop waiting' }));
        await screen.findByText(/The operation outcome is unknown/);
        expect(callbacks.saved).not.toHaveBeenCalled();
        expect(callbacks.dirty).toHaveBeenLastCalledWith(true);
    });

    it('confirms disconnect and preserves the current connection when the user cancels', async () => {
        panel();
        fireEvent.click(screen.getByRole('button', { name: 'Disconnect' }));
        const confirmation = screen.getByRole('alertdialog');
        fireEvent.click(
            within(confirmation).getByRole('button', { name: 'Cancel' }),
        );
        expect(http.request).not.toHaveBeenCalled();
        http.request.mockResolvedValueOnce({
            data: {
                status: 'disconnected',
                connection: connection({
                    id: null,
                    version: null,
                    status: null,
                    account_email: null,
                    mailbox_email: null,
                    effective_mailbox: null,
                    can_poll: false,
                }),
            },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Disconnect' }));
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Disconnect mailbox',
            }),
        );
        await screen.findByText(
            'Mailbox disconnected. Existing tickets and inbound records are retained.',
        );
        expect(http.request).toHaveBeenCalledWith(
            expect.objectContaining({
                method: 'delete',
                data: { connection_id: 4, expected_version: 1 },
            }),
        );
        expect(screen.queryByRole('button', { name: 'Disconnect' })).toBeNull();
    });

    it('conceals both providers after access loss and restores only an authorized reload', async () => {
        http.request.mockRejectedValueOnce({ response: { status: 403 } });
        render(<ItMailboxSettings />);
        fireEvent.change(screen.getByLabelText('Support mailbox'), {
            target: { value: 'private-draft@example.test' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Verify and save mailbox' }),
        );
        await screen.findByText('Current access required');
        expect(screen.queryByLabelText('Support mailbox')).toBeNull();
        expect(screen.queryByText('synthetic-admin@example.test')).toBeNull();
        expect(
            screen
                .getByRole('link', { name: 'Sign in again' })
                .getAttribute('target'),
        ).toBe('_blank');
        http.get.mockResolvedValueOnce({
            data: { connections: connections() },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Reload with current access' }),
        );
        await screen.findByLabelText('Support mailbox');
        expect(
            (screen.getByLabelText('Support mailbox') as HTMLInputElement)
                .value,
        ).toBe('support@example.test');
    });

    it('discards a draft when a header action returns to the same provider', async () => {
        render(<ItMailboxSettings />);
        fireEvent.change(screen.getByLabelText('Support mailbox'), {
            target: { value: 'discard-me@example.test' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'View authorizations' }),
        );
        fireEvent.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Discard and continue',
            }),
        );
        await waitFor(() =>
            expect(
                (screen.getByLabelText('Support mailbox') as HTMLInputElement)
                    .value,
            ).toBe('support@example.test'),
        );
        expect(http.request).not.toHaveBeenCalled();
        expect(
            screen
                .getByRole('button', { name: 'Verify and save mailbox' })
                .hasAttribute('disabled'),
        ).toBe(true);
    });

    it('uses the approved discard confirmation when changing provider with a draft', async () => {
        render(<ItMailboxSettings />);
        fireEvent.change(screen.getByLabelText('Support mailbox'), {
            target: { value: 'keep-draft@example.test' },
        });
        fireEvent.click(screen.getByRole('tab', { name: 'Google Workspace' }));
        const dialog = await screen.findByRole('alertdialog');
        expect(
            within(dialog).getByText('Discard unsaved mailbox entries?'),
        ).toBeTruthy();
        fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        expect(
            (screen.getByLabelText('Support mailbox') as HTMLInputElement)
                .value,
        ).toBe('keep-draft@example.test');
        fireEvent.click(screen.getByRole('tab', { name: 'Google Workspace' }));
        await act(async () =>
            fireEvent.click(
                within(screen.getByRole('alertdialog')).getByRole('button', {
                    name: 'Discard and continue',
                }),
            ),
        );
        expect(screen.queryByLabelText('Support mailbox')).toBeNull();
        expect(
            screen.getByText(
                'Gmail reads the connected support account’s own inbox.',
            ),
        ).toBeTruthy();
    });
});
