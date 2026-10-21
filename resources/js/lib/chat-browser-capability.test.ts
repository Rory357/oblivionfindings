import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { verifyChatBrowserWorker } from './chat-browser-capability';

class FakeChannel {
    static latest: FakeChannel;
    port1 = {
        onmessage: null as ((event: { data: unknown }) => void) | null,
        close: vi.fn(),
    };
    port2 = { close: vi.fn() };
    constructor() {
        FakeChannel.latest = this;
    }
}
const postMessage = vi.fn();
let worker: { state: string; postMessage: typeof postMessage };
let registration: ServiceWorkerRegistration;
beforeEach(() => {
    vi.resetAllMocks();
    vi.useFakeTimers();
    vi.stubGlobal('MessageChannel', FakeChannel);
    worker = { state: 'activated', postMessage };
    registration = { active: worker } as unknown as ServiceWorkerRegistration;
});
afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});
function reply(version = 2, nonce = postMessage.mock.calls[0][0].nonce) {
    FakeChannel.latest.port1.onmessage?.({
        data: { type: 'chat-worker-ready', nonce, version },
    });
}

it('requires the active worker to answer the exact challenge and closes the channel', async () => {
    const pending = verifyChatBrowserWorker(registration);
    expect(postMessage.mock.calls[0][0].type).toBe('chat-worker-capability');
    expect(postMessage.mock.calls[0][1]).toEqual([FakeChannel.latest.port2]);
    reply(2, 'another-challenge');
    expect(FakeChannel.latest.port1.close).not.toHaveBeenCalled();
    reply();
    await expect(pending).resolves.toBe(2);
    expect(FakeChannel.latest.port1.close).toHaveBeenCalledOnce();
    expect(FakeChannel.latest.port2.close).toHaveBeenCalledOnce();
    expect(vi.getTimerCount()).toBe(0);
});

it('rejects an older worker protocol', async () => {
    const pending = verifyChatBrowserWorker(registration);
    const rejected = expect(pending).rejects.toThrow('updating');
    reply(1);
    await rejected;
});

it('rejects a response from a worker replaced during the handshake', async () => {
    const pending = verifyChatBrowserWorker(registration);
    const rejected = expect(pending).rejects.toThrow('updating');
    Object.assign(registration, {
        active: { state: 'activated', postMessage: vi.fn() },
    });
    reply();
    await rejected;
});

it('times out an old worker that does not understand the message', async () => {
    const pending = verifyChatBrowserWorker(registration);
    const rejected = expect(pending).rejects.toThrow('Refresh this page');
    await vi.advanceTimersByTimeAsync(5000);
    await rejected;
    expect(FakeChannel.latest.port1.close).toHaveBeenCalledOnce();
    expect(vi.getTimerCount()).toBe(0);
});

it('rejects a missing active worker before sending anything', async () => {
    await expect(
        verifyChatBrowserWorker({ active: null } as ServiceWorkerRegistration),
    ).rejects.toThrow('updating');
    expect(postMessage).not.toHaveBeenCalled();
});

it('closes the channel when the worker refuses the message', async () => {
    postMessage.mockImplementation(() => {
        throw new Error('Detached worker');
    });
    await expect(verifyChatBrowserWorker(registration)).rejects.toThrow(
        'updating',
    );
    expect(FakeChannel.latest.port2.close).toHaveBeenCalledOnce();
    expect(vi.getTimerCount()).toBe(0);
});
