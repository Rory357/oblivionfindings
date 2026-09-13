import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useItTicketPeek } from './use-it-ticket-peek';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        isAxiosError: (error: unknown) =>
            !!error && typeof error === 'object' && 'response' in error,
    },
}));
const get = vi.mocked(axios.get);
const payload = (id: number) => ({
    viewer_user_id: 10,
    ticket: {
        id,
        title: `Ticket ${id}`,
        status: 'open',
        priority: 'normal',
        requester: { name: 'Synthetic requester' },
        watchers: [],
        attachments: [],
    },
    comments: [],
    events: [],
    can: { internal: true, comment: true },
    replyUnavailableReason: null,
});
function pending() {
    let resolve!: (value: { data: ReturnType<typeof payload> }) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise<{ data: ReturnType<typeof payload> }>(
        (yes, no) => {
            resolve = yes;
            reject = no;
        },
    );
    return { promise, resolve, reject };
}
beforeEach(() => get.mockReset());
afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

describe('current ticket peek request', () => {
    it('bounds a hung refresh, retains the snapshot, ignores late data and retries', async () => {
        get.mockResolvedValueOnce({ data: payload(1) });
        const { result } = renderHook(() => useItTicketPeek(1, 10));
        await waitFor(() => expect(result.current.data).not.toBeNull());
        const stalled = pending();
        get.mockReturnValueOnce(stalled.promise);
        vi.useFakeTimers();
        act(() => result.current.refresh());
        const signal = get.mock.calls[1][1]?.signal;
        act(() => vi.advanceTimersByTime(20000));
        expect(signal?.aborted).toBe(true);
        expect(result.current.loading).toBe(false);
        expect(result.current.error).toBe('network');
        expect(result.current.data?.ticket.title).toBe('Ticket 1');
        await act(async () =>
            stalled.resolve({
                data: {
                    ...payload(1),
                    ticket: { ...payload(1).ticket, title: 'Late result' },
                },
            }),
        );
        expect(result.current.data?.ticket.title).toBe('Ticket 1');
        get.mockResolvedValueOnce({ data: payload(1) });
        await act(async () => result.current.refresh());
        expect(result.current.error).toBeNull();
    });
    it('retains session concealment across a failed network retry until fresh actor proof', async () => {
        get.mockResolvedValueOnce({ data: payload(1) }).mockRejectedValueOnce({
            response: { status: 419 },
        });
        const { result } = renderHook(() => useItTicketPeek(1, 10));
        await waitFor(() => expect(result.current.data).not.toBeNull());
        await act(async () => result.current.refresh());
        expect(result.current.access).toBe('session');
        get.mockRejectedValueOnce(new Error('Network'));
        await act(async () => result.current.refresh());
        expect(result.current.access).toBe('session');
        expect(result.current.data).toBeNull();
        get.mockResolvedValueOnce({ data: payload(1) });
        await act(async () => result.current.refresh());
        expect(result.current.access).toBeNull();
    });
    it('conceals a successful response for a different authenticated viewer', async () => {
        get.mockResolvedValueOnce({
            data: { ...payload(1), viewer_user_id: 11 },
        });
        const { result } = renderHook(() => useItTicketPeek(1, 10));
        await waitFor(() => expect(result.current.error).toBe('actor'));
        expect(result.current.data).toBeNull();
    });
    it('aborts a previous ticket and ignores its late successful response', async () => {
        const first = pending();
        const second = pending();
        get.mockReturnValueOnce(first.promise).mockReturnValueOnce(
            second.promise,
        );
        const { result, rerender } = renderHook(
            ({ id }) => useItTicketPeek(id, 10),
            { initialProps: { id: 1 } },
        );
        const signal = get.mock.calls[0][1]?.signal;
        rerender({ id: 2 });
        expect(signal?.aborted).toBe(true);
        expect(result.current.data).toBeNull();
        await act(async () => second.resolve({ data: payload(2) }));
        await act(async () => first.resolve({ data: payload(1) }));
        expect(result.current.data?.ticket.id).toBe(2);
    });

    it('ignores a previous actor request denial and never displays that actor snapshot', async () => {
        const first = pending();
        const second = pending();
        get.mockReturnValueOnce(first.promise).mockReturnValueOnce(
            second.promise,
        );
        const { result, rerender } = renderHook(
            ({ actor }) => useItTicketPeek(1, actor),
            { initialProps: { actor: 10 } },
        );
        rerender({ actor: 11 });
        expect(result.current.data).toBeNull();
        await act(async () =>
            second.resolve({ data: { ...payload(1), viewer_user_id: 11 } }),
        );
        await act(async () => first.reject({ response: { status: 403 } }));
        expect(result.current.error).toBeNull();
        expect(result.current.data?.ticket.id).toBe(1);
    });

    it('retains the mounted conversation during refresh and a network failure, then retries', async () => {
        const next = pending();
        get.mockResolvedValueOnce({ data: payload(1) })
            .mockReturnValueOnce(next.promise)
            .mockResolvedValueOnce({ data: payload(1) });
        const { result, rerender } = renderHook(() => useItTicketPeek(1, 10));
        await waitFor(() => expect(result.current.data?.ticket.id).toBe(1));
        rerender();
        expect(get).toHaveBeenCalledTimes(1);
        act(() => result.current.refresh());
        expect(result.current.loading).toBe(true);
        expect(result.current.data?.ticket.id).toBe(1);
        await act(async () => next.reject(new Error('Network')));
        expect(result.current.error).toBe('network');
        expect(result.current.data?.ticket.id).toBe(1);
        act(() => result.current.refresh());
        await waitFor(() => expect(result.current.error).toBeNull());
    });

    it.each([401, 419, 403, 404])(
        'conceals an already loaded conversation after current status %i',
        async (status) => {
            get.mockResolvedValueOnce({
                data: payload(1),
            }).mockRejectedValueOnce({ response: { status } });
            const { result } = renderHook(() => useItTicketPeek(1, 10));
            await waitFor(() => expect(result.current.data).not.toBeNull());
            act(() => result.current.refresh());
            await waitFor(() =>
                expect(result.current.error).toBe(
                    status === 401 || status === 419 ? 'session' : 'access',
                ),
            );
            expect(result.current.data).toBeNull();
        },
    );

    it('does not accept another ticket returned by a malformed response', async () => {
        get.mockResolvedValueOnce({ data: payload(2) });
        const { result } = renderHook(() => useItTicketPeek(1, 10));
        await waitFor(() => expect(result.current.error).toBe('network'));
        expect(result.current.data).toBeNull();
    });

    it('aborts on close/unmount and does not request without a current actor', () => {
        get.mockReturnValueOnce(pending().promise);
        const { rerender, unmount } = renderHook(
            ({ id }) => useItTicketPeek(id, 10),
            { initialProps: { id: 1 as number | null } },
        );
        const signal = get.mock.calls[0][1]?.signal;
        rerender({ id: null });
        expect(signal?.aborted).toBe(true);
        unmount();
        renderHook(() => useItTicketPeek(1, undefined));
        expect(get).toHaveBeenCalledTimes(1);
    });
});
