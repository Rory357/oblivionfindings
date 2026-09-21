import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useRecordedLocationRefresh } from './use-recorded-location-refresh';
const reload = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', () => ({ router: { reload } }));
beforeEach(() => {
    vi.useFakeTimers();
    reload.mockReset();
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.restoreAllMocks();
});
it('refreshes only location props without overlapping visits and preserves local editor/history state', () => {
    renderHook(() => useRecordedLocationRefresh(true, 'client:one'));
    act(() => vi.advanceTimersByTime(30_000));
    expect(reload).toHaveBeenCalledTimes(1);
    expect(reload.mock.calls[0][0]).toMatchObject({
        only: ['location'],
        preserveState: true,
        preserveScroll: true,
    });
    act(() => vi.advanceTimersByTime(60_000));
    expect(reload).toHaveBeenCalledTimes(1);
    act(() => reload.mock.calls[0][0].onFinish());
    act(() => vi.advanceTimersByTime(30_000));
    expect(reload).toHaveBeenCalledTimes(2);
});
it('cancels in-flight refresh on pause and starts a fresh cycle when resumed', () => {
    const view = renderHook(
        ({ enabled }) => useRecordedLocationRefresh(enabled, 'client:one'),
        { initialProps: { enabled: true } },
    );
    act(() => vi.advanceTimersByTime(30_000));
    const cancel = vi.fn();
    reload.mock.calls[0][0].onCancelToken({ cancel });
    view.rerender({ enabled: false });
    expect(cancel).toHaveBeenCalledOnce();
    act(() => vi.advanceTimersByTime(60_000));
    expect(reload).toHaveBeenCalledTimes(1);
    view.rerender({ enabled: true });
    act(() => vi.advanceTimersByTime(30_000));
    expect(reload).toHaveBeenCalledTimes(2);
});
it('cancels on hidden, access change and unmount, including cancellation tokens delivered late', () => {
    const view = renderHook(
        ({ key }) => useRecordedLocationRefresh(true, key),
        { initialProps: { key: 'client:one' } },
    );
    act(() => vi.advanceTimersByTime(30_000));
    const oldVisit = reload.mock.calls[0][0];
    view.rerender({ key: 'client:changed' });
    const lateCancel = vi.fn();
    oldVisit.onCancelToken({ cancel: lateCancel });
    expect(lateCancel).toHaveBeenCalledOnce();
    act(() => vi.advanceTimersByTime(30_000));
    const cancel = vi.fn();
    reload.mock.calls[1][0].onCancelToken({ cancel });
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
    act(() => document.dispatchEvent(new Event('visibilitychange')));
    expect(cancel).toHaveBeenCalledOnce();
    act(() => {
        oldVisit.onFinish();
        vi.advanceTimersByTime(60_000);
    });
    expect(reload).toHaveBeenCalledTimes(2);
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    act(() => vi.advanceTimersByTime(30_000));
    const lastCancel = vi.fn();
    reload.mock.calls[2][0].onCancelToken({ cancel: lastCancel });
    view.unmount();
    expect(lastCancel).toHaveBeenCalledOnce();
    act(() => vi.advanceTimersByTime(60_000));
    expect(reload).toHaveBeenCalledTimes(3);
});
