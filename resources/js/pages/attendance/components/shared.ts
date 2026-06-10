/* Shared types + tiny helpers for the Attendance page and its wizards. */

export type BreakEvent = {
    id: number;
    started_at: string | null;
    ended_at: string | null;
    minutes: number | null;
};

export type Session = {
    id: number;
    clock_in_at: string;
    clock_out_at: string | null;
    break_minutes: number;
    status: string;
    source: string;
    location: string | null;
    worked_hours: number;
    timesheet_id: number | null;
    timesheet_status: string | null;
};

export type OpenSession = {
    id: number;
    clock_in_at: string;
    shift_id: number | null;
    shift_starts_at: string | null;
    shift_ends_at: string | null;
    shift_location: string | null;
    client_name: string | null;
    client_id: number | null;
    timesheet_id: number | null;
    on_break: boolean;
    break_started_at: string | null;
    break_minutes: number;
    breaks: BreakEvent[];
};

export type EligibleShift = {
    id: number;
    starts_at: string;
    ends_at: string;
    status: string;
    location: string | null;
    client_name: string;
};

export type OnClockRow = {
    id: number;
    user_id: number;
    user_name: string | null;
    clock_in_at: string;
    shift_id: number | null;
    shift_location: string | null;
    shift_ends_at: string | null;
    is_stale: boolean;
};

/** A session the Fix-clock-out wizard can correct (open or closed). */
export type FixCandidate = {
    id: number;
    user_name: string;
    clock_in_at: string;
    clock_out_at: string | null;
    break_minutes: number;
    shift_id: number | null;
    location: string | null;
    is_stale: boolean;
};

export const STALE_MS = 16 * 60 * 60 * 1000;

export function minutesBetween(a: Date | string, b: Date | string): number {
    return Math.max(
        0,
        Math.round(
            (new Date(b).getTime() - new Date(a).getTime()) / 60000,
        ),
    );
}

/** "1h 12m" / "45m" from a millisecond duration. */
export function fmtDur(ms: number): string {
    const m = Math.max(0, Math.floor(ms / 60000));
    const h = Math.floor(m / 60);
    return h > 0 ? `${h}h ${String(m % 60).padStart(2, '0')}m` : `${m}m`;
}

/** Local "HH:mm" for <input type="time"> defaults. */
export function toHHMM(d: Date): string {
    return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

/** Combine a local calendar date (today by default) with an "HH:mm" value. */
export function timeOnDate(hhmm: string, base?: Date): Date {
    const d = base ? new Date(base) : new Date();
    if (hhmm) {
        const [h, m] = hhmm.split(':').map(Number);
        d.setHours(h ?? 0, m ?? 0, 0, 0);
    }
    return d;
}

/** Combine a "YYYY-MM-DD" date input with an "HH:mm" time input (local). */
export function dateTimeFromInputs(date: string, hhmm: string): Date {
    const [y, mo, da] = date.split('-').map(Number);
    const [h, mi] = hhmm.split(':').map(Number);
    return new Date(y ?? 1970, (mo ?? 1) - 1, da ?? 1, h ?? 0, mi ?? 0, 0, 0);
}

/** Local "YYYY-MM-DD" for <input type="date"> defaults. */
export function toYMD(d: Date): string {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
