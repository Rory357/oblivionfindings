import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { useRecordJson } from './use-record-json';

afterEach(() => vi.unstubAllGlobals());

describe('person record loading', () => {
    it('does not display another person’s data during navigation or an access failure', async () => {
        const fetch = vi
            .fn()
            .mockResolvedValueOnce({
                ok: true,
                json: async () => ({ person: 'Aroha' }),
            })
            .mockResolvedValueOnce({ ok: false, status: 404 });
        vi.stubGlobal('fetch', fetch);
        const hook = renderHook(
            ({ url }) => useRecordJson<{ person: string }>(url),
            { initialProps: { url: '/record/1' } },
        );
        await waitFor(() =>
            expect(hook.result.current.data?.person).toBe('Aroha'),
        );
        hook.rerender({ url: '/record/2' });
        expect(hook.result.current.data).toBeNull();
        await waitFor(() => expect(hook.result.current.load).toBe('forbidden'));
        expect(hook.result.current.data).toBeNull();
    });

    it('ignores a late response from an abandoned record', async () => {
        let finishOld: (response: unknown) => void = () => undefined;
        vi.stubGlobal(
            'fetch',
            vi
                .fn()
                .mockImplementationOnce(
                    () =>
                        new Promise((resolve) => {
                            finishOld = resolve;
                        }),
                )
                .mockResolvedValueOnce({
                    ok: true,
                    json: async () => ({ person: 'Tama' }),
                }),
        );
        const hook = renderHook(
            ({ url }) => useRecordJson<{ person: string }>(url),
            { initialProps: { url: '/record/1' } },
        );
        hook.rerender({ url: '/record/2' });
        await waitFor(() =>
            expect(hook.result.current.data?.person).toBe('Tama'),
        );
        await act(async () => {
            finishOld({ ok: true, json: async () => ({ person: 'Aroha' }) });
        });
        expect(hook.result.current.data?.person).toBe('Tama');
    });
});
