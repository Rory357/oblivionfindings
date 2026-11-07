import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { watchChatUpdates } from './chat-update-watch';
import { ChatSync } from './live/sync';

describe('shared Chat update scheduling', () => {
    let stop: (() => void) | undefined;
    let sync: ChatSync;
    beforeEach(() => {
        vi.useFakeTimers();
        sync = new ChatSync();
        vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(true);
        vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    });
    afterEach(() => {
        stop?.();
        stop = undefined;
        vi.useRealTimers();
        vi.restoreAllMocks();
    });
    it('backs off failed reads and resets the delay after successful recovery', async () => {
        const read = vi.fn().mockResolvedValue(false);
        stop = watchChatUpdates(read, { interval: () => 1000 });
        await vi.advanceTimersByTimeAsync(0);
        expect(read).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1999);
        expect(read).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(1);
        expect(read).toHaveBeenCalledTimes(2);
        read.mockResolvedValue(true);
        await vi.advanceTimersByTimeAsync(4000);
        expect(read).toHaveBeenCalledTimes(3);
        await vi.advanceTimersByTimeAsync(1000);
        expect(read).toHaveBeenCalledTimes(4);
    });
    it('coalesces bursts without starving updates during a continuous message stream', async () => {
        const read = vi.fn().mockResolvedValue(true);
        stop = watchChatUpdates(read, { interval: () => 30000 });
        await vi.advanceTimersByTimeAsync(0);
        for (let i = 0; i < 20; i++) {
            window.dispatchEvent(new Event('oblivion:chat-refresh'));
            await vi.advanceTimersByTimeAsync(50);
        }
        expect(read).toHaveBeenCalledTimes(5);
    });
    it('allows one in-flight request and one trailing wake-up, and stops after disposal', async () => {
        let complete!: (value: boolean) => void;
        const read = vi
            .fn()
            .mockImplementationOnce(
                () =>
                    new Promise<boolean>((resolve) => {
                        complete = resolve;
                    }),
            )
            .mockResolvedValue(true);
        stop = watchChatUpdates(read, { interval: () => 1000 });
        for (let i = 0; i < 20; i++)
            window.dispatchEvent(new Event('oblivion:chat-refresh'));
        await vi.advanceTimersByTimeAsync(2000);
        expect(read).toHaveBeenCalledTimes(1);
        complete(true);
        await vi.advanceTimersByTimeAsync(250);
        expect(read).toHaveBeenCalledTimes(2);
        stop();
        await vi.advanceTimersByTimeAsync(60000);
        expect(read).toHaveBeenCalledTimes(2);
    });
    it('runs every watcher on the one sync loop, with one timer between them', async () => {
        const reads = [1, 2, 3, 4].map(() => vi.fn().mockResolvedValue(true));
        const stops = reads.map((read, index) =>
            watchChatUpdates(read, { interval: () => 1000 * (index + 1) }),
        );
        await vi.advanceTimersByTimeAsync(0);
        expect(vi.getTimerCount()).toBe(1);
        await vi.advanceTimersByTimeAsync(4000);
        expect(reads.map((read) => read.mock.calls.length)).toEqual([
            5, 3, 2, 2,
        ]);
        expect(vi.getTimerCount()).toBe(1);
        stops.forEach((stopWatcher) => stopWatcher());
        expect(vi.getTimerCount()).toBe(0);
    });
    it('polls only while live updates are down when it has no check of its own', async () => {
        const read = vi.fn().mockResolvedValue(true);
        stop = watchChatUpdates(read, { topics: ['inbox.changed'] }, sync);
        await vi.advanceTimersByTimeAsync(10000);
        expect(read).toHaveBeenCalledTimes(2);
        sync.setState('live');
        await vi.advanceTimersByTimeAsync(60000);
        expect(read).toHaveBeenCalledTimes(2);
        sync.receive({ type: 'inbox.changed', reason: 'added' });
        await vi.advanceTimersByTimeAsync(200);
        expect(read).toHaveBeenCalledTimes(3);
    });
    it('avoids hidden/offline reads and refreshes when the app becomes available', async () => {
        const visible = vi
            .spyOn(document, 'visibilityState', 'get')
            .mockReturnValue('hidden');
        const online = vi
            .spyOn(navigator, 'onLine', 'get')
            .mockReturnValue(false);
        const read = vi.fn().mockResolvedValue(true);
        stop = watchChatUpdates(read, { interval: () => 5000 });
        await vi.advanceTimersByTimeAsync(10000);
        expect(read).not.toHaveBeenCalled();
        visible.mockReturnValue('visible');
        online.mockReturnValue(true);
        window.dispatchEvent(new Event('online'));
        await vi.advanceTimersByTimeAsync(250);
        expect(read).toHaveBeenCalledTimes(1);
    });
});
