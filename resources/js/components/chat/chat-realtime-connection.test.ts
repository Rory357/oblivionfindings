import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { connectChatRealtime } from './chat-realtime-connection';
import { connectLive, type LiveConnectionOptions } from './live/connection';
import { chatSync } from './live/sync';

vi.mock('./live/connection', () => ({ connectLive: vi.fn() }));

describe('Chat live updates start Oblivion Live', () => {
    let options: LiveConnectionOptions;
    const stopLive = vi.fn();
    beforeEach(() => {
        vi.mocked(connectLive).mockImplementation((given) => {
            options = given;
            return { stop: stopLive, send: () => false };
        });
    });
    afterEach(() => vi.restoreAllMocks());

    it('connects for the rendered account and hands events to the one sync loop', () => {
        const receive = vi.spyOn(chatSync, 'receive').mockImplementation(() => undefined);
        const resync = vi.spyOn(chatSync, 'resync').mockImplementation(() => undefined);
        const stop = connectChatRealtime(7, vi.fn(), vi.fn());
        expect(options.actorId).toBe(7);
        const event = { type: 'unread.changed' as const, total: 2 };
        options.onEvent(event);
        expect(receive).toHaveBeenCalledWith(event);
        options.onReady?.(true);
        options.onReady?.(false);
        expect(resync).toHaveBeenCalledOnce();
        stop();
        expect(stopLive).toHaveBeenCalledOnce();
    });

    it('reports state, and asks views to re-check only when a working connection is turned off', () => {
        const states: string[] = [];
        const refresh = vi.fn();
        connectChatRealtime(7, (state) => states.push(state), refresh);
        // Switched off from the start (ticket 404): nothing to re-check.
        options.onState('off');
        expect(refresh).not.toHaveBeenCalled();
        options.onState('connecting');
        options.onState('live');
        // Revoked (4403) after being live.
        options.onState('off');
        expect(refresh).toHaveBeenCalledOnce();
        expect(states).toEqual(['off', 'connecting', 'live', 'off']);
    });
});
