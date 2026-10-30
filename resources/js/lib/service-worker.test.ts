/* eMAR audit Lane A4 (EA-007): the service worker never keeps medication
 * pages, APIs, exports or PDFs on the device, and activating it removes the
 * old cache that did. public/sw.js runs here in a sandbox with stubbed
 * service-worker globals. */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { describe, expect, it, vi } from 'vitest';

type Listener = (event: Record<string, unknown>) => void;

function jsonResponse(payload: unknown, status = 200) {
    return {
        ...htmlResponse('application/json'),
        ok: status === 200,
        status,
        redirected: false,
        json: async () => payload,
    };
}

const chatPayload = {
    title: 'New Chat message',
    body: 'Open Chat to view the message.',
    data: {
        type: 'chat',
        notification_id: 'a1234567-1234-4321-8123-123456789abc',
        device_binding: 'a'.repeat(64),
        silent: false,
    },
};

function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((done) => {
        resolve = done;
    });
    return { promise, resolve };
}

function htmlResponse(
    contentType = 'text/html; charset=UTF-8',
    extra: Record<string, string> = {},
) {
    const headers = new Map(
        Object.entries({ 'Content-Type': contentType, ...extra }).map(
            ([k, v]) => [k.toLowerCase(), v],
        ),
    );
    const response = {
        ok: true,
        redirected: false,
        type: 'basic',
        headers: {
            get: (name: string) => headers.get(name.toLowerCase()) ?? null,
        },
        clone: () => response,
    };
    return response;
}

function loadWorker(
    existingCaches: string[] = [],
    claimSound?: (ids: string[]) => Promise<boolean | null>,
    soundContext?: (
        actor: number,
    ) => Promise<{ active: boolean; conversations: number[] } | null>,
) {
    const listeners = new Map<string, Listener>();
    const stores = new Map<string, Map<string, unknown>>(
        existingCaches.map((name) => [name, new Map()]),
    );
    const keyOf = (request: unknown) =>
        typeof request === 'string'
            ? request
            : String((request as { url: string }).url);
    const caches = {
        open: vi.fn(async (name: string) => {
            if (!stores.has(name)) stores.set(name, new Map());
            const store = stores.get(name)!;
            return {
                put: vi.fn(async (request: unknown, response: unknown) => {
                    store.set(keyOf(request), response);
                }),
                add: vi.fn(async (request: unknown) => {
                    store.set(keyOf(request), htmlResponse());
                }),
                match: vi.fn(async (request: unknown) =>
                    store.get(keyOf(request)),
                ),
            };
        }),
        keys: vi.fn(async () => [...stores.keys()]),
        delete: vi.fn(async (name: string) => stores.delete(name)),
    };
    const fetchMock = vi.fn(async (request?: unknown, options?: unknown) => {
        void request;
        void options;
        return htmlResponse();
    });
    const self = {
        location: { origin: 'https://care.test' },
        addEventListener: (type: string, listener: Listener) =>
            listeners.set(type, listener),
        skipWaiting: vi.fn(),
        clients: {
            claim: vi.fn(),
            matchAll: vi.fn(async () => [] as Array<Record<string, unknown>>),
            openWindow: vi.fn(async () => null),
        },
        registration: {
            showNotification: vi.fn(),
            getNotifications: vi.fn(
                async (_options?: unknown) =>
                    [] as Array<{
                        data: Record<string, unknown>;
                        close: () => void;
                    }>,
            ),
        },
    };
    const context = vm.createContext({
        self,
        OblivionChatSoundReceipts: claimSound
            ? { claim: claimSound, context: soundContext }
            : undefined,
        caches,
        fetch: fetchMock,
        clients: self.clients,
        URL,
        Request: class {
            url: string;
            constructor(url: string) {
                this.url = new URL(url, 'https://care.test').href;
            }
        },
        Promise,
        AbortController,
        setTimeout,
        clearTimeout,
        console,
    });
    vm.runInContext(
        readFileSync(path.resolve(__dirname, '../../../public/sw.js'), 'utf8'),
        context,
    );

    return {
        listeners,
        stores,
        fetchMock,
        clients: self.clients,
        registration: self.registration,
    };
}

async function dispatch(listener: Listener, event: Record<string, unknown>) {
    const waits: Promise<unknown>[] = [];
    let responded: Promise<unknown> | null = null;
    listener({
        ...event,
        waitUntil: (p: Promise<unknown>) => waits.push(p),
        respondWith: (p: Promise<unknown>) => {
            responded = Promise.resolve(p);
        },
    });
    await Promise.all(waits);
    if (responded) await responded;
    return responded !== null;
}

function navigation(pathname: string) {
    return {
        request: {
            url: `https://care.test${pathname}`,
            mode: 'navigate',
            method: 'GET',
        },
    };
}

function get(pathname: string) {
    return {
        request: {
            url: `https://care.test${pathname}`,
            mode: 'cors',
            method: 'GET',
        },
    };
}

describe('service worker (EA-007)', () => {
    it('answers the capability challenge only for an app client using its message port', async () => {
        const { listeners } = loadWorker();
        const postMessage = vi.fn();
        await dispatch(listeners.get('message')!, {
            source: { url: 'https://other.test' },
            ports: [{ postMessage }],
            data: { type: 'chat-worker-capability', nonce: 'challenge' },
        });
        expect(postMessage).not.toHaveBeenCalled();
        await dispatch(listeners.get('message')!, {
            source: { url: 'https://care.test/settings/notifications' },
            ports: [{ postMessage }],
            data: { type: 'chat-worker-capability', nonce: 'challenge' },
        });
        expect(postMessage).toHaveBeenCalledExactlyOnceWith({
            type: 'chat-worker-ready',
            nonce: 'challenge',
            version: 2,
        });
    });
    it('uses only the current authenticated preview and never the queued provider text', async () => {
        const { listeners, registration, fetchMock, stores } = loadWorker();
        fetchMock.mockResolvedValue(jsonResponse(chatPayload));
        await dispatch(listeners.get('push')!, {
            data: {
                json: () => ({
                    ...chatPayload,
                    title: 'Old name',
                    body: 'Private stale text',
                }),
            },
        });
        expect(fetchMock).toHaveBeenCalledWith(
            `/chat/notifications/${chatPayload.data.notification_id}/display?binding=${chatPayload.data.device_binding}`,
            expect.objectContaining({
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                headers: { Accept: 'application/json' },
            }),
        );
        expect(registration.showNotification).toHaveBeenCalledWith(
            chatPayload.title,
            expect.objectContaining({ body: chatPayload.body }),
        );
        expect(stores.size).toBe(0);
    });

    it.each([401, 404, 429, 503])(
        'does not display Chat when the session/authority check returns %s',
        async (status) => {
            const { listeners, registration, fetchMock } = loadWorker();
            fetchMock.mockResolvedValue(jsonResponse({}, status));
            await dispatch(listeners.get('push')!, {
                data: { json: () => chatPayload },
            });
            expect(registration.showNotification).not.toHaveBeenCalled();
        },
    );

    it('does not fall back to a payload when offline, redirected, malformed or bound to another device', async () => {
        const { listeners, registration, fetchMock } = loadWorker();
        fetchMock.mockRejectedValueOnce(new Error('Offline'));
        await dispatch(listeners.get('push')!, {
            data: { json: () => chatPayload },
        });
        fetchMock.mockResolvedValueOnce(htmlResponse());
        await dispatch(listeners.get('push')!, {
            data: { json: () => chatPayload },
        });
        fetchMock.mockResolvedValueOnce({
            ...jsonResponse(chatPayload),
            redirected: true,
        });
        await dispatch(listeners.get('push')!, {
            data: { json: () => chatPayload },
        });
        fetchMock.mockResolvedValueOnce(
            jsonResponse({
                ...chatPayload,
                data: { ...chatPayload.data, device_binding: 'b'.repeat(64) },
            }),
        );
        await dispatch(listeners.get('push')!, {
            data: { json: () => chatPayload },
        });
        const before = fetchMock.mock.calls.length;
        await dispatch(listeners.get('push')!, {
            data: { json: () => ({ ...chatPayload, data: { type: 'chat' } }) },
        });
        expect(fetchMock).toHaveBeenCalledTimes(before);
        expect(registration.showNotification).not.toHaveBeenCalled();
    });

    it('clears only unauthorised Chat notices on account refresh and ignores cross-origin messages', async () => {
        const { listeners, registration, fetchMock } = loadWorker();
        const valid = { data: chatPayload.data, close: vi.fn() };
        const old = {
            data: {
                ...chatPayload.data,
                notification_id: 'b1234567-1234-4321-8123-123456789abc',
            },
            close: vi.fn(),
        };
        const task = { data: { type: 'task' }, close: vi.fn() };
        registration.getNotifications.mockResolvedValue([valid, old, task]);
        fetchMock
            .mockResolvedValueOnce(jsonResponse(chatPayload))
            .mockResolvedValueOnce(jsonResponse({}, 404));
        await dispatch(listeners.get('message')!, {
            data: { type: 'chat-account-refresh' },
            source: { url: 'https://care.test/my-day' },
        });
        expect(valid.close).not.toHaveBeenCalled();
        expect(old.close).toHaveBeenCalledOnce();
        expect(task.close).not.toHaveBeenCalled();
        registration.getNotifications.mockClear();
        await dispatch(listeners.get('message')!, {
            data: { type: 'chat-account-refresh' },
            source: { url: 'https://other.test' },
        });
        expect(registration.getNotifications).not.toHaveBeenCalled();
    });

    it('invalidates a pre-logout permission response and forwards sign-out unchanged', async () => {
        const { listeners, registration, fetchMock } = loadWorker();
        const preview = deferred<ReturnType<typeof jsonResponse>>();
        fetchMock.mockReturnValueOnce(preview.promise);
        const push = dispatch(listeners.get('push')!, {
            data: { json: () => chatPayload },
        });
        const close = vi.fn();
        registration.getNotifications.mockResolvedValue([
            { data: chatPayload.data, close },
        ]);
        const request = { url: 'https://care.test/logout', method: 'POST' };
        await dispatch(listeners.get('fetch')!, { request });
        expect(fetchMock).toHaveBeenLastCalledWith(request);
        expect(close).toHaveBeenCalledOnce();
        preview.resolve(jsonResponse(chatPayload));
        await push;
        expect(registration.showNotification).not.toHaveBeenCalled();
    });

    it('closes a display operation that completes after sign-out cleanup', async () => {
        const { listeners, registration, fetchMock } = loadWorker();
        fetchMock.mockResolvedValue(jsonResponse(chatPayload));
        const shown = deferred<void>();
        registration.showNotification.mockReturnValue(shown.promise);
        const push = dispatch(listeners.get('push')!, {
            data: { json: () => chatPayload },
        });
        await vi.waitFor(() =>
            expect(registration.showNotification).toHaveBeenCalledOnce(),
        );
        await dispatch(listeners.get('fetch')!, {
            request: { url: 'https://care.test/logout', method: 'POST' },
        });
        const close = vi.fn();
        registration.getNotifications.mockResolvedValue([
            { data: chatPayload.data, close },
        ]);
        shown.resolve();
        await push;
        expect(close).toHaveBeenCalledOnce();
    });

    it.each(['/login', '/two-factor-challenge', '/register'])(
        'invalidates pending Chat display during %s',
        async (path) => {
            const { listeners, registration, fetchMock } = loadWorker();
            const auth = deferred<ReturnType<typeof htmlResponse>>();
            fetchMock.mockReturnValueOnce(auth.promise);
            const request = { url: `https://care.test${path}`, method: 'POST' };
            const pending = dispatch(listeners.get('fetch')!, { request });
            await dispatch(listeners.get('push')!, {
                data: { json: () => chatPayload },
            });
            expect(fetchMock).toHaveBeenCalledOnce();
            expect(registration.showNotification).not.toHaveBeenCalled();
            auth.resolve(htmlResponse());
            await pending;
        },
    );

    it('revalidates persisted Chat notifications when the worker activates', async () => {
        const { listeners, registration, fetchMock } = loadWorker();
        const close = vi.fn();
        registration.getNotifications.mockResolvedValue([
            { data: chatPayload.data, close },
        ]);
        fetchMock.mockResolvedValue(jsonResponse({}, 401));
        await dispatch(listeners.get('activate')!, {});
        expect(close).toHaveBeenCalledOnce();
    });

    it('gives each Chat notice a stable replacement tag and respects silent delivery', async () => {
        const { listeners, registration, fetchMock } = loadWorker();
        const id = 'a1234567-1234-4321-8123-123456789abc';
        const payload = {
            title: 'New Chat message',
            body: 'Open Chat to view the message.',
            data: {
                type: 'chat',
                notification_id: id,
                device_binding: 'a'.repeat(64),
                silent: true,
            },
        };
        fetchMock.mockResolvedValue(jsonResponse(payload));
        await dispatch(listeners.get('push')!, {
            data: { json: () => payload },
        });
        await dispatch(listeners.get('push')!, {
            data: { json: () => payload },
        });
        expect(registration.showNotification).toHaveBeenLastCalledWith(
            'New Chat message',
            expect.objectContaining({
                tag: `chat-${id}`,
                renotify: false,
                silent: true,
            }),
        );
        const next = 'b1234567-1234-4321-8123-123456789abc';
        fetchMock.mockResolvedValue(
            jsonResponse({
                ...payload,
                data: { ...payload.data, notification_id: next, silent: false },
            }),
        );
        await dispatch(listeners.get('push')!, {
            data: {
                json: () => ({
                    ...payload,
                    data: {
                        ...payload.data,
                        notification_id: next,
                        silent: false,
                    },
                }),
            },
        });
        expect(registration.showNotification).toHaveBeenLastCalledWith(
            'New Chat message',
            expect.objectContaining({ tag: `chat-${next}`, silent: false }),
        );
    });

    it('opens the exact Chat receipt without navigating away from unfinished work', async () => {
        const { listeners, clients } = loadWorker();
        const focus = vi.fn();
        const navigate = vi.fn();
        clients.matchAll.mockResolvedValue([
            { url: 'https://care.test/my-day', focus, navigate },
        ]);
        const id = 'a1234567-1234-4321-8123-123456789abc';
        const close = vi.fn();
        await dispatch(listeners.get('notificationclick')!, {
            notification: {
                close,
                data: {
                    type: 'chat',
                    notification_id: id,
                    url: 'https://untrusted.test/',
                },
            },
        });
        expect(close).toHaveBeenCalledOnce();
        expect(navigate).not.toHaveBeenCalled();
        expect(clients.openWindow).toHaveBeenCalledWith(
            `https://care.test/chat/notifications/${id}`,
        );
        clients.openWindow.mockClear();
        clients.matchAll.mockResolvedValue([
            {
                url: `https://care.test/chat/notifications/${id}`,
                focus,
                navigate,
            },
        ]);
        await dispatch(listeners.get('notificationclick')!, {
            notification: {
                close,
                data: { type: 'chat', notification_id: id },
            },
        });
        expect(focus).toHaveBeenCalledOnce();
        expect(clients.openWindow).not.toHaveBeenCalled();
    });

    it('retains existing non-Chat notification behaviour and never caches Chat pages', async () => {
        const { listeners, registration, clients, stores } = loadWorker();
        await dispatch(listeners.get('push')!, {
            data: {
                json: () => ({
                    title: 'Task',
                    body: 'Open task',
                    data: { type: 'task', url: '/my-day' },
                }),
            },
        });
        expect(registration.showNotification).toHaveBeenCalledWith(
            'Task',
            expect.objectContaining({ tag: 'task' }),
        );
        const focus = vi.fn();
        const navigate = vi.fn(async () => ({ focus }));
        clients.matchAll.mockResolvedValue([
            { url: 'https://care.test/settings', focus, navigate },
        ]);
        await dispatch(listeners.get('notificationclick')!, {
            notification: {
                close: vi.fn(),
                data: { type: 'task', url: '/my-day' },
            },
        });
        expect(navigate).toHaveBeenCalledWith('https://care.test/my-day');
        expect(
            await dispatch(listeners.get('fetch')!, navigation('/chat')),
        ).toBe(false);
        expect(
            await dispatch(
                listeners.get('fetch')!,
                navigation(
                    '/chat/notifications/a1234567-1234-4321-8123-123456789abc',
                ),
            ),
        ).toBe(false);
        expect(stores.size).toBe(0);
    });

    it('shares a notification sound receipt with app tabs while retaining every visual notice', async () => {
        const claim = vi
            .fn()
            .mockResolvedValueOnce(true)
            .mockResolvedValueOnce(false)
            .mockResolvedValueOnce(null);
        const { listeners, fetchMock, registration } = loadWorker([], claim);
        fetchMock.mockResolvedValue(jsonResponse(chatPayload));
        for (let attempt = 0; attempt < 3; attempt++)
            await dispatch(listeners.get('push')!, {
                data: { json: () => chatPayload },
            });
        expect(claim.mock.calls).toEqual(
            Array.from({ length: 3 }, () => [
                [chatPayload.data.notification_id],
            ]),
        );
        expect(
            registration.showNotification.mock.calls.map(
                ([, options]) => options.silent,
            ),
        ).toEqual([false, true, false]);
        expect(registration.showNotification).toHaveBeenCalledTimes(3);
    });

    it('does not claim sound for an already silent notice or show a notice after logout during a claim', async () => {
        const pending = deferred<boolean>();
        const claim = vi.fn().mockReturnValue(pending.promise);
        const { listeners, fetchMock, registration } = loadWorker([], claim);
        fetchMock.mockResolvedValueOnce(
            jsonResponse({
                ...chatPayload,
                data: { ...chatPayload.data, silent: true },
            }),
        );
        await dispatch(listeners.get('push')!, {
            data: { json: () => chatPayload },
        });
        expect(claim).not.toHaveBeenCalled();
        fetchMock.mockResolvedValue(jsonResponse(chatPayload));
        const arriving = dispatch(listeners.get('push')!, {
            data: { json: () => chatPayload },
        });
        await vi.waitFor(() => expect(claim).toHaveBeenCalledTimes(1));
        await dispatch(listeners.get('message')!, {
            data: { type: 'chat-account-refresh' },
            source: { url: 'https://care.test/chat' },
        });
        pending.resolve(true);
        await arriving;
        expect(registration.showNotification).toHaveBeenCalledTimes(1);
    });

    it.each([
        { active: true, conversations: [] },
        { active: false, conversations: [71] },
    ])(
        'keeps native sound quiet for current call or visible thread context %j',
        async (context) => {
            const claim = vi.fn().mockResolvedValue(true);
            const lookup = vi.fn().mockResolvedValue(context);
            const { listeners, fetchMock, registration } = loadWorker(
                [],
                claim,
                lookup,
            );
            fetchMock.mockResolvedValue(
                jsonResponse({
                    ...chatPayload,
                    actor_id: 11,
                    conversation_id: 71,
                }),
            );
            await dispatch(listeners.get('push')!, {
                data: { json: () => chatPayload },
            });
            expect(lookup).toHaveBeenCalledWith(11);
            expect(claim).toHaveBeenCalledWith([
                chatPayload.data.notification_id,
            ]);
            expect(registration.showNotification).toHaveBeenCalledWith(
                chatPayload.title,
                expect.objectContaining({
                    silent: true,
                    data: chatPayload.data,
                }),
            );
            expect(
                registration.showNotification.mock.calls[0][1].data,
            ).not.toHaveProperty('actor_id');
            expect(
                registration.showNotification.mock.calls[0][1].data,
            ).not.toHaveProperty('conversation_id');
        },
    );

    it('installs without fetching or caching any medication page', async () => {
        const { listeners, stores, fetchMock } = loadWorker();
        await dispatch(listeners.get('install')!, {});
        const cached = [...stores.values()].flatMap((store) => [
            ...store.keys(),
        ]);
        expect(cached.some((key) => /\/(emar|meds)/.test(key))).toBe(false);
        const fetched = fetchMock.mock.calls.map(([request]) =>
            String((request as { url?: string } | undefined)?.url ?? request),
        );
        expect(fetched.some((url) => /\/(emar|meds)/.test(url))).toBe(false);
    });

    it.each([
        ['/emar/mar?client_id=5'],
        ['/meds/today'],
        ['/emar'],
        ['/medication-followups'],
        ['/clients/5?tab=medications'],
    ])('never answers or caches the page %s', async (pathname) => {
        const { listeners, stores } = loadWorker();
        const handled = await dispatch(
            listeners.get('fetch')!,
            navigation(pathname),
        );
        expect(handled).toBe(false);
        expect([...stores.values()].every((store) => store.size === 0)).toBe(
            true,
        );
    });

    it.each([
        ['/emar/pdf/controlled-register'],
        ['/emar/audit/export'],
        ['/api/medications/clients/5/medications'],
    ])('never caches the medication download or API %s', async (pathname) => {
        const { listeners, stores } = loadWorker();
        const handled = await dispatch(listeners.get('fetch')!, get(pathname));
        expect(handled).toBe(false);
        expect([...stores.values()].every((store) => store.size === 0)).toBe(
            true,
        );
    });

    it('keeps the allowed offline fleet page, but never a file answer', async () => {
        const { listeners, stores } = loadWorker();
        expect(
            await dispatch(
                listeners.get('fetch')!,
                navigation('/fleet-assets/daily-check'),
            ),
        ).toBe(true);
        expect(
            stores
                .get('of-app-shell-v4')
                ?.has('https://care.test/fleet-assets/daily-check'),
        ).toBe(true);

        const fresh = loadWorker();
        fresh.fetchMock.mockResolvedValueOnce(
            htmlResponse('text/html', {
                'Content-Disposition': 'attachment; filename=x.html',
            }),
        );
        await dispatch(
            fresh.listeners.get('fetch')!,
            navigation('/fleet-assets/daily-check'),
        );
        expect(fresh.stores.get('of-app-shell-v4')?.size ?? 0).toBe(0);
    });

    it('deletes the old cache that held MAR pages, PDFs and exports when it activates', async () => {
        const { listeners, stores } = loadWorker(['of-app-shell-v3']);
        await dispatch(listeners.get('activate')!, {});
        expect(stores.has('of-app-shell-v3')).toBe(false);
    });

    it('purges every cache when the app asks at sign-out', async () => {
        const { listeners, stores } = loadWorker(['of-app-shell-v4', 'other']);
        await dispatch(listeners.get('message')!, {
            data: { type: 'purge-caches' },
        });
        expect(stores.size).toBe(0);
    });
});
