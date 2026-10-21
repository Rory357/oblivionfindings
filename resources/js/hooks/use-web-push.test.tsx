import { verifyChatBrowserWorker } from '@/lib/chat-browser-capability';
import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useWebPush } from './use-web-push';
vi.mock('@/lib/chat-browser-capability', () => ({
    verifyChatBrowserWorker: vi.fn(),
}));

const page = vi.hoisted(() => ({
    props: { webpush_public_key: 'AQID', auth: { user: { id: 11 } } },
}));
vi.mock('@inertiajs/react', () => ({ usePage: () => page }));

const fetchMock = vi.fn();
const permission = vi.fn();
const removeSubscription = vi.fn();
const device = {
    endpoint: 'https://push.example.test/device',
    getKey: () => new Uint8Array([1, 2, 3]).buffer,
    unsubscribe: removeSubscription,
};
const getSubscription = vi.fn();
const subscribe = vi.fn();
const registration = {
    active: { state: 'activated' },
    pushManager: { getSubscription, subscribe },
};
const register = vi.fn();
const getRegistration = vi.fn();

function response(body: unknown, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => body,
    };
}

function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((done) => {
        resolve = done;
    });
    return { promise, resolve };
}

beforeEach(() => {
    vi.resetAllMocks();
    vi.mocked(verifyChatBrowserWorker).mockResolvedValue(2);
    page.props.auth.user.id = 11;
    vi.stubGlobal('fetch', fetchMock);
    vi.stubGlobal('PushManager', class {});
    vi.stubGlobal('Notification', {
        permission: 'granted',
        requestPermission: permission,
    });
    vi.stubGlobal(
        'navigator',
        Object.assign(Object.create(navigator), {
            serviceWorker: { getRegistration, register },
        }),
    );
    getRegistration.mockResolvedValue(registration);
    register.mockResolvedValue(registration);
    getSubscription.mockResolvedValue(device);
    subscribe.mockResolvedValue(device);
    fetchMock.mockResolvedValue(response({ enabled: false }));
});

afterEach(() => vi.unstubAllGlobals());

it('checks the exact device against the current account without registering or requesting permission', async () => {
    const { result } = renderHook(() => useWebPush());
    await waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());
    await waitFor(() => expect(result.current.processing).toBe(false));
    expect(result.current.enabled).toBe(false);
    const [url, request] = fetchMock.mock.calls[0];
    expect(url).toBe('/settings/notifications/push-subscriptions/status');
    expect(JSON.parse(request.body)).toEqual({
        actor_id: '11',
        provider: 'webpush',
        token: device.endpoint,
        keys: { p256dh: 'AQID', auth: 'AQID' },
    });
    expect(request.cache).toBe('no-store');
    expect(register).not.toHaveBeenCalled();
    expect(permission).not.toHaveBeenCalled();
});

it('refreshes status when returning to a tab after the device changed accounts', async () => {
    fetchMock.mockResolvedValueOnce(response({ enabled: true }));
    const { result } = renderHook(() => useWebPush());
    await waitFor(() => expect(result.current.enabled).toBe(true));
    await act(async () => window.dispatchEvent(new Event('focus')));
    await waitFor(() => expect(result.current.enabled).toBe(false));
    expect(register).not.toHaveBeenCalled();
});

it('ignores an old account status response after the signed-in person changes', async () => {
    const old = deferred<ReturnType<typeof response>>();
    fetchMock.mockReturnValueOnce(old.promise);
    const { result, rerender } = renderHook(() => useWebPush());
    await waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());
    page.props.auth.user.id = 22;
    rerender();
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    await act(async () => old.resolve(response({ enabled: true })));
    expect(result.current.enabled).toBe(false);
    expect(JSON.parse(fetchMock.mock.calls[1][1].body).actor_id).toBe('22');
});

it('sends explicit registration for the current account and serialises repeated clicks', async () => {
    const { result } = renderHook(() => useWebPush());
    await waitFor(() => expect(result.current.processing).toBe(false));
    fetchMock.mockResolvedValue(response({ enabled: true }, 201));
    await act(async () => {
        await Promise.all([
            result.current.subscribe(),
            result.current.subscribe(),
        ]);
    });
    expect(result.current.enabled).toBe(true);
    expect(register).toHaveBeenCalledOnce();
    expect(
        fetchMock.mock.calls.filter(
            ([url]) => url === '/settings/notifications/push-subscriptions',
        ),
    ).toHaveLength(1);
    expect(JSON.parse(fetchMock.mock.calls.at(-1)![1].body).actor_id).toBe(
        '11',
    );
});

it('does not bind the old account after a delayed permission prompt', async () => {
    vi.stubGlobal('Notification', {
        permission: 'default',
        requestPermission: permission,
    });
    const prompt = deferred<string>();
    permission.mockReturnValue(prompt.promise);
    const { result, rerender } = renderHook(() => useWebPush());
    await waitFor(() => expect(result.current.processing).toBe(false));
    let pending!: Promise<void>;
    act(() => {
        pending = result.current.subscribe();
    });
    page.props.auth.user.id = 22;
    rerender();
    await act(async () => {
        prompt.resolve('granted');
        await pending;
    });
    expect(register).not.toHaveBeenCalled();
    expect(fetchMock).not.toHaveBeenCalled();
    expect(result.current.enabled).toBe(false);
});

it('keeps a stale-cookie conflict disabled with a clear recovery message', async () => {
    const { result } = renderHook(() => useWebPush());
    await waitFor(() => expect(result.current.processing).toBe(false));
    fetchMock.mockResolvedValue(response({}, 409));
    await act(async () => result.current.subscribe());
    expect(result.current.enabled).toBe(false);
    expect(result.current.error).toContain('Refresh this page');
    expect(result.current.processing).toBe(false);
});

it('disables only the server binding without deleting a newer browser subscription', async () => {
    fetchMock.mockResolvedValueOnce(response({ enabled: true }));
    const { result } = renderHook(() => useWebPush());
    await waitFor(() => expect(result.current.enabled).toBe(true));
    fetchMock.mockResolvedValue(response(null, 204));
    await act(async () => result.current.unsubscribe());
    const request = fetchMock.mock.calls.at(-1)![1];
    expect(request.method).toBe('DELETE');
    expect(JSON.parse(request.body).keys).toEqual({
        p256dh: 'AQID',
        auth: 'AQID',
    });
    expect(result.current.enabled).toBe(false);
    expect(removeSubscription).not.toHaveBeenCalled();
});

it('fails closed on a status failure and recovers on returning to the tab', async () => {
    fetchMock.mockRejectedValueOnce(new Error('Network unavailable'));
    const { result } = renderHook(() => useWebPush());
    await waitFor(() =>
        expect(result.current.error).toContain('Could not check'),
    );
    expect(result.current.enabled).toBe(false);
    fetchMock.mockResolvedValue(response({ enabled: true }));
    await act(async () => window.dispatchEvent(new Event('focus')));
    await waitFor(() => expect(result.current.enabled).toBe(true));
    expect(result.current.error).toBeNull();
});

it('waits for a newly installed worker before creating a browser subscription', async () => {
    const worker = Object.assign(new EventTarget(), { state: 'installing' });
    const firstRegistration = {
        ...registration,
        active: null,
        installing: worker,
    };
    register.mockResolvedValue(firstRegistration);
    const { result } = renderHook(() => useWebPush());
    await waitFor(() => expect(result.current.processing).toBe(false));
    getSubscription.mockResolvedValue(null);
    let pending!: Promise<void>;
    act(() => {
        pending = result.current.subscribe();
    });
    await waitFor(() => expect(register).toHaveBeenCalledOnce());
    expect(subscribe).not.toHaveBeenCalled();
    await act(async () => {
        worker.state = 'activated';
        worker.dispatchEvent(new Event('statechange'));
        await pending;
    });
    expect(subscribe).toHaveBeenCalledOnce();
    expect(result.current.enabled).toBe(true);
});

it('reports a failed worker activation without binding or enabling the device', async () => {
    const worker = Object.assign(new EventTarget(), { state: 'redundant' });
    register.mockResolvedValue({
        ...registration,
        active: null,
        installing: worker,
    });
    const { result } = renderHook(() => useWebPush());
    await waitFor(() => expect(result.current.processing).toBe(false));
    await act(async () => result.current.subscribe());
    expect(result.current.enabled).toBe(false);
    expect(result.current.error).toContain('could not start');
    expect(subscribe).not.toHaveBeenCalled();
});

it('does not create a subscription after a delayed device lookup changes accounts', async () => {
    const { result, rerender } = renderHook(() => useWebPush());
    await waitFor(() => expect(result.current.processing).toBe(false));
    const lookup = deferred<null>();
    getSubscription.mockReturnValueOnce(lookup.promise);
    let pending!: Promise<void>;
    act(() => {
        pending = result.current.subscribe();
    });
    await waitFor(() => expect(register).toHaveBeenCalledOnce());
    page.props.auth.user.id = 22;
    rerender();
    await act(async () => {
        lookup.resolve(null);
        await pending;
    });
    expect(subscribe).not.toHaveBeenCalled();
    expect(fetchMock.mock.calls.every(([url]) => url.endsWith('/status'))).toBe(
        true,
    );
});

it('requires a current worker capability before registering this browser', async () => {
    const { result } = renderHook(() => useWebPush());
    await waitFor(() => expect(result.current.processing).toBe(false));
    vi.mocked(verifyChatBrowserWorker).mockRejectedValueOnce(
        new Error('Browser notifications are updating.'),
    );
    await act(async () => result.current.subscribe());
    expect(result.current.enabled).toBe(false);
    expect(result.current.error).toContain('updating');
    expect(fetchMock.mock.calls.every(([url]) => url.endsWith('/status'))).toBe(
        true,
    );
    await act(async () => result.current.subscribe());
    expect(
        JSON.parse(fetchMock.mock.calls.at(-1)![1].body).chat_protocol_version,
    ).toBe(2);
});

it('offers an explicit upgrade for an enabled legacy device and keeps that state after a failed upgrade', async () => {
    fetchMock.mockResolvedValueOnce(
        response({ enabled: true, chat_ready: false }),
    );
    const { result } = renderHook(() => useWebPush());
    await waitFor(() => expect(result.current.needsUpgrade).toBe(true));
    expect(result.current.enabled).toBe(true);
    vi.mocked(verifyChatBrowserWorker).mockRejectedValueOnce(
        new Error('Updating'),
    );
    await act(async () => result.current.subscribe());
    expect(result.current.needsUpgrade).toBe(true);
    expect(result.current.enabled).toBe(true);
    fetchMock.mockResolvedValue(
        response({ enabled: true, chat_ready: true }, 201),
    );
    await act(async () => result.current.subscribe());
    expect(result.current.needsUpgrade).toBe(false);
    expect(result.current.enabled).toBe(true);
});
