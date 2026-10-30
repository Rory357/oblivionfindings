import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useRecordJson } from './use-record-json';

/* EA-208 (Lane C9): recording a dose reloads the record. The reload keeps
 * what is on screen, and a failed reload keeps it too, marked stale. */
const okResponse = (body: unknown) =>
    Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve(body),
    } as Response);

describe('useRecordJson refresh', () => {
    afterEach(() => vi.unstubAllGlobals());

    it('keeps the records while refreshing and when a refresh fails', async () => {
        const fetchMock = vi
            .fn()
            .mockImplementationOnce(() => okResponse({ rows: [1, 2] }));
        vi.stubGlobal('fetch', fetchMock);
        const { result } = renderHook(() =>
            useRecordJson<{ rows: number[] }>('/emar/clients/4/record/week'),
        );
        await waitFor(() => expect(result.current.load).toBe('ready'));
        expect(result.current.data).toEqual({ rows: [1, 2] });

        let fail: (reason: unknown) => void = () => {};
        fetchMock.mockImplementationOnce(
            () => new Promise<Response>((_, reject) => (fail = reject)),
        );
        act(() => result.current.reload());
        await waitFor(() => expect(result.current.refreshing).toBe(true));
        expect(result.current.load).toBe('ready');
        expect(result.current.data).toEqual({ rows: [1, 2] });

        await act(async () => fail(new Error('offline')));
        await waitFor(() => expect(result.current.stale).toBe(true));
        expect(result.current.load).toBe('ready');
        expect(result.current.data).toEqual({ rows: [1, 2] });
        expect(result.current.loadedAt).not.toBeNull();
    });

    it('still starts empty for another person', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => okResponse({ rows: [9] })),
        );
        const { result, rerender } = renderHook(
            ({ url }) => useRecordJson<{ rows: number[] }>(url),
            {
                initialProps: { url: '/emar/clients/4/record/week' },
            },
        );
        await waitFor(() => expect(result.current.load).toBe('ready'));
        rerender({ url: '/emar/clients/5/record/week' });
        expect(result.current.data).toBeNull();
        expect(result.current.load).toBe('loading');
    });
});
