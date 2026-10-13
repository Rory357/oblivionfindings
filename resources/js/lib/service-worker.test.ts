/* eMAR audit Lane A4 (EA-007): the service worker never keeps medication
 * pages, APIs, exports or PDFs on the device, and activating it removes the
 * old cache that did. public/sw.js runs here in a sandbox with stubbed
 * service-worker globals. */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import { describe, expect, it, vi } from 'vitest';

type Listener = (event: Record<string, unknown>) => void;

function htmlResponse(
    contentType = 'text/html; charset=UTF-8',
    extra: Record<string, string> = {},
) {
    const headers = new Map(
        Object.entries({ 'Content-Type': contentType, ...extra }).map(
            ([k, v]) => [k.toLowerCase(), v],
        ),
    );
    const response = {
        ok: true,
        type: 'basic',
        headers: {
            get: (name: string) => headers.get(name.toLowerCase()) ?? null,
        },
        clone: () => response,
    };
    return response;
}

function loadWorker(existingCaches: string[] = []) {
    const listeners = new Map<string, Listener>();
    const stores = new Map<string, Map<string, unknown>>(
        existingCaches.map((name) => [name, new Map()]),
    );
    const keyOf = (request: unknown) =>
        typeof request === 'string'
            ? request
            : String((request as { url: string }).url);
    const caches = {
        open: vi.fn(async (name: string) => {
            if (!stores.has(name)) stores.set(name, new Map());
            const store = stores.get(name)!;
            return {
                put: vi.fn(async (request: unknown, response: unknown) => {
                    store.set(keyOf(request), response);
                }),
                add: vi.fn(async (request: unknown) => {
                    store.set(keyOf(request), htmlResponse());
                }),
                match: vi.fn(async (request: unknown) =>
                    store.get(keyOf(request)),
                ),
            };
        }),
        keys: vi.fn(async () => [...stores.keys()]),
        delete: vi.fn(async (name: string) => stores.delete(name)),
    };
    const fetchMock = vi.fn(async (request?: unknown) => {
        void request;
        return htmlResponse();
    });
    const self = {
        location: { origin: 'https://care.test' },
        addEventListener: (type: string, listener: Listener) =>
            listeners.set(type, listener),
        skipWaiting: vi.fn(),
        clients: { claim: vi.fn() },
        registration: { showNotification: vi.fn() },
    };
    const context = vm.createContext({
        self,
        caches,
        fetch: fetchMock,
        clients: self.clients,
        URL,
        Request: class {
            url: string;
            constructor(url: string) {
                this.url = new URL(url, 'https://care.test').href;
            }
        },
        Promise,
        console,
    });
    vm.runInContext(
        readFileSync(path.resolve(__dirname, '../../../public/sw.js'), 'utf8'),
        context,
    );

    return { listeners, stores, fetchMock };
}

async function dispatch(listener: Listener, event: Record<string, unknown>) {
    const waits: Promise<unknown>[] = [];
    let responded: Promise<unknown> | null = null;
    listener({
        ...event,
        waitUntil: (p: Promise<unknown>) => waits.push(p),
        respondWith: (p: Promise<unknown>) => {
            responded = Promise.resolve(p);
        },
    });
    await Promise.all(waits);
    if (responded) await responded;
    return responded !== null;
}

function navigation(pathname: string) {
    return {
        request: {
            url: `https://care.test${pathname}`,
            mode: 'navigate',
            method: 'GET',
        },
    };
}

function get(pathname: string) {
    return {
        request: {
            url: `https://care.test${pathname}`,
            mode: 'cors',
            method: 'GET',
        },
    };
}

describe('service worker (EA-007)', () => {
    it('installs without fetching or caching any medication page', async () => {
        const { listeners, stores, fetchMock } = loadWorker();
        await dispatch(listeners.get('install')!, {});
        const cached = [...stores.values()].flatMap((store) => [
            ...store.keys(),
        ]);
        expect(cached.some((key) => /\/(emar|meds)/.test(key))).toBe(false);
        const fetched = fetchMock.mock.calls.map(([request]) =>
            String((request as { url?: string } | undefined)?.url ?? request),
        );
        expect(fetched.some((url) => /\/(emar|meds)/.test(url))).toBe(false);
    });

    it.each([
        ['/emar/mar?client_id=5'],
        ['/meds/today'],
        ['/emar'],
        ['/medication-followups'],
        ['/clients/5?tab=medications'],
    ])('never answers or caches the page %s', async (pathname) => {
        const { listeners, stores } = loadWorker();
        const handled = await dispatch(
            listeners.get('fetch')!,
            navigation(pathname),
        );
        expect(handled).toBe(false);
        expect([...stores.values()].every((store) => store.size === 0)).toBe(
            true,
        );
    });

    it.each([
        ['/emar/pdf/controlled-register'],
        ['/emar/audit/export'],
        ['/api/medications/clients/5/medications'],
    ])('never caches the medication download or API %s', async (pathname) => {
        const { listeners, stores } = loadWorker();
        const handled = await dispatch(listeners.get('fetch')!, get(pathname));
        expect(handled).toBe(false);
        expect([...stores.values()].every((store) => store.size === 0)).toBe(
            true,
        );
    });

    it('keeps the allowed offline fleet page, but never a file answer', async () => {
        const { listeners, stores } = loadWorker();
        expect(
            await dispatch(
                listeners.get('fetch')!,
                navigation('/fleet-assets/daily-check'),
            ),
        ).toBe(true);
        expect(
            stores
                .get('of-app-shell-v4')
                ?.has('https://care.test/fleet-assets/daily-check'),
        ).toBe(true);

        const fresh = loadWorker();
        fresh.fetchMock.mockResolvedValueOnce(
            htmlResponse('text/html', {
                'Content-Disposition': 'attachment; filename=x.html',
            }),
        );
        await dispatch(
            fresh.listeners.get('fetch')!,
            navigation('/fleet-assets/daily-check'),
        );
        expect(fresh.stores.get('of-app-shell-v4')?.size ?? 0).toBe(0);
    });

    it('deletes the old cache that held MAR pages, PDFs and exports when it activates', async () => {
        const { listeners, stores } = loadWorker(['of-app-shell-v3']);
        await dispatch(listeners.get('activate')!, {});
        expect(stores.has('of-app-shell-v3')).toBe(false);
    });

    it('purges every cache when the app asks at sign-out', async () => {
        const { listeners, stores } = loadWorker(['of-app-shell-v4', 'other']);
        await dispatch(listeners.get('message')!, {
            data: { type: 'purge-caches' },
        });
        expect(stores.size).toBe(0);
    });
});
