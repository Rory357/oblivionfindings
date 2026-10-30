import { WORKER_TIMEZONE } from '@/lib/datetime';
import { rosterInstantOptions } from '@/lib/roster-time';
import { shiftInputInstant, shiftWallInput } from '@/lib/workforce-time-input';

export function attendanceTimeOptions(
    wall: string,
    zone = WORKER_TIMEZONE,
): string[] {
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(wall)) return [];
    try {
        return rosterInstantOptions(wall + ':00', zone).map((value) =>
            value.toISOString(),
        );
    } catch {
        return [];
    }
}

export function attendanceEnd(
    wall: string,
    zone = WORKER_TIMEZONE,
    original?: string | null,
    choice = '',
): { instant: string | null; error: string } {
    const options = attendanceTimeOptions(wall, zone);
    if (choice && options.includes(choice))
        return { instant: choice, error: '' };
    try {
        return { instant: shiftInputInstant(wall, zone, original), error: '' };
    } catch {
        return {
            instant: null,
            error:
                options.length > 1
                    ? 'This time occurs twice when the clocks change. Choose the first or second occurrence.'
                    : options.length === 0 &&
                        /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(wall)
                      ? 'This date and time does not exist in the selected timezone. Choose another time.'
                      : 'Choose a clock-out date and time.',
        };
    }
}

export function attendanceSeed(instant: string, zone = WORKER_TIMEZONE) {
    const wall = shiftWallInput(instant, zone);
    return {
        date: wall.slice(0, 10),
        time: wall.slice(11, 16),
        original: instant,
    };
}

export function wholeAttendanceMinutes(start: string, end: string): number {
    return Math.max(
        0,
        Math.floor((Date.parse(end) - Date.parse(start)) / 60000),
    );
}

function enteredBreak(value: string): number | null {
    return /^\d+$/.test(value) &&
        Number.isSafeInteger(Number(value)) &&
        Number(value) <= 240
        ? Number(value)
        : null;
}

export function attendanceDurationError(
    start: string,
    end: string | null,
    now = Date.now(),
): string {
    if (!end || !Number.isFinite(Date.parse(end)))
        return 'Choose a valid clock-out date and time.';
    if (Date.parse(end) <= Date.parse(start))
        return 'Clock-out must be after clock-in.';
    if (wholeAttendanceMinutes(start, end) < 1)
        return 'Clock-out must be at least one minute after clock-in. Check the end time.';
    if (Date.parse(end) > now + 120000)
        return 'Clock-out cannot be more than two minutes in the future.';
    return '';
}

export function clockOutBreaks(
    session: {
        clock_in_at: string;
        break_minutes: number;
        break_started_at: string | null;
    },
    end: string | null,
    extra: string,
) {
    const running =
        end && session.break_started_at
            ? wholeAttendanceMinutes(session.break_started_at, end)
            : 0;
    const tracked = session.break_minutes + running;
    const addition = enteredBreak(extra);
    const total = tracked + (addition ?? 0);
    let error =
        addition === null
            ? 'Enter whole extra break minutes from 0 to 240.'
            : '';
    if (!error && addition !== 0 && total > 240)
        error =
            'Tracked breaks plus extra minutes exceed the 240-minute entry limit. Keep the tracked breaks or ask a coordinator to review the session.';
    if (
        !error &&
        end &&
        total >= wholeAttendanceMinutes(session.clock_in_at, end)
    )
        error = 'Breaks must be shorter than the session.';
    return {
        tracked,
        total,
        error,
        payload: addition === 0 ? undefined : total,
    };
}

export function correctionBreaks(
    start: string,
    end: string | null,
    entered: string,
) {
    const total = enteredBreak(entered);
    let error =
        total === null ? 'Enter whole break minutes from 0 to 240.' : '';
    if (!error && end && (total ?? 0) >= wholeAttendanceMinutes(start, end))
        error = 'Breaks must be shorter than the session.';
    return { total: total ?? 0, error };
}
