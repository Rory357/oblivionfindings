import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TicketCloseDialog } from '../ticket-close-dialog';

const mocks = vi.hoisted(() => ({
    actor: 11,
    close: vi.fn(),
    completed: vi.fn(),
    outcome: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
    on: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { id: mocks.actor } } } }),
    router: { reload: mocks.reload, visit: mocks.visit, on: mocks.on },
}));
const reason = 'Requester confirmed the synthetic restoration.';
const failure = (status: number, data = {}) => ({
    isAxiosError: true,
    response: { status, data },
});
const committed = (extra = {}) => ({
    status: 200,
    data: {
        status: 'committed',
        data: {
            id: 42,
            viewer_user_id: 11,
            operation: 'ticket.close',
            status: 'closed',
            lock_version: 4,
            reason,
            ...extra,
        },
    },
});
const current = (status = 'resolved', actor = 11) => ({
    ok: true,
    status: 200,
    json: async () => ({
        viewer_user_id: actor,
        can: { manage: true },
        ticket: {
            id: 42,
            reference: 'IT-000042',
            lock_version: 8,
            title: 'Current synthetic ticket',
            status,
            priority: 'normal',
            assignee: null,
        },
    }),
});
function Fixture({
    bulk = false,
    ids = [42],
    version = 3,
}: {
    bulk?: boolean;
    ids?: number[];
    version?: number;
}) {
    return (
        <TicketCloseDialog
            open
            onOpenChange={mocks.close}
            scope={bulk ? 'bulk' : 'single'}
            ticketIds={ids}
            expectedVersions={Object.fromEntries(
                ids.map((id) => [id, version]),
            )}
            ticketReference="IT-000042"
            onCompleted={mocks.completed}
            onBulkResult={mocks.outcome}
        />
    );
}
const enter = (value = reason) =>
    fireEvent.change(screen.getByLabelText('Reason for closing'), {
        target: { value },
    });
const send = (bulk = false) =>
    fireEvent.click(
        screen.getByRole('button', {
            name: bulk ? 'Close selected tickets' : 'Close ticket',
        }),
    );
async function review() {
    fireEvent.click(
        await screen.findByRole('button', { name: 'Review current ticket' }),
    );
    fireEvent.click(
        await screen.findByRole('button', {
            name: 'Use this version and keep my draft',
        }),
    );
}

beforeEach(() => {
    vi.clearAllMocks();
    mocks.actor = 11;
    mocks.on.mockReturnValue(() => {});
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(current()));
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('reasoned close recovery', () => {
    it('requires a reason and submits the original actor and version before showing exact success', async () => {
        const request = vi
            .spyOn(axios, 'request')
            .mockResolvedValue(committed());
        render(<Fixture />);
        expect(
            screen.getByRole('button', { name: 'Close ticket' }),
        ).toBeDisabled();
        enter(`  ${reason}  `);
        send();
        expect(request).toHaveBeenCalledWith(
            expect.objectContaining({
                method: 'post',
                url: '/it/tickets/42/close',
                data: { actor_user_id: 11, expected_version: 3, reason },
            }),
        );
        expect(await screen.findByRole('status')).toHaveTextContent(
            'ticket is closed',
        );
        expect(mocks.completed).toHaveBeenCalledOnce();
        expect(mocks.reload).toHaveBeenCalledOnce();
        expect(mocks.close).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Done' }));
        expect(mocks.close).toHaveBeenCalledWith(false);
    });

    it.each([
        ['operation', { operation: 'ticket.reopen' }],
        ['state', { status: 'open' }],
        ['reason', { reason: 'Different reason' }],
        ['version', { lock_version: 3 }],
        ['ticket', { id: 99 }],
    ])(
        'retains the reason when the response has the wrong %s',
        async (_name, patch) => {
            vi.spyOn(axios, 'request').mockResolvedValue(committed(patch));
            render(<Fixture />);
            enter();
            send();
            await screen.findByRole('button', {
                name: 'Review current ticket',
            });
            expect(screen.getByLabelText('Reason for closing')).toHaveValue(
                reason,
            );
            expect(
                screen.getByRole('button', { name: 'Close ticket' }),
            ).toBeDisabled();
            expect(mocks.completed).not.toHaveBeenCalled();
        },
    );

    it('rejects an unrelated 200 response without pretending closure succeeded', async () => {
        vi.spyOn(axios, 'request').mockResolvedValue({
            status: 200,
            data: { flash: { success: 'Saved' } },
        });
        render(<Fixture />);
        enter();
        send();
        await screen.findByRole('button', { name: 'Review current ticket' });
        expect(mocks.completed).not.toHaveBeenCalled();
        expect(mocks.close).not.toHaveBeenCalled();
    });

    it('keeps the reason and restores editor focus on dirty cancellation before explicit discard', async () => {
        render(<Fixture />);
        enter();
        screen.getByLabelText('Reason for closing').focus();
        fireEvent.click(screen.getByRole('button', { name: 'Keep open' }));
        expect(screen.getByRole('alertdialog')).toHaveTextContent(
            'does not undo any recorded closure',
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        await waitFor(() =>
            expect(screen.getByLabelText('Reason for closing')).toHaveFocus(),
        );
        expect(screen.getByLabelText('Reason for closing')).toHaveValue(reason);
        expect(mocks.close).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Keep open' }));
        fireEvent.click(screen.getByRole('button', { name: 'Leave form' }));
        expect(mocks.close).toHaveBeenCalledWith(false);
    });

    it('stops waiting without claiming server cancellation and ignores a late successful acknowledgement', async () => {
        let finish!: (value: ReturnType<typeof committed>) => void;
        const request = vi.spyOn(axios, 'request').mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        render(<Fixture />);
        enter();
        send();
        fireEvent.click(screen.getByRole('button', { name: 'Stop waiting' }));
        expect(request.mock.calls[0][0].signal?.aborted).toBe(true);
        expect(
            screen.getByText(/The wait stopped\. The request may still finish/),
        ).toBeVisible();
        await act(async () => finish(committed()));
        expect(mocks.completed).not.toHaveBeenCalled();
        expect(screen.getByLabelText('Reason for closing')).toHaveValue(reason);
    });

    it('conceals an expired-session reason then restores it only after original-account review', async () => {
        const request = vi
            .spyOn(axios, 'request')
            .mockRejectedValue(failure(419));
        render(<Fixture />);
        enter();
        send();
        await screen.findByRole('link', { name: 'Sign in again' });
        expect(
            screen.queryByLabelText('Reason for closing'),
        ).not.toBeInTheDocument();
        await review();
        expect(screen.getByLabelText('Reason for closing')).toHaveValue(reason);
        expect(request).toHaveBeenCalledOnce();
        expect(
            screen.getByRole('button', { name: 'Close ticket' }),
        ).toBeEnabled();
    });

    it.each([403, 404])(
        'purges details after access denial %s',
        async (status) => {
            vi.spyOn(axios, 'request').mockRejectedValue(failure(status));
            render(<Fixture />);
            enter();
            send();
            await screen.findByRole('link', { name: 'Reload current work' });
            expect(
                screen.queryByLabelText('Reason for closing'),
            ).not.toBeInTheDocument();
            expect(screen.queryByText(reason)).not.toBeInTheDocument();
            expect(mocks.completed).not.toHaveBeenCalled();
        },
    );

    it('purges a mounted previous-account reason and refuses a different-account acknowledgement', async () => {
        vi.spyOn(axios, 'request').mockResolvedValue(
            committed({ viewer_user_id: 12 }),
        );
        const view = render(<Fixture />);
        enter();
        send();
        await screen.findByRole('link', { name: 'Reload current work' });
        mocks.actor = 12;
        view.rerender(<Fixture />);
        expect(
            screen.queryByLabelText('Reason for closing'),
        ).not.toBeInTheDocument();
        expect(mocks.completed).not.toHaveBeenCalled();
    });

    it('does not retry after a current-state review shows closure already happened', async () => {
        const request = vi
            .spyOn(axios, 'request')
            .mockRejectedValue(failure(409));
        vi.mocked(fetch).mockResolvedValue(current('closed') as Response);
        render(<Fixture />);
        enter();
        send();
        await review();
        expect(
            screen.getByText(
                /This ticket is already closed\. No further close request/,
            ),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Close ticket' }),
        ).toBeDisabled();
        expect(screen.getByLabelText('Reason for closing')).toHaveValue(reason);
        expect(request).toHaveBeenCalledOnce();
    });

    it('allows field correction but requires current review for a settlement refusal', async () => {
        const request = vi
            .spyOn(axios, 'request')
            .mockRejectedValueOnce(
                failure(422, { errors: { reason: ['Review this reason.'] } }),
            )
            .mockRejectedValueOnce(
                failure(422, {
                    code: 'close_unavailable',
                    message: 'Required work is incomplete.',
                }),
            );
        render(<Fixture />);
        enter();
        send();
        await screen.findByText('Review this reason.');
        expect(
            screen.getByRole('button', { name: 'Close ticket' }),
        ).toBeEnabled();
        enter('Reviewed reason.');
        send();
        await screen.findByRole('button', { name: 'Review current ticket' });
        expect(
            screen.getByRole('button', { name: 'Close ticket' }),
        ).toBeDisabled();
        expect(request).toHaveBeenCalledTimes(2);
    });

    it('freezes a partial bulk outcome and never automatically retries the successful records', async () => {
        const result = {
            resource: 'tickets',
            action: 'close',
            selected: 2,
            updated: 1,
            unchanged: 0,
            rejected: 1,
            items: [
                { id: 42, status: 'updated', message: 'Saved' },
                { id: 43, status: 'stale', message: 'Changed elsewhere.' },
            ],
        };
        const request = vi.spyOn(axios, 'request').mockResolvedValue({
            status: 200,
            data: { status: 'completed', viewer_user_id: 11, result },
        });
        const view = render(<Fixture bulk ids={[42, 43]} />);
        enter();
        send(true);
        await screen.findByText('1 updated · 0 unchanged · 1 not applied');
        view.rerender(<Fixture bulk ids={[43]} version={9} />);
        expect(screen.getByRole('heading')).toHaveTextContent(
            'Close 2 selected tickets',
        );
        expect(screen.getByLabelText('Reason for closing')).toHaveValue(reason);
        expect(
            screen.getByRole('button', { name: 'Close selected tickets' }),
        ).toBeDisabled();
        expect(mocks.outcome).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current list' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(mocks.visit).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current list' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Leave form' }));
        expect(mocks.visit).toHaveBeenCalledWith('/it?tab=tickets');
        expect(mocks.outcome).toHaveBeenCalledWith(result);
        expect(request).toHaveBeenCalledOnce();
        expect(mocks.completed).not.toHaveBeenCalled();
    });
});
