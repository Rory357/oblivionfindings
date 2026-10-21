import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import { callRecoveryKey } from './call-recovery-journal';
import { createCallTestLocks } from './call-recovery-test-locks';
import { useCallRecovery } from './use-call-recovery';

afterEach(() => {
    cleanup();
    localStorage.clear();
    vi.unstubAllGlobals();
});
it('coalesces overlapping storage wakeups without mistaking its own lock for another tab', async () => {
    const locks = createCallTestLocks();
    const request = vi.spyOn(locks, 'request');
    vi.stubGlobal('navigator', { locks });
    const saved = {
        version: 1,
        actor: 11,
        device: crypto.randomUUID(),
        conversation: 17,
        kind: 'call',
        call: 'previous',
        invitation: 'invitation',
        stopping: 'none',
    };
    localStorage.setItem(callRecoveryKey(11), JSON.stringify(saved));
    const hook = renderHook(() => useCallRecovery(11, true));
    act(() => {
        window.dispatchEvent(
            new StorageEvent('storage', { key: callRecoveryKey(11) }),
        );
        window.dispatchEvent(
            new StorageEvent('storage', { key: callRecoveryKey(11) }),
        );
    });
    await waitFor(() => expect(hook.result.current.status).toBe('ready'));
    expect(hook.result.current.restored).toEqual(saved);
    expect(request).toHaveBeenCalledOnce();
    expect(await hook.result.current.acquire()).toBe(true);
    expect(request).toHaveBeenCalledOnce();
});
