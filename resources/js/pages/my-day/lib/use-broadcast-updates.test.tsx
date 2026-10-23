import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useBroadcastUpdates } from './use-broadcast-updates';

type Response = { url: string; props: Record<string, unknown> };
type Request = { only: string[]; onStart: () => void;
    onBeforeUpdate: (page: Response) => void; onSuccess: (page: Response) => void; onFinish: () => void };
const mocks = vi.hoisted(() => ({
    before: new Set<() => void>(), requests: [] as Request[],
    beforeUpdate: new Set<(event: { detail: { page: Response } }) => void>(),
    reload: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    router: {
        on: (name: string, callback: never) => {
            const listeners = name === 'beforeUpdate' ? mocks.beforeUpdate : mocks.before;
            listeners.add(callback);
            return () => listeners.delete(callback);
        },
        reload: (request: Request) => mocks.reload(request),
    },
}));
const notificationId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
function snapshot(readId: string, viewerId = 7) {
    return {
        viewerId, url: '/my-day',
        reader: { schema_version: 1, viewer_id: viewerId, read_id: readId, checked_at: '2026-10-21T01:00:00Z',
            capabilities: { mark_read: false, acknowledge: false, bulk_mark_read: false } },
        notifications: [{ id: notificationId, title: 'Private staff message', at: 'Today',
            broadcast: { schema_version: 1, notification_id: notificationId, recipient_viewer_id: viewerId,
                reader_read_id: readId, href: `/notifications?notification=${notificationId}` } }],
    };
}
const initial = () => snapshot('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
const next = () => snapshot('cccccccc-cccc-4ccc-8ccc-cccccccccccc');
function response(input: ReturnType<typeof snapshot>): Response {
    return { url: input.url, props: { auth: { user: { id: input.viewerId } },
        notifications: input.notifications, broadcast_updates_reader: input.reader } };
}
function deliver(request: Request, page: Response) {
    request.onBeforeUpdate(page);
    mocks.beforeUpdate.forEach((callback) => callback({ detail: { page } }));
    request.onSuccess(page);
}

beforeEach(() => {
    window.history.replaceState({}, '', '/my-day');
    mocks.before.clear();
    mocks.beforeUpdate.clear();
    mocks.requests.length = 0;
    mocks.reload.mockReset().mockImplementation((request: Request) => {
        mocks.before.forEach((callback) => callback());
        mocks.requests.push(request);
        request.onStart();
    });
});

describe('My Day current broadcast read boundary', () => {
    it('withholds initial cached props, then admits only a completed paired response and matching snapshot', () => {
        const { result, rerender } = renderHook(useBroadcastUpdates, { initialProps: initial() });
        expect(result.current.items).toBeNull();
        expect(mocks.reload).not.toHaveBeenCalled();
        act(() => result.current.refresh());
        expect(mocks.requests[0].only).toEqual(['notifications', 'broadcast_updates_reader']);
        // router.reload itself preserves state and scroll in the installed version.
        expect(mocks.requests[0]).not.toHaveProperty('preserveState');
        const fresh = next();
        act(() => deliver(mocks.requests[0], response(fresh)));
        expect(result.current.items).toBeNull();
        rerender(fresh);
        expect(result.current.items).toEqual(fresh.notifications);
        act(() => mocks.requests[0].onFinish());
        expect(result.current.loading).toBe(false);
    });
    it('deduplicates pending reads and refuses a successful partial response reusing the prior envelope', () => {
        const seed = initial();
        const { result } = renderHook(useBroadcastUpdates, { initialProps: seed });
        act(() => { result.current.refresh(); result.current.refresh(); });
        expect(mocks.requests).toHaveLength(1);
        act(() => { deliver(mocks.requests[0], response(seed)); mocks.requests[0].onFinish(); });
        expect(result.current.items).toBeNull();
    });
    it('accepts a fresh paired read on the same fragment route', () => {
        window.history.replaceState({}, '', '/my-day#updates');
        const { result, rerender } = renderHook(useBroadcastUpdates, {
            initialProps: { ...initial(), url: '/my-day#updates' },
        });
        act(() => result.current.refresh());
        const fresh = { ...next(), url: '/my-day#updates' };
        act(() => deliver(mocks.requests[0], response(fresh)));
        rerender(fresh);
        expect(result.current.items).toEqual(fresh.notifications);
    });
    it('rejects a response superseded by another page visit', () => {
        const { result, rerender } = renderHook(useBroadcastUpdates, { initialProps: initial() });
        act(() => result.current.refresh());
        act(() => mocks.before.forEach((callback) => callback()));
        const fresh = next();
        act(() => deliver(mocks.requests[0], response(fresh)));
        rerender(fresh);
        expect(result.current.items).toBeNull();
    });
    it('rejects global onSuccess when another response replaced this request during the page swap', () => {
        const { result, rerender } = renderHook(useBroadcastUpdates, { initialProps: initial() });
        act(() => result.current.refresh());
        const fresh = next();
        act(() => {
            mocks.requests[0].onBeforeUpdate(response(fresh));
            mocks.requests[0].onSuccess(response(fresh));
        });
        rerender(fresh);
        // Same values/nonce are insufficient: this is a different response object.
        expect(result.current.items).toBeNull();
    });
    it('rejects an omitted list inherited from an intervening response before React has rendered it', () => {
        const { result } = renderHook(useBroadcastUpdates, { initialProps: initial() });
        act(() => result.current.refresh());
        const earlier = response(next());
        earlier.props.notifications = [];
        act(() => mocks.beforeUpdate.forEach((callback) => callback({ detail: { page: earlier } })));
        const later = response(snapshot('dddddddd-dddd-4ddd-8ddd-dddddddddddd'));
        later.props.notifications = earlier.props.notifications;
        act(() => deliver(mocks.requests[0], later));
        expect(result.current.items).toBeNull();
    });
    it('does not revive previews after an account changes away and back', () => {
        const { result, rerender } = renderHook(useBroadcastUpdates, { initialProps: initial() });
        act(() => result.current.refresh());
        const fresh = next();
        act(() => deliver(mocks.requests[0], response(fresh)));
        rerender(fresh);
        expect(result.current.items).not.toBeNull();
        rerender({ ...fresh, viewerId: 8 });
        expect(result.current.items).toBeNull();
        rerender(fresh);
        expect(result.current.items).toBeNull();
    });
    it.each(['popstate', 'pagehide'])('withdraws previews on %s and rejects the old response', (event) => {
        const { result, rerender } = renderHook(useBroadcastUpdates, { initialProps: initial() });
        act(() => result.current.refresh());
        const fresh = next();
        act(() => deliver(mocks.requests[0], response(fresh)));
        rerender(fresh);
        expect(result.current.items).not.toBeNull();
        act(() => window.dispatchEvent(new Event(event)));
        expect(result.current.items).toBeNull();
        act(() => deliver(mocks.requests[0], response(fresh)));
        expect(result.current.items).toBeNull();
    });
    it('does not accept a response for another actor or route', () => {
        const { result, rerender } = renderHook(useBroadcastUpdates, { initialProps: initial() });
        act(() => result.current.refresh());
        const foreign = snapshot('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 8);
        act(() => deliver(mocks.requests[0], response(foreign)));
        rerender({ ...foreign, viewerId: 7 });
        expect(result.current.items).toBeNull();
        act(() => mocks.requests[0].onSuccess({ ...response(next()), url: '/notifications' }));
        expect(result.current.items).toBeNull();
    });
    it('allows explicit recovery after failure without claiming an empty success', () => {
        const { result } = renderHook(useBroadcastUpdates, { initialProps: initial() });
        mocks.reload.mockImplementationOnce(() => { throw new Error('not sent'); });
        act(() => result.current.refresh());
        expect(result.current.items).toBeNull();
        expect(result.current.loading).toBe(false);
        act(() => result.current.refresh());
        expect(mocks.requests).toHaveLength(1);
        act(() => mocks.requests[0].onFinish());
        expect(result.current.items).toBeNull();
    });
    it('does not remain busy when an existing draft guard cancels before sending', () => {
        mocks.reload.mockImplementationOnce(() => {});
        const { result } = renderHook(useBroadcastUpdates, { initialProps: initial() });
        act(() => result.current.refresh());
        expect(result.current.loading).toBe(false);
        expect(result.current.items).toBeNull();
        act(() => result.current.refresh());
        expect(mocks.requests).toHaveLength(1);
    });
    it('withholds a bfcache-restored snapshot until a new explicit check', () => {
        const { result, rerender } = renderHook(useBroadcastUpdates, { initialProps: initial() });
        act(() => result.current.refresh());
        const fresh = next();
        act(() => deliver(mocks.requests[0], response(fresh)));
        rerender(fresh);
        act(() => window.dispatchEvent(new PageTransitionEvent('pageshow', { persisted: true })));
        expect(result.current.items).toBeNull();
    });
    it('rejects callbacks after unmount without retaining a cross-mount grant', () => {
        const hook = renderHook(useBroadcastUpdates, { initialProps: initial() });
        act(() => hook.result.current.refresh());
        hook.unmount();
        act(() => { deliver(mocks.requests[0], response(next())); mocks.requests[0].onFinish(); });
        const freshHook = renderHook(useBroadcastUpdates, { initialProps: next() });
        expect(freshHook.result.current.items).toBeNull();
    });
});
