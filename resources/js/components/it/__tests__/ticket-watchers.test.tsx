import { ticketWatcherActivity } from '@/components/it/ticket-watcher-activity';
import { TicketWatchers } from '@/components/it/ticket-watchers';
import {
    purgeTicketWatcherCommandsForActor,
    readTicketWatcherAcknowledgement,
    useTicketWatcherCommand,
} from '@/hooks/use-ticket-watcher-command';
import {
    act,
    cleanup,
    fireEvent,
    render,
    renderHook,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const patch = vi.spyOn(axios, 'patch');
const get = vi.spyOn(axios, 'get');
const committed = vi.fn();
const actor = 700;
const other = 701;
const ticket = 42;
const initial = {
    actorId: actor,
    ticketId: ticket,
    version: 3,
    canManage: true,
    watchers: [{ id: other, name: 'Former worker', receives_updates: false }],
    options: [{ id: actor, name: 'Current worker' }],
    onCommitted: committed,
};
const intent = {
    actorId: actor,
    ticketId: ticket,
    version: 3,
    userId: actor,
    watching: true,
};
const ack = (changes: Record<string, unknown> = {}) => ({
    status: 200,
    data: {
        status: 'committed',
        data: {
            id: ticket,
            viewer_user_id: actor,
            watcher_user_id: actor,
            watching: true,
            changed: true,
            lock_version: 4,
            ...changes,
        },
    },
});
const proof = (version = 5, watching = false) => ({
    status: 200,
    data: {
        viewer_user_id: actor,
        ticket: {
            id: ticket,
            lock_version: version,
            watchers: watching
                ? [
                      {
                          id: actor,
                          name: 'Current worker',
                          receives_updates: true,
                      },
                  ]
                : [],
        },
        can: { manageWatchers: true },
        watcherOptions: initial.options,
    },
});
const failure = (status?: number) => ({
    isAxiosError: true,
    response: status ? { status } : undefined,
});
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((yes) => {
        resolve = yes;
    });
    return { promise, resolve };
}
beforeEach(() => {
    patch.mockReset();
    get.mockReset();
    committed.mockReset();
});
afterEach(() => {
    cleanup();
    purgeTicketWatcherCommandsForActor(actor);
    purgeTicketWatcherCommandsForActor(other);
});

describe('watcher command acknowledgement', () => {
    it('binds actor, ticket, target, desired membership and incremented version', () => {
        expect(
            readTicketWatcherAcknowledgement(ack().data, intent)?.changed,
        ).toBe(true);
        for (const mismatch of [
            { id: 90 },
            { viewer_user_id: other },
            { watcher_user_id: other },
            { watching: false },
            { lock_version: 3 },
            { lock_version: 5 },
            { changed: 'true' },
        ])
            expect(
                readTicketWatcherAcknowledgement(ack(mismatch).data, intent),
            ).toBeNull();
        expect(
            readTicketWatcherAcknowledgement(
                { status: 'success', data: ack().data.data },
                intent,
            ),
        ).toBeNull();
    });
    it('accepts a no-op only at the original or later current version', () => {
        expect(
            readTicketWatcherAcknowledgement(
                ack({ changed: false, lock_version: 8 }).data,
                intent,
            )?.changed,
        ).toBe(false);
        expect(
            readTicketWatcherAcknowledgement(
                ack({ changed: false, lock_version: 2 }).data,
                intent,
            ),
        ).toBeNull();
    });
});

describe('watcher command recovery', () => {
    it('replaces an incomplete no-op projection with the refreshed equal-version register', async () => {
        patch.mockResolvedValue(ack({ changed: false, lock_version: 5 }));
        const hook = renderHook((props) => useTicketWatcherCommand(props), {
            initialProps: initial,
        });
        act(() => hook.result.current.begin(actor, true));
        await act(async () => {
            await hook.result.current.send();
        });
        hook.rerender({
            ...initial,
            version: 5,
            watchers: [
                { id: actor, name: 'Current worker', receives_updates: true },
                { id: 702, name: 'Concurrent watcher', receives_updates: true },
            ],
        });
        expect(hook.result.current.watchers.map((row) => row.name)).toEqual([
            'Current worker',
            'Concurrent watcher',
        ]);
    });
    it('keeps an unknown original through same-version opposite-state adoption and close', async () => {
        patch.mockRejectedValue(failure());
        get.mockResolvedValue(proof(3));
        const first = renderHook(() => useTicketWatcherCommand(initial));
        act(() => first.result.current.begin(actor, true));
        await act(async () => {
            await first.result.current.send();
            await first.result.current.review();
        });
        act(() => first.result.current.adopt());
        expect(first.result.current.stage).toBe('unknown');
        act(() => first.result.current.close());
        first.unmount();
        const second = renderHook(() => useTicketWatcherCommand(initial));
        expect(second.result.current.stage).toBe('unknown');
        expect(second.result.current.intent).toEqual(intent);
    });
    it('sends the exact actor/version/desired state and acknowledges only once', async () => {
        patch.mockResolvedValue(ack());
        const { result } = renderHook(() => useTicketWatcherCommand(initial));
        act(() => result.current.begin(actor, true));
        await act(async () => {
            await result.current.send();
        });
        expect(patch).toHaveBeenCalledWith(
            `/it/tickets/${ticket}/watchers/${actor}`,
            { actor_user_id: actor, expected_version: 3, watching: true },
            expect.objectContaining({ timeout: 20000 }),
        );
        expect(result.current.stage).toBe('done');
        expect(committed).toHaveBeenCalledExactlyOnceWith(4);
        expect(
            result.current.watchers.some((person) => person.id === actor),
        ).toBe(true);
    });
    it('retains a cancelled wait and ignores its late valid acknowledgement', async () => {
        const delayed = deferred<ReturnType<typeof ack>>();
        patch
            .mockReturnValueOnce(delayed.promise)
            .mockResolvedValueOnce(ack({ changed: false, lock_version: 4 }));
        const { result } = renderHook(() => useTicketWatcherCommand(initial));
        act(() => result.current.begin(actor, true));
        let sending!: Promise<void>;
        act(() => {
            sending = result.current.send();
        });
        act(() => result.current.cancelWait());
        await act(async () => {
            delayed.resolve(ack());
            await sending;
        });
        expect(result.current.stage).toBe('unknown');
        expect(committed).not.toHaveBeenCalled();
        await act(async () => {
            await result.current.send();
        });
        expect(patch.mock.calls[1][1]).toEqual(patch.mock.calls[0][1]);
        expect(result.current.acknowledgement?.changed).toBe(false);
    });
    it('keeps malformed successful responses unknown without a success callback', async () => {
        patch.mockResolvedValue(ack({ watcher_user_id: other }));
        const { result } = renderHook(() => useTicketWatcherCommand(initial));
        act(() => result.current.begin(actor, true));
        await act(async () => {
            await result.current.send();
        });
        expect(result.current.stage).toBe('unknown');
        expect(committed).not.toHaveBeenCalled();
    });
    it('preserves a stale proposal and separates read, version adoption and apply', async () => {
        patch
            .mockRejectedValueOnce(failure(409))
            .mockResolvedValueOnce(ack({ lock_version: 6 }));
        get.mockResolvedValue(proof());
        const { result } = renderHook(() => useTicketWatcherCommand(initial));
        act(() => result.current.begin(actor, true));
        await act(async () => {
            await result.current.send();
        });
        expect(result.current.stage).toBe('conflict');
        expect(committed).not.toHaveBeenCalled();
        await act(async () => {
            await result.current.review();
        });
        expect(result.current.intent?.version).toBe(3);
        act(() => result.current.adopt());
        expect(result.current.intent?.version).toBe(5);
        expect(patch).toHaveBeenCalledTimes(1);
        await act(async () => {
            await result.current.send();
        });
        expect(patch.mock.calls[1][1]).toEqual({
            actor_user_id: actor,
            expected_version: 5,
            watching: true,
        });
        expect(result.current.stage).toBe('done');
    });
    it('allows a newly ineligible existing watcher to be removed, not added', async () => {
        patch.mockResolvedValue(
            ack({ watcher_user_id: other, watching: false }),
        );
        const { result } = renderHook(() => useTicketWatcherCommand(initial));
        act(() => result.current.begin(other, true));
        expect(result.current.intent).toBeNull();
        act(() => result.current.begin(other, false));
        await act(async () => {
            await result.current.send();
        });
        expect(result.current.stage).toBe('done');
        expect(result.current.watchers).toEqual([]);
    });
    it('does not allow an ineligible add after fresh review', async () => {
        patch.mockRejectedValue(failure(422));
        get.mockResolvedValue({
            ...proof(3),
            data: { ...proof(3).data, watcherOptions: [] },
        });
        const { result } = renderHook(() => useTicketWatcherCommand(initial));
        act(() => result.current.begin(actor, true));
        await act(async () => {
            await result.current.send();
            await result.current.review();
        });
        act(() => result.current.adopt());
        expect(result.current.stage).toBe('reviewed');
        act(() => result.current.finishReview());
        expect(result.current.open).toBe(false);
        expect(committed).not.toHaveBeenCalled();
    });
    it('conceals session-expired details and requires a fresh matching actor review', async () => {
        patch.mockRejectedValue(failure(419));
        get.mockResolvedValue(proof());
        const { result } = renderHook(() => useTicketWatcherCommand(initial));
        act(() => result.current.begin(actor, true));
        await act(async () => {
            await result.current.send();
        });
        expect(result.current.concealed).toBe(true);
        expect(result.current.watchers).toEqual([]);
        expect(result.current.intent).toBeNull();
        await act(async () => {
            await result.current.review();
        });
        expect(result.current.concealed).toBe(false);
        expect(result.current.intent?.version).toBe(3);
        expect(patch).toHaveBeenCalledTimes(1);
    });
    it('purges and conceals after a review account mismatch', async () => {
        get.mockResolvedValue({
            ...proof(),
            data: { ...proof().data, viewer_user_id: other },
        });
        const { result } = renderHook(() => useTicketWatcherCommand(initial));
        act(() => result.current.begin(actor, true));
        await act(async () => {
            await result.current.review();
        });
        expect(result.current.stage).toBe('access');
        expect(result.current.intent).toBeNull();
        expect(result.current.watchers).toEqual([]);
    });
    it('ignores cancelled review results and never adopts their version', async () => {
        const delayed = deferred<ReturnType<typeof proof>>();
        get.mockReturnValue(delayed.promise);
        const { result } = renderHook(() => useTicketWatcherCommand(initial));
        act(() => result.current.begin(actor, true));
        let reading!: Promise<void>;
        act(() => {
            reading = result.current.review();
        });
        act(() => result.current.cancelWait());
        await act(async () => {
            delayed.resolve(proof());
            await reading;
        });
        expect(result.current.reviewed).toBeNull();
        expect(result.current.intent?.version).toBe(3);
    });
    it('retains exact unknown membership across unmount without browser storage', async () => {
        const setStorage = vi.spyOn(Storage.prototype, 'setItem');
        patch.mockRejectedValue(failure());
        const first = renderHook(() => useTicketWatcherCommand(initial));
        act(() => first.result.current.begin(actor, true));
        await act(async () => {
            await first.result.current.send();
        });
        first.unmount();
        const unload = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(unload);
        expect(unload.defaultPrevented).toBe(true);
        const second = renderHook(() => useTicketWatcherCommand(initial));
        expect(second.result.current.stage).toBe('unknown');
        expect(second.result.current.intent).toEqual(intent);
        expect(setStorage).not.toHaveBeenCalled();
        setStorage.mockRestore();
    });
    it('keeps an unknown command while the drawer is still authorizing its data', async () => {
        patch.mockRejectedValue(failure());
        const first = renderHook(() => useTicketWatcherCommand(initial));
        act(() => first.result.current.begin(actor, true));
        await act(async () => {
            await first.result.current.send();
        });
        first.unmount();
        const next = renderHook((props) => useTicketWatcherCommand(props), {
            initialProps: {
                ...initial,
                canManage: false,
                authorizationReady: false,
            },
        });
        expect(next.result.current.stage).toBe('unknown');
        next.rerender({
            ...initial,
            canManage: true,
            authorizationReady: true,
        });
        expect(next.result.current.intent).toEqual(intent);
    });
    it('does not erase an unresolved command when an old version still shows the opposite state', async () => {
        patch.mockRejectedValue(failure());
        get.mockResolvedValue(proof(3));
        const { result } = renderHook(() => useTicketWatcherCommand(initial));
        act(() => result.current.begin(actor, true));
        await act(async () => {
            await result.current.send();
            await result.current.review();
        });
        act(() => result.current.finishReview());
        expect(result.current.stage).toBe('unknown');
        expect(result.current.pending).toBe(true);
    });
    it('keeps confirmed success when only the refresh callback fails', async () => {
        patch.mockResolvedValue(ack());
        const { result } = renderHook(() =>
            useTicketWatcherCommand({
                ...initial,
                onCommitted: () => {
                    throw new Error('refresh');
                },
            }),
        );
        act(() => result.current.begin(actor, true));
        await act(async () => {
            await result.current.send();
        });
        expect(result.current.stage).toBe('done');
        expect(result.current.message).toMatch(/change is confirmed/);
    });
});

function Panel({ canManage = true }: { canManage?: boolean }) {
    const command = useTicketWatcherCommand({ ...initial, canManage });
    return <TicketWatchers command={command} actorId={actor} />;
}
describe('watcher management controls', () => {
    it('shows paused subscriptions, confirms removal and labels the exact person', async () => {
        patch.mockResolvedValue(
            ack({ watcher_user_id: other, watching: false }),
        );
        render(<Panel />);
        expect(
            screen.getByText('Updates paused: access changed'),
        ).toBeVisible();
        const remove = screen.getByRole('button', {
            name: 'Remove Former worker as watcher',
        });
        remove.focus();
        expect(remove).toHaveFocus();
        fireEvent.click(remove);
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByText(/Remove Former worker/)).toBeVisible();
        expect(patch).not.toHaveBeenCalled();
        fireEvent.click(
            within(dialog).getByRole('button', { name: 'Remove watcher' }),
        );
        await waitFor(() =>
            expect(within(dialog).getByRole('status')).toHaveTextContent(
                'The watcher was removed.',
            ),
        );
    });
    it('offers no add/remove/self-watch action to a reader without the canonical capability', () => {
        render(<Panel canManage={false} />);
        expect(screen.getByText('Former worker')).toBeVisible();
        expect(screen.queryByRole('button')).toBeNull();
        expect(screen.queryByRole('combobox')).toBeNull();
    });
    it('keeps a rejected add visible with focus and no success message', async () => {
        patch.mockRejectedValue(failure(422));
        render(<Panel />);
        fireEvent.click(screen.getByRole('button', { name: 'Watch ticket' }));
        fireEvent.click(screen.getByRole('button', { name: 'Add watcher' }));
        await waitFor(() => expect(screen.getByRole('alert')).toHaveFocus());
        expect(screen.getByRole('alert')).toHaveTextContent(
            'This attempt was rejected',
        );
        expect(screen.queryByText('The watcher was added.')).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Review current watchers' }),
        ).toBeVisible();
    });
    it('uses accurate self, delegated and historical activity descriptions', () => {
        expect(
            ticketWatcherActivity('watcher_added', {
                self: true,
                watcher_name: 'A',
            }),
        ).toBe('started watching');
        expect(ticketWatcherActivity('watcher_removed', null)).toBe(
            'stopped watching',
        );
        expect(
            ticketWatcherActivity('watcher_added', {
                self: false,
                watcher_name: 'A',
            }),
        ).toBe('added A as a watcher');
        expect(
            ticketWatcherActivity('watcher_removed', {
                self: false,
                watcher_name: 'A',
            }),
        ).toBe('removed A as a watcher');
    });
});
