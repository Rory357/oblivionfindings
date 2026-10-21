import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createCallTestLocks } from './call-recovery-test-locks';
import { useCallRingtone } from './use-call-ringtone';

class FakeAudio {
    static instances: FakeAudio[] = [];
    state: AudioContextState = 'suspended';
    currentTime = 0;
    destination = {};
    onstatechange: (() => void) | null = null;
    resume = vi.fn(async () => {
        this.state = 'running';
        this.onstatechange?.();
    });
    close = vi.fn(async () => {
        this.state = 'closed';
        this.onstatechange?.();
    });
    nodes: {
        stop: ReturnType<typeof vi.fn>;
        start: ReturnType<typeof vi.fn>;
    }[] = [];
    createOscillator = vi.fn(() => {
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
    });
    createGain = vi.fn(() => ({
        gain: { setValueAtTime: vi.fn(), linearRampToValueAtTime: vi.fn() },
        connect: vi.fn(),
        disconnect: vi.fn(),
    }));
    constructor() {
        FakeAudio.instances.push(this);
    }
}
beforeEach(() => {
    vi.useFakeTimers();
    FakeAudio.instances = [];
    vi.stubGlobal('AudioContext', FakeAudio);
    vi.stubGlobal('navigator', { locks: createCallTestLocks() });
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});
const flush = () => act(async () => undefined);
describe('shared incoming-call ringtone', () => {
    it('requires an explicit sound unlock and rings in only one tab per actor', async () => {
        const first = renderHook(() =>
            useCallRingtone(11, 'call:invite', true),
        );
        const second = renderHook(() =>
            useCallRingtone(11, 'call:invite', true),
        );
        expect(FakeAudio.instances).toHaveLength(0);
        await act(async () => {
            await first.result.current.enable();
            await second.result.current.enable();
        });
        await flush();
        expect(FakeAudio.instances[0].createOscillator).toHaveBeenCalledTimes(
            2,
        );
        expect(FakeAudio.instances[1].createOscillator).not.toHaveBeenCalled();
        await act(async () => vi.advanceTimersByTime(6000));
        expect(FakeAudio.instances[0].createOscillator).toHaveBeenCalledTimes(
            6,
        );
        expect(FakeAudio.instances[1].createOscillator).not.toHaveBeenCalled();
    });
    it('hands over immediately after the owner closes without waiting for a polling timer', async () => {
        const first = renderHook(() =>
            useCallRingtone(11, 'call:invite', true),
        );
        const second = renderHook(() =>
            useCallRingtone(11, 'call:invite', true),
        );
        await act(async () => {
            await first.result.current.enable();
            await second.result.current.enable();
        });
        await flush();
        first.unmount();
        await flush();
        expect(FakeAudio.instances[0].close).toHaveBeenCalledOnce();
        expect(FakeAudio.instances[1].createOscillator).toHaveBeenCalledTimes(
            2,
        );
    });
    it('cancels a queued ringtone when silenced or answered before ownership arrives', async () => {
        const first = renderHook(() =>
            useCallRingtone(11, 'call:invite', true),
        );
        const second = renderHook(
            ({ enabled }) => useCallRingtone(11, 'call:invite', enabled),
            { initialProps: { enabled: true } },
        );
        await act(async () => {
            await first.result.current.enable();
            await second.result.current.enable();
        });
        await flush();
        second.rerender({ enabled: false });
        first.unmount();
        await flush();
        await act(async () => vi.advanceTimersByTime(9000));
        expect(FakeAudio.instances[1].createOscillator).not.toHaveBeenCalled();
    });
    it('stops sounding nodes when the invitation ends and never reuses audio for a changed account', async () => {
        const view = renderHook(
            ({ actor, call }) => useCallRingtone(actor, call, true),
            {
                initialProps: {
                    actor: 11,
                    call: 'old:invitation' as string | null,
                },
            },
        );
        await act(async () => {
            await view.result.current.enable();
        });
        await flush();
        const old = FakeAudio.instances[0];
        view.rerender({ actor: 11, call: null });
        await flush();
        expect(
            old.nodes.every((node) => node.stop.mock.calls.length === 2),
        ).toBe(true);
        view.rerender({ actor: 33, call: 'new:invitation' });
        await flush();
        expect(old.close).toHaveBeenCalledOnce();
        expect(view.result.current.ready).toBe(false);
        await act(async () => vi.advanceTimersByTime(9000));
        expect(FakeAudio.instances).toHaveLength(1);
    });
    it('releases ownership if browser audio becomes suspended', async () => {
        const first = renderHook(() =>
            useCallRingtone(11, 'call:invite', true),
        );
        const second = renderHook(() =>
            useCallRingtone(11, 'call:invite', true),
        );
        await act(async () => {
            await first.result.current.enable();
            await second.result.current.enable();
        });
        await flush();
        act(() => {
            FakeAudio.instances[0].state = 'suspended';
            FakeAudio.instances[0].onstatechange?.();
        });
        await flush();
        expect(first.result.current.ready).toBe(false);
        expect(FakeAudio.instances[1].createOscillator).toHaveBeenCalledTimes(
            2,
        );
    });
    it('keeps the visual-only fallback when the browser cannot coordinate sound', async () => {
        vi.stubGlobal('navigator', {});
        const view = renderHook(() => useCallRingtone(11, 'call:invite', true));
        await act(async () =>
            expect(await view.result.current.enable()).toBe(false),
        );
        expect(FakeAudio.instances).toHaveLength(0);
        expect(view.result.current.ready).toBe(false);
    });
});
