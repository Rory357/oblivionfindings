import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useUnifiAction, type UnifiActionPage } from './unifi-actions';
type InvalidEvent = {
    detail: {
        response: {
            status: number;
            config: {
                method: string;
                url: string;
                headers: Record<string, string>;
            };
            data: string;
        };
    };
    preventDefault: () => void;
};
const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
    listeners: new Set<(event: InvalidEvent) => void>(),
}));
vi.mock('@inertiajs/react', () => ({
    router: {
        post: mocks.post,
        put: mocks.put,
        delete: mocks.delete,
        on: (_name: string, listener: (event: InvalidEvent) => void) => {
            mocks.listeners.add(listener);
            return () => mocks.listeners.delete(listener);
        },
    },
}));
const path = '/security-devices/integrations/unifi/sync-devices';
const page = (
    changes: Partial<UnifiActionPage> = {},
    options?: { headers: Record<string, string> },
) => ({
    component: 'security-devices/integrations/unifi',
    props: {
        actor_id: 42,
        can: { manage: true },
        auth: {
            user: { id: 42 },
            can: {
                securityDevices: { viewAny: true, integrationsManage: true },
            },
        },
        ...(options
            ? {
                  actionOutcome: {
                      actor_id: 42,
                      request_id: options.headers['X-Obli-UniFi-Action'],
                      method: 'post',
                      path,
                      outcome: 'saved' as const,
                  },
              }
            : {}),
        ...changes,
    },
});
const response = (
    options: { headers: Record<string, string> },
    status: number,
    changes: Record<string, unknown> = {},
) => {
    const event = {
        detail: {
            response: {
                status,
                config: {
                    method: 'post',
                    url: path,
                    headers: options.headers,
                    ...changes,
                },
                data: 'private response body',
            },
        },
        preventDefault: vi.fn(),
    };
    act(() => mocks.listeners.forEach((listener) => listener(event)));
    return event;
};
beforeEach(() => {
    vi.clearAllMocks();
    mocks.listeners.clear();
});
describe('owned UniFi actions', () => {
    it('holds a synchronous global lock and ignores an earlier request finish while a later request is pending', () => {
        const denied = vi.fn();
        const { result } = renderHook(() => useUnifiAction(42, denied));
        const submit = (id: number) =>
            result.current.execute({
                method: 'post',
                path,
                payload: { site_config_id: id },
                label: 'Sync',
            });
        act(() => {
            submit(10);
            submit(20);
        });
        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(mocks.post.mock.calls[0][1]).toEqual({ site_config_id: 10 });
        const a = mocks.post.mock.calls[0][2];
        act(() => {
            a.onSuccess(page());
            a.onFinish();
            submit(20);
        });
        const b = mocks.post.mock.calls[1][2];
        expect(a.headers).not.toEqual(b.headers);
        expect(result.current.pending).toBe(true);
        act(() => {
            a.onFinish();
            submit(30);
        });
        expect(mocks.post).toHaveBeenCalledTimes(2);
        expect(result.current.pending).toBe(true);
        act(() => {
            b.onSuccess(page());
            b.onFinish();
        });
        expect(result.current.pending).toBe(false);
        expect(denied).not.toHaveBeenCalled();
    });
    it('leaves an operational error redirect unsuccessful without exposing raw text', () => {
        const confirmed = vi.fn();
        const { result } = renderHook(() => useUnifiAction(42, vi.fn()));
        act(() =>
            result.current.execute({
                method: 'post',
                path,
                label: 'Device sync',
                onConfirmed: confirmed,
            }),
        );
        const options = mocks.post.mock.calls[0][2];
        act(() => {
            options.onSuccess(
                page({ flash: { error: 'private response body' } }),
            );
            options.onFinish();
        });
        expect(confirmed).not.toHaveBeenCalled();
        expect(result.current.error).toMatch(/did not complete/);
        expect(result.current.error).not.toContain('private response body');
        expect(result.current.uncertain).toBe(false);
    });
    it.each(['wrong-marker', 'wrong-method', 'wrong-origin', 'wrong-path'])(
        'ignores an invalid response with %s',
        (variant) => {
            const denied = vi.fn();
            const { result } = renderHook(() => useUnifiAction(42, denied));
            act(() =>
                result.current.execute({ method: 'post', path, label: 'Sync' }),
            );
            const options = mocks.post.mock.calls[0][2];
            const changes =
                variant === 'wrong-marker'
                    ? { headers: { 'X-Obli-UniFi-Action': 'another-request' } }
                    : variant === 'wrong-method'
                      ? { method: 'get' }
                      : variant === 'wrong-origin'
                        ? { url: 'https://elsewhere.example' + path }
                        : { url: path + '-other' };
            const event = response(options, 403, changes);
            expect(event.preventDefault).not.toHaveBeenCalled();
            expect(denied).not.toHaveBeenCalled();
            expect(result.current.pending).toBe(true);
            act(() => {
                options.onSuccess(page());
                options.onFinish();
            });
            expect(result.current.pending).toBe(false);
        },
    );
    it.each([401, 403, 404])(
        'denies an exact-owned %s reply and ignores late success',
        (status) => {
            const denied = vi.fn(),
                confirmed = vi.fn();
            const { result } = renderHook(() => useUnifiAction(42, denied));
            act(() =>
                result.current.execute({
                    method: 'post',
                    path,
                    label: 'Sync',
                    onConfirmed: confirmed,
                }),
            );
            const options = mocks.post.mock.calls[0][2];
            const event = response(options, status);
            expect(event.preventDefault).toHaveBeenCalled();
            expect(denied).toHaveBeenCalledOnce();
            expect(result.current.pending).toBe(false);
            act(() => {
                options.onSuccess(page());
                options.onFinish();
            });
            expect(confirmed).not.toHaveBeenCalled();
        },
    );
    it.each([
        'invalid',
        'cancel',
        'finish-without-outcome',
        'projection-mismatch',
    ])('blocks automatic repeats after an unconfirmed %s', (cause) => {
        const confirmed = vi.fn(),
            unconfirmed = vi.fn();
        const { result } = renderHook(() => useUnifiAction(42, vi.fn()));
        const submit = () =>
            result.current.execute({
                method: 'post',
                path,
                label: 'Sync',
                confirmed: () => cause !== 'projection-mismatch',
                onConfirmed: confirmed,
                onUnconfirmed: unconfirmed,
            });
        act(submit);
        const options = mocks.post.mock.calls[0][2];
        if (cause === 'invalid') response(options, 503);
        else
            act(() => {
                if (cause === 'cancel') options.onCancel();
                if (cause === 'projection-mismatch')
                    options.onSuccess(page({}, options));
                options.onFinish();
                submit();
            });
        act(() => {
            submit();
            result.current.clearMessage();
            options.onFinish();
        });
        expect(result.current.uncertain).toBe(true);
        expect(result.current.error).toMatch(/not confirmed/);
        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(confirmed).not.toHaveBeenCalled();
        expect(unconfirmed).toHaveBeenCalledOnce();
    });
    it.each([
        'actor',
        'user',
        'component',
        'manage',
        'viewAny',
        'integrationsManage',
    ])('clears authority when the response loses %s', (field) => {
        const denied = vi.fn(),
            confirmed = vi.fn();
        const { result } = renderHook(() => useUnifiAction(42, denied));
        act(() =>
            result.current.execute({
                method: 'post',
                path,
                label: 'Sync',
                onConfirmed: confirmed,
            }),
        );
        const next = page();
        if (field === 'actor') next.props.actor_id = 77;
        if (field === 'user') next.props.auth.user!.id = 77;
        if (field === 'component') next.component = 'another-page';
        if (field === 'manage') next.props.can.manage = false;
        if (field === 'viewAny')
            next.props.auth.can!.securityDevices!.viewAny = false;
        if (field === 'integrationsManage')
            next.props.auth.can!.securityDevices!.integrationsManage = false;
        const options = mocks.post.mock.calls[0][2];
        act(() => {
            options.onSuccess(next);
            options.onFinish();
        });
        expect(denied).toHaveBeenCalledOnce();
        expect(confirmed).not.toHaveBeenCalled();
    });
    it('ignores callbacks after unmount and refuses another actor or external route', () => {
        const denied = vi.fn(),
            confirmed = vi.fn();
        const { result, rerender, unmount } = renderHook(
            ({ actor }) => useUnifiAction(actor, denied),
            { initialProps: { actor: 42 } },
        );
        act(() =>
            result.current.execute({
                method: 'post',
                path: 'https://elsewhere.example' + path,
                label: 'Sync',
            }),
        );
        expect(mocks.post).not.toHaveBeenCalled();
        rerender({ actor: 77 });
        act(() =>
            result.current.execute({ method: 'post', path, label: 'Sync' }),
        );
        expect(mocks.post).not.toHaveBeenCalled();
        rerender({ actor: 42 });
        act(() =>
            result.current.execute({
                method: 'post',
                path,
                label: 'Sync',
                onConfirmed: confirmed,
            }),
        );
        const options = mocks.post.mock.calls[0][2];
        unmount();
        act(() => {
            options.onSuccess(page());
            options.onFinish();
        });
        expect(confirmed).not.toHaveBeenCalled();
        expect(denied).not.toHaveBeenCalled();
    });
});

describe('UniFi action acknowledgement', () => {
    it.each([
        'missing',
        'old-request',
        'other-method',
        'other-path',
        'other-actor',
    ])('does not confirm unchanged props with a %s acknowledgement', (kind) => {
        const confirmed = vi.fn();
        const { result } = renderHook(() => useUnifiAction(42, vi.fn()));
        act(() =>
            result.current.execute({
                method: 'post',
                path,
                label: 'Mapping',
                confirmed: () => true,
                onConfirmed: confirmed,
            }),
        );
        const options = mocks.post.mock.calls[0][2];
        const next = page({}, options);
        if (kind === 'missing') next.props.actionOutcome = undefined;
        else if (kind === 'old-request')
            next.props.actionOutcome!.request_id = 'a'.repeat(32);
        else if (kind === 'other-method')
            next.props.actionOutcome!.method = 'put';
        else if (kind === 'other-path')
            next.props.actionOutcome!.path = path + '-other';
        else next.props.actionOutcome!.actor_id = 77;
        act(() => {
            options.onSuccess(next);
            options.onFinish();
        });
        expect(confirmed).not.toHaveBeenCalled();
        expect(result.current.uncertain).toBe(true);
    });
    it('confirms only the acknowledged request together with its expected returned state', () => {
        const confirmed = vi.fn();
        const { result } = renderHook(() => useUnifiAction(42, vi.fn()));
        act(() =>
            result.current.execute({
                method: 'post',
                path,
                label: 'Mapping',
                confirmed: () => true,
                onConfirmed: confirmed,
            }),
        );
        const options = mocks.post.mock.calls[0][2];
        act(() => {
            options.onSuccess(page({}, options));
            options.onFinish();
        });
        expect(confirmed).toHaveBeenCalledOnce();
        expect(result.current.uncertain).toBe(false);
    });
});
