import { act, renderHook } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import {
    useHandoverFilters,
    type HandoverFilters,
} from './use-handover-filters';
const { get } = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { get } }));
const server: HandoverFilters = {
    week: '2026-10-05',
    q: '',
    staff: 7,
    client: 4,
    site: 2,
    status: 'submitted',
    page: 3,
};
beforeEach(() => get.mockReset());

it('sends the latest typed search together with the selected week and identities and resets the page', () => {
    const { result } = renderHook(() => useHandoverFilters(server));
    act(() => {
        result.current.editSearch('  rāhui  ');
        result.current.change({ week: '2026-10-12' });
    });
    expect(get.mock.calls[0][1]).toEqual({
        ...server,
        q: 'rāhui',
        week: '2026-10-12',
        page: 1,
    });
});
it('prevents two reads in the same event and leaves the first request context intact', () => {
    const { result } = renderHook(() => useHandoverFilters(server));
    act(() => {
        result.current.change({ status: 'draft' });
        result.current.change({ site: 999 });
    });
    expect(get).toHaveBeenCalledOnce();
    expect(result.current.draft.site).toBe(2);
    expect(result.current.loading).toBe(true);
});
it('retains failed choices and retries them without dropping people filters', () => {
    const { result } = renderHook(() => useHandoverFilters(server));
    act(() => result.current.change({ status: 'acknowledged' }));
    act(() => {
        get.mock.calls[0][2].onError({ week: 'Try again later' });
        get.mock.calls[0][2].onFinish();
    });
    expect(result.current.error).toBe('Try again later');
    act(() => result.current.retry());
    expect(get.mock.calls[1][1]).toEqual({
        ...server,
        status: 'acknowledged',
        page: 1,
    });
});
it('does not treat a finished read without a successful response as an empty list', () => {
    const { result } = renderHook(() => useHandoverFilters(server));
    act(() => result.current.change());
    act(() => get.mock.calls[0][2].onFinish());
    expect(result.current.error).toContain('Could not load');
    expect(result.current.loading).toBe(false);
});
it('resynchronizes controls on a server-normalized or browser-history filter change', () => {
    const { result, rerender } = renderHook(
        ({ filters }) => useHandoverFilters(filters),
        { initialProps: { filters: server } },
    );
    act(() => result.current.editSearch('pending search'));
    rerender({
        filters: { ...server, q: 'earlier', week: '2026-09-28', page: 2 },
    });
    expect(result.current.draft).toEqual({
        ...server,
        q: 'earlier',
        week: '2026-09-28',
        page: 2,
    });
});
it('clears all search/entity/status filters together while retaining the selected week', () => {
    const { result } = renderHook(() => useHandoverFilters(server));
    act(() => result.current.clear());
    expect(get.mock.calls[0][1]).toEqual({
        week: server.week,
        q: '',
        staff: null,
        client: null,
        site: null,
        status: 'all',
        page: 1,
    });
});
it('cancels its owned read on unmount and safely ignores later completion', () => {
    const { result, unmount } = renderHook(() => useHandoverFilters(server));
    const cancel = vi.fn();
    act(() => result.current.change());
    get.mock.calls[0][2].onCancelToken({ cancel });
    unmount();
    expect(cancel).toHaveBeenCalledOnce();
    act(() => get.mock.calls[0][2].onFinish());
});
it('counts Unicode code points for the search limit and retains an invalid search for correction', () => {
    const { result } = renderHook(() => useHandoverFilters(server));
    act(() => {
        result.current.editSearch('🌿'.repeat(101));
        result.current.change();
    });
    expect(get).not.toHaveBeenCalled();
    expect(result.current.error).toContain('100 characters');
    act(() => {
        result.current.editSearch('🌿'.repeat(100));
        result.current.change();
    });
    expect(get).toHaveBeenCalledOnce();
});
