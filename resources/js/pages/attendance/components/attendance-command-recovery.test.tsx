import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    attendanceReceipt,
    attendanceTimesheetMessage,
    useAttendanceCommand,
    type AttendanceResult,
} from './use-attendance-command';

type Options = {
    onSuccess?: (page: { props: Record<string, unknown> }) => void;
    onError?: (errors: Record<string, string>) => void;
    onCancel?: () => void;
    onFinish?: () => void;
};
const transport = vi.hoisted(() => ({
    requests: [] as { url: string; data: unknown; options: Options }[],
    throwNext: false,
}));
vi.mock('@inertiajs/react', () => ({
    router: {
        post: (url: string, data: unknown, options: Options) => {
            if (transport.throwNext) throw new Error('Disconnected');
            transport.requests.push({ url, data, options });
        },
    },
}));
beforeEach(() => {
    transport.requests.length = 0;
    transport.throwNext = false;
});
const closed: AttendanceResult = {
    action: 'clock_out',
    session_id: 8,
    shift_id: 7,
    session_status: 'closed',
    clock_in_at: '2026-10-05T08:00:00+13:00',
    clock_out_at: '2026-10-05T17:00:00+13:00',
    break_minutes: 30,
    worked_hours: 8.5,
    timesheet_sync_outcome: 'created',
    timesheet_id: 12,
    timesheet_status: 'draft',
    handover_outcome: 'draft_saved',
};
const expected = {
    action: 'clock_out' as const,
    sessionId: 8,
    end: closed.clock_out_at!,
};
const flash = (receipt: unknown = closed) => ({ attendance_result: receipt });
const finish = (value?: unknown) =>
    act(() => {
        const req = transport.requests.at(-1)!;
        if (value !== undefined)
            req.options.onSuccess?.({ props: { flash: value } });
        req.options.onFinish?.();
    });

describe('actual attendance receipts', () => {
    it('confirms exact saved identity, time and outcomes', () => {
        expect(attendanceReceipt(flash(), expected)).toEqual({
            status: 'confirmed',
            receipt: closed,
        });
    });
    it.each([
        { action: 'correct' },
        { session_id: 9 },
        { shift_id: undefined },
        { session_status: 'open' },
        { clock_in_at: '2026-10-05T08:00:00' },
        { clock_out_at: null },
        { clock_out_at: '2026-10-05T18:00:00+13:00' },
        { break_minutes: -1 },
        { break_minutes: 1.5 },
        { worked_hours: null },
        { worked_hours: NaN },
        { timesheet_sync_outcome: 'skipped_follow_up' },
        { timesheet_id: 0 },
        { timesheet_status: null },
        { handover_outcome: null },
        { handover_outcome: 'no_shift' },
    ])('does not confirm malformed or mismatched evidence %j', (delta) => {
        expect(
            attendanceReceipt(flash({ ...closed, ...delta }), expected).status,
        ).toBe('unknown');
    });
    it.each([
        null,
        {},
        { success: 'Clocked out.' },
        { warning: 'Check attendance', ...flash() },
        { error: 'Conflicting result', ...flash() },
    ])('does not promote a redirect/message to saved evidence', (value) => {
        expect(attendanceReceipt(value, expected).status).toBe('unknown');
    });
    it('shows a known rejection without describing it as saved', () => {
        expect(
            attendanceReceipt(
                { error: 'This session is no longer available.' },
                expected,
            ),
        ).toEqual({
            status: 'rejected',
            message: 'This session is no longer available.',
        });
    });
    it('keeps a real follow-up distinct from timesheet synchronization', () => {
        const receipt = {
            ...closed,
            timesheet_sync_outcome: 'skipped_follow_up' as const,
            timesheet_id: null,
            timesheet_status: null,
        };
        expect(attendanceReceipt(flash(receipt), expected).status).toBe(
            'confirmed',
        );
        expect(attendanceTimesheetMessage(receipt)).toMatch(
            /timesheet was not changed/,
        );
    });
    it('uses actual auto-matching but requires an explicitly chosen shift', () => {
        const receipt: AttendanceResult = {
            ...closed,
            action: 'clock_in',
            session_status: 'open',
            clock_out_at: null,
            worked_hours: null,
            timesheet_sync_outcome: 'none',
            timesheet_id: null,
            timesheet_status: null,
            handover_outcome: null,
        };
        expect(
            attendanceReceipt(flash(receipt), { action: 'clock_in' }).status,
        ).toBe('confirmed');
        expect(
            attendanceReceipt(flash(receipt), {
                action: 'clock_in',
                explicitShiftId: 6,
            }).status,
        ).toBe('unknown');
    });
});

describe('attendance transport recovery', () => {
    it('blocks same-tick double submission and subsequent confirmed replay', () => {
        const { result } = renderHook(() => useAttendanceCommand('session:8'));
        act(() => {
            result.current.submit('/attendance/clock-out', {}, expected);
            result.current.submit('/attendance/clock-out', {}, expected);
        });
        expect(transport.requests).toHaveLength(1);
        expect(result.current.pending).toBe(true);
        finish(flash());
        expect(result.current.receipt).toEqual(closed);
        act(() => result.current.submit('/attendance/clock-out', {}, expected));
        expect(transport.requests).toHaveLength(1);
    });
    it.each(['missing', 'cancel', 'throw'] as const)(
        'holds an uncertain %s request and never automatically repeats it',
        (mode) => {
            const { result } = renderHook(() =>
                useAttendanceCommand('session:8'),
            );
            transport.throwNext = mode === 'throw';
            act(() =>
                result.current.submit(
                    '/attendance/clock-out',
                    { notes: 'Keep this' },
                    expected,
                ),
            );
            if (mode === 'cancel')
                act(() => transport.requests[0].options.onCancel?.());
            if (mode !== 'throw') finish();
            expect(result.current.outcome?.status).toBe('unknown');
            expect(result.current.blocked).toBe(true);
            transport.throwNext = false;
            act(() =>
                result.current.submit('/attendance/clock-out', {}, expected),
            );
            expect(transport.requests).toHaveLength(mode === 'throw' ? 0 : 1);
        },
    );
    it('allows a deliberate retry after a known validation rejection', () => {
        const { result } = renderHook(() => useAttendanceCommand('session:8'));
        act(() => result.current.submit('/attendance/clock-out', {}, expected));
        act(() =>
            transport.requests[0].options.onError?.({
                clock_out: 'Review required tasks.',
            }),
        );
        finish();
        expect(result.current.outcome).toEqual({
            status: 'rejected',
            message: 'Review required tasks.',
        });
        act(() => result.current.submit('/attendance/clock-out', {}, expected));
        expect(transport.requests).toHaveLength(2);
    });
    it('does not apply a late success to another session context', () => {
        const { result, rerender } = renderHook(
            ({ id }) => useAttendanceCommand('session:' + id),
            { initialProps: { id: 8 } },
        );
        act(() => result.current.submit('/attendance/clock-out', {}, expected));
        rerender({ id: 9 });
        finish(flash());
        expect(result.current.receipt).toBeNull();
        expect(result.current.outcome?.status).toBe('unknown');
    });
    it('ignores callbacks after unmount or after the outcome has settled', () => {
        const { result, unmount } = renderHook(() =>
            useAttendanceCommand('session:8'),
        );
        act(() => result.current.submit('/attendance/clock-out', {}, expected));
        finish();
        act(() =>
            transport.requests[0].options.onSuccess?.({
                props: { flash: flash() },
            }),
        );
        expect(result.current.outcome?.status).toBe('unknown');
        unmount();
        act(() => transport.requests[0].options.onFinish?.());
        expect(transport.requests).toHaveLength(1);
    });
});
