import { afterEach, expect, it, vi } from 'vitest';

type StoredEntry = { url: string; state: unknown; key: string };

async function browser(native = false) {
    vi.resetModules();
    const entries: StoredEntry[] = [
        {
            url: 'https://app.example.test/control-room/alerts?status=open',
            state: {
                page: new ArrayBuffer(8),
                documentScrollPosition: { top: 24, left: 0 },
            },
            key: 'entry-0',
        },
    ];
    let index = 0;
    let sequence = 0;
    const pending: (() => void)[] = [];
    const main = { inert: false };
    const surface = new EventTarget();
    const location = { href: entries[0].url };
    const api = Object.assign(new EventTarget(), {
        currentEntry: { key: entries[0].key, url: entries[0].url },
        traverseTo: vi.fn((key: string) => {
            const destination = entries.findIndex((e) => e.key === key);
            if (destination < 0) throw new Error('Disposed history entry');
            move(destination, true);
            return {
                committed: Promise.resolve(),
                finished: Promise.resolve(),
            };
        }),
    });
    const setEntry = (at: number) => {
        index = at;
        location.href = entries[at].url;
        api.currentEntry = { key: entries[at].key, url: entries[at].url };
    };
    const move = (at: number, cancelable = false) => {
        if (at < 0 || at >= entries.length) return;
        if (native) {
            const event = Object.assign(new Event('navigate', { cancelable }), {
                navigationType: 'traverse',
                destination: { ...entries[at], sameDocument: true },
            });
            api.dispatchEvent(event);
            if (event.defaultPrevented) return;
        }
        setEntry(at);
        surface.dispatchEvent(
            new PopStateEvent('popstate', { state: entries[at].state }),
        );
    };
    const history = {
        get state() {
            return entries[index].state;
        },
        get length() {
            return entries.length;
        },
        pushState: vi.fn(
            (state: unknown, _unused: string, url?: string | URL | null) => {
                entries.splice(index + 1);
                entries.push({
                    state,
                    url: new URL(
                        url?.toString() ?? location.href,
                        location.href,
                    ).href,
                    key: `entry-${++sequence}`,
                });
                setEntry(index + 1);
            },
        ),
        replaceState: vi.fn(
            (state: unknown, _unused: string, url?: string | URL | null) => {
                entries[index] = {
                    ...entries[index],
                    state,
                    url: new URL(
                        url?.toString() ?? location.href,
                        location.href,
                    ).href,
                };
                setEntry(index);
            },
        ),
        go: vi.fn((delta: number) => pending.push(() => move(index + delta))),
    };
    vi.stubGlobal(
        'window',
        Object.assign(surface, {
            history,
            location,
            crypto: { randomUUID: () => 'this-document' },
            navigation: native ? api : undefined,
            setTimeout: vi.fn(),
        }),
    );
    vi.stubGlobal('document', { querySelector: () => main });
    const adapter = await import('./safety-history');
    adapter.initializeSafetyHistory();
    const reviews: (() => void)[] = [];
    const failed = vi.fn();
    const beforeRestore = vi.fn();
    const resynchronize = vi.fn();
    const subscribe = (priority = 0) =>
        adapter.subscribeSafetyHistory({
            priority,
            review: (run) => reviews.push(run),
            failed,
            beforeRestore,
            resynchronize,
        });
    const flush = () => {
        let steps = 0;
        while (pending.length) {
            if (++steps > 20)
                throw new Error('History restoration did not converge');
            pending.shift()!();
        }
    };
    return {
        adapter,
        entries,
        history,
        location,
        main,
        api,
        reviews,
        failed,
        beforeRestore,
        resynchronize,
        subscribe,
        move,
        flush,
        surface,
    };
}

afterEach(() => vi.unstubAllGlobals());

it('preserves encrypted page state, scroll state, null and non-plain writers without adding entries', async () => {
    const b = await browser();
    const opaque = (b.history.state as { page: ArrayBuffer }).page;
    expect(b.history.length).toBe(1);
    b.history.replaceState(
        { page: opaque, documentScrollPosition: { top: 24 } },
        '',
        '/control-room/alerts/13',
    );
    expect((b.history.state as { page: ArrayBuffer }).page).toBe(opaque);
    expect(b.history.state).toMatchObject({
        documentScrollPosition: { top: 24 },
    });
    b.history.replaceState(null, '', '/respite?pane=notes');
    expect(b.history.state).toBeNull();
    const foreign = new ArrayBuffer(4);
    b.history.replaceState(foreign, '', '/assets?panel=details');
    expect(b.history.state).toBe(foreign);
    expect(b.history.length).toBe(1);
});

it('restores native Back to its exact entry before review and approves the original destination once', async () => {
    const b = await browser(true);
    b.history.pushState(
        { page: new ArrayBuffer(8) },
        '',
        '/control-room/alerts/13?section=evidence',
    );
    b.subscribe();
    const passed = vi.fn();
    b.surface.addEventListener('popstate', passed);
    b.move(0, true);
    expect(b.location.href).toContain('/alerts/13?section=evidence');
    expect(b.reviews).toHaveLength(1);
    expect(passed).not.toHaveBeenCalled();
    expect(b.api.traverseTo).toHaveBeenCalledExactlyOnceWith('entry-1');
    b.reviews[0]();
    expect(b.api.traverseTo).toHaveBeenLastCalledWith('entry-0');
    expect(b.location.href).toContain('/alerts?status=open');
    expect(passed).toHaveBeenCalledTimes(1);
    expect(b.history.length).toBe(2);
});

it('reviews repeated native Back and Forward after keeping the same source draft', async () => {
    const b = await browser(true);
    const source = {
        page: new ArrayBuffer(8),
        documentScrollPosition: { top: 70 },
    };
    b.history.pushState(source, '', '/control-room/alerts/13');
    b.history.pushState(
        { page: {} },
        '',
        '/health-safety/lone-workers/sessions/20',
    );
    b.move(1, true);
    const envelope = b.history.state;
    b.subscribe();
    const passed = vi.fn();
    b.surface.addEventListener('popstate', passed);
    for (const destination of [0, 2, 2]) {
        b.move(destination, true);
        expect(b.location.href).toContain('/control-room/alerts/13');
        expect(b.api.currentEntry.key).toBe('entry-1');
        expect(b.history.state).toBe(envelope);
        expect(b.history.length).toBe(3);
        expect(passed).not.toHaveBeenCalled();
    }
    expect(b.reviews).toHaveLength(3);
    b.reviews[1](); // A dismissed older review cannot authorize the latest one.
    expect(passed).not.toHaveBeenCalled();
    b.reviews[2]();
    expect(b.location.href).toContain(
        '/health-safety/lone-workers/sessions/20',
    );
    expect(passed).toHaveBeenCalledTimes(1);
    expect(b.history.length).toBe(3);
});

it('does not reactivate an approved old draft while Inertia is still loading the destination', async () => {
    const b = await browser(true);
    b.history.pushState({ page: {} }, '', '/control-room/alerts/13');
    const identity = {};
    const oldReview = vi.fn((run: () => void) => b.reviews.push(run));
    const guard = {
        identity,
        priority: 0,
        review: oldReview,
        failed: b.failed,
        beforeRestore: b.beforeRestore,
        resynchronize: b.resynchronize,
    };
    const remove = b.adapter.subscribeSafetyHistory(guard);
    b.move(0, true);
    b.reviews[0]();
    // The real replay has committed; the old React page has not unmounted yet.
    remove();
    const replacement = b.adapter.subscribeSafetyHistory({
        ...guard,
        review: oldReview,
    });
    await Promise.resolve();
    b.move(1, true);
    expect(b.location.href).toContain('/control-room/alerts/13');
    expect(oldReview).toHaveBeenCalledTimes(1);
    replacement();
    await Promise.resolve();
    // A newly mounted form receives its own authority to protect new entries.
    b.subscribe();
    b.move(0, true);
    expect(b.location.href).toContain('/control-room/alerts/13');
    expect(b.reviews).toHaveLength(2);
});

it.each([false, true])(
    'restores noncancelable Back without exposing it to Inertia (native keys: %s)',
    async (native) => {
        const b = await browser(native);
        b.history.pushState(
            { page: new ArrayBuffer(8) },
            '',
            '/control-room/alerts/13?section=evidence',
        );
        b.subscribe();
        const passed = vi.fn();
        b.surface.addEventListener('popstate', passed);
        b.move(0);
        b.flush();
        expect(b.location.href).toContain('/alerts/13?section=evidence');
        expect(b.reviews).toHaveLength(1);
        expect(passed).not.toHaveBeenCalled();
        expect(b.main.inert).toBe(false);
        b.reviews[0]();
        b.flush();
        expect(b.location.href).toContain('/alerts?status=open');
        expect(passed).toHaveBeenCalledTimes(1);
        expect(b.history.length).toBe(2);
    },
);

it('keeps Forward entries and uses the original multi-entry destination', async () => {
    const b = await browser();
    b.history.pushState(
        { page: {} },
        '',
        '/control-room/alerts/13?section=overview',
    );
    b.history.pushState(
        { page: {} },
        '',
        '/control-room/alerts/13?section=tasks',
    );
    b.history.pushState(
        { page: {} },
        '',
        '/control-room/alerts/13?section=evidence',
    );
    b.move(0); // Clean history navigation.
    b.subscribe();
    b.move(3);
    b.flush();
    expect(b.location.href).toContain('/alerts?status=open');
    expect(b.history.length).toBe(4);
    b.reviews[0]();
    b.flush();
    expect(b.location.href).toContain('?section=evidence');
    expect(b.history.length).toBe(4);
});

it('invalidates an old review when a normal visit writes a new entry', async () => {
    const b = await browser(true);
    b.history.pushState({ page: {} }, '', '/control-room/alerts/13');
    b.subscribe();
    b.move(0, true);
    const obsolete = b.reviews[0];
    const restored = b.api.traverseTo.mock.calls.length;
    b.history.pushState({ page: {} }, '', '/control-room/alerts/14');
    obsolete();
    expect(b.api.traverseTo).toHaveBeenCalledTimes(restored);
    b.move(1, true);
    b.reviews[1]();
    expect(b.location.href).toContain('/alerts/13');
    expect(b.failed).not.toHaveBeenCalled();
});

it.each([false, true])(
    'preserves approval through delayed source scroll metadata (native keys: %s)',
    async (native) => {
        const b = await browser(native);
        b.history.pushState(
            { page: new ArrayBuffer(8) },
            '',
            '/control-room/alerts/13',
        );
        b.subscribe();
        b.move(0, true);
        b.flush();
        const opaque = (b.history.state as { page: ArrayBuffer }).page;
        b.history.replaceState(
            { page: opaque, documentScrollPosition: { top: 72, left: 0 } },
            '',
        );
        expect((b.history.state as { page: ArrayBuffer }).page).toBe(opaque);
        expect(b.reviews).toHaveLength(1);
        b.reviews[0]();
        b.flush();
        expect(b.location.href).toContain('/alerts?status=open');
        expect(b.history.length).toBe(2);
        expect(b.failed).not.toHaveBeenCalled();
    },
);

it.each(['page', 'foreign-field', 'url'])(
    'invalidates approval for a same-URL %s replacement instead of treating it as scroll metadata',
    async (change) => {
        const b = await browser(true);
        b.history.pushState({ page: {} }, '', '/control-room/alerts/13');
        b.subscribe();
        b.move(0, true);
        const restored = b.api.traverseTo.mock.calls.length;
        const opaque = (b.history.state as { page: object }).page;
        b.history.replaceState(
            {
                page: change === 'page' ? {} : opaque,
                documentScrollPosition: { top: 72, left: 0 },
                ...(change === 'foreign-field' ? { recordVersion: 2 } : {}),
            },
            '',
            change === 'url' ? b.location.href : undefined,
        );
        b.reviews[0]();
        expect(b.api.traverseTo).toHaveBeenCalledTimes(restored);
        expect(b.location.href).toContain('/alerts/13');
    },
);

it('does not queue competing relative moves when Back interrupts an older-browser return', async () => {
    const b = await browser();
    for (let n = 1; n <= 7; n++)
        b.history.pushState({ page: {} }, '', `/control-room/alerts/${n}`);
    b.move(5);
    b.subscribe();
    b.move(4);
    b.move(3);
    expect(b.history.go).toHaveBeenCalledTimes(1);
    b.flush();
    expect(b.history.go).toHaveBeenCalledTimes(1);
    expect(b.reviews).toHaveLength(0);
    expect(b.main.inert).toBe(true);
    const timeout = vi
        .mocked(window.setTimeout)
        .mock.calls.at(-1)![0] as () => void;
    timeout();
    expect(b.failed).toHaveBeenCalledWith(
        expect.objectContaining({ blocked: true }),
    );
    const recovery = b.failed.mock.calls.at(-1)![0];
    recovery.retry();
    b.flush();
    expect(b.location.href).toContain('/alerts/5');
    expect(b.main.inert).toBe(false);
    expect(b.reviews).toHaveLength(1);
    b.reviews[0]();
    b.flush();
    expect(b.location.href).toContain('/alerts/4');
    expect(b.history.length).toBe(8);
});

it('blocks queued history writes before they can overwrite an entry or swap the source page', async () => {
    const b = await browser();
    b.history.pushState({ page: {} }, '', '/control-room/alerts/13');
    b.subscribe();
    b.move(0);
    const destination = b.history.state;
    expect(() =>
        b.history.replaceState(
            { page: { unexpected: true } },
            '',
            '/control-room/alerts/13',
        ),
    ).toThrow(/Return to the safety record/);
    expect(b.history.state).toBe(destination);
    expect(b.main.inert).toBe(true);
    expect(b.failed).toHaveBeenCalledWith(
        expect.objectContaining({ blocked: true }),
    );
    b.flush();
    expect(b.location.href).toContain('/alerts/13');
    expect(b.main.inert).toBe(true);
    expect(b.failed).toHaveBeenLastCalledWith(
        expect.objectContaining({ reload: true, blocked: true }),
    );
    expect(b.reviews).toHaveLength(0);
});

it('uses the bounded child handoff guard before its parent page and cleans up a vanished owner', async () => {
    const b = await browser();
    b.history.pushState({ page: {} }, '', '/control-room/alerts/13');
    const parent = vi.fn();
    const unsubscribeParent = b.adapter.subscribeSafetyHistory({
        priority: 0,
        review: parent,
        failed: b.failed,
        beforeRestore: b.beforeRestore,
        resynchronize: b.resynchronize,
    });
    const unsubscribeChild = b.subscribe(1);
    b.move(0);
    b.flush();
    expect(parent).not.toHaveBeenCalled();
    expect(b.reviews).toHaveLength(1);
    unsubscribeChild();
    unsubscribeParent();
    await Promise.resolve();
    expect(b.main.inert).toBe(false);
    b.move(0);
    expect(b.location.href).toContain('/alerts?status=open');
});

it('cleans up blocked UI and requests a fresh authorized page when no draft owner remains', async () => {
    const b = await browser();
    b.history.pushState({ page: {} }, '', '/control-room/alerts/13');
    const unsubscribe = b.subscribe();
    b.move(0);
    expect(() =>
        b.history.pushState({ page: {} }, '', '/unexpected'),
    ).toThrow();
    unsubscribe();
    await Promise.resolve();
    expect(b.main.inert).toBe(false);
    expect(b.resynchronize).toHaveBeenCalledTimes(1);
});
