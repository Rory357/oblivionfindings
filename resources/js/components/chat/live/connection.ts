// The Oblivion Live browser connection (spec sections 3 and 8): fetch a ticket, open the
// same-origin socket, say hello, renew, keep alive, and reconnect with jittered backoff.
// Uses the browser's own WebSocket; no packages.

import { ChatError, chatRequest } from '../api';
import { parseLiveEvent, type LiveEvent } from './events';

export type LiveConnectionState =
    | 'connecting'
    | 'live'
    | 'recovering'
    | 'offline'
    | 'off';

export type LiveTicket = { url: string; ticket: string; expires_in: number };

export const LiveClose = {
    TICKET: 4401,
    REVOKED: 4403,
    TOO_MANY: 4409,
} as const;

export type LiveConnectionOptions = {
    actorId: number;
    onState: (state: LiveConnectionState) => void;
    onEvent: (event: LiveEvent) => void;
    /** After hello: resume = true means the browser should catch up through the app. */
    onReady?: (resume: boolean) => void;
    fetchTicket?: (signal: AbortSignal) => Promise<LiveTicket>;
    createSocket?: (url: string) => WebSocket;
    random?: () => number;
};

export type LiveConnection = {
    /** Send a browser-to-gateway message (call signalling) while live. */
    send: (message: Record<string, unknown>) => boolean;
    stop: () => void;
};

// The gateway pings every 25 s; three silent intervals mean the connection is gone.
const WATCHDOG_MS = 75_000;
// Backoff starts again only after a connection has stayed up this long.
const STABLE_MS = 60_000;

/** 1 s, 2 s, 4 s … capped at 30 s, each between half and all of that (spec section 8). */
export function backoffDelay(
    attempt: number,
    random: () => number = Math.random,
) {
    const base = Math.min(30_000, 1000 * 2 ** Math.min(attempt, 5));
    return Math.round(base * (0.5 + 0.5 * random()));
}

/** The socket address for the ticket's same-origin path. Anything else is refused. */
export function socketUrl(path: string, location: Location = window.location) {
    if (!/^\/[A-Za-z0-9/_-]*$/.test(path) || path.startsWith('//')) {
        throw new Error('Invalid live-update address');
    }
    return `${location.protocol === 'https:' ? 'wss' : 'ws'}://${location.host}${path}`;
}

export function connectLive(options: LiveConnectionOptions): LiveConnection {
    const random = options.random ?? Math.random;
    const controller = new AbortController();
    let stopped = false;
    // Revoked, not allowed, or switched off: stop until the page reloads.
    let blocked = false;
    // Closed for having too many connections: wait until this tab is looked at again.
    let parked = false;
    let starting = false;
    let socket: WebSocket | null = null;
    let live = false;
    let everReady = false;
    let attempts = 0;
    let connectedAt = 0;
    let state: LiveConnectionState | null = null;
    let retry: ReturnType<typeof setTimeout> | undefined;
    let watchdog: ReturnType<typeof setTimeout> | undefined;

    const setState = (next: LiveConnectionState) => {
        if (stopped || state === next) return;
        state = next;
        options.onState(next);
    };
    const ticket = (signal: AbortSignal) =>
        options.fetchTicket?.(signal) ??
        chatRequest<LiveTicket>('/chat/api/live/ticket', {
            method: 'POST',
            body: '{}',
            headers: { 'X-Chat-Actor': String(options.actorId) },
            signal: AbortSignal.any([signal, AbortSignal.timeout(10_000)]),
        });
    const refused = (error: unknown) =>
        error instanceof ChatError &&
        [401, 403, 404, 419].includes(error.status);

    const detach = () => {
        clearTimeout(watchdog);
        const previous = socket;
        socket = null;
        live = false;
        if (previous) {
            previous.onopen =
                previous.onmessage =
                previous.onclose =
                previous.onerror =
                    null;
            try {
                previous.close(1000);
            } catch {
                /* already closed */
            }
        }
    };
    const schedule = () => {
        if (stopped || blocked || parked || retry) return;
        const delay = backoffDelay(attempts++, random);
        retry = setTimeout(() => {
            retry = undefined;
            void start();
        }, delay);
    };
    const armWatchdog = (current: WebSocket) => {
        clearTimeout(watchdog);
        watchdog = setTimeout(() => {
            if (socket !== current) return;
            // No ping or event for too long: treat it as dropped and reconnect.
            closed(1006);
        }, WATCHDOG_MS);
    };
    const closed = (code: number) => {
        const stayedUp =
            connectedAt > 0 && Date.now() - connectedAt >= STABLE_MS;
        connectedAt = 0;
        detach();
        if (stayedUp) attempts = 0;
        if (stopped) return;
        if (code === LiveClose.REVOKED) {
            blocked = true;
            setState('off');
            return;
        }
        if (!navigator.onLine) {
            setState('offline');
            return;
        }
        setState('recovering');
        if (code === LiveClose.TOO_MANY) {
            parked = true;
            return;
        }
        schedule();
    };
    const renew = async (current: WebSocket) => {
        try {
            const issued = await ticket(controller.signal);
            if (socket === current && current.readyState === WebSocket.OPEN)
                current.send(
                    JSON.stringify({ type: 'hello', ticket: issued.ticket }),
                );
        } catch (error) {
            if (stopped || socket !== current) return;
            if (refused(error)) {
                blocked = true;
                detach();
                setState('off');
            }
            // Otherwise the gateway closes with 4401 when the session ends, and we reconnect.
        }
    };
    const handle = (current: WebSocket, data: unknown) => {
        let message: unknown;
        try {
            message = JSON.parse(String(data));
        } catch {
            return;
        }
        if (!message || typeof message !== 'object') return;
        const type = (message as { type?: unknown }).type;
        if (type === 'ready') {
            live = true;
            connectedAt ||= Date.now();
            const resume = (message as { resume?: unknown }).resume === true;
            everReady = true;
            setState('live');
            options.onReady?.(resume);
            return;
        }
        if (type === 'renew') {
            void renew(current);
            return;
        }
        if (type === 'ping') {
            current.send('{"type":"pong"}');
            return;
        }
        const event = parseLiveEvent(message);
        if (event && live) options.onEvent(event);
    };
    const open = (issued: LiveTicket) => {
        const current = (options.createSocket ?? ((url) => new WebSocket(url)))(
            socketUrl(issued.url),
        );
        socket = current;
        current.onopen = () => {
            if (socket === current)
                current.send(
                    JSON.stringify({ type: 'hello', ticket: issued.ticket }),
                );
        };
        current.onmessage = (message) => {
            if (socket !== current) return;
            armWatchdog(current);
            handle(current, message.data);
        };
        current.onclose = (event) => {
            if (socket === current) closed(event.code);
        };
        current.onerror = () => {
            /* a close event always follows */
        };
        armWatchdog(current);
    };
    const start = async () => {
        if (stopped || blocked || parked || starting || socket) return;
        if (!navigator.onLine) {
            setState('offline');
            return;
        }
        starting = true;
        setState(everReady ? 'recovering' : 'connecting');
        try {
            const issued = await ticket(controller.signal);
            if (stopped || socket) return;
            open(issued);
        } catch (error) {
            if (stopped) return;
            if (refused(error)) {
                blocked = true;
                setState('off');
                return;
            }
            setState(navigator.onLine ? 'recovering' : 'offline');
            schedule();
        } finally {
            starting = false;
        }
    };
    const now = () => {
        clearTimeout(retry);
        retry = undefined;
        void start();
    };
    const online = () => now();
    const offline = () => {
        clearTimeout(retry);
        retry = undefined;
        detach();
        setState('offline');
    };
    const visible = () => {
        if (document.visibilityState !== 'visible' || socket || blocked) return;
        parked = false;
        now();
    };
    window.addEventListener('online', online);
    window.addEventListener('offline', offline);
    document.addEventListener('visibilitychange', visible);
    void start();

    return {
        send(message) {
            if (!socket || !live || socket.readyState !== WebSocket.OPEN)
                return false;
            socket.send(JSON.stringify(message));
            return true;
        },
        stop() {
            if (stopped) return;
            stopped = true;
            controller.abort();
            clearTimeout(retry);
            detach();
            window.removeEventListener('online', online);
            window.removeEventListener('offline', offline);
            document.removeEventListener('visibilitychange', visible);
        },
    };
}
