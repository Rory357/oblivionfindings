import { act, renderHook } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import {
    useTimesheetFilters,
    type TimesheetFilters,
} from './use-timesheet-filters';
const { get } = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { get } }));
const server: TimesheetFilters = {
    tab: 'submitted',
    from: null,
    to: null,
    client_id: 4,
    staff_id: 7,
    search: '',
    page: 3,
};
beforeEach(() => get.mockReset());
it('keeps all weeks by default and sends literal current search with identities', () => {
    const { result } = renderHook(() => useTimesheetFilters(server));
    act(() => {
        result.current.editSearch('  50% _ māia  ');
        result.current.change({ tab: 'returned' });
    });
    expect(get.mock.calls[0][1]).toEqual({
        ...server,
        search: '50% _ māia',
        tab: 'returned',
        page: 1,
    });
});
it('retains an explicit week when selecting a destination or page', () => {
    const filters = { ...server, from: '2026-10-05', to: '2026-10-11' };
    const { result } = renderHook(() => useTimesheetFilters(filters));
    act(() => result.current.page(4));
    expect(get.mock.calls[0][1]).toEqual({ ...filters, page: 4 });
});
it('starts at page one when a new search is applied by pagination', () => {
    const { result } = renderHook(() => useTimesheetFilters(server));
    act(() => {
        result.current.editSearch('new');
        result.current.page(4);
    });
    expect(get.mock.calls[0][1].page).toBe(1);
});
it('protects in-flight choices against a second event', () => {
    const { result } = renderHook(() => useTimesheetFilters(server));
    act(() => {
        result.current.change({ tab: 'draft' });
        result.current.change({ client_id: 99 });
    });
    expect(get).toHaveBeenCalledOnce();
    expect(result.current.draft.client_id).toBe(4);
});
it('retains failed filters and retries without pretending the list is empty', () => {
    const { result } = renderHook(() => useTimesheetFilters(server));
    act(() => result.current.change({ from: '2026-10-05', to: '2026-10-11' }));
    act(() => {
        get.mock.calls[0][2].onError({ from: 'Could not load' });
        get.mock.calls[0][2].onFinish();
    });
    expect(result.current.error).toContain('Could not load');
    act(() => result.current.retry());
    expect(get.mock.calls[1][1]).toEqual(get.mock.calls[0][1]);
});
it('reports an interrupted read and cancels its read when leaving', () => {
    const { result, unmount } = renderHook(() => useTimesheetFilters(server));
    const cancel = vi.fn();
    act(() => result.current.change());
    get.mock.calls[0][2].onCancelToken({ cancel });
    act(() => get.mock.calls[0][2].onFinish());
    expect(result.current.error).toContain('Could not load');
    act(() => result.current.retry());
    get.mock.calls[1][2].onCancelToken({ cancel });
    unmount();
    expect(cancel).toHaveBeenCalledOnce();
});
it('restores controls from normalized server and browser-history filters', () => {
    const { result, rerender } = renderHook(
        ({ filters }) => useTimesheetFilters(filters),
        { initialProps: { filters: server } },
    );
    act(() => result.current.editSearch('draft search'));
    rerender({ filters: { ...server, tab: 'paid', page: 1 } });
    expect(result.current.draft).toEqual({ ...server, tab: 'paid', page: 1 });
});
it('clears dates as well as identity, search and status filters', () => {
    const { result } = renderHook(() =>
        useTimesheetFilters({
            ...server,
            from: '2026-10-05',
            to: '2026-10-11',
        }),
    );
    act(() => result.current.clear());
    expect(get.mock.calls[0][1]).toEqual({
        tab: 'all',
        from: null,
        to: null,
        staff_id: null,
        client_id: null,
        search: '',
        page: 1,
    });
});
it('uses Unicode character limits and keeps invalid searches for correction', () => {
    const { result } = renderHook(() => useTimesheetFilters(server));
    act(() => {
        result.current.editSearch('🌿'.repeat(256));
        result.current.change();
    });
    expect(get).not.toHaveBeenCalled();
    expect(result.current.error).toContain('255');
    act(() => {
        result.current.editSearch('🌿'.repeat(255));
        result.current.change();
    });
    expect(get).toHaveBeenCalledOnce();
});
