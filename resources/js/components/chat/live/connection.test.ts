import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatError } from '../api';
import {
    backoffDelay,
    connectLive,
    LiveClose,
    socketUrl,
    type LiveConnection,
    type LiveTicket,
} from './connection';
import { parseLiveEvent } from './events';

/** A stand-in for the browser's WebSocket that the test drives by hand. */
class FakeSocket {
    static OPEN = 1;
    readyState = 0;
    sent: string[] = [];
    closedWith: number | null = null;
    onopen: (() => void) | null = null;
    onmessage: ((event: { data: string }) => void) | null = null;
    onclose: ((event: { code: number }) => void) | null = null;
    onerror: (() => void) | null = null;
    constructor(public url: string) {}
    send(data: string) {
        this.sent.push(data);
    }
    close(code = 1000) {
        this.closedWith = code;
        this.readyState = 3;
    }
    open() {
        this.readyState = 1;
        this.onopen?.();
    }
    receive(message: unknown) {
        this.onmessage?.({ data: JSON.stringify(message) });
    }
    drop(code: number) {
        this.readyState = 3;
        this.onclose?.({ code });
    }
}

describe('Oblivion Live connection', () => {
    let sockets: FakeSocket[];
    let tickets: number;
    let fetchTicket: ReturnType<
        typeof vi.fn<(signal: AbortSignal) => Promise<LiveTicket>>
    >;
    let states: string[];
    let events: unknown[];
    let ready: boolean[];
    let connection: LiveConnection | undefined;
    let online: ReturnType<typeof vi.spyOn>;
    let visibility: ReturnType<typeof vi.spyOn>;

    const start = (random = () => 1) => {
        connection = connectLive({
            actorId: 7,
            onState: (state) => states.push(state),
            onEvent: (event) => events.push(event),
            onReady: (resume) => ready.push(resume),
            fetchTicket,
            createSocket: (url) => {
                const socket = new FakeSocket(url);
                sockets.push(socket);
                return socket as unknown as WebSocket;
            },
            random,
        });
    };
    const settle = () => vi.advanceTimersByTimeAsync(0);
    const last = () => sockets.at(-1)!;
    const openLatest = async (resume = true) => {
        await settle();
        last().open();
        last().receive({ type: 'ready', resume });
    };

    beforeEach(() => {
        vi.useFakeTimers();
        vi.stubGlobal('WebSocket', FakeSocket);
        sockets = [];
        tickets = 0;
        states = [];
        events = [];
        ready = [];
        fetchTicket = vi.fn(async () => ({
            url: '/chat-live',
            ticket: `ticket-${++tickets}`,
            expires_in: 60,
        }));
        online = vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(true);
        visibility = vi
            .spyOn(document, 'visibilityState', 'get')
            .mockReturnValue('visible');
    });
    afterEach(() => {
        connection?.stop();
        connection = undefined;
        vi.unstubAllGlobals();
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('opens the same-origin socket and sends the ticket in hello, never in the address', async () => {
        start();
        await openLatest();
        expect(last().url).toBe(`ws://${window.location.host}/chat-live`);
        expect(last().url).not.toContain('ticket');
        expect(JSON.parse(last().sent[0])).toEqual({
            type: 'hello',
            ticket: 'ticket-1',
        });
        expect(states).toEqual(['connecting', 'live']);
        expect(ready).toEqual([true]);
    });

    it('delivers well-formed events only after ready, and answers application pings', async () => {
        start();
        await settle();
        last().open();
        last().receive({ type: 'unread.changed', total: 2 });
        last().receive({ type: 'ready', resume: true });
        last().receive({ type: 'unread.changed', total: 3 });
        last().receive({
            type: 'conversation.changed',
            conversation_id: 4,
            change_id: 'x',
            kinds: [],
        });
        last().receive({ type: 'chat.changed', refresh: true });
        last().receive({ type: 'ping' });
        expect(events).toEqual([{ type: 'unread.changed', total: 3 }]);
        expect(last().sent.at(-1)).toBe('{"type":"pong"}');
    });

    it('renews with a fresh ticket on the same connection', async () => {
        start();
        await openLatest();
        last().receive({ type: 'renew' });
        await settle();
        expect(JSON.parse(last().sent.at(-1)!)).toEqual({
            type: 'hello',
            ticket: 'ticket-2',
        });
        last().receive({ type: 'ready', resume: false });
        expect(ready).toEqual([true, false]);
        expect(sockets).toHaveLength(1);
    });

    it('reconnects with backoff of 1, 2, 4 … seconds capped at 30, then resets after a minute connected', async () => {
        start();
        await openLatest();
        const waits: number[] = [];
        for (let attempt = 0; attempt < 7; attempt++) {
            const before = sockets.length;
            last().drop(1006);
            let waited = 0;
            while (sockets.length === before) {
                await vi.advanceTimersByTimeAsync(100);
                waited += 100;
            }
            waits.push(waited);
            last().open();
        }
        expect(waits).toEqual([1000, 2000, 4000, 8000, 16000, 30000, 30000]);
        expect(states).toContain('recovering');
        // A minute of being connected resets the backoff.
        last().receive({ type: 'ready', resume: true });
        await vi.advanceTimersByTimeAsync(61_000);
        last().receive({ type: 'ping' });
        last().drop(1006);
        await vi.advanceTimersByTimeAsync(1000);
        expect(sockets).toHaveLength(9);
    });

    it('jitters each wait between half and all of its step', () => {
        expect(backoffDelay(0, () => 0)).toBe(500);
        expect(backoffDelay(0, () => 1)).toBe(1000);
        expect(backoffDelay(3, () => 0.5)).toBe(6000);
        expect(backoffDelay(10, () => 1)).toBe(30000);
    });

    it('fetches a new ticket and reconnects after 4401', async () => {
        start();
        await openLatest();
        last().drop(LiveClose.TICKET);
        await vi.advanceTimersByTimeAsync(1000);
        expect(sockets).toHaveLength(2);
        expect(fetchTicket).toHaveBeenCalledTimes(2);
    });

    it('stops for good on 4403, even when the network or tab comes back', async () => {
        start();
        await openLatest();
        last().drop(LiveClose.REVOKED);
        expect(states.at(-1)).toBe('off');
        window.dispatchEvent(new Event('online'));
        document.dispatchEvent(new Event('visibilitychange'));
        await vi.advanceTimersByTimeAsync(120_000);
        expect(sockets).toHaveLength(1);
        expect(fetchTicket).toHaveBeenCalledTimes(1);
    });

    it('stops when the app refuses a ticket (no access, or live updates switched off)', async () => {
        for (const status of [403, 404]) {
            fetchTicket.mockRejectedValueOnce(new ChatError('No', status));
            start();
            await settle();
            await vi.advanceTimersByTimeAsync(60_000);
            expect(states.at(-1)).toBe('off');
            expect(sockets).toHaveLength(0);
            connection?.stop();
        }
    });

    it('retries a failed ticket request with backoff', async () => {
        fetchTicket
            .mockRejectedValueOnce(new Error('network'))
            .mockRejectedValueOnce(new ChatError('Busy', 503));
        start();
        await settle();
        expect(states).toEqual(['connecting', 'recovering']);
        await vi.advanceTimersByTimeAsync(1000);
        await vi.advanceTimersByTimeAsync(2000);
        expect(sockets).toHaveLength(1);
    });

    it('pauses while offline and reconnects at once when the network returns', async () => {
        start();
        await openLatest();
        online.mockReturnValue(false);
        window.dispatchEvent(new Event('offline'));
        expect(states.at(-1)).toBe('offline');
        expect(sockets[0].closedWith).toBe(1000);
        await vi.advanceTimersByTimeAsync(60_000);
        expect(sockets).toHaveLength(1);
        online.mockReturnValue(true);
        window.dispatchEvent(new Event('online'));
        await settle();
        expect(sockets).toHaveLength(2);
    });

    it('waits for the tab to be looked at after 4409 instead of evicting another tab', async () => {
        start();
        await openLatest();
        last().drop(LiveClose.TOO_MANY);
        await vi.advanceTimersByTimeAsync(120_000);
        expect(sockets).toHaveLength(1);
        visibility.mockReturnValue('visible');
        document.dispatchEvent(new Event('visibilitychange'));
        await settle();
        expect(sockets).toHaveLength(2);
    });

    it('treats a silent connection as dropped after three missed pings', async () => {
        start();
        await openLatest();
        await vi.advanceTimersByTimeAsync(74_000);
        expect(sockets).toHaveLength(1);
        await vi.advanceTimersByTimeAsync(1_000);
        expect(sockets[0].closedWith).toBe(1000);
        await vi.advanceTimersByTimeAsync(1_000);
        expect(sockets).toHaveLength(2);
    });

    it('sends call signals only while live, and closes cleanly when stopped', async () => {
        start();
        await settle();
        last().open();
        expect(connection!.send({ type: 'call.signal' })).toBe(false);
        last().receive({ type: 'ready', resume: true });
        last().readyState = FakeSocket.OPEN;
        expect(
            connection!.send({
                type: 'call.signal',
                call_id: 'c',
                to: 2,
                payload: {},
            }),
        ).toBe(true);
        connection!.stop();
        expect(sockets[0].closedWith).toBe(1000);
        await vi.advanceTimersByTimeAsync(60_000);
        expect(sockets).toHaveLength(1);
    });

    it('accepts only a same-origin path for the socket', () => {
        expect(
            socketUrl('/chat-live', {
                protocol: 'https:',
                host: 'care.example',
            } as Location),
        ).toBe('wss://care.example/chat-live');
        for (const path of [
            '//evil.example/chat-live',
            'https://evil.example',
            'chat-live',
            '/chat-live?x=1',
            '/chat live',
        ]) {
            expect(() => socketUrl(path)).toThrow();
        }
    });
});

describe('live event shapes', () => {
    it('accepts the spec shapes and rejects anything else', () => {
        expect(
            parseLiveEvent({
                type: 'conversation.changed',
                conversation_id: 3,
                change_id: 0,
                kinds: ['message'],
            }),
        ).not.toBeNull();
        expect(
            parseLiveEvent({
                type: 'typing',
                conversation_id: 3,
                who: 'Care team',
                state: 'start',
            }),
        ).not.toBeNull();
        expect(
            parseLiveEvent({
                type: 'presence',
                user_id: 3,
                state: 'available',
            }),
        ).not.toBeNull();
        expect(
            parseLiveEvent({
                type: 'call.signal',
                call_id: 'c-1',
                from: 4,
                payload: { sdp: 'v=0' },
            }),
        ).not.toBeNull();
        for (const bad of [
            null,
            [],
            {
                type: 'conversation.changed',
                conversation_id: 0,
                change_id: 1,
                kinds: [],
            },
            {
                type: 'conversation.changed',
                conversation_id: 3,
                change_id: -1,
                kinds: [],
            },
            { type: 'unread.changed', total: '4' },
            { type: 'typing', conversation_id: 3, who: '', state: 'start' },
            { type: 'presence', user_id: 3, state: 'busy' },
            { type: 'call.signal', call_id: 'c', from: 4, payload: [] },
            { type: 'ready' },
            { type: 'chat.changed', refresh: true },
        ]) {
            expect(parseLiveEvent(bad)).toBeNull();
        }
    });
});
