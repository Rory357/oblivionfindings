import type { subscribeSafetyHistory } from '@/lib/safety-history';
import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useSafetyHistoryGuard } from './use-safety-history-guard';

type Guard = Parameters<typeof subscribeSafetyHistory>[0];
const state = vi.hoisted(() => ({
    guard: null as Guard | null,
    unsubscribe: vi.fn(),
    cancelAll: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('@/lib/safety-history', () => ({
    safetyHistoryIsRestoring: () => false,
    subscribeSafetyHistory: (guard: Guard) => {
        state.guard = guard;
        return state.unsubscribe;
    },
}));
vi.mock('@inertiajs/react', () => ({
    router: {
        on: () => () => {},
        cancelAll: state.cancelAll,
        visit: state.visit,
    },
}));
const review = vi.fn();
function Form({ guarded = true }: { guarded?: boolean }) {
    const recovery = useSafetyHistoryGuard(guarded, review, 1);
    return (
        <>
            <input
                aria-label="Unsent note"
                defaultValue="Keep this response draft"
            />
            {recovery}
        </>
    );
}
beforeEach(() => {
    vi.clearAllMocks();
    state.guard = null;
});
afterEach(cleanup);

it('tries a real return without triggering discard or closing the recovery dialog', () => {
    const retry = vi.fn();
    render(<Form />);
    act(() =>
        state.guard!.failed({
            retry,
            url: '/control-room/alerts/13',
            blocked: true,
            reload: false,
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Try returning' }));
    expect(retry).toHaveBeenCalledTimes(1);
    expect(screen.getByRole('alertdialog')).toBeVisible();
    expect(screen.getByLabelText('Unsent note')).toHaveValue(
        'Keep this response draft',
    );
    expect(state.visit).not.toHaveBeenCalled();
    fireEvent.click(
        screen.getByRole('button', { name: 'Keep entries in this tab' }),
    );
    expect(screen.getByRole('alertdialog')).toBeVisible();
    expect(retry).toHaveBeenCalledTimes(1);
});

it('makes a queued response uncertainty explicit and never labels an Inertia retry as available', () => {
    const retry = vi.fn();
    render(<Form />);
    act(() =>
        state.guard!.failed({
            retry,
            url: '/control-room/alerts/13',
            blocked: true,
            reload: true,
        }),
    );
    expect(screen.getByText(/submitted save may have finished/)).toBeVisible();
    expect(
        screen.getByRole('button', { name: 'Reload and check record' }),
    ).toBeVisible();
    expect(
        screen.queryByRole('button', { name: 'Try returning' }),
    ).not.toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Keep entries in this tab' }),
    );
    expect(retry).not.toHaveBeenCalled();
    expect(screen.getByLabelText('Unsent note')).toHaveValue(
        'Keep this response draft',
    );
    expect(state.visit).not.toHaveBeenCalled();
});

it('allows editing after a disposed target and clears obsolete recovery after a completed save', () => {
    const view = render(<Form />);
    act(() =>
        state.guard!.failed({
            retry: vi.fn(),
            url: '/control-room/alerts/13',
            blocked: false,
            reload: false,
        }),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    act(() =>
        state.guard!.failed({
            retry: vi.fn(),
            url: '/control-room/alerts/13',
            blocked: true,
            reload: false,
        }),
    );
    view.rerender(<Form guarded={false} />);
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    view.rerender(<Form />);
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument();
    expect(state.unsubscribe).toHaveBeenCalled();
});
