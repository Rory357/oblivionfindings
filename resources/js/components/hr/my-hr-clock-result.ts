import {
    attendanceReceipt,
    type AttendanceResult,
} from '@/pages/attendance/components/use-attendance-command';

export type MyHrClockTarget =
    | { kind: 'idle'; shiftId: number | null }
    | { kind: 'attendance_session' | 'orphan_time_entry'; id: number; shiftId: number | null };

export type MyHrClockExpectation = {
    actorId: number;
    target: MyHrClockTarget;
};

export type RecordedOrphanEntry = {
    id: number;
    attendance_session_id: null;
    shift_id: number | null;
    status: string;
    clock_in_at: string;
    clock_out_at: string;
    break_minutes: number;
    worked_hours: number;
    timesheet_sync_outcome: AttendanceResult['timesheet_sync_outcome'];
    timesheet_id: number | null;
    timesheet_status: string | null;
};

type ClockResultBase = {
    schema_version: 1;
    scope: 'my_hr_clock_recorded_result';
    actor_id: number;
};

export type MyHrClockResult = ClockResultBase & (
    | {
          action: 'clock_in' | 'clock_out';
          target_kind: 'attendance_session';
          attendance_result: AttendanceResult;
          time_entry_result: null;
      }
    | {
          action: 'clock_out';
          target_kind: 'orphan_time_entry';
          attendance_result: null;
          time_entry_result: RecordedOrphanEntry;
      }
);

const positiveId = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;
const instant = (value: unknown): value is string =>
    typeof value === 'string' &&
    /(?:Z|[+-]\d{2}:\d{2})$/.test(value) &&
    Number.isFinite(Date.parse(value));

/** Only the reply to the captured command can confirm that command's result. */
export function readMyHrClockResult(
    value: unknown,
    expected: MyHrClockExpectation,
): MyHrClockResult | null {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
    const result = value as Partial<MyHrClockResult>;
    const target = expected.target;
    const action = target.kind === 'idle' ? 'clock_in' : 'clock_out';
    if (
        result.schema_version !== 1 ||
        result.scope !== 'my_hr_clock_recorded_result' ||
        !positiveId(expected.actorId) ||
        result.actor_id !== expected.actorId ||
        result.action !== action ||
        !(target.shiftId === null || positiveId(target.shiftId)) ||
        (target.kind !== 'idle' && !positiveId(target.id))
    ) return null;

    if (target.kind !== 'orphan_time_entry') {
        if (
            result.target_kind !== 'attendance_session' ||
            result.time_entry_result !== null ||
            ((target.kind !== 'idle' || target.shiftId !== null) &&
                result.attendance_result?.shift_id !== target.shiftId)
        ) return null;
        const checked = attendanceReceipt(
            { attendance_result: result.attendance_result },
            {
                action,
                sessionId: target.kind === 'attendance_session' ? target.id : undefined,
                explicitShiftId: target.shiftId,
            },
        );
        return checked.status === 'confirmed' ? result as MyHrClockResult : null;
    }

    if (result.target_kind !== 'orphan_time_entry' || result.attendance_result !== null)
        return null;
    const entry = result.time_entry_result;
    if (
        !entry ||
        entry.id !== target.id ||
        entry.attendance_session_id !== null ||
        entry.shift_id !== target.shiftId ||
        typeof entry.status !== 'string' ||
        !entry.status.trim() ||
        entry.status.length > 255 ||
        ['active', 'approved', 'voided'].includes(entry.status.trim().toLowerCase()) ||
        !instant(entry.clock_in_at) ||
        !instant(entry.clock_out_at) ||
        Date.parse(entry.clock_out_at) <= Date.parse(entry.clock_in_at) ||
        !Number.isSafeInteger(entry.break_minutes) ||
        entry.break_minutes < 0 ||
        entry.break_minutes * 60_000 >= Date.parse(entry.clock_out_at) - Date.parse(entry.clock_in_at) ||
        typeof entry.worked_hours !== 'number' ||
        !Number.isFinite(entry.worked_hours) ||
        entry.worked_hours < 0 ||
        !['none', 'created', 'updated', 'skipped_follow_up'].includes(entry.timesheet_sync_outcome)
    ) return null;
    const synced = ['created', 'updated'].includes(entry.timesheet_sync_outcome);
    if (synced
        ? !positiveId(entry.timesheet_id) ||
          typeof entry.timesheet_status !== 'string' || !entry.timesheet_status.trim()
        : entry.timesheet_id !== null || entry.timesheet_status !== null
    ) return null;
    return result as MyHrClockResult;
}

export function myHrClockRequest(
    expected: MyHrClockExpectation,
    breakMinutes?: number,
): { url: string; data: Record<string, unknown> } {
    const target = expected.target;
    if (target.kind === 'idle') {
        return {
            url: '/hr/my/time/clock-in',
            data: {
                actor_id: expected.actorId,
                target: { kind: 'idle' },
                shift_id: target.shiftId,
            },
        };
    }
    return {
        url: '/hr/my/time/clock-out',
        data: {
            actor_id: expected.actorId,
            target: { kind: target.kind, id: target.id },
            expected_shift_id: target.shiftId,
            ...(target.kind === 'orphan_time_entry' && breakMinutes !== undefined
                ? { break_minutes: breakMinutes } : {}),
        },
    };
}
