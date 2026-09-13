import { TicketConfirmResolutionDialog } from '@/pages/it/tickets/_dialogs';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('axios', () => ({
    default: {
        post: mocks.post,
        isAxiosError: (error: unknown) =>
            !!error && typeof error === 'object' && 'response' in error,
    },
}));
const confirmed = vi.fn();
const closed = vi.fn();
const success = {
    status: 200,
    data: {
        status: 'committed',
        data: {
            id: 4,
            viewer_user_id: 3,
            lock_version: 8,
            operation: 'resolution.confirm',
            status: 'closed',
        },
    },
};
const form = () =>
    render(
        <TicketConfirmResolutionDialog
            ticketId={4}
            actorId={3}
            expectedVersion={7}
            onClose={closed}
            onConfirmed={confirmed}
        />,
    );
beforeEach(() => vi.clearAllMocks());
afterEach(() => vi.unstubAllGlobals());

it('records success only for the exact actor, ticket and confirmed operation', async () => {
    mocks.post.mockResolvedValue(success);
    form();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm and close' }));
    await screen.findByText(
        'Your confirmation is recorded and the ticket is closed.',
    );
    expect(confirmed).toHaveBeenCalledOnce();
    expect(mocks.post).toHaveBeenCalledWith(
        '/it/tickets/4/confirm-resolution',
        { actor_user_id: 3, expected_version: 7 },
        expect.any(Object),
    );
});

it('refuses an unrelated success response and requires read-only review before a separate retry', async () => {
    mocks.post.mockResolvedValue({
        ...success,
        data: {
            ...success.data,
            data: { ...success.data.data, viewer_user_id: 9 },
        },
    });
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                viewer_user_id: 3,
                can: { manage: false, confirmResolution: true },
                ticket: {
                    id: 4,
                    reference: 'IT-000004',
                    title: 'Reviewed resolution',
                    lock_version: 9,
                    status: 'resolved',
                    priority: 'normal',
                    assignee: null,
                },
            }),
        }),
    );
    form();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm and close' }));
    fireEvent.click(
        await screen.findByRole('button', { name: 'Review current ticket' }),
    );
    fireEvent.click(
        await screen.findByRole('button', {
            name: 'Use this resolution',
        }),
    );
    expect(mocks.post).toHaveBeenCalledOnce();
    expect(confirmed).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm and close' }));
    expect(mocks.post.mock.calls[1][1]).toEqual({
        actor_user_id: 3,
        expected_version: 9,
    });
    await waitFor(() =>
        expect(
            screen.getByRole('button', { name: 'Review current ticket' }),
        ).toBeInTheDocument(),
    );
});

it('keeps a stopped request uncertain and ignores its late acknowledgement', async () => {
    let finish!: (value: typeof success) => void;
    mocks.post.mockReturnValue(
        new Promise((resolve) => {
            finish = resolve;
        }),
    );
    form();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm and close' }));
    fireEvent.click(screen.getByRole('button', { name: 'Stop waiting' }));
    await act(async () => finish(success));
    expect(screen.getByText(/The wait stopped/)).toBeInTheDocument();
    expect(confirmed).not.toHaveBeenCalled();
    expect(
        screen.getByRole('button', { name: 'Confirm and close' }),
    ).toBeDisabled();
});

it('does not claim success for expired authentication or unavailable confirmation', async () => {
    mocks.post.mockRejectedValue({ response: { status: 419 } });
    form();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm and close' }));
    await screen.findByRole('link', { name: 'Sign in again' });
    expect(confirmed).not.toHaveBeenCalled();
    expect(
        screen.getByRole('button', { name: 'Confirm and close' }),
    ).toBeDisabled();
});
