import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { useLiveRefresh } from './use-live-refresh';

const mocks = vi.hoisted(() => ({ reload: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { reload: mocks.reload } }));

describe('Live refresh freshness', () => {
    afterEach(() => { vi.useRealTimers(); mocks.reload.mockReset(); });

    it('keeps the last successful timestamp after a failed refresh, then recovers on success', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-09-12T00:00:00Z'));
        const { result } = renderHook(() => useLiveRefresh({ enabled: false }));
        const initial = result.current.lastUpdatedAt.getTime();
        vi.setSystemTime(new Date('2026-09-12T00:02:00Z'));
        act(() => result.current.refreshNow());
        const failure = mocks.reload.mock.calls[0][0];
        act(() => failure.onFinish());
        expect(result.current.lastUpdatedAt.getTime()).toBe(initial);
        expect(result.current.hasError).toBe(true);
        expect(result.current.isRefreshing).toBe(false);
        act(() => result.current.refreshNow());
        const success = mocks.reload.mock.calls[1][0];
        act(() => { success.onSuccess(); success.onFinish(); });
        expect(result.current.lastUpdatedAt.getTime()).toBe(Date.now());
        expect(result.current.hasError).toBe(false);
    });
});
