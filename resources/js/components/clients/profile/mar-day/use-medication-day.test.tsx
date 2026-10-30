import { act, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useMedicationDay } from './use-medication-day';

vi.mock('axios', () => ({
    default: {
        get: vi.fn(),
        isAxiosError: (error: unknown) =>
            !!error && typeof error === 'object' && 'isAxiosError' in error,
    },
}));
afterEach(() => vi.resetAllMocks());

describe('medication day person boundary', () => {
    it.each([401, 403, 404])(
        'removes cached private records after a focus refresh loses access (%s)',
        async (status) => {
            vi.mocked(axios.get)
                .mockResolvedValueOnce({
                    data: {
                        date: '2026-10-04',
                        followups: [{ label: 'Private medication check' }],
                    },
                })
                .mockRejectedValueOnce({
                    isAxiosError: true,
                    response: { status },
                });
            const hook = renderHook(() => useMedicationDay(1, null));
            await waitFor(() =>
                expect(hook.result.current.load.status).toBe('ready'),
            );
            act(() => window.dispatchEvent(new Event('focus')));
            await waitFor(() =>
                expect(hook.result.current.load.status).toBe('error'),
            );
            expect(hook.result.current.load.data).toBeNull();
            hook.unmount();
        },
    );

    it('retains the same person’s last records behind a transient failure warning', async () => {
        vi.mocked(axios.get)
            .mockResolvedValueOnce({ data: { date: '2026-10-04' } })
            .mockRejectedValueOnce({
                isAxiosError: true,
                response: { status: 503 },
            });
        const hook = renderHook(() => useMedicationDay(1, null));
        await waitFor(() =>
            expect(hook.result.current.load.status).toBe('ready'),
        );
        await act(async () => hook.result.current.reload());
        expect(hook.result.current.load.status).toBe('error');
        expect(hook.result.current.load.data?.date).toBe('2026-10-04');
        hook.unmount();
    });

    it('clears the previous person immediately and ignores their delayed response', async () => {
        let finishOld: (value: unknown) => void = () => undefined;
        vi.mocked(axios.get)
            .mockResolvedValueOnce({ data: { date: 'person-one' } })
            .mockImplementationOnce(
                () =>
                    new Promise((resolve) => {
                        finishOld = resolve;
                    }),
            )
            .mockRejectedValueOnce(new Error('Access denied'));
        const hook = renderHook(
            ({ clientId, date }) => useMedicationDay(clientId, date),
            { initialProps: { clientId: 1, date: '2026-10-02' } },
        );
        await waitFor(() =>
            expect(hook.result.current.load.data?.date).toBe('person-one'),
        );
        hook.rerender({ clientId: 1, date: '2026-10-03' });
        hook.rerender({ clientId: 2, date: '2026-10-03' });
        expect(hook.result.current.load.data).toBeNull();
        await waitFor(() =>
            expect(hook.result.current.load.status).toBe('error'),
        );
        await act(async () => {
            finishOld({ data: { date: 'late-person-one' } });
        });
        expect(hook.result.current.load.data).toBeNull();
    });
});
