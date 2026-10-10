import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useBoardFilters } from './use-board-filters';

const { get } = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { get } }));

function callbacks() {
    return get.mock.calls.at(-1)![2] as {
        onSuccess: () => void;
        onError: () => void;
        onFinish: () => void;
        onCancelToken: (token: { cancel: () => void }) => void;
    };
}

beforeEach(() => {
    get.mockReset();
});

describe('Job Board retained searches', () => {
    it('retains typing through a same-query render and applies it only on Search', () => {
        const { result, rerender } = renderHook(
            ({ server }) => useBoardFilters(server, '5'),
            { initialProps: { server: { q: '', week: '2026-10-05' } } },
        );
        act(() => result.current.editSearch('North House'));
        rerender({ server: { q: '', week: '2026-10-05' } });
        expect(result.current.draft.q).toBe('North House');
        expect(get).not.toHaveBeenCalled();
        act(() => result.current.change());
        expect(get).toHaveBeenCalledWith(
            '/operations/job-board',
            { q: 'North House', week: '2026-10-05' },
            expect.any(Object),
        );
    });

    it('applies the retained search together with a newly selected week, with one read in flight', () => {
        const { result } = renderHook(() =>
            useBoardFilters({ week: '2026-10-05' }, '5'),
        );
        act(() => result.current.editSearch('  North  '));
        act(() => result.current.change({ week: '2026-10-12' }));
        act(() => {
            result.current.editSearch('lost text');
            result.current.change({ scope: 'all' });
        });
        expect(get).toHaveBeenCalledTimes(1);
        expect(get.mock.calls[0][1]).toEqual({
            q: 'North',
            week: '2026-10-12',
        });
        expect(result.current.draft.q).toBe('North');
        expect(result.current.loading).toBe(true);
    });

    it('retains a failed read for a deliberate retry without a delayed automatic search', () => {
        const { result } = renderHook(() => useBoardFilters({}, '5'));
        act(() => result.current.editSearch('North'));
        act(() => result.current.change({ skill: 'Hoist' }));
        act(() => {
            callbacks().onError();
            callbacks().onFinish();
        });
        expect(result.current.loading).toBe(false);
        expect(result.current.error).toMatch(/not updated/);
        expect(result.current.draft).toEqual({ q: 'North', skill: 'Hoist' });
        expect(get).toHaveBeenCalledTimes(1);
        act(() => result.current.change());
        expect(get).toHaveBeenCalledTimes(2);
        expect(get.mock.calls[1][1]).toEqual({ q: 'North', skill: 'Hoist' });
    });

    it('clears the search by submitting an empty query and leaves My claims free of a stale status', () => {
        const { result } = renderHook(() =>
            useBoardFilters(
                { q: 'North', status: 'open', skill: 'Hoist' },
                '5',
            ),
        );
        act(() => result.current.editSearch(''));
        act(() => result.current.change({ scope: 'mine', skill: undefined }));
        expect(get.mock.calls[0][1]).toEqual({ scope: 'mine' });
    });

    it('does not let callbacks from a prior account clear a current pending search', () => {
        const { result, rerender } = renderHook(
            ({ actor }) => useBoardFilters({}, actor),
            { initialProps: { actor: '5' } },
        );
        act(() => result.current.editSearch('Account A query'));
        act(() => result.current.change());
        const previous = callbacks();
        const cancel = vi.fn();
        act(() => previous.onCancelToken({ cancel }));
        rerender({ actor: '8' });
        expect(cancel).toHaveBeenCalledOnce();
        expect(result.current.draft.q).toBeUndefined();
        act(() => result.current.editSearch('Account B query'));
        act(() => result.current.change());
        act(() => {
            previous.onSuccess();
            previous.onFinish();
            previous.onError();
        });
        expect(result.current.loading).toBe(true);
        expect(result.current.error).toBeNull();
        expect(result.current.draft.q).toBe('Account B query');
    });

    it('accepts a new loaded query without retaining the old search or old read error', () => {
        const { result, rerender } = renderHook(
            ({ q }) => useBoardFilters({ q }, '5'),
            { initialProps: { q: 'North' } },
        );
        act(() => result.current.editSearch('East'));
        rerender({ q: 'West' });
        expect(result.current.draft.q).toBe('West');
        expect(result.current.error).toBeNull();
    });

    it('retains an oversized search and a synchronous failure, without inventing a loaded result', () => {
        const { result } = renderHook(() => useBoardFilters({}, '5'));
        act(() => result.current.editSearch('x'.repeat(256)));
        act(() => result.current.change());
        expect(get).not.toHaveBeenCalled();
        expect(result.current.draft.q).toHaveLength(256);
        expect(result.current.error).toMatch(/255 characters/);
        get.mockImplementation(() => {
            throw new Error('offline');
        });
        act(() => result.current.editSearch('North'));
        act(() => result.current.change());
        expect(result.current.loading).toBe(false);
        expect(result.current.draft.q).toBe('North');
        expect(result.current.error).toMatch(/not updated/);
    });

    it('preserves existing searches up to the server limit of 255 characters', () => {
        const { result } = renderHook(() => useBoardFilters({}, '5'));
        act(() => result.current.editSearch('x'.repeat(255)));
        act(() => result.current.change());
        expect(get).toHaveBeenCalledOnce();
        expect(get.mock.calls[0][1].q).toHaveLength(255);
    });

    it('paginates with the retained query, serializes reads, and resets to page one for new filters', () => {
        const { result } = renderHook(() =>
            useBoardFilters({ q: 'Old', skill: 'Hoist' }, '5'),
        );
        act(() => result.current.editSearch('North House'));
        act(() => result.current.goPage('/operations/job-board?page=2&q=Old'));
        expect(get.mock.calls[0][1]).toEqual({
            q: 'North House',
            skill: 'Hoist',
            page: '2',
        });
        act(() => result.current.goPage('/operations/job-board?page=3'));
        expect(get).toHaveBeenCalledOnce();
        act(() => {
            callbacks().onSuccess();
            callbacks().onFinish();
        });
        act(() => result.current.change({ scope: 'all' }));
        expect(get.mock.calls[1][1]).toEqual({
            q: 'North House',
            skill: 'Hoist',
            scope: 'all',
        });
    });

    it('refuses another destination or malformed page while retaining the search', () => {
        const { result } = renderHook(() =>
            useBoardFilters({ q: 'North' }, '5'),
        );
        for (const url of [
            'https://elsewhere.example/operations/job-board?page=2',
            '/operations/shifts?page=2',
            '/operations/job-board?page=-1',
        ])
            act(() => result.current.goPage(url));
        expect(get).not.toHaveBeenCalled();
        expect(result.current.draft.q).toBe('North');
        expect(result.current.error).toMatch(/Could not open that page/);
    });
});
