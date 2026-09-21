import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

import { usePersonalLocationPrivacy } from '@/hooks/use-personal-location-privacy';

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

function pendingResponse() {
    let resolve!: (value: unknown) => void;
    const promise = new Promise((done) => {
        resolve = done;
    });
    return { promise, resolve };
}
const statusResponse = (active: boolean, fingerprint = 'one') => ({
    ok: true,
    status: 200,
    json: async () => ({ active, access_fingerprint: fingerprint }),
});

it('never restores location from an older success after a newer denial', async () => {
    const old = pendingResponse();
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockReturnValueOnce(old.promise)
            .mockResolvedValue(statusResponse(false)),
    );
    const ended = vi.fn();
    const { result } = renderHook(() =>
        usePersonalLocationPrivacy({ statusUrl: '/one', onAccessEnded: ended }),
    );
    await act(async () => {
        await result.current.recheck();
    });
    await act(async () => {
        old.resolve(statusResponse(true));
    });
    expect(result.current.active).toBe(false);
    await act(async () => {
        await result.current.recheck();
    });
    expect(result.current.active).toBe(false);
    expect(ended).toHaveBeenCalledTimes(1);
});

it('discards an old client response and permits a fresh authorised entry', async () => {
    const old = pendingResponse();
    const next = pendingResponse();
    vi.stubGlobal(
        'fetch',
        vi
            .fn()
            .mockReturnValueOnce(old.promise)
            .mockReturnValueOnce(next.promise),
    );
    const { result, rerender } = renderHook(
        ({ url }) => usePersonalLocationPrivacy({ statusUrl: url }),
        { initialProps: { url: '/one' } },
    );
    rerender({ url: '/two' });
    await act(async () => {
        old.resolve(statusResponse(true));
    });
    expect(result.current.active).toBe(false);
    await act(async () => {
        next.resolve(statusResponse(true));
    });
    expect(result.current.active).toBe(true);
});

it('rejects an assignment fingerprint mismatch until current props are reloaded', async () => {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue(statusResponse(true, 'two')),
    );
    const { result, rerender } = renderHook(
        ({ fingerprint }) =>
            usePersonalLocationPrivacy({ statusUrl: '/one', fingerprint }),
        { initialProps: { fingerprint: 'one' } },
    );
    await waitFor(() => expect(result.current.checking).toBe(false));
    expect(result.current.active).toBe(false);
    rerender({ fingerprint: 'two' });
    await waitFor(() => expect(result.current.active).toBe(true));
});

it('aborts unmounted checks and ignores their eventual response', async () => {
    const pending = pendingResponse();
    const ended = vi.fn();
    const fetchMock = vi.fn().mockReturnValue(pending.promise);
    vi.stubGlobal('fetch', fetchMock);
    const { unmount } = renderHook(() =>
        usePersonalLocationPrivacy({ statusUrl: '/one', onAccessEnded: ended }),
    );
    const signal = fetchMock.mock.calls[0][1].signal as AbortSignal;
    unmount();
    expect(signal.aborted).toBe(true);
    await act(async () => {
        pending.resolve(statusResponse(false));
    });
    expect(ended).not.toHaveBeenCalled();
});

it('keeps cached location hidden until revalidated and removes access on focus after withdrawal', async () => {
    const onAccessEnded = vi.fn();
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({ active: true, export_allowed: true }),
        })
        .mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({ active: false, export_allowed: false }),
        });
    vi.stubGlobal('fetch', fetchMock);

    const { result } = renderHook(() =>
        usePersonalLocationPrivacy({
            statusUrl: '/location/privacy-status',
            intervalMs: 60_000,
            onAccessEnded,
        }),
    );

    expect(result.current.active).toBe(false);
    await waitFor(() => expect(result.current.active).toBe(true));
    expect(fetchMock).toHaveBeenCalledWith(
        '/location/privacy-status',
        expect.objectContaining({
            cache: 'no-store',
            credentials: 'same-origin',
        }),
    );

    act(() => window.dispatchEvent(new Event('focus')));

    await waitFor(() => expect(result.current.active).toBe(false));
    expect(result.current.message).toMatch(/assignment has ended/i);
    expect(onAccessEnded).toHaveBeenCalledTimes(1);
});

it('fails closed when privacy status cannot be revalidated', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));

    const { result } = renderHook(() =>
        usePersonalLocationPrivacy({ statusUrl: '/location/privacy-status' }),
    );

    expect(result.current.active).toBe(false);
    await waitFor(() => expect(result.current.checking).toBe(false));
    expect(result.current.active).toBe(false);
    expect(result.current.message).toMatch(/hidden/i);
});
