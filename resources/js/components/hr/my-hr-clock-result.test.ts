import { expect, it } from 'vitest';
import { myHrClockRequest, readMyHrClockResult, type MyHrClockExpectation } from './my-hr-clock-result';

const canonical: MyHrClockExpectation = {
    actorId: 7, target: { kind: 'attendance_session', id: 42, shiftId: null },
};
const orphan: MyHrClockExpectation = {
    actorId: 7, target: { kind: 'orphan_time_entry', id: 701, shiftId: null },
};
const receipt = () => ({
    schema_version: 1,
    scope: 'my_hr_clock_recorded_result',
    actor_id: 7,
    action: 'clock_out',
    target_kind: 'attendance_session',
    time_entry_result: null,
    attendance_result: {
        action: 'clock_out', session_id: 42, shift_id: null, session_status: 'closed',
        clock_in_at: '2026-10-20T08:00:00+13:00',
        clock_out_at: '2026-10-20T10:00:00+13:00',
        break_minutes: 15, worked_hours: 1.75,
        timesheet_sync_outcome: 'skipped_follow_up', timesheet_id: null,
        timesheet_status: null, handover_outcome: 'no_shift',
    },
});
const orphanReceipt = () => ({
    ...receipt(),
    target_kind: 'orphan_time_entry',
    attendance_result: null,
    time_entry_result: {
        id: 701, attendance_session_id: null, shift_id: null, status: 'submitted',
        clock_in_at: '2026-10-20T08:00:00+13:00',
        clock_out_at: '2026-10-20T10:00:00+13:00',
        break_minutes: 15, worked_hours: 1.75,
        timesheet_sync_outcome: 'created', timesheet_id: 98, timesheet_status: 'draft',
    },
});

it('accepts a matching attendance receipt without inventing a timesheet save', () => {
    const result = readMyHrClockResult(receipt(), canonical);
    expect(result?.attendance_result?.timesheet_sync_outcome).toBe('skipped_follow_up');
});

it('matches a null original shift exactly even though the shared validator permits unspecified shifts', () => {
    const value = receipt();
    expect(readMyHrClockResult({
        ...value,
        attendance_result: { ...value.attendance_result, shift_id: 81, handover_outcome: 'no_payload' },
    }, canonical)).toBeNull();
});

it.each([
    { actor_id: 8 }, { action: 'clock_in' }, { schema_version: 2 },
    { scope: 'other' }, { time_entry_result: {} }, { target_kind: 'orphan_time_entry' },
])('refuses a mismatched command envelope: %j', (delta) => {
    expect(readMyHrClockResult({ ...receipt(), ...delta }, canonical)).toBeNull();
});

it('never substitutes a replacement session or another entry for the captured target', () => {
    const value = receipt();
    expect(readMyHrClockResult({
        ...value, attendance_result: { ...value.attendance_result, session_id: 43 },
    }, canonical)).toBeNull();
    expect(readMyHrClockResult(orphanReceipt(), canonical)).toBeNull();
    expect(readMyHrClockResult(receipt(), orphan)).toBeNull();
    expect(readMyHrClockResult({
        ...orphanReceipt(), time_entry_result: { ...orphanReceipt().time_entry_result, id: 702 },
    }, orphan)).toBeNull();
});

it('accepts only the exact orphan result with a real timesheet outcome', () => {
    expect(readMyHrClockResult(orphanReceipt(), orphan)).toEqual(orphanReceipt());
});

it.each([
    { attendance_session_id: 42 }, { shift_id: 81 }, { status: 'active' },
    { status: 'approved' }, { status: 'voided' }, { status: '  ' },
    { status: 'a'.repeat(256) }, { break_minutes: 120 },
    { clock_out_at: null }, { clock_out_at: '2026-10-20T07:00:00+13:00' },
    { clock_in_at: '2026-10-20T08:00:00' }, { break_minutes: -1 },
    { worked_hours: NaN }, { worked_hours: -1 }, { timesheet_sync_outcome: 'unknown' },
    { timesheet_id: null }, { timesheet_status: '' },
    { timesheet_sync_outcome: 'skipped_follow_up' },
])('refuses invalid orphan recorded evidence: %j', (delta) => {
    const value = orphanReceipt();
    expect(readMyHrClockResult({
        ...value, time_entry_result: { ...value.time_entry_result, ...delta },
    }, orphan)).toBeNull();
});

it('does not send a locally calculated break total for a canonical session', () => {
    expect(myHrClockRequest(canonical, 99)).toEqual({
        url: '/hr/my/time/clock-out',
        data: { actor_id: 7, target: { kind: 'attendance_session', id: 42 }, expected_shift_id: null },
    });
});

it.each(['rejected', 'draft'])('preserves the recorded %s status of a recovered entry', (status) => {
    const value = orphanReceipt();
    expect(readMyHrClockResult({
        ...value, time_entry_result: { ...value.time_entry_result, status },
    }, orphan)?.time_entry_result?.status).toBe(status);
});

it('preserves omitted orphan breaks and supports an explicit zero-minute correction', () => {
    expect(myHrClockRequest(orphan).data).not.toHaveProperty('break_minutes');
    expect(myHrClockRequest(orphan, 0).data).toHaveProperty('break_minutes', 0);
});

it('captures idle intent and the selected shift for clock-in', () => {
    expect(myHrClockRequest({ actorId: 7, target: { kind: 'idle', shiftId: 81 } })).toEqual({
        url: '/hr/my/time/clock-in',
        data: { actor_id: 7, target: { kind: 'idle' }, shift_id: 81 },
    });
});

it('accepts the uniquely resolved shift for automatic clock-in and enforces an explicit selection', () => {
    const value = receipt();
    const started = {
        ...value,
        action: 'clock_in',
        attendance_result: {
            ...value.attendance_result,
            action: 'clock_in', session_status: 'open', shift_id: 81,
            clock_out_at: null, break_minutes: 0, worked_hours: null,
            timesheet_sync_outcome: 'none', handover_outcome: null,
        },
    };
    expect(readMyHrClockResult(started, {
        actorId: 7, target: { kind: 'idle', shiftId: null },
    })).not.toBeNull();
    expect(readMyHrClockResult(started, {
        actorId: 7, target: { kind: 'idle', shiftId: 81 },
    })).not.toBeNull();
    expect(readMyHrClockResult(started, {
        actorId: 7, target: { kind: 'idle', shiftId: 82 },
    })).toBeNull();
});
