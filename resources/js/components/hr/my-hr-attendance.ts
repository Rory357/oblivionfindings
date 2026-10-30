import type { BreakContext } from '@/hooks/use-attendance-break';

type AttendanceReadBase = {
    schema_version: 1;
    scope: 'my_hr_self_attendance';
    actor_id: number;
    timezone: string;
};

export type MyHrAttendance = AttendanceReadBase & (
    | {
          state: 'open';
          can_break: boolean;
          can_clock_out: boolean;
          time_entry: null;
          session: {
              id: number;
              shift_id: number | null;
              clock_in_at: string;
              is_on_break: boolean;
              break_started_at: string | null;
              break_minutes: number;
              break_count: number;
          };
      }
    | {
          state: 'none' | 'unavailable';
          can_break: false;
          can_clock_out: false;
          session: null;
          time_entry: null;
      }
    | {
          state: 'entry_only';
          can_break: false;
          can_clock_out: false;
          session: null;
          time_entry: {
              id: number;
              shift_id: number | null;
              clock_in_at: string;
              break_minutes: number;
          };
      }
);

const positiveId = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;
const count = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) >= 0;
const instant = (value: unknown): value is string =>
    typeof value === 'string' &&
    /(?:Z|[+-]\d{2}:\d{2})$/.test(value) &&
    Number.isFinite(Date.parse(value));

/** A current read, not evidence that any earlier clock or break request saved. */
export function readMyHrAttendance(
    value: unknown,
    actorId: unknown,
): MyHrAttendance | null {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
    const read = value as Partial<MyHrAttendance>;
    if (
        read.schema_version !== 1 ||
        read.scope !== 'my_hr_self_attendance' ||
        !positiveId(actorId) ||
        read.actor_id !== actorId ||
        typeof read.timezone !== 'string' ||
        !read.timezone.trim()
    ) return null;
    try {
        new Intl.DateTimeFormat('en-NZ', { timeZone: read.timezone });
    } catch {
        return null;
    }
    if (read.state !== 'open') {
        if (read.session !== null || read.can_break !== false || read.can_clock_out !== false) return null;
        if (read.state === 'entry_only') {
            const entry = read.time_entry;
            return entry &&
                positiveId(entry.id) &&
                (entry.shift_id === null || positiveId(entry.shift_id)) &&
                instant(entry.clock_in_at) &&
                count(entry.break_minutes)
                ? read as MyHrAttendance : null;
        }
        return (
            ['none', 'unavailable'].includes(read.state ?? '') &&
            read.time_entry === null
        ) ? read as MyHrAttendance : null;
    }
    const session = read.session;
    if (
        typeof read.can_break !== 'boolean' ||
        typeof read.can_clock_out !== 'boolean' ||
        read.time_entry !== null ||
        !session ||
        !positiveId(session.id) ||
        !(session.shift_id === null || positiveId(session.shift_id)) ||
        !instant(session.clock_in_at) ||
        typeof session.is_on_break !== 'boolean' ||
        !count(session.break_minutes) ||
        !count(session.break_count) ||
        (session.is_on_break
            ? !instant(session.break_started_at)
            : session.break_started_at !== null)
    ) return null;
    return read as MyHrAttendance;
}

export function myHrBreakContext(read: MyHrAttendance | null): BreakContext {
    return {
        actorId: read?.actor_id ?? null,
        sessionId: read?.state === 'open' ? read.session.id : null,
        shiftId: read?.state === 'open' ? read.session.shift_id : null,
        canAct: read?.state === 'open' && read.can_break,
        onBreak: read?.state === 'open' && read.session.is_on_break,
    };
}
