// One sync loop for the whole app (spec section 8). Watchers (the conversation list, the open
// thread, call status, message sounds, family views…) register what they need; the loop runs
// them when a live event names something they show, and polls only while live updates are down:
// every 10 s with the tab visible, 60 s hidden. One timer serves every watcher.

import type { LiveEvent, LiveEventType } from './events';

export type SyncState =
    | 'off'
    | 'connecting'
    | 'live'
    | 'recovering'
    | 'offline';

export type SyncReason = {
    kind: 'start' | 'event' | 'poll' | 'resync' | 'local' | 'visible';
    /** The live events that woke this run (empty for polls and local wakes). */
    events: LiveEvent[];
};

/** A number polls at that interval even while live; 'fallback' polls only while live updates are down. */
export type SyncPoll = number | 'fallback' | null;

export type ChatWatch = {
    refresh: (reason: SyncReason) => Promise<boolean>;
    /** Live event types that wake this watcher. */
    topics?: readonly LiveEventType[];
    /** A further filter, e.g. one conversation. */
    match?: (event: LiveEvent) => boolean;
    /** The open thread: skip message changes its own change cursor has already passed. */
    cursor?: { conversationId: number; position: () => number | null };
    /** Keep refreshing while the tab is hidden (calls, sounds, file checks). */
    whenHidden?: boolean;
    /** Window events from the person's own actions that also wake this watcher. */
    wakeOn?: readonly string[];
    poll?: SyncPoll | (() => SyncPoll);
    /** Run once straight away (default). */
    immediate?: boolean;
};

export type SyncEnvironment = {
    now: () => number;
    setTimeout: (callback: () => void, ms: number) => unknown;
    clearTimeout: (timer: unknown) => void;
    visible: () => boolean;
    online: () => boolean;
    target: Pick<EventTarget, 'addEventListener' | 'removeEventListener'>;
    documentTarget: Pick<
        EventTarget,
        'addEventListener' | 'removeEventListener'
    >;
};

export const VISIBLE_POLL_MS = 10_000;
export const HIDDEN_POLL_MS = 60_000;
const EVENT_DELAY_MS = 150;
const LOCAL_DELAY_MS = 250;
const MAX_BACKOFF_MS = 60_000;

// Kinds that only add or change messages, which the thread's change cursor covers.
export const CURSOR_KINDS = new Set([
    'message',
    'edit',
    'removal',
    'reaction',
    'pin',
    'save',
    'update',
]);

type Watcher = {
    options: ChatWatch;
    running: boolean;
    again: SyncReason | null;
    failures: number;
    /** When the next poll or event run is due; null when nothing is scheduled. */
    nextPoll: number | null;
    due: number | null;
    dueKind: SyncReason['kind'];
    dirty: boolean;
    events: LiveEvent[];
    seen: Map<number, string>;
    removed: boolean;
};

const browserEnvironment = (): SyncEnvironment => ({
    now: () => Date.now(),
    setTimeout: (callback, ms) => window.setTimeout(callback, ms),
    clearTimeout: (timer) => window.clearTimeout(timer as number),
    visible: () => document.visibilityState === 'visible',
    online: () => navigator.onLine,
    target: window,
    documentTarget: document,
});

export class ChatSync {
    private readonly env: SyncEnvironment;
    private readonly watchers = new Set<Watcher>();
    private readonly subscribers = new Map<
        LiveEventType,
        Set<(event: LiveEvent) => void>
    >();
    private readonly stateListeners = new Set<(state: SyncState) => void>();
    private readonly localNames = new Map<string, () => void>();
    private timer: unknown = null;
    private attached = false;
    private current: SyncState = 'off';

    constructor(environment?: SyncEnvironment) {
        this.env = environment ?? browserEnvironment();
    }

    get state() {
        return this.current;
    }

    get live() {
        return this.current === 'live';
    }

    /** Register a watcher. Returns a function that removes it. */
    watch(options: ChatWatch): () => void {
        const watcher: Watcher = {
            options,
            running: false,
            again: null,
            failures: 0,
            nextPoll: null,
            due: null,
            dueKind: 'poll',
            dirty: false,
            events: [],
            seen: new Map(),
            removed: false,
        };
        this.watchers.add(watcher);
        this.attach();
        for (const name of options.wakeOn ?? []) this.listenLocal(name);
        if (options.immediate === false) {
            watcher.nextPoll = this.pollAt(watcher);
            this.schedule();
        } else void this.run(watcher, { kind: 'start', events: [] });
        return () => {
            watcher.removed = true;
            this.watchers.delete(watcher);
            if (this.watchers.size === 0) this.detach();
            else this.schedule();
        };
    }

    /** Raw events for things that are not fetched: typing, presence, unread totals, call signals. */
    subscribe(
        types: readonly LiveEventType[],
        handler: (event: LiveEvent) => void,
    ): () => void {
        for (const type of types) {
            let set = this.subscribers.get(type);
            if (!set) this.subscribers.set(type, (set = new Set()));
            set.add(handler);
        }
        return () => {
            for (const type of types)
                this.subscribers.get(type)?.delete(handler);
        };
    }

    onState(listener: (state: SyncState) => void): () => void {
        this.stateListeners.add(listener);
        return () => this.stateListeners.delete(listener);
    }

    setState(state: SyncState) {
        if (state === this.current) return;
        const wasLive = this.current === 'live';
        this.current = state;
        // Switching between live and polling changes when each watcher is next due.
        if (wasLive !== (state === 'live')) {
            for (const watcher of this.watchers)
                watcher.nextPoll = this.pollAt(watcher);
            this.schedule();
        }
        for (const listener of this.stateListeners) listener(state);
    }

    /** After any (re)connect: catch up once through the app, then follow events. */
    resync() {
        for (const watcher of this.watchers)
            this.queue(watcher, 'resync', null, 0);
    }

    receive(event: LiveEvent) {
        for (const handler of this.subscribers.get(event.type) ?? []) {
            try {
                handler(event);
            } catch {
                /* one listener must not stop the others */
            }
        }
        for (const watcher of this.watchers) {
            if (this.wants(watcher, event))
                this.queue(watcher, 'event', event, EVENT_DELAY_MS);
        }
    }

    /** Wake watchers that listen for a local window event (the person's own action). */
    wakeLocal(name: string) {
        for (const watcher of this.watchers) {
            if (watcher.options.wakeOn?.includes(name))
                this.queue(watcher, 'local', null, LOCAL_DELAY_MS);
        }
    }

    private wants(watcher: Watcher, event: LiveEvent) {
        const { topics, match, cursor } = watcher.options;
        if (!topics?.includes(event.type) || (match && !match(event)))
            return false;
        if (event.type !== 'conversation.changed') return true;
        // Only message changes have their own change ID. Other kinds (people, calls, reads)
        // share the newest one, so a repeat may be a new change and is always fetched.
        if (!event.kinds.every((kind) => CURSOR_KINDS.has(kind))) return true;
        // The same message change told twice is ignored.
        const signature = `${event.change_id}:${[...event.kinds].sort().join(',')}`;
        if (watcher.seen.get(event.conversation_id) === signature) return false;
        watcher.seen.set(event.conversation_id, signature);
        // Out-of-order or already-read message changes: the cursor is at or past them.
        if (cursor?.conversationId !== event.conversation_id) return true;
        const position = cursor.position();
        return position === null || event.change_id > position;
    }

    private queue(
        watcher: Watcher,
        kind: SyncReason['kind'],
        event: LiveEvent | null,
        delay: number,
    ) {
        if (event) watcher.events.push(event);
        if (!this.env.visible() && !watcher.options.whenHidden) {
            // Caught up when the tab is next looked at.
            watcher.dirty = true;
            return;
        }
        const due = this.env.now() + delay;
        if (watcher.due === null || due < watcher.due) {
            watcher.due = due;
            watcher.dueKind = kind;
        }
        this.schedule();
    }

    private interval(watcher: Watcher): number | null {
        const poll =
            typeof watcher.options.poll === 'function'
                ? watcher.options.poll()
                : (watcher.options.poll ?? 'fallback');
        const hidden = !this.env.visible();
        if (hidden && !watcher.options.whenHidden) return null;
        let base: number | null;
        if (typeof poll === 'number') base = poll;
        else if (poll === 'fallback' && !this.live)
            base = hidden ? HIDDEN_POLL_MS : VISIBLE_POLL_MS;
        else base = null;
        if (base === null) return null;
        return Math.min(
            MAX_BACKOFF_MS,
            base * 2 ** Math.min(watcher.failures, 5),
        );
    }

    private pollAt(watcher: Watcher): number | null {
        const interval = this.interval(watcher);
        return interval === null ? null : this.env.now() + interval;
    }

    private schedule() {
        if (this.timer !== null) this.env.clearTimeout(this.timer);
        this.timer = null;
        let earliest = Infinity;
        for (const watcher of this.watchers) {
            if (watcher.running) continue;
            if (watcher.due !== null)
                earliest = Math.min(earliest, watcher.due);
            if (watcher.nextPoll !== null)
                earliest = Math.min(earliest, watcher.nextPoll);
        }
        if (earliest === Infinity) return;
        this.timer = this.env.setTimeout(
            () => this.tick(),
            Math.max(0, earliest - this.env.now()),
        );
    }

    private tick() {
        this.timer = null;
        const now = this.env.now();
        for (const watcher of [...this.watchers]) {
            if (watcher.running) continue;
            if (watcher.due !== null && watcher.due <= now) {
                const kind = watcher.dueKind;
                void this.run(watcher, { kind, events: [] });
            } else if (watcher.nextPoll !== null && watcher.nextPoll <= now) {
                void this.run(watcher, { kind: 'poll', events: [] });
            }
        }
        this.schedule();
    }

    private async run(watcher: Watcher, reason: SyncReason) {
        if (watcher.removed) return;
        if (watcher.running) {
            watcher.again = reason;
            return;
        }
        if (
            (!this.env.visible() && !watcher.options.whenHidden) ||
            !this.env.online()
        ) {
            watcher.dirty = true;
            watcher.due = null;
            watcher.nextPoll = this.pollAt(watcher);
            this.schedule();
            return;
        }
        watcher.running = true;
        watcher.due = null;
        watcher.dirty = false;
        const events = watcher.events.splice(0);
        try {
            const ok = await watcher.options.refresh({
                kind: reason.kind,
                events: [...reason.events, ...events],
            });
            watcher.failures = ok ? 0 : watcher.failures + 1;
        } catch {
            watcher.failures++;
        } finally {
            watcher.running = false;
            if (!watcher.removed) {
                watcher.nextPoll = this.pollAt(watcher);
                if (watcher.again || watcher.events.length) {
                    const again = watcher.again ?? {
                        kind: 'event',
                        events: [],
                    };
                    watcher.again = null;
                    this.queue(watcher, again.kind, null, LOCAL_DELAY_MS);
                }
                this.schedule();
            }
        }
    }

    private wakeAll(kind: SyncReason['kind'], onlyDirty: boolean) {
        for (const watcher of this.watchers) {
            if (!onlyDirty || watcher.dirty)
                this.queue(watcher, kind, null, LOCAL_DELAY_MS);
        }
    }

    private readonly visibility = () => {
        if (!this.env.visible()) {
            for (const watcher of this.watchers)
                watcher.nextPoll = this.pollAt(watcher);
            this.schedule();
            return;
        }
        // Back on screen: while live only what changed meanwhile; otherwise catch up everything.
        this.wakeAll('visible', this.live);
    };

    private readonly reconnect = () => this.wakeAll('local', false);

    private listenLocal(name: string) {
        if (this.localNames.has(name)) return;
        const handler = () => this.wakeLocal(name);
        this.localNames.set(name, handler);
        this.env.target.addEventListener(name, handler);
    }

    private attach() {
        if (this.attached) return;
        this.attached = true;
        this.env.documentTarget.addEventListener(
            'visibilitychange',
            this.visibility,
        );
        this.env.target.addEventListener('online', this.reconnect);
    }

    private detach() {
        if (!this.attached) return;
        this.attached = false;
        this.env.documentTarget.removeEventListener(
            'visibilitychange',
            this.visibility,
        );
        this.env.target.removeEventListener('online', this.reconnect);
        for (const [name, handler] of this.localNames)
            this.env.target.removeEventListener(name, handler);
        this.localNames.clear();
        if (this.timer !== null) this.env.clearTimeout(this.timer);
        this.timer = null;
    }
}

/** The app's one sync loop. */
export const chatSync = new ChatSync();
