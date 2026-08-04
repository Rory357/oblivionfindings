import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

import { usePersonalLocationPrivacy } from '@/hooks/use-personal-location-privacy';

afterEach(() => {
    vi.unstubAllGlobals();
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
