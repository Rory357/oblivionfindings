import type { TransportRecord } from './types';

/** Only future, unstarted plans can change through Transport. Source checks still own saving. */
export function canRescheduleTransport(row: TransportRecord, now = Date.now()) {
    return !!(
        row.can.manage &&
        row.booking &&
        !row.journey &&
        ['pending', 'approved'].includes(row.booking.status) &&
        row.stage !== 'cancelled' &&
        new Date(row.booking.start).getTime() > now
    );
}

/** Shared calendar Date values are Auckland wall time, not UTC instants. */
export function calendarLocal(date: Date) {
    const pad = (value: number) => String(value).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function proposedCalendarWindow(
    originalStart: Date,
    originalEnd: Date,
    target: Date,
    end?: Date,
) {
    const start = new Date(target);
    // Month drops supply only the target date. Preserve the departure time and duration.
    if (!end)
        start.setHours(
            originalStart.getHours(),
            originalStart.getMinutes(),
            0,
            0,
        );
    const finish =
        end ||
        new Date(
            start.getTime() + originalEnd.getTime() - originalStart.getTime(),
        );
    return { start: calendarLocal(start), end: calendarLocal(finish) };
}
