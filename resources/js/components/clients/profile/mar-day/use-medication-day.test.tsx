import { act, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useMedicationDay } from './use-medication-day';

vi.mock('axios', () => ({ default: { get: vi.fn() } }));
afterEach(() => vi.resetAllMocks());

describe('medication day person boundary', () => {
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
