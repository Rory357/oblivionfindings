import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TicketReopenDialog } from '../ticket-reopen-dialog';

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    actor: 7,
    reload: vi.fn(),
    before: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { id: mocks.actor } } } }),
    router: {
        on: (_: string, callback: unknown) => {
            mocks.before(callback);
            return () => {};
        },
        reload: mocks.reload,
        visit: mocks.visit,
    },
}));
vi.mock('axios', () => ({
    default: {
        post: mocks.post,
        isAxiosError: (value: unknown) =>
            !!value && typeof value === 'object' && 'response' in value,
    },
}));
const reason = 'The connection dropped again.';
const props = {
    open: true,
    onOpenChange: vi.fn(),
    ticketId: 42,
    expectedVersion: 4,
    ticketReference: 'IT-000042',
    audience: 'requester' as const,
    onCompleted: vi.fn(),
};
const saved = (changes = {}) => ({
    status: 200,
    data: {
        status: 'committed',
        data: {
            id: 42,
            viewer_user_id: 7,
            operation: 'ticket.reopen',
            status: 'open',
            lock_version: 5,
            comment_id: 83,
            reason,
            visibility: 'public',
            ...changes,
        },
    },
});
function enter(value = reason) {
    fireEvent.change(screen.getByRole('textbox'), { target: { value } });
}
function submit() {
    fireEvent.click(
        screen.getByRole('button', { name: 'Reopen ticket' }),
    );
}
function current(status = 'resolved', actor = 7, canReopen = true) {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                viewer_user_id: actor,
                can: { reopen: canReopen },
                ticket: {
                    id: 42,
                    reference: 'IT-000042',
                    title: 'Synthetic request',
                    status,
                    priority: 'normal',
                    assignee: null,
                    lock_version: 9,
                },
            }),
        }),
    );
}
async function review() {
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Review current ticket',
        }),
    );
    await screen.findByRole('button', {
        name: 'Continue with this ticket version',
    });
    fireEvent.click(
        screen.getByRole('button', {
            name: 'Continue with this ticket version',
        }),
    );
}

describe('TicketReopenDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.actor = 7;
        mocks.post.mockReset();
        mocks.reload.mockImplementation((options) => options?.onFinish?.());
    });
    afterEach(() => vi.unstubAllGlobals());

    it('requires a reason, binds the original actor, and confirms only exact saved public evidence', async () => {
        mocks.post.mockResolvedValue(saved());
        render(<TicketReopenDialog {...props} />);
        expect(
            screen.getByText(/explanation appears in the conversation/i),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Reopen ticket' }),
        ).toBeDisabled();
        enter(`  ${reason}  `);
        submit();
        expect(mocks.post).toHaveBeenCalledWith(
            '/it/tickets/42/reopen',
            {
                actor_user_id: 7,
                reason,
                expected_version: 4,
            },
            expect.objectContaining({
                timeout: 30000,
                signal: expect.any(AbortSignal),
            }),
        );
        await screen.findByText(
            'The ticket is reopened and your reason is recorded.',
        );
        expect(props.onCompleted).toHaveBeenCalledTimes(1);
        expect(mocks.reload).toHaveBeenCalledTimes(1);
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(props.onOpenChange).toHaveBeenCalledWith(false);
    });

    it('keeps an agent explanation internal', async () => {
        mocks.post.mockResolvedValue(saved({ visibility: 'internal' }));
        render(<TicketReopenDialog {...props} audience="agent" />);
        expect(screen.getByText(/recorded as an internal note/i)).toBeVisible();
        expect(screen.getByLabelText('Reason for reopening')).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Keep settled' }),
        ).toHaveClass('min-h-11');
        enter();
        submit();
        await screen.findByRole('button', { name: 'Done' });
    });

    it.each([
        { viewer_user_id: 8 },
        { reason: 'Different saved reason.' },
        { visibility: 'internal' },
        { lock_version: 4 },
        { comment_id: null },
        { operation: 'other' },
        { status: 'closed' },
    ])(
        'does not claim success for a mismatched acknowledgement %o',
        async (changes) => {
            mocks.post.mockResolvedValue(saved(changes));
            render(<TicketReopenDialog {...props} />);
            enter();
            submit();
            await screen.findByText(/Reopening could not be confirmed/);
            expect(props.onCompleted).not.toHaveBeenCalled();
            expect(mocks.reload).not.toHaveBeenCalled();
            expect(screen.getByRole('textbox')).toHaveValue(reason);
            expect(
                screen.getByRole('button', { name: 'Reopen ticket' }),
            ).toBeDisabled();
        },
    );

    it('asks before dirty dismissal and returns focus and the exact reason when discard is cancelled', async () => {
        render(<TicketReopenDialog {...props} />);
        enter();
        screen.getByRole('textbox').focus();
        fireEvent.keyDown(screen.getByRole('textbox'), {
            key: 'Escape',
            code: 'Escape',
        });
        const confirmation = await screen.findByRole('alertdialog');
        expect(props.onOpenChange).not.toHaveBeenCalled();
        fireEvent.click(
            within(confirmation).getByRole('button', {
                name: 'Cancel',
            }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
        expect(screen.getByRole('textbox')).toHaveValue(reason);
        expect(screen.getByRole('textbox')).toHaveFocus();
        fireEvent.click(screen.getByRole('button', { name: 'Keep settled' }));
        fireEvent.click(
            await screen.findByRole('button', { name: 'Discard reason' }),
        );
        expect(props.onOpenChange).toHaveBeenCalledWith(false);
        expect(mocks.post).not.toHaveBeenCalled();
    });

    it('reviews a conflict read-only and requires a separate retry with the reviewed version', async () => {
        mocks.post.mockRejectedValueOnce({ response: { status: 409 } });
        current();
        render(<TicketReopenDialog {...props} />);
        enter();
        submit();
        await screen.findByText(/This ticket changed after you opened it/);
        await review();
        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('textbox')).toHaveValue(reason);
        mocks.post.mockResolvedValue(saved({ lock_version: 10 }));
        submit();
        await screen.findByRole('button', { name: 'Done' });
        expect(mocks.post.mock.calls[1][1].expected_version).toBe(9);
    });

    it('conceals after session loss and restores only after the original actor reviews the ticket', async () => {
        mocks.post.mockRejectedValueOnce({ response: { status: 419 } });
        current();
        render(<TicketReopenDialog {...props} />);
        enter();
        submit();
        await screen.findByRole('link', { name: 'Sign in again' });
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(screen.queryByText(reason)).not.toBeInTheDocument();
        await review();
        expect(screen.getByRole('textbox')).toHaveValue(reason);
        expect(mocks.post).toHaveBeenCalledTimes(1);
    });

    it('purges the reason when review belongs to a different login', async () => {
        mocks.post.mockRejectedValueOnce({ response: { status: 419 } });
        current('resolved', 8);
        render(<TicketReopenDialog {...props} />);
        enter();
        submit();
        await screen.findByRole('link', { name: 'Sign in again' });
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current ticket' }),
        );
        await screen.findByText(
            /Your account or ticket access changed. The entered reason/,
        );
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
        expect(screen.queryByText(reason)).not.toBeInTheDocument();
        expect(mocks.post).toHaveBeenCalledTimes(1);
    });

    it.each([
        ['open', false],
        ['resolved', false],
    ])(
        'does not resubmit an unavailable reopen after current-state review (%s)',
        async (status, canReopen) => {
            mocks.post.mockRejectedValueOnce(new Error('lost response'));
            current(status as string, 7, canReopen as boolean);
            render(<TicketReopenDialog {...props} />);
            enter();
            submit();
            await screen.findByText(/Reopening could not be confirmed/);
            await review();
            await screen.findByText(/No further reopen request was sent/);
            expect(
                screen.getByRole('button', { name: 'Reopen ticket' }),
            ).toBeDisabled();
            expect(props.onCompleted).not.toHaveBeenCalled();
            expect(mocks.post).toHaveBeenCalledTimes(1);
        },
    );

    it('stops waiting without claiming cancellation and ignores a late acknowledgement', async () => {
        let finish!: (value: unknown) => void;
        mocks.post.mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        render(<TicketReopenDialog {...props} />);
        enter();
        submit();
        fireEvent.click(
            await screen.findByRole('button', { name: 'Stop waiting' }),
        );
        expect(mocks.post.mock.calls[0][2].signal.aborted).toBe(true);
        expect(screen.getByText(/Reopening may still finish/)).toBeVisible();
        await act(async () => finish(saved()));
        expect(props.onCompleted).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: 'Reopen ticket' }),
        ).toBeDisabled();
    });

    it('retains a rejected reason for correction and resets an old actor buffer on account change', async () => {
        mocks.post.mockRejectedValueOnce({
            response: {
                status: 422,
                data: { errors: { reason: ['Please explain what changed.'] } },
            },
        });
        const view = render(<TicketReopenDialog {...props} />);
        enter();
        submit();
        await screen.findByText('Please explain what changed.');
        expect(screen.getByRole('textbox')).toHaveValue(reason);
        expect(
            screen.getByRole('button', { name: 'Reopen ticket' }),
        ).toBeEnabled();
        mocks.actor = 8;
        view.rerender(<TicketReopenDialog {...props} />);
        expect(screen.getByRole('textbox')).toHaveValue('');
        expect(
            screen.getByRole('button', { name: 'Reopen ticket' }),
        ).toBeDisabled();
    });
});
