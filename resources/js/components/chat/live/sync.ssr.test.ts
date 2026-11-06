import { afterEach, expect, it, vi } from 'vitest';

const browserGlobals = ['window', 'document', 'navigator'] as const;
const saved = new Map<string, PropertyDescriptor | undefined>();

/** Like the server: touching a browser global throws, as it does in Node. */
function withoutBrowser() {
    for (const name of browserGlobals) {
        saved.set(name, Object.getOwnPropertyDescriptor(globalThis, name));
        Object.defineProperty(globalThis, name, {
            configurable: true,
            get() {
                throw new ReferenceError(`${name} is not defined`);
            },
        });
    }
}

afterEach(() => {
    for (const [name, descriptor] of saved)
        if (descriptor) Object.defineProperty(globalThis, name, descriptor);
        else delete (globalThis as Record<string, unknown>)[name];
    saved.clear();
});

it('can be imported by the server render, which has no window or document', async () => {
    vi.resetModules();
    withoutBrowser();

    const { chatSync } = await import('./sync');

    expect(chatSync.state).toBe('off');
    expect(chatSync.live).toBe(false);
});
