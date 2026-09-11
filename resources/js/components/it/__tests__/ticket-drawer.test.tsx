import { Button } from '@/components/ui/button';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { useEffect, useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TicketDrawer } from '../ticket-drawer';

const visit = vi.hoisted(() => vi.fn());
const before = vi.hoisted(() => ({
    listener: undefined as
        | undefined
        | ((event: {
              detail: { visit: { method: string; url: URL } };
              preventDefault: () => void;
          }) => void),
}));
vi.mock('@inertiajs/react', () => ({
    router: {
        visit,
        on: (_name: string, listener: typeof before.listener) => {
            before.listener = listener;
            return () => {
                before.listener = undefined;
            };
        },
    },
    usePage: () => ({ props: { auth: { user: { id: 10 } } } }),
}));
vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        patch: vi.fn(),
        isAxiosError: (error: unknown) =>
            !!error && typeof error === 'object' && 'response' in error,
    },
}));
vi.mock('@/components/it/ticket-thread', () => ({
    TicketThread: ({
        onDraftStateChange,
        onPosted,
        accessState,
    }: {
        onDraftStateChange?: (state: { dirty: boolean; busy: boolean }) => void;
        onPosted?: () => void;
        accessState?: 'session' | 'access' | 'actor' | null;
    }) => {
        const [body, setBody] = useState('');
        const [busy, setBusy] = useState(false);
        useEffect(
            () => onDraftStateChange?.({ dirty: body.length > 0, busy }),
            [body, busy, onDraftStateChange],
        );
        useEffect(() => {
            if (accessState === 'access' || accessState === 'actor') {
                setBody('');
                setBusy(false);
            }
        }, [accessState]);
        if (accessState) return null;
        return (
            <>
                <textarea
                    aria-label="Reply"
                    value={body}
                    onChange={(event) => setBody(event.target.value)}
                />
                <Button onClick={onPosted}>Refresh conversation</Button>
                <Button onClick={() => setBusy(true)}>
                    Start pending reply
                </Button>
            </>
        );
    },
}));

const get = vi.mocked(axios.get);
const payload = {
    viewer_user_id: 10,
    ticket: {
        id: 1,
        reference: 'IT-000001',
        title: 'Synthetic ticket',
        status: 'open',
        priority: 'normal',
        requester: { name: 'Synthetic requester', role: null },
        watchers: [],
        attachments: [],
        assignee: null,
    },
    comments: [],
    events: [],
    can: { internal: true, comment: true },
};
beforeEach(() => {
    before.listener = undefined;
    get.mockReset();
    visit.mockReset();
    get.mockResolvedValue({ data: payload });
});

describe('ticket drawer lifecycle', () => {
    it.each([false, true])(
        'keeps exit, Close and GET guards for session-concealed retained work (pending %s)',
        async (busy) => {
            get.mockResolvedValueOnce({ data: payload }).mockRejectedValueOnce({
                response: { status: 419 },
            });
            const close = vi.fn();
            render(<TicketDrawer ticketId={1} onClose={close} />);
            fireEvent.change(await screen.findByLabelText('Reply'), {
                target: { value: 'Session private unfinished draft' },
            });
            if (busy)
                fireEvent.click(
                    screen.getByRole('button', { name: 'Start pending reply' }),
                );
            fireEvent.click(
                screen.getByRole('button', { name: 'Refresh conversation' }),
            );
            await screen.findByRole('link', { name: 'Sign in' });
            expect(screen.queryByLabelText('Reply')).not.toBeInTheDocument();
            const exit = new Event('beforeunload', { cancelable: true });
            window.dispatchEvent(exit);
            expect(exit.defaultPrevented).toBe(true);
            fireEvent.click(screen.getByRole('button', { name: 'Close' }));
            const confirm = await screen.findByRole('alertdialog');
            expect(confirm).toBeVisible();
            expect(confirm).not.toHaveTextContent(
                'Session private unfinished draft',
            );
            expect(close).not.toHaveBeenCalled();
            fireEvent.click(
                within(confirm).getByRole('button', { name: 'Cancel' }),
            );
            const preventDefault = vi.fn();
            act(() =>
                before.listener?.({
                    detail: {
                        visit: {
                            method: 'get',
                            url: new URL('http://localhost/it'),
                        },
                    },
                    preventDefault,
                }),
            );
            expect(preventDefault).toHaveBeenCalledOnce();
            expect(visit).not.toHaveBeenCalled();
            expect(await screen.findByRole('alertdialog')).toBeVisible();
            fireEvent.click(
                within(screen.getByRole('alertdialog')).getByRole('button', {
                    name: 'Cancel',
                }),
            );
            fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
            expect(await screen.findByLabelText('Reply')).toHaveValue(
                'Session private unfinished draft',
            );
        },
    );

    it('drops the departed work guard after confirmed access denial and purge', async () => {
        get.mockResolvedValueOnce({ data: payload }).mockRejectedValueOnce({
            response: { status: 403 },
        });
        const close = vi.fn();
        render(<TicketDrawer ticketId={1} onClose={close} />);
        fireEvent.change(await screen.findByLabelText('Reply'), {
            target: { value: 'Revoked private draft' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh conversation' }),
        );
        await screen.findByText('Ticket access unavailable');
        const exit = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(exit);
        expect(exit.defaultPrevented).toBe(false);
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        expect(close).toHaveBeenCalledOnce();
        expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    });
    it('retains the same concealed composer across session expiry and requires an authorized retry', async () => {
        get.mockResolvedValueOnce({ data: payload })
            .mockRejectedValueOnce({ response: { status: 419 } })
            .mockRejectedValueOnce(new Error('Network'))
            .mockResolvedValueOnce({ data: payload });
        render(<TicketDrawer ticketId={1} onClose={() => undefined} />);
        fireEvent.change(await screen.findByLabelText('Reply'), {
            target: { value: 'Keep this original session draft' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh conversation' }),
        );
        await waitFor(() =>
            expect(screen.queryByLabelText('Reply')).not.toBeInTheDocument(),
        );
        expect(screen.queryByText('Synthetic ticket')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Try again' }),
            ).toBeEnabled(),
        );
        expect(screen.queryByLabelText('Reply')).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(await screen.findByLabelText('Reply')).toHaveValue(
            'Keep this original session draft',
        );
    });
    it('manages watchers from People and feedback through the actor-bound command', async () => {
        const current = {
            ...payload,
            ticket: { ...payload.ticket, lock_version: 2 },
            can: { ...payload.can, manageWatchers: true },
            watcherOptions: [{ id: 10, name: 'Current worker' }],
        };
        get.mockResolvedValueOnce({ data: current }).mockResolvedValue({
            data: {
                ...current,
                ticket: {
                    ...current.ticket,
                    lock_version: 3,
                    watchers: [
                        {
                            id: 10,
                            name: 'Current worker',
                            receives_updates: true,
                        },
                    ],
                },
            },
        });
        vi.mocked(axios.patch).mockResolvedValue({
            status: 200,
            data: {
                status: 'committed',
                data: {
                    id: 1,
                    viewer_user_id: 10,
                    watcher_user_id: 10,
                    watching: true,
                    changed: true,
                    lock_version: 3,
                },
            },
        });
        render(<TicketDrawer ticketId={1} onClose={vi.fn()} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'Watch ticket' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Add watcher' }));
        await screen.findByText('The watcher was added.');
        expect(axios.patch).toHaveBeenCalledWith(
            '/it/tickets/1/watchers/10',
            { actor_user_id: 10, expected_version: 2, watching: true },
            expect.anything(),
        );
    });
    it('purges entered agent-side work when current ticket capabilities narrow', async () => {
        get.mockResolvedValueOnce({ data: payload }).mockResolvedValueOnce({
            data: { ...payload, can: { internal: false, comment: true } },
        });
        render(<TicketDrawer ticketId={1} onClose={() => undefined} />);
        fireEvent.change(await screen.findByLabelText('Reply'), {
            target: { value: 'Private agent draft' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh conversation' }),
        );
        await waitFor(() =>
            expect(screen.getByLabelText('Reply')).toHaveValue(''),
        );
    });
    it('protects browser exit and generic GET navigation while allowing comment POST', async () => {
        render(<TicketDrawer ticketId={1} onClose={() => undefined} />);
        fireEvent.change(await screen.findByLabelText('Reply'), {
            target: { value: 'Unsent draft' },
        });
        const exit = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(exit);
        expect(exit.defaultPrevented).toBe(true);
        const preventPost = vi.fn();
        act(() =>
            before.listener?.({
                detail: {
                    visit: {
                        method: 'post',
                        url: new URL('http://localhost/it/tickets/1/comments'),
                    },
                },
                preventDefault: preventPost,
            }),
        );
        expect(preventPost).not.toHaveBeenCalled();
        const preventGet = vi.fn();
        const url = new URL('http://localhost/it?tab=tickets');
        act(() =>
            before.listener?.({
                detail: { visit: { method: 'get', url } },
                preventDefault: preventGet,
            }),
        );
        expect(preventGet).toHaveBeenCalledOnce();
        expect(visit).not.toHaveBeenCalled();
        fireEvent.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Discard and continue',
            }),
        );
        expect(visit).toHaveBeenCalledWith(url, { method: 'get', url });
    });

    it('conceals a changed-account response and offers a normal page reload', async () => {
        get.mockResolvedValueOnce({ data: { ...payload, viewer_user_id: 11 } });
        render(<TicketDrawer ticketId={1} onClose={() => undefined} />);
        const alert = await screen.findByRole('alert');
        expect(alert).toHaveFocus();
        expect(screen.queryByLabelText('Reply')).not.toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Reload page' }),
        ).toHaveAttribute('href', '/it/tickets/1');
        expect(
            screen.queryByRole('button', { name: 'Try again' }),
        ).not.toBeInTheDocument();
    });
    it('keeps entered work through an unrelated parent render with a fresh close callback', async () => {
        const { rerender } = render(
            <TicketDrawer ticketId={1} onClose={() => undefined} />,
        );
        fireEvent.change(await screen.findByLabelText('Reply'), {
            target: { value: 'Retain this draft' },
        });
        rerender(<TicketDrawer ticketId={1} onClose={() => undefined} />);
        expect(get).toHaveBeenCalledTimes(1);
        expect(screen.getByLabelText('Reply')).toHaveValue('Retain this draft');
    });

    it('requires an explicit decision before Escape discards work', async () => {
        const close = vi.fn();
        render(<TicketDrawer ticketId={1} onClose={close} />);
        fireEvent.change(await screen.findByLabelText('Reply'), {
            target: { value: 'Unsent reply' },
        });
        fireEvent.keyDown(document, { key: 'Escape' });
        const confirmation = await screen.findByRole('alertdialog');
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(
            within(confirmation).getByRole('button', { name: 'Cancel' }),
        );
        expect(screen.getByLabelText('Reply')).toHaveValue('Unsent reply');
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        fireEvent.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Discard and close',
            }),
        );
        expect(close).toHaveBeenCalledTimes(1);
    });

    it('does not lose work during full-page handoff without an explicit discard', async () => {
        render(<TicketDrawer ticketId={1} onClose={() => undefined} />);
        fireEvent.change(await screen.findByLabelText('Reply'), {
            target: { value: 'Drawer draft' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Open full page' }));
        expect(visit).not.toHaveBeenCalled();
        fireEvent.click(
            within(await screen.findByRole('alertdialog')).getByRole('button', {
                name: 'Discard and open full page',
            }),
        );
        expect(visit).toHaveBeenCalledWith('/it/tickets/1');
    });

    it('keeps the current composer mounted when refreshing or retrying a failed refresh', async () => {
        get.mockResolvedValueOnce({ data: payload })
            .mockRejectedValueOnce(new Error('Network'))
            .mockResolvedValueOnce({ data: payload });
        render(<TicketDrawer ticketId={1} onClose={() => undefined} />);
        fireEvent.change(await screen.findByLabelText('Reply'), {
            target: { value: 'Still composing' },
        });
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh conversation' }),
        );
        await screen.findByRole('alert');
        expect(screen.getByLabelText('Reply')).toHaveValue('Still composing');
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        await waitFor(() =>
            expect(screen.queryByRole('alert')).not.toBeInTheDocument(),
        );
        expect(screen.getByLabelText('Reply')).toHaveValue('Still composing');
    });

    it('shows session recovery inline and never treats a settled error as loading', async () => {
        get.mockRejectedValueOnce({ response: { status: 401 } });
        const close = vi.fn();
        render(<TicketDrawer ticketId={1} onClose={close} />);
        await screen.findByRole('alert');
        expect(screen.getByRole('link', { name: 'Sign in' })).toHaveAttribute(
            'href',
            '/login',
        );
        expect(screen.queryByText('Loading ticket…')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Reply')).not.toBeInTheDocument();
        expect(close).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
        await screen.findByLabelText('Reply');
    });

    it('returns keyboard focus to the opening control after an ordinary close', async () => {
        function Harness() {
            const [id, setId] = useState<number | null>(null);
            return (
                <>
                    <Button onClick={() => setId(1)}>Peek ticket</Button>
                    <TicketDrawer ticketId={id} onClose={() => setId(null)} />
                </>
            );
        }
        render(<Harness />);
        const trigger = screen.getByRole('button', { name: 'Peek ticket' });
        trigger.focus();
        fireEvent.click(trigger);
        await screen.findByLabelText('Reply');
        fireEvent.click(screen.getByRole('button', { name: 'Close' }));
        await waitFor(() => expect(trigger).toHaveFocus());
    });
});
