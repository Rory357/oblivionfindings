import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { ChatError, chatRequest } from './api';
import { startChatMessageSounds } from './chat-message-sound-session';
import { claimChatSound, readChatSoundContext } from './chat-sound-receipts';
import { registerChatSoundView } from './chat-sound-views';

const updates = vi.hoisted(() => ({
    refresh: null as null | (() => Promise<boolean>),
    stop: vi.fn(),
}));
vi.mock('./chat-update-watch', () => ({
    watchChatUpdates: (refresh: () => Promise<boolean>) => {
        updates.refresh = refresh;
        return updates.stop;
    },
}));
vi.mock('./api', async () => ({
    ...(await vi.importActual<typeof import('./api')>('./api')),
    chatRequest: vi.fn(),
}));
vi.mock('./chat-sound-receipts', () => ({
    claimChatSound: vi.fn(),
    readChatSoundContext: vi.fn(),
}));
const id = 'a1234567-1234-4321-8123-123456789abc';
const baseline = { actor_id: 1, cursor: 0, alerts: [], has_more: false };
const arrival = {
    actor_id: 1,
    cursor: 1,
    alerts: [{ id, conversation_id: 71 }],
    has_more: false,
};
const contexts: TestAudio[] = [];
const channels: TestChannel[] = [];
const cleanups: Array<() => void> = [];
class TestAudio {
    state = 'running';
    currentTime = 0;
    destination = {};
    onstatechange: null | (() => void) = null;
    resume = vi.fn(async () => {});
    close = vi.fn(async () => {
        this.state = 'closed';
    });
    nodes: Array<{
        start: ReturnType<typeof vi.fn>;
        stop: ReturnType<typeof vi.fn>;
    }> = [];
    constructor() {
        contexts.push(this);
    }
    createOscillator() {
        const node = {
            frequency: { value: 0 },
            connect: vi.fn(),
            disconnect: vi.fn(),
            start: vi.fn(),
            stop: vi.fn(),
            onended: null,
        };
        this.nodes.push(node);
        return node;
    }
    createGain() {
        return {
            gain: { setValueAtTime: vi.fn(), linearRampToValueAtTime: vi.fn() },
            connect: vi.fn(),
            disconnect: vi.fn(),
        };
    }
}
class TestChannel {
    onmessage: null | ((event: { data: unknown }) => void) = null;
    postMessage = vi.fn();
    close = vi.fn();
    constructor(public name: string) {
        channels.push(this);
    }
}
let listener: EventListener | undefined;
let state: { enabled: boolean; activeCall: boolean };
beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-18T10:00:00Z'));
    vi.clearAllMocks();
    contexts.length = channels.length = 0;
    localStorage.clear();
    updates.refresh = null;
    listener = undefined;
    state = { enabled: true, activeCall: false };
    vi.mocked(chatRequest).mockResolvedValue(baseline);
    vi.mocked(claimChatSound).mockResolvedValue(true);
    vi.mocked(readChatSoundContext).mockResolvedValue({
        active: false,
        conversations: [],
    });
    vi.stubGlobal('AudioContext', TestAudio);
    vi.stubGlobal('BroadcastChannel', TestChannel);
    vi.stubGlobal(
        'navigator',
        Object.assign(Object.create(navigator), {
            locks: {
                request: vi.fn(async (_name, _options, callback) =>
                    callback({ name: 'test' }),
                ),
            },
        }),
    );
    vi.spyOn(document, 'hasFocus').mockReturnValue(true);
    const add = window.addEventListener.bind(window);
    vi.spyOn(window, 'addEventListener').mockImplementation(
        (type, callback, options) => {
            if (type === 'pointerdown') listener = callback as EventListener;
            add(type, callback, options);
        },
    );
});
afterEach(() => {
    cleanups.splice(0).forEach((stop) => stop());
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});
function start(actor = 1) {
    const session = startChatMessageSounds(actor, () => state);
    cleanups.push(() => session.dispose());
    return session;
}
async function unlock() {
    listener?.({ isTrusted: true } as Event);
    await Promise.resolve();
    await Promise.resolve();
}
async function prime() {
    await updates.refresh?.();
    vi.setSystemTime(Date.now() + 1000);
    vi.mocked(chatRequest).mockResolvedValue(arrival);
}

it('requires a trusted gesture, starts quietly, and emits one short sound for a fresh eligible arrival', async () => {
    start();
    listener?.({ isTrusted: false } as Event);
    expect(contexts).toHaveLength(0);
    expect(chatRequest).not.toHaveBeenCalled();
    await unlock();
    await prime();
    expect(contexts[0].nodes).toHaveLength(0);
    await updates.refresh?.();
    expect(contexts[0].nodes).toHaveLength(1);
    expect(contexts[0].nodes[0].start).toHaveBeenCalledOnce();
    expect(claimChatSound).toHaveBeenCalledWith([id]);
    expect(vi.mocked(chatRequest).mock.calls.map(([url]) => url)).toEqual([
        '/chat/api/message-alerts',
        '/chat/api/message-alerts?after=0',
    ]);
    expect(
        new Headers(vi.mocked(chatRequest).mock.calls[1][1]?.headers).get(
            'X-Chat-Actor',
        ),
    ).toBe('1');
});

it.each([
    'local view',
    'other tab view',
    'other tab call',
    'this call',
    'quiet preference',
    'already sounded',
])('keeps %s silent without changing message read state', async (kind) => {
    start();
    await unlock();
    await prime();
    if (kind === 'local view') cleanups.push(registerChatSoundView(1, 71));
    if (kind === 'other tab view')
        vi.mocked(readChatSoundContext).mockResolvedValue({
            active: false,
            conversations: [71],
        });
    if (kind === 'other tab call')
        vi.mocked(readChatSoundContext).mockResolvedValue({
            active: true,
            conversations: [],
        });
    if (kind === 'this call') state.activeCall = true;
    if (kind === 'quiet preference') state.enabled = false;
    if (kind === 'already sounded')
        vi.mocked(claimChatSound).mockResolvedValue(false);
    await updates.refresh?.();
    expect(contexts[0].nodes).toHaveLength(0);
    expect(claimChatSound).toHaveBeenCalledWith([id]);
    expect(
        vi
            .mocked(chatRequest)
            .mock.calls.every(([url]) =>
                url.startsWith('/chat/api/message-alerts'),
            ),
    ).toBe(true);
});

it('responds to another tab or worker with current visible views and call state only', () => {
    const session = start();
    cleanups.push(registerChatSoundView(1, 71));
    channels[0].onmessage?.({ data: { type: 'probe', request: id } });
    expect(channels[0].postMessage).toHaveBeenLastCalledWith({
        type: 'context',
        request: id,
        active: false,
        conversations: [71],
    });
    vi.mocked(document.hasFocus).mockReturnValue(false);
    state.activeCall = true;
    channels[0].onmessage?.({ data: { type: 'probe', request: id } });
    expect(channels[0].postMessage).toHaveBeenLastCalledWith({
        type: 'context',
        request: id,
        active: true,
        conversations: [],
    });
    session.dispose();
    channels[0].onmessage?.({ data: { type: 'probe', request: id } });
    expect(channels[0].postMessage).toHaveBeenCalledTimes(2);
});

it('does not poll when another tab owns the request and coalesces a just-completed poll', async () => {
    start();
    await unlock();
    vi.mocked(navigator.locks.request).mockImplementationOnce(
        async (_name, _options, callback) => callback!(null),
    );
    await updates.refresh?.();
    expect(chatRequest).not.toHaveBeenCalled();
    await updates.refresh?.();
    await updates.refresh?.();
    expect(chatRequest).toHaveBeenCalledTimes(1);
});

it('ignores a late account response, aborts its request and closes audio on account exit', async () => {
    const session = start();
    await unlock();
    await prime();
    let resolve!: (value: unknown) => void;
    vi.mocked(chatRequest).mockImplementationOnce(
        () =>
            new Promise((done) => {
                resolve = done;
            }),
    );
    const pending = updates.refresh?.();
    await Promise.resolve();
    const signal = vi.mocked(chatRequest).mock.calls.at(-1)![1]?.signal;
    session.dispose();
    resolve(arrival);
    await pending;
    expect(signal?.aborted).toBe(true);
    expect(claimChatSound).not.toHaveBeenCalled();
    expect(contexts[0].close).toHaveBeenCalled();
});

it('drops a pending claim if the account exits or sound is disabled before playback', async () => {
    const session = start();
    await unlock();
    await prime();
    let resolve!: (value: boolean) => void;
    vi.mocked(claimChatSound).mockImplementationOnce(
        () =>
            new Promise((done) => {
                resolve = done;
            }),
    );
    const pending = updates.refresh?.();
    for (let i = 0; i < 8; i++) await Promise.resolve();
    state.enabled = false;
    session.updated();
    resolve(true);
    await pending;
    expect(contexts[0].nodes).toHaveLength(0);
});

it('stops on authentication failure and reconnects ordinary failures with a silent baseline', async () => {
    start();
    await unlock();
    await prime();
    vi.mocked(chatRequest).mockRejectedValueOnce(new Error('offline'));
    expect(await updates.refresh?.()).toBe(false);
    vi.mocked(chatRequest).mockResolvedValueOnce(arrival);
    await updates.refresh?.();
    expect(contexts[0].nodes).toHaveLength(0);
    vi.setSystemTime(Date.now() + 1000);
    vi.mocked(chatRequest).mockRejectedValueOnce(
        new ChatError('Account changed', 403),
    );
    await updates.refresh?.();
    expect(updates.stop).toHaveBeenCalled();
    const calls = contexts[0].resume.mock.calls.length;
    await unlock();
    expect(contexts[0].resume).toHaveBeenCalledTimes(calls);
});

it.each([
    { actor_id: 2 },
    { cursor: -1 },
    { alerts: [{ id: [id], conversation_id: 71 }] },
    { has_more: 'yes' },
])('rejects malformed or wrong-account arrivals %j', async (patch) => {
    start();
    await unlock();
    await prime();
    vi.mocked(chatRequest).mockResolvedValue({ ...arrival, ...patch });
    expect(await updates.refresh?.()).toBe(false);
    expect(claimChatSound).not.toHaveBeenCalled();
    expect(contexts[0].nodes).toHaveLength(0);
});

it('bounds a busy catch-up to five pages and claims notices in bounded batches with one sound', async () => {
    start();
    await unlock();
    await prime();
    vi.mocked(chatRequest).mockImplementation(async (url) => {
        const after = Number(
            new URL(url, 'https://example.test').searchParams.get('after'),
        );
        return {
            ...arrival,
            cursor: after + 100,
            has_more: true,
            alerts: Array.from({ length: 100 }, (_, index) => ({
                id: `a1234567-1234-4321-8123-${String(after + index).padStart(12, '0')}`,
                conversation_id: 71,
            })),
        };
    });
    await updates.refresh?.();
    expect(chatRequest).toHaveBeenCalledTimes(6);
    expect(claimChatSound).toHaveBeenCalledTimes(5);
    expect(
        vi
            .mocked(claimChatSound)
            .mock.calls.every(([ids]) => ids.length === 100),
    ).toBe(true);
    expect(contexts[0].nodes).toHaveLength(1);
    expect(
        JSON.parse(localStorage.getItem('oblivion:chat-arrivals:v1:1')!),
    ).toMatchObject({ cursor: 500 });
});

it('does not play a response completed after audio suspension and restarts quietly after a stale cursor', async () => {
    start();
    await unlock();
    await prime();
    let resolve!: (value: unknown) => void;
    vi.mocked(chatRequest).mockImplementationOnce(
        () =>
            new Promise((done) => {
                resolve = done;
            }),
    );
    const pending = updates.refresh?.();
    await Promise.resolve();
    const signal = vi.mocked(chatRequest).mock.calls.at(-1)![1]?.signal;
    contexts[0].state = 'suspended';
    contexts[0].onstatechange?.();
    expect(signal?.aborted).toBe(true);
    resolve(arrival);
    await pending;
    expect(claimChatSound).not.toHaveBeenCalled();
    contexts[0].state = 'running';
    vi.setSystemTime(Date.now() + 20000);
    await unlock();
    await updates.refresh?.();
    expect(vi.mocked(chatRequest).mock.calls.at(-1)?.[0]).toBe(
        '/chat/api/message-alerts',
    );
    expect(contexts[0].nodes).toHaveLength(0);
});
