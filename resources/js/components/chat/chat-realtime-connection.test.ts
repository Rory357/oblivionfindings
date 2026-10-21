import type Pusher from 'pusher-js';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import { connectChatRealtime } from './chat-realtime-connection';

type Handler = (value?: unknown) => void;
type Fixture = {
    options: ConstructorParameters<typeof Pusher>[1];
    events: Map<string, Handler>;
    channelEvents: Map<string, Handler>;
    disconnect: ReturnType<typeof vi.fn>;
    subscribe: ReturnType<typeof vi.fn>;
};
const fixtures = vi.hoisted(() => ({ clients: [] as Fixture[] }));
vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
vi.mock('pusher-js', () => ({
    default: class {
        connection: { bind: (event: string, handler: Handler) => void };
        disconnect = vi.fn();
        subscribe: ReturnType<typeof vi.fn>;
        constructor(_key: string, options: Fixture['options']) {
            const events = new Map<string, Handler>();
            const channelEvents = new Map<string, Handler>();
            this.connection = {
                bind: (name, callback) => {
                    events.set(name, callback);
                },
            };
            this.subscribe = vi.fn(() => ({
                bind: (name: string, callback: Handler) =>
                    channelEvents.set(name, callback),
            }));
            fixtures.clients.push({
                options,
                events,
                channelEvents,
                disconnect: this.disconnect,
                subscribe: this.subscribe,
            });
        }
    },
}));
const config = {
    actor_id: 1,
    enabled: true,
    key: 'public-fixture',
    path: '/chat-live',
    channel: 'private-chat.user.1',
};
const flush = async () => {
    await vi.dynamicImportSettled();
    await vi.advanceTimersByTimeAsync(0);
};

describe('self-hosted Chat live connection', () => {
    let stop: (() => void) | undefined;
    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
        fixtures.clients.length = 0;
        vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(true);
        vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
        vi.mocked(chatRequest).mockResolvedValue(config);
    });
    afterEach(() => {
        stop?.();
        stop = undefined;
        vi.useRealTimers();
        vi.restoreAllMocks();
    });
    it('times out a stalled configuration request and retries without remaining stuck', async () => {
        const state = vi.fn();
        let signal: AbortSignal | undefined;
        vi.mocked(chatRequest).mockImplementationOnce((_path, options) => {
            signal = options?.signal as AbortSignal;
            return new Promise((_resolve, reject) => {
                signal?.addEventListener(
                    'abort',
                    () => reject(new DOMException('Aborted', 'AbortError')),
                    { once: true },
                );
            });
        });
        stop = connectChatRealtime(1, state, vi.fn());
        await vi.advanceTimersByTimeAsync(10000);
        expect(signal?.aborted).toBe(true);
        expect(state).toHaveBeenLastCalledWith('recovering');
        await vi.advanceTimersByTimeAsync(1000);
        await flush();
        expect(fixtures.clients).toHaveLength(1);
    });
    it('uses only same-origin WebSockets with telemetry and public fallback disabled', async () => {
        const state = vi.fn();
        const refresh = vi.fn();
        stop = connectChatRealtime(1, state, refresh);
        await flush();
        const client = fixtures.clients[0];
        expect(client.options).toMatchObject({
            wsHost: location.hostname,
            wsPath: '/chat-live',
            enabledTransports: ['ws', 'wss'],
            enableStats: false,
            disableStats: true,
        });
        expect(client.subscribe).toHaveBeenCalledWith('private-chat.user.1');
        client.channelEvents.get('pusher:subscription_succeeded')?.();
        for (let i = 0; i < 20; i++)
            client.channelEvents.get('chat.changed')?.({
                ignored: 'No message data is consumed',
            });
        await vi.advanceTimersByTimeAsync(150);
        expect(state).toHaveBeenLastCalledWith('live');
        expect(refresh).toHaveBeenCalledTimes(1);
        for (let i = 0; i < 10; i++) {
            client.channelEvents.get('chat.changed')?.();
            await vi.advanceTimersByTimeAsync(50);
        }
        expect(refresh.mock.calls.length).toBeGreaterThan(3);
    });
    it('does not start a socket when the feature is unconfigured or configuration arrives after cleanup', async () => {
        vi.mocked(chatRequest).mockResolvedValue({
            actor_id: 1,
            enabled: false,
        });
        stop = connectChatRealtime(1, vi.fn(), vi.fn());
        await flush();
        expect(fixtures.clients).toHaveLength(0);
        stop();
        let finish!: (value: typeof config) => void;
        vi.mocked(chatRequest).mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }) as never,
        );
        stop = connectChatRealtime(1, vi.fn(), vi.fn());
        stop();
        finish(config);
        await flush();
        expect(fixtures.clients).toHaveLength(0);
    });
    it('disconnects on revoked authorisation and does not retry the rejected identity on a timer', async () => {
        const state = vi.fn();
        const refresh = vi.fn();
        vi.mocked(chatRequest).mockImplementation(async (url) => {
            if (url.endsWith('/auth'))
                throw new ChatError('Access removed', 403);
            return config as never;
        });
        stop = connectChatRealtime(1, state, refresh);
        await flush();
        const client = fixtures.clients[0];
        const authorise = client.options.channelAuthorization;
        if (!authorise || !('customHandler' in authorise))
            throw new Error('Missing private authorisation handler');
        const callback = vi.fn();
        authorise.customHandler(
            { socketId: '123.456', channelName: config.channel },
            callback,
        );
        await flush();
        await vi.advanceTimersByTimeAsync(120000);
        expect(callback).toHaveBeenCalledWith(expect.any(Error), null);
        expect(client.disconnect).toHaveBeenCalledTimes(1);
        expect(fixtures.clients).toHaveLength(1);
        expect(refresh).toHaveBeenCalledTimes(1);
    });
    it('reconnects with backoff and ignores events from a discarded socket', async () => {
        const refresh = vi.fn();
        stop = connectChatRealtime(1, vi.fn(), refresh);
        await flush();
        fixtures.clients[0].events.get('state_change')?.({
            current: 'unavailable',
        });
        await vi.advanceTimersByTimeAsync(999);
        expect(fixtures.clients).toHaveLength(1);
        await vi.advanceTimersByTimeAsync(1);
        await flush();
        expect(fixtures.clients).toHaveLength(2);
        fixtures.clients[0].channelEvents.get('chat.changed')?.();
        await vi.advanceTimersByTimeAsync(150);
        expect(refresh).not.toHaveBeenCalled();
        fixtures.clients[1].channelEvents.get(
            'pusher:subscription_succeeded',
        )?.();
        await vi.advanceTimersByTimeAsync(150);
        expect(refresh).toHaveBeenCalledTimes(1);
        stop();
        await vi.advanceTimersByTimeAsync(120000);
        expect(fixtures.clients).toHaveLength(2);
    });
    it('binds both configuration and socket authorisation to the rendered account', async () => {
        vi.mocked(chatRequest).mockImplementation(
            async (path) =>
                (path.endsWith('/auth')
                    ? { auth: 'fixture-signature' }
                    : config) as never,
        );
        stop = connectChatRealtime(1, vi.fn(), vi.fn());
        await flush();
        const authorise = fixtures.clients[0].options.channelAuthorization;
        if (!authorise || !('customHandler' in authorise))
            throw new Error('Missing authorisation');
        const callback = vi.fn();
        authorise.customHandler(
            { socketId: '123.456', channelName: config.channel },
            callback,
        );
        await flush();
        expect(
            vi
                .mocked(chatRequest)
                .mock.calls.map(([url, options]) => [
                    url,
                    new Headers(options?.headers).get('X-Chat-Actor'),
                ]),
        ).toEqual([
            ['/chat/api/realtime', '1'],
            ['/chat/api/realtime/auth', '1'],
        ]);
        expect(callback).toHaveBeenCalledWith(null, {
            auth: 'fixture-signature',
        });
        const rejected = vi.fn();
        authorise.customHandler(
            { socketId: '123.456', channelName: 'private-chat.user.2' },
            rejected,
        );
        expect(rejected).toHaveBeenCalledWith(expect.any(Error), null);
        expect(chatRequest).toHaveBeenCalledTimes(2);
    });
    it.each([
        { ...config, actor_id: 2 },
        { ...config, channel: 'private-chat.user.2' },
        { ...config, channel: 'private-chat.user.1.extra' },
        { enabled: false, actor_id: 2 },
    ])(
        'never subscribes to a configuration outside the rendered account',
        async (wrong) => {
            vi.mocked(chatRequest).mockResolvedValue(wrong);
            stop = connectChatRealtime(1, vi.fn(), vi.fn());
            await flush();
            expect(fixtures.clients).toHaveLength(0);
        },
    );
    it('disconnects an existing socket when renewal detects a changed account', async () => {
        const refresh = vi.fn();
        stop = connectChatRealtime(1, vi.fn(), refresh);
        await flush();
        const socket = fixtures.clients[0];
        vi.mocked(chatRequest).mockRejectedValue(
            new ChatError('Account changed', 403, 'chat_actor_changed'),
        );
        await vi.advanceTimersByTimeAsync(60000);
        await flush();
        expect(socket.disconnect).toHaveBeenCalledOnce();
        await vi.advanceTimersByTimeAsync(150);
        const refreshCount = refresh.mock.calls.length;
        socket.channelEvents.get('chat.changed')?.();
        socket.channelEvents.get('pusher:subscription_succeeded')?.();
        await vi.advanceTimersByTimeAsync(120000);
        expect(refresh).toHaveBeenCalledTimes(refreshCount);
        expect(fixtures.clients).toHaveLength(1);
        expect(chatRequest).toHaveBeenCalledTimes(2);
    });
    it('ignores an authorisation response after its account connection was disposed', async () => {
        let finish!: (value: unknown) => void;
        vi.mocked(chatRequest).mockImplementation((path) =>
            path.endsWith('/auth')
                ? new Promise((resolve) => {
                      finish = resolve;
                  })
                : (Promise.resolve(config) as never),
        );
        stop = connectChatRealtime(1, vi.fn(), vi.fn());
        await flush();
        const authorise = fixtures.clients[0].options.channelAuthorization;
        if (!authorise || !('customHandler' in authorise))
            throw new Error('Missing authorisation');
        const callback = vi.fn();
        authorise.customHandler(
            { socketId: '123.456', channelName: config.channel },
            callback,
        );
        stop();
        finish({ auth: 'old-account-signature' });
        await flush();
        expect(callback).not.toHaveBeenCalled();
    });
});
