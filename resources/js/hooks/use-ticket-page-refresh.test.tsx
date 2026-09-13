import { router } from '@inertiajs/react';
import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useTicketPageRefresh } from './use-ticket-page-refresh';

const listeners = vi.hoisted(
    () => new Set<(event: unknown) => boolean | void>(),
);
vi.mock('@inertiajs/react', () => ({
    router: {
        reload: vi.fn(),
        on: (_type: string, listener: (event: unknown) => boolean | void) => {
            listeners.add(listener);
            return () => {
                listeners.delete(listener);
            };
        },
    },
}));
const reload = vi.mocked(router.reload);
const page = {
    component: 'it/tickets/show',
    props: { auth: { user: { id: 8 } }, viewer_user_id: 8, ticket: { id: 42 } },
};
beforeEach(() => {
    reload.mockReset();
    listeners.clear();
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
});
describe('ticket page delivery refresh', () => {
    it.each([
        { component: 'it/tickets/show', ticketId: 99 },
        { component: 'another/component', ticketId: 42 },
    ])(
        'conceals a wrong record/component before application even when the scope changes: %j',
        (mismatch) => {
            const hook = renderHook((id) => useTicketPageRefresh(8, id), {
                initialProps: 42,
            });
            act(() => hook.result.current.refresh());
            act(() =>
                reload.mock.calls[0][0]?.onBeforeUpdate?.({
                    ...page,
                    component: mismatch.component,
                    props: { ...page.props, ticket: { id: mismatch.ticketId } },
                } as never),
            );
            expect(hook.result.current.access).toBe('access');
            hook.rerender(mismatch.ticketId);
            expect(hook.result.current.access).toBe('access');
        },
    );
    it('shows pending until the matching canonical page is acknowledged without remounting', () => {
        const { result } = renderHook(() => useTicketPageRefresh(8, 42));
        act(() => result.current.refresh());
        expect(result.current.pending).toBe(true);
        expect(reload.mock.calls[0][0]?.only).toBeUndefined();
        expect(reload.mock.calls[0][0]?.except).toBeUndefined();
        act(() => {
            reload.mock.calls[0][0]?.onSuccess?.(page as never);
            reload.mock.calls[0][0]?.onFinish?.({} as never);
        });
        expect(result.current.pending).toBe(false);
        expect(result.current.error).toBeNull();
    });
    it('does not claim refresh when Inertia finishes without a page acknowledgement', () => {
        const { result } = renderHook(() => useTicketPageRefresh(8, 42));
        act(() => result.current.refresh());
        act(() => reload.mock.calls[0][0]?.onFinish?.({} as never));
        expect(result.current.error).toMatch(/not confirmed/);
        expect(result.current.pending).toBe(false);
        act(() => result.current.refresh());
        expect(reload).toHaveBeenCalledTimes(2);
    });
    it('rejects a redirected or different actor page as confirmation', () => {
        const { result } = renderHook(() => useTicketPageRefresh(8, 42));
        act(() => result.current.refresh());
        act(() =>
            reload.mock.calls[0][0]?.onSuccess?.({
                ...page,
                props: { ...page.props, viewer_user_id: 9 },
            } as never),
        );
        expect(result.current.access).toBe('actor');
    });
    it('bounds a lost refresh and ignores late callbacks', () => {
        vi.useFakeTimers();
        const cancel = vi.fn();
        const { result } = renderHook(() => useTicketPageRefresh(8, 42));
        act(() => result.current.refresh());
        act(() => reload.mock.calls[0][0]?.onCancelToken?.({ cancel }));
        act(() => vi.advanceTimersByTime(20000));
        expect(cancel).toHaveBeenCalledOnce();
        expect(result.current.error).toMatch(/in time/);
        act(() => reload.mock.calls[0][0]?.onSuccess?.(page as never));
        expect(result.current.error).toMatch(/in time/);
    });
    it('cancels a departed actor scope without surfacing its late result', () => {
        const cancel = vi.fn();
        const hook = renderHook((actor) => useTicketPageRefresh(actor, 42), {
            initialProps: 8,
        });
        act(() => hook.result.current.refresh());
        act(() => reload.mock.calls[0][0]?.onCancelToken?.({ cancel }));
        hook.rerender(9);
        act(() => reload.mock.calls[0][0]?.onSuccess?.(page as never));
        expect(cancel).toHaveBeenCalledOnce();
        expect(hook.result.current.error).toBeNull();
        expect(hook.result.current.pending).toBe(false);
    });
    it('coalesces a commit during an older pending read into a later read, including same-tick calls', async () => {
        const { result } = renderHook(() => useTicketPageRefresh(8, 42));
        act(() => {
            result.current.refresh();
            result.current.refresh();
            result.current.refresh();
        });
        expect(reload).toHaveBeenCalledOnce();
        await act(async () =>
            reload.mock.calls[0][0]?.onSuccess?.(page as never),
        );
        expect(reload).toHaveBeenCalledTimes(2);
        expect(result.current.pending).toBe(true);
        act(() => reload.mock.calls[0][0]?.onFinish?.({} as never));
        expect(result.current.pending).toBe(true);
        act(() => reload.mock.calls[1][0]?.onSuccess?.(page as never));
        expect(result.current.pending).toBe(false);
        expect(result.current.error).toBeNull();
    });
    it('conceals before applying a mismatched actor and stays blocked across the new auth props', () => {
        const hook = renderHook((actor) => useTicketPageRefresh(actor, 42), {
            initialProps: 8,
        });
        act(() => hook.result.current.refresh());
        act(() =>
            reload.mock.calls[0][0]?.onBeforeUpdate?.({
                ...page,
                props: {
                    ...page.props,
                    auth: { user: { id: 9 } },
                    viewer_user_id: 9,
                },
            } as never),
        );
        expect(hook.result.current.access).toBe('actor');
        hook.rerender(9);
        expect(hook.result.current.access).toBe('actor');
    });
    it.each([401, 419, 403, 404])(
        'conceals only its own HTTP %i response and ignores unrelated visits',
        (status) => {
            const { result } = renderHook(() => useTicketPageRefresh(8, 42));
            act(() => result.current.refresh());
            const listener = [...listeners][0];
            const own = reload.mock.calls[0][0]?.headers;
            expect(
                listener({
                    detail: {
                        response: {
                            status,
                            config: {
                                headers: { 'X-IT-Ticket-Refresh': 'unrelated' },
                            },
                        },
                    },
                }),
            ).toBeUndefined();
            expect(result.current.access).toBeNull();
            let suppress: boolean | void;
            act(() => {
                suppress = listener({
                    detail: { response: { status, config: { headers: own } } },
                });
            });
            expect(suppress!).toBe(false);
            expect(result.current.access).toBe(
                status === 401 || status === 419 ? 'session' : 'access',
            );
            expect(result.current.pending).toBe(false);
            expect(listeners.size).toBe(0);
        },
    );
    it('does not reveal a concealed draft after a failed retry; requires fresh matching auth and ticket', () => {
        const { result } = renderHook(() => useTicketPageRefresh(8, 42));
        act(() => result.current.refresh());
        act(() =>
            [...listeners][0]({
                detail: {
                    response: {
                        status: 419,
                        config: { headers: reload.mock.calls[0][0]?.headers },
                    },
                },
            }),
        );
        act(() => result.current.refresh());
        act(() => reload.mock.calls[1][0]?.onFinish?.({} as never));
        expect(result.current.access).toBe('session');
        act(() => result.current.refresh());
        act(() => reload.mock.calls[2][0]?.onSuccess?.(page as never));
        expect(result.current.access).toBeNull();
    });
});
