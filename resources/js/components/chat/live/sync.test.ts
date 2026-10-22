import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { LiveEvent } from './events';
import {
    ChatSync,
    HIDDEN_POLL_MS,
    VISIBLE_POLL_MS,
    type SyncEnvironment,
} from './sync';

type Timer = { at: number; callback: () => void; id: number };

/** A controllable clock, visibility and network, so the loop's single timer can be counted. */
function environment() {
    let now = 0;
    let nextId = 1;
    const timers = new Map<number, Timer>();
    const page = new EventTarget();
    const doc = new EventTarget();
    const state = { visible: true, online: true };
    const env: SyncEnvironment = {
        now: () => now,
        setTimeout: (callback, ms) => {
            const id = nextId++;
            timers.set(id, { at: now + ms, callback, id });
            return id;
        },
        clearTimeout: (id) => {
            timers.delete(id as number);
        },
        visible: () => state.visible,
        online: () => state.online,
        target: page,
        documentTarget: doc,
    };
    // Let pending promise callbacks (finished refreshes) run before looking at the clock.
    const flush = async () => {
        for (let index = 0; index < 10; index++) await Promise.resolve();
    };
    const advance = async (ms: number) => {
        const end = now + ms;
        for (;;) {
            await flush();
            const due = [...timers.values()]
                .filter((timer) => timer.at <= end)
                .sort((a, b) => a.at - b.at)[0];
            if (!due) break;
            timers.delete(due.id);
            now = Math.max(now, due.at);
            due.callback();
        }
        now = end;
        await flush();
    };
    return {
        env,
        advance,
        state,
        timers,
        page,
        setVisible(visible: boolean) {
            state.visible = visible;
            doc.dispatchEvent(new Event('visibilitychange'));
        },
    };
}

const changed = (
    conversation_id: number,
    change_id: number,
    kinds = ['message'],
): LiveEvent => ({
    type: 'conversation.changed',
    conversation_id,
    change_id,
    kinds,
});

describe('the one sync loop', () => {
    let fake: ReturnType<typeof environment>;
    let sync: ChatSync;
    beforeEach(() => {
        fake = environment();
        sync = new ChatSync(fake.env);
    });
    afterEach(() => vi.restoreAllMocks());

    it('serves every watcher from a single timer and polls every 10 s while live updates are down', async () => {
        const list = vi.fn().mockResolvedValue(true);
        const thread = vi.fn().mockResolvedValue(true);
        const calls = vi.fn().mockResolvedValue(true);
        sync.watch({
            refresh: list,
            topics: ['inbox.changed'],
            poll: 'fallback',
        });
        sync.watch({
            refresh: thread,
            topics: ['conversation.changed'],
            poll: 'fallback',
        });
        sync.watch({
            refresh: calls,
            topics: ['call.changed'],
            poll: 'fallback',
            whenHidden: true,
        });
        await fake.advance(0);
        expect([list, thread, calls].map((fn) => fn.mock.calls.length)).toEqual(
            [1, 1, 1],
        );
        // One pending timer, however many watchers there are.
        expect(fake.timers.size).toBe(1);
        await fake.advance(VISIBLE_POLL_MS);
        expect([list, thread, calls].map((fn) => fn.mock.calls.length)).toEqual(
            [2, 2, 2],
        );
        expect(list.mock.calls[1][0]).toEqual({ kind: 'poll', events: [] });
        expect(fake.timers.size).toBe(1);
    });

    it('stops polling while live and fetches only what an event names', async () => {
        const thread = vi.fn().mockResolvedValue(true);
        sync.watch({
            refresh: thread,
            topics: ['conversation.changed'],
            match: (event) =>
                event.type === 'conversation.changed' &&
                event.conversation_id === 5,
            poll: 'fallback',
        });
        await fake.advance(0);
        sync.setState('live');
        await fake.advance(5 * VISIBLE_POLL_MS);
        expect(thread).toHaveBeenCalledTimes(1);
        expect(fake.timers.size).toBe(0);

        sync.receive(changed(6, 40));
        sync.receive({ type: 'inbox.changed', reason: 'added' });
        await fake.advance(1000);
        expect(thread).toHaveBeenCalledTimes(1);

        sync.receive(changed(5, 41));
        await fake.advance(149);
        expect(thread).toHaveBeenCalledTimes(1);
        await fake.advance(1);
        expect(thread).toHaveBeenCalledTimes(2);
        expect(thread.mock.calls[1][0]).toEqual({
            kind: 'event',
            events: [changed(5, 41)],
        });
    });

    it('ignores duplicate and out-of-order message changes the cursor has passed, but never other kinds', async () => {
        let position: number | null = 10;
        const thread = vi.fn().mockResolvedValue(true);
        sync.setState('live');
        sync.watch({
            refresh: thread,
            topics: ['conversation.changed'],
            cursor: { conversationId: 5, position: () => position },
        });
        await fake.advance(0);
        expect(thread).toHaveBeenCalledTimes(1);

        sync.receive(changed(5, 9));
        sync.receive(changed(5, 10, ['reaction']));
        await fake.advance(500);
        expect(thread).toHaveBeenCalledTimes(1);

        sync.receive(changed(5, 11));
        await fake.advance(500);
        expect(thread).toHaveBeenCalledTimes(2);
        position = 11;
        // The same change told twice.
        sync.receive(changed(5, 12));
        sync.receive(changed(5, 12));
        await fake.advance(500);
        expect(thread).toHaveBeenCalledTimes(3);
        expect(thread.mock.calls[2][0].events).toEqual([changed(5, 12)]);
        position = 12;
        sync.receive(changed(5, 12));
        await fake.advance(500);
        expect(thread).toHaveBeenCalledTimes(3);

        // People or call changes share the newest change ID and are always fetched.
        sync.receive(changed(5, 12, ['people']));
        await fake.advance(500);
        sync.receive(changed(5, 12, ['people']));
        await fake.advance(500);
        expect(thread).toHaveBeenCalledTimes(5);
    });

    it('coalesces a burst of events into one fetch carrying all of them', async () => {
        const list = vi.fn().mockResolvedValue(true);
        sync.setState('live');
        sync.watch({
            refresh: list,
            topics: ['conversation.changed'],
            immediate: false,
        });
        for (let id = 1; id <= 3; id++) sync.receive(changed(id, id));
        await fake.advance(200);
        expect(list).toHaveBeenCalledTimes(1);
        expect(list.mock.calls[0][0].events).toHaveLength(3);
    });

    it('keeps one fetch in flight and one trailing run for events that arrive meanwhile', async () => {
        let finish!: (value: boolean) => void;
        const list = vi
            .fn()
            .mockImplementationOnce(
                () => new Promise<boolean>((resolve) => (finish = resolve)),
            )
            .mockResolvedValue(true);
        sync.setState('live');
        sync.watch({ refresh: list, topics: ['conversation.changed'] });
        for (let id = 1; id <= 5; id++) sync.receive(changed(1, id));
        await fake.advance(2000);
        expect(list).toHaveBeenCalledTimes(1);
        finish(true);
        await fake.advance(300);
        expect(list).toHaveBeenCalledTimes(2);
        expect(list.mock.calls[1][0].events).toHaveLength(5);
    });

    it('polls hidden tabs every 60 s only for watchers that run hidden, and catches the rest up on return', async () => {
        const list = vi.fn().mockResolvedValue(true);
        const sounds = vi.fn().mockResolvedValue(true);
        sync.watch({ refresh: list, topics: ['conversation.changed'] });
        sync.watch({ refresh: sounds, topics: ['notice'], whenHidden: true });
        await fake.advance(0);
        fake.setVisible(false);
        await fake.advance(HIDDEN_POLL_MS);
        expect(list).toHaveBeenCalledTimes(1);
        expect(sounds).toHaveBeenCalledTimes(2);
        await fake.advance(HIDDEN_POLL_MS);
        expect(sounds).toHaveBeenCalledTimes(3);
        fake.setVisible(true);
        await fake.advance(300);
        expect(list).toHaveBeenCalledTimes(2);
    });

    it('marks events for hidden tabs and fetches them when the tab is looked at again', async () => {
        const thread = vi.fn().mockResolvedValue(true);
        sync.setState('live');
        sync.watch({ refresh: thread, topics: ['conversation.changed'] });
        await fake.advance(0);
        fake.setVisible(false);
        sync.receive(changed(1, 2));
        await fake.advance(5000);
        expect(thread).toHaveBeenCalledTimes(1);
        fake.setVisible(true);
        await fake.advance(300);
        expect(thread).toHaveBeenCalledTimes(2);
        expect(thread.mock.calls[1][0].events).toEqual([changed(1, 2)]);
    });

    it('catches every watcher up once after a reconnect', async () => {
        const list = vi.fn().mockResolvedValue(true);
        const thread = vi.fn().mockResolvedValue(true);
        sync.watch({ refresh: list, topics: ['inbox.changed'] });
        sync.watch({ refresh: thread, topics: ['conversation.changed'] });
        await fake.advance(0);
        sync.setState('live');
        sync.resync();
        await fake.advance(0);
        expect(list.mock.calls.at(-1)![0]).toEqual({
            kind: 'resync',
            events: [],
        });
        expect(thread.mock.calls.at(-1)![0]).toEqual({
            kind: 'resync',
            events: [],
        });
    });

    it('takes over with polling within 15 s when live updates drop', async () => {
        const list = vi.fn().mockResolvedValue(true);
        sync.setState('live');
        sync.watch({ refresh: list, topics: ['conversation.changed'] });
        await fake.advance(60_000);
        expect(list).toHaveBeenCalledTimes(1);
        sync.setState('recovering');
        await fake.advance(15_000);
        expect(list).toHaveBeenCalledTimes(2);
    });

    it('backs off failing polls up to a minute and resets after success', async () => {
        const list = vi.fn().mockResolvedValue(false);
        sync.watch({ refresh: list, topics: [] });
        await fake.advance(0);
        await fake.advance(20_000);
        expect(list).toHaveBeenCalledTimes(2);
        await fake.advance(40_000);
        expect(list).toHaveBeenCalledTimes(3);
        list.mockResolvedValue(true);
        await fake.advance(60_000);
        expect(list).toHaveBeenCalledTimes(4);
        await fake.advance(10_000);
        expect(list).toHaveBeenCalledTimes(5);
    });

    it('keeps a watcher own check running while live, and wakes on local window events', async () => {
        const scan = vi.fn().mockResolvedValue(true);
        sync.setState('live');
        sync.watch({
            refresh: scan,
            poll: () => 5000,
            wakeOn: ['oblivion:chat-refresh'],
        });
        await fake.advance(10_000);
        expect(scan).toHaveBeenCalledTimes(3);
        fake.page.dispatchEvent(new Event('oblivion:chat-refresh'));
        await fake.advance(250);
        expect(scan).toHaveBeenCalledTimes(4);
    });

    it('hands raw events to subscribers and stops after removal', async () => {
        const typing = vi.fn();
        const stop = sync.subscribe(['typing'], typing);
        const event: LiveEvent = {
            type: 'typing',
            conversation_id: 3,
            who: 'Hana Wiremu',
            state: 'start',
        };
        sync.receive(event);
        expect(typing).toHaveBeenCalledWith(event);
        stop();
        sync.receive(event);
        expect(typing).toHaveBeenCalledTimes(1);

        const list = vi.fn().mockResolvedValue(true);
        const remove = sync.watch({ refresh: list, topics: ['inbox.changed'] });
        await fake.advance(0);
        remove();
        sync.receive({ type: 'inbox.changed', reason: 'added' });
        await fake.advance(VISIBLE_POLL_MS * 3);
        expect(list).toHaveBeenCalledTimes(1);
        expect(fake.timers.size).toBe(0);
    });
});
