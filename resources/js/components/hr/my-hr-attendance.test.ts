import { expect, it } from 'vitest';
import { myHrBreakContext, readMyHrAttendance } from './my-hr-attendance';

const open = () => ({
    schema_version: 1,
    scope: 'my_hr_self_attendance',
    state: 'open',
    actor_id: 7,
    timezone: 'Pacific/Auckland',
    can_break: true,
    can_clock_out: true,
    time_entry: null,
    session: {
        id: 42,
        shift_id: 81,
        clock_in_at: '2026-10-20T08:00:00+13:00',
        is_on_break: false,
        break_started_at: null,
        break_minutes: 15,
        break_count: 1,
    },
});

it('uses the canonical session identity even when an HR entry has a different ID', () => {
    const page = { activeClock: { id: 701 }, attendance: open() };
    expect(myHrBreakContext(readMyHrAttendance(page.attendance, 7))).toEqual({
        actorId: 7, sessionId: 42, shiftId: 81, canAct: true, onBreak: false,
    });
    expect(readMyHrAttendance(page.activeClock, 7)).toBeNull();
});

it.each(['none', 'unavailable'])('keeps %s separate from a break-capable session', (state) => {
    const read = { ...open(), state, session: null, can_break: false, can_clock_out: false };
    expect(myHrBreakContext(readMyHrAttendance(read, 7))).toEqual({
        actorId: 7, sessionId: null, shiftId: null, canAct: false, onBreak: false,
    });
    expect(readMyHrAttendance({ ...read, can_break: true }, 7)).toBeNull();
    expect(readMyHrAttendance({ ...read, can_clock_out: true }, 7)).toBeNull();
    expect(readMyHrAttendance({ ...read, session: open().session }, 7)).toBeNull();
});

it('keeps a scoped legacy entry available for exact recovery without granting break actions', () => {
    const entry = { id: 701, shift_id: 81, clock_in_at: '2026-10-20T08:00:00+13:00', break_minutes: 15 };
    const read = { ...open(), state: 'entry_only', session: null, can_break: false, can_clock_out: false, time_entry: entry };
    expect(readMyHrAttendance(read, 7)?.time_entry).toEqual(entry);
    expect(myHrBreakContext(readMyHrAttendance(read, 7))).toEqual({
        actorId: 7, sessionId: null, shiftId: null, canAct: false, onBreak: false,
    });
    expect(readMyHrAttendance({ ...read, can_break: true }, 7)).toBeNull();
    expect(readMyHrAttendance({ ...read, time_entry: null }, 7)).toBeNull();
    expect(readMyHrAttendance({ ...read, time_entry: { ...entry, shift_id: undefined } }, 7)).toBeNull();
    expect(readMyHrAttendance({ ...open(), time_entry: entry }, 7)).toBeNull();
});

it.each([
    { actor_id: 8 }, { schema_version: 2 }, { scope: 'attendance_break_recorded_result' },
    { timezone: 'invalid/timezone' }, { timezone: '' }, { state: 'other' },
    { can_break: 'true' }, { can_clock_out: undefined }, { can_clock_out: 'true' }, { session: null },
])('refuses an incompatible or wrong-person read: %j', (delta) => {
    expect(readMyHrAttendance({ ...open(), ...delta }, 7)).toBeNull();
});

it.each([
    { id: 0 }, { shift_id: undefined }, { shift_id: -1 },
    { clock_in_at: '2026-10-20T08:00:00' }, { is_on_break: undefined },
    { break_minutes: -1 }, { break_count: 1.5 },
    { is_on_break: true, break_started_at: null },
    { is_on_break: false, break_started_at: '2026-10-20T09:00:00+13:00' },
])('refuses malformed session data: %j', (delta) => {
    const read = open();
    expect(readMyHrAttendance({ ...read, session: { ...read.session, ...delta } }, 7)).toBeNull();
});

it('retains a recorded legacy break with zero count and a denied capability', () => {
    const read = open();
    const result = readMyHrAttendance({
        ...read,
        can_break: false,
        session: {
            ...read.session,
            shift_id: null,
            is_on_break: true,
            break_count: 0,
            break_started_at: '2026-10-20T09:00:00+13:00',
        },
    }, 7);
    expect(result).not.toBeNull();
    expect(myHrBreakContext(result)).toEqual({
        actorId: 7, sessionId: 42, shiftId: null, canAct: false, onBreak: true,
    });
});

it('does not authorize an absent or invalid current actor', () => {
    expect(readMyHrAttendance(open(), null)).toBeNull();
    expect(readMyHrAttendance(open(), '7')).toBeNull();
    expect(myHrBreakContext(null).canAct).toBe(false);
});

it('reads clock-out capability separately from break capability', () => {
    const read = readMyHrAttendance({ ...open(), can_break: false }, 7);
    expect(read?.can_clock_out).toBe(true);
    expect(myHrBreakContext(read).canAct).toBe(false);
    const historical = readMyHrAttendance({ ...open(), can_break: false, can_clock_out: false }, 7);
    expect(historical?.state).toBe('open');
    expect(historical?.session?.id).toBe(42);
});
