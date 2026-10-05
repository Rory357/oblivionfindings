import type { StockRow } from '@/pages/emar/_stock-dialogs';
import {
    act,
    cleanup,
    fireEvent,
    render,
    renderHook,
    screen,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useStockFilters } from './_filters';
import type { StockFilters } from './_hub-types';
import { StockPagedList } from './_pagination';
import { useSelectedStock } from './_selection';

const mocked = vi.hoisted(() => ({ get: vi.fn(), on: vi.fn(() => vi.fn()) }));
vi.mock('@inertiajs/react', () => ({ router: mocked }));
const base: StockFilters = {
    q: '',
    view: 'all',
    chip: 'all',
    page: 1,
    per_page: 10,
    site_id: null,
    client_id: null,
};
beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers();
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

describe('Stock server pagination', () => {
    it('keeps a rapid person and house selection together and ignores cancelled search callbacks', () => {
        const { result } = renderHook(() => useStockFilters(base));
        act(() => result.current.change({ q: 'older order' }, 250));
        act(() => vi.advanceTimersByTime(250));
        const old = mocked.get.mock.calls[0][2];
        const cancel = vi.fn();
        old.onCancelToken({ cancel });
        act(() => result.current.change({ site_id: 4 }));
        act(() => result.current.change({ client_id: 10 }));
        act(() => vi.runOnlyPendingTimers());
        expect(cancel).toHaveBeenCalledOnce();
        const [, requested, visit] = mocked.get.mock.calls[1];
        expect(requested).toEqual({
            ...base,
            q: 'older order',
            site_id: 4,
            client_id: 10,
        });
        act(() => {
            old.onSuccess({ props: { filters: base } });
            old.onFinish();
        });
        expect(result.current.busy).toBe(true);
        expect(result.current.filters.client_id).toBe(10);
        act(() => {
            visit.onSuccess({ props: { filters: requested } });
            visit.onFinish();
        });
    });

    it('searches from page one, retains selected page size, and synchronises back navigation', () => {
        const initial = { ...base, page: 8, per_page: 25 };
        const { result, rerender } = renderHook(
            ({ server }) => useStockFilters(server),
            { initialProps: { server: initial } },
        );
        act(() => result.current.change({ q: 'batch' }, 250));
        expect(result.current.filters).toMatchObject({ page: 1, per_page: 25 });
        act(() => {
            window.dispatchEvent(new PopStateEvent('popstate'));
            vi.runOnlyPendingTimers();
        });
        expect(mocked.get).not.toHaveBeenCalled();
        rerender({ server: { ...initial, page: 2 } });
        expect(result.current.filters.page).toBe(2);
        expect(result.current.filters.q).toBe('');
    });

    it('keeps failed filters available for retry instead of showing stale records as a result', () => {
        const { result } = renderHook(() => useStockFilters(base));
        act(() => result.current.change({ q: 'retained' }));
        act(() => vi.runOnlyPendingTimers());
        const failed = mocked.get.mock.calls[0][2];
        act(() => {
            failed.onError();
            failed.onFinish();
        });
        expect(result.current.failed).toBe(true);
        expect(result.current.filters.q).toBe('retained');
        act(() => result.current.retry());
        act(() => vi.runOnlyPendingTimers());
        expect(mocked.get.mock.calls[1][1].q).toBe('retained');
    });

    it('uses server totals and boundaries even when only one page of records is present', () => {
        const onPage = vi.fn();
        render(
            <StockPagedList
                title="Pharmacy orders"
                page={{
                    current_page: 5,
                    per_page: 10,
                    total: 47,
                    from: 41,
                    to: 47,
                    last_page: 5,
                }}
                onPage={onPage}
                onPageSize={vi.fn()}
            >
                <p>Seven matching orders</p>
            </StockPagedList>,
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            '41–47 of 47 items',
        );
        expect(
            screen.getAllByRole('button', {
                name: 'Next pharmacy orders page',
            })[0],
        ).toBeDisabled();
        fireEvent.click(
            screen.getAllByRole('button', {
                name: 'Previous pharmacy orders page',
            })[0],
        );
        expect(onPage).toHaveBeenCalledWith(4);
    });
});

describe('Off-page medication context', () => {
    it('aborts an old selection and never borrows its balance for the new medicine', async () => {
        const requests: {
            id: number;
            signal: AbortSignal;
            resolve: (row: StockRow | null) => void;
        }[] = [];
        const load = vi.fn(
            (id: number, signal: AbortSignal) =>
                new Promise<StockRow | null>((resolve) =>
                    requests.push({ id, signal, resolve }),
                ),
        );
        const { result, rerender } = renderHook(
            ({ id }) => useSelectedStock(id, [], load),
            { initialProps: { id: '91' } },
        );
        expect(result.current.loading).toBe(true);
        rerender({ id: '92' });
        expect(requests[0].signal.aborted).toBe(true);
        await act(async () =>
            requests[0].resolve({
                medication_id: 91,
                on_hand: 600,
            } as StockRow),
        );
        expect(result.current.row).toBeNull();
        expect(result.current.loading).toBe(true);
        await act(async () => requests[1].resolve(null));
        expect(result.current.loading).toBe(false);
        expect(result.current.row).toBeNull();
        expect(result.current.failed).toBe(false);
    });

    it('offers a retry after context failure without inventing a zero balance', async () => {
        const load = vi
            .fn()
            .mockRejectedValueOnce(new Error('offline'))
            .mockResolvedValueOnce({ medication_id: 99, on_hand: null });
        const { result } = renderHook(() => useSelectedStock('99', [], load));
        await act(async () => {});
        expect(result.current.failed).toBe(true);
        expect(result.current.row).toBeNull();
        await act(async () => result.current.retry());
        expect(result.current.failed).toBe(false);
        expect(result.current.row?.on_hand).toBeNull();
    });
});
