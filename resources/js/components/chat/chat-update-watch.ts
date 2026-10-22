import type { LiveEvent, LiveEventType } from './live/events';
import { chatSync, type ChatSync, type SyncPoll } from './live/sync';

// Any Chat change wakes a watcher that has not said what it shows.
const ANY_CHAT_CHANGE: readonly LiveEventType[] = [
    'conversation.changed',
    'inbox.changed',
];

/**
 * Keep something on screen up to date. Every caller shares the app's one sync loop
 * (live/sync.ts): one timer, woken by Oblivion Live events that match `topics` and
 * `match`, and by the window events listed in `events`.
 *
 * `interval` is the watcher's own check and keeps running while live (file scans, call
 * timers). Without it the watcher polls only while live updates are down.
 */
export function watchChatUpdates(
    refresh: () => Promise<boolean>,
    options: {
        interval?: () => number;
        poll?: SyncPoll;
        whenHidden?: boolean;
        events?: string[];
        topics?: readonly LiveEventType[];
        match?: (event: LiveEvent) => boolean;
    },
    sync: ChatSync = chatSync,
) {
    const interval = options.interval;
    return sync.watch({
        refresh: async () => Boolean(await refresh()),
        poll: interval ? () => interval() : (options.poll ?? 'fallback'),
        whenHidden: options.whenHidden,
        wakeOn: options.events ?? ['oblivion:chat-refresh'],
        topics: options.topics ?? ANY_CHAT_CHANGE,
        match: options.match,
    });
}
