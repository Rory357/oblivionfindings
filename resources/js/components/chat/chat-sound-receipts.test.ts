import { afterEach, beforeEach, expect, it, vi } from 'vitest';

beforeEach(() => {
    vi.resetModules();
    delete globalThis.OblivionChatSoundReceipts;
});
afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
    delete globalThis.OblivionChatSoundReceipts;
    document.head
        .querySelectorAll('script[src="/chat-sound-receipts.js"]')
        .forEach((node) => node.remove());
});
const id = 'a1234567-1234-4321-8123-123456789abc';

it('loads one shared same-origin script for concurrent requests and only accepts committed claims', async () => {
    const claim = vi
        .fn()
        .mockResolvedValueOnce(true)
        .mockResolvedValueOnce(false)
        .mockResolvedValueOnce(null);
    const append = document.head.appendChild.bind(document.head);
    const requests: HTMLScriptElement[] = [];
    vi.spyOn(document.head, 'appendChild').mockImplementation((node) => {
        const script = node as HTMLScriptElement;
        requests.push(script);
        const result = append(node);
        queueMicrotask(() => {
            globalThis.OblivionChatSoundReceipts = { claim };
            script.dispatchEvent(new Event('load'));
        });
        return result;
    });
    const { claimChatSound } = await import('./chat-sound-receipts');
    expect(
        await Promise.all([claimChatSound([id]), claimChatSound([id])]),
    ).toEqual([true, false]);
    expect(requests).toHaveLength(1);
    expect(requests[0].getAttribute('src')).toBe('/chat-sound-receipts.js');
    expect(requests[0].isConnected).toBe(false);
    expect(await claimChatSound([id])).toBe(false);
    expect(claim.mock.calls).toEqual([[[id]], [[id]], [[id]]]);
});

it('stays silent on script failure and allows a later successful retry', async () => {
    const append = document.head.appendChild.bind(document.head);
    let attempts = 0;
    vi.spyOn(document.head, 'appendChild').mockImplementation((node) => {
        const script = node as HTMLScriptElement;
        append(node);
        queueMicrotask(() => {
            if (++attempts === 1) script.dispatchEvent(new Event('error'));
            else {
                globalThis.OblivionChatSoundReceipts = {
                    claim: async () => true,
                };
                script.dispatchEvent(new Event('load'));
            }
        });
        return node;
    });
    const { claimChatSound } = await import('./chat-sound-receipts');
    expect(await claimChatSound([id])).toBe(false);
    expect(await claimChatSound([id])).toBe(true);
    expect(attempts).toBe(2);
});

it('bounds an unavailable script load and removes its callbacks', async () => {
    vi.useFakeTimers();
    const { claimChatSound } = await import('./chat-sound-receipts');
    const pending = claimChatSound([id]);
    const script = document.head.querySelector('script') as HTMLScriptElement;
    await vi.advanceTimersByTimeAsync(5000);
    expect(await pending).toBe(false);
    expect(script.isConnected).toBe(false);
    expect(script.onload).toBeNull();
});
