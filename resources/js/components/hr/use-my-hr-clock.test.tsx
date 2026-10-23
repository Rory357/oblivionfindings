import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { useMyHrClock } from './use-my-hr-clock';
import type { MyHrClockExpectation } from './my-hr-clock-result';

const mocks = vi.hoisted(() => ({ post: vi.fn(), reload: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { reload: mocks.reload } }));
vi.mock('axios', () => ({
    default: {
        post: mocks.post,
        isAxiosError: (error: { isAxiosError?: boolean }) => error?.isAxiosError === true,
    },
}));

const current: MyHrClockExpectation = {
    actorId: 7, target: { kind: 'attendance_session', id: 42, shiftId: null },
};
const idle: MyHrClockExpectation = { actorId: 7, target: { kind: 'idle', shiftId: null } };
const response = () => ({
    status: 200,
    data: {
        schema_version: 1, scope: 'my_hr_clock_recorded_result', actor_id: 7,
        action: 'clock_out', target_kind: 'attendance_session', time_entry_result: null,
        attendance_result: {
            action: 'clock_out', session_id: 42, shift_id: null, session_status: 'closed',
            clock_in_at: '2026-10-20T08:00:00+13:00', clock_out_at: '2026-10-20T10:00:00+13:00',
            break_minutes: 15, worked_hours: 1.75,
            timesheet_sync_outcome: 'none', timesheet_id: null, timesheet_status: null,
            handover_outcome: 'no_shift',
        },
    },
});
function deferred() {
    let resolve!: (value: ReturnType<typeof response>) => void;
    const promise = new Promise<ReturnType<typeof response>>((done) => { resolve = done; });
    return { promise, resolve };
}

beforeEach(() => {
    mocks.post.mockReset();
    mocks.reload.mockReset();
});
afterEach(cleanup);

it('reserves one synchronous attempt even before React updates the button', async () => {
    const pending = deferred();
    mocks.post.mockReturnValue(pending.promise);
    const { result } = renderHook(() => useMyHrClock(current));
    let first!: Promise<void>;
    act(() => {
        first = result.current.submit();
        void result.current.submit();
        expect(result.current.isBlocked()).toBe(true);
    });
    expect(mocks.post).toHaveBeenCalledTimes(1);
    await act(async () => { pending.resolve(response()); await first; });
    expect(result.current.outcome?.status).toBe('confirmed');
    expect(result.current.blocked).toBe(true);
});

it('unlocks only after the recorded result and matching current page state', async () => {
    mocks.post.mockResolvedValue(response());
    const { result, rerender } = renderHook(({ context }) => useMyHrClock(context), {
        initialProps: { context: current },
    });
    await act(async () => { await result.current.submit(); });
    expect(mocks.reload).toHaveBeenCalledOnce();
    act(() => mocks.reload.mock.calls[0][0].onFinish());
    expect(result.current.blocked).toBe(true);
    rerender({ context: idle });
    expect(result.current.blocked).toBe(false);
    expect(result.current.outcome).toMatchObject({ status: 'confirmed', refreshed: true });
});

it('keeps confirmed evidence after refresh failure and retries only the read', async () => {
    mocks.post.mockResolvedValue(response());
    mocks.reload.mockImplementation(() => { throw new Error('offline'); });
    const { result } = renderHook(() => useMyHrClock(current));
    await act(async () => { await result.current.submit(); });
    expect(result.current.outcome).toMatchObject({ status: 'confirmed', refreshed: false });
    act(() => result.current.refresh());
    await act(async () => { await result.current.submit(); });
    expect(mocks.reload).toHaveBeenCalledTimes(2);
    expect(mocks.post).toHaveBeenCalledTimes(1);
});

it('waits for the current refresh to finish even after the matching read arrives', async () => {
    mocks.post.mockResolvedValue(response());
    const { result, rerender } = renderHook(({ context }) => useMyHrClock(context), {
        initialProps: { context: current },
    });
    await act(async () => { await result.current.submit(); });
    rerender({ context: idle });
    expect(result.current.outcome).toMatchObject({ status: 'confirmed', refreshed: true });
    expect(result.current.blocked).toBe(true);
    await act(async () => { await result.current.submit(); });
    expect(mocks.post).toHaveBeenCalledTimes(1);
    act(() => mocks.reload.mock.calls[0][0].onFinish());
    expect(result.current.blocked).toBe(false);
});

it('holds a lost reply and does not let a later page change prove the write', async () => {
    mocks.post.mockRejectedValue(new Error('connection lost'));
    const { result, rerender } = renderHook(({ context }) => useMyHrClock(context), {
        initialProps: { context: current },
    });
    await act(async () => { await result.current.submit(); });
    expect(result.current.outcome?.status).toBe('unknown');
    rerender({ context: idle });
    expect(result.current.outcome?.status).toBe('unavailable');
    await act(async () => { await result.current.submit(); });
    expect(mocks.post).toHaveBeenCalledTimes(1);
});

it('does not revive a pending target after another target appears and the original returns', async () => {
    const pending = deferred();
    mocks.post.mockReturnValue(pending.promise);
    const { result, rerender } = renderHook(({ context }) => useMyHrClock(context), {
        initialProps: { context: current },
    });
    let work!: Promise<void>;
    act(() => { work = result.current.submit(); });
    rerender({ context: { actorId: 7, target: { kind: 'attendance_session', id: 43, shiftId: null } } });
    rerender({ context: current });
    await act(async () => { pending.resolve(response()); await work; });
    expect(result.current.outcome?.status).toBe('unavailable');
    expect(mocks.reload).not.toHaveBeenCalled();
    expect(result.current.blocked).toBe(true);
});

it('withdraws a confirmed receipt when the current actor or access changes', async () => {
    mocks.post.mockResolvedValue(response());
    const { result, rerender } = renderHook(({ context }: { context: MyHrClockExpectation | null }) => useMyHrClock(context), {
        initialProps: { context: current as MyHrClockExpectation | null },
    });
    await act(async () => { await result.current.submit(); });
    rerender({ context: null });
    rerender({ context: idle });
    expect(result.current.outcome?.status).toBe('unavailable');
    expect(result.current.blocked).toBe(true);
});

it('permits deliberate correction only for the checked prewriter rejection', async () => {
    mocks.post.mockRejectedValue({
        isAxiosError: true,
        response: { status: 422, data: { code: 'my_hr_clock_rejected', errors: { notes: ['Shorten the note.'] } } },
    });
    const { result } = renderHook(() => useMyHrClock(current));
    await act(async () => { await result.current.submit(); });
    expect(result.current.outcome).toEqual({ status: 'rejected', message: 'Shorten the note.' });
    expect(result.current.blocked).toBe(false);
    await act(async () => { await result.current.submit(); });
    expect(mocks.post).toHaveBeenCalledTimes(2);
});

it.each([
    { status: 422, data: { errors: { clock: ['No change.'] } } },
    { status: 422, data: { code: 'my_hr_clock_rejected', errors: { clock: ['No change.'], other: [] } } },
    { status: 409, data: { code: 'my_hr_clock_result_unconfirmed', message: 'Review your clock.' } },
    { status: 403, data: {} },
])('does not convert unproven errors into permission to retry: %j', async (errorResponse) => {
    mocks.post.mockRejectedValue({ isAxiosError: true, response: errorResponse });
    const { result } = renderHook(() => useMyHrClock(current));
    await act(async () => { await result.current.submit(); });
    expect(result.current.outcome?.status).toBe('unknown');
    await act(async () => { await result.current.submit(); });
    expect(mocks.post).toHaveBeenCalledTimes(1);
});

it('does not refresh or display a result after its owner unmounts', async () => {
    const pending = deferred();
    mocks.post.mockReturnValue(pending.promise);
    const { result, unmount } = renderHook(() => useMyHrClock(current));
    let work!: Promise<void>;
    act(() => { work = result.current.submit(); });
    unmount();
    await act(async () => { pending.resolve(response()); await work; });
    expect(mocks.reload).not.toHaveBeenCalled();
});
