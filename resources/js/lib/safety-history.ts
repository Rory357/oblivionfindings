type Position = { scope: string; index: number };
type Entry = { position: Position; url: string; key?: string };
type BrowserNavigation = {
    currentEntry: { key: string; url: string } | null;
    traverseTo: (key: string) => {
        committed: Promise<unknown>;
        finished: Promise<unknown>;
    };
};
type Guard = {
    identity?: object;
    priority: number;
    beforeRestore: () => void;
    resynchronize: (reload: boolean, url: string) => void;
    review: (continueNavigation: () => void) => void;
    failed: (recovery: {
        retry: () => void;
        url: string;
        blocked: boolean;
        reload: boolean;
    }) => void;
};

const marker = '__oblivionSafetyHistory';
let initialized = false;
let current: Entry;
let origin: Entry | null = null;
let target: Entry | null = null;
let phase: 'idle' | 'restoring' | 'confirming' | 'replaying' | 'blocked' =
    'idle';
let replay: Entry | null = null;
let revision = 0;
let inertMain: HTMLElement | null = null;
let previousInert = false;
let reloadRequired = false;
const guards = new Set<Guard>();
const retiredOwners = new Set<object>();

function navigation(): BrowserNavigation | undefined {
    const api = (window as Window & { navigation?: BrowserNavigation })
        .navigation;
    return typeof api?.traverseTo === 'function' ? api : undefined;
}

function position(state: unknown): Position | null {
    if (!state || typeof state !== 'object') return null;
    const candidate = (state as Record<string, unknown>)[marker] as
        | Position
        | undefined;
    if (!candidate || !current) return null;
    return candidate?.scope === current?.position.scope &&
        Number.isSafeInteger(candidate.index)
        ? candidate
        : null;
}

function entry(at: Position): Entry {
    return {
        position: at,
        url: window.location.href,
        key: navigation()?.currentEntry?.key,
    };
}

function same(a: Entry | null, b: Entry): boolean {
    return (
        !!a &&
        a.position.scope === b.position.scope &&
        a.position.index === b.position.index &&
        a.url === b.url &&
        (!a.key || !b.key || a.key === b.key)
    );
}

function owner(): Guard | undefined {
    return [...guards]
        .filter((guard) => !retiredOwners.has(guard.identity ?? guard))
        .sort((a, b) => b.priority - a.priority)
        .at(0);
}

function blockMain(blocked: boolean) {
    if (blocked && !inertMain) {
        inertMain = document.querySelector('main');
        if (inertMain) {
            previousInert = inertMain.inert;
            inertMain.inert = true;
        }
    } else if (!blocked && inertMain) {
        inertMain.inert = previousInert;
        inertMain = null;
    }
}

function failed(blocked: boolean) {
    revision += 1;
    replay = null;
    phase = blocked ? 'blocked' : 'idle';
    blockMain(blocked);
    const saved = origin;
    if (saved)
        owner()?.failed({
            retry: () => restore(),
            url: saved.url,
            blocked,
            reload: reloadRequired,
        });
}

function traverse(destination: Entry, restoring: boolean) {
    const attempt = ++revision;
    window.setTimeout(() => {
        if (
            revision === attempt &&
            (phase === 'restoring' || phase === 'replaying')
        )
            failed(restoring);
    }, 1500);
    try {
        const api = navigation();
        if (api && destination.key) {
            const result = api.traverseTo(destination.key);
            const reject = () => {
                if (revision === attempt) failed(restoring);
            };
            void result.committed.catch(reject);
            void result.finished.catch(reject);
        } else {
            const at = position(window.history.state);
            if (!at) throw new Error('Unknown history entry');
            const delta = destination.position.index - at.index;
            if (!delta) throw new Error('History entry no longer matches');
            window.history.go(delta);
        }
    } catch {
        if (revision === attempt) failed(restoring);
    }
}

function restore() {
    if (!origin) return;
    phase = 'restoring';
    blockMain(true);
    owner()?.beforeRestore();
    traverse(origin, true);
}

function prepareWrite() {
    if (phase === 'restoring' || phase === 'blocked') {
        // Inertia writes history before swapping a page. A queued response must
        // not replace the destination entry or unmount the retained forms.
        // This library version has no supported bridge for a rejected queued
        // history callback. Recovery must use a full document reload.
        reloadRequired = true;
        failed(true);
        throw new DOMException(
            'Return to the safety record before updating it.',
            'AbortError',
        );
    }
    origin = target = replay = null;
    phase = 'idle';
    revision += 1;
}

function isReviewScrollWrite(state: unknown, url?: string | URL | null) {
    // Installed Inertia saves delayed scroll metadata with no URL and the
    // exact opaque page it just read. That does not navigate or replace a
    // record. A page response, foreign field or changed entry still invalidates
    // the old approval; no private page is decrypted or compared by contents.
    if (phase !== 'confirming' || url != null || !origin) return false;
    const before = window.history.state;
    if (!state || typeof state !== 'object' || !before) return false;
    if (
        Object.getPrototypeOf(state) !== Object.prototype &&
        Object.getPrototypeOf(state) !== null
    )
        return false;
    const incoming = state as Record<string, unknown>;
    const existing = before as Record<string, unknown>;
    const allowed = new Set([
        'page',
        'scrollRegions',
        'documentScrollPosition',
        marker,
    ]);
    const at = position(before);
    return (
        !!at &&
        same(origin, entry(at)) &&
        incoming.page != null &&
        incoming.page === existing.page &&
        Object.keys(incoming).every((key) => allowed.has(key)) &&
        Object.keys(existing).every((key) => allowed.has(key)) &&
        ('scrollRegions' in incoming || 'documentScrollPosition' in incoming) &&
        (!(marker in incoming) ||
            (position(incoming)?.index === at.index &&
                position(incoming)?.scope === at.scope))
    );
}

function review() {
    phase = 'confirming';
    blockMain(false);
    const savedOrigin = origin;
    const savedTarget = target;
    const attempt = ++revision;
    owner()?.review(() => {
        if (attempt !== revision || phase !== 'confirming') return;
        if (!savedOrigin || !savedTarget) return;
        const at = position(window.history.state);
        if (!at || !same(savedOrigin, entry(at))) {
            failed(true);
            return;
        }
        replay = savedTarget;
        phase = 'replaying';
        traverse(savedTarget, false);
    });
}

/** Boot before Inertia: only non-sensitive positions are added to opaque state.
 * No entry is inserted/deleted for a leave review, and page encryption is untouched. */
export function initializeSafetyHistory() {
    if (initialized || typeof window === 'undefined') return;
    initialized = true;
    const scope =
        window.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random()}`;
    const push = window.history.pushState.bind(window.history);
    const replace = window.history.replaceState.bind(window.history);
    current = entry({ scope, index: 0 });
    const tagged = (state: unknown, at: Position) =>
        state !== null &&
        typeof state === 'object' &&
        (Object.getPrototypeOf(state) === Object.prototype ||
            Object.getPrototypeOf(state) === null)
            ? { ...state, [marker]: at }
            : state;
    replace(
        tagged(window.history.state ?? {}, current.position),
        '',
        current.url,
    );
    current = entry(current.position);
    window.history.pushState = (state, unused, url) => {
        prepareWrite();
        const at = { scope, index: current.position.index + 1 };
        push(tagged(state, at), unused, url);
        current = entry(at);
    };
    window.history.replaceState = (state, unused, url) => {
        const scrollWrite = isReviewScrollWrite(state, url);
        if (!scrollWrite) prepareWrite();
        replace(tagged(state, current.position), unused, url);
        current = entry(current.position);
        if (scrollWrite && origin) origin.key = current.key;
    };

    // Restore a real traversal before reviewing it. Cancelling the native
    // navigate event can leave repeated History API Forward requests without
    // another event even while its destination remains available. Entry keys
    // still identify the exact restoration/replay; Inertia never sees the
    // unapproved pop or replaces the retained source forms.
    window.addEventListener(
        'popstate',
        (event) => {
            const at = position(event.state);
            if (!at) {
                if (owner()) {
                    event.stopImmediatePropagation();
                    origin ??= current;
                    failed(true);
                }
                return;
            }
            const arrived = entry(at);
            if (
                phase === 'replaying' &&
                replay &&
                (replay.key
                    ? replay.key === arrived.key && replay.url === arrived.url
                    : same(replay, arrived))
            ) {
                // Leaving these drafts has been explicitly approved. Inertia
                // decrypts/loads the destination asynchronously after this pop;
                // another traversal must not reactivate the discarded source
                // owner while that old component is still mounted.
                for (const guard of guards)
                    retiredOwners.add(guard.identity ?? guard);
                replay = origin = target = null;
                phase = 'idle';
                current = arrived;
                revision += 1;
                return;
            }
            if (!owner() && phase !== 'restoring' && phase !== 'blocked') {
                current = arrived;
                origin = target = null;
                phase = 'idle';
                return;
            }
            event.stopImmediatePropagation();
            if (
                (phase === 'restoring' || phase === 'blocked') &&
                same(origin, arrived)
            ) {
                current = arrived;
                revision += 1;
                if (reloadRequired) {
                    failed(true);
                    return;
                }
                if (owner()) review();
                else {
                    phase = 'idle';
                    origin = target = null;
                    blockMain(false);
                }
                return;
            }
            origin ??= current;
            // Older History APIs have no traversal promise. Do not queue
            // competing relative moves on repeated Back; retain the forms and
            // report an interrupted return if the single move cannot restore.
            if (phase === 'restoring' || phase === 'blocked') return;
            target = arrived;
            restore();
        },
        true,
    );
}

export function subscribeSafetyHistory(guard: Guard) {
    guards.add(guard);
    return () => {
        guards.delete(guard);
        queueMicrotask(() => {
            // A rerender can replace the callback without replacing its form.
            for (const identity of retiredOwners)
                if (![...guards].some((g) => (g.identity ?? g) === identity))
                    retiredOwners.delete(identity);
            // StrictMode can replace a subscription within this same turn.
            if (guards.size) return;
            const resynchronize = phase === 'restoring' || phase === 'blocked';
            const savedUrl = origin?.url ?? window.location.href;
            const reload = reloadRequired;
            reloadRequired = false;
            origin = target = replay = null;
            phase = 'idle';
            revision += 1;
            blockMain(false);
            const at = position(window.history.state);
            if (at) current = entry(at);
            if (resynchronize) guard.resynchronize(reload, savedUrl);
        });
    };
}

export function safetyHistoryIsRestoring() {
    return phase === 'restoring' || phase === 'blocked';
}
