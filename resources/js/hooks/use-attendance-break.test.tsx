import { act, cleanup, renderHook } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readBreakResult, useAttendanceBreak, type BreakContext, type BreakResult } from './use-attendance-break';

const transport = vi.hoisted(() => ({
    reload: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({ router: { reload: transport.reload } }));
vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
        isAxiosError: (error: unknown) =>
            typeof error === 'object' && error !== null && 'response' in error,
    },
}));
const post = vi.mocked(axios.post);
const context: BreakContext = {
    actorId: 7, sessionId: 42, shiftId: 9, canAct: true, onBreak: false,
};
const receipt = (): BreakResult => ({
    schema_version: 1,
    scope: 'attendance_break_recorded_result',
    action: 'start_break',
    actor_id: 7,
    session: {
        id: 42, shift_id: 9, status: 'open',
        break_started_at: '2026-10-20T10:00:00+13:00',
        break_minutes: 10, break_count: 2,
    },
    break_event: {
        id: 55, session_id: 42, started_at: '2026-10-20T10:00:00+13:00',
        ended_at: null, minutes: 0,
    },
});
const read = (props: unknown) => props as BreakContext;
const setup = (initial = context) =>
    renderHook((value) => useAttendanceBreak(value, read), { initialProps: initial });
beforeEach(() => {
    post.mockReset();
    transport.reload.mockReset();
});
afterEach(cleanup);

describe('recorded break result', () => {
    it('accepts an exact start and a zero-minute legacy end', () => {
        expect(readBreakResult(receipt(), context, 'start_break')).not.toBeNull();
        const end = receipt();
        end.action = 'end_break';
        end.session.break_started_at = null;
        end.session.break_count = 0;
        end.break_event.ended_at = end.break_event.started_at;
        expect(readBreakResult(end, { ...context, onBreak: true }, 'end_break')).not.toBeNull();
    });
    it.each([
        (r: BreakResult) => { r.actor_id = 8; },
        (r: BreakResult) => { r.session.id = 43; },
        (r: BreakResult) => { r.session.shift_id = null; },
        (r: BreakResult) => { r.break_event.session_id = 43; },
        (r: BreakResult) => { r.break_event.started_at = '2026-10-20T10:00:00'; },
        (r: BreakResult) => { r.break_event.minutes = 3; },
        (r: BreakResult) => { r.session.break_count = 0; },
        (r: BreakResult) => { r.session.break_minutes = -1; },
        (r: BreakResult) => { r.session.break_started_at = '2026-10-20T11:00:00+13:00'; },
        (r: BreakResult) => { r.action = 'end_break'; },
    ])('rejects an inconsistent or unrelated frame %#', (change) => {
        const r = receipt();
        change(r);
        expect(readBreakResult(r, context, 'start_break')).toBeNull();
    });
});

it('sends once even before React renders the pending state', async () => {
    let resolve!: (value: unknown) => void;
    post.mockImplementation(() => new Promise((done) => { resolve = done; }));
    const { result } = setup();
    let request!: Promise<void>;
    act(() => {
        request = result.current.submit();
        void result.current.submit();
    });
    expect(post).toHaveBeenCalledTimes(1);
    expect(result.current.pending).toBe(true);
    expect(result.current.blocksSession(42)).toBe(true);
    expect(result.current.blocksSession(43)).toBe(false);
    expect(post).toHaveBeenCalledWith('/attendance/break/start', { session_id: 42 },
        { headers: { Accept: 'application/json' }, timeout: 30_000 });
    await act(async () => {
        resolve({ status: 200, data: receipt() });
        await request;
    });
    expect(result.current.outcome?.status).toBe('confirmed');
    expect(result.current.blocked).toBe(true);
    expect(transport.reload).toHaveBeenCalledTimes(1);
});

it('unlocks after a matching fresh read and uses the new break state for the next action', async () => {
    post.mockResolvedValue({ status: 200, data: receipt() });
    const { result, rerender } = setup();
    await act(async () => { await result.current.submit(); });
    const fresh = { ...context, onBreak: true };
    rerender(fresh);
    act(() => {
        transport.reload.mock.calls[0][0].onSuccess({ props: fresh });
        transport.reload.mock.calls[0][0].onFinish();
    });
    expect(result.current.blocked).toBe(false);
    expect(result.current.blocksSession(42)).toBe(false);
    expect(result.current.outcome).toMatchObject({ status: 'confirmed', refreshed: true });
    post.mockRejectedValue(new Error('Disconnected'));
    await act(async () => { await result.current.submit(); });
    expect(post.mock.calls[1][0]).toBe('/attendance/break/end');
});

it('keeps a confirmed write held when refresh fails, and refresh never repeats the write', async () => {
    post.mockResolvedValue({ status: 200, data: receipt() });
    const { result } = setup();
    await act(async () => { await result.current.submit(); });
    act(() => transport.reload.mock.calls[0][0].onError({ message: 'Unavailable' }));
    expect(result.current.blocked).toBe(true);
    act(() => result.current.refresh());
    expect(transport.reload).toHaveBeenCalledTimes(2);
    expect(post).toHaveBeenCalledTimes(1);
});

it('does not unlock for a stale or different-session refresh', async () => {
    post.mockResolvedValue({ status: 200, data: receipt() });
    const { result } = setup();
    await act(async () => { await result.current.submit(); });
    act(() => {
        transport.reload.mock.calls[0][0].onSuccess({ props: context });
        transport.reload.mock.calls[0][0].onSuccess({ props: { ...context, sessionId: 99, onBreak: true } });
        transport.reload.mock.calls[0][0].onFinish();
    });
    expect(result.current.blocked).toBe(true);
});

it.each([
    { response: { status: 403 } },
    { response: { status: 409, data: { code: 'break_result_unconfirmed' } } },
    { response: { status: 500 } },
    { response: { status: 422, data: { errors: { other: ['Rejected'] } } } },
    { response: { status: 422, data: { errors: { break: ['Unclassified framework error'] } } } },
    { response: { status: 422, data: { code: 'break_rejected', errors: { break: ['Refused'], session_id: 'Malformed' } } } },
    { response: { status: 422, data: { code: 'break_rejected', errors: { break: ['Refused'], session_id: [] } } } },
    new Error('Connection lost'),
])('holds uncertain errors without a refresh or automatic retry %#', async (error) => {
    post.mockRejectedValue(error);
    const { result, rerender } = setup();
    await act(async () => { await result.current.submit(); });
    expect(result.current.outcome?.status).toBe('unknown');
    rerender({ ...context, onBreak: true });
    await act(async () => { await result.current.submit(); });
    expect(post).toHaveBeenCalledTimes(1);
    expect(transport.reload).not.toHaveBeenCalled();
});

it.each([
    { status: 200, data: '<html>Sign in</html>' },
    { status: 200, data: { ...receipt(), actor_id: 99 } },
    { status: 204, data: receipt() },
])('does not treat a generic success or unrelated frame as saved %#', async (response) => {
    post.mockResolvedValue(response);
    const { result } = setup();
    await act(async () => { await result.current.submit(); });
    expect(result.current.outcome?.status).toBe('unknown');
    expect(result.current.blocked).toBe(true);
});

it('allows a deliberate new attempt after a known validation/domain refusal', async () => {
    post.mockRejectedValue({ response: { status: 422, data: { code: 'break_rejected', errors: { break: ['No break is currently in progress.'] } } } });
    const { result } = setup({ ...context, onBreak: true });
    await act(async () => { await result.current.submit(); });
    expect(result.current.outcome).toMatchObject({ status: 'rejected' });
    expect(result.current.blocked).toBe(false);
    await act(async () => { await result.current.submit(); });
    expect(post).toHaveBeenCalledTimes(2);
});

it('accepts finite model-validation errors only with the proven-rejection code', async () => {
    post.mockRejectedValue({
        response: {
            status: 422,
            data: { code: 'break_rejected', errors: { attendance: ['Review this session.'], break_minutes: ['Check the break duration.'] } },
        },
    });
    const { result } = setup();
    await act(async () => { await result.current.submit(); });
    expect(result.current.outcome).toEqual({
        status: 'rejected', message: 'Review this session. Check the break duration.',
    });
    expect(result.current.blocked).toBe(false);
});

it('allows a new session after the previous command and refresh completed', async () => {
    post.mockResolvedValue({ status: 200, data: receipt() });
    const { result, rerender } = setup();
    await act(async () => { await result.current.submit(); });
    const fresh = { ...context, onBreak: true };
    rerender(fresh);
    act(() => {
        transport.reload.mock.calls[0][0].onSuccess({ props: fresh });
        transport.reload.mock.calls[0][0].onFinish();
    });
    rerender({ ...context, sessionId: 99 });
    expect(result.current.outcome).toBeNull();
    expect(result.current.blocksSession(42)).toBe(false);
    expect(result.current.blocked).toBe(false);
});

it.each([
    { ...context, actorId: 8 },
    { ...context, canAct: false },
    { ...context, sessionId: null },
    { ...context, shiftId: 10 },
])('retires a pending owner permanently when its context changes %#', async (changed) => {
    let resolve!: (value: unknown) => void;
    post.mockImplementation(() => new Promise((done) => { resolve = done; }));
    const { result, rerender } = setup();
    let request!: Promise<void>;
    act(() => { request = result.current.submit(); });
    rerender(changed);
    rerender(context);
    await act(async () => {
        resolve({ status: 200, data: receipt() });
        await request;
    });
    expect(result.current.outcome?.status).toBe('unavailable');
    expect(result.current.blocked).toBe(true);
    expect(transport.reload).not.toHaveBeenCalled();
});

it('ignores completion after unmount', async () => {
    let resolve!: (value: unknown) => void;
    post.mockImplementation(() => new Promise((done) => { resolve = done; }));
    const { result, unmount } = setup();
    let request!: Promise<void>;
    act(() => { request = result.current.submit(); });
    unmount();
    await act(async () => {
        resolve({ status: 200, data: receipt() });
        await request;
    });
    expect(transport.reload).not.toHaveBeenCalled();
});
