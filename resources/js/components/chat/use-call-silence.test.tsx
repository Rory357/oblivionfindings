import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useCallSilence } from './use-call-silence';

type Message = {
    type: string;
    call: string;
    silent?: boolean;
    revision?: number;
    source?: string;
};
class Channel {
    static peers: Channel[] = [];
    static pending: { target: Channel; data: Message }[] = [];
    onmessage: ((event: { data: Message }) => void) | null = null;
    closed = false;
    constructor(readonly name: string) {
        Channel.peers.push(this);
    }
    postMessage(data: Message) {
        for (const target of Channel.peers)
            if (target !== this && !target.closed && target.name === this.name)
                Channel.pending.push({ target, data });
    }
    close() {
        this.closed = true;
    }
}
function drain() {
    act(() => {
        while (Channel.pending.length) {
            const message = Channel.pending.shift()!;
            if (!message.target.closed)
                message.target.onmessage?.({ data: message.data });
        }
    });
}
function deliver(message: { target: Channel; data: Message }) {
    act(() => message.target.onmessage?.({ data: message.data }));
}
beforeEach(() => {
    Channel.peers = [];
    Channel.pending = [];
    vi.stubGlobal('BroadcastChannel', Channel);
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

it('shares silence and unmute across open tabs without changing the next invitation', () => {
    const first = renderHook(() => useCallSilence(11, 'call:one'));
    const second = renderHook(({ call }) => useCallSilence(11, call), {
        initialProps: { call: 'call:one' },
    });
    drain();
    act(() => first.result.current.toggle());
    drain();
    expect(second.result.current.silent).toBe(true);
    act(() => second.result.current.toggle());
    drain();
    expect(first.result.current.silent).toBe(false);
    act(() => first.result.current.toggle());
    drain();
    second.rerender({ call: 'call:two' });
    drain();
    expect(second.result.current.silent).toBe(false);
    expect(first.result.current.silent).toBe(true);
});
it('does not let a delayed snapshot overwrite a newer unmute choice', () => {
    const first = renderHook(() => useCallSilence(11, 'call:one'));
    act(() => first.result.current.toggle());
    const second = renderHook(() => useCallSilence(11, 'call:one'));
    const sync = Channel.pending.shift()!;
    deliver(sync);
    const snapshot = Channel.pending.shift()!;
    expect(snapshot.data.silent).toBe(true);
    act(() => first.result.current.toggle());
    const newer = Channel.pending.shift()!;
    deliver(newer);
    deliver(snapshot);
    expect(second.result.current.silent).toBe(false);
    expect(newer.data.revision).toBeGreaterThan(snapshot.data.revision!);
});
it('uses the latest received revision when another tab changes sound', () => {
    const first = renderHook(() => useCallSilence(11, 'call:one'));
    const second = renderHook(() => useCallSilence(11, 'call:one'));
    const third = renderHook(() => useCallSilence(11, 'call:one'));
    drain();
    act(() => first.result.current.toggle());
    const toSecond = Channel.pending.shift()!;
    const delayed = Channel.pending.shift()!;
    deliver(toSecond);
    act(() => second.result.current.toggle());
    drain();
    deliver(delayed);
    expect(first.result.current.silent).toBe(false);
    expect(third.result.current.silent).toBe(false);
});
it('ignores queued messages after account change and isolates account channels', () => {
    const first = renderHook(() => useCallSilence(11, 'call:one'));
    const second = renderHook(
        ({ actor }) => useCallSilence(actor, 'call:one'),
        { initialProps: { actor: 11 } },
    );
    drain();
    act(() => first.result.current.toggle());
    const delayed = Channel.pending.shift()!;
    second.rerender({ actor: 33 });
    deliver(delayed);
    drain();
    expect(second.result.current.silent).toBe(false);
    act(() => first.result.current.toggle());
    act(() => first.result.current.toggle());
    drain();
    expect(second.result.current.silent).toBe(false);
});
it('rejects malformed and unversioned state messages', () => {
    const view = renderHook(() => useCallSilence(11, 'call:one'));
    const target = Channel.peers[0];
    for (const data of [
        { type: 'state', call: 'call:one', silent: true },
        {
            type: 'state',
            call: 'call:one',
            silent: true,
            revision: -1,
            source: crypto.randomUUID(),
        },
        {
            type: 'state',
            call: 'call:other',
            silent: true,
            revision: 1,
            source: crypto.randomUUID(),
        },
        {
            type: 'state',
            call: 'call:one',
            silent: true,
            revision: 1,
            source: 'invalid',
        },
    ])
        deliver({ target, data });
    expect(view.result.current.silent).toBe(false);
});
it('still supports local silence when peer messaging is unavailable', () => {
    vi.stubGlobal('BroadcastChannel', undefined);
    const view = renderHook(() => useCallSilence(11, 'call:one'));
    act(() => view.result.current.toggle());
    expect(view.result.current.silent).toBe(true);
    act(() => view.result.current.toggle());
    expect(view.result.current.silent).toBe(false);
});
