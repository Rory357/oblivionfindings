import type Pusher from 'pusher-js';
import { ChatError, chatRequest } from './api';

export type ChatLiveState =
    | 'off'
    | 'connecting'
    | 'live'
    | 'recovering'
    | 'offline';
type Configuration = {
    actor_id: number;
    enabled: boolean;
    key?: string;
    path?: string;
    channel?: string;
};

export function connectChatRealtime(
    actorId: number,
    onState: (state: ChatLiveState) => void,
    onRefresh: () => void,
) {
    let stopped = false;
    let starting = false;
    let blocked = false;
    let attempts = 0;
    let client: Pusher | null = null;
    let configured = '';
    let retry: ReturnType<typeof setTimeout> | undefined;
    let notice: ReturnType<typeof setTimeout> | undefined;
    const controller = new AbortController();
    const request = async <T>(path: string, options: RequestInit = {}) => {
        const pending = new AbortController();
        const abort = () => pending.abort();
        controller.signal.addEventListener('abort', abort, { once: true });
        if (controller.signal.aborted) abort();
        const deadline = setTimeout(abort, 10000);
        const headers = new Headers(options.headers);
        headers.set('X-Chat-Actor', String(actorId));
        try {
            return await chatRequest<T>(path, {
                ...options,
                headers,
                signal: pending.signal,
            });
        } finally {
            clearTimeout(deadline);
            controller.signal.removeEventListener('abort', abort);
        }
    };
    const refresh = () => {
        if (notice) return;
        notice = setTimeout(() => {
            notice = undefined;
            if (!stopped) onRefresh();
        }, 150);
    };
    const disconnect = () => {
        const previous = client;
        client = null;
        configured = '';
        previous?.disconnect();
    };
    const recover = () => {
        disconnect();
        if (stopped) return;
        onState(navigator.onLine ? 'recovering' : 'offline');
        if (blocked || retry || !navigator.onLine) return;
        const delay = Math.min(60000, 1000 * 2 ** Math.min(attempts++, 6));
        retry = setTimeout(() => {
            retry = undefined;
            void start();
        }, delay);
    };
    const start = async () => {
        if (stopped || starting || retry) return;
        if (!navigator.onLine) {
            onState('offline');
            return;
        }
        starting = true;
        if (!client) onState('connecting');
        try {
            const config = await request<Configuration>('/chat/api/realtime');
            if (stopped) return;
            if (config.actor_id !== actorId)
                throw new ChatError(
                    'Your signed-in account has changed. Refresh this page before continuing.',
                    403,
                    'chat_actor_changed',
                );
            if (typeof config.enabled !== 'boolean')
                throw new Error('Invalid live-update configuration');
            if (!config.enabled) {
                disconnect();
                onState('off');
                return;
            }
            if (
                !config.key ||
                config.path !== '/chat-live' ||
                config.channel !== `private-chat.user.${actorId}`
            )
                throw new Error('Invalid live-update configuration');
            const identity = JSON.stringify(config);
            if (client && identity === configured) return;
            disconnect();
            const { default: SocketClient } = await import('pusher-js');
            if (stopped) return;
            const socket: Pusher = new SocketClient(config.key, {
                cluster: 'self-hosted',
                wsHost: window.location.hostname,
                wsPath: config.path,
                wsPort: Number(window.location.port) || 80,
                wssPort: Number(window.location.port) || 443,
                forceTLS: window.location.protocol === 'https:',
                enabledTransports: ['ws', 'wss'],
                enableStats: false,
                disableStats: true,
                channelAuthorization: {
                    customHandler: (params, callback) => {
                        if (params.channelName !== config.channel) {
                            callback(new Error('Invalid Chat channel'), null);
                            return;
                        }
                        void request<{ auth: string }>(
                            '/chat/api/realtime/auth',
                            {
                                method: 'POST',
                                body: JSON.stringify({
                                    socket_id: params.socketId,
                                    channel_name: params.channelName,
                                }),
                            },
                        )
                            .then((auth) => {
                                if (!stopped && client === socket)
                                    callback(null, auth);
                            })
                            .catch((error: unknown) => {
                                if (stopped || client !== socket) return;
                                blocked =
                                    error instanceof ChatError &&
                                    [401, 403, 419].includes(error.status);
                                callback(
                                    error instanceof Error
                                        ? error
                                        : new Error('Live updates unavailable'),
                                    null,
                                );
                                recover();
                                if (blocked) refresh();
                            });
                    },
                },
            });
            client = socket;
            configured = identity;
            socket.connection.bind(
                'state_change',
                ({ current }: { current: string }) => {
                    if (stopped || client !== socket) return;
                    if (
                        ['unavailable', 'failed', 'disconnected'].includes(
                            current,
                        )
                    )
                        recover();
                    else if (current === 'connecting') onState('connecting');
                },
            );
            const channel = socket.subscribe(config.channel);
            channel.bind('pusher:subscription_succeeded', () => {
                if (stopped || client !== socket) return;
                attempts = 0;
                blocked = false;
                onState('live');
                refresh();
            });
            channel.bind('pusher:subscription_error', () => {
                if (client === socket) recover();
            });
            channel.bind('chat.changed', () => {
                if (!stopped && client === socket) refresh();
            });
        } catch (error) {
            if (stopped) return;
            blocked =
                error instanceof ChatError &&
                [401, 403, 419].includes(error.status);
            recover();
            if (blocked) refresh();
        } finally {
            starting = false;
        }
    };
    const wake = () => {
        if (document.visibilityState !== 'visible') return;
        clearTimeout(retry);
        retry = undefined;
        blocked = false;
        void start();
        refresh();
    };
    const offline = () => {
        clearTimeout(retry);
        retry = undefined;
        disconnect();
        onState('offline');
    };
    window.addEventListener('online', wake);
    window.addEventListener('offline', offline);
    document.addEventListener('visibilitychange', wake);
    const lease = setInterval(() => {
        if (!blocked) void start();
    }, 60000);
    void start();
    return () => {
        stopped = true;
        controller.abort();
        clearTimeout(retry);
        clearTimeout(notice);
        clearInterval(lease);
        disconnect();
        window.removeEventListener('online', wake);
        window.removeEventListener('offline', offline);
        document.removeEventListener('visibilitychange', wake);
    };
}
