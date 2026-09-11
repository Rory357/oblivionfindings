import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import type { MailboxConnection } from '../it-mailbox-contract';
import { MailboxQuarantinePanel } from '../it-mailbox-quarantine';
import {
    validQuarantinePage,
    type QuarantineRecord,
} from '../it-mailbox-quarantine-contract';

const http = vi.hoisted(() => ({ request: vi.fn() }));
vi.mock('axios', () => ({
    default: {
        ...http,
        isAxiosError: (error: unknown) =>
            !!error && typeof error === 'object' && 'response' in error,
    },
}));
const connection: MailboxConnection = {
    id: 4,
    version: 1,
    configured: true,
    status: 'connected',
    account_email: 'synthetic@example.test',
    account_name: 'Synthetic',
    mailbox_email: null,
    effective_mailbox: 'synthetic@example.test',
    last_polled_at: null,
    last_poll_attempt_at: null,
    next_poll_at: null,
    operation_active: false,
    can_poll: true,
    authorization_required: false,
    last_error: null,
    scan_pending: false,
    counts: {
        awaiting_processing: 0,
        awaiting_acknowledgement: 0,
        quarantined: 1,
    },
};
const record: QuarantineRecord = {
    id: 9,
    version: 0,
    status: 'quarantined',
    reason: 'Sender is not active or approved',
    received_at: '2026-09-11T00:00:00Z',
    retry_requested_at: null,
    can_retry: true,
    guidance: 'Correct approved access before retrying.',
};
const page = (records = [record]) => ({
    connection_id: 4,
    connection_version: 1,
    records,
    next_before_id: null,
});
function panel() {
    const accessLost = vi.fn();
    const dirty = vi.fn();
    render(
        <MailboxQuarantinePanel
            provider="microsoft"
            connection={connection}
            onAccessLost={accessLost}
            onDirtyChange={dirty}
        />,
    );
    return { accessLost, dirty };
}
async function load() {
    http.request.mockResolvedValueOnce({ data: page() });
    const callbacks = panel();
    fireEvent.click(
        screen.getByRole('button', { name: 'Load quarantine records' }),
    );
    await screen.findByText('Inbound record 9');
    return callbacks;
}
function confirm() {
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Request retry for inbound record 9',
        }),
    );
    fireEvent.click(
        within(screen.getByRole('alertdialog')).getByRole('button', {
            name: 'Request retry',
        }),
    );
}
beforeEach(() => vi.clearAllMocks());
afterEach(cleanup);

it('cancels the approved confirmation without mutation and only acknowledges a persisted retry request', async () => {
    await load();
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Request retry for inbound record 9',
        }),
    );
    expect(screen.getByRole('alertdialog').textContent).toContain(
        'It may remain quarantined',
    );
    fireEvent.click(
        within(screen.getByRole('alertdialog')).getByRole('button', {
            name: 'Cancel',
        }),
    );
    expect(http.request).toHaveBeenCalledTimes(1);
    await waitFor(() =>
        expect(document.activeElement).toBe(
            screen.getByRole('button', {
                name: 'Request retry for inbound record 9',
            }),
        ),
    );
    http.request.mockResolvedValueOnce({
        data: {
            status: 'requested',
            record: {
                ...record,
                version: 1,
                status: 'pending',
                can_retry: false,
                reason: null,
                retry_requested_at: '2026-09-11T01:00:00Z',
            },
        },
    });
    confirm();
    await screen.findByText(/No ticket creation is confirmed yet/);
    expect(
        screen.queryByRole('button', {
            name: 'Request retry for inbound record 9',
        }),
    ).toBeNull();
    expect(http.request).toHaveBeenLastCalledWith(
        expect.objectContaining({
            method: 'post',
            url: '/settings/it-mailbox/quarantine/microsoft/9/retry',
            data: {
                connection_id: 4,
                expected_version: 1,
                expected_review_version: 0,
            },
        }),
    );
});

it('requires a fresh read after a conflict and sends the refreshed record version', async () => {
    await load();
    http.request.mockRejectedValueOnce({ response: { status: 409 } });
    confirm();
    await screen.findByText(/The mailbox or record changed/);
    expect(
        screen
            .getByRole('button', { name: 'Request retry for inbound record 9' })
            .hasAttribute('disabled'),
    ).toBe(true);
    http.request.mockResolvedValueOnce({
        data: page([{ ...record, version: 2 }]),
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Reload latest records' }),
    );
    await waitFor(() =>
        expect(
            screen
                .getByRole('button', {
                    name: 'Request retry for inbound record 9',
                })
                .hasAttribute('disabled'),
        ).toBe(false),
    );
    http.request.mockResolvedValueOnce({
        data: {
            status: 'requested',
            record: {
                ...record,
                version: 3,
                status: 'pending',
                can_retry: false,
                retry_requested_at: '2026-09-11T01:00:00Z',
            },
        },
    });
    confirm();
    await screen.findByText(/No ticket creation is confirmed yet/);
    expect(http.request).toHaveBeenLastCalledWith(
        expect.objectContaining({
            data: expect.objectContaining({ expected_review_version: 2 }),
        }),
    );
});

it('treats an invalid acknowledgement as unknown instead of claiming success', async () => {
    await load();
    http.request.mockResolvedValueOnce({
        data: { status: 'requested', record: { ...record, id: 10 } },
    });
    confirm();
    await screen.findByText(/The retry outcome is unknown/);
    expect(
        screen.queryByText(/No ticket creation is confirmed yet/),
    ).toBeNull();
});

it('stopping a sent request retains an unknown outcome until a read confirms it', async () => {
    const callbacks = await load();
    http.request.mockImplementationOnce(
        ({ signal }: { signal: AbortSignal }) =>
            new Promise((_, reject) =>
                signal.addEventListener('abort', () =>
                    reject(new Error('stopped')),
                ),
            ),
    );
    confirm();
    fireEvent.click(screen.getByRole('button', { name: 'Stop waiting' }));
    await screen.findByText(/The retry outcome is unknown/);
    expect(callbacks.dirty).toHaveBeenLastCalledWith(true);
    http.request.mockResolvedValueOnce({
        data: page([
            {
                ...record,
                version: 1,
                can_retry: false,
                status: 'pending',
                retry_requested_at: '2026-09-11T01:00:00Z',
            },
        ]),
    });
    fireEvent.click(
        screen.getByRole('button', { name: 'Reload latest records' }),
    );
    await screen.findByText('Retry requested');
    await waitFor(() =>
        expect(callbacks.dirty).toHaveBeenLastCalledWith(false),
    );
});

it('conceals receipt metadata on lost access and delegates session recovery', async () => {
    const callbacks = await load();
    http.request.mockRejectedValueOnce({ response: { status: 403 } });
    confirm();
    await waitFor(() => expect(callbacks.accessLost).toHaveBeenCalledOnce());
    expect(screen.queryByText('Inbound record 9')).toBeNull();
});

it('rejects oversized duplicate and invalid cursor contracts', () => {
    expect(validQuarantinePage(page())).toBe(true);
    expect(validQuarantinePage(page([record, record]))).toBe(false);
    expect(
        validQuarantinePage(
            page(
                Array.from({ length: 26 }, (_, index) => ({
                    ...record,
                    id: index + 1,
                })),
            ),
        ),
    ).toBe(false);
    expect(validQuarantinePage({ ...page(), next_before_id: 9 })).toBe(false);
    expect(
        validQuarantinePage(
            page([{ ...record, status: 'processed', can_retry: true }]),
        ),
    ).toBe(false);
});
